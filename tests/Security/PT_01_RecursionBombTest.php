<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Elementor\Writer;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * Threat model PT-01: deeply-nested Elementor JSON must be rejected at depth 51
 * — never reach Elementor's saver.
 *
 * @group security
 */
final class PT_01_RecursionBombTest extends TestCase {

	public function test_writer_rejects_payload_deeper_than_max_depth(): void {
		// Build a 60-deep section/column structure.
		$leaf = array(
			'id'       => uniqid( 'l_', true ),
			'elType'   => 'widget',
			'widgetType' => 'heading',
			'settings' => array( 'title' => 'leaf' ),
			'elements' => array(),
		);

		$payload = $leaf;
		for ( $i = 0; $i < ( Constants::ELEMENTOR_MAX_DEPTH + 10 ); $i++ ) {
			$payload = array(
				'id'       => uniqid( 's_', true ),
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array( $payload ),
			);
		}

		$writer = new Writer();
		$result = $writer->validate( array( $payload ) );

		// Either an array result with 'ok' => false, or a WP_Error, or an
		// invalidation that returns a non-true value.
		$this->assertNotTrue(
			$result === true || ( is_array( $result ) && ( $result['ok'] ?? false ) === true ),
			'Recursion bomb must not pass validation'
		);
	}
}
