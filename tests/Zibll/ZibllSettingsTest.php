<?php
/**
 * Zibll settings save-path tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Zibll\License;
use Sakura\ImageOptimizer\Zibll\License_Cache;
use Sakura\ImageOptimizer\Zibll\Settings;

/**
 * Enabling is only persisted after the theme itself verified the license.
 */
final class ZibllSettingsTest extends TestCase {

	/**
	 * Reset the shimmed environment between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options']        = array();
		$GLOBALS['sakura_test_settings_errors'] = array();
		$GLOBALS['sakura_test_home_url']       = 'https://example.test';
		$GLOBALS['sakura_test_theme']          = 'zibll';
		$GLOBALS['sakura_test_theme_headers']  = array( 'Version' => '9.2' );
	}

	/**
	 * Turning the switch off clears the cache.
	 */
	public function test_disabling_clears_cache(): void {
		update_option( Settings::OPTION, array( 'license_cache' => 1 ) );
		update_option( License_Cache::OPTION, array( 'ok' => 1 ) );

		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '0' ) ) );

		$this->assertSame( 0, $result['license_cache'] );
		$this->assertSame( 1, $result['duration'] );
		$this->assertSame( 'forever', $result['unit'] );
		$this->assertSame( array(), License_Cache::get() );
	}

	/**
	 * A save from another card must not flip this option.
	 */
	public function test_foreign_card_submission_is_ignored(): void {
		update_option( Settings::OPTION, array( 'license_cache' => 1 ) );

		$result = Settings::sanitize(
			array(
				'_sakura_card'    => 'budget',
				'_sakura_partial' => '1',
				'license_cache'   => '0',
			)
		);

		$this->assertSame( 1, $result['license_cache'] );
		$this->assertSame( 'forever', $result['unit'] );
	}

	/**
	 * An untested theme version needs an explicit acknowledgement.
	 */
	public function test_unsupported_version_requires_acknowledgement(): void {
		$GLOBALS['sakura_test_theme_headers']['Version'] = '9.0';

		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '1' ) ) );

		$this->assertSame( 0, $result['license_cache'] );

		$errors = (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'warning', $errors[0][3] );
	}

	/**
	 * A site without the license payload cannot enable the cache.
	 */
	public function test_enabling_without_license_is_refused(): void {
		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '1' ) ) );

		$this->assertSame( 0, $result['license_cache'] );

		$errors = (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'error', $errors[0][3] );
		$this->assertStringContainsString( '授权', $errors[0][2] );
	}

	/**
	 * A cache that already matches the environment is proof enough: saving must
	 * neither rerun the probe nor print a second notice.
	 */
	public function test_valid_cache_skips_probe_on_save(): void {
		$created = time();

		update_option( 'zibll_code', str_repeat( 'a', 32 ) );
		update_option( 'post_zat_end_time', '606012934560|ee720e98b54bb34a74b16f8b962f3174' );
		update_option( 'post_zat_next', $created + DAY_IN_SECONDS );
		update_option(
			License_Cache::OPTION,
			array(
				'ok'               => 1,
				'theme_version'    => License::theme_version(),
				'home_url'         => License::home_fingerprint(),
				'auth_fingerprint' => License::auth_fingerprint(),
				'expires_at'       => $created + DAY_IN_SECONDS,
				'created_at'       => $created,
				'created_by'       => 1,
				'source'           => 'cron',
			)
		);

		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '1' ) ) );

		$this->assertSame( 1, $result['license_cache'] );
		$this->assertSame( array(), (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() ) );
		$this->assertSame( $created, (int) License_Cache::get()['created_at'] );
	}

	/**
	 * Lifetime seconds follow the unit and fall back to “forever”.
	 */
	public function test_lifetime_seconds_follows_unit(): void {
		$cases = array(
			'hour'    => HOUR_IN_SECONDS,
			'day'     => DAY_IN_SECONDS,
			'week'    => 7 * DAY_IN_SECONDS,
			'month'   => 30 * DAY_IN_SECONDS,
			'year'    => 365 * DAY_IN_SECONDS,
			'forever' => 0,
		);

		foreach ( $cases as $unit => $expected ) {
			update_option(
				Settings::OPTION,
				array(
					'license_cache' => 0,
					'duration'      => 2,
					'unit'          => $unit,
				)
			);

			$this->assertSame( 2 * $expected, Settings::lifetime_seconds(), $unit );
		}
	}

	/**
	 * Bad input is clamped to a sane duration and a known unit.
	 */
	public function test_duration_and_unit_are_clamped(): void {
		$result = Settings::sanitize(
			$this->submission(
				array(
					'license_cache' => '0',
					'duration'      => '0',
					'unit'          => 'decade',
				)
			)
		);

		$this->assertSame( 1, $result['duration'] );
		$this->assertSame( 'forever', $result['unit'] );
	}

	/**
	 * Changing the lifetime retunes a valid verdict instead of probing again.
	 */
	public function test_changing_lifetime_retunes_cache_without_probe(): void {
		$created = time();

		update_option( 'zibll_code', str_repeat( 'a', 32 ) );
		update_option( 'post_zat_end_time', '606012934560|ee720e98b54bb34a74b16f8b962f3174' );
		update_option( 'post_zat_next', $created + 30 * DAY_IN_SECONDS );
		update_option(
			License_Cache::OPTION,
			array(
				'ok'               => 1,
				'theme_version'    => License::theme_version(),
				'home_url'         => License::home_fingerprint(),
				'auth_fingerprint' => License::auth_fingerprint(),
				'expires_at'       => $created + 30 * DAY_IN_SECONDS,
				'created_at'       => $created,
				'created_by'       => 1,
				'source'           => 'cron',
			)
		);

		$result = Settings::sanitize(
			$this->submission(
				array(
					'license_cache' => '1',
					'duration'      => '6',
					'unit'          => 'hour',
				)
			)
		);

		$this->assertSame( 1, $result['license_cache'] );
		$this->assertSame( 6, $result['duration'] );
		$this->assertSame( 'hour', $result['unit'] );
		$this->assertSame( array(), (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() ) );
		$this->assertSame( $created + 6 * HOUR_IN_SECONDS, (int) License_Cache::get()['cache_until'] );
	}

	/**
	 * Build a card-scoped submission.
	 *
	 * @param array<string,string> $fields Submitted fields.
	 * @return array<string,string>
	 */
	private function submission( array $fields ): array {
		return array_merge(
			array(
				'_sakura_card'    => Settings::CARD,
				'_sakura_partial' => '1',
			),
			$fields
		);
	}
}