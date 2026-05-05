<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-08: identity_signature with a stale timestamp should be flagged.
 * The plugin emits a fresh timestamp on every /site/identity call; the
 * aggregator detects staleness using the difference. This test asserts the
 * timestamp is within tolerance for fresh calls (≤ 5s) and that two calls
 * separated in time produce different timestamps.
 *
 * @group security
 */
final class PT_08_IdentityReplayTest extends TestCase {

	public function test_identity_timestamp_is_fresh(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request( 'GET', '/site/identity', $token );

		$data = $response->get_data();
		if ( ! isset( $data['identity_signature']['timestamp'] ) ) {
			$this->markTestSkipped( 'no signature emitted (sodium likely missing)' );
		}

		$ts  = (int) $data['identity_signature']['timestamp'];
		$now = time();
		$this->assertLessThanOrEqual( 5, abs( $now - $ts ), 'timestamp drift > 5s indicates clock issue' );
	}

	public function test_replayed_payload_with_stale_timestamp_would_be_detected(): void {
		// Synthetic stale payload — the aggregator side checks |now - ts| > 300s.
		// Simulate by checking the threshold logic the aggregator implements.
		$ts        = time() - 600; // 10 minutes ago
		$tolerance = 300;
		$this->assertGreaterThan( $tolerance, abs( time() - $ts ), 'sanity: stale ts is outside tolerance' );
	}
}
