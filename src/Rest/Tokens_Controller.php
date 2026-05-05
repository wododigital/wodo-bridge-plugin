<?php

declare( strict_types=1 );

namespace WODO_Bridge\Rest;

use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Lib\Errors;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-facing token endpoints.
 *
 * Auth model:
 *  - Bridge-scoped tokens with `admin.full` may revoke any token.
 *  - WP admin users (manage_options) hitting these via wp_rest nonce may revoke
 *    their own tokens, or any token if they hold `manage_options`.
 *
 * Endpoints:
 *   GET    /auth/tokens
 *   DELETE /auth/tokens/{id}
 */
final class Tokens_Controller {

	private Token_Service $tokens;

	public function __construct( ?Token_Service $tokens = null ) {
		$this->tokens = $tokens ?? new Token_Service();
	}

	/**
	 * Permission gate. Allow if:
	 *  - WP user has manage_options (admin UI via wp_rest nonce), OR
	 *  - Caller holds admin.full scope on a Bridge token.
	 */
	public function can_manage( WP_REST_Request $request ): bool {
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

	/**
	 * GET /auth/tokens
	 *
	 * Query: per_page (1..100, default 25), page (>=1, default 1),
	 *        status (active|revoked|all, default active),
	 *        scope (one of the canonical scopes; filters tokens that include it).
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?? 25 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$status   = (string) ( $request->get_param( 'status' ) ?? 'active' );
		$scope    = (string) ( $request->get_param( 'scope' ) ?? '' );

		$include_revoked = ( $status === 'revoked' || $status === 'all' );

		// Token_Service::list_all() returns up to 500 rows, normalized.
		$rows = $this->tokens->list_all( $include_revoked );

		// Apply status filter (revoked-only).
		if ( $status === 'revoked' ) {
			$rows = array_values( array_filter(
				$rows,
				static fn( array $r ): bool => ! empty( $r['revoked_at'] )
			) );
		} elseif ( $status === 'active' ) {
			$rows = array_values( array_filter(
				$rows,
				static fn( array $r ): bool => empty( $r['revoked_at'] )
			) );
		}

		// Apply scope filter.
		if ( $scope !== '' ) {
			$rows = array_values( array_filter(
				$rows,
				static fn( array $r ): bool => is_array( $r['scopes'] ?? null )
					&& in_array( $scope, $r['scopes'], true )
			) );
		}

		// Decorate with WP user display info.
		foreach ( $rows as &$row ) {
			$user = get_user_by( 'id', (int) $row['wp_user_id'] );
			$row['wp_user'] = array(
				'id'           => (int) $row['wp_user_id'],
				'login'        => $user ? (string) $user->user_login : '',
				'display_name' => $user ? (string) $user->display_name : '',
			);
		}
		unset( $row );

		$total       = count( $rows );
		$total_pages = (int) max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$slice       = array_slice( $rows, $offset, $per_page );

		$response = new WP_REST_Response( array(
			'tokens'      => $slice,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		), 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return $response;
	}

	/**
	 * DELETE /auth/tokens/{id}
	 *
	 * Revokes the token row AND the underlying WP application password
	 * so the credential can no longer authenticate.
	 */
	public function revoke( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return Errors::validation( __( 'Invalid token id.', 'wodo-bridge' ) );
		}

		$row = $this->tokens->lookup_by_id( $id );
		if ( $row === null ) {
			return Errors::not_found( __( 'Token not found.', 'wodo-bridge' ) );
		}

		// If the caller is not an admin, they may only revoke their own token.
		if ( ! current_user_can( 'manage_options' ) ) {
			$attrs       = $request->get_attributes();
			$token       = is_array( $attrs['wodo_bridge_token'] ?? null ) ? $attrs['wodo_bridge_token'] : array();
			$caller_user = (int) ( $token['wp_user_id'] ?? 0 );
			$scopes      = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
			if ( ! in_array( 'admin.full', $scopes, true ) && $caller_user !== (int) $row['wp_user_id'] ) {
				return Errors::forbidden();
			}
		}

		// Best-effort revoke the underlying WP App Password.
		if ( class_exists( '\WP_Application_Passwords' ) && ! empty( $row['app_password_uuid'] ) ) {
			\WP_Application_Passwords::delete_application_password(
				(int) $row['wp_user_id'],
				(string) $row['app_password_uuid']
			);
		}

		$ok = $this->tokens->revoke( $id );
		if ( ! $ok ) {
			return Errors::envelope( 'revoke_failed', __( 'Could not revoke token.', 'wodo-bridge' ), 500 );
		}

		return new WP_REST_Response( array(
			'revoked' => true,
			'id'      => $id,
		), 200 );
	}
}
