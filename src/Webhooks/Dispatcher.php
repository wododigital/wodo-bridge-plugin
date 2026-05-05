<?php

declare( strict_types=1 );

namespace WODO_Bridge\Webhooks;

use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dispatcher {

	private const RETRY_DELAYS = array( 60, 300, 900, 3600, 21600 );
	private const MAX_ATTEMPTS = 5;

	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		add_action( 'wp_handle_upload', array( $this, 'on_upload_handled' ), 10, 1 );
		add_action( 'created_term', array( $this, 'on_term_created' ), 10, 3 );
		add_action( 'wodo_bridge_dispatch_webhook', array( $this, 'cron_dispatch' ), 10, 1 );
	}

	public function on_post_transition( string $new_status, string $old_status, $post ): void {
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		if ( $new_status === 'publish' && $old_status !== 'publish' ) {
			$this->enqueue( 'post.published', array(
				'id'        => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'type'      => (string) $post->post_type,
				'status'    => $new_status,
				'permalink' => (string) get_permalink( $post->ID ),
			) );
			return;
		}
		if ( $new_status === 'publish' && $old_status === 'publish' ) {
			$this->enqueue( 'post.updated', array(
				'id'        => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'type'      => (string) $post->post_type,
				'permalink' => (string) get_permalink( $post->ID ),
			) );
		}
	}

	public function on_upload_handled( $upload ): void {
		if ( ! is_array( $upload ) || empty( $upload['file'] ) ) {
			return;
		}
		$this->enqueue( 'media.uploaded', array(
			'file' => (string) ( $upload['file'] ?? '' ),
			'type' => (string) ( $upload['type'] ?? '' ),
			'url'  => (string) ( $upload['url'] ?? '' ),
		) );
	}

	public function on_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
		$term = get_term( $term_id, $taxonomy );
		if ( is_wp_error( $term ) || ! is_object( $term ) ) {
			return;
		}
		$this->enqueue( 'term.created', array(
			'id'       => (int) $term->term_id,
			'name'     => (string) $term->name,
			'slug'     => (string) $term->slug,
			'taxonomy' => (string) $taxonomy,
		) );
	}

	public function enqueue( string $event, array $payload ): void {
		$manager = new Webhook_Manager();
		foreach ( $manager->list( true ) as $webhook ) {
			if ( ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}
			$delivery_uuid = wp_generate_uuid4();
			wp_schedule_single_event(
				time(),
				'wodo_bridge_dispatch_webhook',
				array(
					array(
						'webhook_id'    => (int) $webhook['id'],
						'event'         => $event,
						'payload'       => $payload,
						'attempt'       => 1,
						'delivery_uuid' => $delivery_uuid,
					),
				)
			);
		}
	}

	public function cron_dispatch( array $args ): void {
		$webhook_id    = (int) ( $args['webhook_id'] ?? 0 );
		$event         = (string) ( $args['event'] ?? '' );
		$payload       = is_array( $args['payload'] ?? null ) ? $args['payload'] : array();
		$attempt       = max( 1, (int) ( $args['attempt'] ?? 1 ) );
		$delivery_uuid = (string) ( $args['delivery_uuid'] ?? wp_generate_uuid4() );

		$manager = new Webhook_Manager();
		$webhook = $manager->get( $webhook_id );
		if ( $webhook === null || ! $webhook['active'] ) {
			return;
		}
		if ( Url_Validator::validate( (string) $webhook['target_url'] ) !== true ) {
			return;
		}

		$secret = $manager->get_secret( $webhook_id );
		if ( $secret === null ) {
			return;
		}

		$timestamp = time();
		$body      = wp_json_encode(
			array(
				'event'         => $event,
				'delivery_uuid' => $delivery_uuid,
				'timestamp'     => $timestamp,
				'site_url'      => get_site_url(),
				'payload'       => $payload,
			)
		);
		if ( ! is_string( $body ) ) {
			$body = '{}';
		}

		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		$headers   = array(
			'Content-Type'   => 'application/json',
			'X-WB-Signature' => 't=' . $timestamp . ',v1=' . $signature,
			'X-WB-Delivery'  => $delivery_uuid,
			'X-WB-Event'     => $event,
		);

		$start    = microtime( true );
		$response = wp_remote_post(
			(string) $webhook['target_url'],
			array(
				'timeout'  => 10,
				'headers'  => $headers,
				'body'     => $body,
				'blocking' => true,
			)
		);
		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$excerpt  = is_wp_error( $response ) ? (string) $response->get_error_message() : (string) wp_remote_retrieve_body( $response );
		$excerpt  = mb_substr( $excerpt, 0, 512 );

		$this->record_delivery( $webhook_id, $delivery_uuid, $event, $body, $status, $attempt, $latency_ms, $excerpt );
		$manager->update_last_delivery( $webhook_id );

		if ( $status >= 200 && $status < 300 ) {
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			return;
		}

		$delay = self::RETRY_DELAYS[ $attempt - 1 ] ?? 3600;
		wp_schedule_single_event(
			time() + $delay,
			'wodo_bridge_dispatch_webhook',
			array(
				array(
					'webhook_id'    => $webhook_id,
					'event'         => $event,
					'payload'       => $payload,
					'attempt'       => $attempt + 1,
					'delivery_uuid' => $delivery_uuid,
				),
			)
		);
	}

	public function fire_test( int $webhook_id, string $event, array $payload ): array {
		$manager = new Webhook_Manager();
		$webhook = $manager->get( $webhook_id );
		if ( $webhook === null ) {
			return array(
				'status'        => 'error',
				'response_code' => 0,
				'body'          => __( 'Webhook not found.', 'wodo-bridge' ),
				'latency_ms'    => 0,
			);
		}
		$secret = $manager->get_secret( $webhook_id );
		if ( $secret === null ) {
			return array(
				'status'        => 'error',
				'response_code' => 0,
				'body'          => __( 'Webhook secret missing.', 'wodo-bridge' ),
				'latency_ms'    => 0,
			);
		}

		$timestamp = time();
		$body      = wp_json_encode(
			array(
				'event'         => $event,
				'delivery_uuid' => wp_generate_uuid4(),
				'timestamp'     => $timestamp,
				'site_url'      => get_site_url(),
				'payload'       => $payload,
			)
		);
		if ( ! is_string( $body ) ) {
			$body = '{}';
		}
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		$start    = microtime( true );
		$response = wp_remote_post(
			(string) $webhook['target_url'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-WB-Signature' => 't=' . $timestamp . ',v1=' . $signature,
					'X-WB-Event'     => $event,
				),
				'body'    => $body,
			)
		);
		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'        => 'error',
				'response_code' => 0,
				'body'          => (string) $response->get_error_message(),
				'latency_ms'    => $latency_ms,
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'status'        => $status_code >= 200 && $status_code < 300 ? 'ok' : 'error',
			'response_code' => $status_code,
			'body'          => mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 1024 ),
			'latency_ms'    => $latency_ms,
		);
	}

	private function record_delivery( int $webhook_id, string $delivery_uuid, string $event, string $body, int $status, int $attempt, int $latency_ms, string $excerpt ): void {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_WEBHOOK_DELIVERIES;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'webhook_id'      => $webhook_id,
				'delivery_uuid'   => $delivery_uuid . '-' . $attempt,
				'event'           => mb_substr( $event, 0, 64 ),
				'payload_hash'    => hash( 'sha256', $body ),
				'http_status'     => $status,
				'attempt'         => $attempt,
				'latency_ms'      => max( 0, $latency_ms ),
				'response_excerpt'=> $excerpt,
				'delivered_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
	}

	public static function prune_deliveries(): void {
		global $wpdb;
		$table  = $wpdb->prefix . Constants::TABLE_WEBHOOK_DELIVERIES;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( Constants::DELIVERIES_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE delivered_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}
}
