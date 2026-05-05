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

final class Post_Service {

	private Block_Codec $codec;
	private Cpt_Service $cpt;

	public function __construct( ?Block_Codec $codec = null, ?Cpt_Service $cpt = null ) {
		$this->codec = $codec ?? new Block_Codec();
		$this->cpt   = $cpt ?? new Cpt_Service();
	}

	public function list( array $args ): array {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : Constants::POSTS_DEFAULT_PER_PAGE;
		$per_page = max( 1, min( Constants::POSTS_MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$type = isset( $args['type'] ) ? (string) $args['type'] : 'post';

		$query_args = array(
			'post_type'      => $type === 'any' ? 'any' : $type,
			'post_status'    => $this->normalize_status( $args['status'] ?? 'any' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $this->normalize_orderby( $args['orderby'] ?? 'date' ),
			'order'          => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = (int) $args['author'];
		}
		if ( ! empty( $args['date_from'] ) || ! empty( $args['date_to'] ) ) {
			$query_args['date_query'] = array(
				array(
					'after'     => sanitize_text_field( (string) ( $args['date_from'] ?? '' ) ),
					'before'    => sanitize_text_field( (string) ( $args['date_to'] ?? '' ) ),
					'inclusive' => true,
				),
			);
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

	public function get( int $id, bool $include_blocks = true ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		return $this->full( $post, $include_blocks );
	}

	public function create( int $user_id, string $type, array $payload ): array|WP_Error {
		if ( $this->cpt->get( $type ) === null ) {
			return new WP_Error( 'not_found', __( 'Unknown post type.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! $this->cpt->user_can_edit( $type, $user_id ) ) {
			return new WP_Error( 'capability_missing', __( 'User cannot edit this post type.', 'wodo-bridge' ), array( 'status' => 403 ) );
		}

		$status = $this->validate_status( $payload['status'] ?? 'draft' );
		if ( $status === null ) {
			return new WP_Error( 'validation_failed', __( 'Invalid status.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}
		if ( $status === 'publish' && ! $this->cpt->user_can_publish( $type, $user_id ) ) {
			return new WP_Error( 'capability_missing', __( 'User cannot publish in this post type.', 'wodo-bridge' ), array( 'status' => 403 ) );
		}

		$insert = array(
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( (string) ( $payload['title'] ?? '' ) ),
			'post_content' => $this->prepare_content( $payload['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( (string) ( $payload['excerpt'] ?? '' ) ),
			'post_author'  => $user_id,
		);

		if ( ! empty( $payload['slug'] ) ) {
			$insert['post_name'] = sanitize_title( (string) $payload['slug'] );
		}

		$id = wp_insert_post( $insert, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->apply_side_effects( (int) $id, $payload );

		return $this->get( (int) $id );
	}

	public function update( int $user_id, int $id, array $payload ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! $this->cpt->user_can_edit( (string) $post->post_type, $user_id ) ) {
			return new WP_Error( 'capability_missing', __( 'User cannot edit this post type.', 'wodo-bridge' ), array( 'status' => 403 ) );
		}

		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $payload ) ) {
			$update['post_title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( array_key_exists( 'content', $payload ) ) {
			$update['post_content'] = $this->prepare_content( $payload['content'] );
		}
		if ( array_key_exists( 'excerpt', $payload ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $payload['excerpt'] );
		}
		if ( array_key_exists( 'slug', $payload ) ) {
			$update['post_name'] = sanitize_title( (string) $payload['slug'] );
		}
		if ( array_key_exists( 'status', $payload ) ) {
			$status = $this->validate_status( (string) $payload['status'] );
			if ( $status === null ) {
				return new WP_Error( 'validation_failed', __( 'Invalid status.', 'wodo-bridge' ), array( 'status' => 422 ) );
			}
			if ( $status === 'publish' && ! $this->cpt->user_can_publish( (string) $post->post_type, $user_id ) ) {
				return new WP_Error( 'capability_missing', __( 'User cannot publish in this post type.', 'wodo-bridge' ), array( 'status' => 403 ) );
			}
			$update['post_status'] = $status;
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->apply_side_effects( $id, $payload );

		return $this->get( $id );
	}

	public function delete( int $user_id, int $id, bool $force = false ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( ! $this->cpt->user_can_delete( (string) $post->post_type, $user_id, $id ) ) {
			return new WP_Error( 'capability_missing', __( 'User cannot delete this post.', 'wodo-bridge' ), array( 'status' => 403 ) );
		}

		$result = wp_delete_post( $id, $force );
		if ( $result === false || $result === null ) {
			return new WP_Error( 'internal_error', __( 'Failed to delete post.', 'wodo-bridge' ), array( 'status' => 500 ) );
		}

		return array(
			'deleted' => true,
			'id'      => $id,
			'force'   => $force,
		);
	}

	public function summary( WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'type'      => $post->post_type,
			'date'      => mysql2date( 'c', $post->post_date_gmt, false ),
			'modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
			'author'    => (int) $post->post_author,
			'url'       => get_permalink( $post->ID ),
			'edit_url'  => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		);
	}

	public function full( WP_Post $post, bool $include_blocks = true ): array {
		$summary           = $this->summary( $post );
		$summary['content'] = $post->post_content;
		$summary['excerpt'] = $post->post_excerpt;
		if ( $include_blocks ) {
			$summary['blocks'] = $this->codec->decode( (string) $post->post_content );
		}
		$summary['featured_media'] = (int) get_post_thumbnail_id( $post->ID );
		$summary['template']       = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		$summary['meta']           = $this->safe_meta( $post->ID );
		$summary['terms']          = $this->terms_for( $post );
		return $summary;
	}

	private function apply_side_effects( int $post_id, array $payload ): void {
		if ( isset( $payload['featured_media'] ) ) {
			$thumb = (int) $payload['featured_media'];
			if ( $thumb > 0 ) {
				set_post_thumbnail( $post_id, $thumb );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		if ( isset( $payload['template'] ) && in_array( '_wp_page_template', Constants::POSTMETA_ALLOWLIST, true ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( (string) $payload['template'] ) );
		}

		if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			$this->merge_meta( $post_id, $payload['meta'] );
		}

		if ( isset( $payload['terms'] ) && is_array( $payload['terms'] ) ) {
			$this->merge_terms( $post_id, $payload['terms'] );
		}

		update_post_meta( $post_id, '_wodo_bridge_authored', '1' );
		do_action( 'wodo_bridge_post_authored', $post_id, $payload );
	}

	private function merge_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! $this->meta_key_writable( $key ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $value ) );
			}
		}
	}

	private function meta_key_writable( string $key ): bool {
		if ( $key === '' ) {
			return false;
		}
		if ( in_array( $key, Constants::ELEMENTOR_META_KEYS, true ) ) {
			return false;
		}
		if ( strpos( $key, Constants::POSTMETA_DENY_PREFIX ) === 0 ) {
			return in_array( $key, Constants::POSTMETA_ALLOWLIST, true );
		}
		return true;
	}

	private function merge_terms( int $post_id, array $terms ): void {
		foreach ( $terms as $taxonomy => $values ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $values ) ) {
				continue;
			}
			$ids = array();
			foreach ( $values as $value ) {
				if ( is_numeric( $value ) ) {
					$ids[] = (int) $value;
					continue;
				}
				$slug = sanitize_title( (string) $value );
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term ) {
					$inserted = wp_insert_term( (string) $value, $taxonomy );
					if ( ! is_wp_error( $inserted ) ) {
						$ids[] = (int) $inserted['term_id'];
					}
					continue;
				}
				$ids[] = (int) $term->term_id;
			}
			if ( ! empty( $ids ) ) {
				wp_set_object_terms( $post_id, $ids, $taxonomy, false );
			}
		}
	}

	private function safe_meta( int $post_id ): array {
		$out  = array();
		$meta = get_post_meta( $post_id );
		if ( ! is_array( $meta ) ) {
			return $out;
		}
		foreach ( $meta as $key => $values ) {
			if ( ! $this->meta_key_readable( (string) $key ) ) {
				continue;
			}
			$out[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return $out;
	}

	private function meta_key_readable( string $key ): bool {
		if ( strpos( $key, Constants::POSTMETA_DENY_PREFIX ) === 0 ) {
			return in_array( $key, Constants::POSTMETA_ALLOWLIST, true ) || $key === '_thumbnail_id';
		}
		return true;
	}

	private function terms_for( WP_Post $post ): array {
		$out        = array();
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $tax ) {
			$terms = wp_get_post_terms( $post->ID, $tax );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$out[ $tax ] = array_map(
				static fn( $term ) => array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				$terms
			);
		}
		return $out;
	}

	private function prepare_content( $content ): string {
		if ( is_array( $content ) ) {
			return $this->codec->encode( $content );
		}
		return (string) $content;
	}

	private function validate_status( string $status ): ?string {
		$allowed = array( 'draft', 'pending', 'publish', 'private', 'future' );
		return in_array( $status, $allowed, true ) ? $status : null;
	}

	private function normalize_status( $status ): array|string {
		if ( $status === 'any' ) {
			return 'any';
		}
		if ( is_array( $status ) ) {
			return array_filter( array_map( array( $this, 'validate_status' ), array_map( 'strval', $status ) ) );
		}
		$validated = $this->validate_status( (string) $status );
		return $validated !== null ? $validated : 'any';
	}

	private function normalize_orderby( string $orderby ): string {
		$allowed = array( 'date', 'title', 'modified', 'menu_order' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'date';
	}
}
