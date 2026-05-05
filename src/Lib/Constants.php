<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Constants {

	public const REST_NAMESPACE = 'wodo-bridge/v2';

	public const TABLE_TOKENS              = 'wodo_bridge_tokens';
	public const TABLE_ACTIVITY            = 'wodo_bridge_activity';
	public const TABLE_WEBHOOKS            = 'wodo_bridge_webhooks';
	public const TABLE_WEBHOOK_DELIVERIES  = 'wodo_bridge_webhook_deliveries';
	public const TABLE_RATE_LIMIT          = 'wodo_bridge_rate_limit';

	public const OPTION_DB_VERSION         = 'wodo_bridge_v2_db_version';
	public const OPTION_SETTINGS           = 'wodo_bridge_v2_settings';
	public const OPTION_SIGNING_KEY        = 'wodo_bridge_v2_site_signing_key';
	public const OPTION_SIGNING_PUBKEY     = 'wodo_bridge_v2_site_signing_pubkey';
	public const OPTION_SITE_PROFILE       = 'wodo_bridge_site_profile';

	public const ELEMENTOR_MAX_DEPTH       = 50;
	public const ELEMENTOR_MAX_ELEMENTS    = 5000;
	public const ELEMENTOR_MAX_PAYLOAD     = 5242880;
	public const ELEMENTOR_MAX_CSS_BYTES   = 5242880;

	public const POSTS_MAX_PER_PAGE        = 100;
	public const POSTS_DEFAULT_PER_PAGE    = 10;

	public const BULK_MAX_ITEMS            = 100;

	public const ACTIVITY_RETENTION_DAYS   = 90;
	public const DELIVERIES_RETENTION_DAYS = 30;
	public const RATE_LIMIT_TTL_SECONDS    = 3600;

	public const RATE_BUCKET_READ_CHEAP     = 'read_cheap';
	public const RATE_BUCKET_READ_EXPENSIVE = 'read_expensive';
	public const RATE_BUCKET_WRITE          = 'write';
	public const RATE_BUCKET_BULK           = 'bulk';
	public const RATE_BUCKET_WEBHOOK_TEST   = 'webhook_test';
	public const RATE_BUCKET_AUTH           = 'auth';

	public const RATE_LIMITS = array(
		self::RATE_BUCKET_READ_CHEAP     => array( 'limit' => 60,  'window' => 60 ),
		self::RATE_BUCKET_READ_EXPENSIVE => array( 'limit' => 10,  'window' => 60 ),
		self::RATE_BUCKET_WRITE          => array( 'limit' => 30,  'window' => 60 ),
		self::RATE_BUCKET_BULK           => array( 'limit' => 5,   'window' => 60 ),
		self::RATE_BUCKET_WEBHOOK_TEST   => array( 'limit' => 5,   'window' => 60 ),
		self::RATE_BUCKET_AUTH           => array( 'limit' => 10,  'window' => 60 ),
	);

	public const SCOPES = array(
		'posts.read',
		'posts.write',
		'cpt.read',
		'cpt.write',
		'taxonomy.write',
		'media.read',
		'media.write',
		'media.svg',
		'elementor.read',
		'elementor.write',
		'webhooks.manage',
		'admin.full',
	);

	public const WEBHOOK_EVENTS = array(
		'post.published',
		'post.updated',
		'media.uploaded',
		'term.created',
	);

	public const POSTMETA_DENY_PREFIX = '_';

	public const POSTMETA_ALLOWLIST = array(
		'_thumbnail_id',
		'_wp_page_template',
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_aioseo_title',
		'_aioseo_description',
		'_genesis_title',
		'_genesis_description',
	);

	public const ELEMENTOR_META_KEYS = array(
		'_elementor_data',
		'_elementor_edit_mode',
		'_elementor_version',
		'_elementor_page_settings',
		'_elementor_template_type',
		'_elementor_pro_version',
	);

	public const SUPPORTED_MIME_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
		'application/pdf',
		'audio/mpeg',
		'audio/ogg',
		'audio/wav',
		'video/mp4',
		'video/webm',
	);

	public const SVG_MIME_TYPES = array(
		'image/svg+xml',
	);

	private function __construct() {}
}
