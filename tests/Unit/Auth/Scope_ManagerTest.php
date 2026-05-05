<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Auth;

use WODO_Bridge\Auth\Scope_Manager;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Auth\Scope_Manager
 */
final class Scope_ManagerTest extends TestCase {

	private Scope_Manager $scopes;

	public function set_up(): void {
		parent::set_up();
		$this->scopes = new Scope_Manager();
	}

	public function test_catalog_returns_all_known_scopes(): void {
		$catalog = $this->scopes->catalog();

		$this->assertContains( 'posts.read', $catalog );
		$this->assertContains( 'posts.write', $catalog );
		$this->assertContains( 'admin.full', $catalog );
		$this->assertCount( 12, $catalog, 'spec §7 lists 12 scopes' );
	}

	public function test_token_with_admin_full_satisfies_any_scope(): void {
		$this->assertTrue( $this->scopes->token_has_scope( array( 'admin.full' ), 'posts.write' ) );
		$this->assertTrue( $this->scopes->token_has_scope( array( 'admin.full' ), 'webhooks.manage' ) );
		$this->assertTrue( $this->scopes->token_has_scope( array( 'admin.full' ), 'elementor.write' ) );
	}

	public function test_token_must_have_explicit_scope_otherwise(): void {
		$this->assertTrue( $this->scopes->token_has_scope( array( 'posts.read' ), 'posts.read' ) );
		$this->assertFalse( $this->scopes->token_has_scope( array( 'posts.read' ), 'posts.write' ) );
	}

	public function test_empty_scope_set_grants_nothing(): void {
		$this->assertFalse( $this->scopes->token_has_scope( array(), 'posts.read' ) );
	}

	public function test_unknown_scope_in_token_does_not_grant_unrelated_scope(): void {
		$this->assertFalse(
			$this->scopes->token_has_scope( array( 'something.invented' ), 'posts.read' )
		);
	}

	public function test_capabilities_for_posts_read_includes_read_posts(): void {
		$caps = $this->scopes->capabilities_for( array( 'posts.read' ) );

		$this->assertContains( 'read_posts', $caps );
		$this->assertNotContains( 'edit_posts', $caps );
	}

	public function test_capabilities_for_posts_write_includes_edit_and_delete(): void {
		$caps = $this->scopes->capabilities_for( array( 'posts.write' ) );

		$this->assertContains( 'edit_posts', $caps );
		$this->assertContains( 'delete_posts', $caps );
	}

	public function test_capabilities_for_admin_full_implies_every_capability(): void {
		$caps = $this->scopes->capabilities_for( array( 'admin.full' ) );

		$this->assertContains( 'read_posts', $caps );
		$this->assertContains( 'edit_posts', $caps );
		$this->assertContains( 'manage_terms', $caps );
		$this->assertContains( 'edit_elementor', $caps );
		$this->assertContains( 'manage_webhooks', $caps );
	}

	public function test_capabilities_for_combined_scopes_are_unique(): void {
		$caps = $this->scopes->capabilities_for( array( 'posts.read', 'posts.read', 'posts.write' ) );

		$this->assertSame( count( $caps ), count( array_unique( $caps ) ), 'duplicates removed' );
	}

	public function test_capabilities_empty_when_no_scopes(): void {
		$this->assertSame( array(), $this->scopes->capabilities_for( array() ) );
	}

	public function test_media_svg_capability_not_implied_by_media_write(): void {
		$caps = $this->scopes->capabilities_for( array( 'media.write' ) );
		$this->assertContains( 'upload_media', $caps );
		// SVG is a separate explicit scope; media.write must not imply SVG upload.
		$this->assertNotContains( 'upload_svg', $caps );
	}

	public function test_taxonomy_write_grants_manage_terms(): void {
		$caps = $this->scopes->capabilities_for( array( 'taxonomy.write' ) );
		$this->assertContains( 'manage_terms', $caps );
	}

	public function test_webhooks_manage_only_grants_manage_webhooks(): void {
		$caps = $this->scopes->capabilities_for( array( 'webhooks.manage' ) );
		$this->assertSame( array( 'manage_webhooks' ), $caps );
	}
}
