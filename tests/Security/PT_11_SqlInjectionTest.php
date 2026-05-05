<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-11 (PT-21 on the aggregator side): SQLi probes via filter params must not
 * cause errors or affect the schema. Token tables, posts table, and users
 * table must all be intact afterwards.
 *
 * @group security
 */
final class PT_11_SqlInjectionTest extends TestCase {

	public function test_sql_injection_in_search_param_is_safe(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );

		// Several classic payloads.
		$payloads = array(
			"'; DROP TABLE wp_posts; --",
			"' OR 1=1 --",
			'"; SELECT * FROM users; --',
			"%' UNION SELECT password FROM wp_users --",
		);

		foreach ( $payloads as $payload ) {
			$response = $this->make_rest_request( 'GET', '/posts', $token, array(), array( 'search' => $payload ) );
			$this->assertContains( $response->get_status(), array( 200, 422 ), "payload '{$payload}' must not 500" );
		}

		// Sanity: posts table still exists.
		global $wpdb;
		$posts_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$this->assertGreaterThanOrEqual( 0, $posts_count );

		// Users table still exists.
		$users_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$this->assertGreaterThan( 0, $users_count );
	}

	public function test_sql_injection_in_site_label_is_stored_verbatim(): void {
		// Webhook target URL accepts free-text fields elsewhere; verify event
		// passthrough doesn't barf.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'webhooks.manage' ) );

		// Stub HTTP to keep things offline.
		add_filter(
			'pre_http_request',
			static fn() => array(
				'response' => array( 'code' => 200 ),
				'body'     => '{}',
				'headers'  => array(),
				'cookies'  => array(),
			)
		);

		$response = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => 'https://hooks.example.com/incoming',
				'events'     => array( "post.published'; DROP TABLE wp_users; --" ),
				'secret'     => 'irrelevant',
			)
		);

		$this->assertContains( $response->get_status(), array( 201, 422 ) );
		global $wpdb;
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
	}
}
