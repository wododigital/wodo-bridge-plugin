<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Tests\TestCase;

/**
 * REST auth filter behaviour: missing scope, revoked token, etc.
 *
 * @group rest
 */
final class Auth_FlowTest extends TestCase {

	public function test_unauthenticated_request_to_protected_route_returns_401(): void {
		$response = $this->make_rest_request( 'GET', '/posts' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_token_missing_required_scope_returns_403(): void {
		$user  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = $this->create_token( $user, array( 'media.read' ) );

		$response = $this->make_rest_request( 'GET', '/posts', $token );

		$this->assertSame( 403, $response->get_status() );
		$this->assert_error_envelope( $response, 'scope_missing' );
	}

	public function test_revoked_token_returns_401_token_revoked(): void {
		$user  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = $this->create_token( $user, array( 'posts.read' ) );

		( new Token_Service() )->revoke( $token['id'] );

		$response = $this->make_rest_request( 'GET', '/posts', $token );

		$this->assertSame( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'token_revoked', $data['error']['code'] ?? null );
	}

	public function test_admin_full_scope_grants_access_to_any_endpoint(): void {
		$user  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token = $this->create_token( $user, array( 'admin.full' ) );

		$response = $this->make_rest_request( 'GET', '/posts', $token );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_health_endpoint_is_unauthenticated(): void {
		$response = $this->make_rest_request( 'GET', '/health' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'wodo-bridge', $data['plugin'] ?? null );
		$this->assertSame( 'ok', $data['status'] ?? null );
	}
}
