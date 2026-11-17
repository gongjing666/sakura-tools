<?php
/**
 * Unit tests for upload metrics.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Upload_Metrics;
use Sakura\ImageOptimizer\S3\Upload_Observer;

/**
 * In-memory event table with just enough SQL surface for Upload_Metrics.
 */
final class UploadMetricsDatabase {
	public string $prefix = 'test_';
	public string $last_error = '';
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $inserts = array();

	public function insert( $table, $row ) {
		$this->inserts[] = $row;
		$this->rows[]     = $row;
		return 1;
	}

	public function prepare( $query, ...$args ) {
		// wpdb quotes string placeholders; the doubles below match on the quotes.
		$args = array_map( static fn( $arg ) => is_string( $arg ) ? "'" . $arg . "'" : $arg, $args );

		return vsprintf( (string) $query, $args );
	}

	public function get_results( $sql ) {
		$rows = $this->rows;
		if ( preg_match( "/day >= '([0-9-]+)'/", (string) $sql, $match ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) $row['day'] >= $match[1] ) );
		}
		if ( preg_match( "/day < '([0-9-]+)'/", (string) $sql, $match ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) $row['day'] < $match[1] ) );
		}

		if ( str_contains( (string) $sql, 'GROUP BY day, result' ) ) {
			return $this->aggregate( $rows, true );
		}
		if ( str_contains( (string) $sql, 'GROUP BY result' ) ) {
			return $this->aggregate( $rows, false );
		}

		return array();
	}

	private function aggregate( array $rows, bool $by_day ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$key = $by_day ? (string) $row['day'] . '|' . (string) $row['result'] : (string) $row['result'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = (object) array(
					'day'     => $by_day ? (string) $row['day'] : null,
					'result'  => (string) $row['result'],
					'n'       => 0,
					'bytes'   => 0,
					'ms_sum'  => 0,
					'max_ms'  => 0,
				);
			}
			$groups[ $key ]->n++;
			$groups[ $key ]->bytes  += 'ok' === $row['result'] ? (int) $row['bytes'] : 0;
			$groups[ $key ]->ms_sum += (int) $row['duration_ms'];
			$groups[ $key ]->max_ms  = max( (int) $groups[ $key ]->max_ms, (int) $row['duration_ms'] );
		}

		return array_values( $groups );
	}

	public function get_row( $sql ) {
		$rows = array_values( array_filter( $this->rows, static fn( array $row ): bool => in_array( $row['result'], array( 'fail', 'dead' ), true ) ) );
		if ( ! $rows ) {
			return null;
		}
		$row = $rows[ count( $rows ) - 1 ];

		return (object) array(
			'created_at'    => (string) $row['created_at'],
			'error_message' => (string) ( $row['error_message'] ?? '' ),
			'attachment_id' => (int) ( $row['attachment_id'] ?? 0 ),
			'object_key'    => (string) ( $row['object_key'] ?? '' ),
		);
	}

	public function get_var( $sql ) {
		if ( ! $this->rows ) {
			return null;
		}
		return max( array_column( $this->rows, 'created_at' ) );
	}

	public function get_col( $sql ) {
		return array( 'id', 'created_at', 'day', 'result', 'error_message' );
	}

	public function query( $sql ) {
		if ( str_starts_with( trim( (string) $sql ), 'DELETE FROM' ) ) {
			if ( preg_match( "/day < '([0-9-]+)'/", (string) $sql, $match ) ) {
				$keep       = array_values( array_filter( $this->rows, static fn( array $row ): bool => (string) $row['day'] >= $match[1] ) );
				$count      = count( $this->rows ) - count( $keep );
				$this->rows = $keep;
				return $count;
			}
			$count      = count( $this->rows );
			$this->rows = array();
			return $count;
		}
		return false;
	}
}

final class UploadMetricsTest extends TestCase {
	private ?object $previous_wpdb = null;
	private UploadMetricsDatabase $db;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->db            = new UploadMetricsDatabase();
		$GLOBALS['wpdb']     = $this->db;
		$GLOBALS['sakura_test_options'] = array(
			Upload_Metrics::SCHEMA_OPTION => Upload_Metrics::VERSION,
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	public function test_empty_snapshot(): void {
		$snapshot = ( new Upload_Metrics() )->snapshot();

		$this->assertSame( 0, $snapshot['today']['ok'] );
		$this->assertSame( 0, $snapshot['today']['attempts'] );
		$this->assertSame( '—', $snapshot['today']['rate_text'] );
		$this->assertSame( '—', $snapshot['today']['avg_text'] );
		$this->assertFalse( $snapshot['last_error']['has'] );
		$this->assertArrayNotHasKey( 'backlog', $snapshot );
	}

	public function test_records_success_and_failure_as_events(): void {
		$metrics = new Upload_Metrics();
		$metrics->record_batch(
			array(
				array(
					'ok'         => true,
					'ms'         => 100,
					'bytes'      => 2048,
					'attachment' => 1,
					'key'        => 'a.jpg',
					'profile'    => 'prof',
				),
				array(
					'ok'         => true,
					'ms'         => 200,
					'bytes'      => 1024,
					'attachment' => 2,
					'key'        => 'b.jpg',
					'profile'    => 'prof',
				),
				array(
					'ok'         => false,
					'ms'         => 300,
					'bytes'      => 0,
					'error'      => 'Access Denied',
					'error_code' => 'access_denied',
					'attachment' => 3,
					'key'        => 'c.jpg',
					'profile'    => 'prof',
					'dead'       => true,
				),
			)
		);

		$snapshot = $metrics->snapshot();

		$this->assertCount( 3, $this->db->inserts );
		$this->assertSame( 'prof', $this->db->inserts[0]['profile_id'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $this->db->inserts[0]['day'] );
		$this->assertSame( 2, $snapshot['today']['ok'] );
		$this->assertSame( 1, $snapshot['today']['fail'] );
		$this->assertSame( 1, $snapshot['today']['dead'] );
		$this->assertSame( 3, $snapshot['today']['attempts'] );
		$this->assertSame( '33.3%', $snapshot['today']['rate_text'] );
		$this->assertSame( '200 ms', $snapshot['today']['avg_text'] );
		$this->assertSame( '300 ms', $snapshot['today']['max_text'] );
		$this->assertSame( '3.0 KB', $snapshot['today']['bytes_text'] );
		$this->assertSame( 3, $snapshot['totals']['attempts'] );
		$this->assertSame( 1, $snapshot['totals']['dead'] );
		$this->assertTrue( $snapshot['last_error']['has'] );
		$this->assertSame( 'Access Denied', $snapshot['last_error']['message'] );
		$this->assertSame( 3, $snapshot['last_error']['attachment'] );
		$this->assertSame( 'access_denied', $this->db->inserts[2]['error_code'] );
	}

	public function test_snapshot_keeps_legacy_totals_but_drops_old_daily_buckets(): void {
		$old = array(
			'ok'       => 9,
			'fail'     => 0,
			'dead'     => 0,
			'bytes'    => 0,
			'ms_sum'   => 0,
			'max_ms'   => 0,
			'attempts' => 9,
		);
		$GLOBALS['sakura_test_options'][ Upload_Metrics::OPTION ] = array(
			'version'    => '1.0.0',
			'totals'     => $old,
			'days'       => array( '2020-01-01' => $old ),
			'last_error' => array( 'at' => 0, 'message' => '', 'attachment' => 0, 'key' => '' ),
			'updated_at' => 1,
		);

		$metrics = new Upload_Metrics();
		$metrics->record_batch( array( array( 'ok' => true, 'ms' => 50, 'bytes' => 10 ) ) );
		$snapshot = $metrics->snapshot();

		$this->assertSame( 10, $snapshot['totals']['ok'] );
		$this->assertSame( 1, $snapshot['today']['ok'] );
		$this->assertSame( 1, $snapshot['week']['attempts'] );
		$this->assertSame( 9, get_option( Upload_Metrics::OPTION )['totals']['ok'], 'Recording events must not rewrite the frozen legacy option.' );
	}

	public function test_old_version_option_write_is_added_once_after_upgrade(): void {
		$old = array(
			'ok'       => 30,
			'fail'     => 0,
			'dead'     => 0,
			'bytes'    => 1279424,
			'ms_sum'   => 0,
			'max_ms'   => 0,
			'attempts' => 30,
		);
		$GLOBALS['sakura_test_options'][ Upload_Metrics::OPTION ] = array(
			'version'    => '1.0.0',
			'totals'     => $old,
			'days'       => array(),
			'last_error' => array( 'at' => 0, 'message' => '', 'attachment' => 0, 'key' => '' ),
			'updated_at' => 1,
		);

		$metrics = new Upload_Metrics();
		for ( $i = 0; $i < 5; $i++ ) {
			$metrics->record_batch( array( array( 'ok' => true, 'ms' => 10, 'bytes' => 100 ) ) );
		}

		// A rollback writes only its own counter into the still-frozen option.
		$legacy                   = get_option( Upload_Metrics::OPTION );
		$legacy['totals']['ok']      += 3;
		$legacy['totals']['attempts'] += 3;
		update_option( Upload_Metrics::OPTION, $legacy );

		$snapshot = $metrics->snapshot();

		$this->assertCount( 5, $this->db->rows );
		$this->assertSame( 38, $snapshot['totals']['ok'], 'The old write and the event rows are additive, not duplicated.' );
		$this->assertSame( 38, $snapshot['totals']['attempts'] );
	}

	public function test_failed_upload_observer_records_attachment_and_error(): void {
		$observer = new Upload_Observer( new Options( array( 'active_profile_id' => 'prof', 'profiles' => array( 'prof' => array( 'provider' => 'minio', 'bucket' => 'test' ) ) ) ), new Upload_Metrics() );
		$observer->record_failed( 'bad.jpg', '/missing/file.jpg', new WP_Error( 'sakura_s3_upload_failed', 'Access Denied' ), 7 );

		$snapshot = ( new Upload_Metrics() )->snapshot();
		$this->assertSame( 1, $snapshot['today']['fail'] );
		$this->assertSame( 'Access Denied', $snapshot['last_error']['message'] );
		$this->assertSame( 7, $snapshot['last_error']['attachment'] );
		$this->assertSame( 'bad.jpg', $snapshot['last_error']['key'] );
		$this->assertSame( 'prof', $this->db->inserts[0]['profile_id'] );
	}

	public function test_reset_clears_events_and_legacy_option(): void {
		$metrics = new Upload_Metrics();
		$metrics->record_batch( array( array( 'ok' => true, 'ms' => 1 ) ) );
		$this->assertCount( 1, $this->db->rows );
		$GLOBALS['sakura_test_options'][ Upload_Metrics::OPTION ] = array( 'totals' => array( 'ok' => 1 ) );

		$metrics->reset();

		$this->assertCount( 0, $this->db->rows );
		$this->assertFalse( get_option( Upload_Metrics::OPTION ) );
		$this->assertSame( Upload_Metrics::VERSION, get_option( Upload_Metrics::SCHEMA_OPTION ) );
	}

	public function test_prune_rolls_expired_rows_into_the_baseline(): void {
		$metrics = new Upload_Metrics();
		$old_day = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		$new_day = gmdate( 'Y-m-d' );
		$this->db->rows = array(
			array( 'day' => $old_day, 'result' => 'ok', 'bytes' => 500, 'duration_ms' => 100, 'created_at' => $old_day . ' 00:00:00' ),
			array( 'day' => $old_day, 'result' => 'ok', 'bytes' => 250, 'duration_ms' => 300, 'created_at' => $old_day . ' 01:00:00' ),
			array( 'day' => $old_day, 'result' => 'dead', 'bytes' => 0, 'duration_ms' => 900, 'created_at' => $old_day . ' 02:00:00' ),
			array( 'day' => $new_day, 'result' => 'ok', 'bytes' => 10, 'duration_ms' => 5, 'created_at' => $new_day . ' 00:00:00' ),
		);

		$before  = $metrics->snapshot();
		$removed = $metrics->prune();
		$after   = $metrics->snapshot();

		$this->assertSame( 3, $removed, 'Only rows older than the window are deleted.' );
		$this->assertCount( 1, $this->db->rows );
		$this->assertSame( $before['totals']['ok'], $after['totals']['ok'], 'Totals must not go down when rows age out.' );
		$this->assertSame( $before['totals']['attempts'], $after['totals']['attempts'] );
		$this->assertSame( $before['totals']['bytes'], $after['totals']['bytes'] );
		$this->assertSame( $before['totals']['dead'], $after['totals']['dead'] );
		$this->assertSame( 900, $after['totals']['max_ms'] );
		$this->assertSame( 3, get_option( Upload_Metrics::OPTION )['totals']['attempts'], 'The expired rows are folded into the frozen baseline.' );
		$this->assertFalse( get_option( Upload_Metrics::PRUNE_LOCK ), 'The prune lock is released.' );
	}

	public function test_prune_keeps_the_daily_window_clean(): void {
		$metrics = new Upload_Metrics();
		$old_day = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		$this->db->rows = array(
			array( 'day' => $old_day, 'result' => 'ok', 'bytes' => 100, 'duration_ms' => 10, 'created_at' => $old_day . ' 00:00:00' ),
		);

		$metrics->prune();
		$snapshot = $metrics->snapshot();

		$this->assertArrayNotHasKey( $old_day, $snapshot['days'] ?? array(), 'A pruned day must not reappear in the daily read model.' );
		$this->assertSame( 0, $snapshot['week']['attempts'] );
	}

	public function test_prune_does_nothing_when_nothing_expired(): void {
		$metrics = new Upload_Metrics();
		$metrics->record_batch( array( array( 'ok' => true, 'ms' => 1, 'bytes' => 2 ) ) );

		$this->assertSame( 0, $metrics->prune() );
		$this->assertCount( 1, $this->db->rows );
		$this->assertFalse( get_option( Upload_Metrics::OPTION ), 'The baseline is left alone when there is nothing to fold in.' );
	}

	public function test_prune_lock_skips_a_second_concurrent_run(): void {
		$metrics = new Upload_Metrics();
		$old_day = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		$this->db->rows = array(
			array( 'day' => $old_day, 'result' => 'ok', 'bytes' => 1, 'duration_ms' => 1, 'created_at' => $old_day . ' 00:00:00' ),
		);
		$GLOBALS['sakura_test_options'][ Upload_Metrics::PRUNE_LOCK ] = time();

		$this->assertSame( 0, $metrics->prune(), 'A lock held by another worker is honoured.' );
		$this->assertCount( 1, $this->db->rows );

		$GLOBALS['sakura_test_options'][ Upload_Metrics::PRUNE_LOCK ] = time() - 3600;
		$this->assertSame( 1, $metrics->prune(), 'A stale lock is stolen.' );
	}
}
