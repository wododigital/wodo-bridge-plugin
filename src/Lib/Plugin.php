<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

use WODO_Bridge\Admin\Admin_Page;
use WODO_Bridge\Auth\Auth_Filter;
use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Rest\Router;
use WODO_Bridge\Webhooks\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'wodo-bridge', false, dirname( WODO_BRIDGE_BASENAME ) . '/languages' );

		Installer::run_migrations();
		Installer::ensure_signing_key();

		add_action( 'wodo_bridge_prune_activity', array( Activity_Logger::class, 'prune' ) );
		add_action( 'wodo_bridge_prune_deliveries', array( Dispatcher::class, 'prune_deliveries' ) );
		add_action( 'wodo_bridge_prune_rate_limit', array( Rate_Limiter::class, 'prune' ) );

		( new Auth_Filter() )->register();
		( new Router() )->register();
		( new Dispatcher() )->register();

		$token_capture = new Token_Service();
		$token_capture->register_capture_hooks();

		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
	}
}
