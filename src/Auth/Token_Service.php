<?php

declare( strict_types=1 );

namespace WODO_Bridge\Auth;

use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Token_Service {

	public function register_capture_hooks(): void {
		add_action( 'wp_create_application_password', array( $this, 'on_application_password_created' ), 10, 4 );
		add_action( 'wp_delete_application_password', array( $this, 'on_application_password_deleted' ), 10, 2 );
	}

	public function on_application_password_created( int $user_id, array $new_item, string $new_password, array $args ): void {
		$uuid  = isset( $new_item['uuid'] ) ? (string) $new_item['uuid'] : '';
		$label = isset( $new_item['name'] ) ? (string) $new_item['name'] : '';
		if ( $uuid === '' ) {
			return;
		}

		$app_id           = isset( $new_item['app_id'] ) ? (string) $new_item['app_id'] : '';
		$requested_scopes = $this->parse_scopes_from_app_id( $app_id );
		$origin           = isset( $args['app_id'] ) && is_string( $args['app_id'] ) ? '' : '';

		if ( isset( $_GET['aggregator_origin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$origin = esc_url_raw( wp_unslash( (string) $_GET['aggregator_origin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$this->upsert(
			array(
				'app_password_uuid' => $uuid,
				'wp_user_id'        => $user_id,
				'scopes_json'       => wp_json_encode( $requested_scopes ),
				'label'             => mb_substr( $label, 0, 191 ),
				'aggregator_origin' => mb_substr( $origin, 0, 255 ),
			)
		);
	}

	public function on_application_password_deleted( int $user_id, array $item ): void {
		$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
		if ( $uuid === '' ) {
			return;
		}
		$this->revoke_by_uuid( $uuid );
	}

	public function lookup_by_uuid( string $uuid ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, app_password_uuid, wp_user_id, scopes_json, label, aggregator_origin, created_at, last_used_at, last_used_ip, revoked_at FROM `{$table}` WHERE app_password_uuid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$uuid
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	public function lookup_by_id( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, app_password_uuid, wp_user_id, scopes_json, label, aggregator_origin, created_at, last_used_at, last_used_ip, revoked_at FROM `{$table}` WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	public function list_for_user( int $user_id, bool $include_revoked = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$sql = "SELECT id, app_password_uuid, wp_user_id, scopes_json, label, aggregator_origin, created_at, last_used_at, last_used_ip, revoked_at FROM `{$table}` WHERE wp_user_id = %d";
		if ( ! $include_revoked ) {
			$sql .= ' AND revoked_at IS NULL';
		}
		$sql .= ' ORDER BY created_at DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize' ), $rows );
	}

	public function list_all( bool $include_revoked = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$sql = "SELECT id, app_password_uuid, wp_user_id, scopes_json, label, aggregator_origin, created_at, last_used_at, last_used_ip, revoked_at FROM `{$table}`";
		if ( ! $include_revoked ) {
			$sql .= ' WHERE revoked_at IS NULL';
		}
		$sql .= ' ORDER BY created_at DESC LIMIT 500';

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'normalize' ), $rows );
	}

	public function record_use( int $token_id, string $ip ): void {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'last_used_at' => current_time( 'mysql', true ),
				'last_used_ip' => mb_substr( $ip, 0, 45 ),
			),
			array( 'id' => $token_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function bind_scopes( int $token_id, array $scopes, string $aggregator_origin = '' ): bool {
		$normalized = $this->sanitize_scopes( $scopes );

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$update = array( 'scopes_json' => wp_json_encode( $normalized ) );
		$format = array( '%s' );
		if ( $aggregator_origin !== '' ) {
			$update['aggregator_origin'] = mb_substr( $aggregator_origin, 0, 255 );
			$format[]                    = '%s';
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			$update,
			array( 'id' => $token_id ),
			$format,
			array( '%d' )
		);

		return $result !== false;
	}

	public function revoke( int $token_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array(
				'id'         => $token_id,
				'revoked_at' => null,
			),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}

	public function revoke_by_uuid( string $uuid ): bool {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'app_password_uuid' => $uuid ),
			array( '%s' ),
			array( '%s' )
		);

		return $result !== false;
	}

	public function upsert( array $row ): int {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_TOKENS;

		$existing = $this->lookup_by_uuid( (string) $row['app_password_uuid'] );
		if ( $existing !== null ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'wp_user_id'        => (int) $row['wp_user_id'],
					'scopes_json'       => $row['scopes_json'],
					'label'             => $row['label'],
					'aggregator_origin' => $row['aggregator_origin'],
				),
				array( 'app_password_uuid' => (string) $row['app_password_uuid'] ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%s' )
			);
			return (int) $existing['id'];
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'app_password_uuid' => (string) $row['app_password_uuid'],
				'wp_user_id'        => (int) $row['wp_user_id'],
				'scopes_json'       => (string) $row['scopes_json'],
				'label'             => (string) $row['label'],
				'aggregator_origin' => (string) $row['aggregator_origin'],
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private function normalize( array $row ): array {
		$scopes = json_decode( (string) $row['scopes_json'], true );
		if ( ! is_array( $scopes ) ) {
			$scopes = array();
		}

		return array(
			'id'                => (int) $row['id'],
			'app_password_uuid' => (string) $row['app_password_uuid'],
			'wp_user_id'        => (int) $row['wp_user_id'],
			'scopes'            => array_values( array_map( 'strval', $scopes ) ),
			'label'             => (string) $row['label'],
			'aggregator_origin' => (string) $row['aggregator_origin'],
			'created_at'        => (string) $row['created_at'],
			'last_used_at'      => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
			'last_used_ip'      => $row['last_used_ip'] !== null ? (string) $row['last_used_ip'] : null,
			'revoked_at'        => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
		);
	}

	public function sanitize_scopes( array $scopes ): array {
		$catalog = Constants::SCOPES;
		$clean   = array();
		foreach ( $scopes as $scope ) {
			if ( ! is_string( $scope ) ) {
				continue;
			}
			$scope = trim( $scope );
			if ( $scope === '' ) {
				continue;
			}
			if ( ! in_array( $scope, $catalog, true ) ) {
				continue;
			}
			$clean[] = $scope;
		}

		return array_values( array_unique( $clean ) );
	}

	private function parse_scopes_from_app_id( string $app_id ): array {
		if ( $app_id === '' ) {
			return array();
		}
		$parts = explode( ':', $app_id );
		$scopes = array();
		foreach ( $parts as $part ) {
			if ( strpos( $part, 'scope=' ) === 0 ) {
				$value  = substr( $part, 6 );
				$scopes = explode( ',', $value );
				break;
			}
		}
		return $this->sanitize_scopes( $scopes );
	}
}
