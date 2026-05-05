<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activity_Logger {

	public function record(
		?int $token_id,
		string $endpoint,
		string $method,
		int $http_status,
		string $error_code,
		int $latency_ms
	): void {
		global $wpdb;
		$table   = $wpdb->prefix . Constants::TABLE_ACTIVITY;
		$context = Request_Context::ip();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'token_id'      => $token_id,
				'endpoint'      => mb_substr( $endpoint, 0, 191 ),
				'method'        => mb_substr( strtoupper( $method ), 0, 10 ),
				'http_status'   => $http_status,
				'error_code'    => mb_substr( $error_code, 0, 64 ),
				'latency_ms'    => max( 0, $latency_ms ),
				'ip'            => $context['ip'],
				'proxy_trusted' => $context['proxy_trusted'] ? 1 : 0,
				'user_agent'    => Request_Context::user_agent(),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_ACTIVITY;
		$limit = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, token_id, endpoint, method, http_status, error_code, latency_ms, ip, proxy_trusted, created_at FROM `{$table}` ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public static function prune(): void {
		global $wpdb;
		$table   = $wpdb->prefix . Constants::TABLE_ACTIVITY;
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( Constants::ACTIVITY_RETENTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}
}
