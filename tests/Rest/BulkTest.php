<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class BulkTest extends TestCase {

	public function test_bulk_create_50_posts_returns_summary_with_per_item_results(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		$ops = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$ops[] = array(
				'action' => 'create',
				'data'   => array( 'title' => "Bulk #{$i}", 'content' => 'x' ),
			);
		}

		$response = $this->make_rest_request( 'POST', '/bulk/posts', $token, array( 'operations' => $ops ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 50, $data['summary']['total'] );
		$this->assertCount( 50, $data['results'] );
		$this->assertSame( 50, $data['summary']['succeeded'] );
		$this->assertSame( 0, $data['summary']['failed'] );
	}

	public function test_bulk_with_partial_failure_keeps_succeeded_rows(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.write' ) );

		// Op 0: valid create. Op 1: update without id → must fail validation.
		$response = $this->make_rest_request(
			'POST',
			'/bulk/posts',
			$token,
			array(
				'operations' => array(
					array( 'action' => 'create', 'data' => array( 'title' => 'A' ) ),
					array( 'action' => 'update', 'data' => array( 'title' => 'no id' ) ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['summary']['succeeded'] );
		$this->assertSame( 1, $data['summary']['failed'] );
	}

	public function test_bulk_without_write_scope_returns_403(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request(
			'POST',
			'/bulk/posts',
			$token,
			array( 'operations' => array( array( 'action' => 'create', 'data' => array( 'title' => 'x' ) ) ) )
		);

		$this->assertSame( 403, $response->get_status() );
	}
}
