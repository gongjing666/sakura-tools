<?php
/**
 * Layout save gateway tests.
 *
 * The REST route is the only write path now, so the merge rules it uses are the
 * only thing standing between a tampered payload and the shared canvas. These
 * tests pin the three behaviours the old options.php sanitizers provided:
 * clamping, field-by-field fallback, and rejecting an unknown shadow value.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Appearance;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\System_Layout_Settings;

require_once dirname( __DIR__, 2 ) . '/src/Admin/Appearance.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Layout.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/System_Layout_Settings.php';

final class SystemLayoutTest extends TestCase {

	/**
	 * Stored state used as the merge base.
	 *
	 * @return array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>}
	 */
	private function stored(): array {
		return array(
			'gaps'       => array( 'gapX' => 16, 'gapY' => 18 ),
			'appearance' => array(
				'page_width'   => 1480,
				'border_width' => 1,
				'radius'       => 18,
				'shadow'       => 'default',
			),
		);
	}

	public function test_missing_fields_keep_the_stored_values(): void {
		$merged = System_Layout_Settings::merge( array( 'radius' => 24 ), $this->stored() );

		$this->assertSame( 24, $merged['appearance']['radius'] );
		$this->assertSame( 1480, $merged['appearance']['page_width'] );
		$this->assertSame( 1, $merged['appearance']['border_width'] );
		$this->assertSame( 'default', $merged['appearance']['shadow'] );
		$this->assertSame( array( 'gapX' => 16, 'gapY' => 18 ), $merged['gaps'] );
	}

	public function test_ranges_are_clamped_the_way_options_php_did(): void {
		$merged = System_Layout_Settings::merge(
			array(
				'gapX'         => -5,
				'gapY'         => 99,
				'page_width'   => 10,
				'border_width' => 40,
				'radius'       => 999,
			),
			$this->stored()
		);

		$this->assertSame( array( 'gapX' => 0, 'gapY' => Layout::MAX_GAP ), $merged['gaps'] );
		$this->assertSame( Appearance::MIN_WIDTH, $merged['appearance']['page_width'] );
		$this->assertSame( Appearance::MAX_BORDER, $merged['appearance']['border_width'] );
		$this->assertSame( Appearance::MAX_RADIUS, $merged['appearance']['radius'] );
	}

	public function test_unknown_shadow_keeps_the_stored_one(): void {
		$accepted = System_Layout_Settings::merge( array( 'shadow' => 'soft' ), $this->stored() );
		$this->assertSame( 'soft', $accepted['appearance']['shadow'] );

		$rejected = System_Layout_Settings::merge( array( 'shadow' => 'dramatic' ), $this->stored() );
		$this->assertSame( 'default', $rejected['appearance']['shadow'] );
	}

	public function test_non_numeric_input_keeps_the_stored_value(): void {
		$merged = System_Layout_Settings::merge(
			array(
				'page_width' => 'wide',
				'radius'     => array( 12 ),
			),
			$this->stored()
		);

		$this->assertSame( 1480, $merged['appearance']['page_width'] );
		$this->assertSame( 18, $merged['appearance']['radius'] );
	}

	public function test_save_writes_both_options_and_reads_them_back(): void {
		$GLOBALS['sakura_test_options'] = array();

		$saved = System_Layout_Settings::save(
			array(
				'gaps'       => array( 'gapX' => 20, 'gapY' => 24 ),
				'appearance' => array(
					'page_width'   => 1600,
					'border_width' => 2,
					'radius'       => 22,
					'shadow'       => 'strong',
				),
			)
		);

		$this->assertSame( array( 'gapX' => 20, 'gapY' => 24 ), $GLOBALS['sakura_test_options'][ Layout::GAPS_OPTION ] );
		$this->assertSame( 1600, $GLOBALS['sakura_test_options'][ Appearance::OPTION ]['page_width'] );
		$this->assertSame( $GLOBALS['sakura_test_options'][ Appearance::OPTION ], $saved['appearance'] );
		$this->assertSame( $GLOBALS['sakura_test_options'][ Layout::GAPS_OPTION ], $saved['gaps'] );

		unset( $GLOBALS['sakura_test_options'] );
	}
}
