<?php
/**
 * Provider registry tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Provider_Registry;
use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Providers\Doge;
use Sakura\ImageOptimizer\Thumbnail\Providers\Generic;

/**
 * The table that replaced the scattered `'bitiful' === $provider` checks.
 */
final class ProviderRegistryTest extends TestCase {

	public function test_bitiful_and_dogecloud_own_both_modes(): void {
		foreach ( array( 'bitiful', 'dogecloud' ) as $provider ) {
			$this->assertTrue( Provider_Registry::supports( $provider, 'style' ) );
			$this->assertTrue( Provider_Registry::supports( $provider, 'params' ) );
		}
	}

	public function test_other_providers_have_no_built_in_syntax(): void {
		$this->assertFalse( Provider_Registry::supports( 'aliyun', 'style' ) );
		$this->assertFalse( Provider_Registry::supports( 'minio', 'params' ) );
		$this->assertFalse( Provider_Registry::supports( '', 'style' ) );
		$this->assertNull( Provider_Registry::make( 'minio', 'params' ) );
	}

	public function test_make_returns_the_class_that_speaks_the_carrier_syntax(): void {
		$this->assertInstanceOf( Bitiful::class, Provider_Registry::make( 'bitiful', 'style' ) );
		$this->assertInstanceOf( Doge::class, Provider_Registry::make( 'dogecloud', 'style' ) );
	}

	/**
	 * The decorator asks the registry for the effective mode, which is
	 * `params` for every carrier, so the built class must carry that mode.
	 */
	public function test_make_passes_the_mode_through(): void {
		$style = Provider_Registry::make( 'dogecloud', 'style' );
		$params = Provider_Registry::make( 'dogecloud', 'params' );

		$this->assertNotNull( $style );
		$this->assertNotNull( $params );
		$this->assertTrue( $style->supports( 'style' ) );
		$this->assertTrue( $params->supports( 'quality' ) );
	}

	public function test_names_lists_the_carriers(): void {
		$this->assertSame( array( 'bitiful', 'dogecloud' ), Provider_Registry::names() );
	}

	/**
	 * Generic is the fallback for every other carrier, never a registry entry.
	 */
	public function test_generic_is_not_registered(): void {
		$this->assertNotContains( 'generic', Provider_Registry::names() );
		$this->assertInstanceOf( Generic::class, new Generic( '?w={w}' ) );
	}
}
