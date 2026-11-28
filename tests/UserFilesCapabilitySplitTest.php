<?php
/**
 * A7 capability split: management endpoints use their own capabilities.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Admin\Capability_Matrix;
use Nboke\UserFiles\Admin\Files_Admin_Payload;
use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Rest\Controller;
use PHPUnit\Framework\TestCase;

/**
 * Pins the server side of the DELETE_FILES split: a role that can delete keeps
 * only the deletion batch routes, and each new capability opens just its group.
 */
final class UserFilesCapabilitySplitTest extends TestCase {

	/** @var Controller */
	private $controller;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_rest_routes'] = array();
		$GLOBALS['sakura_test_role_caps']   = array();
		$GLOBALS['sakura_test_options']     = array();

		$this->controller = ( new ReflectionClass( Controller::class ) )->newInstanceWithoutConstructor();
		$this->controller->register();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['sakura_test_rest_routes'],
			$GLOBALS['sakura_test_role_caps'],
			$GLOBALS['sakura_test_options'],
			$GLOBALS['sakura_test_can']
		);

		parent::tearDown();
	}

	/** Every capability false, so a fixture grants only what it names. */
	private function denied_caps(): array {
		return array(
			'manage_options'             => false,
			Capabilities::MANAGE_OWN     => false,
			Capabilities::REQUEST_DELETE => false,
			Capabilities::REVIEW         => false,
			Capabilities::DELETE_FILES   => false,
			Capabilities::MANAGE_USERS   => false,
			Capabilities::VIEW_AUDIT     => false,
			Capabilities::MANAGE_SYNC    => false,
			Capabilities::MANAGE_MEDIA   => false,
			Capabilities::TRANSFER       => false,
			Capabilities::SANCTION_USERS => false,
		);
	}

	private function allow_only( string $cap ): void {
		$GLOBALS['sakura_test_can'] = array_merge( $this->denied_caps(), array( $cap => true ) );
	}

	private function route_callback( string $route ): string {
		$args     = $GLOBALS['sakura_test_rest_routes'][ Controller::NS . $route ] ?? array();
		$callback = $args['permission_callback'] ?? null;

		return is_array( $callback ) && isset( $callback[1] ) && is_string( $callback[1] )
			? $callback[1]
			: '';
	}

	private function route_allows( string $route ): bool {
		$args     = $GLOBALS['sakura_test_rest_routes'][ Controller::NS . $route ] ?? array();
		$callback = $args['permission_callback'] ?? null;

		return is_callable( $callback ) && (bool) call_user_func( $callback );
	}

	public function test_management_routes_use_their_own_capability_callbacks(): void {
		$expected = array(
			'/admin/users'                           => 'can_manage_users',
			'/admin/users/(?P<id>\d+)/quota'         => 'can_manage_users',
			'/admin/reset-quotas'                    => 'can_manage_users',
			'/admin/events'                          => 'can_view_audit',
			'/admin/reconcile'                       => 'can_manage_sync',
			'/admin/sync'                            => 'can_manage_sync',
			'/admin/delete-batches/preview'          => 'can_execute',
			'/admin/delete-batches'                  => 'can_execute',
			'/admin/delete-batches/(?P<id>\d+)'      => 'can_execute',
			'/admin/delete-batches/(?P<id>\d+)/stop' => 'can_execute',
			'/admin/delete-requests'                 => 'can_review',
			'/admin/users/(?P<id>\d+)/sanction'      => 'can_sanction_users',
		);

		foreach ( $expected as $route => $callback ) {
			$this->assertSame( $callback, $this->route_callback( $route ), 'Unexpected gate on ' . $route );
		}
	}

	public function test_delete_files_only_denies_users_quota_audit_and_sync(): void {
		$this->allow_only( Capabilities::DELETE_FILES );

		$this->assertTrue( $this->controller->can_execute() );
		$this->assertFalse( $this->controller->can_manage_users() );
		$this->assertFalse( $this->controller->can_view_audit() );
		$this->assertFalse( $this->controller->can_manage_sync() );

		$closed = array(
			'/admin/users',
			'/admin/users/(?P<id>\d+)/quota',
			'/admin/reset-quotas',
			'/admin/events',
			'/admin/reconcile',
			'/admin/sync',
		);

		foreach ( $closed as $route ) {
			$this->assertFalse( $this->route_allows( $route ), $route . ' must stay closed to DELETE_FILES alone' );
		}
	}

	public function test_manage_users_opens_only_the_user_routes(): void {
		$this->allow_only( Capabilities::MANAGE_USERS );

		foreach ( array( '/admin/users', '/admin/users/(?P<id>\d+)/quota', '/admin/reset-quotas' ) as $route ) {
			$this->assertTrue( $this->route_allows( $route ), $route );
		}

		foreach ( array( '/admin/events', '/admin/reconcile', '/admin/sync', '/admin/delete-batches', '/admin/delete-requests' ) as $route ) {
			$this->assertFalse( $this->route_allows( $route ), $route );
		}

		$this->assertFalse( $this->controller->can_execute() );
		$this->assertFalse( $this->controller->can_view_audit() );
		$this->assertFalse( $this->controller->can_manage_sync() );
	}

	public function test_view_audit_opens_only_the_events_route(): void {
		$this->allow_only( Capabilities::VIEW_AUDIT );

		$this->assertTrue( $this->route_allows( '/admin/events' ) );

		foreach ( array( '/admin/users', '/admin/users/(?P<id>\d+)/quota', '/admin/reset-quotas', '/admin/reconcile', '/admin/sync', '/admin/delete-batches', '/admin/delete-requests' ) as $route ) {
			$this->assertFalse( $this->route_allows( $route ), $route );
		}

		$this->assertFalse( $this->controller->can_execute() );
		$this->assertFalse( $this->controller->can_manage_users() );
		$this->assertFalse( $this->controller->can_manage_sync() );
	}

	public function test_manage_sync_opens_only_sync_and_reconcile(): void {
		$this->allow_only( Capabilities::MANAGE_SYNC );

		$this->assertTrue( $this->route_allows( '/admin/sync' ) );
		$this->assertTrue( $this->route_allows( '/admin/reconcile' ) );

		foreach ( array( '/admin/users', '/admin/users/(?P<id>\d+)/quota', '/admin/reset-quotas', '/admin/events', '/admin/delete-batches', '/admin/delete-requests' ) as $route ) {
			$this->assertFalse( $this->route_allows( $route ), $route );
		}

		$this->assertFalse( $this->controller->can_execute() );
		$this->assertFalse( $this->controller->can_manage_users() );
		$this->assertFalse( $this->controller->can_view_audit() );
	}

	public function test_delete_files_still_opens_every_batch_route(): void {
		$this->allow_only( Capabilities::DELETE_FILES );

		foreach ( array( '/admin/delete-batches/preview', '/admin/delete-batches', '/admin/delete-batches/(?P<id>\d+)', '/admin/delete-batches/(?P<id>\d+)/stop' ) as $route ) {
			$this->assertTrue( $this->route_allows( $route ), $route );
		}
	}

	public function test_review_and_sanction_gates_are_unchanged(): void {
		$this->allow_only( Capabilities::REVIEW );
		$this->assertTrue( $this->route_allows( '/admin/delete-requests' ) );
		$this->assertFalse( $this->route_allows( '/admin/delete-batches' ) );
		$this->assertFalse( $this->route_allows( '/admin/users/(?P<id>\d+)/sanction' ) );

		$this->allow_only( Capabilities::SANCTION_USERS );
		$this->assertTrue( $this->route_allows( '/admin/users/(?P<id>\d+)/sanction' ) );
		$this->assertFalse( $this->route_allows( '/admin/delete-requests' ) );
		$this->assertFalse( $this->route_allows( '/admin/users' ) );
	}

	public function test_administrator_defaults_include_the_split_capabilities(): void {
		$this->assertContains( Capabilities::MANAGE_USERS, Capabilities::admin_caps() );
		$this->assertContains( Capabilities::VIEW_AUDIT, Capabilities::admin_caps() );
		$this->assertContains( Capabilities::MANAGE_SYNC, Capabilities::admin_caps() );
		$this->assertNotContains( Capabilities::MANAGE_USERS, Capabilities::user_caps() );
		$this->assertNotContains( Capabilities::VIEW_AUDIT, Capabilities::user_caps() );
		$this->assertNotContains( Capabilities::MANAGE_SYNC, Capabilities::user_caps() );
	}

	public function test_grant_defaults_seeds_the_split_capabilities_only_for_administrators(): void {
		// The test double mirrors add_cap() onto every seeded role, so each
		// scenario seeds one role only; production targets the named role.
		$GLOBALS['sakura_test_role_caps'] = array( 'administrator' => array() );

		Capabilities::grant_defaults();

		$admin = $GLOBALS['sakura_test_role_caps']['administrator'];

		foreach ( Capabilities::admin_caps() as $cap ) {
			$this->assertTrue( ! empty( $admin[ $cap ] ), $cap . ' should be seeded on administrator' );
		}

		$this->assertTrue( ! empty( $GLOBALS['sakura_test_options'][ Capabilities::SEED_OPTION ] ) );

		$GLOBALS['sakura_test_options']     = array();
		$GLOBALS['sakura_test_role_caps'] = array( 'editor' => array() );

		Capabilities::grant_defaults();

		foreach ( array( Capabilities::MANAGE_USERS, Capabilities::VIEW_AUDIT, Capabilities::MANAGE_SYNC ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $GLOBALS['sakura_test_role_caps']['editor'], $cap . ' is not a role default' );
		}

		foreach ( Capabilities::user_caps() as $cap ) {
			$this->assertArrayHasKey( $cap, $GLOBALS['sakura_test_role_caps']['editor'] );
		}
	}

	public function test_revoke_removes_the_split_capabilities_and_clears_the_option(): void {
		$GLOBALS['sakura_test_role_caps'] = array(
			'administrator' => array(
				Capabilities::MANAGE_USERS => true,
				Capabilities::VIEW_AUDIT   => true,
				Capabilities::MANAGE_SYNC  => true,
				Capabilities::DELETE_FILES => true,
			),
			'editor'        => array(
				Capabilities::MANAGE_USERS => true,
				Capabilities::DELETE_FILES => true,
			),
		);
		$GLOBALS['sakura_test_options'][ Capabilities::CAP_SPLIT_OPTION ] = '1';

		Capabilities::revoke();

		foreach ( $GLOBALS['sakura_test_role_caps'] as $role => $caps ) {
			foreach ( array( Capabilities::MANAGE_USERS, Capabilities::VIEW_AUDIT, Capabilities::MANAGE_SYNC, Capabilities::DELETE_FILES ) as $cap ) {
				$this->assertArrayNotHasKey( $cap, $caps, $role . ' still has ' . $cap );
			}
		}

		$this->assertArrayNotHasKey( Capabilities::CAP_SPLIT_OPTION, $GLOBALS['sakura_test_options'] );
	}

	public function test_upgrade_grants_the_split_capabilities_once_to_administrators_only(): void {
		$GLOBALS['sakura_test_role_caps'] = array( 'administrator' => array( 'manage_options' => true ) );

		Capabilities::upgrade_capability_split();

		$admin = $GLOBALS['sakura_test_role_caps']['administrator'];

		foreach ( array( Capabilities::MANAGE_USERS, Capabilities::VIEW_AUDIT, Capabilities::MANAGE_SYNC ) as $cap ) {
			$this->assertTrue( ! empty( $admin[ $cap ] ), $cap . ' should reach the administrator' );
		}

		$this->assertSame( '1', (string) $GLOBALS['sakura_test_options'][ Capabilities::CAP_SPLIT_OPTION ] );

		get_role( 'administrator' )->remove_cap( Capabilities::MANAGE_USERS );
		Capabilities::upgrade_capability_split();

		$this->assertArrayNotHasKey( Capabilities::MANAGE_USERS, $GLOBALS['sakura_test_role_caps']['administrator'] );

		// A role that only has DELETE_FILES is never a source of the new caps.
		$GLOBALS['sakura_test_options']   = array();
		$GLOBALS['sakura_test_role_caps'] = array( 'editor' => array( Capabilities::DELETE_FILES => true ) );

		Capabilities::upgrade_capability_split();

		foreach ( array( Capabilities::MANAGE_USERS, Capabilities::VIEW_AUDIT, Capabilities::MANAGE_SYNC ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $GLOBALS['sakura_test_role_caps']['editor'], $cap . ' must not follow DELETE_FILES' );
		}
	}

	public function test_matrix_roundtrip_preserves_existing_caps_and_changes_only_the_delta(): void {
		$GLOBALS['sakura_test_role_caps'] = array(
			'administrator' => array_merge(
				array( 'manage_options' => true ),
				array_fill_keys( Capabilities::admin_caps(), true ),
				array_fill_keys( Capabilities::user_caps(), true )
			),
			'editor'        => array(
				Capabilities::MANAGE_OWN     => true,
				Capabilities::REQUEST_DELETE => true,
				Capabilities::REVIEW         => true,
				Capabilities::DELETE_FILES   => true,
			),
		);

		$posted = array();

		foreach ( Capability_Matrix::roles() as $role ) {
			$posted[ $role ] = array();

			foreach ( array_keys( Capability_Matrix::caps() ) as $cap ) {
				$posted[ $role ][ $cap ] = Capability_Matrix::configured( (string) $role, (string) $cap ) ? '1' : '0';
			}
		}

		$posted['editor'][ Capabilities::MANAGE_USERS ] = '1';

		$db = new Sakura_Test_Wpdb();
		$db->install();

		try {
			$diff = Capability_Matrix::apply( $posted );
		} finally {
			$db->restore();
		}

		$this->assertSame( array( 'editor:+' . Capabilities::MANAGE_USERS ), $diff );
		$this->assertTrue( Capability_Matrix::configured( 'editor', Capabilities::MANAGE_USERS ) );
		$this->assertTrue( Capability_Matrix::configured( 'editor', Capabilities::REVIEW ) );
		$this->assertTrue( Capability_Matrix::configured( 'editor', Capabilities::DELETE_FILES ) );
		$this->assertTrue( Capability_Matrix::configured( 'administrator', Capabilities::MANAGE_SYNC ) );
		$this->assertTrue( Capability_Matrix::configured( 'administrator', Capabilities::VIEW_AUDIT ) );
		$this->assertTrue( Capability_Matrix::configured( 'administrator', Capabilities::DELETE_FILES ) );
		$this->assertSame( '1', (string) $GLOBALS['sakura_test_options'][ Capabilities::SEED_OPTION ] );
	}

	public function test_delete_files_cell_does_not_imply_management_capabilities(): void {
		$posted = array( 'editor' => array( Capabilities::DELETE_FILES => '1' ) );

		foreach ( array( Capabilities::MANAGE_USERS, Capabilities::VIEW_AUDIT, Capabilities::MANAGE_SYNC ) as $cap ) {
			$this->assertFalse( Capability_Matrix::desired( 'editor', $cap, $posted ), $cap . ' must not follow DELETE_FILES' );
		}
	}

	public function test_matrix_payload_carries_the_ten_columns_and_cells(): void {
		$GLOBALS['sakura_test_role_caps'] = array(
			'administrator' => array(),
			'editor'        => array(),
		);

		$payload = ( new Files_Admin_Payload() )->caps_matrix();

		$this->assertCount( 10, $payload['columns'] );
		$this->assertSame( array_keys( Capability_Matrix::caps() ), array_column( $payload['columns'], 'key' ) );
		$this->assertStringContainsString( '管理用户容量', (string) $payload['legend'] );

		foreach ( $payload['roles'] as $role ) {
			$this->assertCount( 10, $role['cells'] );
			$this->assertArrayHasKey( 'stored', $role['cells'][4] );
		}
	}
}
