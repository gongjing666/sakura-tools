<?php
/**
 * Zibll license-bypass guard tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Zibll\Request_Guard;
use Sakura\ImageOptimizer\Zibll\License;
use Sakura\ImageOptimizer\Zibll\License_Cache;
use Sakura\ImageOptimizer\Zibll\Settings;

if ( ! function_exists( 'sakura_test_zib_init_add_action' ) ) {
	/**
	 * Stand-in for the theme callback that runs the license check.
	 *
	 * @return void
	 */
	function sakura_test_zib_init_add_action(): void {}
}

/**
 * The bypass only applies to cached, verified, plain page reads.
 */
final class ZibllRequestGuardTest extends TestCase {

	/**
	 * Reset the shimmed environment between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options']         = array();
		$GLOBALS['sakura_test_hooks']           = array(
			'actions' => array(),
			'filters' => array(),
			'removed' => array(),
		);
		$GLOBALS['sakura_test_removed_actions'] = array();
		$GLOBALS['sakura_test_home_url']        = 'https://example.test';
		$GLOBALS['sakura_test_theme']           = 'zibll';
		$GLOBALS['sakura_test_theme_headers']   = array( 'Version' => '9.2' );
		$GLOBALS['sakura_test_is_admin']        = false;
	}

	/**
	 * Plain page reads qualify; writes and theme-owned endpoints do not.
	 */
	public function test_read_path_classification(): void {
		$this->assertTrue( Request_Guard::is_read_path( 'GET', '/' ) );
		$this->assertTrue( Request_Guard::is_read_path( 'HEAD', '/posts/hello/' ) );
		$this->assertTrue( Request_Guard::is_read_path( 'GET', '/wp-content-style-path/' ) );
		$this->assertTrue( Request_Guard::is_read_path( 'GET', '/wp-admin/admin.php' ) );
		$this->assertTrue( Request_Guard::is_read_path( 'GET', '/wp-admin/edit.php' ) );

		$this->assertFalse( Request_Guard::is_read_path( 'POST', '/' ) );
		$this->assertFalse( Request_Guard::is_read_path( 'POST', '/wp-admin/options.php' ) );
		$this->assertFalse( Request_Guard::is_read_path( 'GET', '/wp-login.php' ) );
		$this->assertFalse( Request_Guard::is_read_path( 'GET', '/wp-json/wp/v2/posts' ) );
		$this->assertFalse( Request_Guard::is_read_path( 'GET', '/xmlrpc.php' ) );
		$this->assertFalse( Request_Guard::is_read_path( 'GET', '/wp-cron.php' ) );
	}

	/**
	 * PHPUnit runs on the command line, which must never skip the check.
	 */
	public function test_command_line_is_detected(): void {
		$this->assertTrue( Request_Guard::is_cli_request() );
	}

	/**
	 * A disabled switch leaves the theme callback in place.
	 */
	public function test_disabled_switch_keeps_theme_callback(): void {
		add_action( 'init', 'sakura_test_zib_init_add_action', 10 );
		$this->seed_cache();

		Request_Guard::maybe_skip_auth();

		$this->assertSame( 10, has_action( 'init', 'sakura_test_zib_init_add_action' ) );
		$this->assertSame( array(), $GLOBALS['sakura_test_removed_actions'] );
	}

	/**
	 * An enabled switch without a valid cache leaves the callback in place.
	 */
	public function test_invalid_cache_keeps_theme_callback(): void {
		update_option( Settings::OPTION, array( 'license_cache' => 1 ) );
		add_action( 'init', 'sakura_test_zib_init_add_action', 10 );

		Request_Guard::maybe_skip_auth();

		$this->assertSame( 10, has_action( 'init', 'sakura_test_zib_init_add_action' ) );
		$this->assertSame( array(), $GLOBALS['sakura_test_removed_actions'] );
	}

	/**
	 * Even a valid cache never applies on the command line.
	 */
	public function test_command_line_never_skips_the_check(): void {
		update_option( Settings::OPTION, array( 'license_cache' => 1 ) );
		$this->seed_cache();
		add_action( 'init', 'sakura_test_zib_init_add_action', 10 );

		Request_Guard::maybe_skip_auth();

		$this->assertSame( 10, has_action( 'init', 'sakura_test_zib_init_add_action' ) );
		$this->assertSame( array(), $GLOBALS['sakura_test_removed_actions'] );
	}

	/**
	 * Write a cache as if the theme had just verified the license.
	 *
	 * @return void
	 */
	private function seed_cache(): void {
		update_option( 'zibll_code', str_repeat( 'a', 32 ) );
		update_option( 'post_zat_end_time', '606012934560|ee720e98b54bb34a74b16f8b962f3174' );
		update_option( 'post_zat_next', time() + DAY_IN_SECONDS );

		update_option(
			License_Cache::OPTION,
			array(
				'ok'               => 1,
				'theme_version'    => License::theme_version(),
				'home_url'         => License::home_fingerprint(),
				'auth_fingerprint' => License::auth_fingerprint(),
				'expires_at'       => (int) get_option( 'post_zat_next', 0 ),
				'created_at'       => time(),
				'created_by'       => 1,
				'source'           => 'cron',
			)
		);
	}
}
