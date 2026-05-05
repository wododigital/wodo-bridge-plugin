<?php

declare( strict_types=1 );

namespace WODO_Bridge\Bulk;

use WODO_Bridge\Content\Post_Service;
use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Handler {

	private Post_Service $posts;

	public function __construct( ?Post_Service $posts = null ) {
		$this->posts = $posts ?? new Post_Service();
	}

	public function run( int $user_id, string $type, array $operations ): array {
		$start    = microtime( true );
		$results  = array();
		$succeeded = 0;
		$failed    = 0;

		$max = Constants::BULK_MAX_ITEMS;
		$operations = array_slice( $operations, 0, $max );

		foreach ( $operations as $index => $op ) {
			$action = is_array( $op ) ? (string) ( $op['action'] ?? '' ) : '';
			$data   = is_array( $op ) && is_array( $op['data'] ?? null ) ? $op['data'] : array();

			$result = match ( $action ) {
				'create' => $this->posts->create( $user_id, $type, $data ),
				'update' => isset( $data['id'] )
					? $this->posts->update( $user_id, (int) $data['id'], $data )
					: new \WP_Error( 'validation_failed', __( 'Update requires id.', 'wodo-bridge' ), array( 'status' => 422 ) ),
				'delete' => isset( $data['id'] )
					? $this->posts->delete( $user_id, (int) $data['id'], ! empty( $data['force'] ) )
					: new \WP_Error( 'validation_failed', __( 'Delete requires id.', 'wodo-bridge' ), array( 'status' => 422 ) ),
				default  => new \WP_Error( 'validation_failed', __( 'Invalid action.', 'wodo-bridge' ), array( 'status' => 422 ) ),
			};

			if ( is_wp_error( $result ) ) {
				$failed++;
				$results[] = array(
					'index'  => $index,
					'action' => $action,
					'status' => 'error',
					'error'  => array(
						'code'    => (string) $result->get_error_code(),
						'message' => (string) $result->get_error_message(),
					),
				);
				continue;
			}

			$succeeded++;
			$results[] = array(
				'index'  => $index,
				'action' => $action,
				'status' => 'success',
				'data'   => $result,
			);
		}

		return array(
			'summary' => array(
				'total'       => count( $operations ),
				'succeeded'   => $succeeded,
				'failed'      => $failed,
				'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			),
			'results' => $results,
		);
	}
}
