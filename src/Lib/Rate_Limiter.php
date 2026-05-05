<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rate_Limiter {

	public function consume( string $bucket, string $identity, int $cost = 1 ): array {
		$config = Constants::RATE_LIMITS[ $bucket ] ?? Constants::RATE_LIMITS[ Constants::RATE_BUCKET_READ_CHEAP ];
		$limit  = (int) $config['limit'];
		$window = (int) $config['window'];

		$key  = $this->hash_key( $bucket, $identity );
		$now  = microtime( true );
		$rate = $limit / $window;

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_RATE_LIMIT;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tokens, UNIX_TIMESTAMP(last_refill) AS last_refill FROM `{$table}` WHERE bucket_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			),
			ARRAY_A
		);

		if ( $row === null ) {
			$tokens      = (float) $limit - $cost;
			$last_refill = $now;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO `{$table}` (bucket_key, tokens, last_refill) VALUES (%s, %f, FROM_UNIXTIME(%f)) ON DUPLICATE KEY UPDATE tokens = VALUES(tokens), last_refill = VALUES(last_refill)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					$tokens,
					$now
				)
			);
			return $this->result( $tokens >= 0, $tokens, $limit, $window, $rate );
		}

		$elapsed     = max( 0.0, $now - (float) $row['last_refill'] );
		$current     = min( (float) $limit, (float) $row['tokens'] + ( $elapsed * $rate ) );
		$new_tokens  = $current - $cost;
		$last_refill = $now;
		$allowed     = $new_tokens >= 0;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE `{$table}` SET tokens = %f, last_refill = FROM_UNIXTIME(%f) WHERE bucket_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$allowed ? $new_tokens : (float) $row['tokens'] + ( $elapsed * $rate ),
				$last_refill,
				$key
			)
		);

		return $this->result( $allowed, $new_tokens, $limit, $window, $rate );
	}

	private function result( bool $allowed, float $tokens, int $limit, int $window, float $rate ): array {
		$remaining   = (int) max( 0, floor( $tokens ) );
		$retry_after = $allowed ? 0 : (int) max( 1, ceil( ( 1 - $tokens ) / max( $rate, 0.0001 ) ) );

		return array(
			'allowed'     => $allowed,
			'remaining'   => $remaining,
			'limit'       => $limit,
			'window'      => $window,
			'retry_after' => $retry_after,
		);
	}

	private function hash_key( string $bucket, string $identity ): string {
		return mb_substr( $bucket . ':' . hash( 'sha256', $identity ), 0, 96 );
	}

	public static function prune(): void {
		global $wpdb;
		$table  = $wpdb->prefix . Constants::TABLE_RATE_LIMIT;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - Constants::RATE_LIMIT_TTL_SECONDS );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE last_refill < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}
}
