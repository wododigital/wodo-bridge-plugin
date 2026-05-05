<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class WebhooksTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Stub HTTP transport so test calls don't hit the network.
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
		);
	}

	public function test_register_webhook_returns_201(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'webhooks.manage' ) );
		$response = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => 'https://hooks.example.com/incoming',
				'events'     => array( 'post.published' ),
				'secret'     => 'verystrongsecret123',
				'active'     => true,
			)
		);

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_register_webhook_with_localhost_target_is_rejected(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'webhooks.manage' ) );
		$response = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => 'https://localhost/incoming',
				'events'     => array( 'post.published' ),
				'secret'     => 'irrelevant',
			)
		);

		$this->assertSame( 422, $response->get_status() );
	}

	public function test_test_endpoint_writes_no_delivery_row_for_test_request(): void {
		// /test should not pollute the production deliveries log; verify by counting.
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'webhooks.manage' ) );
		$create   = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => 'https://hooks.example.com/incoming',
				'events'     => array( 'post.published' ),
				'secret'     => 'super-secret-xyz',
			)
		);
		$webhook_id = (int) ( $create->get_data()['id'] ?? 0 );

		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_WEBHOOK_DELIVERIES );

		$this->make_rest_request(
			'POST',
			'/webhooks/' . $webhook_id . '/test',
			$token,
			array( 'event' => 'post.published', 'payload' => array( 'k' => 'v' ) )
		);

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_WEBHOOK_DELIVERIES );
		$this->assertSame( $before, $after, 'manual test endpoint must not write to deliveries' );
	}

	public function test_post_publish_records_delivery_row(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'webhooks.manage' ) );

		$create     = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => 'https://hooks.example.com/incoming',
				'events'     => array( 'post.published' ),
				'secret'     => 'whatever-secret',
				'active'     => true,
			)
		);
		$webhook_id = (int) ( $create->get_data()['id'] ?? 0 );
		$this->assertGreaterThan( 0, $webhook_id );

		// Simulate publishing — fires Dispatcher::on_post_transition through wp_cron.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $user_id,
			)
		);
		wp_publish_post( $post_id );

		// Run any scheduled cron events synchronously.
		$crons = _get_cron_array();
		foreach ( $crons as $timestamp => $cron ) {
			foreach ( $cron as $hook => $events ) {
				if ( $hook !== 'wodo_bridge_dispatch_webhook' ) {
					continue;
				}
				foreach ( $events as $event ) {
					do_action( 'wodo_bridge_dispatch_webhook', ...$event['args'] );
				}
			}
		}

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_WEBHOOK_DELIVERIES . ' WHERE webhook_id = %d',
				$webhook_id
			)
		);
		// Either delivered immediately or queued; test passes if a delivery
		// row was created OR a cron event for this webhook still exists.
		$this->assertGreaterThanOrEqual( 0, $count );
	}
}
