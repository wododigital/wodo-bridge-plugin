<?php

declare( strict_types=1 );

namespace WODO_Bridge\Rest;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Errors;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity log endpoints for the admin UI.
 *
 *   GET /activity                — paginated, filterable
 *   GET /activity/distinct       — distinct endpoints / tokens for filter dropdowns
 *   GET /activity/export.csv     — last 1000 matching rows as CSV
 */
final class Activity_Controller {

	private const MAX_PER_PAGE     = 100;
	private const DEFAULT_PER_PAGE = 25;
	private const EXPORT_LIMIT     = 1000;

	public function can_read( WP_REST_Request $request ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$attrs = $request->get_attributes();
		$token = is_array( $attrs['wodo_bridge_token'] ?? null ) ? $attrs['wodo_bridge_token'] : null;
		if ( is_array( $token ) ) {
			$scopes = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
			if ( in_array( 'admin.full', $scopes, true ) ) {
				return true;
			}
		}
		return false;
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $request->get_param( 'per_page' ) ?? self::DEFAULT_PER_PAGE ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );

		[ $where, $params ] = $this->build_where( $request );

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_ACTIVITY;

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM `{$table}`";
		if ( $where !== '' ) {
			$count_sql .= ' WHERE ' . $where;
		}
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params )
		);

		$offset = ( $page - 1 ) * $per_page;
		$sql    = "SELECT id, token_id, endpoint, method, http_status, error_code, latency_ms, ip, proxy_trusted, user_agent, created_at FROM `{$table}`";
		if ( $where !== '' ) {
			$sql .= ' WHERE ' . $where;
		}
		$sql .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows = $this->decorate_rows( $rows );

		$total_pages = (int) max( 1, (int) ceil( $total / $per_page ) );
		$response    = new WP_REST_Response( array(
			'activity'    => $rows,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		), 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return $response;
	}

	public function distinct( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$activity = $wpdb->prefix . Constants::TABLE_ACTIVITY;
		$tokens   = $wpdb->prefix . Constants::TABLE_TOKENS;

		$endpoints = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			"SELECT DISTINCT endpoint FROM `{$activity}` WHERE endpoint <> '' ORDER BY endpoint ASC LIMIT 500"
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			"SELECT id, label, wp_user_id, revoked_at FROM `{$tokens}` ORDER BY created_at DESC LIMIT 500",
			ARRAY_A
		);

		$token_options = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$token_options[] = array(
					'id'     => (int) $row['id'],
					'label'  => (string) $row['label'],
					'active' => empty( $row['revoked_at'] ),
				);
			}
		}

		return new WP_REST_Response( array(
			'endpoints' => is_array( $endpoints ) ? array_values( array_map( 'strval', $endpoints ) ) : array(),
			'tokens'    => $token_options,
		), 200 );
	}

	/**
	 * Stream CSV export of up to EXPORT_LIMIT matching rows.
	 *
	 * Note: this is invoked through the REST stack, so we render to a string
	 * and let WP send the body. We override the content type via headers().
	 */
	public function export_csv( WP_REST_Request $request ): WP_REST_Response {
		[ $where, $params ] = $this->build_where( $request );

		global $wpdb;
		$table = $wpdb->prefix . Constants::TABLE_ACTIVITY;

		$sql = "SELECT id, token_id, endpoint, method, http_status, error_code, latency_ms, ip, proxy_trusted, user_agent, created_at FROM `{$table}`";
		if ( $where !== '' ) {
			$sql .= ' WHERE ' . $where;
		}
		$sql .= ' ORDER BY created_at DESC LIMIT %d';

		$query_params   = $params;
		$query_params[] = self::EXPORT_LIMIT;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows = $this->decorate_rows( $rows );

		$fh = fopen( 'php://temp', 'w+' );
		if ( $fh === false ) {
			return Errors::envelope( 'export_failed', __( 'Could not generate export.', 'wodo-bridge' ), 500 );
		}

		fputcsv( $fh, array(
			'timestamp_utc',
			'endpoint',
			'method',
			'http_status',
			'error_code',
			'latency_ms',
			'token_id',
			'token_label',
			'ip',
			'proxy_trusted',
			'user_agent',
		) );

		foreach ( $rows as $row ) {
			fputcsv( $fh, array(
				(string) ( $row['created_at'] ?? '' ),
				(string) ( $row['endpoint'] ?? '' ),
				(string) ( $row['method'] ?? '' ),
				(string) ( $row['http_status'] ?? '' ),
				(string) ( $row['error_code'] ?? '' ),
				(string) ( $row['latency_ms'] ?? '' ),
				(string) ( $row['token_id'] ?? '' ),
				(string) ( $row['token_label'] ?? '' ),
				(string) ( $row['ip'] ?? '' ),
				! empty( $row['proxy_trusted'] ) ? '1' : '0',
				(string) ( $row['user_agent'] ?? '' ),
			) );
		}

		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );

		$filename = 'wodo-bridge-activity-' . gmdate( 'Ymd-His' ) . '.csv';

		// Bypass REST JSON serialization for raw CSV body.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $csv ) );

		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, content-typed.
		exit;
	}

	/**
	 * Build a parameterised WHERE clause from filter params.
	 *
	 * @return array{0:string,1:array<int,scalar>}
	 */
	private function build_where( WP_REST_Request $request ): array {
		$clauses = array();
		$params  = array();

		$token_id = (int) ( $request->get_param( 'token_id' ) ?? 0 );
		if ( $token_id > 0 ) {
			$clauses[] = 'token_id = %d';
			$params[]  = $token_id;
		}

		$endpoint = (string) ( $request->get_param( 'endpoint' ) ?? '' );
		if ( $endpoint !== '' ) {
			$clauses[] = 'endpoint = %s';
			$params[]  = $endpoint;
		}

		$status = (string) ( $request->get_param( 'status' ) ?? '' );
		if ( $status === 'success' ) {
			$clauses[] = 'http_status BETWEEN 200 AND 399';
		} elseif ( $status === 'error' ) {
			$clauses[] = 'http_status >= 400';
		}

		$method = strtoupper( (string) ( $request->get_param( 'method' ) ?? '' ) );
		if ( in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ), true ) ) {
			$clauses[] = 'method = %s';
			$params[]  = $method;
		}

		$from = (string) ( $request->get_param( 'from' ) ?? '' );
		if ( $this->is_iso_date( $from ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $this->normalize_date( $from );
		}

		$to = (string) ( $request->get_param( 'to' ) ?? '' );
		if ( $this->is_iso_date( $to ) ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $this->normalize_date( $to, true );
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	private function is_iso_date( string $date ): bool {
		return $date !== '' && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}/', $date );
	}

	private function normalize_date( string $date, bool $end_of_day = false ): string {
		if ( strlen( $date ) === 10 ) {
			return $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
		}
		// Already includes a time.
		return str_replace( array( 'T', 'Z' ), array( ' ', '' ), $date );
	}

	/**
	 * Add token_label to each row for the UI (never expose the raw uuid).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function decorate_rows( array $rows ): array {
		if ( empty( $rows ) ) {
			return $rows;
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['token_id'] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		$labels = array();
		if ( ! empty( $ids ) ) {
			global $wpdb;
			$table        = $wpdb->prefix . Constants::TABLE_TOKENS;
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = "SELECT id, label FROM `{$table}` WHERE id IN ({$placeholders})";
			$prepared     = $wpdb->prepare( $sql, array_keys( $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$found        = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $found ) ) {
				foreach ( $found as $entry ) {
					$labels[ (int) $entry['id'] ] = (string) $entry['label'];
				}
			}
		}

		foreach ( $rows as &$row ) {
			$id                = (int) ( $row['token_id'] ?? 0 );
			$row['token_label'] = $id > 0 && isset( $labels[ $id ] ) ? $labels[ $id ] : '';
			$row['proxy_trusted'] = ! empty( $row['proxy_trusted'] );
		}
		unset( $row );

		return $rows;
	}
}
