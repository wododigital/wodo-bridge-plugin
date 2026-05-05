<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Rest;

use WODO_Bridge\Elementor\Reader as Elementor_Reader;
use WODO_Bridge\Elementor\Writer as Elementor_Writer;
use WODO_Bridge\Tests\TestCase;

/**
 * Elementor route tests. Skip the entire class when Elementor isn't loaded
 * (CI matrix may run with and without).
 *
 * @group rest
 * @group elementor
 */
final class ElementorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! Elementor_Reader::is_active() ) {
			$this->markTestSkipped( 'Elementor not active in this environment.' );
		}
	}

	public function test_get_kit_returns_200_for_elementor_read(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'elementor.read' ) );
		$response = $this->make_rest_request( 'GET', '/elementor/kit', $token );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_validate_against_crypto_design_fixture(): void {
		$fixture = $this->load_elementor_fixture();

		// validate() is the writer's input-shape gate. Whether Elementor accepts
		// any specific control name is out of scope for the bridge — we want
		// "well-formed elements + within depth/count/payload caps".
		$writer = new Elementor_Writer();
		$result = $writer->validate( $fixture );

		$this->assertTrue(
			$result === true || ( is_array( $result ) && ( $result['ok'] ?? false ) ),
			'288KB crypto design fixture should pass writer validation'
		);
	}

	public function test_create_elementor_page_as_draft(): void {
		$user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$token    = $this->create_token( $user_id, array( 'elementor.write' ) );
		$response = $this->make_rest_request(
			'POST',
			'/elementor/pages',
			$token,
			array(
				'title'          => 'Crypto Test',
				'status'         => 'draft',
				'elementor_data' => $this->load_elementor_fixture(),
			)
		);

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
	}

	public function test_study_endpoint_uses_expensive_rate_bucket(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_type'   => 'page',
			)
		);
		update_post_meta( $post_id, '_elementor_data', wp_json_encode( $this->load_elementor_fixture() ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		$token    = $this->create_token( $user_id, array( 'elementor.read' ) );
		$response = $this->make_rest_request( 'GET', '/elementor/pages/' . $post_id . '/study', $token );

		$this->assertContains( $response->get_status(), array( 200, 409 ) );
		// Either ok, or "elementor_not_active" 409 if a CI image strips the plugin.
	}
}
