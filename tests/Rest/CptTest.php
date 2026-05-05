<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class CptTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type(
			'wb_test_book',
			array(
				'public'              => true,
				'show_in_rest'        => true,
				'rest_base'           => 'books',
				'exclude_from_search' => false,
				'capability_type'     => 'post',
			)
		);
	}

	public function tear_down(): void {
		unregister_post_type( 'wb_test_book' );
		parent::tear_down();
	}

	public function test_cpt_discovery_includes_registered_test_type(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'cpt.read' ) );
		$response = $this->make_rest_request( 'GET', '/cpt', $token );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$slugs = array_column( $data['types'] ?? array(), 'slug' );
		$this->assertContains( 'wb_test_book', $slugs );
	}

	public function test_create_cpt_item_writes_post(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'cpt.write' ) );
		$response = $this->make_rest_request(
			'POST',
			'/cpt/wb_test_book',
			$token,
			array( 'title' => 'Bridge Book', 'content' => 'About bridges' )
		);

		$this->assertSame( 201, $response->get_status() );

		$post_id = (int) ( $response->get_data()['id'] ?? 0 );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'wb_test_book', get_post( $post_id )->post_type );
	}

	public function test_get_cpt_unknown_type_returns_404(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'cpt.read' ) );
		$response = $this->make_rest_request( 'GET', '/cpt/does_not_exist', $token );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_cpt_item_only_when_post_type_matches(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// Create as a regular post (different CPT).
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );

		$token    = $this->create_token( $user_id, array( 'cpt.write' ) );
		$response = $this->make_rest_request(
			'PUT',
			'/cpt/wb_test_book/' . $post_id,
			$token,
			array( 'title' => 'wrong type' )
		);

		$this->assertSame( 404, $response->get_status() );
	}
}
