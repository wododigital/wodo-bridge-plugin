<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	public static function activate(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::run_migrations();
		self::ensure_signing_key();
		self::schedule_cron();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wodo_bridge_prune_activity' );
		wp_clear_scheduled_hook( 'wodo_bridge_prune_deliveries' );
		wp_clear_scheduled_hook( 'wodo_bridge_prune_rate_limit' );
	}

	public static function run_migrations(): void {
		$installed = (string) get_option( Constants::OPTION_DB_VERSION, '' );
		if ( $installed === WODO_BRIDGE_DB_VERSION ) {
			return;
		}

		global $wpdb;
		$prefix  = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		$tokens   = $prefix . Constants::TABLE_TOKENS;
		$activity = $prefix . Constants::TABLE_ACTIVITY;
		$webhooks = $prefix . Constants::TABLE_WEBHOOKS;
		$deliv    = $prefix . Constants::TABLE_WEBHOOK_DELIVERIES;
		$rate     = $prefix . Constants::TABLE_RATE_LIMIT;

		$schema_tokens = "CREATE TABLE {$tokens} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			app_password_uuid CHAR(36) NOT NULL,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			scopes_json LONGTEXT NOT NULL,
			label VARCHAR(191) NOT NULL DEFAULT '',
			aggregator_origin VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME NULL,
			last_used_ip VARCHAR(45) NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_app_password_uuid (app_password_uuid),
			KEY idx_wp_user_id (wp_user_id),
			KEY idx_revoked_created (revoked_at, created_at)
		) {$charset};";

		$schema_activity = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_id BIGINT UNSIGNED NULL,
			endpoint VARCHAR(191) NOT NULL DEFAULT '',
			method VARCHAR(10) NOT NULL DEFAULT '',
			http_status SMALLINT NOT NULL DEFAULT 0,
			error_code VARCHAR(64) NOT NULL DEFAULT '',
			latency_ms INT NOT NULL DEFAULT 0,
			ip VARCHAR(45) NULL,
			proxy_trusted TINYINT(1) NOT NULL DEFAULT 0,
			user_agent VARCHAR(512) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_token_created (token_id, created_at),
			KEY idx_endpoint_created (endpoint, created_at),
			KEY idx_created (created_at)
		) {$charset};";

		$schema_webhooks = "CREATE TABLE {$webhooks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			target_url TEXT NOT NULL,
			secret_hash VARCHAR(128) NOT NULL,
			events_json LONGTEXT NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_delivery_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_uuid (uuid),
			KEY idx_active (active)
		) {$charset};";

		$schema_deliv = "CREATE TABLE {$deliv} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			webhook_id BIGINT UNSIGNED NOT NULL,
			delivery_uuid CHAR(36) NOT NULL,
			event VARCHAR(64) NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			http_status SMALLINT NOT NULL DEFAULT 0,
			attempt SMALLINT NOT NULL DEFAULT 1,
			latency_ms INT NOT NULL DEFAULT 0,
			response_excerpt VARCHAR(512) NOT NULL DEFAULT '',
			delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_delivery_uuid (delivery_uuid),
			KEY idx_webhook_delivered (webhook_id, delivered_at),
			KEY idx_event_delivered (event, delivered_at)
		) {$charset};";

		$schema_rate = "CREATE TABLE {$rate} (
			bucket_key VARCHAR(96) NOT NULL,
			tokens DOUBLE NOT NULL,
			last_refill DATETIME(3) NOT NULL,
			PRIMARY KEY  (bucket_key),
			KEY idx_last_refill (last_refill)
		) {$charset};";

		dbDelta( $schema_tokens );
		dbDelta( $schema_activity );
		dbDelta( $schema_webhooks );
		dbDelta( $schema_deliv );
		dbDelta( $schema_rate );

		update_option( Constants::OPTION_DB_VERSION, WODO_BRIDGE_DB_VERSION, true );
	}

	public static function ensure_signing_key(): void {
		$existing = get_option( Constants::OPTION_SIGNING_KEY, '' );
		if ( $existing !== '' ) {
			return;
		}

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return;
		}

		$keypair = sodium_crypto_sign_keypair();
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$public  = sodium_crypto_sign_publickey( $keypair );

		update_option( Constants::OPTION_SIGNING_KEY, base64_encode( $secret ), false );
		update_option( Constants::OPTION_SIGNING_PUBKEY, base64_encode( $public ), true );
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'wodo_bridge_prune_activity' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wodo_bridge_prune_activity' );
		}
		if ( ! wp_next_scheduled( 'wodo_bridge_prune_deliveries' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wodo_bridge_prune_deliveries' );
		}
		if ( ! wp_next_scheduled( 'wodo_bridge_prune_rate_limit' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wodo_bridge_prune_rate_limit' );
		}
	}
}
