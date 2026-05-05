<?php
/**
 * Shared base class for plugin tests.
 *
 * @package WODO_Bridge\Tests
 */

declare( strict_types=1 );

namespace WODO_Bridge\Tests;

use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Lib\Constants;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Adds:
 *   - create_token()       — insert a row into wp_wodo_bridge_tokens with a chosen
 *                            scope set, attached to a fresh app password.
 *   - make_rest_request()  — build + dispatch a REST request as that token.
 *   - assert_error_envelope() — validate the unified error envelope shape.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Reset rate-limit table and tokens table between tests so token-bucket
	 * state doesn't leak across tests within a class.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_TOKENS );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_RATE_LIMIT );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_ACTIVITY );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_WEBHOOKS );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . Constants::TABLE_WEBHOOK_DELIVERIES );
	}

	/**
	 * Insert a token row with the given user + scopes. Returns the normalized
	 * row (matches Token_Service::lookup_by_uuid()).
	 *
	 * @param int      $user_id WP user the token should authenticate as.
	 * @param string[] $scopes  Granted scopes (e.g. ['posts.read']).
	 *
	 * @return array{id:int,app_password_uuid:string,wp_user_id:int,scopes:string[]}
	 */
	protected function create_token( int $user_id, array $scopes ): array {
		$service = new Token_Service();
		$uuid    = wp_generate_uuid4();
		$service->upsert(
			array(
				'app_password_uuid' => $uuid,
				'wp_user_id'        => $user_id,
				'scopes_json'       => wp_json_encode( $service->sanitize_scopes( $scopes ) ),
				'label'             => 'phpunit-token',
				'aggregator_origin' => 'https://test.example',
			)
		);
		$row = $service->lookup_by_uuid( $uuid );
		if ( $row === null ) {
			throw new \RuntimeException( 'Failed to create token row in fixture.' );
		}
		return $row;
	}

	/**
	 * Issue a REST request as the supplied token. Sets the X-WB-AppPassword-UUID
	 * header that the plugin's Auth_Filter uses to resolve a token row, and
	 * pre-authenticates the WP user so capability checks pass.
	 *
	 * @param string                $method HTTP verb.
	 * @param string                $path   Request path under the bridge namespace
	 *                                      (e.g. '/posts').
	 * @param array<string,mixed>|null $token  Token row from create_token() or null
	 *                                      for an unauthenticated call.
	 * @param array<string,mixed>   $body   JSON body, optional.
	 * @param array<string,mixed>   $query  Query parameters, optional.
	 *
	 * @return WP_REST_Response
	 */
	protected function make_rest_request(
		string $method,
		string $path,
		?array $token = null,
		array $body = array(),
		array $query = array()
	): WP_REST_Response {
		$full_path = '/' . Constants::REST_NAMESPACE . $path;
		$request   = new WP_REST_Request( strtoupper( $method ), $full_path );

		if ( $token !== null ) {
			$request->set_header( 'X-WB-AppPassword-UUID', $token['app_password_uuid'] );
			wp_set_current_user( (int) $token['wp_user_id'] );
		}

		if ( ! empty( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		foreach ( $query as $k => $v ) {
			$request->set_param( (string) $k, $v );
		}

		$response = rest_do_request( $request );
		if ( ! $response instanceof WP_REST_Response ) {
			$response = rest_ensure_response( $response );
		}
		return $response;
	}

	/**
	 * Assert an error envelope is well-formed and matches the expected code.
	 *
	 * @param WP_REST_Response $response Response under test.
	 * @param string           $expected_code Error code that should appear under error.code.
	 */
	protected function assert_error_envelope( WP_REST_Response $response, string $expected_code ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data, 'Error response should serialize to array' );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertFalse( $data['success'] ?? true, 'success flag should be false on errors' );
		$this->assertArrayHasKey( 'error', $data );
		$this->assertIsArray( $data['error'] );
		$this->assertSame( $expected_code, $data['error']['code'] ?? null );
		$this->assertArrayHasKey( 'trace_id', $data['error'] );
		$this->assertNotEmpty( $data['error']['trace_id'] );
		$this->assertIsString( $data['error']['trace_id'] );
	}

	/**
	 * Helper: load the bundled Elementor crypto-design fixture as an array.
	 *
	 * @return array<int,mixed>
	 */
	protected function load_elementor_fixture(): array {
		$path = __DIR__ . '/Fixtures/elementor-crypto-design.json';
		$json = (string) file_get_contents( $path );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'crypto fixture failed to decode' );
		}
		return $data;
	}
}
