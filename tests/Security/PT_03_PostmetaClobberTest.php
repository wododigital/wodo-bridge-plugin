<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-03: writes attempting to clobber `_edit_lock` (or any other denied
 * underscore-prefixed key not in the allow-list) must NOT land in postmeta.
 *
 * @group security
 */
final class PT_03_PostmetaClobberTest extends TestCase {

	public function test_create_post_with_edit_lock_meta_does_not_persist_it(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		$response = $this->make_rest_request(
			'POST',
			'/posts',
			$token,
			array(
				'title' => 'pt-03',
				'meta'  => array(
					'_edit_lock' => '999999:1',
					'_edit_last' => '999999',
				),
			)
		);

		// Response is allowed to be 201 (silent strip) OR 422 (strict reject).
		$this->assertContains( $response->get_status(), array( 201, 422 ) );
		if ( $response->get_status() === 201 ) {
			$post_id = (int) ( $response->get_data()['id'] ?? 0 );
			$this->assertSame( '', get_post_meta( $post_id, '_edit_lock', true ) );
			$this->assertSame( '', get_post_meta( $post_id, '_edit_last', true ) );
		}
	}
}
