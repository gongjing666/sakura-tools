<?php
/**
 * Crop configuration scanner tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Image\Crop_Config_Scanner;

/**
 * Covers static parsing and the source/precedence resolution rules.
 */
final class CropConfigScannerTest extends TestCase {

	/**
	 * Minimal core catalog for resolution tests.
	 *
	 * @return array<string,array{width:int,height:int,crop:bool,label:string,detail:string,source:string}>
	 */
	private function core(): array {
		return array(
			'thumbnail' => array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
				'label'  => '缩略图',
				'detail' => '设置 → 媒体 → 缩略图大小',
				'source' => 'core',
			),
			'medium'    => array(
				'width'  => 300,
				'height' => 300,
				'crop'   => false,
				'label'  => '中等',
				'detail' => '设置 → 媒体 → 中等大小',
				'source' => 'core',
			),
		);
	}

	/**
	 * add_image_size() arguments are decoded, including array crop.
	 */
	public function test_parse_add_image_size(): void {
		$source = "<?php\nadd_image_size( 'hero', 1200, 600, array( 'crop' => true ) );\n";

		$parsed = Crop_Config_Scanner::parse_php_source( $source, 'functions.php' );

		$this->assertSame( 1, count( $parsed['adds'] ) );
		$this->assertSame( 'hero', $parsed['adds'][0]['name'] );
		$this->assertSame( 1200, $parsed['adds'][0]['width'] );
		$this->assertSame( 600, $parsed['adds'][0]['height'] );
		$this->assertTrue( $parsed['adds'][0]['crop'] );
		$this->assertFalse( $parsed['adds'][0]['dynamic'] );
	}

	/**
	 * A dynamic size name is reported, not guessed.
	 */
	public function test_parse_dynamic_size_name(): void {
		$source = "<?php\nforeach ( \$sizes as \$name ) { add_image_size( \$name, 100, 100 ); }\n";

		$parsed = Crop_Config_Scanner::parse_php_source( $source, 'inc/media.php' );

		$this->assertSame( array(), $parsed['adds'] );
		$this->assertSame( 1, count( $parsed['dynamic'] ) );
		$this->assertSame( 'add_image_size', $parsed['dynamic'][0]['function'] );
	}

	/**
	 * remove_image_size(), filters and core option writes are collected.
	 */
	public function test_parse_removal_filter_and_option(): void {
		$source = "<?php\nremove_image_size( 'large' );\nadd_filter( 'intermediate_image_sizes', 'cb' );\nupdate_option( 'medium_size_w', 500 );\n";

		$parsed = Crop_Config_Scanner::parse_php_source( $source, 'functions.php' );

		$this->assertSame( 'large', $parsed['removals'][0]['name'] );
		$this->assertSame( 'intermediate_image_sizes', $parsed['hooks'][0]['hook'] );
		$this->assertSame( 'medium_size_w', $parsed['options'][0]['option'] );
		$this->assertSame( 500, $parsed['options'][0]['value'] );
	}

	/**
	 * Theme additions are attributed to the theme and runtime registration wins.
	 */
	public function test_resolve_merges_theme_source(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(
				array( 'name' => 'medium', 'width' => 600, 'height' => 600, 'crop' => true, 'file' => 'functions.php', 'line' => 12 ),
			),
			'removals' => array(),
			'hooks'    => array(),
		);

		$additional = array(
			'medium' => array( 'width' => 600, 'height' => 600, 'crop' => true ),
		);

		$resolved = Crop_Config_Scanner::resolve( $this->core(), $additional, $theme, array(), array() );
		$row      = $resolved['sizes']['medium'];

		$this->assertSame( 'enabled', $row['status'] );
		$this->assertSame( 600, $row['width'] );
		$this->assertSame( '主题：Sakura（sakura）', $row['sources'][1]['label'] );
		$this->assertTrue( $row['conflict'], 'Core 300x300 vs theme 600x600 must be flagged.' );
	}

	/**
	 * Static-only registration is reported as unregistered.
	 */
	public function test_resolve_static_only_row(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(
				array( 'name' => 'hero', 'width' => 1200, 'height' => 600, 'crop' => false, 'file' => 'functions.php', 'line' => 20 ),
			),
			'removals' => array(),
			'hooks'    => array(),
		);

		$resolved = Crop_Config_Scanner::resolve( $this->core(), array(), $theme, array(), array() );
		$row      = $resolved['sizes']['hero'];

		$this->assertSame( 'unregistered', $row['status'] );
		$this->assertFalse( $row['registered'] );
		$this->assertSame( 1200, $row['width'] );
	}

	/**
	 * Plugin removal is attributed to the plugin.
	 */
	public function test_resolve_removed_by_plugin(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(
				array( 'name' => 'hero', 'width' => 1200, 'height' => 600, 'crop' => false, 'file' => 'functions.php', 'line' => 20 ),
			),
			'removals' => array(),
			'hooks'    => array(),
		);

		$plugin = array(
			array(
				'type'     => 'plugin',
				'label'    => '插件：Optimus（optimus）',
				'adds'     => array(),
				'removals' => array(
					array( 'name' => 'hero', 'file' => 'optimus.php', 'line' => 30 ),
				),
				'hooks'    => array(),
			),
		);

		$resolved = Crop_Config_Scanner::resolve( array(), array(), $theme, $plugin, array() );
		$row      = $resolved['sizes']['hero'];

		$this->assertSame( 'removed', $row['status'] );
		$this->assertSame( array( '插件：Optimus（optimus）' ), $row['removed_by'] );
		$this->assertSame( '主题：Sakura（sakura）', $row['sources'][0]['label'] );
	}

	public function test_resolve_disabled_wins(): void {
		$resolved = Crop_Config_Scanner::resolve( $this->core(), array(), null, array(), array( 'thumbnail' ) );
		$row      = $resolved['sizes']['thumbnail'];

		$this->assertSame( 'disabled_by_plugin', $row['status'] );
		$this->assertTrue( $row['registered'] );
		$this->assertSame( 1, $resolved['summary']['disabled'] );
	}

	/**
	 * A disabled name with no live source stays visible and removable.
	 */
	public function test_resolve_orphan_disabled_name(): void {
		$resolved = Crop_Config_Scanner::resolve( $this->core(), array(), null, array(), array( 'ghost' ) );

		$this->assertArrayHasKey( 'ghost', $resolved['sizes'] );
		$this->assertSame( 'disabled_by_plugin', $resolved['sizes']['ghost']['status'] );
		$this->assertSame( array(), $resolved['sizes']['ghost']['sources'] );
	}

	/**
	 * A filter alone is a warning, not proof that a live size was removed.
	 */
	public function test_resolve_filter_only_keeps_enabled_with_warning(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(),
			'removals' => array(),
			'hooks'    => array( array( 'hook' => 'intermediate_image_sizes', 'file' => 'functions.php', 'line' => 5 ) ),
		);

		$additional = array(
			'medium_large' => array( 'width' => 768, 'height' => 0, 'crop' => false ),
		);

		$resolved = Crop_Config_Scanner::resolve( array(), $additional, $theme, array(), array() );

		$this->assertSame( 'enabled', $resolved['sizes']['medium_large']['status'] );
		$this->assertNotEmpty( $resolved['warnings'] );
		$this->assertSame( 'intermediate_image_sizes_filter', $resolved['warnings'][0]['code'] );
	}

	/**
	 * A statically added size that is not registered at runtime and sits behind
	 * a filter is reported as filtered instead of enabled.
	 */
	public function test_resolve_filtered_when_static_add_missing(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(
				array( 'name' => 'hero', 'width' => 1200, 'height' => 600, 'crop' => false, 'file' => 'functions.php', 'line' => 20 ),
			),
			'removals' => array(),
			'hooks'    => array( array( 'hook' => 'intermediate_image_sizes', 'file' => 'functions.php', 'line' => 5 ) ),
		);

		$resolved = Crop_Config_Scanner::resolve( $this->core(), array(), $theme, array(), array() );
		$row      = $resolved['sizes']['hero'];

		$this->assertSame( 'filtered', $row['status'] );
		$this->assertSame( '可能被过滤器移除', $row['status_label'] );
		$this->assertFalse( $row['registered'] );
	}

	/**
	 * Core < theme < plugin precedence is reflected in the source list.
	 */
	public function test_resolve_source_order_and_runtime_conflict(): void {
		$theme = array(
			'type'     => 'theme',
			'label'    => '主题：Sakura（sakura）',
			'adds'     => array(
				array( 'name' => 'thumbnail', 'width' => 300, 'height' => 300, 'crop' => false, 'file' => 'functions.php', 'line' => 12 ),
			),
			'removals' => array(),
			'hooks'    => array(),
		);
		$plugins = array(
			array(
				'type'     => 'plugin',
				'label'    => '插件：Optimus（optimus）',
				'adds'     => array(
					array( 'name' => 'thumbnail', 'width' => 400, 'height' => 400, 'crop' => true, 'file' => 'optimus.php', 'line' => 30 ),
				),
				'removals' => array(),
				'hooks'    => array(),
			),
		);
		$runtime = array(
			'thumbnail' => array( 'width' => 400, 'height' => 400, 'crop' => true ),
		);

		$resolved = Crop_Config_Scanner::resolve( $this->core(), $runtime, $theme, $plugins, array() );
		$row      = $resolved['sizes']['thumbnail'];

		$this->assertSame( 400, $row['width'] );
		$this->assertTrue( $row['conflict'], 'Three different shapes must be flagged as a conflict.' );
		$this->assertSame(
			array( 'WordPress 核心', '主题：Sakura（sakura）', '插件：Optimus（optimus）' ),
			array_column( $row['sources'], 'label' )
		);
		$this->assertSame( 1, $resolved['summary']['conflicts'] );
	}

	/**
	 * The big-image threshold is reported separately and never counted as a size.
	 */
	public function test_resolve_ignores_big_threshold_in_size_totals(): void {
		$resolved = Crop_Config_Scanner::resolve( $this->core(), array(), null, array(), array( '_big_threshold' ) );

		$this->assertArrayNotHasKey( '_big_threshold', $resolved['sizes'] );
		$this->assertSame( 2, $resolved['summary']['total'] );
		$this->assertSame( 0, $resolved['summary']['disabled'] );
	}
}
