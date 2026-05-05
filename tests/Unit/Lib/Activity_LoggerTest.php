<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Lib;

use WODO_Bridge\Lib\Activity_Logger;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Lib\Activity_Logger
 */
final class Activity_LoggerTest extends TestCase {

	public function test_record_inserts_row_with_expected_columns(): void {
		$logger = new Activity_Logger();
		$logger->record( 17, '/wodo-bridge/v2/posts', 'GET', 200, '', 42 );

		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( '17', (string) $row['token_id'] );
		$this->assertSame( '/wodo-bridge/v2/posts', $row['endpoint'] );
		$this->assertSame( 'GET', $row['method'] );
		$this->assertSame( '200', (string) $row['http_status'] );
		$this->assertSame( '', $row['error_code'] );
		$this->assertSame( '42', (string) $row['latency_ms'] );
	}

	public function test_record_does_not_persist_authorization_header(): void {
		// Activity_Logger never reads the Authorization header — assert that no
		// raw header bytes can be reflected from the public record() API. This
		// guards against a future regression where someone extends the row with
		// arbitrary request meta.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer should-never-appear';

		$logger = new Activity_Logger();
		$logger->record( 5, '/wodo-bridge/v2/posts', 'GET', 200, '', 1 );

		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1',
			ARRAY_A
		);

		// None of the schema columns should contain Bearer tokens or 'Bearer'.
		foreach ( $row as $col => $val ) {
			$this->assertStringNotContainsString( 'should-never-appear', (string) $val, "column {$col} leaked Authorization payload" );
			$this->assertStringNotContainsStringIgnoringCase( 'bearer', (string) $val, "column {$col} contained 'bearer'" );
		}

		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	public function test_record_clamps_negative_latency_to_zero(): void {
		$logger = new Activity_Logger();
		$logger->record( null, '/x', 'GET', 500, 'oops', -123 );

		global $wpdb;
		$latency = (int) $wpdb->get_var(
			"SELECT latency_ms FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1'
		);

		$this->assertSame( 0, $latency );
	}

	public function test_record_truncates_oversized_endpoint(): void {
		$logger = new Activity_Logger();
		$logger->record( null, str_repeat( 'a', 500 ), 'GET', 200, '', 1 );

		global $wpdb;
		$endpoint = (string) $wpdb->get_var(
			"SELECT endpoint FROM {$wpdb->prefix}" . Constants::TABLE_ACTIVITY . ' ORDER BY id DESC LIMIT 1'
		);

		$this->assertLessThanOrEqual( 191, strlen( $endpoint ) );
	}

	public function test_recent_returns_rows_in_reverse_chronological_order(): void {
		$logger = new Activity_Logger();
		$logger->record( 1, '/first', 'GET', 200, '', 1 );
		$logger->record( 2, '/second', 'GET', 200, '', 2 );
		$logger->record( 3, '/third', 'GET', 200, '', 3 );

		$rows = $logger->recent( 5 );

		$this->assertNotEmpty( $rows );
		// Most recent first.
		$this->assertSame( '/third', $rows[0]['endpoint'] );
	}

	public function test_prune_deletes_rows_older_than_retention(): void {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_ACTIVITY;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (token_id, endpoint, method, http_status, error_code, latency_ms, ip, proxy_trusted, user_agent, created_at) VALUES (%d, %s, %s, %d, %s, %d, %s, %d, %s, %s)",
				1,
				'/old',
				'GET',
				200,
				'',
				1,
				'127.0.0.1',
				0,
				'tester/1',
				gmdate( 'Y-m-d H:i:s', time() - ( ( Constants::ACTIVITY_RETENTION_DAYS + 5 ) * DAY_IN_SECONDS ) )
			)
		);

		Activity_Logger::prune();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE endpoint = %s", '/old' )
		);
		$this->assertSame( 0, $count );
	}
}
