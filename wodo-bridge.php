<?php
/**
 * Plugin Name:       WODO Bridge
 * Plugin URI:        https://wododigital.com/bridge
 * Description:       Scope-gated REST surface for AI assistants. Pairs with the WODO Bridge aggregator over WordPress Application Passwords.
 * Version:           2.0.0-alpha.4
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            WODO Digital
 * Author URI:        https://wododigital.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wodo-bridge
 * Domain Path:       /languages
 *
 * @package WODO_Bridge
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WODO_BRIDGE_VERSION', '2.0.0-alpha.4' );
define( 'WODO_BRIDGE_FILE', __FILE__ );
define( 'WODO_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WODO_BRIDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'WODO_BRIDGE_BASENAME', plugin_basename( __FILE__ ) );
define( 'WODO_BRIDGE_REST_NAMESPACE', 'wodo-bridge/v2' );
define( 'WODO_BRIDGE_DB_VERSION', '1' );
define( 'WODO_BRIDGE_TEXT_DOMAIN', 'wodo-bridge' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WODO_Bridge\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = WODO_BRIDGE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\WODO_Bridge\Lib\Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\WODO_Bridge\Lib\Installer::deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\WODO_Bridge\Lib\Plugin::instance()->boot();
	},
	1
);
