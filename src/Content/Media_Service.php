<?php

declare( strict_types=1 );

namespace WODO_Bridge\Content;

use WODO_Bridge\Lib\Constants;
use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Media_Service {

	public function list( array $args ): array {
		$per_page = max( 1, min( Constants::POSTS_MAX_PER_PAGE, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = sanitize_text_field( (string) $args['mime_type'] );
		}

		$query = new WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->summary( $post );
			}
		}

		return array(
			'total'      => (int) $query->found_posts,
			'items'      => $items,
			'pagination' => array(
				'current_page' => $page,
				'total_pages'  => (int) $query->max_num_pages,
				'per_page'     => $per_page,
			),
		);
	}

	public function get( int $id ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== 'attachment' ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		return $this->full( $post );
	}

	public function update( int $id, array $payload ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== 'attachment' ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}

		$update = array( 'ID' => $id );
		if ( isset( $payload['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( isset( $payload['caption'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $payload['caption'] );
		}
		if ( isset( $payload['description'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $payload['description'] );
		}
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $payload['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $payload['alt_text'] ) );
		}

		return $this->get( $id );
	}

	public function delete( int $id, bool $force = false ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || $post->post_type !== 'attachment' ) {
			return new WP_Error( 'not_found', __( 'Attachment not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		$result = wp_delete_attachment( $id, $force );
		if ( $result === false ) {
			return new WP_Error( 'internal_error', __( 'Failed to delete attachment.', 'wodo-bridge' ), array( 'status' => 500 ) );
		}
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	public function upload_from_url( string $url, array $payload, array $scopes ): array|WP_Error {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$url = esc_url_raw( $url );
		if ( $url === '' ) {
			return new WP_Error( 'validation_failed', __( 'source_url is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename = isset( $payload['filename'] ) ? sanitize_file_name( (string) $payload['filename'] ) : basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'download' );
		$file_array = array(
			'name'     => $filename !== '' ? $filename : 'upload',
			'tmp_name' => $tmp,
		);

		$mime = wp_check_filetype( $file_array['name'] );
		if ( ! $this->mime_allowed( (string) ( $mime['type'] ?? '' ), $scopes ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'unsupported_media_type', __( 'Mime type not allowed.', 'wodo-bridge' ), array( 'status' => 415 ) );
		}

		$attachment_id = media_handle_sideload( $file_array, isset( $payload['post'] ) ? (int) $payload['post'] : 0 );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $attachment_id;
		}

		$this->apply_attachment_meta( (int) $attachment_id, $payload );
		return $this->get( (int) $attachment_id );
	}

	public function upload_from_request( array $file, array $payload, array $scopes ): array|WP_Error {
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$mime = wp_check_filetype( (string) ( $file['name'] ?? '' ) );
		if ( ! $this->mime_allowed( (string) ( $mime['type'] ?? '' ), $scopes ) ) {
			return new WP_Error( 'unsupported_media_type', __( 'Mime type not allowed.', 'wodo-bridge' ), array( 'status' => 415 ) );
		}

		$overrides = array( 'test_form' => false );
		$result    = wp_handle_sideload( $file, $overrides );
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'internal_error', (string) $result['error'], array( 'status' => 500 ) );
		}

		$attachment = array(
			'post_mime_type' => $result['type'],
			'post_title'     => sanitize_text_field( (string) ( $payload['title'] ?? pathinfo( $result['file'], PATHINFO_FILENAME ) ) ),
			'post_status'    => 'inherit',
			'post_content'   => '',
		);

		$attachment_id = wp_insert_attachment( $attachment, $result['file'], isset( $payload['post'] ) ? (int) $payload['post'] : 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $result['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$this->apply_attachment_meta( (int) $attachment_id, $payload );
		return $this->get( (int) $attachment_id );
	}

	private function summary( WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'mime_type' => $post->post_mime_type,
			'date'      => mysql2date( 'c', $post->post_date_gmt, false ),
			'url'       => wp_get_attachment_url( $post->ID ),
		);
	}

	private function full( WP_Post $post ): array {
		$summary               = $this->summary( $post );
		$summary['alt_text']   = (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		$summary['caption']    = $post->post_excerpt;
		$summary['description'] = $post->post_content;
		$summary['sizes']      = $this->sizes( $post->ID );
		$summary['exif']       = $this->exif( $post->ID );
		return $summary;
	}

	private function sizes( int $id ): array {
		$out  = array();
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) {
				$out['full'] = array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
			return $out;
		}
		foreach ( array_keys( $meta['sizes'] ) as $size ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( $src ) {
				$out[ $size ] = array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}
		$full = wp_get_attachment_image_src( $id, 'full' );
		if ( $full ) {
			$out['full'] = array(
				'url'    => $full[0],
				'width'  => (int) $full[1],
				'height' => (int) $full[2],
			);
		}
		return $out;
	}

	private function exif( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && isset( $meta['image_meta'] ) && is_array( $meta['image_meta'] ) ) {
			return $meta['image_meta'];
		}
		return array();
	}

	private function apply_attachment_meta( int $id, array $payload ): void {
		if ( isset( $payload['alt_text'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $payload['alt_text'] ) );
		}
		if ( isset( $payload['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => sanitize_textarea_field( (string) $payload['caption'] ),
				)
			);
		}
		if ( isset( $payload['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( (string) $payload['title'] ),
				)
			);
		}
	}

	private function mime_allowed( string $mime, array $scopes ): bool {
		if ( $mime === '' ) {
			return false;
		}
		if ( in_array( $mime, Constants::SVG_MIME_TYPES, true ) ) {
			return in_array( 'media.svg', $scopes, true ) || in_array( 'admin.full', $scopes, true );
		}
		return in_array( $mime, Constants::SUPPORTED_MIME_TYPES, true );
	}
}
