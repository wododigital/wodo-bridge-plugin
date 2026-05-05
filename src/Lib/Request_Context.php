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
		if ( defined( 'WB_ALLOW_INSECURE' ) && WB_ALLOW_INSECURE === true ) {
			return true;
		}
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return in_array( $env, array( 'local', 'development' ), true );
	}
}
