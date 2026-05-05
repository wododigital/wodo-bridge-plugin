<?php

declare( strict_types=1 );

namespace WODO_Bridge\Rest;

use WODO_Bridge\Auth\Auth_Filter;
use WODO_Bridge\Auth\Scope_Manager;
use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Bulk\Handler as Bulk_Handler;
use WODO_Bridge\Content\Block_Codec;
use WODO_Bridge\Content\Cpt_Service;
use WODO_Bridge\Content\Media_Service;
use WODO_Bridge\Content\Post_Service;
use WODO_Bridge\Content\Taxonomy_Service;
use WODO_Bridge\Elementor\Page_Analyzer;
use WODO_Bridge\Elementor\Reader as Elementor_Reader;
use WODO_Bridge\Elementor\Site_Profile;
use WODO_Bridge\Elementor\Writer as Elementor_Writer;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Errors;
use WODO_Bridge\Lib\Rate_Limiter;
use WODO_Bridge\Lib\Request_Context;
use WODO_Bridge\Webhooks\Dispatcher;
use WODO_Bridge\Webhooks\Webhook_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Router {

	private Scope_Manager $scopes;
	private Rate_Limiter $rate;
	private Token_Service $tokens;

	public function __construct(
		?Scope_Manager $scopes = null,
		?Rate_Limiter $rate = null,
		?Token_Service $tokens = null
	) {
		$this->scopes = $scopes ?? new Scope_Manager();
		$this->rate   = $rate ?? new Rate_Limiter();
		$this->tokens = $tokens ?? new Token_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns = Constants::REST_NAMESPACE;

		register_rest_route( $ns, '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_health' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/site/identity', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_site_identity' ),
			'permission_callback' => array( $this, 'auth_only' ),
		) );

		register_rest_route( $ns, '/auth/scopes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_auth_scopes' ),
			'permission_callback' => array( $this, 'auth_only' ),
		) );

		register_rest_route( $ns, '/auth/grant-scopes', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_grant_scopes' ),
			'permission_callback' => array( $this, 'auth_only' ),
			'args'                => array(
				'scopes' => array( 'required' => true, 'type' => 'array' ),
			),
		) );

		register_rest_route( $ns, '/posts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_posts_list' ),
				'permission_callback' => $this->require_scope( 'posts.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_posts_create' ),
				'permission_callback' => $this->require_scope( 'posts.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/posts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_posts_get' ),
				'permission_callback' => $this->require_scope( 'posts.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_posts_update' ),
				'permission_callback' => $this->require_scope( 'posts.write', Constants::RATE_BUCKET_WRITE ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_posts_delete' ),
				'permission_callback' => $this->require_scope( 'posts.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/cpt', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_cpt_list' ),
			'permission_callback' => $this->require_scope( 'cpt.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/cpt/(?P<type>[a-z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_cpt_items' ),
				'permission_callback' => $this->require_scope( 'cpt.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_cpt_create' ),
				'permission_callback' => $this->require_scope( 'cpt.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/cpt/(?P<type>[a-z0-9_-]+)/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_cpt_get' ),
				'permission_callback' => $this->require_scope( 'cpt.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_cpt_update' ),
				'permission_callback' => $this->require_scope( 'cpt.write', Constants::RATE_BUCKET_WRITE ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_cpt_delete' ),
				'permission_callback' => $this->require_scope( 'cpt.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/taxonomies', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_taxonomies' ),
			'permission_callback' => $this->require_scope( 'cpt.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/terms/(?P<taxonomy>[a-z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_terms_list' ),
				'permission_callback' => $this->require_scope( 'cpt.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_terms_create' ),
				'permission_callback' => $this->require_scope( 'taxonomy.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/terms/(?P<taxonomy>[a-z0-9_-]+)/(?P<id>\d+)', array(
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_terms_update' ),
				'permission_callback' => $this->require_scope( 'taxonomy.write', Constants::RATE_BUCKET_WRITE ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_terms_delete' ),
				'permission_callback' => $this->require_scope( 'taxonomy.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/media', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_media_list' ),
			'permission_callback' => $this->require_scope( 'media.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/media/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_media_upload' ),
			'permission_callback' => $this->require_scope( 'media.write', Constants::RATE_BUCKET_WRITE ),
		) );

		register_rest_route( $ns, '/media/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_media_get' ),
				'permission_callback' => $this->require_scope( 'media.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_media_update' ),
				'permission_callback' => $this->require_scope( 'media.write', Constants::RATE_BUCKET_WRITE ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_media_delete' ),
				'permission_callback' => $this->require_scope( 'media.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/elementor/kit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_elementor_kit' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/elementor/widgets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_elementor_widgets' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/elementor/widgets/(?P<name>[a-z0-9_-]+)/controls', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_elementor_widget_controls' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/elementor/pages', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_elementor_pages_list' ),
				'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_elementor_pages_create' ),
				'permission_callback' => $this->require_scope( 'elementor.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/elementor/pages/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_elementor_page_get' ),
				'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_elementor_page_update' ),
				'permission_callback' => $this->require_scope( 'elementor.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/elementor/pages/(?P<id>\d+)/study', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_elementor_page_study' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_EXPENSIVE ),
		) );

		register_rest_route( $ns, '/elementor/templates', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_elementor_templates_list' ),
				'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_elementor_templates_create' ),
				'permission_callback' => $this->require_scope( 'elementor.write', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/elementor/templates/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_elementor_template_get' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_EXPENSIVE ),
		) );

		register_rest_route( $ns, '/elementor/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_elementor_validate' ),
			'permission_callback' => $this->require_scope( 'elementor.write', Constants::RATE_BUCKET_READ_EXPENSIVE ),
		) );

		register_rest_route( $ns, '/profile', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_profile_get' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/profile/refresh', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_profile_refresh' ),
			'permission_callback' => $this->require_scope( 'elementor.read', Constants::RATE_BUCKET_READ_EXPENSIVE ),
		) );

		register_rest_route( $ns, '/webhooks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_webhooks_list' ),
				'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'route_webhooks_create' ),
				'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/webhooks/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'route_webhooks_get' ),
				'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_READ_CHEAP ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'route_webhooks_update' ),
				'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_WRITE ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'route_webhooks_delete' ),
				'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_WRITE ),
			),
		) );

		register_rest_route( $ns, '/webhooks/(?P<id>\d+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_webhooks_test' ),
			'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_WEBHOOK_TEST ),
		) );

		register_rest_route( $ns, '/webhooks/(?P<id>\d+)/deliveries', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'route_webhooks_deliveries' ),
			'permission_callback' => $this->require_scope( 'webhooks.manage', Constants::RATE_BUCKET_READ_CHEAP ),
		) );

		register_rest_route( $ns, '/bulk/posts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_bulk_posts' ),
			'permission_callback' => $this->require_scope( 'posts.write', Constants::RATE_BUCKET_BULK ),
		) );

		register_rest_route( $ns, '/bulk/cpt/(?P<type>[a-z0-9_-]+)', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'route_bulk_cpt' ),
			'permission_callback' => $this->require_scope( 'cpt.write', Constants::RATE_BUCKET_BULK ),
		) );

		// --- Admin UI endpoints (manage_options OR admin.full scope). ---

		$tokens_ctrl   = new Tokens_Controller( $this->tokens );
		$activity_ctrl = new Activity_Controller();

		register_rest_route( $ns, '/auth/tokens', array(
			'methods'             => 'GET',
			'callback'            => array( $tokens_ctrl, 'list' ),
			'permission_callback' => array( $tokens_ctrl, 'can_manage' ),
		) );

		register_rest_route( $ns, '/auth/tokens/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $tokens_ctrl, 'revoke' ),
			'permission_callback' => array( $tokens_ctrl, 'can_manage' ),
		) );

		register_rest_route( $ns, '/activity', array(
			'methods'             => 'GET',
			'callback'            => array( $activity_ctrl, 'list' ),
			'permission_callback' => array( $activity_ctrl, 'can_read' ),
		) );

		register_rest_route( $ns, '/activity/distinct', array(
			'methods'             => 'GET',
			'callback'            => array( $activity_ctrl, 'distinct' ),
			'permission_callback' => array( $activity_ctrl, 'can_read' ),
		) );

		register_rest_route( $ns, '/activity/export.csv', array(
			'methods'             => 'GET',
			'callback'            => array( $activity_ctrl, 'export_csv' ),
			'permission_callback' => array( $activity_ctrl, 'can_read' ),
		) );
	}

	public function auth_only( WP_REST_Request $request ) {
		$token = $this->token_for( $request );
		if ( ! is_array( $token ) ) {
			return new WP_Error( 'unauthorized', __( 'Authentication required.', 'wodo-bridge' ), array( 'status' => 401 ) );
		}
		if ( ! empty( $token['revoked_at'] ) ) {
			return new WP_Error( 'token_revoked', __( 'Token revoked.', 'wodo-bridge' ), array( 'status' => 401 ) );
		}
		return true;
	}

	private function token_for( WP_REST_Request $request ): ?array {
		$token = Auth_Filter::token_for( $request );
		if ( $token !== null ) {
			return $token;
		}
		$attrs = $request->get_attributes();
		return is_array( $attrs['wodo_bridge_token'] ?? null ) ? $attrs['wodo_bridge_token'] : null;
	}

	private function require_scope( string $scope, string $bucket ): callable {
		return function ( WP_REST_Request $request ) use ( $scope, $bucket ) {
			$auth = $this->auth_only( $request );
			if ( $auth !== true ) {
				return $auth;
			}
			$check = $this->scopes->require( $request, $scope );
			if ( $check !== true ) {
				return $check;
			}

			$token    = $this->token_for( $request ) ?? array();
			$token_id = (int) ( $token['id'] ?? 0 );
			$identity = 'token:' . $token_id;
			$result   = $this->rate->consume( $bucket, $identity );
			if ( ! $result['allowed'] ) {
				$response = Errors::envelope(
					'rate_limited',
					__( 'Rate limit exceeded.', 'wodo-bridge' ),
					429,
					array(
						'bucket'      => $bucket,
						'limit'       => $result['limit'],
						'window'      => $result['window'],
						'retry_after' => $result['retry_after'],
					)
				);
				$response->header( 'Retry-After', (string) $result['retry_after'] );
				$response->header( 'X-RateLimit-Limit', (string) $result['limit'] );
				$response->header( 'X-RateLimit-Remaining', (string) $result['remaining'] );
				return $response;
			}
			return true;
		};
	}

	public function route_health( WP_REST_Request $request ) {
		return new WP_REST_Response( array(
			'plugin'  => 'wodo-bridge',
			'version' => WODO_BRIDGE_VERSION,
			'status'  => 'ok',
		), 200 );
	}

	public function route_site_identity( WP_REST_Request $request ) {
		$token    = $this->token_for( $request ) ?? array();
		$scopes   = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		$cpts     = ( new Cpt_Service() )->discover();
		$pubkey   = (string) get_option( Constants::OPTION_SIGNING_PUBKEY, '' );
		$secret_b = (string) get_option( Constants::OPTION_SIGNING_KEY, '' );
		$theme    = wp_get_theme();

		$payload = array(
			'name'             => (string) get_bloginfo( 'name' ),
			'url'              => (string) get_site_url(),
			'wp_version'       => (string) get_bloginfo( 'version' ),
			'plugin_version'   => WODO_BRIDGE_VERSION,
			'php_version'      => PHP_VERSION,
			'locale'           => (string) get_locale(),
			'timezone'         => (string) wp_timezone_string(),
			'theme'            => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			),
			'plugins'          => $this->active_plugins(),
			'elementor'        => array(
				'active'  => Elementor_Reader::is_active(),
				'version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			),
			'capabilities'     => $this->scopes->capabilities_for( $scopes ),
			'cpts'             => $cpts,
			'identity_pubkey'  => $pubkey,
		);

		$timestamp = time();
		$body      = (string) wp_json_encode( $payload );
		if ( $secret_b !== '' && function_exists( 'sodium_crypto_sign_detached' ) ) {
			try {
				$sig = sodium_crypto_sign_detached( $timestamp . '.' . $body, base64_decode( $secret_b ) );
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

	public function route_auth_scopes( WP_REST_Request $request ) {
		$token  = $this->token_for( $request ) ?? array();
		$scopes = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		sort( $scopes );

		return new WP_REST_Response( array(
			'token_id'     => (int) ( $token['id'] ?? 0 ),
			'scopes'       => $scopes,
			'granted_at'   => $token['created_at'] ?? null,
			'last_used_at' => $token['last_used_at'] ?? null,
		), 200 );
	}

	public function route_grant_scopes( WP_REST_Request $request ) {
		$token    = $this->token_for( $request ) ?? array();
		$token_id = (int) ( $token['id'] ?? 0 );
		if ( $token_id === 0 ) {
			return Errors::unauthorized();
		}

		$user_id        = (int) ( $token['wp_user_id'] ?? 0 );
		$current_scopes = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		$is_admin       = $user_id > 0 && user_can( $user_id, 'manage_options' );
		if ( ! empty( $current_scopes ) && ! $is_admin ) {
			return Errors::forbidden( __( 'Token already has scopes; only admins may rebind.', 'wodo-bridge' ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$scopes = is_array( $payload['scopes'] ?? null ) ? $payload['scopes'] : array();
		$origin = isset( $payload['aggregator_origin'] ) ? esc_url_raw( (string) $payload['aggregator_origin'] ) : '';

		$clean = $this->tokens->sanitize_scopes( array_map( 'strval', $scopes ) );
		$this->tokens->bind_scopes( $token_id, $clean, $origin );

		return new WP_REST_Response(
			array(
				'token_id' => $token_id,
				'scopes'   => $clean,
			),
			200
		);
	}

	public function route_posts_list( WP_REST_Request $request ) {
		$args = $request->get_query_params();
		if ( empty( $args['type'] ) ) {
			$args['type'] = 'post';
		}
		return $this->respond( ( new Post_Service() )->list( $args ) );
	}

	public function route_posts_get( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = ( new Post_Service() )->get( $id );
		return $this->respond( $result );
	}

	public function route_posts_create( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$type   = isset( $payload['type'] ) ? sanitize_key( (string) $payload['type'] ) : 'post';
		$result = ( new Post_Service() )->create( $user_id, $type, $payload );
		return $this->respond( $result, 201 );
	}

	public function route_posts_update( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$id      = (int) $request['id'];
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Post_Service() )->update( $user_id, $id, $payload ) );
	}

	public function route_posts_delete( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$id      = (int) $request['id'];
		$force   = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		return $this->respond( ( new Post_Service() )->delete( $user_id, $id, (bool) $force ) );
	}

	public function route_cpt_list( WP_REST_Request $request ) {
		$cpts = ( new Cpt_Service() )->discover();
		return new WP_REST_Response( array( 'types' => $cpts ), 200 );
	}

	public function route_cpt_items( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request['type'] );
		if ( ( new Cpt_Service() )->get( $type ) === null ) {
			return Errors::not_found( __( 'Unknown post type.', 'wodo-bridge' ) );
		}
		$args         = $request->get_query_params();
		$args['type'] = $type;
		return $this->respond( ( new Post_Service() )->list( $args ) );
	}

	public function route_cpt_create( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$type    = sanitize_key( (string) $request['type'] );
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Post_Service() )->create( $user_id, $type, $payload ), 201 );
	}

	public function route_cpt_get( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request['type'] );
		$id   = (int) $request['id'];
		if ( ( new Cpt_Service() )->get( $type ) === null ) {
			return Errors::not_found( __( 'Unknown post type.', 'wodo-bridge' ) );
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Errors::not_found();
		}
		return $this->respond( ( new Post_Service() )->get( $id ) );
	}

	public function route_cpt_update( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$type    = sanitize_key( (string) $request['type'] );
		$id      = (int) $request['id'];
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Errors::not_found();
		}
		return $this->respond( ( new Post_Service() )->update( $user_id, $id, $payload ) );
	}

	public function route_cpt_delete( WP_REST_Request $request ) {
		$user_id = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$type    = sanitize_key( (string) $request['type'] );
		$id      = (int) $request['id'];
		$force   = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		$post    = get_post( $id );
		if ( ! $post || $post->post_type !== $type ) {
			return Errors::not_found();
		}
		return $this->respond( ( new Post_Service() )->delete( $user_id, $id, (bool) $force ) );
	}

	public function route_taxonomies( WP_REST_Request $request ) {
		return new WP_REST_Response( array( 'taxonomies' => ( new Taxonomy_Service() )->list() ), 200 );
	}

	public function route_terms_list( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		return $this->respond( ( new Taxonomy_Service() )->list_terms( $taxonomy, $request->get_query_params() ) );
	}

	public function route_terms_create( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		$payload  = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Taxonomy_Service() )->create_term( $taxonomy, $payload ), 201 );
	}

	public function route_terms_update( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		$id       = (int) $request['id'];
		$payload  = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Taxonomy_Service() )->update_term( $taxonomy, $id, $payload ) );
	}

	public function route_terms_delete( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( (string) $request['taxonomy'] );
		$id       = (int) $request['id'];
		return $this->respond( ( new Taxonomy_Service() )->delete_term( $taxonomy, $id ) );
	}

	public function route_media_list( WP_REST_Request $request ) {
		return $this->respond( ( new Media_Service() )->list( $request->get_query_params() ) );
	}

	public function route_media_get( WP_REST_Request $request ) {
		return $this->respond( ( new Media_Service() )->get( (int) $request['id'] ) );
	}

	public function route_media_update( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Media_Service() )->update( (int) $request['id'], $payload ) );
	}

	public function route_media_delete( WP_REST_Request $request ) {
		$force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		return $this->respond( ( new Media_Service() )->delete( (int) $request['id'], (bool) $force ) );
	}

	public function route_media_upload( WP_REST_Request $request ) {
		$token   = $this->token_for( $request ) ?? array();
		$scopes  = is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array();
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
			if ( ! is_array( $payload ) ) {
				$payload = array();
			}
		}

		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) && is_array( $files['file'] ) ) {
			$result = ( new Media_Service() )->upload_from_request( $files['file'], $payload, $scopes );
			return $this->respond( $result, 201 );
		}

		$source = isset( $payload['source_url'] ) ? (string) $payload['source_url'] : '';
		if ( $source === '' ) {
			return Errors::validation( __( 'source_url or file is required.', 'wodo-bridge' ) );
		}
		return $this->respond( ( new Media_Service() )->upload_from_url( $source, $payload, $scopes ), 201 );
	}

	public function route_elementor_kit( WP_REST_Request $request ) {
		return $this->respond( ( new Elementor_Reader() )->get_kit() );
	}

	public function route_elementor_widgets( WP_REST_Request $request ) {
		return $this->respond( ( new Elementor_Reader() )->get_widgets( $request->get_query_params() ) );
	}

	public function route_elementor_widget_controls( WP_REST_Request $request ) {
		return $this->respond( ( new Elementor_Reader() )->get_widget_controls( sanitize_key( (string) $request['name'] ) ) );
	}

	public function route_elementor_pages_list( WP_REST_Request $request ) {
		return $this->respond( ( new Elementor_Reader() )->list_pages( $request->get_query_params() ) );
	}

	public function route_elementor_page_get( WP_REST_Request $request ) {
		return $this->respond( ( new Elementor_Reader() )->get_page( (int) $request['id'] ) );
	}

	public function route_elementor_page_study( WP_REST_Request $request ) {
		return $this->respond( ( new Page_Analyzer() )->study( (int) $request['id'] ) );
	}

	public function route_elementor_pages_create( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Elementor_Writer() )->create_page( $payload ), 201 );
	}

	public function route_elementor_page_update( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Elementor_Writer() )->update_page( (int) $request['id'], $payload ) );
	}

	public function route_elementor_templates_list( WP_REST_Request $request ) {
		$type = (string) ( $request->get_param( 'type' ) ?? '' );
		return $this->respond( ( new Elementor_Reader() )->list_templates( $type ) );
	}

	public function route_elementor_templates_create( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Elementor_Writer() )->create_template( $payload ), 201 );
	}

	public function route_elementor_template_get( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return $this->respond( ( new Elementor_Reader() )->get_template( $id ) );
	}

	public function route_elementor_validate( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		// Title is intentionally optional here — callers use this route to
		// dry-run an elementor_data shape before deciding whether to POST a
		// page/template, and may not have a title yet.
		$result = ( new Elementor_Writer() )->validate( $payload, false );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		return $this->respond(
			array(
				'valid'         => true,
				'element_count' => (int) ( $result['element_count'] ?? 0 ),
				'normalized'    => $result['elementor_data'],
			)
		);
	}

	public function route_profile_get( WP_REST_Request $request ) {
		if ( ! Elementor_Reader::is_active() ) {
			return Errors::elementor_not_active();
		}
		$profile = new Site_Profile();
		$data    = $profile->get();
		return new WP_REST_Response( array(
			'profile'      => $data,
			'is_stale'     => $profile->is_stale( $data ),
			'generated_at' => $data['generated_at'] ?? null,
		), 200 );
	}

	public function route_profile_refresh( WP_REST_Request $request ) {
		if ( ! Elementor_Reader::is_active() ) {
			return Errors::elementor_not_active();
		}
		$start   = microtime( true );
		$profile = ( new Site_Profile() )->refresh();
		return new WP_REST_Response( array(
			'profile'       => $profile,
			'rebuilt_at'    => gmdate( 'c' ),
			'build_time_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		), 200 );
	}

	public function route_webhooks_list( WP_REST_Request $request ) {
		return new WP_REST_Response( array( 'webhooks' => ( new Webhook_Manager() )->list() ), 200 );
	}

	public function route_webhooks_create( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Webhook_Manager() )->create( $payload ), 201 );
	}

	public function route_webhooks_get( WP_REST_Request $request ) {
		$item = ( new Webhook_Manager() )->get( (int) $request['id'] );
		return $item === null ? Errors::not_found() : new WP_REST_Response( $item, 200 );
	}

	public function route_webhooks_update( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		return $this->respond( ( new Webhook_Manager() )->update( (int) $request['id'], $payload ) );
	}

	public function route_webhooks_delete( WP_REST_Request $request ) {
		return $this->respond( ( new Webhook_Manager() )->delete( (int) $request['id'] ) );
	}

	public function route_webhooks_test( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		$event   = isset( $payload['event'] ) ? (string) $payload['event'] : 'post.published';
		$body    = is_array( $payload['payload'] ?? null ) ? $payload['payload'] : array( 'test' => true );
		$result  = ( new Dispatcher() )->fire_test( (int) $request['id'], $event, $body );
		return new WP_REST_Response( $result, 200 );
	}

	public function route_webhooks_deliveries( WP_REST_Request $request ) {
		return new WP_REST_Response(
			( new Webhook_Manager() )->deliveries( (int) $request['id'], $request->get_query_params() ),
			200
		);
	}

	public function route_bulk_posts( WP_REST_Request $request ) {
		$user_id    = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$payload    = $request->get_json_params();
		$operations = is_array( $payload['operations'] ?? null ) ? $payload['operations'] : array();
		return new WP_REST_Response( ( new Bulk_Handler() )->run( $user_id, 'post', $operations ), 200 );
	}

	public function route_bulk_cpt( WP_REST_Request $request ) {
		$user_id    = (int) ( ( $this->token_for( $request ) ?? array() )['wp_user_id'] ?? 0 );
		$type       = sanitize_key( (string) $request['type'] );
		$payload    = $request->get_json_params();
		$operations = is_array( $payload['operations'] ?? null ) ? $payload['operations'] : array();
		if ( ( new Cpt_Service() )->get( $type ) === null ) {
			return Errors::not_found( __( 'Unknown post type.', 'wodo-bridge' ) );
		}
		return new WP_REST_Response( ( new Bulk_Handler() )->run( $user_id, $type, $operations ), 200 );
	}

	private function respond( $result, int $success_status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return Errors::from_wp_error( $result );
		}
		if ( $result instanceof WP_REST_Response ) {
			return $result;
		}
		return new WP_REST_Response( $result, $success_status );
	}

	private function active_plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$catalog = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$out     = array();
		foreach ( $plugins as $slug ) {
			$slug = (string) $slug;
			$meta = $catalog[ $slug ] ?? array();
			$out[] = array(
				'slug'    => $slug,
				'name'    => (string) ( $meta['Name'] ?? $slug ),
				'version' => (string) ( $meta['Version'] ?? '' ),
			);
		}
		return $out;
	}
}
