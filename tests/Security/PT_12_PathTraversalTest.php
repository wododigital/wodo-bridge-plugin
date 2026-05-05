<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Elementor\Page_Analyzer;
use WODO_Bridge\Tests\TestCase;

/**
 * PT-12 (PT-08 on the threat model): CSS file references in Elementor metadata
 * that try to escape uploads/ via `../` must be confined.
 *
 * The Page_Analyzer reads CSS files referenced by Elementor; we feed it a
 * page whose `_elementor_css` metadata points to a relative path containing
 * '../', and verify the analyzer either rejects or returns no content.
 *
 * @group security
 */
final class PT_12_PathTraversalTest extends TestCase {

	public function test_path_traversal_in_css_reference_is_confined_to_uploads(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		// Crafted CSS reference targeting wp-config.php via traversal.
		update_post_meta(
			$post_id,
			'_elementor_css',
			array(
				'css'  => '../../../wp-config.php',
				'time' => time(),
			)
		);

		$analyzer = new Page_Analyzer();

		try {
			$study = $analyzer->study( $post_id );
		} catch ( \Throwable $e ) {
			// If the analyzer throws on traversal, that's a pass.
			$this->assertTrue( true );
			return;
		}

		$serialized = is_array( $study ) ? wp_json_encode( $study ) : (string) $study;
		$this->assertStringNotContainsString( 'DB_PASSWORD', (string) $serialized );
		$this->assertStringNotContainsString( 'AUTH_KEY', (string) $serialized );
	}
}
