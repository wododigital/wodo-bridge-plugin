<?php

declare( strict_types=1 );

namespace WODO_Bridge\Content;

use WP_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cpt_Service {

	public function discover( bool $public_only = true ): array {
		$args  = $public_only ? array( 'public' => true ) : array();
		$types = get_post_types( $args, 'objects' );
		$out   = array();

		foreach ( $types as $type ) {
			if ( ! $type instanceof WP_Post_Type ) {
				continue;
			}
			if ( $public_only && empty( $type->show_in_rest ) ) {
				continue;
			}
			$out[] = $this->describe( $type );
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( (string) $a['slug'], (string) $b['slug'] )
		);

		return $out;
	}

	public function get( string $slug ): ?WP_Post_Type {
		$type = get_post_type_object( $slug );
		return $type instanceof WP_Post_Type ? $type : null;
	}

	public function describe( WP_Post_Type $type ): array {
		$count = 0;
		$counts = wp_count_posts( $type->name );
		if ( is_object( $counts ) ) {
			foreach ( $counts as $value ) {
				$count += (int) $value;
			}
		}

		return array(
			'slug'         => (string) $type->name,
			'label'        => (string) $type->label,
			'public'       => (bool) $type->public,
			'hierarchical' => (bool) $type->hierarchical,
			'supports'     => array_keys( get_all_post_type_supports( $type->name ) ),
			'taxonomies'   => get_object_taxonomies( $type->name ),
			'rest_base'    => $type->rest_base !== false ? (string) $type->rest_base : (string) $type->name,
			'rest_namespace' => isset( $type->rest_namespace ) ? (string) $type->rest_namespace : 'wp/v2',
			'count'        => $count,
		);
	}

	public function user_can_edit( string $type, int $user_id ): bool {
		$object = $this->get( $type );
		if ( $object === null ) {
			return false;
		}
		return user_can( $user_id, $object->cap->edit_posts );
	}

	public function user_can_publish( string $type, int $user_id ): bool {
		$object = $this->get( $type );
		if ( $object === null ) {
			return false;
		}
		return user_can( $user_id, $object->cap->publish_posts );
	}

	public function user_can_delete( string $type, int $user_id, int $post_id ): bool {
		$object = $this->get( $type );
		if ( $object === null ) {
			return false;
		}
		return user_can( $user_id, $object->cap->delete_post, $post_id );
	}
}
