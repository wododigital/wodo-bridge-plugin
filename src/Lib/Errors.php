<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Errors {

	public static function envelope( string $code, string $message, int $status = 400, array $details = array() ): WP_REST_Response {
		$payload = array(
			'success' => false,
			'error'   => array(
				'code'     => $code,
				'message'  => $message,
				'status'   => $status,
				'trace_id' => Trace::id(),
			),
		);

		if ( ! empty( $details ) ) {
			$payload['error']['details'] = $details;
		}

		return new WP_REST_Response( $payload, $status );
	}

	public static function from_wp_error( WP_Error $error ): WP_REST_Response {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();
		$data    = $error->get_error_data();
		$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		$details = array();
		if ( is_array( $data ) ) {
			unset( $data['status'] );
			$details = $data;
		}

		return self::envelope( $code !== '' ? $code : 'internal_error', $message, $status, $details );
	}

	public static function unauthorized( string $message = '', string $code = 'unauthorized' ): WP_REST_Response {
		if ( $message === '' ) {
			$message = __( 'Authentication required.', 'wodo-bridge' );
		}
		return self::envelope( $code, $message, 401 );
	}

	public static function forbidden( string $message = '', string $code = 'forbidden' ): WP_REST_Response {
		if ( $message === '' ) {
			$message = __( 'Access denied.', 'wodo-bridge' );
		}
		return self::envelope( $code, $message, 403 );
	}

	public static function not_found( string $message = '' ): WP_REST_Response {
		if ( $message === '' ) {
			$message = __( 'Resource not found.', 'wodo-bridge' );
		}
		return self::envelope( 'not_found', $message, 404 );
	}

	public static function validation( string $message, array $details = array() ): WP_REST_Response {
		return self::envelope( 'validation_failed', $message, 422, $details );
	}

	public static function elementor_not_active(): WP_REST_Response {
		return self::envelope( 'elementor_not_active', __( 'Elementor is not active on this site.', 'wodo-bridge' ), 409 );
	}
}
