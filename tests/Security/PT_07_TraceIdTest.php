<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-07: every error response must carry a unique trace_id.
 *
 * @group security
 */
final class PT_07_TraceIdTest extends TestCase {

	public function test_unauthorized_response_carries_trace_id(): void {
		$response = $this->make_rest_request( 'GET', '/posts' );

		$this->assertSame( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['error']['trace_id'] ?? '' );
		$this->assertIsString( $data['error']['trace_id'] );
	}

	public function test_two_error_responses_have_distinct_trace_ids(): void {
		$one = $this->make_rest_request( 'GET', '/posts' )->get_data();
		$two = $this->make_rest_request( 'GET', '/posts' )->get_data();

		$this->assertNotSame(
			$one['error']['trace_id'] ?? null,
			$two['error']['trace_id'] ?? null,
			'each error must produce a fresh trace_id'
		);
	}
}
