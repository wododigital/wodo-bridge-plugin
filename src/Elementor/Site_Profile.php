<?php

declare( strict_types=1 );

namespace WODO_Bridge\Elementor;

use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Profile {

	private const STALE_DAYS = 7;

	public function get(): array {
		$raw = get_option( Constants::OPTION_SITE_PROFILE, '' );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return $this->build();
	}

	public function is_stale( array $profile ): bool {
		if ( empty( $profile['generated_at'] ) ) {
			return true;
		}
		$generated = strtotime( (string) $profile['generated_at'] );
		if ( $generated === false ) {
			return true;
		}
		if ( ( time() - $generated ) > ( self::STALE_DAYS * DAY_IN_SECONDS ) ) {
			return true;
		}
		if ( defined( 'ELEMENTOR_VERSION' ) && isset( $profile['environment']['elementor_version'] ) && $profile['environment']['elementor_version'] !== ELEMENTOR_VERSION ) {
			return true;
		}
		$active = get_option( 'active_plugins', array() );
		if ( isset( $profile['environment']['active_plugin_count'] ) && count( $active ) !== (int) $profile['environment']['active_plugin_count'] ) {
			return true;
		}
		return false;
	}

	public function build(): array {
		$start  = microtime( true );
		$reader = new Reader();
		$kit    = $reader->get_kit();
		$kit    = is_wp_error( $kit ) ? array(
			'global_colors' => array(),
			'global_fonts'  => array(),
			'kit_settings'  => array(),
		) : $kit;

		$widgets = $reader->get_widgets();
		$widgets = is_wp_error( $widgets ) ? array( 'widgets' => array() ) : $widgets;

		$profile = array(
			'version'           => WODO_BRIDGE_VERSION,
			'generated_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'environment'       => $this->environment(),
			'global_colors'     => $kit['global_colors'],
			'global_fonts'      => $kit['global_fonts'],
			'kit_settings'      => $kit['kit_settings'],
			'registered_widgets'=> $widgets['widgets'],
			'page_patterns'     => $this->patterns(),
		);
		$profile['build_time_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );

		update_option( Constants::OPTION_SITE_PROFILE, wp_json_encode( $profile ), false );
		return $profile;
	}

	public function refresh(): array {
		return $this->build();
	}

	private function environment(): array {
		$theme  = wp_get_theme();
		$active = get_option( 'active_plugins', array() );
		return array(
			'elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown',
			'elementor_pro'       => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : false,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'site_url'            => get_site_url(),
			'site_name'           => get_bloginfo( 'name' ),
			'theme'               => array(
				'name'     => (string) $theme->get( 'Name' ),
				'version'  => (string) $theme->get( 'Version' ),
				'parent'   => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : null,
				'template' => (string) $theme->get_template(),
			),
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'active_plugin_count' => count( $active ),
		);
	}

	private function patterns(): array {
		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => '_elementor_edit_mode',
				'meta_value'     => 'builder',
			)
		);

		$widgets    = array();
		$structures = array();
		$layout     = 'container';
		$analyzed   = 0;

		foreach ( $pages as $page ) {
			$raw = (string) get_post_meta( $page->ID, '_elementor_data', true );
			if ( $raw === '' ) {
				continue;
			}
			$elements = json_decode( $raw, true );
			if ( ! is_array( $elements ) ) {
				continue;
			}
			$analyzed++;
			$this->walk( $elements, $widgets, $structures, $layout );
		}

		$counts = array_count_values( $widgets );
		arsort( $counts );

		return array(
			'sample_pages_analyzed' => $analyzed,
			'common_structures'     => array_values( array_unique( array_slice( $structures, 0, 10 ) ) ),
			'common_widgets_used'   => array_keys( array_slice( $counts, 0, 15, true ) ),
			'layout_mode'           => $layout,
		);
	}

	private function walk( array $elements, array &$widgets, array &$structures, string &$layout ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type = (string) ( $el['elType'] ?? '' );
			if ( $type === 'section' ) {
				$layout       = 'section';
				$structures[] = 'section';
			} elseif ( $type === 'container' ) {
				$dir          = (string) ( $el['settings']['flex_direction'] ?? 'column' );
				$structures[] = 'container:' . $dir;
			} elseif ( $type === 'widget' ) {
				$widgets[] = (string) ( $el['widgetType'] ?? 'unknown' );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk( $el['elements'], $widgets, $structures, $layout );
			}
		}
	}
}
