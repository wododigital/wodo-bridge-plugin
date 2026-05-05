<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Content;

use WODO_Bridge\Content\Block_Codec;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Content\Block_Codec
 */
final class Block_CodecTest extends TestCase {

	private Block_Codec $codec;

	public function set_up(): void {
		parent::set_up();
		$this->codec = new Block_Codec();
	}

	public function test_decode_empty_string_returns_empty_array(): void {
		$this->assertSame( array(), $this->codec->decode( '' ) );
	}

	public function test_round_trip_paragraph_block_preserves_content(): void {
		$source  = "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->";
		$decoded = $this->codec->decode( $source );

		$this->assertCount( 1, $decoded );
		$this->assertSame( 'core/paragraph', $decoded[0]['blockName'] );

		$encoded = $this->codec->encode( $decoded );
		$this->assertStringContainsString( 'Hello world.', $encoded );
		$this->assertStringContainsString( 'wp:paragraph', $encoded );
	}

	public function test_round_trip_heading_with_attributes(): void {
		$source  = "<!-- wp:heading {\"level\":3} -->\n<h3>Section</h3>\n<!-- /wp:heading -->";
		$decoded = $this->codec->decode( $source );

		$this->assertSame( 'core/heading', $decoded[0]['blockName'] );
		$this->assertSame( 3, $decoded[0]['attrs']['level'] ?? null );

		$encoded = $this->codec->encode( $decoded );
		$this->assertStringContainsString( 'wp:heading', $encoded );
		$this->assertStringContainsString( '"level":3', $encoded );
	}

	public function test_round_trip_nested_columns(): void {
		$source  = "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column --><div class=\"wp-block-column\"><!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph --></div><!-- /wp:column --></div>\n<!-- /wp:columns -->";
		$decoded = $this->codec->decode( $source );

		$this->assertSame( 'core/columns', $decoded[0]['blockName'] );
		$this->assertCount( 1, $decoded[0]['innerBlocks'] );
		$this->assertSame( 'core/column', $decoded[0]['innerBlocks'][0]['blockName'] );
		$this->assertCount( 1, $decoded[0]['innerBlocks'][0]['innerBlocks'] );
		$this->assertSame( 'core/paragraph', $decoded[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] );

		$encoded = $this->codec->encode( $decoded );
		$this->assertStringContainsString( 'wp:columns', $encoded );
		$this->assertStringContainsString( 'wp:column', $encoded );
		$this->assertStringContainsString( 'Inner', $encoded );
	}

	public function test_decode_handles_dynamic_block_with_no_inner_html(): void {
		$source  = '<!-- wp:latest-posts {"postsToShow":5} /-->';
		$decoded = $this->codec->decode( $source );

		$this->assertCount( 1, $decoded );
		$this->assertSame( 'core/latest-posts', $decoded[0]['blockName'] );
		$this->assertSame( 5, $decoded[0]['attrs']['postsToShow'] ?? null );
		$this->assertSame( '', $decoded[0]['innerHTML'] );
	}

	public function test_decode_unknown_block_namespace_preserves_attrs(): void {
		// ACF blocks use acf/* — codec must round-trip them without changing payload.
		$source  = "<!-- wp:acf/testimonial {\"name\":\"acf/testimonial\",\"data\":{\"author\":\"Jane\"}} /-->";
		$decoded = $this->codec->decode( $source );

		$this->assertSame( 'acf/testimonial', $decoded[0]['blockName'] );
		$this->assertSame( 'Jane', $decoded[0]['attrs']['data']['author'] ?? null );
	}

	public function test_normalize_includes_inner_content_array(): void {
		$source  = "<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->";
		$decoded = $this->codec->decode( $source );

		$this->assertArrayHasKey( 'innerContent', $decoded[0] );
		$this->assertIsArray( $decoded[0]['innerContent'] );
	}

	public function test_encode_empty_array_returns_empty_string(): void {
		$this->assertSame( '', $this->codec->encode( array() ) );
	}

	public function test_round_trip_preserves_multiple_top_level_blocks(): void {
		$source  = "<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->";
		$decoded = $this->codec->decode( $source );

		$this->assertCount( 2, $decoded );

		$encoded = $this->codec->encode( $decoded );
		$this->assertStringContainsString( '<p>a</p>', $encoded );
		$this->assertStringContainsString( '<p>b</p>', $encoded );
	}
}
