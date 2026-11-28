<?php
/**
 * Save path for the file-space settings cards.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use Nboke\UserFiles\Admin\Settings_Cards;
use Nboke\UserFiles\Rest\Settings_Controller;
use Nboke\UserFiles\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers the merge, clamp and response shape of Settings_Cards::save().
 */
final class FilesSettingsSaveTest extends TestCase {

	/**
	 * Reset the option store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS["sakura_test_options"] = array();
	}

	/**
	 * Drop the fixture.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS["sakura_test_options"] = array();
		unset( $GLOBALS["sakura_test_can"] );

		parent::tearDown();
	}

	/**
	 * An unknown card is rejected before any write.
	 *
	 * @return void
	 */
	public function test_unknown_card_is_rejected(): void {
		$result = Settings_Cards::save( "nope", array() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( "sakura_settings_card", $result->get_error_code() );
		self::assertSame( array(), $GLOBALS["sakura_test_options"] );
	}

	/**
	 * Saving the appearance card must not touch policy or gate flags.
	 *
	 * @return void
	 */
	public function test_appearance_save_keeps_policy_and_flags(): void {
		$GLOBALS["sakura_test_options"][ Settings::OPTION ] = array(
			"enabled" => true,
			"quota_default_bytes" => 1048576,
			"max_user_files" => 10,
			"allowed_types" => array( "image/png" ),
		);

		$result = Settings_Cards::save( "appearance", array( "tree_style" => "mac", "folder_accent" => "#3a8f7a" ) );

		self::assertIsArray( $result );

		$stored = get_option( Settings::OPTION );
		self::assertSame( "mac", $stored["tree_style"] );
		self::assertSame( "#3a8f7a", $stored["folder_accent"] );
		self::assertTrue( $stored["enabled"] );
		self::assertSame( 1048576, $stored["quota_default_bytes"] );
		self::assertSame( array( "image/png" ), $stored["allowed_types"] );
	}

	/**
	 * Out-of-range input is clamped and reported as a warning.
	 *
	 * @return void
	 */
	public function test_policy_save_clamps_and_reports(): void {
		$result = Settings_Cards::save(
			"settings",
			array(
				"max_user_files" => "0",
				"quota_default_bytes_mb" => "512",
				"allowed_types" => array( "image/png" ),
			)
		);

		self::assertIsArray( $result );
		self::assertSame( "warn", $result["notice"]["tone"] );
		self::assertSame( "1", $result["sections"]["settings"]["values"]["max_user_files"] );
		self::assertSame( "512", $result["sections"]["settings"]["values"]["quota_default_bytes_mb"] );

		$stored = get_option( Settings::OPTION );
		self::assertSame( 1, $stored["max_user_files"] );
		self::assertSame( 536870912, $stored["quota_default_bytes"] );
	}

	/**
	 * An empty allow-list is a real value, not a missing field.
	 *
	 * @return void
	 */
	public function test_empty_allowed_types_is_persisted(): void {
		$GLOBALS["sakura_test_options"][ Settings::OPTION ] = array( "allowed_types" => array( "image/png" ) );

		$result = Settings_Cards::save( "settings", array( "allowed_types" => array() ) );

		self::assertIsArray( $result );

		$stored = get_option( Settings::OPTION );
		self::assertSame( array(), $stored["allowed_types"] );
		self::assertSame( array(), $result["sections"]["settings"]["values"]["allowed_types"] );
	}

	/**
	 * The write route reuses the page gate instead of a second check.
	 *
	 * @return void
	 */
	public function test_controller_permission_reuses_page_gate(): void {
		$GLOBALS["sakura_test_can"] = true;

		self::assertTrue( Settings_Controller::can_edit() );

		$GLOBALS["sakura_test_can"] = false;

		self::assertFalse( Settings_Controller::can_edit() );
	}

	/**
	 * The response section is the same builder the payload uses.
	 *
	 * @return void
	 */
	public function test_response_section_matches_payload_data(): void {
		$result = Settings_Cards::save( "appearance", array() );

		self::assertIsArray( $result );
		self::assertSame(
			Settings_Cards::data( "appearance", Settings::get() ),
			$result["sections"]["appearance"]
		);
	}
}
