<?php

declare( strict_types=1 );

namespace WODO_Bridge\Elementor;

use WODO_Bridge\Lib\Constants;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Writer {

	public function create_page( array $data ): array|WP_Error {
		if ( ! Reader::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$validation = $this->validate( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$elementor_data = $validation['elementor_data'];
		$post_type      = isset( $data['post_type'] ) ? sanitize_text_field( (string) $data['post_type'] ) : 'page';
		$template       = isset( $data['template'] ) ? sanitize_text_field( (string) $data['template'] ) : 'elementor_canvas';
		$status         = isset( $data['status'] ) && in_array( $data['status'], array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ? (string) $data['status'] : 'draft';

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( (string) $data['title'] ),
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_content' => '',
				'post_name'    => isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, '_wp_page_template', $template );
		update_post_meta( (int) $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		update_post_meta( (int) $post_id, '_wodo_bridge_authored', '1' );

		$page_settings = is_array( $data['page_settings'] ?? null ) ? $data['page_settings'] : array();
		$this->save_via_document( (int) $post_id, $elementor_data, $page_settings );

		return $this->summary( (int) $post_id );
	}

	public function update_page( int $post_id, array $data ): array|WP_Error {
		if ( ! Reader::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}

		$update = array( 'ID' => $post_id );
		if ( isset( $data['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['slug'] ) ) {
			$update['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
			$update['post_status'] = (string) $data['status'];
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $data['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( (string) $data['template'] ) );
		}

		if ( ! empty( $data['elementor_data'] ) ) {
			$validation = $this->validate( $data );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$page_settings = is_array( $data['page_settings'] ?? null ) ? $data['page_settings'] : array();
			$this->save_via_document( $post_id, $validation['elementor_data'], $page_settings );
		}

		update_post_meta( $post_id, '_wodo_bridge_authored', '1' );

		return $this->summary( $post_id );
	}

	public function create_template( array $data ): array|WP_Error {
		if ( ! Reader::is_active() ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'wodo-bridge' ), array( 'status' => 409 ) );
		}
		$validation = $this->validate( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$type = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : 'section';
		if ( ! in_array( $type, array( 'page', 'section' ), true ) ) {
			return new WP_Error( 'validation_failed', __( 'Invalid template type.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( (string) $data['title'] ),
				'post_status' => 'publish',
				'post_type'   => 'elementor_library',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( (int) $post_id, '_elementor_template_type', $type );
		update_post_meta( (int) $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( (int) $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );

		$this->save_via_document( (int) $post_id, $validation['elementor_data'], array() );

		return array(
			'id'       => (int) $post_id,
			'title'    => (string) get_the_title( (int) $post_id ),
			'type'     => $type,
			'edit_url' => admin_url( "post.php?post={$post_id}&action=elementor" ),
		);
	}

	/**
	 * Validate (and normalize) an Elementor payload without persisting.
	 *
	 * Used internally by create/update flows AND exposed via the
	 * /elementor/validate REST route so callers can dry-run a payload
	 * before writing. Skips the `title` requirement when invoked in
	 * dry-run mode (the validate route does not require a title — only
	 * the structural shape of `elementor_data` is checked).
	 *
	 * On success returns: [ 'elementor_data' => array, 'element_count' => int ]
	 *
	 * @param array $data         Payload from caller.
	 * @param bool  $require_title Whether the title field is mandatory.
	 */
	public function validate( array $data, bool $require_title = true ): array|WP_Error {
		if ( $require_title && empty( $data['title'] ) ) {
			return new WP_Error( 'validation_failed', __( 'Title is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}
		if ( empty( $data['elementor_data'] ) ) {
			return new WP_Error( 'validation_failed', __( 'elementor_data is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}
		$payload = $data['elementor_data'];
		if ( is_string( $payload ) ) {
			if ( strlen( $payload ) > Constants::ELEMENTOR_MAX_PAYLOAD ) {
				return new WP_Error( 'payload_too_large', __( 'Elementor payload exceeds size cap.', 'wodo-bridge' ), array( 'status' => 413 ) );
			}
			$decoded = json_decode( $payload, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'invalid_json', __( 'elementor_data is not valid JSON.', 'wodo-bridge' ), array( 'status' => 400 ) );
			}
			$payload = $decoded;
		}
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'validation_failed', __( 'elementor_data must be an array.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$count = 0;
		$ok    = $this->cap_check( $payload, 0, $count );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$payload = $this->ensure_ids( $payload );
		return array(
			'elementor_data' => $payload,
			'element_count'  => $count,
		);
	}

	private function cap_check( array $elements, int $depth, int &$count ): null|WP_Error {
		if ( $depth > Constants::ELEMENTOR_MAX_DEPTH ) {
			return new WP_Error( 'payload_too_deep', __( 'Element depth cap exceeded.', 'wodo-bridge' ), array( 'status' => 413 ) );
		}
		foreach ( $elements as $el ) {
			$count++;
			if ( $count > Constants::ELEMENTOR_MAX_ELEMENTS ) {
				return new WP_Error( 'payload_too_large', __( 'Element count cap exceeded.', 'wodo-bridge' ), array( 'status' => 413 ) );
			}
			if ( is_array( $el ) && ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$result = $this->cap_check( $el['elements'], $depth + 1, $count );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return null;
	}

	private function ensure_ids( array $elements, array &$used = array() ): array {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( empty( $el['id'] ) || in_array( $el['id'], $used, true ) ) {
				$el['id'] = $this->generate_id();
			}
			$used[] = $el['id'];
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}
			if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
				$el['elements'] = array();
			}
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->ensure_ids( $el['elements'], $used );
			}
		}
		return $elements;
	}

	private function generate_id(): string {
		try {
			return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
		} catch ( \Throwable $e ) {
			return substr( md5( (string) microtime( true ) . wp_rand() ), 0, 7 );
		}
	}

	private function save_via_document( int $post_id, array $elementor_data, array $page_settings ): void {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $document ) {
				$save = array( 'elements' => $elementor_data );
				if ( ! empty( $page_settings ) ) {
					$save['settings'] = $page_settings;
				}
				$document->save( $save );
				return;
			}
		}
		update_post_meta( $post_id, '_elementor_data', wp_json_encode( $elementor_data ) );
		if ( ! empty( $page_settings ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		}
	}

	private function summary( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		return array(
			'id'          => (int) $post->ID,
			'title'       => (string) $post->post_title,
			'slug'        => (string) $post->post_name,
			'status'      => (string) $post->post_status,
			'url'         => (string) get_permalink( $post_id ),
			'edit_url'    => admin_url( "post.php?post={$post_id}&action=elementor" ),
			'preview_url' => (string) get_preview_post_link( $post_id ),
		);
	}
}
