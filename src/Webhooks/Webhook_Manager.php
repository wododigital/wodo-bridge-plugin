<?php

declare( strict_types=1 );

namespace WODO_Bridge\Webhooks;

use WODO_Bridge\Lib\Constants;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhook_Manager {

	public function list( bool $active_only = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$sql   = "SELECT * FROM `{$table}`";
		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}
		$sql .= ' ORDER BY created_at DESC';

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( $this, 'normalize' ), $rows );
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	public function get_secret( int $id ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT secret_hash FROM `{$table}` WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? (string) $row['secret_hash'] : null;
	}

	public function create( array $payload ): array|WP_Error {
		$url    = isset( $payload['target_url'] ) ? (string) $payload['target_url'] : '';
		$events = is_array( $payload['events'] ?? null ) ? $this->validate_events( $payload['events'] ) : array();
		$active = isset( $payload['active'] ) ? (bool) $payload['active'] : true;

		$validation = Url_Validator::validate( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		if ( empty( $events ) ) {
			return new WP_Error( 'validation_failed', __( 'At least one event is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$secret = isset( $payload['secret'] ) && is_string( $payload['secret'] ) && $payload['secret'] !== ''
			? (string) $payload['secret']
			: bin2hex( random_bytes( 24 ) );

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$uuid  = wp_generate_uuid4();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'uuid'        => $uuid,
				'target_url'  => $url,
				'secret_hash' => $secret,
				'events_json' => wp_json_encode( $events ),
				'active'      => $active ? 1 : 0,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$id    = (int) $wpdb->insert_id;
		$item  = $this->get( $id );
		if ( $item === null ) {
			return new WP_Error( 'internal_error', __( 'Failed to create webhook.', 'wodo-bridge' ), array( 'status' => 500 ) );
		}
		$item['secret'] = $secret;
		return $item;
	}

	public function update( int $id, array $payload ): array|WP_Error {
		$existing = $this->get( $id );
		if ( $existing === null ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}

		$update = array( 'updated_at' => current_time( 'mysql', true ) );
		$format = array( '%s' );

		if ( isset( $payload['target_url'] ) ) {
			$validation = Url_Validator::validate( (string) $payload['target_url'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$update['target_url'] = (string) $payload['target_url'];
			$format[]             = '%s';
		}
		if ( isset( $payload['events'] ) && is_array( $payload['events'] ) ) {
			$events = $this->validate_events( $payload['events'] );
			if ( empty( $events ) ) {
				return new WP_Error( 'validation_failed', __( 'At least one event is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
			}
			$update['events_json'] = wp_json_encode( $events );
			$format[]              = '%s';
		}
		if ( isset( $payload['active'] ) ) {
			$update['active'] = ! empty( $payload['active'] ) ? 1 : 0;
			$format[]         = '%d';
		}
		if ( isset( $payload['secret'] ) && is_string( $payload['secret'] ) && $payload['secret'] !== '' ) {
			$update['secret_hash'] = (string) $payload['secret'];
			$format[]              = '%s';
		}

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $this->get( $id );
	}

	public function delete( int $id ): array|WP_Error {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $result === false || $result === 0 ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		return array( 'deleted' => true, 'id' => $id );
	}

	public function deliveries( int $webhook_id, array $args = array() ): array {
		global $wpdb;
		$table   = $wpdb->prefix . Constants::TABLE_WEBHOOK_DELIVERIES;
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, delivery_uuid, event, payload_hash, http_status, attempt, latency_ms, response_excerpt, delivered_at FROM `{$table}` WHERE webhook_id = %d ORDER BY delivered_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$webhook_id,
				$per_page
			),
			ARRAY_A
		);

		return array(
			'total'      => is_array( $rows ) ? count( $rows ) : 0,
			'deliveries' => is_array( $rows ) ? $rows : array(),
		);
	}

	public function update_last_delivery( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOKS;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'last_delivery_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private function normalize( array $row ): array {
		$events = json_decode( (string) ( $row['events_json'] ?? '' ), true );
		if ( ! is_array( $events ) ) {
			$events = array();
		}
		$secret = (string) ( $row['secret_hash'] ?? '' );
		$hint   = $secret === '' ? '' : substr( $secret, 0, 4 ) . '...' . substr( $secret, -4 );
		return array(
			'id'               => (int) $row['id'],
			'uuid'             => (string) $row['uuid'],
			'target_url'       => (string) $row['target_url'],
			'secret_hint'      => $hint,
			'events'           => array_values( array_map( 'strval', $events ) ),
			'active'           => (int) $row['active'] === 1,
			'created_at'       => (string) $row['created_at'],
			'updated_at'       => (string) $row['updated_at'],
			'last_delivery_at' => $row['last_delivery_at'] !== null ? (string) $row['last_delivery_at'] : null,
		);
	}

	private function validate_events( array $events ): array {
		$out = array();
		foreach ( $events as $event ) {
			$event = (string) $event;
			if ( in_array( $event, Constants::WEBHOOK_EVENTS, true ) ) {
				$out[] = $event;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
