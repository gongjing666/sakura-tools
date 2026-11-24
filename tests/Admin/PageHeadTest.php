<?php
/**
 * Page head data tests.
 *
 * Page::head() and the React island both read Page::head_data(), so this array
 * is the contract between them: the island cannot invent a field the server
 * never sends, and a tone or action the server would have dropped must be
 * dropped here too.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\UI\Page;

require_once dirname( __DIR__, 2 ) . '/src/Admin/UI/Hero.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Appearance.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/UI/Page.php';

final class PageHeadTest extends TestCase {

	/**
	 * An island's first paint still has to look like a page: ghost top bar for
	 * height, the real head, and the boot marker inside the action row.
	 *
	 * @return void
	 */
	public function test_island_first_paint_draws_the_head_and_the_boot_marker(): void {
		ob_start();
		Page::render(
			array(
				'id'     => 'system-settings',
				'module' => 'system',
				'tab'    => 'settings',
				'title'  => '系统设置',
				'island' => true,
				'actions' => array(
					array(
						'label' => '重新检测',
						'url'   => 'https://example.test/admin.php?page=sakura-tools',
					),
				),
			),
			static function (): void {
			}
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="sakura-topbar sakura-topbar--boot"', $html );
		self::assertStringContainsString( 'class="sakura-tabs sakura-tabs--boot"', $html );
		self::assertStringContainsString( 'class="sakura-admin__main"', $html );
		self::assertStringContainsString( 'id="sakura-page-title"', $html );
		self::assertMatchesRegularExpression( '/page-head__actions.*?sakura-boot.*?<\/div>/s', $html );
		self::assertStringContainsString( '正在读取页面信息…', $html );
		self::assertStringNotContainsString( 'system-boot', $html );
	}

	/**
	 * A page without action buttons must not get a row just to hold the marker:
	 * the row would vanish on mount and pull the page up by its height.
	 *
	 * @return void
	 */
	public function test_island_root_marks_itself_so_main_can_skip_its_entrance(): void {
		ob_start();
		Page::render(
			array(
				'id'     => 'image-rules',
				'module' => 'image',
				'tab'    => 'rules',
				'title'  => '图像优化',
				'island' => true,
			),
			static function (): void {
			}
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-sakura-island="1"', $html );
		// No action row on this page: the boot marker must not conjure one, or the
		// row disappears on mount and the whole page jumps up by its height.
		self::assertStringNotContainsString( 'page-head__actions', $html );
		self::assertStringNotContainsString( 'sakura-boot', $html );
	}

	/**
	 * The four text parts travel unchanged when a view sets them.
	 *
	 * @return void
	 */
	public function test_head_data_carries_what_the_view_passed(): void {
		$head = Page::head_data(
			array(
				'id'      => 'system-settings',
				'eyebrow' => 'SYSTEM READOUT / 1.28.8',
				'title'   => '系统设置',
				'lede'    => '服务器运行参数。',
				'tagline' => '先把<em>底盘</em>照清楚。',
			)
		);

		self::assertSame( 'system-settings', $head['id'] );
		self::assertSame( '系统设置', $head['title'] );
		self::assertSame( 'SYSTEM READOUT / 1.28.8', $head['eyebrow'] );
		self::assertSame( '服务器运行参数。', $head['lede'] );
		self::assertSame( '先把<em>底盘</em>照清楚。', $head['tagline'] );
		self::assertSame( array(), $head['status'] );
		self::assertSame( array(), $head['actions'] );
	}

	/**
	 * An unset tagline comes from the hero table; markup beyond em/strong/span
	 * never reaches the island.
	 *
	 * @return void
	 */
	public function test_tagline_falls_back_to_the_hero_and_strips_unknown_markup(): void {
		$hero = Page::head_data( array( 'id' => 'system-settings' ) );

		self::assertStringContainsString( '<em>', $hero['tagline'] );

		$stripped = Page::head_data(
			array(
				'id'      => 'system-settings',
				'tagline' => '<script>x</script>安全',
			)
		);

		self::assertSame( 'x安全', $stripped['tagline'] );
	}

	/**
	 * Tone is a closed set: anything else becomes neutral rather than reaching
	 * a CSS class that does not exist.
	 *
	 * @return void
	 */
	public function test_status_tones_are_limited_to_the_known_set(): void {
		$head = Page::head_data(
			array(
				'status' => array(
					'label' => '环境自检',
					'value' => '9/9',
					'tone'  => 'papaya',
				),
			)
		);

		self::assertSame( 'neutral', $head['status']['tone'] );
		self::assertSame( '9/9', $head['status']['value'] );
	}

	/**
	 * Half an action is not an action, and the default variant is the quiet one.
	 *
	 * @return void
	 */
	public function test_actions_need_a_label_and_a_url_and_default_to_ghost(): void {
		$head = Page::head_data(
			array(
				'actions' => array(
					array(
						'label' => '重新检测',
						'url'   => 'https://example.test/a',
					),
					array( 'label' => '缺少地址' ),
					array( 'url' => 'https://example.test/b' ),
				),
			)
		);

		self::assertCount( 1, $head['actions'] );
		self::assertSame( 'ghost', $head['actions'][0]['variant'] );
		self::assertSame( '重新检测', $head['actions'][0]['label'] );
	}

	/**
	 * The seal is shared, and the hero places it in the aside column.
	 *
	 * @return void
	 */
	public function test_the_shared_seal_is_placed_in_the_aside_column(): void {
		$head = Page::head_data( array( 'id' => 'system-settings' ) );

		self::assertSame( '桜', $head['stamp']['zh'] );
		self::assertSame( 'aside', $head['stamp']['place'] );
	}

	/**
	 * A skeleton is only useful if it lands on the same grid the real cards use:
	 * one `.card--boot` per declared id, carrying the layout's span.
	 *
	 * @return void
	 */
	public function test_island_skeleton_uses_the_layout_placement_of_each_card(): void {
		require_once dirname( __DIR__, 2 ) . '/src/Admin/Layout.php';

		ob_start();
		Page::render(
			array(
				'id'       => 'image-backup',
				'module'   => 'image',
				'tab'      => 'backup',
				'title'    => '备份管理',
				'island'   => true,
				'skeleton' => array(
					'kpis'  => 0,
					'kpi'   => 0,
					'cards' => array( 'usage', 'policy' ),
				),
			),
			static function (): void {}
		);
		$html = (string) ob_get_clean();

		self::assertSame( 2, substr_count( $html, 'card card--boot' ) );
		self::assertStringContainsString( 'boot-rows', $html );
		self::assertStringContainsString( '--g-card-span:8', $html );
		self::assertStringContainsString( '--g-card-span:4', $html );
	}
}
