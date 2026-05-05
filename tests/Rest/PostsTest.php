<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class PostsTest extends TestCase {

	public function test_list_posts_returns_200_for_posts_read_token(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::factory()->post->create_many( 3, array( 'post_author' => $user_id ) );

		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request( 'GET', '/posts', $token );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_post_returns_single_post_payload(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create(
			array( 'post_author' => $user_id, 'post_title' => 'Hello' )
		);

		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request( 'GET', '/posts/' . $post_id, $token );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $post_id, $data['id'] ?? null );
	}

	public function test_create_post_with_posts_write_succeeds(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.write' ) );
		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array(
				'title'   => 'API created post',
				'content' => 'Body',
				'status'  => 'draft',
				'type'    => 'post',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['id'] ?? null );
	}

	public function test_update_post_with_posts_write_succeeds(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		$response = $this->make_rest_request(
			'PUT',
			'/posts/' . $post_id,
			$token,
			array( 'title' => 'updated title' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'updated title', get_post( $post_id )->post_title );
	}

	public function test_postmeta_allowlist_rejects_edit_lock_meta(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array(
				'title' => 'Sneaky',
				'meta'  => array(
					'_edit_lock' => '999999:1',
					'_thumbnail_id' => 0, // allow-listed
				),
			)
		);

		// Either accepted with the disallowed key dropped, or 422; both are
		// acceptable per spec — we only care that _edit_lock didn't land.
		if ( $response->get_status() === 201 ) {
			$post_id    = (int) ( $response->get_data()['id'] ?? 0 );
			$edit_lock  = get_post_meta( $post_id, '_edit_lock', true );
			$this->assertSame( '', $edit_lock, '_edit_lock must never be writable via the bridge' );
		} else {
			$this->assertSame( 422, $response->get_status() );
		}
	}

	public function test_create_post_without_write_scope_is_403(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array( 'title' => 'denied' )
		);

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_delete_post_with_force_removes_post(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		$response = $this->make_rest_request( 'DELETE', '/posts/' . $post_id, $token, array(), array( 'force' => 'true' ) );
		$this->assertContains( $response->get_status(), array( 200, 204 ) );

		$this->assertNull( get_post( $post_id ) );
	}
}
