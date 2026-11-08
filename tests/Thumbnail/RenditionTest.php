<?php
/**
 * Rendition tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

/**
 * Value object behaviour.
 */
final class RenditionTest extends TestCase {

	public function test_style_validation(): void {
		$this->assertSame( 'siomedium', Rendition::sanitize_style( ' siomedium ' ) );
		$this->assertSame( '', Rendition::sanitize_style( '../etc/passwd' ) );
		$this->assertSame( '', Rendition::sanitize_style( str_repeat( 'a', 37 ) ) );
	}

	public function test_from_array_sanitizes_unknown_values(): void {
		$rendition = Rendition::from_array(
			array(
				'width'    => 500,
				'height'   => -10,
				'mode'     => 'stretch',
				'position' => 'left',
				'quality'  => 140,
				'format'   => 'gif',
				'style'    => 'siomedium',
			)
		);

		$this->assertSame( 500, $rendition->width );
		$this->assertSame( 0, $rendition->height );
		$this->assertSame( Rendition::MODE_FIT, $rendition->mode );
		$this->assertSame( 'left', $rendition->position );
		$this->assertSame( 100, $rendition->quality );
		$this->assertSame( 'auto', $rendition->format );
		$this->assertSame( 'siomedium', $rendition->style );
	}

	public function test_with_style_and_key(): void {
		$rendition = ( new Rendition( 500, 500, Rendition::MODE_CROP ) )->with_style( 'siomedium' );

		$this->assertTrue( $rendition->is_usable() );
		$this->assertSame( 'siomedium', $rendition->style );
		$this->assertStringContainsString( 'crop', $rendition->key() );
	}

	public function test_style_alone_is_usable(): void {
		$this->assertTrue( ( new Rendition( 0, 0, Rendition::MODE_FIT, 'auto', 0, 'auto', 'siolarge' ) )->is_usable() );
		$this->assertFalse( ( new Rendition() )->is_usable() );
	}
}
