<?php
/**
 * Card placement tests.
 *
 * The drag engine was removed in 1.27: cards now get an explicit row, column
 * and span from Layout::PAGES and the server writes them onto the markup. These
 * tests guard the two things that can still go wrong — a card pointing outside
 * the 12-column grid, and the table disagreeing with the order a screen renders
 * its cards in.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Layout;
use Sakura\ImageOptimizer\Admin\System_Payload;

require_once dirname( __DIR__ ) . '/src/Admin/Layout.php';

final class LayoutTest extends TestCase {

	public function test_global_gaps_are_clamped(): void {
		$this->assertSame( array( 'gapX' => 48, 'gapY' => 0 ), Layout::sanitize_gaps( array( 'gapX' => 99, 'gapY' => -5 ) ) );
		$this->assertSame( array( 'gapX' => 30, 'gapY' => 16 ), Layout::sanitize_gaps( array( 'gapX' => 30, 'gapY' => 16 ) ) );
	}

	public function test_global_gaps_fall_back_to_defaults(): void {
		$this->assertSame( array( 'gapX' => 20, 'gapY' => 20 ), Layout::sanitize_gaps( 'not-an-array' ) );
	}

	/**
	 * Every declared card must fit inside the 12-column grid and start on a
	 * column that exists.
	 */
	public function test_every_card_fits_the_grid(): void {
		foreach ( Layout::PAGES as $layout => $page ) {
			foreach ( $page['cards'] as $card => $placement ) {
				$this->assertCount( 3, $placement, "$layout/$card must be array( row, column, span )" );

				list( $row, $column, $span ) = $placement;

				$this->assertGreaterThanOrEqual( 1, $row, "$layout/$card row" );
				$this->assertGreaterThanOrEqual( 1, $column, "$layout/$card column" );
				$this->assertGreaterThanOrEqual( 1, $span, "$layout/$card span" );
				$this->assertLessThanOrEqual( 12, $span, "$layout/$card span" );
				$this->assertLessThanOrEqual( 12, $column + $span - 1, "$layout/$card overflows the grid" );
			}
		}
	}

	/**
	 * Two cards must never occupy the same cell.
	 */
	public function test_cards_do_not_overlap(): void {
		foreach ( Layout::PAGES as $layout => $page ) {
			$taken = array();

			foreach ( $page['cards'] as $card => $placement ) {
				list( $row, $column, $span ) = $placement;

				for ( $col = $column; $col < $column + $span; $col++ ) {
					$cell = $row . ':' . $col;
					$this->assertArrayNotHasKey( $cell, $taken, "$layout/$card overlaps " . ( $taken[ $cell ] ?? '' ) . " at $cell" );
					$taken[ $cell ] = $card;
				}
			}
		}
	}

	/**
	 * The order list and the card table must describe the same cards.
	 */
	public function test_order_and_cards_agree(): void {
		foreach ( Layout::PAGES as $layout => $page ) {
			$ordered = $page['order'];
			$placed  = array_keys( $page['cards'] );

			sort( $ordered );
			sort( $placed );

			$this->assertSame( $placed, $ordered, "$layout: order and cards must list the same ids" );
		}
	}

	/**
	 * The table has to mirror the render order of each screen, or a page is
	 * silently read in an order the view never intended.
	 *
	 * Card ids are read straight out of the view files so the markup stays the
	 * single source of truth.
	 *
	 * @dataProvider page_markup_provider
	 *
	 * @param string             $layout Layout id.
	 * @param array<int, string> $files  Files that render the cards, in order.
	 */
	public function test_order_matches_markup_render_order( string $layout, array $files ): void {
		$rendered = array();

		foreach ( $files as $file ) {
			$path = dirname( __DIR__ ) . '/' . $file;
			if ( ! is_readable( $path ) ) {
				continue;
			}

			$source = (string) file_get_contents( $path );
			if ( preg_match_all( "/UI::card\(\s*array\(\s*'id'\s*=>\s*'([a-z0-9_-]+)'/", $source, $found ) ) {
				foreach ( $found[1] as $id ) {
					$rendered[] = $id;
				}
			}
		}

		if ( array() === $rendered ) {
			$this->markTestSkipped( 'No UI::card() calls found for ' . $layout );
		}

		$declared = Layout::PAGES[ $layout ]['order'] ?? array();
		sort( $rendered );

		$sorted = $declared;
		sort( $sorted );

		$this->assertSame( $rendered, $sorted, 'Layout::PAGES["' . $layout . '"] must place exactly the cards the screen renders.' );
	}

	/**
	 * @return array<string, array{0:string, 1:array<int,string>}>
	 */
	public static function page_markup_provider(): array {
		return array(
			'image'  => array( 'image', array( 'includes/views/settings-main.php' ) ),
			'thumb'  => array( 'thumb', array( 'includes/views/thumb-settings.php' ) ),
			's3'     => array( 's3', array( 'src/S3/S3_Storage_Page.php', 'src/S3/Sync_Center_Panel.php', 'src/S3/Monitoring_Panel.php' ) ),
			'sync'   => array( 'sync', array( 'includes/views/s3-sync-center.php' ) ),
			'backup' => array( 'backup', array( 'includes/views/settings-backup.php' ) ),
		);
	}

	/**
	 * The system screen is a React island, so its card order left the PHP markup.
	 *
	 * Guarding the payload order and the JSX render order separately keeps the
	 * Layout table authoritative even though no UI::card() call is left to scan.
	 */
	public function test_system_island_keeps_the_declared_card_order(): void {
		require_once dirname( __DIR__ ) . '/src/Admin/System_Payload.php';

		$declared = Layout::PAGES['system-settings']['order'];
		$this->assertSame( $declared, System_Payload::card_ids() );

		$source = (string) file_get_contents( dirname( __DIR__ ) . '/assets/ui/src/system/app.jsx' );
		preg_match_all( '/<Card card=\{\s*(?:boot\.cards\.([a-z_]+)|(layoutCard))\s*\}/', $source, $found );

		$rendered = array();
		foreach ( $found[0] as $index => $markup ) {
			unset( $markup );
			$rendered[] = '' !== $found[1][ $index ] ? $found[1][ $index ] : 'layout';
		}

		$this->assertSame( $declared, $rendered, 'assets/ui/src/system/app.jsx must render the cards in Layout order.' );
	}
}
