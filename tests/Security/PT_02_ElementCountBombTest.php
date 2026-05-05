<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Security;

use WODO_Bridge\Elementor\Writer;
use WODO_Bridge\Lib\Constants;
use WODO_Bridge\Tests\TestCase;

/**
 * Threat model PT-02: 5001 elements should be rejected before Elementor parses them.
 *
 * @group security
 */
final class PT_02_ElementCountBombTest extends TestCase {

	public function test_writer_rejects_payload_with_more_than_max_elements(): void {
		$elements = array();
		for ( $i = 0; $i < ( Constants::ELEMENTOR_MAX_ELEMENTS + 1 ); $i++ ) {
			$elements[] = array(
				'id'         => uniqid( 'e_', true ),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => "el {$i}" ),
				'elements'   => array(),
			);
		}

		$result = ( new Writer() )->validate( $elements );

		$this->assertNotTrue(
			$result === true || ( is_array( $result ) && ( $result['ok'] ?? false ) === true ),
			'Element-count bomb must not pass validation'
		);
	}
}
