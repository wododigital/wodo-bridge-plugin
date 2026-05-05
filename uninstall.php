<?php
/**
 * Uninstall handler. Drops custom tables and removes plugin options.
 *
 * @package WODO_Bridge
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$prefix = $wpdb->prefix . 'wodo_bridge_';
$tables = array(
	$prefix . 'tokens',
	$prefix . 'activity',
	$prefix . 'webhooks',
	$prefix . 'webhook_deliveries',
	$prefix . 'rate_limit',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
}

$options = array(
	'wodo_bridge_v2_settings',
	'wodo_bridge_v2_db_version',
	'wodo_bridge_site_profile',
	'wodo_bridge_v2_site_signing_key',
	'wodo_bridge_v2_site_signing_pubkey',
);

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

wp_clear_scheduled_hook( 'wodo_bridge_prune_activity' );
wp_clear_scheduled_hook( 'wodo_bridge_prune_deliveries' );
wp_clear_scheduled_hook( 'wodo_bridge_prune_rate_limit' );
wp_clear_scheduled_hook( 'wodo_bridge_dispatch_webhook' );
