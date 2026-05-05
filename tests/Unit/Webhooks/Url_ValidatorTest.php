<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Webhooks;

use WODO_Bridge\Tests\TestCase;
use WODO_Bridge\Webhooks\Url_Validator;
use WP_Error;

/**
 * SSRF blocklist tests — threat model T9 / PT-05 / H-P-13.
 *
 * @covers \WODO_Bridge\Webhooks\Url_Validator
 */
final class Url_ValidatorTest extends TestCase {

	public function test_https_public_host_is_accepted(): void {
		$result = Url_Validator::validate( 'https://hooks.example.com/incoming' );
		$this->assertTrue( $result );
	}

	public function test_empty_url_is_rejected(): void {
		$result = Url_Validator::validate( '' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_http_scheme_is_rejected(): void {
		$result = Url_Validator::validate( 'http://example.com/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_file_scheme_is_rejected(): void {
		$result = Url_Validator::validate( 'file:///etc/passwd' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_gopher_scheme_is_rejected(): void {
		$result = Url_Validator::validate( 'gopher://example.com/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_javascript_scheme_is_rejected(): void {
		$result = Url_Validator::validate( 'javascript:alert(1)' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_localhost_hostname_is_rejected(): void {
		$result = Url_Validator::validate( 'https://localhost/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_localhost_localdomain_is_rejected(): void {
		$result = Url_Validator::validate( 'https://localhost.localdomain/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_127_0_0_1_literal_is_rejected(): void {
		$result = Url_Validator::validate( 'https://127.0.0.1/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_10_0_0_0_8_literal_is_rejected(): void {
		$result = Url_Validator::validate( 'https://10.0.0.5/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_172_16_0_0_12_literal_is_rejected(): void {
		$result = Url_Validator::validate( 'https://172.16.0.1/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_192_168_0_0_16_literal_is_rejected(): void {
		$result = Url_Validator::validate( 'https://192.168.1.1/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_link_local_169_254_is_rejected(): void {
		$result = Url_Validator::validate( 'https://169.254.169.254/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_ipv6_loopback_is_rejected(): void {
		$result = Url_Validator::validate( 'https://[::1]/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_ipv6_link_local_fe80_is_rejected(): void {
		$result = Url_Validator::validate( 'https://[fe80::1]/' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_url_without_host_is_rejected(): void {
		$result = Url_Validator::validate( 'https:///foo' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_url_without_scheme_is_rejected(): void {
		$result = Url_Validator::validate( 'example.com/path' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_is_private_ip_returns_false_for_public_ip(): void {
		$this->assertFalse( Url_Validator::is_private_ip( '8.8.8.8' ) );
	}

	public function test_is_private_ip_returns_true_for_loopback(): void {
		$this->assertTrue( Url_Validator::is_private_ip( '127.0.0.1' ) );
	}

	public function test_is_private_ip_returns_false_for_garbage(): void {
		$this->assertFalse( Url_Validator::is_private_ip( 'not-an-ip' ) );
	}

	public function test_is_private_ip_returns_true_for_class_a_private(): void {
		$this->assertTrue( Url_Validator::is_private_ip( '10.255.255.255' ) );
	}
}
