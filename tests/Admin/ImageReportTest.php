<?php
/**
 * The size table must follow the live option, not the last scan snapshot.
 *
 * @package Sakura_Tools
 */

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Image_Report;

require_once dirname( __DIR__, 2 ) . '/src/Admin/Image_Report.php';

/**
 * Class ImageReportTest
 */
final class ImageReportTest extends TestCase {

	/**
	 * Build a scan payload that remembers our own checkbox being ticked.
	 *
	 * @param string $name   Size name, or '' for the big threshold only.
	 * @param bool   $big    Whether the snapshot says the big row is disabled.
	 * @return array<string,mixed>
	 */
	private function stale_scan( string $name = '', bool $big = true ): array {
		$scan = array(
			'sizes'         => array(),
			'big_threshold' => array(
				'disabled'     => $big,
				'status'       => $big ? 'disabled_by_plugin' : 'enabled',
				'status_label' => $big ? '已禁止' : '正常生成',
				'reason'       => $big ? '快照里记着勾选状态。' : '',
			),
		);

		if ( '' !== $name ) {
			$scan['sizes'][ $name ] = array(
				'width'        => 300,
				'height'       => 300,
				'crop'         => false,
				'registered'   => true,
				'status'       => 'disabled_by_plugin',
				'status_label' => '已禁止',
				'reason'       => '快照里记着勾选状态。',
				'sources'      => array(),
			);
		}

		return $scan;
	}

	/**
	 * Find a row by name.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param string                         $name Size name.
	 * @return array<string,mixed>
	 */
	private function row( array $rows, string $name ): array {
		foreach ( $rows as $row ) {
			if ( $name === $row['name'] ) {
				return $row;
			}
		}

		$this->fail( 'Row not found: ' . $name );
	}

	/**
	 * A stale snapshot must not keep the big row disabled once it is unticked.
	 *
	 * @return void
	 */
	public function test_big_row_follows_the_live_option_not_the_snapshot(): void {
		$row = $this->row( Image_Report::size_rows( array(), $this->stale_scan() ), '_big_threshold' );

		$this->assertFalse( $row['disabled'] );
		$this->assertSame( 'enabled', $row['status'] );
		$this->assertSame( 'ok', $row['tone'] );
	}

	/**
	 * Ticking it flips the row immediately, without a rescan.
	 *
	 * @return void
	 */
	public function test_big_row_is_disabled_as_soon_as_it_is_ticked(): void {
		$row = $this->row( Image_Report::size_rows( array( '_big_threshold' ), null ), '_big_threshold' );

		$this->assertTrue( $row['disabled'] );
		$this->assertSame( 'disabled_by_plugin', $row['status'] );
		$this->assertSame( '已禁止', $row['status_label'] );
		$this->assertSame( 'neutral', $row['tone'] );
	}

	/**
	 * Regular sizes have the same staleness trap.
	 *
	 * @return void
	 */
	public function test_regular_size_ignores_a_stale_disabled_status(): void {
		$row = $this->row( Image_Report::size_rows( array(), $this->stale_scan( 'medium' ) ), 'medium' );

		$this->assertFalse( $row['disabled'] );
		$this->assertSame( 'enabled', $row['status'] );
		$this->assertSame( '正常生成', $row['status_label'] );
		$this->assertSame( '', $row['reason'] );
	}
}
