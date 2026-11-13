<?php
/**
 * WP-CLI commands for the S3 sync index.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Upload_Metrics;

defined( 'ABSPATH' ) || exit;

/**
 * Scan, inspect and maintain the S3 sync index without the browser.
 *
 * Long scans are designed to run in bounded ticks; this command loops those
 * ticks so it can index a large bucket from a shell or a cron job even when
 * WP-Cron is unreliable.
 */
final class CLI {

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Storage adapter.
	 *
	 * @var Storage_Adapter
	 */
	private Storage_Adapter $adapter;

	/**
	 * Index repository.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $index;

	/**
	 * Constructor.
	 *
	 * @param Options|null         $options Options.
	 * @param Storage_Adapter|null $adapter Adapter.
	 * @param Index_Repository|null $index  Index.
	 */
	public function __construct( ?Options $options = null, ?Storage_Adapter $adapter = null, ?Index_Repository $index = null ) {
		$this->options = $options ?? new Options();
		$this->adapter = $adapter ?? new Storage_Adapter( $this->options );
		$this->index   = $index ?? new Index_Repository();
	}

	/**
	 * Build the sync index from the uploads directory and the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<id>]
	 * : Profile id to scan. Defaults to the active profile.
	 *
	 * [--resume]
	 * : Continue the latest failed or cancelled scan instead of starting over.
	 *
	 * [--pages=<n>]
	 * : Objects listed per tick. Default 50 (50,000 objects).
	 *
	 * [--seconds=<n>]
	 * : Soft time budget per tick. Default 0 (no budget).
	 *
	 * [--local-root=<path>]
	 * : Local directory to index instead of the uploads directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-sync scan --pages=100
	 *     wp sakura-sync scan --resume
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function scan( array $args, array $assoc ): void {
		$profile = isset( $assoc['profile'] ) ? (string) $assoc['profile'] : '';
		$root    = isset( $assoc['local-root'] ) ? (string) $assoc['local-root'] : null;
		$pages   = isset( $assoc['pages'] ) ? max( 1, (int) $assoc['pages'] ) : 50;
		$seconds = isset( $assoc['seconds'] ) ? max( 0, (int) $assoc['seconds'] ) : 0;

		$service = new Scan_Service( $this->options, $this->adapter, $this->index, $root );
		$mode    = isset( $assoc['full'] ) ? 'full' : 'incremental';
		$scan_id = isset( $assoc['resume'] ) ? $service->resume( $profile ) : $service->start( $profile, $mode );

		if ( is_wp_error( $scan_id ) ) {
			\WP_CLI::error( $scan_id->get_error_message() );
		}

		\WP_CLI::log( sprintf( /* translators: 1: scan id, 2: profile id or "active", 3: pages per tick. */ __( '扫描 #%1$d 已启动（profile=%2$s，每 tick %3$d 页）。', 'sakura-imgopt' ), $scan_id, '' !== $profile ? $profile : 'active', $pages ) );

		$result = array();
		do {
			$result = $service->tick( $scan_id, $pages, $seconds );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}

			if ( ! empty( $result['busy'] ) ) {
				\WP_CLI::log( __( '另一个 tick 正在运行，等待…', 'sakura-imgopt' ) );
				usleep( 500000 );
				continue;
			}

			\WP_CLI::log(
				sprintf(
					'%s %3d%% · %s',
					(string) ( $result['state'] ?? 'running' ),
					(int) ( $result['progress'] ?? 0 ),
					(string) ( $result['message'] ?? '' )
				)
			);
		} while ( in_array( (string) ( $result['state'] ?? '' ), array( 'running', 'queued' ), true ) );

		if ( 'failed' === (string) ( $result['state'] ?? '' ) ) {
			\WP_CLI::error( (string) ( $result['message'] ?? __( '扫描失败。', 'sakura-imgopt' ) ) );
		}

		$summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : $this->index->summary( (string) $this->options->active_profile_id() );
		$this->render_summary( $summary );
	}

	/**
	 * Print upload metrics: failure rate, durations, last error.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-sync stats
	 *     wp sakura-sync stats --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>   $args  Positional args.
	 * @param array<string,mixed> $assoc Associative args.
	 */
	public function stats( array $args, array $assoc ): void {
		$snapshot = ( new Upload_Metrics() )->snapshot();

		if ( isset( $assoc['format'] ) && 'json' === $assoc['format'] ) {
			\WP_CLI::line( (string) wp_json_encode( $snapshot ) );
			return;
		}

		$today = $snapshot['today'];
		$week  = $snapshot['week'];
		$last  = $snapshot['last_error'];

		\WP_CLI::line(
			sprintf(
				'Today: %d ok, %d failed, rate %s, avg %s, max %s, %s',
				(int) $today['ok'],
				(int) $today['fail'],
				(string) $today['rate_text'],
				(string) $today['avg_text'],
				(string) $today['max_text'],
				(string) $today['bytes_text']
			)
		);
		\WP_CLI::line(
			sprintf(
				'Last 14 days: %d attempts, %d failed, rate %s, avg %s',
				(int) $week['attempts'],
				(int) $week['fail'],
				(string) $week['rate_text'],
				(string) $week['avg_text']
			)
		);

		if ( ! empty( $last['has'] ) ) {
			\WP_CLI::line( sprintf( 'Last error %s: %s (attachment %d, key %s)', (string) $last['ago'], (string) $last['message'], (int) $last['attachment'], (string) $last['key'] ) );
		}
	}

	/**
	 * Show the latest scan and the pre-aggregated counts.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<id>]
	 * : Profile id. Defaults to the active profile.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function status( array $args, array $assoc ): void {
		$profile = isset( $assoc['profile'] ) ? (string) $assoc['profile'] : (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			\WP_CLI::error( __( '尚未选择 S3 配置。', 'sakura-imgopt' ) );
		}

		$summary = $this->index->summary( $profile );
		$this->render_summary( $summary );

		$rows = array();
		foreach ( array_slice( $this->index->recent_scans( $profile, 5 ), 0, 5 ) as $scan ) {
			if ( ! is_object( $scan ) ) {
				continue;
			}
			$rows[] = array(
				'id'           => (int) $scan->id,
				'state'        => (string) $scan->state,
				'phase'        => (string) $scan->phase,
				'processed'    => (int) $scan->processed,
				'total_local'  => (int) $scan->total_local,
				'total_remote' => (int) $scan->total_remote,
				'started_at'   => (string) $scan->started_at,
				'finished_at'  => (string) ( $scan->finished_at ?? '' ),
			);
		}

		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'state', 'phase', 'processed', 'total_local', 'total_remote', 'started_at', 'finished_at' ) );
		}
	}

	/**
	 * Verify synced objects: local MD5 versus the stored remote ETag.
	 *
	 * Runs in bounded batches and resumes from the stored cursor, so it is
	 * safe for a large library and for a system cron.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<id>]
	 * : Profile id. Defaults to every configured profile.
	 *
	 * [--seconds=<n>]
	 * : Time budget per batch. Default 20 (1-120).
	 *
	 * [--limit=<n>]
	 * : Optional cap on files processed in this invocation.
	 *
	 * [--once]
	 * : Run one batch and stop (for system cron).
	 *
	 * [--reset]
	 * : Re-queue already verified rows (periodic re-check).
	 *
	 * ## EXAMPLES
	 *
	 *     wp sakura-sync verify --once
	 *     wp sakura-sync verify --reset --seconds=60
	 *     wp sakura-sync verify --seconds=60 --limit=10000
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function verify( array $args, array $assoc ): void {
		$profile = isset( $assoc['profile'] ) ? (string) $assoc['profile'] : '';
		$seconds = isset( $assoc['seconds'] ) ? (int) $assoc['seconds'] : 20;
		$limit   = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0;
		$once    = isset( $assoc['once'] );

		if ( $seconds < 1 || $seconds > 120 ) {
			\WP_CLI::error( '--seconds must be between 1 and 120.' );
		}

		if ( isset( $assoc['reset'] ) ) {
			$profiles = '' !== $profile ? array( $profile ) : array_keys( $this->options->profiles() );
			$reset    = 0;
			foreach ( $profiles as $id ) {
				$reset += $this->index->reset_verification( (string) $id );
			}
			\WP_CLI::log( sprintf( /* translators: %d: number of rows. */ __( '已重置 %d 行的校验状态。', 'sakura-imgopt' ), $reset ) );
		}

		$service = new Verify_Service( $this->options, $this->index );
		$totals  = array( 'processed' => 0, 'checked' => 0, 'mismatched' => 0, 'unverifiable' => 0 );
		$rounds  = 0;

		do {
			$result = $service->run( $seconds, $limit, '' !== $profile ? $profile : null );
			++$rounds;

			if ( ! empty( $result['busy'] ) ) {
				\WP_CLI::log( __( '另一个校验进程正在运行，等待 2 秒…', 'sakura-imgopt' ) );
				\sleep( 2 );
				continue;
			}

			foreach ( $totals as $name => $value ) {
				$totals[ $name ] = $value + (int) ( $result[ $name ] ?? 0 );
			}

			\WP_CLI::log(
				sprintf(
					'%s: processed=%d matched=%d mismatch=%d unverifiable=%d done=%s',
					__( '校验批次', 'sakura-imgopt' ),
					(int) $result['processed'],
					(int) $result['checked'],
					(int) $result['mismatched'],
					(int) $result['unverifiable'],
					! empty( $result['done'] ) ? 'yes' : 'no'
				)
			);

			if ( $once || ! empty( $result['done'] ) || 0 === (int) $result['processed'] || $rounds > 5000 ) {
				break;
			}
		} while ( true );

		\WP_CLI::success(
			sprintf(
				'%s: %d checked, %d mismatch, %d unverifiable',
				__( '校验完成', 'sakura-imgopt' ),
				(int) $totals['checked'],
				(int) $totals['mismatched'],
				(int) $totals['unverifiable']
			)
		);
	}

	/**
	 * Rebuild the pre-aggregated status and type counts.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<id>]
	 * : Profile id. Defaults to the active profile.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function rebuild( array $args, array $assoc ): void {
		$profile = isset( $assoc['profile'] ) ? (string) $assoc['profile'] : (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			\WP_CLI::error( __( '尚未选择 S3 配置。', 'sakura-imgopt' ) );
		}

		$this->index->rebuild_counts( $profile );

		$backfilled = 0;
		while ( $this->index->pending_recent_count() > 0 ) {
			$rows = $this->index->backfill_recent( 20000 );
			if ( $rows <= 0 ) {
				break;
			}
			$backfilled += $rows;
		}
		if ( 0 === $this->index->pending_recent_count() ) {
			update_option( Schema::RECENT_READY, '1', false );
		}
		if ( $backfilled > 0 ) {
			\WP_CLI::log( sprintf( 'recent_at backfilled: %d rows', $backfilled ) );
		}

		$this->index->optimize();
		$scan = $this->index->latest_scan( $profile );
		$this->index->rebuild_type_counts( $profile, $scan ? (int) $scan->id : 0 );
		\WP_CLI::success( __( '计数表已重建。', 'sakura-imgopt' ) );
		$this->render_summary( $this->index->summary( $profile ) );
	}

	/**
	 * Apply the retention policy to events, scans and jobs.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function cleanup( array $args, array $assoc ): void {
		$this->index->cleanup();
		\WP_CLI::success( __( '清理完成（事件 30 天、扫描记录 90 天、死任务 30 天）。', 'sakura-imgopt' ) );
	}

	/**
	 * Delete every row a profile owns: index, counts, jobs, scans and events.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<id>]
	 * : Profile id. Defaults to the active profile.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>    $args  Positional args.
	 * @param array<string,string> $assoc Associative args.
	 */
	public function purge( array $args, array $assoc ): void {
		$profile = isset( $assoc['profile'] ) ? (string) $assoc['profile'] : (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			\WP_CLI::error( __( '尚未选择 S3 配置。', 'sakura-imgopt' ) );
		}

		\WP_CLI::confirm( sprintf( /* translators: %s: S3 profile id. */ __( '将删除 profile %s 的全部索引、计数、任务、核对记录与事件日志，继续？', 'sakura-imgopt' ), $profile ), $assoc );
		$this->index->purge_index( $profile );
		\WP_CLI::success( __( '该配置的同步数据已清空。', 'sakura-imgopt' ) );
	}

	/**
	 * Print the summary as key/value rows.
	 *
	 * @param array<string,mixed> $summary Summary.
	 */
	private function render_summary( array $summary ): void {
		$counts = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$bytes  = isset( $summary['bytes'] ) && is_array( $summary['bytes'] ) ? $summary['bytes'] : array();

		$rows = array();
		foreach ( $counts as $status => $items ) {
			$rows[] = array(
				'status' => (string) $status,
				'items'  => (int) $items,
				'bytes'  => (int) ( $bytes[ $status ] ?? 0 ),
			);
		}

		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'items', 'bytes' ) );
		}

		\WP_CLI::log(
			sprintf(
				/* translators: 1: indexed rows, 2: indexed size in MB, 3: local files, 4: local size in MB, 5: cloud objects, 6: cloud size in MB. */
				__( '索引 %1$d 项 / %2$.2f MB · 本地 %3$d 项 / %4$.2f MB · 云端 %5$d 项 / %6$.2f MB', 'sakura-imgopt' ),
				(int) ( $summary['total'] ?? 0 ),
				(float) array_sum( $bytes ) / 1048576,
				(int) ( $summary['local_files'] ?? 0 ),
				(float) ( $summary['local_bytes'] ?? 0 ) / 1048576,
				(int) ( $summary['cloud_objects'] ?? 0 ),
				(float) ( $summary['cloud_bytes'] ?? 0 ) / 1048576
			)
		);
	}
}
