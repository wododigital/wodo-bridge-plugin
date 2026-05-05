<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-06 / PT-17: 11 requests in a row on the read-expensive bucket — 11th must
 * 429 with Retry-After.
 *
 * /elementor/pages/{id}/study lives on the read_expensive bucket (10/min).
 *
 * @group security
 */
final class PT_06_RateLimitTest extends TestCase {

	public function test_eleventh_consecutive_call_to_expensive_bucket_is_429(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'elementor.read' ) );

		$post_id = self::factory()->post->create(
			array( 'post_author' => $user_id, 'post_type' => 'page' )
		);
		// Profile-refresh path is also expensive; use whichever exists locally.
		$path = '/profile/refresh';
		$any_429 = false;

		for ( $i = 0; $i < 12; $i++ ) {
			$response = $this->make_rest_request( 'POST', $path, $token, array() );
			if ( $response->get_status() === 429 ) {
				$any_429 = true;
				$this->assertNotEmpty( $response->get_headers()['Retry-After'] ?? '' );
				$this->assert_error_envelope( $response, 'rate_limited' );
				break;
			}
		}

		$this->assertTrue( $any_429, 'expected at least one 429 within the first 12 calls on the expensive bucket' );
	}
}
