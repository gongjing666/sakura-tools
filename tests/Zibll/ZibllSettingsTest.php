<?php
/**
 * Zibll settings save-path tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
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

		$this->assertSame( array( 'license_cache' => 0 ), $result );
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

		$this->assertSame( array( 'license_cache' => 1 ), $result );
	}

	/**
	 * An untested theme version needs an explicit acknowledgement.
	 */
	public function test_unsupported_version_requires_acknowledgement(): void {
		$GLOBALS['sakura_test_theme_headers']['Version'] = '9.0';

		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '1' ) ) );

		$this->assertSame( array( 'license_cache' => 0 ), $result );

		$errors = (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'warning', $errors[0][3] );
	}

	/**
	 * A site without the license payload cannot enable the cache.
	 */
	public function test_enabling_without_license_is_refused(): void {
		$result = Settings::sanitize( $this->submission( array( 'license_cache' => '1' ) ) );

		$this->assertSame( array( 'license_cache' => 0 ), $result );

		$errors = (array) ( $GLOBALS['sakura_test_settings_errors'] ?? array() );
		$this->assertCount( 1, $errors );
		$this->assertSame( 'error', $errors[0][3] );
		$this->assertStringContainsString( '授权', $errors[0][2] );
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
