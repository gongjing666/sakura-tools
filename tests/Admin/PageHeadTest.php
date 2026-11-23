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
require_once dirname( __DIR__, 2 ) . '/src/Admin/UI/Page.php';

final class PageHeadTest extends TestCase {

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
}
