<?php
/**
 * Rules card writes over REST.
 *
 * register_setting() only runs on admin_init, and admin_init never fires on a
 * REST request, so the module's sanitize_* callbacks are not attached when the
 * image controller writes an option. Image_Settings::clamp() stands in for
 * them; these tests pin the ranges and the "only my own options" boundary.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Image_Settings;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

require_once dirname( __DIR__, 2 ) . '/src/Settings/Settings_Repository.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Image_Settings.php';

final class ImageSettingsTest extends TestCase {

	/**
	 * Settings gateway under test.
	 *
	 * @var Image_Settings
	 */
	private Image_Settings $settings;

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
		$this->settings                 = new Image_Settings( new Settings_Repository() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_options'] );
	}

	/**
	 * Values of one card as they came back from a save.
	 *
	 * @param string              $card  Card id.
	 * @param array<string,mixed> $input Submitted values.
	 * @return array<string,mixed>
	 */
	private function save( string $card, array $input ): array {
		return $this->settings->save_rules_card( $card, $input )['values'];
	}

	public function test_quality_and_batch_size_are_clamped(): void {
		$values = $this->save(
			'compress',
			array(
				'quality'          => 500,
				'max_file_size_mb' => 5000,
				'max_image_width'  => 999999,
				'max_image_height' => 1,
			)
		);

		$this->assertSame( 100, (int) $values['quality'] );
		$this->assertSame( 1024, (int) $values['max_file_size_mb'] );
		$this->assertSame( 20000, (int) $values['max_image_width'] );
		$this->assertSame( 1, (int) $values['max_image_height'] );

		$bulk = $this->save( 'bulk', array( 'batch_size' => 99, 'ajax_delay' => 9999 ) );

		$this->assertSame( 20, (int) $bulk['batch_size'] );
		$this->assertSame( 2000, (int) $bulk['ajax_delay'] );
	}

	public function test_unknown_enum_values_fall_back(): void {
		$values = $this->save(
			'compress',
			array(
				'compression_mode' => 'sideways',
				'convert_format'   => 'gif',
			)
		);

		$this->assertSame( 'compress_only', $values['compression_mode'] );
		$this->assertSame( 'webp', $values['convert_format'] );
	}

	public function test_source_formats_drop_unknown_extensions(): void {
		$values = $this->save( 'compress', array( 'source_formats' => array( 'webp', 'exe', 'PNG', 'webp' ) ) );

		$this->assertSame( array( 'webp', 'png' ), $values['source_formats'] );
	}

	public function test_rename_rule_keeps_only_supported_placeholders(): void {
		$values = $this->save(
			'rename',
			array(
				'rename_enabled' => 1,
				'rename_rule'    => '{date}/{nope}-{rand}',
				'rename_fixed'   => '空 格!abc',
			)
		);

		$this->assertSame( 1, (int) $values['rename_enabled'] );
		$this->assertSame( '{date}--{rand}', $values['rename_rule'] );
		$this->assertSame( 'abc', $values['rename_fixed'] );
	}

	public function test_a_card_only_writes_its_own_options(): void {
		$GLOBALS['sakura_test_options']['sakura_imgopt_quality'] = 77;

		$this->save( 'bulk', array( 'batch_size' => 4, 'quality' => 10 ) );

		$this->assertSame( 4, (int) $GLOBALS['sakura_test_options']['sakura_imgopt_batch_size'] );
		$this->assertSame( 77, (int) $GLOBALS['sakura_test_options']['sakura_imgopt_quality'] );
	}

	public function test_notice_warns_only_when_a_value_was_adjusted(): void {
		$clean = $this->settings->save_rules_card( 'bulk', array( 'batch_size' => 5 ) );
		$this->assertSame( 'ok', $clean['notice']['tone'] );
		$this->assertSame( 'SETTINGS / SAVED', $clean['notice']['kicker'] );

		$adjusted = $this->settings->save_rules_card( 'bulk', array( 'batch_size' => 500 ) );
		$this->assertSame( 'warn', $adjusted['notice']['tone'] );
		$this->assertSame( 'SETTINGS / CHECK', $adjusted['notice']['kicker'] );
		$this->assertStringContainsString( '1 项', $adjusted['notice']['message'] );
	}
}
