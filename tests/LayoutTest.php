<?php
/**
 * Card layout sanitizer tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Layout;

require_once dirname( __DIR__ ) . '/src/Admin/Layout.php';

final class LayoutTest extends TestCase {

	public function test_groups_keep_order_and_clamp_sizes(): void {
		$clean = Layout::sanitize(
			array(
				'image' => array(
					'gapX'   => 99,
					'gapY'   => -5,
					'groups' => array(
						'main' => array(
							'order' => array( 'compress', 'thumbs', 'compress', ' bad id ' ),
							'cards' => array(
								'compress' => array( 'span' => 99, 'minH' => 9000, 'h' => 30000 ),
								'thumbs'   => array( 'span' => 0, 'minH' => -20, 'h' => -10 ),
								'bad id'   => array( 'span' => 6, 'minH' => 10 ),
							),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'compress', 'thumbs', 'badid' ), $clean['image']['groups']['main']['order'] );
		$this->assertSame( 12, $clean['image']['groups']['main']['cards']['compress']['span'] );
		$this->assertSame( 2000, $clean['image']['groups']['main']['cards']['compress']['minH'] );
		$this->assertSame( 20000, $clean['image']['groups']['main']['cards']['compress']['h'] );
		$this->assertSame( 1, $clean['image']['groups']['main']['cards']['thumbs']['span'] );
		$this->assertSame( 0, $clean['image']['groups']['main']['cards']['thumbs']['minH'] );
		$this->assertSame( 0, $clean['image']['groups']['main']['cards']['thumbs']['h'] );
		$this->assertSame( 48, $clean['image']['gapX'] );
		$this->assertSame( 0, $clean['image']['gapY'] );
	}

	public function test_absolute_placement_is_clamped(): void {
		$clean = Layout::sanitize(
			array(
				'thumb' => array(
					'groups' => array(
						'main' => array(
							'order' => array( 'rollback' ),
							'cards' => array(
								'rollback' => array( 'span' => 6, 'col' => 7, 'top' => 245, 'minH' => 147 ),
								'oversized' => array( 'span' => 6, 'col' => 99, 'top' => -10 ),
							),
						),
					),
				),
			)
		);

		$cards = $clean['thumb']['groups']['main']['cards'];
		$this->assertSame( 7, $cards['rollback']['col'] );
		$this->assertSame( 245, $cards['rollback']['top'] );
		$this->assertSame( 12, $cards['oversized']['col'] );
		$this->assertSame( 0, $cards['oversized']['top'] );
	}

	public function test_legacy_flat_layout_is_treated_as_main_group(): void {
		$clean = Layout::sanitize(
			array(
				'backup' => array(
					'order' => array( 'usage', 'policy' ),
					'cards' => array( 'usage' => array( 'span' => 4 ) ),
				),
			)
		);

		$this->assertSame( array( 'usage', 'policy' ), $clean['backup']['groups']['main']['order'] );
		$this->assertSame( 4, $clean['backup']['groups']['main']['cards']['usage']['span'] );
		$this->assertSame( 20, $clean['backup']['gapX'] );
		$this->assertSame( 20, $clean['backup']['gapY'] );
		$this->assertSame( 0, $clean['backup']['grid'] );
	}

	public function test_grid_preview_flag_round_trips(): void {
		$clean = Layout::sanitize(
			array(
				'image' => array( 'grid' => 1, 'order' => array( 'thumbs' ) ),
				'thumb' => array( 'grid' => '0', 'order' => array( 'switches' ) ),
			)
		);

		$this->assertSame( 1, $clean['image']['grid'] );
		$this->assertSame( 0, $clean['thumb']['grid'] );
	}

	public function test_invalid_pages_and_groups_are_dropped(): void {
		$clean = Layout::sanitize(
			array(
				''          => array( 'order' => array( 'a' ) ),
				'ok'        => array( 'groups' => array( '__bad__' => array( 'order' => array( 'a' ) ) ) ),
				'not-array' => 'nope',
			)
		);

		$this->assertArrayNotHasKey( '', $clean );
		$this->assertArrayNotHasKey( 'not-array', $clean );
		$this->assertArrayHasKey( '__bad__', $clean['ok']['groups'] );
		$this->assertSame( array( 'a' ), $clean['ok']['groups']['__bad__']['order'] );
	}

	public function test_global_gaps_are_clamped_and_persisted(): void {
		update_option( Layout::GAPS_OPTION, array( 'gapX' => 99, 'gapY' => -5 ) );

		$this->assertSame( array( 'gapX' => 48, 'gapY' => 0 ), Layout::gaps() );

		update_option( Layout::GAPS_OPTION, array( 'gapX' => 30, 'gapY' => 16 ) );

		$this->assertSame( array( 'gapX' => 30, 'gapY' => 16 ), Layout::gaps() );
	}

	public function test_global_gaps_fall_back_to_defaults(): void {
		$this->assertSame( array( 'gapX' => 20, 'gapY' => 20 ), Layout::sanitize_gaps( 'not-an-array' ) );
	}

	public function test_default_layout_covers_every_layout_page(): void {
		$clean = Layout::sanitize( Layout::DEFAULT_LAYOUT );

		// The file space is one screen per tab since 1.20, so each tab carries
		// its own layout block instead of sharing a single "files" canvas.
		$this->assertSame(
			array( 'image', 'thumb', 's3', 'sync', 'files-queue', 'files-users', 'files-settings', 'files-log', 'files-space', 'backup' ),
			array_keys( $clean )
		);
		$this->assertSame( array( 'thumbs', 'rename', 'compress', 'bulk' ), $clean['image']['groups']['main']['order'] );
		$this->assertSame( array( 'ignored', 'tasks', 'health', 'storage', 'notes', 'reconcile', 'logs' ), $clean['sync']['groups']['main']['order'] );
		$this->assertSame( 12, $clean['sync']['groups']['main']['cards']['tasks']['span'] );
		$this->assertSame( 1, $clean['backup']['grid'] );
		$this->assertSame( array( 'queue', 'execute' ), $clean['files-queue']['groups']['main']['order'] );
		$this->assertSame( 12, $clean['files-queue']['groups']['main']['cards']['queue']['span'] );
		$this->assertSame( 12, $clean['files-queue']['groups']['main']['cards']['execute']['span'] );
		$this->assertSame( array( 'users' ), $clean['files-users']['groups']['main']['order'] );
		$this->assertSame( array( 'appearance', 'settings' ), $clean['files-settings']['groups']['main']['order'] );
		$this->assertSame( array( 'log' ), $clean['files-log']['groups']['main']['order'] );
		$this->assertCount( 8, $clean['files-space']['groups']['main']['order'] );
	}

	protected function tearDown(): void {
		delete_option( Layout::GAPS_OPTION );
		parent::tearDown();
	}
}
