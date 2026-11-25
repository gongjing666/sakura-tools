<?php
/**
 * User file space core policy tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Services\Theme_Limits;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/src/UserFiles/Settings.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Events.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Files.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Folders.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Repositories/Requests.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Support/Time.php';
require_once dirname( __DIR__ ) . '/src/UserFiles/Services/Theme_Limits.php';

final class UserFilesCoreTest extends TestCase {

	public function test_defaults_cover_the_nine_allowed_types(): void {
		$defaults = Settings::defaults();

		$this->assertCount( 9, $defaults['allowed_types'] );
		$this->assertSame( 536870912, $defaults['quota_default_bytes'] );
		$this->assertSame( 52428800, $defaults['max_file_bytes'] );
		$this->assertSame( '2026-10-31', $defaults['reference_notice_version'] );
		$this->assertContains( 'image/webp', $defaults['allowed_types'] );
		$this->assertNotContains( 'image/svg+xml', $defaults['allowed_types'] );
	}

	public function test_feature_is_off_by_default_and_only_an_explicit_flag_turns_it_on(): void {
		$defaults = Settings::defaults();
		$this->assertFalse( $defaults['enabled'] );

		$this->assertFalse( Settings::sanitize( array() )['enabled'] );
		$this->assertFalse( Settings::sanitize( array( 'enabled' => '0' ) )['enabled'] );
		$this->assertFalse( Settings::sanitize( array( 'enabled' => '' ) )['enabled'] );
		$this->assertTrue( Settings::sanitize( array( 'enabled' => '1' ) )['enabled'] );
	}

	public function test_sanitize_clamps_numbers_and_drops_bad_types(): void {
		$clean = Settings::sanitize(
			array(
				'max_user_files'         => 0,
				'max_folder_depth'       => 999,
				'max_concurrent_uploads' => 99,
				'allowed_types'          => array( 'image/png', 'IMAGE/WEBP', 'not-a-mime', 'image/png' ),
				'reference_notice_version' => 'yesterday',
			)
		);

		$this->assertSame( 1, $clean['max_user_files'] );
		$this->assertSame( 32, $clean['max_folder_depth'] );
		$this->assertSame( 8, $clean['max_concurrent_uploads'] );
		$this->assertSame( array( 'image/png', 'image/webp' ), $clean['allowed_types'] );
		$this->assertSame( '2026-10-31', $clean['reference_notice_version'] );
	}

	public function test_custom_folder_accent_round_trips_and_derives_fill(): void {
		$clean = Settings::sanitize( array( 'folder_accent' => '#3A8F7A' ) );

		$this->assertSame( '#3a8f7a', $clean['folder_accent'] );
		$this->assertTrue( Settings::is_custom_accent( $clean['folder_accent'] ) );
		$this->assertSame( 'sufm-accent-custom', Settings::accent_class( $clean['folder_accent'] ) );
		$this->assertSame(
			'--sufm-folder-ink:#3a8f7a;--sufm-folder-fill:#c8e0da;',
			Settings::accent_style( $clean['folder_accent'] )
		);

		$this->assertSame( 'sufm-accent-amber', Settings::accent_class( 'amber' ) );
		$this->assertSame( '', Settings::accent_style( 'amber' ) );
		$this->assertSame( '', Settings::accent_class( '' ) );
	}

	public function test_invalid_folder_accent_falls_back_to_auto(): void {
		foreach ( array( '#12345', '#1234567', 'not-a-colour', '#gggggg' ) as $bad ) {
			$this->assertSame( '', Settings::sanitize( array( 'folder_accent' => $bad ) )['folder_accent'] );
		}
	}

	public function test_preset_accent_palette_matches_the_stylesheet_values(): void {
		$palette = Settings::accent_palette();

		$this->assertCount( 6, $palette );
		$this->assertSame( array( 'ink' => '#c8932b', 'fill' => '#f7dc9a' ), $palette['amber'] );
		$this->assertSame( array( 'ink' => '#3b7dd8', 'fill' => '#cfe0f7' ), $palette['blue'] );
	}

	public function test_megabyte_inputs_are_stored_as_bytes(): void {
		$clean = Settings::sanitize(
			array(
				'quota_default_bytes_mb'       => '512',
				'max_file_bytes_mb'            => '25',
				'daily_upload_bytes_mb'        => '1',
				'site_storage_budget_bytes_mb' => '0',
			)
		);

		$this->assertSame( 536870912, $clean['quota_default_bytes'] );
		$this->assertSame( 26214400, $clean['max_file_bytes'] );
		$this->assertSame( 1048576, $clean['daily_upload_bytes'] );
		$this->assertSame( 0, $clean['site_storage_budget_bytes'] );
	}

	public function test_sanitize_keeps_zero_for_an_unset_site_budget(): void {
		$clean = Settings::sanitize( array( 'site_storage_budget_bytes' => 0 ) );

		$this->assertSame( 0, $clean['site_storage_budget_bytes'] );
	}

	public function test_dangerous_mime_types_never_pass(): void {
		$this->assertTrue( Settings::is_allowed_type( 'image/png' ) );
		$this->assertFalse( Settings::is_allowed_type( 'image/svg+xml' ) );
		$this->assertFalse( Settings::is_allowed_type( 'text/html' ) );
		$this->assertFalse( Settings::is_allowed_type( 'application/x-php' ) );
	}

	public function test_request_statuses_are_unique_and_complete(): void {
		$statuses = array(
			Requests::PENDING,
			Requests::WITHDRAWN,
			Requests::REJECTED,
			Requests::APPROVED,
			Requests::REVOKED,
			Requests::NEEDS,
			Requests::QUEUED,
			Requests::EXECUTING,
			Requests::COMPLETED,
			Requests::PARTIAL,
			Requests::FAILED,
			Requests::STOPPED,
		);

		$this->assertCount( 12, $statuses );
		$this->assertCount( 12, array_unique( $statuses ) );
	}

	public function test_time_helpers_are_utc_formatted(): void {
		$now = Time::now();

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now );
		$this->assertGreaterThan( $now, Time::in( 60 ) );
		$this->assertSame( '0000-00-00 00:00:00', Time::zero() );
	}

	public function test_theme_limits_round_trip_through_the_theme_option(): void {
		$previous_theme = $GLOBALS['sakura_test_theme'] ?? null;

		// Theme_Limits only reports the Zibll guard when Zibll is the active
		// theme, so flip the theme shim instead of faking the option alone.
		$GLOBALS['sakura_test_theme'] = 'zibll';

		update_option(
			'zibll_options',
			array(
				'brush_limit_upload' => array(
					'minutes_10' => '50',
					'day_1'      => '200',
				),
				'other_key'          => 'keep',
			)
		);

		try {
			$detected = Theme_Limits::detect();

			$this->assertSame( 'zibll', $detected['id'] );
			$this->assertSame( 50, $detected['ten'] );
			$this->assertSame( 200, $detected['day'] );

			$this->assertTrue( Theme_Limits::write( 'zibll', 80, 300 ) );

			$options = get_option( 'zibll_options' );

			$this->assertSame( '80', $options['brush_limit_upload']['minutes_10'] );
			$this->assertSame( '300', $options['brush_limit_upload']['day_1'] );
			$this->assertSame( 'keep', $options['other_key'] );
		} finally {
			if ( null === $previous_theme ) {
				unset( $GLOBALS['sakura_test_theme'] );
			} else {
				$GLOBALS['sakura_test_theme'] = $previous_theme;
			}
		}
	}

	public function test_sanitize_keeps_theme_limit_fields(): void {
		$out = Settings::sanitize(
			array(
				'theme_upload_limit_ten_minutes' => 80,
				'theme_upload_limit_day'         => 300,
				'theme_upload_limit_source'      => 'zibll',
				'theme_upload_limit_synced_at'   => '2026-11-02 00:40:00',
			)
		);

		$this->assertSame( 80, $out['theme_upload_limit_ten_minutes'] );
		$this->assertSame( 300, $out['theme_upload_limit_day'] );
		$this->assertSame( 'zibll', $out['theme_upload_limit_source'] );
		$this->assertSame( '2026-11-02 00:40:00', $out['theme_upload_limit_synced_at'] );
	}
}
