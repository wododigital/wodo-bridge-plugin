<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Tests\TestCase;

/**
 * @group rest
 */
final class MediaTest extends TestCase {

	public function test_list_media_with_media_read_returns_200(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Seed an attachment.
		self::factory()->attachment->create_object(
			'fixture.png',
			0,
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'fixture',
			)
		);

		$token    = $this->create_token( $user_id, array( 'media.read' ) );
		$response = $this->make_rest_request( 'GET', '/media', $token );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_upload_media_executable_mime_is_rejected(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'media.write' ) );

		// Use base64-style API surface — pass a php-like file via source_url
		// would attempt SSRF; instead provide an inline placeholder. The
		// service must reject by mime allow-list.
		$response = $this->make_rest_request(
			'POST',
			'/media/upload',
			$token,
			array(
				'source_url' => 'https://example.com/payload.php',
			)
		);

		$this->assertContains( $response->get_status(), array( 415, 422, 500 ), 'executable upload must not succeed' );
	}

	public function test_svg_upload_default_denied_without_media_svg_scope(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token   = $this->create_token( $user_id, array( 'media.write' ) );

		$response = $this->make_rest_request(
			'POST',
			'/media/upload',
			$token,
			array( 'source_url' => 'https://example.com/icon.svg' )
		);

		$this->assertContains( $response->get_status(), array( 403, 415, 422, 500 ) );
	}

	public function test_get_media_unknown_id_is_404(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'media.read' ) );
		$response = $this->make_rest_request( 'GET', '/media/9999999', $token );

		$this->assertSame( 404, $response->get_status() );
	}
}
