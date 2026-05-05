<?php

declare( strict_types=1 );

namespace WODO_Bridge\Auth;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Errors;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scope_Manager {

	public function catalog(): array {
		return Constants::SCOPES;
	}

	public function token_has_scope( array $scopes, string $needed ): bool {
		if ( in_array( 'admin.full', $scopes, true ) ) {
			return true;
		}
		return in_array( $needed, $scopes, true );
	}

	public function require( WP_REST_Request $request, string $scope ): bool|WP_REST_Response {
		$token = Auth_Filter::token_for( $request );
		if ( $token === null ) {
			$token = $request->get_attributes()['wodo_bridge_token'] ?? null;
		}
		if ( ! is_array( $token ) ) {
			return Errors::unauthorized();
		}
		if ( ! empty( $token['revoked_at'] ) ) {
			return Errors::unauthorized( __( 'Token revoked.', 'wodo-bridge' ), 'token_revoked' );
		}

		$scopes = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		if ( ! $this->token_has_scope( $scopes, $scope ) ) {
			return Errors::forbidden(
				sprintf(
					/* translators: %s: scope name */
					esc_html__( 'Scope %s is required.', 'wodo-bridge' ),
					$scope
				),
				'scope_missing'
			);
		}

		return true;
	}

	public function capabilities_for( array $scopes ): array {
		$caps = array();
		$has  = static fn( string $s ) => in_array( $s, $scopes, true ) || in_array( 'admin.full', $scopes, true );

		if ( $has( 'posts.read' ) ) {
			$caps[] = 'read_posts';
		}
		if ( $has( 'posts.write' ) ) {
			$caps[] = 'edit_posts';
			$caps[] = 'delete_posts';
		}
		if ( $has( 'cpt.read' ) ) {
			$caps[] = 'read_cpt';
		}
		if ( $has( 'cpt.write' ) ) {
			$caps[] = 'edit_cpt';
		}
		if ( $has( 'taxonomy.write' ) ) {
			$caps[] = 'manage_terms';
		}
		if ( $has( 'media.read' ) ) {
			$caps[] = 'read_media';
		}
		if ( $has( 'media.write' ) ) {
			$caps[] = 'upload_media';
		}
		if ( $has( 'elementor.read' ) ) {
			$caps[] = 'read_elementor';
		}
		if ( $has( 'elementor.write' ) ) {
			$caps[] = 'edit_elementor';
		}
		if ( $has( 'webhooks.manage' ) ) {
			$caps[] = 'manage_webhooks';
		}

		return array_values( array_unique( $caps ) );
	}
}
