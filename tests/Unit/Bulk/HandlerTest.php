<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Bulk;

use WODO_Bridge\Bulk\Handler;
use WODO_Bridge\Content\Post_Service;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;
use WP_Error;

/**
 * @covers \WODO_Bridge\Bulk\Handler
 */
final class HandlerTest extends TestCase {

	public function test_run_aggregates_per_item_results(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ops = array(
			array( 'action' => 'create', 'data' => array( 'title' => 'A', 'content' => 'a body' ) ),
			array( 'action' => 'create', 'data' => array( 'title' => 'B', 'content' => 'b body' ) ),
		);

		$result = ( new Handler() )->run( $user_id, 'post', $ops );

		$this->assertSame( 2, $result['summary']['total'] );
		$this->assertCount( 2, $result['results'] );
		$this->assertGreaterThanOrEqual( 0, $result['summary']['duration_ms'] );
	}

	public function test_run_truncates_to_bulk_max_items(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ops = array();
		for ( $i = 0; $i < Constants::BULK_MAX_ITEMS + 25; $i++ ) {
			$ops[] = array( 'action' => 'create', 'data' => array( 'title' => "p{$i}", 'content' => 'x' ) );
		}

		$result = ( new Handler() )->run( $user_id, 'post', $ops );

		$this->assertSame( Constants::BULK_MAX_ITEMS, $result['summary']['total'] );
		$this->assertCount( Constants::BULK_MAX_ITEMS, $result['results'] );
	}

	public function test_partial_success_keeps_succeeded_rows_and_records_failures(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$service = new class extends Post_Service {
			public int $calls = 0;
			public function create( int $u, string $t, array $data ): array|WP_Error {
				$this->calls++;
				if ( $this->calls === 2 ) {
					return new WP_Error( 'validation_failed', 'mock fail', array( 'status' => 422 ) );
				}
				return array( 'id' => 1000 + $this->calls, 'title' => (string) $data['title'] );
			}
		};

		$handler = new Handler( $service );
		$result  = $handler->run(
			$user_id,
			'post',
			array(
				array( 'action' => 'create', 'data' => array( 'title' => 'A' ) ),
				array( 'action' => 'create', 'data' => array( 'title' => 'B' ) ),
				array( 'action' => 'create', 'data' => array( 'title' => 'C' ) ),
			)
		);

		$this->assertSame( 3, $result['summary']['total'] );
		$this->assertSame( 2, $result['summary']['succeeded'] );
		$this->assertSame( 1, $result['summary']['failed'] );
		$this->assertSame( 'success', $result['results'][0]['status'] );
		$this->assertSame( 'error', $result['results'][1]['status'] );
		$this->assertSame( 'validation_failed', $result['results'][1]['error']['code'] );
		$this->assertSame( 'success', $result['results'][2]['status'] );
	}

	public function test_update_without_id_returns_validation_error(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = ( new Handler() )->run(
			$user_id,
			'post',
			array(
				array( 'action' => 'update', 'data' => array( 'title' => 'no id' ) ),
			)
		);

		$this->assertSame( 'error', $result['results'][0]['status'] );
		$this->assertSame( 'validation_failed', $result['results'][0]['error']['code'] );
	}

	public function test_unknown_action_returns_validation_error(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = ( new Handler() )->run(
			$user_id,
			'post',
			array(
				array( 'action' => 'foo', 'data' => array() ),
			)
		);

		$this->assertSame( 'validation_failed', $result['results'][0]['error']['code'] );
	}
}
