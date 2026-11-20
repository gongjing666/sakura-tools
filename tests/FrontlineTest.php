<?php
/**
 * The "sakura front" rail renders exactly three stations, or nothing.
 *
 * The rail is decorative but its numbers are not: they must come through
 * escaped, and a half-filled rail (fewer than three) would claim a relationship
 * between sides that does not exist, so it renders nothing instead.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\UI\Frontline;

/**
 * Covers Frontline::render().
 */
final class FrontlineTest extends TestCase {

	/**
	 * Skips when the WordPress runtime (or the plugin autoloader) is missing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'esc_html' ) || ! function_exists( 'esc_attr' ) ) {
			$this->markTestSkipped( 'WordPress runtime is not available.' );
		}

		if ( ! class_exists( Frontline::class ) ) {
			$this->markTestSkipped( 'Frontline class is not autoloadable.' );
		}
	}

	/**
	 * Renders the rail and returns its markup.
	 *
	 * @param array<int,array<string,string>> $stations Stations.
	 * @param string                          $caption  Caption.
	 * @param string                          $note     Note.
	 * @return string
	 */
	private function capture( array $stations, string $caption = '', string $note = '' ): string {
		ob_start();
		Frontline::render( $stations, $caption, $note );
		return (string) ob_get_clean();
	}

	/**
	 * Three stations render the drawing, three values and the captions.
	 *
	 * @return void
	 */
	public function test_render_draws_three_stations_with_captions(): void {
		$html = $this->capture(
			array(
				array( 'value' => '135', 'label' => '本地媒体', 'sub' => '45.73 MB', 'tone' => 'sky' ),
				array( 'value' => '135', 'label' => '云端文件', 'sub' => '45.73 MB', 'tone' => 'petal' ),
				array( 'value' => '100.0%', 'label' => '已同步', 'sub' => '135 个文件三方一致', 'tone' => 'sun' ),
			),
			'本地 → 云端 → 索引',
			'最近核对 11-20 09:31'
		);

		$this->assertStringContainsString( 'class="frontline"', $html );
		$this->assertStringContainsString( 'sakura-front-arc', $html );
		$this->assertSame( 3, substr_count( $html, 'frontline__station frontline__station--' ) );
		$this->assertSame( 3, substr_count( $html, 'class="frontline__value"' ) );
		$this->assertStringContainsString( '>100.0%<', $html );
		$this->assertStringContainsString( '本地 → 云端 → 索引', $html );
		$this->assertStringContainsString( '最近核对 11-20 09:31', $html );
		$this->assertStringContainsString( 'frontline__station--sun', $html );
	}

	/**
	 * A rail with the wrong number of stations claims a false relationship.
	 *
	 * @return void
	 */
	public function test_render_skips_when_station_count_is_not_three(): void {
		$html = $this->capture(
			array(
				array( 'value' => '135', 'label' => '本地媒体' ),
				array( 'value' => '135', 'label' => '云端文件' ),
			)
		);

		$this->assertSame( '', $html );
	}

	/**
	 * Values and labels are escaped, so a hostile label cannot become markup.
	 *
	 * @return void
	 */
	public function test_render_escapes_station_content(): void {
		$html = $this->capture(
			array(
				array( 'value' => '<b>1</b>', 'label' => '<i>本地</i>' ),
				array( 'value' => '2', 'label' => '云端' ),
				array( 'value' => '3', 'label' => '索引' ),
			)
		);

		$this->assertStringNotContainsString( '<b>1</b>', $html );
		$this->assertStringNotContainsString( '<i>本地</i>', $html );
		$this->assertStringContainsString( '&lt;b&gt;1&lt;/b&gt;', $html );
	}

	/**
	 * An unknown tone falls back to the station's position default.
	 *
	 * @return void
	 */
	public function test_render_uses_position_defaults_for_tones(): void {
		$html = $this->capture(
			array(
				array( 'value' => '1', 'label' => 'a' ),
				array( 'value' => '2', 'label' => 'b' ),
				array( 'value' => '3', 'label' => 'c', 'tone' => '' ),
			)
		);

		$this->assertStringContainsString( 'frontline__station--sky', $html );
		$this->assertStringContainsString( 'frontline__station--petal', $html );
		$this->assertStringContainsString( 'frontline__station--sun', $html );
	}
}
