<?php

declare( strict_types=1 );

namespace WODO_Bridge\Webhooks;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Url_Validator {

	public static function validate( string $url ): true|WP_Error {
		$url = trim( $url );
		if ( $url === '' ) {
			return new WP_Error( 'validation_failed', __( 'target_url is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'validation_failed', __( 'Invalid target_url.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( $scheme !== 'https' ) {
			return new WP_Error( 'validation_failed', __( 'Webhook target must use HTTPS.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$host = strtolower( (string) $parts['host'] );
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return new WP_Error( 'validation_failed', __( 'Localhost targets are not allowed.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$resolved = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $resolved ) ) {
				foreach ( $resolved as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					}
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		foreach ( $ips as $ip ) {
			if ( self::is_private_ip( $ip ) ) {
				return new WP_Error( 'validation_failed', __( 'Webhook target resolves to a private network.', 'wodo-bridge' ), array( 'status' => 422 ) );
			}
		}

		return true;
	}

	public static function is_private_ip( string $ip ): bool {
		if ( $ip === '' ) {
			return false;
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return true;
		}
		return false;
	}
}
