<?php

declare( strict_types=1 );

namespace WODO_Bridge\Elementor;

use WODO_Bridge\Lib\Constants;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Page_Analyzer {

	private array $global_colors = array();
	private array $global_fonts  = array();

	public function study( int $post_id ): array|WP_Error {
		if ( ! Reader::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) !== 'builder' ) {
			return new WP_Error( 'not_found', __( 'Page is not built with Elementor.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}

		$raw = (string) get_post_meta( $post_id, '_elementor_data', true );
		if ( strlen( $raw ) > Constants::ELEMENTOR_MAX_PAYLOAD ) {
			return new WP_Error( 'payload_too_large', __( 'Elementor payload exceeds size cap.', 'wodo-bridge' ), array( 'status' => 413 ) );
		}
		if ( $raw === '' ) {
			return new WP_Error( 'not_found', __( 'No Elementor data.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'invalid_json', __( 'Stored Elementor data is not valid JSON.', 'wodo-bridge' ), array( 'status' => 400 ) );
		}

		$this->load_globals();

		$resolved = array();
		$counts   = array( 'containers' => 0, 'widgets' => 0, 'total' => 0 );
		$summary  = array(
			'widgets_used'         => array(),
			'uses_global_colors'   => false,
			'uses_global_fonts'    => false,
			'custom_colors'        => array(),
			'custom_fonts'         => array(),
			'responsive_overrides' => 0,
			'background_types'     => array(),
			'has_custom_css'       => false,
		);

		$result = $this->walk_iterative( $elements, $resolved, $counts, $summary );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$layout_mode = 'container';
		foreach ( $resolved as $el ) {
			if ( ( $el['elType'] ?? '' ) === 'section' ) {
				$layout_mode = 'section';
				break;
			}
		}

		return array(
			'id'            => (int) $post->ID,
			'title'         => (string) $post->post_title,
			'url'           => (string) get_permalink( $post_id ),
			'template'      => (string) ( get_post_meta( $post_id, '_wp_page_template', true ) ?: 'default' ),
			'element_count' => $counts,
			'elements'      => $resolved,
			'page_summary'  => array(
				'layout_mode'                => $layout_mode,
				'top_level_sections'         => count( $elements ),
				'unique_widgets_used'        => array_values( array_unique( $summary['widgets_used'] ) ),
				'uses_global_colors'         => $summary['uses_global_colors'],
				'uses_global_fonts'          => $summary['uses_global_fonts'],
				'custom_colors_found'        => array_values( array_unique( $summary['custom_colors'] ) ),
				'custom_fonts_found'         => array_values( array_unique( $summary['custom_fonts'] ) ),
				'responsive_overrides_count' => $summary['responsive_overrides'],
				'background_types_used'      => array_values( array_unique( $summary['background_types'] ) ),
				'has_custom_css'             => $summary['has_custom_css'],
			),
		);
	}

	private function walk_iterative( array $top_level, array &$result, array &$counts, array &$summary ): null|WP_Error {
		$stack = array();
		foreach ( array_reverse( $top_level ) as $el ) {
			$stack[] = array( 'el' => $el, 'depth' => 0, 'parent' => null );
		}

		while ( ! empty( $stack ) ) {
			if ( $counts['total'] >= Constants::ELEMENTOR_MAX_ELEMENTS ) {
				return new WP_Error( 'payload_too_large', __( 'Element count cap exceeded.', 'wodo-bridge' ), array( 'status' => 413 ) );
			}
			$frame = array_pop( $stack );
			$el    = $frame['el'];
			$depth = (int) $frame['depth'];

			if ( $depth > Constants::ELEMENTOR_MAX_DEPTH ) {
				return new WP_Error( 'payload_too_deep', __( 'Element depth cap exceeded.', 'wodo-bridge' ), array( 'status' => 413 ) );
			}
			if ( ! is_array( $el ) ) {
				continue;
			}

			$el_type     = (string) ( $el['elType'] ?? '' );
			$widget_type = (string) ( $el['widgetType'] ?? '' );
			$el_id       = (string) ( $el['id'] ?? '' );
			$settings    = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
			$children    = is_array( $el['elements'] ?? null ) ? $el['elements'] : array();

			$counts['total']++;
			if ( in_array( $el_type, array( 'container', 'section', 'column' ), true ) ) {
				$counts['containers']++;
			} elseif ( $el_type === 'widget' ) {
				$counts['widgets']++;
				if ( $widget_type !== '' ) {
					$summary['widgets_used'][] = $widget_type;
				}
			}

			$defaults = $widget_type !== '' ? $this->get_widget_defaults( $widget_type ) : array();
			$resolved = $this->resolve_settings( $settings, $defaults, $summary );

			if ( ! empty( $settings['custom_css'] ) ) {
				$summary['has_custom_css'] = true;
			}
			if ( ! empty( $settings['background_background'] ) ) {
				$summary['background_types'][] = (string) $settings['background_background'];
			}
			foreach ( $settings as $key => $val ) {
				if ( is_string( $key ) && preg_match( '/_(tablet|mobile)$/', $key ) && $val !== '' && $val !== null ) {
					$summary['responsive_overrides']++;
				}
			}

			$entry = array(
				'id'                => $el_id,
				'elType'            => $el_type,
				'depth'             => $depth,
				'settings_raw'      => $settings,
				'settings_resolved' => $resolved,
				'children_ids'      => array_map( static fn( $c ) => (string) ( $c['id'] ?? '' ), array_filter( $children, 'is_array' ) ),
			);
			if ( $widget_type !== '' ) {
				$entry['widgetType'] = $widget_type;
			}
			if ( $frame['parent'] !== null ) {
				$entry['parent_id'] = (string) $frame['parent'];
			}
			$result[] = $entry;

			foreach ( array_reverse( $children ) as $child ) {
				if ( is_array( $child ) ) {
					$stack[] = array( 'el' => $child, 'depth' => $depth + 1, 'parent' => $el_id );
				}
			}
		}

		return null;
	}

	private function load_globals(): void {
		$reader = new Reader();
		$kit    = $reader->get_kit();
		if ( is_wp_error( $kit ) ) {
			return;
		}
		foreach ( array( 'system', 'custom' ) as $bucket ) {
			foreach ( $kit['global_colors'][ $bucket ] ?? array() as $color ) {
				if ( isset( $color['id'] ) ) {
					$this->global_colors[ (string) $color['id'] ] = $color;
				}
			}
			foreach ( $kit['global_fonts'][ $bucket ] ?? array() as $font ) {
				if ( isset( $font['id'] ) ) {
					$this->global_fonts[ (string) $font['id'] ] = $font;
				}
			}
		}
	}

	private function get_widget_defaults( string $widget_type ): array {
		if ( ! Reader::is_active() ) {
			return array();
		}
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );
		if ( ! $widget ) {
			return array();
		}
		try {
			$stack    = $widget->get_stack();
			$controls = (array) ( $stack['controls'] ?? array() );
		} catch ( \Throwable $e ) {
			return array();
		}
		$defaults = array();
		foreach ( $controls as $id => $control ) {
			if ( isset( $control['default'] ) ) {
				$defaults[ (string) $id ] = $control['default'];
			}
		}
		return $defaults;
	}

	private function resolve_settings( array $settings, array $defaults, array &$summary ): array {
		$resolved = array();
		$globals  = is_array( $settings['__globals__'] ?? null ) ? $settings['__globals__'] : array();

		foreach ( $defaults as $key => $value ) {
			if ( strpos( (string) $key, '_' ) === 0 && $key !== '__globals__' ) {
				continue;
			}
			$resolved[ (string) $key ] = $value;
		}

		foreach ( $globals as $key => $ref ) {
			$value = $this->resolve_global( (string) $ref );
			if ( $value === null ) {
				continue;
			}
			$id = $this->extract_global_id( (string) $ref );
			if ( is_array( $value ) ) {
				foreach ( $value as $rk => $rv ) {
					$resolved[ (string) $rk ]              = $rv;
					$resolved[ (string) $rk . '_source' ]  = 'global:' . $id;
				}
			} else {
				$resolved[ (string) $key ]            = $value;
				$resolved[ (string) $key . '_source' ] = 'global:' . $id;
			}
			if ( strpos( (string) $key, 'color' ) !== false ) {
				$summary['uses_global_colors'] = true;
			}
			if ( strpos( (string) $key, 'typography' ) !== false ) {
				$summary['uses_global_fonts'] = true;
			}
		}

		foreach ( $settings as $key => $value ) {
			if ( $key === '__globals__' ) {
				continue;
			}
			if ( strpos( (string) $key, '_' ) === 0 ) {
				continue;
			}
			if ( $value !== '' && $value !== null && ! isset( $globals[ $key ] ) ) {
				$resolved[ (string) $key ] = $value;
				if ( isset( $defaults[ $key ] ) && $defaults[ $key ] !== $value ) {
					$resolved[ (string) $key . '_source' ] = 'inline';
				}
			} elseif ( ! isset( $resolved[ $key ] ) ) {
				$resolved[ (string) $key ] = $value;
			}

			if ( strpos( (string) $key, 'color' ) !== false && is_string( $value ) && preg_match( '/^#[0-9A-Fa-f]{3,8}$/', $value ) && ! isset( $globals[ $key ] ) ) {
				$summary['custom_colors'][] = $value;
			}
			if ( $key === 'typography_font_family' && is_string( $value ) && $value !== '' && ! isset( $globals['typography_typography'] ) ) {
				$summary['custom_fonts'][] = $value;
			}
		}

		return $resolved;
	}

	private function resolve_global( string $ref ): mixed {
		$id = $this->extract_global_id( $ref );
		if ( $id === '' ) {
			return null;
		}
		if ( strpos( $ref, 'globals/colors' ) !== false ) {
			return $this->global_colors[ $id ]['color'] ?? null;
		}
		if ( strpos( $ref, 'globals/typography' ) !== false ) {
			$font = $this->global_fonts[ $id ] ?? null;
			if ( $font === null ) {
				return null;
			}
			$mapping = array(
				'font_family'      => 'typography_font_family',
				'font_weight'      => 'typography_font_weight',
				'font_size'        => 'typography_font_size',
				'font_size_tablet' => 'typography_font_size_tablet',
				'font_size_mobile' => 'typography_font_size_mobile',
				'line_height'      => 'typography_line_height',
				'letter_spacing'   => 'typography_letter_spacing',
				'text_transform'   => 'typography_text_transform',
				'font_style'       => 'typography_font_style',
			);
			$out = array();
			foreach ( $mapping as $src => $dst ) {
				if ( isset( $font[ $src ] ) ) {
					$out[ $dst ] = $font[ $src ];
				}
			}
			$out['typography_typography'] = 'custom';
			return $out;
		}
		return null;
	}

	private function extract_global_id( string $ref ): string {
		if ( preg_match( '/[?&]id=([^&]+)/', $ref, $matches ) ) {
			return (string) $matches[1];
		}
		return '';
	}
}
