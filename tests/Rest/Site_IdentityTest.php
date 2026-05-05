<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Lib\Installer;
use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class Site_IdentityTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Make sure the keypair is generated; tests run against a stubbed install
		// where activation may not have fired.
		if ( ! get_option( Constants::OPTION_SIGNING_KEY ) && method_exists( Installer::class, 'activate' ) ) {
			Installer::activate();
		}
	}

	public function test_identity_payload_contains_pubkey_and_signature(): void {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			$this->markTestSkipped( 'libsodium not available; signing is best-effort.' );
		}

		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request( 'GET', '/site/identity', $token );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'identity_pubkey', $data );
		$this->assertNotEmpty( $data['identity_pubkey'] );
		$this->assertArrayHasKey( 'identity_signature', $data );
		$this->assertSame( 'ed25519', $data['identity_signature']['algorithm'] ?? null );
		$this->assertNotEmpty( $data['identity_signature']['signature'] ?? null );
	}

	public function test_signature_changes_when_timestamp_changes(): void {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'posts.read' ) );

		$first  = $this->make_rest_request( 'GET', '/site/identity', $token )->get_data();
		// Force a one-second gap so the timestamp differs.
		sleep( 1 );
		$second = $this->make_rest_request( 'GET', '/site/identity', $token )->get_data();

		$this->assertNotSame(
			$first['identity_signature']['signature'] ?? null,
			$second['identity_signature']['signature'] ?? null,
			'identity signature must change with timestamp'
		);
	}

	public function test_identity_signature_verifies_against_public_key(): void {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->markTestSkipped( 'libsodium verify unavailable' );
		}

		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'posts.read' ) );
		$response = $this->make_rest_request( 'GET', '/site/identity', $token );

		$data = $response->get_data();
		if ( ! isset( $data['identity_signature']['signature'] ) ) {
			$this->markTestSkipped( 'sodium not enabled — no signature emitted' );
		}

		$signature = base64_decode( (string) $data['identity_signature']['signature'] );
		$pubkey    = base64_decode( (string) $data['identity_pubkey'] );
		$timestamp = (int) ( $data['identity_signature']['timestamp'] ?? 0 );

		// Reconstruct payload exactly as the controller does (signing happens
		// after identity_pubkey is set, before identity_signature is set).
		$copy = $data;
		unset( $copy['identity_signature'] );
		$body = (string) wp_json_encode( $copy );

		$valid = sodium_crypto_sign_verify_detached( $signature, $timestamp . '.' . $body, $pubkey );
		$this->assertTrue( $valid, 'detached signature must verify against the published pubkey' );
	}
}
