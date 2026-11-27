<?php
/**
 * The page route that feeds the shell.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Rest\Page_Controller;
use WP_Error;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/src/Contracts/Settings_Repository_Interface.php';
require_once dirname( __DIR__, 2 ) . '/src/Settings/Settings_Repository.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Route.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Registry.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/App_Manifest.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Rest/Page_Controller.php';

final class PageControllerTest extends TestCase {

	/**
	 * Reset the option store and the request between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options']     = array();
		$GLOBALS['sakura_test_can']         = true;
		$GLOBALS['sakura_test_rest_routes'] = array();
		$_GET                               = array();
	}

	/**
	 * Clean up the same globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['sakura_test_options']     = array();
		$GLOBALS['sakura_test_can']         = true;
		$GLOBALS['sakura_test_rest_routes'] = array();
		$_GET                               = array();

		parent::tearDown();
	}

	/**
	 * One route, GET only, with a page pattern that cannot reach the filesystem.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered_with_a_safe_pattern(): void {
		Page_Controller::register();

		$routes = $GLOBALS['sakura_test_rest_routes'];

		self::assertArrayHasKey( 'sakura-tools/v1/pages/(?P<page>[a-z0-9-]+)', $routes );

		$route = $routes['sakura-tools/v1/pages/(?P<page>[a-z0-9-]+)'];

		self::assertSame( 'GET', $route['methods'] );
		self::assertSame( array( Page_Controller::class, 'can_read' ), $route['permission_callback'] );
		self::assertSame( array( Page_Controller::class, 'read' ), $route['callback'] );
	}

	/**
	 * An off-allowlist page is a 404, so the browser falls back to a real load.
	 *
	 * @return void
	 */
	public function test_an_unknown_page_is_a_404(): void {

		$response = Page_Controller::read( new WP_REST_Request( array( 'page' => 'media-progress' ) ) );

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'sakura_unknown_page', $response->get_error_code() );
	}

	/**
	 * The permission callback is the manifest: allowlist first, tab gate second.
	 *
	 * @return void
	 */
	public function test_the_permission_callback_reuses_the_manifest(): void {
		$request = new WP_REST_Request( array( 'page' => 'system-settings' ) );

		self::assertTrue( Page_Controller::can_read( $request ) );
		self::assertFalse( Page_Controller::can_read( new WP_REST_Request( array( 'page' => 'nope' ) ) ) );

		$GLOBALS['sakura_test_can'] = false;

		self::assertFalse( Page_Controller::can_read( $request ) );
	}
}
