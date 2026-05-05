<?php

declare( strict_types=1 );

namespace WODO_Bridge\Elementor;

use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reader {

	public static function is_active(): bool {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' );
	}

	public function get_kit(): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}

		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( $kit_id === 0 ) {
			return array(
				'global_colors' => array( 'system' => array(), 'custom' => array() ),
				'global_fonts'  => array( 'system' => array(), 'custom' => array() ),
				'kit_settings'  => array(),
			);
		}

		$document = \Elementor\Plugin::$instance->documents->get( $kit_id );
		$settings = $document ? $document->get_settings() : array();

		return array(
			'global_colors' => $this->extract_colors( $settings ),
			'global_fonts'  => $this->extract_fonts( $settings ),
			'kit_settings'  => $this->extract_kit_settings( $settings ),
		);
	}

	public function get_widgets( array $args = array() ): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$category = isset( $args['category'] ) ? (string) $args['category'] : '';
		$search   = isset( $args['search'] ) ? strtolower( (string) $args['search'] ) : '';
		$include_controls = ! empty( $args['include_controls'] );

		$manager = \Elementor\Plugin::$instance->widgets_manager;
		$out     = array();
		foreach ( $manager->get_widget_types() as $widget ) {
			$name       = (string) $widget->get_name();
			$title      = (string) $widget->get_title();
			$categories = (array) $widget->get_categories();
			$keywords   = (array) $widget->get_keywords();
			$source     = $this->detect_source( get_class( $widget ) );

			if ( $category !== '' && ! in_array( $category, $categories, true ) ) {
				continue;
			}
			if ( $search !== '' ) {
				$haystack = strtolower( $name . ' ' . $title . ' ' . implode( ' ', $keywords ) );
				if ( strpos( $haystack, $search ) === false ) {
					continue;
				}
			}

			$entry = array(
				'name'       => $name,
				'title'      => $title,
				'icon'       => (string) $widget->get_icon(),
				'categories' => $categories,
				'keywords'   => $keywords,
				'source'     => $source,
			);
			if ( $include_controls ) {
				$entry['controls'] = $this->controls_for( $widget );
			}

			$out[] = $entry;
		}

		return array(
			'total'   => count( $out ),
			'widgets' => $out,
		);
	}

	public function get_widget_controls( string $name ): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $name );
		if ( ! $widget ) {
			return new WP_Error( 'not_found', __( 'Widget not registered.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		return array(
			'name'     => (string) $widget->get_name(),
			'title'    => (string) $widget->get_title(),
			'controls' => $this->controls_for( $widget ),
		);
	}

	public function list_pages( array $args = array() ): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}

		$post_type = $args['post_type'] ?? array( 'page', 'post' );
		if ( is_string( $post_type ) ) {
			$post_type = array_map( 'trim', explode( ',', $post_type ) );
		}
		$status = $args['status'] ?? array( 'publish', 'draft', 'private' );
		if ( is_string( $status ) ) {
			$status = array_map( 'trim', explode( ',', $status ) );
		}

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}

		$query = new WP_Query( $query_args );
		$pages = array();
		foreach ( $query->posts as $post ) {
			$pages[] = array(
				'id'                => (int) $post->ID,
				'title'             => (string) $post->post_title,
				'slug'              => (string) $post->post_name,
				'status'            => (string) $post->post_status,
				'post_type'         => (string) $post->post_type,
				'url'               => (string) get_permalink( $post->ID ),
				'edit_url'          => admin_url( "post.php?post={$post->ID}&action=elementor" ),
				'template'          => (string) ( get_post_meta( $post->ID, '_wp_page_template', true ) ?: 'default' ),
				'modified'          => mysql2date( 'c', $post->post_modified_gmt, false ),
				'elementor_version' => (string) ( get_post_meta( $post->ID, '_elementor_version', true ) ?: 'unknown' ),
			);
		}

		return array(
			'total'      => (int) $query->found_posts,
			'pages'      => $pages,
			'pagination' => array(
				'current_page' => $page,
				'total_pages'  => (int) $query->max_num_pages,
				'per_page'     => $per_page,
			),
		);
	}

	public function get_page( int $post_id ): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) !== 'builder' ) {
			return new WP_Error( 'not_found', __( 'Page is not built with Elementor.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}

		$raw     = (string) get_post_meta( $post_id, '_elementor_data', true );
		$decoded = $raw === '' ? array() : json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$template      = get_post_meta( $post_id, '_wp_page_template', true );

		$css = $this->read_generated_css( $post_id );

		return array(
			'id'             => (int) $post->ID,
			'title'          => (string) $post->post_title,
			'slug'           => (string) $post->post_name,
			'status'         => (string) $post->post_status,
			'template'       => (string) ( $template ?: 'default' ),
			'url'            => (string) get_permalink( $post_id ),
			'elementor_data' => $decoded,
			'page_settings'  => is_array( $page_settings ) ? $page_settings : array(),
			'css'            => $css,
		);
	}

	public function list_templates( string $type = '' ): array|WP_Error {
		if ( ! self::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$query_args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $type !== '' ) {
			$query_args['meta_key']   = '_elementor_template_type';
			$query_args['meta_value'] = sanitize_text_field( $type );
		}

		$query     = new WP_Query( $query_args );
		$templates = array();
		foreach ( $query->posts as $post ) {
			$templates[] = array(
				'id'        => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'type'      => (string) ( get_post_meta( $post->ID, '_elementor_template_type', true ) ?: 'unknown' ),
				'status'    => (string) $post->post_status,
				'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
				'thumbnail' => (string) ( get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '' ),
			);
		}
		return array(
			'total'     => (int) $query->found_posts,
			'templates' => $templates,
		);
	}

	private function controls_for( $widget ): array {
		try {
			$stack = $widget->get_stack();
		} catch ( \Throwable $e ) {
			return array();
		}
		$controls = isset( $stack['controls'] ) ? (array) $stack['controls'] : array();
		$out      = array();
		foreach ( $controls as $id => $control ) {
			if ( strpos( (string) $id, '_' ) === 0 && $id !== '__globals__' ) {
				continue;
			}
			$entry = array(
				'type'  => (string) ( $control['type'] ?? '' ),
				'label' => (string) ( $control['label'] ?? '' ),
			);
			foreach ( array( 'default', 'options', 'section', 'tab', 'selectors', 'condition' ) as $field ) {
				if ( isset( $control[ $field ] ) ) {
					$entry[ $field ] = $control[ $field ];
				}
			}
			if ( ! empty( $control['responsive'] ) ) {
				$entry['responsive'] = true;
			}
			$out[ (string) $id ] = $entry;
		}
		return $out;
	}

	private function detect_source( string $class ): string {
		if ( strpos( $class, 'ElementorPro' ) !== false ) {
			return 'pro';
		}
		if ( strpos( $class, 'Elementor\\' ) === 0 || strpos( $class, '\\Elementor\\' ) !== false ) {
			return 'core';
		}
		return 'addon';
	}

	private function extract_colors( array $settings ): array {
		$out = array( 'system' => array(), 'custom' => array() );
		foreach ( array( 'system_colors' => 'system', 'custom_colors' => 'custom' ) as $key => $bucket ) {
			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}
			foreach ( $settings[ $key ] as $color ) {
				if ( ! is_array( $color ) ) {
					continue;
				}
				$out[ $bucket ][] = array(
					'id'    => (string) ( $color['_id'] ?? '' ),
					'title' => (string) ( $color['title'] ?? '' ),
					'color' => (string) ( $color['color'] ?? '' ),
				);
			}
		}
		return $out;
	}

	private function extract_fonts( array $settings ): array {
		$out = array( 'system' => array(), 'custom' => array() );
		foreach ( array( 'system_typography' => 'system', 'custom_typography' => 'custom' ) as $key => $bucket ) {
			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}
			foreach ( $settings[ $key ] as $typo ) {
				if ( ! is_array( $typo ) ) {
					continue;
				}
				$entry = array(
					'id'    => (string) ( $typo['_id'] ?? '' ),
					'title' => (string) ( $typo['title'] ?? '' ),
				);
				foreach ( $typo as $tk => $tv ) {
					if ( strpos( (string) $tk, 'typography_' ) === 0 ) {
						$entry[ substr( (string) $tk, strlen( 'typography_' ) ) ] = $tv;
					}
				}
				$out[ $bucket ][] = $entry;
			}
		}
		return $out;
	}

	private function extract_kit_settings( array $settings ): array {
		$out  = array();
		$keys = array( 'container_width', 'content_width', 'space_between_widgets', 'page_title_selector', 'stretched_section_container' );
		foreach ( $keys as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$out[ $key ] = $settings[ $key ];
			}
		}
		$buttons = array();
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && strpos( $key, 'button_' ) === 0 ) {
				$buttons[ substr( $key, 7 ) ] = $value;
			}
		}
		if ( ! empty( $buttons ) ) {
			$out['buttons'] = $buttons;
		}
		return $out;
	}

	private function read_generated_css( int $post_id ): string {
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( $base === '' ) {
			return '';
		}
		$candidate = $base . '/elementor/css/post-' . $post_id . '.css';
		$base_real = realpath( $base );
		$file_real = realpath( $candidate );
		if ( $base_real === false || $file_real === false ) {
			return '';
		}
		if ( strncmp( $file_real, $base_real, strlen( $base_real ) ) !== 0 ) {
			return '';
		}
		$handle = @fopen( $file_real, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( ! $handle ) {
			return '';
		}
		$content = stream_get_contents( $handle, \WODO_Bridge\Lib\Constants::ELEMENTOR_MAX_CSS_BYTES );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return is_string( $content ) ? $content : '';
	}
}
