<?php
/**
 * Route manifest for the persistent admin shell.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Registry;

require_once dirname( __DIR__, 2 ) . '/src/Contracts/Settings_Repository_Interface.php';
require_once dirname( __DIR__, 2 ) . '/src/Settings/Settings_Repository.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Route.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Registry.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/App_Manifest.php';

final class AppManifestTest extends TestCase {

	/**
	 * Reset the request and the capability shim between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_can'] = true;
		$_GET                       = array();
	}

	/**
	 * Clean up the same globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['sakura_test_can'] = true;
		$_GET                       = array();

		parent::tearDown();
	}

	/**
	 * Every plugin screen is on the allowlist; there is no second render path.
	 *
	 * @return void
	 */
	public function test_the_allowlist_covers_every_plugin_screen(): void {
		self::assertSame(
			array(
				'system-settings',
				'image-rules',
				'image-thumb',
				'image-backup',
				'image-stats',
				'storage-connection',
				'storage-sync',
				'storage-deletes',
				'storage-rewrite',
				'storage-cost',
				'files-settings',
				'files-queue',
				'files-log',
				'files-zibll',
				'zibll-performance',
			),
			array_keys( App_Manifest::routes() )
		);
	}

	/**
	 * A page outside the list is never reachable.
	 *
	 * @return void
	 */
	public function test_pages_outside_the_allowlist_stay_unreachable(): void {
		self::assertTrue( App_Manifest::can_view( 'system-settings' ) );
		self::assertTrue( App_Manifest::can_view( 'image-stats' ) );
		self::assertTrue( App_Manifest::can_view( 'storage-sync' ) );
		self::assertTrue( App_Manifest::can_view( 'files-log' ) );
		self::assertFalse( App_Manifest::can_view( 'media-progress' ) );
		self::assertFalse( App_Manifest::can_view( '' ) );
	}

	/**
	 * The gate is the tab gate: a user who cannot see the tab cannot fetch it.
	 *
	 * @return void
	 */
	public function test_the_capability_of_the_tab_governs_the_route(): void {
		$GLOBALS['sakura_test_can'] = false;

		self::assertFalse( App_Manifest::can_view( 'system-settings' ) );
		self::assertFalse( App_Manifest::can_view( 'files-queue' ) );
	}

	/**
	 * Enabled means "this request is one of the routes".
	 *
	 * @return void
	 */
	public function test_enabled_follows_the_current_screen(): void {
		$_GET['page'] = 'sakura-tools';
		self::assertTrue( App_Manifest::enabled() );

		$_GET['page'] = 'sakura-tools-image';
		$_GET['t']    = 'thumb';
		self::assertTrue( App_Manifest::enabled() );

		$_GET['page'] = 'edit.php';
		unset( $_GET['t'] );
		self::assertFalse( App_Manifest::enabled() );
	}

	/**
	 * The manifest lists URLs Registry built, not paths this class invented.
	 *
	 * @return void
	 */
	public function test_the_manifest_reuses_the_registry_urls(): void {
		$_GET['page'] = 'sakura-tools';

		$manifest = App_Manifest::manifest();

		self::assertTrue( $manifest['enabled'] );
		self::assertSame( 'system-settings', $manifest['page'] );
		self::assertStringContainsString( '/pages', (string) $manifest['endpoint'] );
		self::assertNotSame( '', (string) $manifest['nonce'] );
		/*
		 * Thirteen, not fifteen: the two zibll tabs are gated on the theme, and the
		 * shim has no theme bridge, so `enabled` is false for them — which is the
		 * behaviour this asserts: a blocked tab is not fetchable.
		 */
		self::assertCount( 13, $manifest['routes'] );
		self::assertArrayNotHasKey( 'files-zibll', $manifest['routes'] );
		self::assertArrayNotHasKey( 'zibll-performance', $manifest['routes'] );
		self::assertSame( Registry::url( 'image', 'thumb' ), $manifest['routes']['image-thumb']['url'] );
		self::assertSame( 'image', $manifest['routes']['image-thumb']['module'] );
		self::assertSame( 'thumb', $manifest['routes']['image-thumb']['tab'] );
	}

	/**
	 * A user who cannot see the tabs gets an empty route list, not an error.
	 *
	 * @return void
	 */
	public function test_the_manifest_only_lists_capable_routes(): void {
		$GLOBALS['sakura_test_can'] = false;
		$_GET['page']               = 'sakura-tools';

		$manifest = App_Manifest::manifest();

		self::assertSame( array(), $manifest['routes'] );
		self::assertFalse( $manifest['enabled'] );
	}

	/**
	 * An unknown page is not a payload: the caller answers 404.
	 *
	 * @return void
	 */
	public function test_unknown_pages_have_no_payload(): void {
		self::assertNull( App_Manifest::payload( 'media-progress' ) );
		self::assertNull( App_Manifest::payload( '' ) );
	}
}
