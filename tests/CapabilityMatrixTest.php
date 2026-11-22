<?php
/**
 * Tests for the capability matrix card's pure decisions.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests {
	use Nboke\UserFiles\Admin\Capability_Matrix;
	use Nboke\UserFiles\Capabilities;
	use PHPUnit\Framework\TestCase;

	/**
	 * The matrix edits only the plugin's own six capabilities, pins the
	 * administrator's direct delete and marks roles whose reach is limited to
	 * their own attachments.
	 */
	final class CapabilityMatrixTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['sakura_test_role_caps'] = array(
				'administrator' => array( 'manage_options' => true ),
				'editor'        => array(),
				'author'        => array(),
				'contributor'   => array(),
				'subscriber'    => array(),
			);
		}

		protected function tearDown(): void {
			unset( $GLOBALS['sakura_test_role_caps'] );

			parent::tearDown();
		}

		public function test_caps_are_exactly_the_seven_plugin_capabilities(): void {
			$this->assertSame(
				array(
					Capabilities::MANAGE_OWN,
					Capabilities::REQUEST_DELETE,
					Capabilities::REVIEW,
					Capabilities::DELETE_FILES,
					Capabilities::MANAGE_MEDIA,
					Capabilities::TRANSFER,
					Capabilities::SANCTION_USERS,
				),
				array_keys( Capability_Matrix::caps() )
			);
		}

		public function test_every_capability_has_a_label_and_hint(): void {
			foreach ( Capability_Matrix::caps() as $cap => $meta ) {
				$this->assertNotSame( '', (string) $meta['abbr'], $cap );
				$this->assertNotSame( '', (string) $meta['label'], $cap );
				$this->assertNotSame( '', (string) $meta['hint'], $cap );
			}
		}

		public function test_only_the_administrator_direct_delete_is_locked(): void {
			$this->assertTrue( Capability_Matrix::locked( 'administrator', Capabilities::DELETE_FILES ) );
			$this->assertFalse( Capability_Matrix::locked( 'administrator', Capabilities::REVIEW ) );
			$this->assertFalse( Capability_Matrix::locked( 'editor', Capabilities::DELETE_FILES ) );
			$this->assertFalse( Capability_Matrix::locked( 'author', Capabilities::DELETE_FILES ) );
		}

		public function test_a_locked_cell_cannot_be_turned_off_by_the_form(): void {
			$posted = array( 'administrator' => array( Capabilities::DELETE_FILES => '0' ) );

			$this->assertTrue( Capability_Matrix::desired( 'administrator', Capabilities::DELETE_FILES, $posted ) );
		}

		public function test_a_normal_cell_follows_the_form(): void {
			$posted = array(
				'author' => array(
					Capabilities::MANAGE_OWN     => '1',
					Capabilities::REQUEST_DELETE => '1',
				),
			);

			$this->assertTrue( Capability_Matrix::desired( 'author', Capabilities::MANAGE_OWN, $posted ) );
			$this->assertFalse( Capability_Matrix::desired( 'author', Capabilities::DELETE_FILES, $posted ) );
		}

		public function test_a_malformed_row_denies_everything(): void {
			$posted = array( 'author' => 'yes' );

			$this->assertFalse( Capability_Matrix::desired( 'author', Capabilities::MANAGE_OWN, $posted ) );
			$this->assertFalse( Capability_Matrix::desired( 'ghost', Capabilities::MANAGE_OWN, $posted ) );
		}

		public function test_direct_delete_without_delete_others_is_limited_to_own_files(): void {
			$GLOBALS['sakura_test_role_caps']['editor'] = array(
				Capabilities::DELETE_FILES => true,
			);

			$this->assertTrue( Capability_Matrix::limited( 'editor' ) );

			$GLOBALS['sakura_test_role_caps']['editor'] = array(
				Capabilities::DELETE_FILES   => true,
				'delete_others_posts'        => true,
			);

			$this->assertFalse( Capability_Matrix::limited( 'editor' ) );
		}

		public function test_a_role_without_direct_delete_is_not_marked_limited(): void {
			$GLOBALS['sakura_test_role_caps']['author'] = array( Capabilities::MANAGE_OWN => true );

			$this->assertFalse( Capability_Matrix::limited( 'author' ) );
		}

		public function test_configured_reads_the_stored_map(): void {
			$GLOBALS['sakura_test_role_caps']['author'] = array( Capabilities::REQUEST_DELETE => true );

			$this->assertTrue( Capability_Matrix::configured( 'author', Capabilities::REQUEST_DELETE ) );
			$this->assertFalse( Capability_Matrix::configured( 'author', Capabilities::DELETE_FILES ) );
			$this->assertFalse( Capability_Matrix::configured( 'ghost', Capabilities::DELETE_FILES ) );
		}

		public function test_roles_put_the_builtins_first_and_custom_roles_last(): void {
			$GLOBALS['sakura_test_role_caps'] = array(
				'shop_manager'  => array(),
				'subscriber'    => array(),
				'administrator' => array(),
				'editor'        => array(),
			);

			$this->assertSame(
				array( 'administrator', 'editor', 'subscriber', 'shop_manager' ),
				Capability_Matrix::roles()
			);
		}

		public function test_role_label_maps_the_built_in_roles_to_chinese(): void {
			$this->assertSame( '管理员', Capability_Matrix::role_label( 'administrator' ) );
			$this->assertSame( '编辑', Capability_Matrix::role_label( 'editor' ) );
			$this->assertSame( '作者', Capability_Matrix::role_label( 'author' ) );
			$this->assertSame( '投稿者', Capability_Matrix::role_label( 'contributor' ) );
			$this->assertSame( '订阅者', Capability_Matrix::role_label( 'subscriber' ) );
		}

		public function test_role_names_cover_exactly_the_five_built_in_roles(): void {
			$this->assertSame(
				array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
				array_keys( Capability_Matrix::role_names() )
			);
		}

		public function test_role_label_falls_back_to_the_slug_for_custom_roles(): void {
			$this->assertSame( 'shop-manager', Capability_Matrix::role_label( 'shop-manager' ) );
		}
	}
}
