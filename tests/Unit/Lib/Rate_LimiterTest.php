<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Lib;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Rate_Limiter;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Lib\Rate_Limiter
 */
final class Rate_LimiterTest extends TestCase {

	private Rate_Limiter $limiter;

	public function set_up(): void {
		parent::set_up();
		$this->limiter = new Rate_Limiter();
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_RATE_LIMIT );
	}

	public function test_first_consume_returns_allowed(): void {
		$result = $this->limiter->consume( Constants::RATE_BUCKET_READ_CHEAP, 'token:1' );

		$this->assertTrue( $result['allowed'] );
		$this->assertSame( 60, $result['limit'] );
		$this->assertGreaterThan( 0, $result['remaining'] );
	}

	public function test_exhausting_bucket_yields_429_with_retry_after(): void {
		// Use the bulk bucket which is the smallest (5/min) — fastest to exhaust.
		for ( $i = 0; $i < 5; $i++ ) {
			$ok = $this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:1' );
			$this->assertTrue( $ok['allowed'], "Request {$i} of 5 should be allowed" );
		}

		$denied = $this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:1' );

		$this->assertFalse( $denied['allowed'] );
		$this->assertGreaterThanOrEqual( 1, $denied['retry_after'] );
		$this->assertSame( 0, $denied['remaining'] );
	}

	public function test_separate_identities_have_independent_buckets(): void {
		// Drain one identity completely.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:1' );
		}

		// Different identity should still be allowed.
		$other = $this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:2' );

		$this->assertTrue( $other['allowed'] );
	}

	public function test_separate_buckets_have_independent_state(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:1' );
		}

		// Same identity, different bucket → fresh allowance.
		$other = $this->limiter->consume( Constants::RATE_BUCKET_READ_CHEAP, 'token:1' );

		$this->assertTrue( $other['allowed'] );
	}

	public function test_unknown_bucket_falls_back_to_read_cheap_limits(): void {
		$result = $this->limiter->consume( 'never_registered_bucket', 'token:1' );

		$this->assertTrue( $result['allowed'] );
		$this->assertSame( 60, $result['limit'], 'falls back to read_cheap limit (60/min)' );
	}

	public function test_remaining_decreases_with_each_consume(): void {
		$first  = $this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:5' );
		$second = $this->limiter->consume( Constants::RATE_BUCKET_BULK, 'token:5' );

		$this->assertGreaterThanOrEqual( $second['remaining'], $first['remaining'] );
	}

	public function test_prune_deletes_old_buckets(): void {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_RATE_LIMIT;

		// Manually insert a long-stale row.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (bucket_key, tokens, last_refill) VALUES (%s, %f, %s)",
				'fake:stale',
				1.0,
				gmdate( 'Y-m-d H:i:s', time() - ( Constants::RATE_LIMIT_TTL_SECONDS + 60 ) )
			)
		);

		Rate_Limiter::prune();

		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE bucket_key = %s", 'fake:stale' ) );
		$this->assertSame( 0, $count, 'stale rows should be pruned' );
	}

	public function test_returned_window_matches_bucket_config(): void {
		$result = $this->limiter->consume( Constants::RATE_BUCKET_READ_EXPENSIVE, 'token:exp' );

		$this->assertSame( 10, $result['limit'] );
		$this->assertSame( 60, $result['window'] );
	}
}
