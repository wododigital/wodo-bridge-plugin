<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Webhooks;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;
use WODO_Bridge\Webhooks\Dispatcher;
use WODO_Bridge\Webhooks\Webhook_Manager;

/**
 * Unit tests for outbound webhook signing + retry logic.
 *
 * Mocks the HTTP transport via the `pre_http_request` filter; no real network.
 *
 * @covers \WODO_Bridge\Webhooks\Dispatcher
 */
final class DispatcherTest extends TestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $captured = array();

	/** @var array<string,array{status:int,body:string}> */
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		$this->captured  = array();
		$this->responses = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->captured[] = array( 'url' => $url, 'args' => $args );
				$key = $url;
				if ( isset( $this->responses[ $key ] ) ) {
					return array(
						'response' => array( 'code' => $this->responses[ $key ]['status'] ),
						'body'     => $this->responses[ $key ]['body'],
						'headers'  => array(),
						'cookies'  => array(),
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{}',
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_fire_test_signs_payload_with_hmac_sha256(): void {
		$webhook_id = $this->insert_webhook( 'https://hooks.example.com/test', 'super-secret-1234' );

		$result = ( new Dispatcher() )->fire_test( $webhook_id, 'post.published', array( 'hello' => 'world' ) );

		$this->assertSame( 'ok', $result['status'] );
		$this->assertCount( 1, $this->captured );
		$call = $this->captured[0];
		$this->assertSame( 'https://hooks.example.com/test', $call['url'] );

		$sig_header = (string) ( $call['args']['headers']['X-WB-Signature'] ?? '' );
		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[a-f0-9]{64}$/', $sig_header );

		// Verify the signature actually validates against the body + secret.
		preg_match( '/t=(\d+),v1=([a-f0-9]{64})/', $sig_header, $m );
		$timestamp     = $m[1];
		$signature_hex = $m[2];
		$body          = (string) $call['args']['body'];

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, 'super-secret-1234' );
		$this->assertSame( $expected, $signature_hex );
	}

	public function test_fire_test_includes_required_headers(): void {
		$webhook_id = $this->insert_webhook( 'https://hooks.example.com/test', 'secret' );
		( new Dispatcher() )->fire_test( $webhook_id, 'post.published', array( 'k' => 'v' ) );

		$headers = $this->captured[0]['args']['headers'] ?? array();
		$this->assertArrayHasKey( 'Content-Type', $headers );
		$this->assertArrayHasKey( 'X-WB-Signature', $headers );
		$this->assertArrayHasKey( 'X-WB-Event', $headers );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'post.published', $headers['X-WB-Event'] );
	}

	public function test_fire_test_returns_error_when_webhook_missing(): void {
		$result = ( new Dispatcher() )->fire_test( 99999, 'post.published', array() );
		$this->assertSame( 'error', $result['status'] );
	}

	public function test_dispatcher_writes_delivery_row_on_cron_dispatch(): void {
		$webhook_id  = $this->insert_webhook( 'https://hooks.example.com/cron', 'secret' );
		$this->responses['https://hooks.example.com/cron'] = array( 'status' => 200, 'body' => 'ok' );

		( new Dispatcher() )->cron_dispatch(
			array(
				'webhook_id'    => $webhook_id,
				'event'         => 'post.published',
				'payload'       => array( 'id' => 1 ),
				'attempt'       => 1,
				'delivery_uuid' => 'fixed-delivery-uuid',
			)
		);

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_WEBHOOK_DELIVERIES . ' WHERE webhook_id = %d',
				$webhook_id
			)
		);
		$this->assertSame( 1, $count );
	}

	public function test_dispatcher_schedules_retry_on_5xx_failure(): void {
		$webhook_id = $this->insert_webhook( 'https://hooks.example.com/fail', 'secret' );
		$this->responses['https://hooks.example.com/fail'] = array( 'status' => 502, 'body' => 'bad' );

		( new Dispatcher() )->cron_dispatch(
			array(
				'webhook_id'    => $webhook_id,
				'event'         => 'post.published',
				'payload'       => array( 'id' => 1 ),
				'attempt'       => 1,
				'delivery_uuid' => 'retry-uuid',
			)
		);

		// Drilling into wp_cron — the retry should have scheduled the same hook.
		$next = wp_next_scheduled(
			'wodo_bridge_dispatch_webhook',
			array(
				array(
					'webhook_id'    => $webhook_id,
					'event'         => 'post.published',
					'payload'       => array( 'id' => 1 ),
					'attempt'       => 2,
					'delivery_uuid' => 'retry-uuid',
				),
			)
		);

		$this->assertNotFalse( $next, 'retry should be queued in wp_cron' );
	}

	public function test_cron_dispatch_does_not_send_if_url_invalid(): void {
		$webhook_id = $this->insert_webhook( 'https://localhost/bad', 'secret' );

		( new Dispatcher() )->cron_dispatch(
			array(
				'webhook_id'    => $webhook_id,
				'event'         => 'post.published',
				'payload'       => array(),
				'attempt'       => 1,
				'delivery_uuid' => 'localhost-uuid',
			)
		);

		$this->assertCount( 0, $this->captured, 'localhost target should be blocked at send time' );
	}

	private function insert_webhook( string $url, string $secret ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . Constants::TABLE_WEBHOOKS,
			array(
				'target_url'  => $url,
				'secret'      => $secret,
				'events_json' => wp_json_encode( array( 'post.published' ) ),
				'active'      => 1,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}
}
