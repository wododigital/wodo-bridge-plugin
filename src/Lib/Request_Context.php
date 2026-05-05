<?php

declare( strict_types=1 );

namespace WODO_Bridge\Lib;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Request_Context {

	public static function ip(): array {
		$proxy_trusted = defined( 'WB_TRUST_PROXY' ) && WB_TRUST_PROXY === true;
		$remote        = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		$ip = $remote;
		if ( $proxy_trusted && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
			$first     = trim( explode( ',', $forwarded )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				$ip = $first;
			}
		}

		return array(
			'ip'            => $ip,
			'proxy_trusted' => $proxy_trusted,
		);
	}

	public static function user_agent(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		return mb_substr( sanitize_text_field( $ua ), 0, 512 );
	}

	public static function is_https(): bool {
		if ( is_ssl() ) {
			return true;
		}
		// Accommodate WP installs sitting behind a TLS-terminating proxy
		// (Cloudflare, host load balancer, Nginx in front of Apache, etc.).
		// In that topology WP's is_ssl() returns false because $_SERVER['HTTPS']
		// is unset and SERVER_PORT is 80, even though the original client
		// connection was HTTPS. The proxy signals the original protocol via
		// X-Forwarded-Proto / X-Forwarded-Ssl. We trust those headers as
		// defense-in-depth; the App Password Basic-auth check is the real
		// security gate, and any attacker able to reach the origin directly
		// over HTTP would still need a valid App Password to do anything.
		$forwarded_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
			: '';
		if ( $forwarded_proto === 'https' ) {
			return true;
		}
		$forwarded_ssl = isset( $_SERVER['HTTP_X_FORWARDED_SSL'] )
			? strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) )
			: '';
		if ( $forwarded_ssl === 'on' || $forwarded_ssl === '1' ) {
			return true;
		}
		if ( defined( 'WB_ALLOW_INSECURE' ) && WB_ALLOW_INSECURE === true ) {
			return true;
		}
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return in_array( $env, array( 'local', 'development' ), true );
	}
}
