<?php

declare( strict_types=1 );

namespace WODO_Bridge\Admin;

use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Assets {

	public const HANDLE_STYLE  = 'wodo-bridge-admin';
	public const HANDLE_SCRIPT = 'wodo-bridge-admin';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== 'settings_page_' . Admin_Page::SLUG ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE_STYLE,
			WODO_BRIDGE_URL . 'assets/admin.css',
			array(),
			WODO_BRIDGE_VERSION
		);

		wp_enqueue_script(
			self::HANDLE_SCRIPT,
			WODO_BRIDGE_URL . 'assets/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			WODO_BRIDGE_VERSION,
			true
		);

		// Strict-typed config blob for the JS module.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$config = array(
			'restRoot'       => esc_url_raw( rest_url( Constants::REST_NAMESPACE . '/' ) ),
			'restRootGlobal' => esc_url_raw( rest_url() ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'scopes'         => array_values( Constants::SCOPES ),
			'webhookEvents'  => array_merge( array_values( Constants::WEBHOOK_EVENTS ), array( 'webhook.test' ) ),
			'currentScreen'  => $active_tab,
			'siteUrl'        => esc_url_raw( home_url() ),
			'aggregatorUrl'  => esc_url_raw( (string) get_option( Admin_Page::OPTION_AGGREGATOR_URL, '' ) ),
			'pluginUuid'     => $this->plugin_uuid(),
			'wpUserId'       => (int) get_current_user_id(),
			'restNamespace'  => Constants::REST_NAMESPACE,
		);

		wp_localize_script( self::HANDLE_SCRIPT, 'wodoBridgeAdmin', $config );

		// Load WP's i18n translations for the script handle (no-op if no .mo present).
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE_SCRIPT, 'wodo-bridge', WODO_BRIDGE_DIR . 'languages' );
		}
	}

	/**
	 * Stable per-install plugin uuid used as the WP authorize-app `app_id`.
	 * Generated lazily and persisted as an option.
	 */
	private function plugin_uuid(): string {
		$key  = 'wodo_bridge_v2_plugin_uuid';
		$uuid = (string) get_option( $key, '' );
		if ( $uuid !== '' ) {
			return $uuid;
		}
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
		update_option( $key, $uuid, true );
		return $uuid;
	}
}
