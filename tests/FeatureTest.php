<?php
/**
 * Feature switch tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Feature;

/**
 * The rollback switch defaults on and can be persisted off.
 */
final class FeatureTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	public function test_enabled_by_default(): void {
		$this->assertTrue( Feature::enabled() );
	}

	public function test_set_disables_and_persists(): void {
		Feature::set( false );
		$this->assertFalse( Feature::enabled() );
		$this->assertSame( '0', get_option( Feature::OPTION ) );

		Feature::set( true );
		$this->assertTrue( Feature::enabled() );
		$this->assertSame( '1', get_option( Feature::OPTION ) );
	}
}
