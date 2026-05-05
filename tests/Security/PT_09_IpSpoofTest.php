<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * PT-09: X-Forwarded-For without WB_TRUST_PROXY must NOT change the recorded
 * IP — proxy_trusted should be false in activity rows.
 *
 * @group security
 */
final class PT_09_IpSpoofTest extends TestCase {

	public function test_xff_is_ignored_when_wb_trust_proxy_is_off(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 10.0.0.1';

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );
		$this->make_rest_request( 'GET', '/posts', $token );

		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( '203.0.113.10', (string) $row['ip'] );
		$this->assertSame( '0', (string) $row['proxy_trusted'] );
	}
}
