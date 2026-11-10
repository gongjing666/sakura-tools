<?php
/**
 * Upgrade guard tests.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Upgrade_Guard;

/**
 * Tests the in-place upgrade decision and lock handling.
 */
final class UpgradeGuardTest extends TestCase {

	/**
	 * Reset the shim globals before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sakura_test_options']    = array();
		$GLOBALS['sakura_test_transients'] = array();
		$GLOBALS['sakura_test_actions']    = array();
	}

	/**
	 * A site without a version marker needs the routine once.
	 */
	public function test_missing_version_needs_upgrade(): void {
		$this->assertTrue( Upgrade_Guard::needs_upgrade( '', '1.24.4' ) );
	}

	/**
	 * An older version needs the routine.
	 */
	public function test_older_version_needs_upgrade(): void {
		$this->assertTrue( Upgrade_Guard::needs_upgrade( '1.24.2', '1.24.4' ) );
	}

	/**
	 * The same version is already up to date.
	 */
	public function test_same_version_is_skipped(): void {
		$this->assertFalse( Upgrade_Guard::needs_upgrade( '1.24.4', '1.24.4' ) );
	}

	/**
	 * A newer database version means a downgrade; never rewrite it.
	 */
	public function test_newer_version_is_not_downgraded(): void {
		$this->assertFalse( Upgrade_Guard::needs_upgrade( '1.25.0', '1.24.4' ) );
	}

	/**
	 * Version comparison is numeric, not lexical.
	 */
	public function test_version_comparison_is_numeric(): void {
		$this->assertTrue( Upgrade_Guard::needs_upgrade( '1.24.9', '1.24.10' ) );
	}

	/**
	 * The upgrade callback is not called when versions match.
	 */
	public function test_maybe_upgrade_skips_current_version(): void {
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] = '1.24.4';
		$calls = 0;

		$ran = Upgrade_Guard::maybe_upgrade(
			'1.24.4',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->assertFalse( $ran );
		$this->assertSame( 0, $calls );
	}

	/**
	 * An older version runs the callback and releases the lock.
	 */
	public function test_maybe_upgrade_runs_older_version(): void {
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] = '1.24.2';
		$calls = 0;

		$ran = Upgrade_Guard::maybe_upgrade(
			'1.24.4',
			static function () use ( &$calls ): void {
				++$calls;
				update_option( Upgrade_Guard::VERSION_OPTION, '1.24.4' );
			}
		);

		$this->assertTrue( $ran );
		$this->assertSame( 1, $calls );
		$this->assertArrayNotHasKey( Upgrade_Guard::LOCK_OPTION, $GLOBALS['sakura_test_options'] );
	}

	/**
	 * A live lock makes concurrent requests wait for the next page load.
	 */
	public function test_live_lock_blocks_upgrade(): void {
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] = '1.24.2';
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::LOCK_OPTION ]    = time();
		$calls = 0;

		$ran = Upgrade_Guard::maybe_upgrade(
			'1.24.4',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->assertFalse( $ran );
		$this->assertSame( 0, $calls );
	}

	/**
	 * A lock left behind by a crashed request is stolen after its TTL.
	 */
	public function test_stale_lock_is_stolen(): void {
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] = '1.24.2';
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::LOCK_OPTION ]    = time() - 301;
		$calls = 0;

		$ran = Upgrade_Guard::maybe_upgrade(
			'1.24.4',
			static function () use ( &$calls ): void {
				++$calls;
				update_option( Upgrade_Guard::VERSION_OPTION, '1.24.4' );
			}
		);

		$this->assertTrue( $ran );
		$this->assertSame( 1, $calls );
	}

	/**
	 * A failed upgrade keeps the old version, releases the lock, and records
	 * the error for the admin UI instead of taking the site down.
	 */
	public function test_failure_releases_lock_and_records_error(): void {
		$GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] = '1.24.2';

		$ran = Upgrade_Guard::maybe_upgrade(
			'1.24.4',
			static function (): void {
				throw new RuntimeException( 'schema failed' );
			}
		);

		$this->assertFalse( $ran );
		$this->assertSame( '1.24.2', $GLOBALS['sakura_test_options'][ Upgrade_Guard::VERSION_OPTION ] );
		$this->assertArrayNotHasKey( Upgrade_Guard::LOCK_OPTION, $GLOBALS['sakura_test_options'] );

		$error = $GLOBALS['sakura_test_transients'][ Upgrade_Guard::ERROR_TRANSIENT ];
		$this->assertSame( 'schema failed', $error['message'] );
		$this->assertSame( 'sakura_imgopt_upgrade_failed', end( $GLOBALS['sakura_test_actions'] )[0] );
	}
}
