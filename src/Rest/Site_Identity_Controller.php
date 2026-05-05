<?php

declare( strict_types=1 );

namespace WODO_Bridge\Rest;

use WODO_Bridge\Auth\Auth_Filter;
use WODO_Bridge\Auth\Scope_Manager;
use WODO_Bridge\Content\Cpt_Service;
use WODO_Bridge\Elementor\Reader as Elementor_Reader;
use WODO_Bridge\Lib\Constants;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Identity_Controller {

	private Scope_Manager $scopes;

	public function __construct( ?Scope_Manager $scopes = null ) {
		$this->scopes = $scopes ?? new Scope_Manager();
	}

	public function build( WP_REST_Request $request ): WP_REST_Response {
		$token  = Auth_Filter::token_for( $request );
		if ( $token === null ) {
			$attrs = $request->get_attributes();
			$token = is_array( $attrs['wodo_bridge_token'] ?? null ) ? $attrs['wodo_bridge_token'] : array();
		}
		$scopes = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		$theme  = wp_get_theme();

		$payload = array(
			'name'           => (string) get_bloginfo( 'name' ),
			'url'            => (string) get_site_url(),
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'plugin_version' => WODO_BRIDGE_VERSION,
			'php_version'    => PHP_VERSION,
			'locale'         => (string) get_locale(),
			'timezone'       => (string) wp_timezone_string(),
			'theme'          => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			),
			'elementor'      => array(
				'active'  => Elementor_Reader::is_active(),
				'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			),
			'capabilities'   => $this->scopes->capabilities_for( $scopes ),
			'cpts'           => ( new Cpt_Service() )->discover(),
		);

		$payload['identity_pubkey'] = (string) get_option( Constants::OPTION_SIGNING_PUBKEY, '' );

		$secret_b = (string) get_option( Constants::OPTION_SIGNING_KEY, '' );
		if ( $secret_b !== '' && function_exists( 'sodium_crypto_sign_detached' ) ) {
			$timestamp = time();
			$body      = (string) wp_json_encode( $payload );
			try {
				$sig                            = sodium_crypto_sign_detached( $timestamp . '.' . $body, base64_decode( $secret_b ) );
				$payload['identity_signature'] = array(
					'algorithm' => 'ed25519',
					'timestamp' => $timestamp,
					'signature' => base64_encode( $sig ),
				);
			} catch ( \Throwable $e ) {
				$payload['identity_signature'] = null;
			}
		}

		return new WP_REST_Response( $payload, 200 );
	}
}
