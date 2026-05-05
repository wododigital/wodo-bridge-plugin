<?php

declare( strict_types=1 );

namespace WODO_Bridge\Content;

use WP_Error;
use WP_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Taxonomy_Service {

	public function list(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$out        = array();
		foreach ( $taxonomies as $tax ) {
			if ( ! $tax instanceof WP_Taxonomy ) {
				continue;
			}
			$out[] = array(
				'slug'         => (string) $tax->name,
				'label'        => (string) $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_types' => array_values( $tax->object_type ),
				'rest_base'    => $tax->rest_base !== false ? (string) $tax->rest_base : (string) $tax->name,
				'count'        => (int) wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) ),
			);
		}
		return $out;
	}

	public function list_terms( string $taxonomy, array $args = array() ): array|WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'not_found', __( 'Unknown taxonomy.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		$query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => isset( $args['per_page'] ) ? min( 200, max( 1, (int) $args['per_page'] ) ) : 50,
		);
		if ( isset( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( isset( $args['parent'] ) ) {
			$query['parent'] = (int) $args['parent'];
		}

		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = $this->summary( $term );
		}

		return array(
			'total' => count( $items ),
			'items' => $items,
		);
	}

	public function create_term( string $taxonomy, array $payload ): array|WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'not_found', __( 'Unknown taxonomy.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		if ( empty( $payload['name'] ) || ! is_string( $payload['name'] ) ) {
			return new WP_Error( 'validation_failed', __( 'Term name is required.', 'wodo-bridge' ), array( 'status' => 422 ) );
		}

		$args = array();
		if ( ! empty( $payload['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $payload['slug'] );
		}
		if ( isset( $payload['parent'] ) ) {
			$args['parent'] = (int) $payload['parent'];
		}
		if ( isset( $payload['description'] ) ) {
			$args['description'] = wp_kses_post( (string) $payload['description'] );
		}

		$created = wp_insert_term( sanitize_text_field( $payload['name'] ), $taxonomy, $args );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			foreach ( $payload['meta'] as $key => $value ) {
				$key = (string) $key;
				if ( $key === '' || strpos( $key, '_' ) === 0 ) {
					continue;
				}
				update_term_meta( (int) $created['term_id'], $key, is_array( $value ) ? $value : sanitize_text_field( (string) $value ) );
			}
		}

		$term = get_term( (int) $created['term_id'], $taxonomy );
		return $this->summary( $term );
	}

	public function update_term( string $taxonomy, int $term_id, array $payload ): array|WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'not_found', __( 'Unknown taxonomy.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		$args = array();
		if ( isset( $payload['name'] ) ) {
			$args['name'] = sanitize_text_field( (string) $payload['name'] );
		}
		if ( isset( $payload['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $payload['slug'] );
		}
		if ( isset( $payload['parent'] ) ) {
			$args['parent'] = (int) $payload['parent'];
		}
		if ( isset( $payload['description'] ) ) {
			$args['description'] = wp_kses_post( (string) $payload['description'] );
		}

		$updated = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$term = get_term( $term_id, $taxonomy );
		return $this->summary( $term );
	}

	public function delete_term( string $taxonomy, int $term_id ): array|WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'not_found', __( 'Unknown taxonomy.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result === false || $result === 0 ) {
			return new WP_Error( 'not_found', __( 'Term not found.', 'wodo-bridge' ), array( 'status' => 404 ) );
		}
		return array(
			'deleted' => true,
			'id'      => $term_id,
		);
	}

	private function summary( $term ): array {
		if ( ! is_object( $term ) ) {
			return array();
		}
		return array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'parent'      => (int) ( $term->parent ?? 0 ),
			'count'       => (int) ( $term->count ?? 0 ),
			'taxonomy'    => (string) $term->taxonomy,
			'description' => (string) $term->description,
		);
	}
}
