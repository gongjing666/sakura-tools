<?php
/**
 * Tests for the per-request memo of the latest scan row.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Index_Repository;

require_once dirname( __DIR__ ) . '/src/Sync/Schema.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Minimal $wpdb double for the scans table.
 */
final class LatestScanMemoDatabase {

	public string $prefix = 't_';

	/**
	 * Scan rows, newest last.
	 *
	 * @var array<int,object>
	 */
	public array $scans = array();

	/**
	 * SELECTs that asked for the latest scan of a profile.
	 */
	public int $scan_reads = 0;

	public function prepare( $sql, ...$args ): string {
		return serialize( array( (string) $sql, $args ) );
	}

	public function get_row( $prepared, $output = ARRAY_A ) {
		unset( $output );

		list( $sql ) = unserialize( $prepared );

		if ( false === strpos( (string) $sql, 'sio_sync_scans' ) || false === strpos( (string) $sql, 'ORDER BY id DESC' ) ) {
			return null;
		}

		++$this->scan_reads;

		return $this->scans ? $this->scans[ count( $this->scans ) - 1 ] : null;
	}

	public function get_results( $prepared, $output = ARRAY_A ) {
		unset( $prepared, $output );
		return array();
	}

	public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
		unset( $table, $formats, $where_formats );

		$id = (int) ( $where['id'] ?? 0 );
		foreach ( $this->scans as $row ) {
			if ( (int) $row->id !== $id ) {
				continue;
			}
			foreach ( (array) $data as $key => $value ) {
				$row->{$key} = $value;
			}
		}

		return 1;
	}
}

final class LatestScanMemoTest extends TestCase {

	private $previous_wpdb;

	private $previous_options;

	private LatestScanMemoDatabase $db;

	protected function setUp(): void {
		$this->previous_wpdb    = $GLOBALS['wpdb'] ?? null;
		$this->previous_options = $GLOBALS['sakura_test_options'] ?? null;

		$this->db        = new LatestScanMemoDatabase();
		$GLOBALS['wpdb'] = $this->db;

		// bucket() builds an Options snapshot; no profile may resolve, so the
		// summary skips its counts query and only the scans read is exercised.
		$GLOBALS['sakura_test_options'] = array();

		$this->db->scans = array( $this->scan_row( 7, 'failed' ) );
	}

	protected function tearDown(): void {
		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		$GLOBALS['sakura_test_options'] = $this->previous_options;
	}

	private function scan_row( int $id, string $state ): object {
		return (object) array(
			'id'            => $id,
			'profile_id'    => 'prof_memo',
			'mode'          => 'full',
			'state'         => $state,
			'phase'         => 'list_remote',
			'error_code'    => 'sio_remote_error',
			'error_message' => 'S3 timed out',
			'processed'     => 0,
			'attempts'      => 3,
			'updated_at'    => '2026-11-22 00:00:00',
			'finished_at'   => '2026-11-22 00:00:05',
			'total_local'   => 10,
			'total_remote'  => 10,
			'local_bytes'   => 100,
			'remote_bytes'  => 100,
			'cloud_image'   => 6,
			'cloud_video'   => 2,
			'cloud_archive' => 1,
		);
	}

	/**
	 * The page snapshot and the failed-scan admin notice read the same row
	 * during one request; Query Monitor reported that as a duplicate query.
	 */
	public function test_the_snapshot_and_the_notice_share_one_scans_lookup(): void {
		$index = new Index_Repository();

		$summary = $index->summary( 'prof_memo' );

		// admin_notices() re-read the row directly.
		$scan = $index->latest_scan( 'prof_memo' );

		$this->assertSame( 1, $this->db->scan_reads );
		$this->assertSame( $scan, $summary['scan'] );
		$this->assertSame( 7, (int) $scan->id );
	}

	/**
	 * A scan started, finished or failed earlier in the same request must be
	 * read back in its new state, not from the memo.
	 */
	public function test_a_scans_write_drops_the_memo(): void {
		$index = new Index_Repository();

		$this->assertSame( 7, (int) $index->latest_scan( 'prof_memo' )->id );

		$index->update_scan( 7, array( 'state' => 'running' ) );

		$scan = $index->latest_scan( 'prof_memo' );

		$this->assertSame( 2, $this->db->scan_reads );
		$this->assertSame( 'running', (string) $scan->state );
	}

	/**
	 * "No scan yet" is a fact worth memoising too: the notice asks again.
	 */
	public function test_a_missing_scan_is_memoised_as_null(): void {
		$this->db->scans = array();

		$index = new Index_Repository();

		$this->assertNull( $index->latest_scan( 'prof_memo' ) );
		$this->assertNull( $index->latest_scan( 'prof_memo' ) );
		$this->assertSame( 1, $this->db->scan_reads );
	}
}
