<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Auth;

use WODO_Bridge\Auth\Token_Service;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Auth\Token_Service
 */
final class Token_ServiceTest extends TestCase {

	private Token_Service $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new Token_Service();
	}

	public function test_upsert_inserts_new_token_row(): void {
		$user_id = self::factory()->user->create();
		$uuid    = wp_generate_uuid4();

		$id = $this->service->upsert(
			array(
				'app_password_uuid' => $uuid,
				'wp_user_id'        => $user_id,
				'scopes_json'       => wp_json_encode( array( 'posts.read' ) ),
				'label'             => 'unit-test',
				'aggregator_origin' => 'https://agg.example',
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = $this->service->lookup_by_uuid( $uuid );
		$this->assertNotNull( $row );
		$this->assertSame( $user_id, $row['wp_user_id'] );
		$this->assertSame( array( 'posts.read' ), $row['scopes'] );
	}

	public function test_upsert_updates_existing_row_keeps_same_id(): void {
		$user_id = self::factory()->user->create();
		$uuid    = wp_generate_uuid4();

		$first_id = $this->service->upsert(
			array(
				'app_password_uuid' => $uuid,
				'wp_user_id'        => $user_id,
				'scopes_json'       => wp_json_encode( array( 'posts.read' ) ),
				'label'             => 'first',
				'aggregator_origin' => '',
			)
		);

		$second_id = $this->service->upsert(
			array(
				'app_password_uuid' => $uuid,
				'wp_user_id'        => $user_id,
				'scopes_json'       => wp_json_encode( array( 'posts.read', 'posts.write' ) ),
				'label'             => 'second',
				'aggregator_origin' => '',
			)
		);

		$this->assertSame( $first_id, $second_id, 'upsert should reuse the row id' );
		$row = $this->service->lookup_by_uuid( $uuid );
		$this->assertSame( array( 'posts.read', 'posts.write' ), $row['scopes'] );
		$this->assertSame( 'second', $row['label'] );
	}

	public function test_lookup_by_uuid_returns_null_for_missing(): void {
		$this->assertNull( $this->service->lookup_by_uuid( '00000000-0000-0000-0000-000000000000' ) );
	}

	public function test_revoke_marks_token_revoked(): void {
		$row = $this->create_token( self::factory()->user->create(), array( 'posts.read' ) );

		$this->assertNull( $row['revoked_at'] );
		$this->assertTrue( $this->service->revoke( $row['id'] ) );

		$after = $this->service->lookup_by_uuid( $row['app_password_uuid'] );
		$this->assertNotNull( $after['revoked_at'] );
	}

	public function test_revoke_by_uuid_marks_revoked(): void {
		$row = $this->create_token( self::factory()->user->create(), array( 'posts.read' ) );

		$this->assertTrue( $this->service->revoke_by_uuid( $row['app_password_uuid'] ) );

		$after = $this->service->lookup_by_uuid( $row['app_password_uuid'] );
		$this->assertNotNull( $after['revoked_at'] );
	}

	public function test_list_for_user_excludes_revoked_by_default(): void {
		$user_id = self::factory()->user->create();
		$alive   = $this->create_token( $user_id, array( 'posts.read' ) );
		$dead    = $this->create_token( $user_id, array( 'posts.write' ) );
		$this->service->revoke( $dead['id'] );

		$rows = $this->service->list_for_user( $user_id, false );
		$ids  = wp_list_pluck( $rows, 'id' );

		$this->assertContains( $alive['id'], $ids );
		$this->assertNotContains( $dead['id'], $ids );
	}

	public function test_list_for_user_can_include_revoked(): void {
		$user_id = self::factory()->user->create();
		$alive   = $this->create_token( $user_id, array( 'posts.read' ) );
		$dead    = $this->create_token( $user_id, array( 'posts.write' ) );
		$this->service->revoke( $dead['id'] );

		$rows = $this->service->list_for_user( $user_id, true );
		$ids  = wp_list_pluck( $rows, 'id' );

		$this->assertContains( $alive['id'], $ids );
		$this->assertContains( $dead['id'], $ids );
	}

	public function test_bind_scopes_replaces_full_scope_set(): void {
		$row = $this->create_token( self::factory()->user->create(), array( 'posts.read' ) );

		$ok = $this->service->bind_scopes( $row['id'], array( 'posts.write', 'media.read' ), 'https://new.example' );
		$this->assertTrue( $ok );

		$after = $this->service->lookup_by_uuid( $row['app_password_uuid'] );
		$this->assertSame( array( 'posts.write', 'media.read' ), $after['scopes'] );
		$this->assertSame( 'https://new.example', $after['aggregator_origin'] );
	}

	public function test_sanitize_scopes_drops_unknown_values(): void {
		$clean = $this->service->sanitize_scopes( array( 'posts.read', 'foo.bar', '', 123, 'admin.full' ) );

		$this->assertSame( array( 'posts.read', 'admin.full' ), $clean );
	}

	public function test_sanitize_scopes_dedupes(): void {
		$clean = $this->service->sanitize_scopes( array( 'posts.read', 'posts.read' ) );
		$this->assertSame( array( 'posts.read' ), $clean );
	}

	public function test_record_use_updates_last_used_fields(): void {
		$row = $this->create_token( self::factory()->user->create(), array( 'posts.read' ) );
		$this->assertNull( $row['last_used_at'] );

		$this->service->record_use( $row['id'], '203.0.113.7' );
		$after = $this->service->lookup_by_id( $row['id'] );

		$this->assertNotNull( $after['last_used_at'] );
		$this->assertSame( '203.0.113.7', $after['last_used_ip'] );
	}

	public function test_application_password_create_hook_links_token(): void {
		$user_id = self::factory()->user->create();
		$uuid    = wp_generate_uuid4();

		$this->service->on_application_password_created(
			$user_id,
			array(
				'uuid'   => $uuid,
				'name'   => 'agg-init',
				'app_id' => 'wodo:scope=posts.read,posts.write',
			),
			'plain-pass-here',
			array()
		);

		$row = $this->service->lookup_by_uuid( $uuid );
		$this->assertNotNull( $row );
		$this->assertSame( $user_id, $row['wp_user_id'] );
		$this->assertSame( array( 'posts.read', 'posts.write' ), $row['scopes'] );
	}

	public function test_application_password_delete_hook_revokes_row(): void {
		$user_id = self::factory()->user->create();
		$row     = $this->create_token( $user_id, array( 'posts.read' ) );

		$this->service->on_application_password_deleted(
			$user_id,
			array( 'uuid' => $row['app_password_uuid'] )
		);

		$after = $this->service->lookup_by_uuid( $row['app_password_uuid'] );
		$this->assertNotNull( $after['revoked_at'] );
	}
}
