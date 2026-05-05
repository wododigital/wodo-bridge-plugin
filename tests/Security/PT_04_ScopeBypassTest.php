<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-04: scope-confusion. A token with only posts.read calling POST /posts
 * must hit a 403 (`scope_missing`) — never a 200/201.
 *
 * @group security
 */
final class PT_04_ScopeBypassTest extends TestCase {

	public function test_posts_read_token_cannot_create_post(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array( 'title' => 'should not write' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assert_error_envelope( $response, 'scope_missing' );
	}

	public function test_media_read_token_cannot_call_posts_write(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'media.read' ) );
		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array( 'title' => 'cross-scope try' )
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_admin_full_overrides_scope_check(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'admin.full' ) );
		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array( 'title' => 'admin can' )
		);

		$this->assertSame( 201, $response->get_status() );
	}
}
