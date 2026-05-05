<?php

declare( strict_types=1 );

namespace WODO_Bridge\Auth;

use WODO_Bridge\Lib\Activity_Logger;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Request_Context;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Auth_Filter {

	/**
	 * Per-request token store keyed by spl_object_hash of the request.
	 *
	 * @var array<string,array>
	 */
	private static array $token_store = array();

	/**
	 * UUID captured from the most recent `application_password_did_authenticate`
	 * action. Stashed at auth time so `resolve_token()` can look up the bridge
	 * token row without depending on PHP's `$_SERVER['PHP_AUTH_USER']` /
	 * `PHP_AUTH_PW` being populated by the SAPI — many hosts (Apache + PHP-FPM,
	 * LiteSpeed, certain reverse-proxy configurations) drop those superglobals
	 * even though WordPress itself reads `HTTP_AUTHORIZATION` and authenticates
	 * the user successfully. Without this fallback, our `auth_only` permission
	 * gate 401s every request from a freshly-minted App Password.
	 *
	 * @var string|null
	 */
	private static ?string $captured_uuid = null;

	private float $start_time = 0.0;

	public static function token_for( WP_REST_Request $request ): ?array {
		$key = spl_object_hash( $request );
		return self::$token_store[ $key ] ?? null;
	}

	public function register(): void {
		add_filter( 'rest_authentication_errors', array( $this, 'authenticate' ), 99 );
		add_filter( 'rest_pre_dispatch', array( $this, 'pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'post_dispatch' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'inject_token_attribute' ), 10, 3 );
		add_filter( 'application_password_did_authenticate', array( $this, 'capture_application_password' ), 10, 2 );
	}

	public function inject_token_attribute( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $this->is_bridge_request( $request ) ) {
			return $response;
		}
		$token = self::token_for( $request );
		if ( $token === null ) {
			return $response;
		}
		$attrs                       = $request->get_attributes();
		$attrs['wodo_bridge_token']  = $token;
		$request->set_attributes( $attrs );
		return $response;
	}

	public function authenticate( $result ) {
		if ( ! $this->is_bridge_request() ) {
			return $result;
		}

		if ( ! Request_Context::is_https() ) {
			return new \WP_Error(
				'insecure_transport',
				__( 'HTTPS is required for WODO Bridge requests.', 'wodo-bridge' ),
				array( 'status' => 426 )
			);
		}

		return $result;
	}

	public function capture_application_password( $user, $item ) {
		if ( ! is_array( $item ) ) {
			return $user;
		}
		$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
		if ( $uuid === '' ) {
			return $user;
		}

		// Stash for `resolve_token()` to read during `rest_pre_dispatch`. The
		// action runs once per authenticated request before route dispatch, so
		// the value is current for the in-flight request. Cleared in
		// `post_dispatch` to avoid leaking across the same PHP-FPM worker's
		// next request.
		self::$captured_uuid = $uuid;

		$service = new Token_Service();
		$row     = $service->lookup_by_uuid( $uuid );

		if ( $row === null ) {
			$service->upsert(
				array(
					'app_password_uuid' => $uuid,
					'wp_user_id'        => is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0,
					'scopes_json'       => wp_json_encode( array() ),
					'label'             => isset( $item['name'] ) ? (string) $item['name'] : '',
					'aggregator_origin' => '',
				)
			);
		}

		return $user;
	}

	public function pre_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( ! $this->is_bridge_request( $request ) ) {
			return $result;
		}

		$this->start_time = microtime( true );

		$attached = $this->resolve_token( $request );
		if ( $attached !== null ) {
			self::$token_store[ spl_object_hash( $request ) ] = $attached;

			$service = new Token_Service();
			$service->record_use( (int) $attached['id'], Request_Context::ip()['ip'] );
		}

		return $result;
	}

	public function post_dispatch( $response, $server, $request ) {
		if ( ! $this->is_bridge_request( $request ) ) {
			return $response;
		}

		$status     = 200;
		$error_code = '';

		if ( $response instanceof WP_REST_Response ) {
			$status = (int) $response->get_status();
			$data   = $response->get_data();
			if ( is_array( $data ) && isset( $data['error']['code'] ) ) {
				$error_code = (string) $data['error']['code'];
			}
		} elseif ( is_wp_error( $response ) ) {
			$data       = $response->get_error_data();
			$status     = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$error_code = (string) $response->get_error_code();
		}

		$token      = $request instanceof WP_REST_Request ? self::token_for( $request ) : null;
		$token_id   = is_array( $token ) && isset( $token['id'] ) ? (int) $token['id'] : null;
		$latency_ms = (int) round( ( microtime( true ) - $this->start_time ) * 1000 );

		if ( $request instanceof WP_REST_Request ) {
			unset( self::$token_store[ spl_object_hash( $request ) ] );
		}
		// Clear the auth-time UUID so it doesn't leak into the next request
		// served by the same PHP-FPM worker.
		self::$captured_uuid = null;

		( new Activity_Logger() )->record(
			$token_id,
			(string) ( is_object( $request ) ? $request->get_route() : '' ),
			(string) ( is_object( $request ) ? $request->get_method() : '' ),
			$status,
			$error_code,
			$latency_ms
		);

		return $response;
	}

	private function resolve_token( WP_REST_Request $request ): ?array {
		$user_id = get_current_user_id();
		if ( $user_id === 0 ) {
			return null;
		}

		$uuid = $this->detect_app_password_uuid( $request );
		if ( $uuid === '' ) {
			return null;
		}

		$service = new Token_Service();
		$row     = $service->lookup_by_uuid( $uuid );
		if ( $row === null ) {
			return null;
		}

		if ( ! empty( $row['revoked_at'] ) ) {
			return $row;
		}

		if ( (int) $row['wp_user_id'] !== $user_id ) {
			return null;
		}

		return $row;
	}

	private function detect_app_password_uuid( WP_REST_Request $request ): string {
		// 1. Explicit header from the aggregator (rare — most clients don't
		// send this).
		$header = $request->get_header( 'X-WB-AppPassword-UUID' );
		if ( is_string( $header ) && $header !== '' ) {
			return sanitize_text_field( $header );
		}

		// 2. Stashed at `application_password_did_authenticate` time. This is
		// the canonical, auth-state-independent path: if WordPress
		// successfully authenticated an App Password at all (regardless of
		// whether PHP_AUTH_* or HTTP_AUTHORIZATION populated the SAPI), the
		// action fired and `capture_application_password` recorded the UUID.
		if ( self::$captured_uuid !== null && self::$captured_uuid !== '' ) {
			return self::$captured_uuid;
		}

		// 3. Last-resort password match against PHP_AUTH_*. Only fires when
		// the SAPI happens to populate these — many hosts don't.
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof \WP_User && $user->exists() && class_exists( '\WP_Application_Passwords' ) ) {
				$candidate = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
				if ( is_array( $candidate ) ) {
					$pw = (string) $_SERVER['PHP_AUTH_PW'];
					foreach ( $candidate as $row ) {
						if ( ! is_array( $row ) || empty( $row['password'] ) ) {
							continue;
						}
						if ( wp_check_password( $pw, (string) $row['password'], $user->ID ) ) {
							return isset( $row['uuid'] ) ? (string) $row['uuid'] : '';
						}
					}
				}
			}
		}

		return '';
	}

	private function is_bridge_request( ?WP_REST_Request $request = null ): bool {
		if ( $request !== null ) {
			$route = (string) $request->get_route();
			return strpos( $route, '/' . Constants::REST_NAMESPACE ) === 0;
		}

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		return strpos( $path, '/wp-json/' . Constants::REST_NAMESPACE ) !== false || strpos( $path, '?rest_route=/' . Constants::REST_NAMESPACE ) !== false;
	}
}
