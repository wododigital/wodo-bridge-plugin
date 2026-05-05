<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * PT-10: Authorization header content must not be persisted in activity rows.
 *
 * @group security
 */
final class PT_10_AuthHeaderRedactedTest extends TestCase {

	public function test_authorization_header_does_not_leak_into_activity_log(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer NEVER-LEAK-MEFIRST-' . wp_generate_uuid4();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );
		$this->make_rest_request( 'GET', '/posts', $token );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY, ARRAY_A );

		foreach ( $rows as $row ) {
			foreach ( $row as $col => $val ) {
				$this->assertStringNotContainsString( 'NEVER-LEAK-MEFIRST', (string) $val, "column {$col} leaked the bearer" );
			}
		}

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}
}
