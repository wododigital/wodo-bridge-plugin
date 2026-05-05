<?php

declare( strict_types=1 );

namespace WODO_Bridge\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Block_Codec {

	public function decode( string $content ): array {
		if ( $content === '' ) {
			return array();
		}
		$blocks = parse_blocks( $content );
		return is_array( $blocks ) ? $this->normalize_blocks( $blocks ) : array();
	}

	public function encode( array $blocks ): string {
		return serialize_blocks( $blocks );
	}

	private function normalize_blocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$out[] = array(
				'blockName'    => $block['blockName'] ?? null,
				'attrs'        => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
				'innerHTML'    => (string) ( $block['innerHTML'] ?? '' ),
				'innerContent' => isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array(),
				'innerBlocks'  => isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $this->normalize_blocks( $block['innerBlocks'] ) : array(),
			);
		}
		return $out;
	}
}
