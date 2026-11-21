<?php
/**
 * Zibll license cache tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Zibll\License;
use Sakura\ImageOptimizer\Zibll\License_Cache;

/**
 * A cached verdict may only stay valid while it still describes this site.
 */
final class ZibllLicenseCacheTest extends TestCase {

	/**
	 * Reset the shimmed environment between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options']       = array();
		$GLOBALS['sakura_test_home_url']      = 'https://example.test';
		$GLOBALS['sakura_test_theme']         = 'zibll';
		$GLOBALS['sakura_test_theme_headers'] = array( 'Version' => '9.2' );
	}

	/**
	 * A cache written for this exact environment is valid.
	 */
	public function test_cache_for_current_environment_is_valid(): void {
		$this->seed_cache();

		$this->assertTrue( License_Cache::is_valid() );
	}

	/**
	 * A theme upgrade invalidates the verdict.
	 */
	public function test_theme_version_change_invalidates_cache(): void {
		$this->seed_cache();

		$GLOBALS['sakura_test_theme_headers']['Version'] = '9.1';

		$this->assertFalse( License_Cache::is_valid() );
	}

	/**
	 * Moving the site to another domain invalidates the verdict.
	 */
	public function test_home_url_change_invalidates_cache(): void {
		$this->seed_cache();

		$GLOBALS['sakura_test_home_url'] = 'https://other.test';

		$this->assertFalse( License_Cache::is_valid() );
	}

	/**
	 * Renewing or replacing the license invalidates the verdict.
	 */
	public function test_license_change_invalidates_cache(): void {
		$this->seed_cache();

		update_option( 'post_zat_end_time', '606012934560|ffffffffffffffffffffffffffffffff' );

		$this->assertFalse( License_Cache::is_valid() );
	}

	/**
	 * An expired license falls back to the theme's own check.
	 */
	public function test_expired_license_invalidates_cache(): void {
		$this->seed_cache();

		$expired = time() - 10;

		update_option( 'post_zat_next', $expired );
		$cache               = (array) get_option( License_Cache::OPTION, array() );
		$cache['expires_at'] = $expired;
		update_option( License_Cache::OPTION, $cache );

		$this->assertFalse( License_Cache::is_valid() );
	}

	/**
	 * Clear removes the option, so nothing is left to trust.
	 */
	public function test_clear_removes_cache(): void {
		$this->seed_cache();

		License_Cache::clear();

		$this->assertSame( array(), License_Cache::get() );
		$this->assertFalse( License_Cache::is_valid() );
	}

	/**
	 * Write a cache as if the theme had just verified the license.
	 *
	 * @param array<string,mixed> $overrides Values to overwrite.
	 * @return void
	 */
	private function seed_cache( array $overrides = array() ): void {
		update_option( 'zibll_code', str_repeat( 'a', 32 ) );
		update_option( 'post_zat_end_time', '606012934560|ee720e98b54bb34a74b16f8b962f3174' );
		update_option( 'post_zat_next', time() + DAY_IN_SECONDS );

		$cache = array_merge(
			array(
				'ok'               => 1,
				'theme_version'    => License::theme_version(),
				'home_url'         => License::home_fingerprint(),
				'auth_fingerprint' => License::auth_fingerprint(),
				'expires_at'       => (int) get_option( 'post_zat_next', 0 ),
				'created_at'       => time(),
				'created_by'       => 1,
				'source'           => 'cron',
			),
			$overrides
		);

		update_option( License_Cache::OPTION, $cache );
	}
}
