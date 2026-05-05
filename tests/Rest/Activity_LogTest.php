<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class Activity_LogTest extends TestCase {

	public function test_authenticated_request_writes_one_activity_row(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );

		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY );

		$this->make_rest_request( 'GET', '/posts', $token );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY );
		$this->assertSame( $before + 1, $after );
	}

	public function test_failed_auth_request_still_records_activity_row(): void {
		// No token attached.
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY );

		$this->make_rest_request( 'GET', '/posts' );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY );
		$this->assertGreaterThan( $before, $after, 'failures must also be logged' );

		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertSame( 401, (int) $row['http_status'] );
	}

	public function test_activity_endpoint_has_request_metadata(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );
		$_SERVER['HTTP_USER_AGENT'] = 'wodo-bridge-test/1.0';

		$this->make_rest_request( 'GET', '/posts', $token );

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'GET', $row['method'] );
		$this->assertStringContainsString( '/posts', (string) $row['endpoint'] );
		$this->assertSame( 'wodo-bridge-test/1.0', (string) $row['user_agent'] );
	}
}
