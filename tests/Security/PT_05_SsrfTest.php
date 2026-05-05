<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Tests\TestCase;

/**
 * PT-05: webhook registration with a private/loopback target must be rejected.
 *
 * @group security
 */
final class PT_05_SsrfTest extends TestCase {

	/** @return iterable<array{0:string}> */
	public static function ssrf_targets(): iterable {
		yield array( 'http://127.0.0.1/incoming' );
		yield array( 'https://127.0.0.1/incoming' );
		yield array( 'https://localhost/incoming' );
		yield array( 'https://10.0.0.5/incoming' );
		yield array( 'https://192.168.1.1/incoming' );
		yield array( 'https://169.254.169.254/latest/meta-data/' );
		yield array( 'file:///etc/passwd' );
		yield array( 'gopher://example.com/x' );
		yield array( 'http://example.com/insecure' );
	}

	/** @dataProvider ssrf_targets */
	public function test_webhook_registration_with_private_target_returns_422( string $target ): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'webhooks.manage' ) );
		$response = $this->make_rest_request(
			'POST',
			'/webhooks',
			$token,
			array(
				'target_url' => $target,
				'events'     => array( 'post.published' ),
				'secret'     => 'doesntmatter',
			)
		);

		$this->assertSame( 422, $response->get_status(), "target '{$target}' must be rejected" );
	}
}
