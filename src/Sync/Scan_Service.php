<?php
/**
 * Resumable sync scan service.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\S3\Ignored_Paths;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Streams local files and S3 objects into the sync index.
 *
 * Both phases are resumable:
 * - local uses a deterministic depth-first walk and stores the last relative
 *   path in scans.scan_cursor;
 * - remote stores the S3 ContinuationToken in the same column.
 *
 * A tick never runs longer than its time budget, holds a per-scan advisory
 * lock so AJAX and cron cannot advance the same cursor, and notices a cancel
 * request between batches.
 */
final class Scan_Service {

	/**
	 * Local files per write batch.
	 */
	private const BATCH = 500;

	/**
	 * Hard local file ceiling for a single scan.
	 */
	private const MAX_LOCAL = 250000;

	/**
	 * S3 page size.
	 */
	private const PAGE = 1000;

	/**
	 * Default per-tick time budget in seconds.
	 */
	public const TICK_SECONDS = 20;

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * S3 adapter.
	 *
	 * @var Storage_Adapter
	 */
	private Storage_Adapter $adapter;

	/**
	 * Index.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $index;

	/**
	 * Optional local root override (CLI benchmarks).
	 *
	 * @var string|null
	 */
	private ?string $local_root;

	/** Whether the current scan skips unchanged directories. */
	private bool $incremental = false;

	/** Timestamp of the last completed full scan; 0 disables skipping. */
	private int $cutoff = 0;

	/** Relative directories skipped in this batch. */
	private array $scanned_dirs = array();

	/**
	 * Directory stack named by the cursor being resumed.
	 *
	 * @var string[]
	 */
	private array $resume_stack = array();

	/**
	 * Whether the walk reached the directory named by the cursor.
	 *
	 * @var bool
	 */
	private bool $walk_resumed = false;

	/**
	 * Memoized ignore needles for this instance.
	 *
	 * @var string[]|null
	 */
	private ?array $ignored_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Options          $options    Options.
	 * @param Storage_Adapter  $adapter    Adapter.
	 * @param Index_Repository $index      Index.
	 * @param string|null      $local_root Local root override.
	 */
	public function __construct( Options $options, Storage_Adapter $adapter, Index_Repository $index, ?string $local_root = null ) {
		$this->options    = $options;
		$this->adapter    = $adapter;
		$this->index      = $index;
		$this->local_root = $local_root;
	}

	/**
	 * Start a full scan for the active profile (or a given profile id).
	 *
	 * @param string $profile_id Optional profile to scan instead of the active one.
	 * @return int|\WP_Error
	 */
	public function start( string $profile_id = '', string $mode = 'incremental' ) {
		$profile = $this->validated_profile( $profile_id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$mode = 'full' === $mode ? 'full' : 'incremental';
		return $this->index->start_scan( $profile, $mode );
	}

	/**
	 * Resume the latest failed/cancelled scan.
	 *
	 * @param string $profile_id Optional profile to resume instead of the active one.
	 * @return int|\WP_Error
	 */
	public function resume( string $profile_id = '' ) {
		$profile = $this->validated_profile( $profile_id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		return $this->index->resume_scan( $profile );
	}

	/**
	 * Profile id, validated for scanning.
	 *
	 * @param string $profile_id Explicit profile, or empty for the active one.
	 * @return string|\WP_Error
	 */
	private function validated_profile( string $profile_id = '' ) {
		$profiles = $this->options->profiles();
		if ( '' !== $profile_id && ! isset( $profiles[ $profile_id ] ) ) {
			return new \WP_Error( 'sio_unknown_profile', __( '指定的 S3 配置不存在。', 'sakura-imgopt' ) );
		}
		$profile = '' !== $profile_id ? $profile_id : (string) $this->options->active_profile_id();
		if ( '' === $profile ) {
			return new \WP_Error( 'sio_no_profile', __( '尚未选择 S3 配置。', 'sakura-imgopt' ) );
		}
		$errors = $this->options->configuration_errors();
		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'sio_s3_not_configured', __( 'S3 配置不完整，无法对账。', 'sakura-imgopt' ) );
		}
		return $profile;
	}

	/**
	 * Recompute statuses and counts from the existing index without listing S3.
	 *
	 * This is the "refresh differences" action: it is cheap (one CASE update and
	 * two aggregates) and never touches object storage.
	 *
	 * @param string $profile_id Profile override.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function refresh( string $profile_id = '' ) {
		$profile = $this->validated_profile( $profile_id );
		if ( is_wp_error( $profile ) ) {
			return $profile;
		}
		$this->index->recalculate( $profile );
		$this->index->rebuild_counts( $profile );
		$summary = $this->index->summary( $profile );
		$this->index->event( $profile, 'info', 'refresh', __( '已按现有索引重算差异。', 'sakura-imgopt' ) );
		return $summary;
	}

	/**
	 * Refresh every configured profile (daily lightweight reconciliation).
	 *
	 * @return array<string,mixed>
	 */
	public function refresh_all(): array {
		$out = array();
		foreach ( $this->options->profiles() as $profile_id => $profile ) {
			$result = $this->refresh( (string) $profile_id );
			if ( ! is_wp_error( $result ) ) {
				$out[ (string) $profile_id ] = $result;
			}
		}
		return $out;
	}

	/**
	 * Run a scan to completion (CLI and manual fallback).
	 *
	 * @param int $scan_id Scan.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run( int $scan_id ) {
		$result = $this->tick( $scan_id, 0, self::TICK_SECONDS );
		while ( ! is_wp_error( $result ) && 'running' === ( $result['state'] ?? '' ) ) {
			if ( ! empty( $result['busy'] ) ) {
				usleep( 250000 );
			}
			$result = $this->tick( $scan_id, 0, self::TICK_SECONDS );
		}
		return $result;
	}

	/**
	 * Process one resumable step.
	 *
	 * @param int $scan_id     Scan.
	 * @param int $max_pages   Cloud pages to process; 0 means unlimited.
	 * @param int $max_seconds Time budget; 0 means unlimited.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function tick( int $scan_id, int $max_pages = 0, int $max_seconds = 0 ) {
		$scan = $this->index->scan( $scan_id );
		if ( ! $scan ) {
			return new \WP_Error( 'sio_scan_missing', __( '对账任务不存在。', 'sakura-imgopt' ) );
		}
		if ( 'done' === $scan->state ) {
			return array( 'state' => 'done', 'progress' => 100, 'message' => __( '对账已完成。', 'sakura-imgopt' ) );
		}
		if ( 'cancelled' === $scan->state ) {
			return array( 'state' => 'cancelled', 'progress' => $this->progress_for( $scan ), 'message' => __( '对账已取消。', 'sakura-imgopt' ) );
		}
		if ( 'failed' === $scan->state ) {
			return new \WP_Error( 'sio_scan_failed', (string) $scan->error_message );
		}

		if ( ! $this->index->acquire_tick_lock( $scan_id ) ) {
			return array(
				'state'    => 'running',
				'busy'     => true,
				'progress' => $this->progress_for( $scan ),
				'phase'    => (string) $scan->phase,
				'message'  => __( '对账正在另一个请求中推进…', 'sakura-imgopt' ),
			);
		}

		$deadline = $max_seconds > 0 ? microtime( true ) + $max_seconds : 0.0;

		try {
			return $this->advance( $scan_id, $max_pages, $deadline );
		} finally {
			$this->index->release_tick_lock( $scan_id );
		}
	}

	/**
	 * Advance one phase within the budget.
	 *
	 * @param int   $scan_id   Scan.
	 * @param int   $max_pages Cloud pages.
	 * @param float $deadline  Absolute microtime deadline (0 = none).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function advance( int $scan_id, int $max_pages, float $deadline ) {
		$scan    = $this->index->scan( $scan_id );
		$profile = (string) $scan->profile_id;
		$phase   = (string) $scan->phase;

		if ( '' === $phase ) {
			$this->index->reset_seen( $profile );
			$this->index->update_scan(
				$scan_id,
				array(
					'phase'       => 'local',
					'processed'   => 0,
					'scan_cursor' => '',
					'total_local' => 0,
					'local_bytes' => 0,
				)
			);
			$phase = 'local';
			$this->index->event( $profile, 'info', 'scan_start', __( '开始全量对账。', 'sakura-imgopt' ) );
		}

		if ( 'local' === $phase ) {
			$local = $this->local_phase( $scan_id, $deadline );
			if ( is_wp_error( $local ) ) {
				return $this->abort( $scan_id, $profile, $local );
			}
			if ( ! empty( $local['cancelled'] ) ) {
				return $this->cancelled( $scan_id, $profile );
			}
			if ( ! $local['done'] ) {
				return array(
					'state'    => 'running',
					'phase'    => 'local',
					'progress' => $this->progress_for( $this->index->scan( $scan_id ) ),
					'message'  => __( '正在扫描本地文件…', 'sakura-imgopt' ),
				);
			}
			$this->index->update_scan(
				$scan_id,
				array(
					'phase'       => 'remote',
					'total_local' => (int) $local['files'],
					'local_bytes' => (int) $local['bytes'],
					'processed'   => (int) $local['files'],
					'scan_cursor' => '',
				)
			);
			$phase = 'remote';
			$scan  = $this->index->scan( $scan_id );
		}

		if ( 'remote' === $phase ) {
			$remote = $this->remote_phase( $profile, $scan_id, $max_pages, $deadline );
			if ( is_wp_error( $remote ) ) {
				return $this->abort( $scan_id, $profile, $remote );
			}
			if ( ! empty( $remote['cancelled'] ) ) {
				return $this->cancelled( $scan_id, $profile );
			}
			if ( ! $remote['done'] ) {
				return array(
					'state'    => 'running',
					'phase'    => 'remote',
					'progress' => $this->progress_for( $this->index->scan( $scan_id ) ),
					'message'  => __( '正在列举云端对象…', 'sakura-imgopt' ),
				);
			}
			$this->index->update_scan( $scan_id, array( 'phase' => 'reconcile' ) );
		}

		return $this->finalize( $scan_id, $profile );
	}

	/**
	 * Local phase with cursor-miss recovery.
	 *
	 * @param int   $scan_id  Scan.
	 * @param float $deadline Deadline.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function local_phase( int $scan_id, float $deadline ) {
		$scan   = $this->index->scan( $scan_id );
		$cursor = (string) $scan->scan_cursor;
		$files  = (int) $scan->processed;
		$bytes  = (int) $scan->local_bytes;
		$retry  = false;

		while ( true ) {
			$result = $this->local_batch( $scan_id, $cursor, $files, $bytes, $deadline );
			if ( is_wp_error( $result ) || ! empty( $result['cursor_missing'] ) ) {
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( $retry ) {
					return new \WP_Error( 'sio_local_cursor', __( '本地游标失效，无法继续对账。', 'sakura-imgopt' ) );
				}
				// The file at the cursor disappeared between ticks. Restart the
				// local phase from the beginning; upserts are idempotent.
				$retry  = true;
				$cursor = '';
				$files  = 0;
				$bytes  = 0;
				$this->index->update_scan( $scan_id, array( 'processed' => 0, 'local_bytes' => 0, 'scan_cursor' => '' ) );
				continue;
			}
			return $result;
		}
	}

	/**
	 * Stream local files from the cursor until a batch boundary, a budget, or
	 * the end of the tree.
	 *
	 * The cursor is a small JSON envelope `{"d":["2026","11"],"a":"foo.jpg"}`
	 * holding the directory stack plus the last entry inside it, so a resumed
	 * batch jumps to its position instead of walking the tree from the root
	 * again. A legacy plain-path cursor fails validation once and
	 * local_phase() restarts the phase, which upserts make harmless.
	 *
	 * @param int    $scan_id  Scan.
	 * @param string $cursor   Encoded cursor (empty on the first batch).
	 * @param int    $files    Files already counted.
	 * @param int    $bytes    Bytes already counted.
	 * @param float  $deadline Deadline.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function local_batch( int $scan_id, string $cursor, int $files, int $bytes, float $deadline ) {
		$base = $this->local_base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$resume = $this->decode_cursor( $cursor );
		if ( null === $resume ) {
			return array( 'cursor_missing' => true );
		}
		list( $stack, $after ) = $resume;

		$scan               = $this->index->scan( $scan_id );
		$profile            = (string) ( $scan ? $scan->profile_id : '' );
		$this->incremental  = $scan && 'incremental' === (string) $scan->mode;
		$this->cutoff       = $this->incremental ? (int) get_option( 'sakura_imgopt_sync_full_at', 0 ) : 0;
		$this->scanned_dirs = array();
		$prefix      = $this->key_prefix();
		$batch       = array();
		$batch_bytes = 0;
		$last        = $cursor;

		$this->resume_stack = $stack;
		$this->walk_resumed = false;

		foreach ( $this->walk_local( $base, $stack, $after ) as $file ) {
			++$files;
			if ( $files > self::MAX_LOCAL ) {
				return new \WP_Error(
					'sio_local_limit',
					sprintf(
						/* translators: %d: file limit. */
						__( '本地文件超过 %d 个，已停止本次对账；请使用 WP-CLI 分批扫描。', 'sakura-imgopt' ),
						self::MAX_LOCAL
					)
				);
			}

			$key          = '' === $prefix ? $file['rel'] : $prefix . '/' . $file['rel'];
			$batch[]      = array(
				'key'   => $key,
				'path'  => $key,
				'size'  => (int) $file['size'],
				'mtime' => (int) $file['mtime'],
			);
			$batch_bytes += max( 0, (int) $file['size'] );
			$last         = $file['cursor'];

			if ( count( $batch ) < self::BATCH ) {
				continue;
			}

			$error = $this->flush_local( $profile, $scan_id, $batch, $files, $bytes + $batch_bytes, $last );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$bytes      += $batch_bytes;
			$batch       = array();
			$batch_bytes = 0;

			if ( 'cancelled' === $this->index->state( $scan_id ) ) {
				return array( 'done' => false, 'cancelled' => true, 'files' => $files, 'bytes' => $bytes );
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				return array( 'done' => false, 'files' => $files, 'bytes' => $bytes );
			}
		}

		if ( ! $this->walk_resumed ) {
			// The directory the cursor pointed at is gone.
			return array( 'cursor_missing' => true );
		}

		if ( $batch ) {
			$error = $this->flush_local( $profile, $scan_id, $batch, $files, $bytes + $batch_bytes, $last );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$bytes += $batch_bytes;
		}

		if ( $this->scanned_dirs ) {
			$this->index->mark_dirs_scanned( $profile, $scan_id, array_keys( $this->scanned_dirs ) );
		}

		return array(
			'done'   => true,
			'files'  => $files,
			'bytes'  => $bytes,
			'cursor' => $last,
		);
	}

	/**
	 * Persist one local batch with its progress.
	 *
	 * @param string                         $profile Profile.
	 * @param int                            $scan_id Scan.
	 * @param array<int,array<string,mixed>> $batch   Rows.
	 * @param int                            $files   Files so far.
	 * @param int                            $bytes   Bytes so far.
	 * @param string                         $last    Last relative path.
	 * @return int|\WP_Error
	 */
	private function flush_local( string $profile, int $scan_id, array $batch, int $files, int $bytes, string $last ) {
		$started = microtime( true );
		$written = $this->index->upsert_local( $profile, $scan_id, $batch );
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		Metrics::bump(
			array(
				'batches'  => 1,
				'rows'     => count( $batch ),
				'batch_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			)
		);
		$this->index->update_scan(
			$scan_id,
			array(
				'processed'   => $files,
				'local_bytes' => $bytes,
				'scan_cursor' => $last,
			)
		);
		return $written;
	}

	/**
	 * Walk the uploads tree in a deterministic, resumable order.
	 *
	 * Entries are visited in strcmp order per directory and a directory is
	 * entered at its sorted position. A cursor of (directory stack, entry name)
	 * therefore names a resume point: the walker descends straight to that
	 * directory and starts after that entry instead of re-reading the tree.
	 *
	 * @param string   $dir   Absolute directory.
	 * @param string[] $stack Directory names from the root to $dir.
	 * @param string   $after Last processed entry name inside the resume directory.
	 * @param string   $root  Absolute root (filled in on the first call).
	 * @param int      $depth Depth guard.
	 * @return \Generator<int,array{rel:string,size:int,mtime:int,cursor:string}>
	 * @phpstan-impure
	 */
	private function walk_local( string $dir, array $stack = array(), string $after = '', string $root = '', int $depth = 0 ): \Generator {
		if ( 0 === $depth && '' === $root ) {
			$root = $dir;
		}
		if ( $depth > 64 ) {
			return;
		}
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable directories are skipped.
		if ( ! is_array( $entries ) ) {
			return;
		}
		sort( $entries, SORT_STRING );

		$dir_rel = ltrim( substr( $dir, strlen( $root ) ), '/\\' );
		$dir_rel = str_replace( '\\', '/', $dir_rel );

		// A directory's mtime changes when an entry is added or removed, so an
		// unchanged directory cannot hold new or deleted files. Skipping the
		// per-file stats there is what makes rescanning a large library cheap.
		// Rows are re-stamped by mark_dirs_scanned() so the purge keeps them.
		$skip_files = $this->incremental && $this->cutoff > 0 && '' !== $dir_rel
			&& (int) @filemtime( $dir ) < $this->cutoff; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable directories are walked.
		if ( $skip_files ) {
			// Index rows store dirname( object_key ), which includes the key
			// prefix, so the stamp target has to as well.
			$prefix              = $this->key_prefix();
			$store_dir           = '' === $prefix ? $dir_rel : ( '' === $dir_rel ? $prefix : $prefix . '/' . $dir_rel );
			$this->scanned_dirs[ $store_dir ] = true;
		}

		$resume    = $this->resume_stack;
		$resume_at = $depth >= count( $resume ); // The cursor directory has been reached.
		if ( $resume_at ) {
			$this->walk_resumed = true;
		}

		$skip_until = $resume_at ? $after : '';

		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			if ( '' !== $skip_until && strcmp( $name, $skip_until ) <= 0 ) {
				continue;
			}
			$path = $dir . '/' . $name;

			if ( ! $resume_at ) {
				$want = $resume[ $depth ];
				$cmp  = strcmp( $name, $want );
				if ( $cmp < 0 ) {
					continue;
				}
				if ( $cmp > 0 ) {
					// The cursor directory vanished.
					return;
				}
				if ( is_link( $path ) || ! is_dir( $path ) ) {
					return;
				}
				$child   = $stack;
				$child[] = $name;
				yield from $this->walk_local( $path, $child, $after, $root, $depth + 1 );
				// Siblings after the cursor directory are walked in full.
				$resume_at  = true;
				$skip_until = '';
				continue;
			}

			if ( is_link( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$child   = $stack;
				$child[] = $name;
				yield from $this->walk_local( $path, $child, '', $root, $depth + 1 );
				continue;
			}
			if ( $skip_files ) {
				continue;
			}
			if ( ! is_file( $path ) ) {
				continue;
			}
			$rel = ltrim( substr( $path, strlen( $root ) ), '/\\' );
			$rel = str_replace( '\\', '/', $rel );
			if ( $this->is_ignored_key( $rel ) ) {
				continue;
			}
			$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with deletion is tolerated.
			if ( false === $size ) {
				continue;
			}
			yield array(
				'rel'    => $rel,
				'size'   => max( 0, (int) $size ),
				'mtime'  => (int) ( @filemtime( $path ) ?: 0 ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- See above.
				'cursor' => (string) wp_json_encode(
					array(
						'd' => array_values( $stack ),
						'a' => $name,
					)
				),
			);
		}
	}

	/**
	 * Decode a scan cursor.
	 *
	 * @param string $cursor Raw cursor.
	 * @return array{0:string[],1:string}|null Null when the cursor is not a
	 *                                           valid envelope (legacy path).
	 */
	private function decode_cursor( string $cursor ) {
		if ( '' === $cursor ) {
			return array( array(), '' );
		}

		$data = json_decode( $cursor, true );
		if ( ! is_array( $data ) || ! isset( $data['d'], $data['a'] ) || ! is_array( $data['d'] ) || ! is_string( $data['a'] ) ) {
			return null;
		}

		$stack = array();
		foreach ( $data['d'] as $part ) {
			if ( ! is_string( $part ) || '' === $part ) {
				return null;
			}
			$stack[] = $part;
		}

		return array( $stack, $data['a'] );
	}

	/**
	 * Absolute local root.
	 *
	 * @return string|\WP_Error
	 */
	private function local_base() {
		if ( null !== $this->local_root ) {
			$base = rtrim( $this->local_root, '/\\' );
			return is_dir( $base ) ? $base : new \WP_Error( 'sio_local_root', __( '本地目录不存在。', 'sakura-imgopt' ) );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'sio_uploads_unavailable', (string) $uploads['error'] );
		}
		$base = (string) $uploads['basedir'];
		return is_dir( $base ) ? $base : new \WP_Error( 'sio_uploads_unavailable', __( '上传目录不可用。', 'sakura-imgopt' ) );
	}

	/**
	 * List S3 pages into the index until done, the page cap, or the budget.
	 *
	 * @param string $profile   Profile.
	 * @param int    $scan_id   Scan.
	 * @param int    $max_pages Page cap (0 = unlimited).
	 * @param float  $deadline  Deadline.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function remote_phase( string $profile, int $scan_id, int $max_pages, float $deadline ) {
		$prefix = $this->key_prefix();
		$scan   = $this->index->scan( $scan_id );
		$token  = (string) $scan->scan_cursor;
		$base   = (int) $scan->total_remote;
		$bytes  = (int) $scan->remote_bytes;
		$types  = array(
			'image'   => (int) $scan->cloud_image,
			'video'   => (int) $scan->cloud_video,
			'archive' => (int) $scan->cloud_archive,
		);
		$pages  = 0;
		$before = $this->progress_estimate( $scan );

		do {
			$page = $this->list_page_with_retry( $prefix, $token );
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			$batch = array();
			$added = 0;
			foreach ( (array) $page['objects'] as $item ) {
				$key = (string) $item['key'];
				if ( $this->is_ignored_key( $key ) ) {
					continue;
				}
				$batch[] = array(
					'key'      => $key,
					'size'     => (int) $item['size'],
					'modified' => (int) $item['modified'],
					'etag'     => (string) ( $item['etag'] ?? '' ),
				);
				$bytes += max( 0, (int) $item['size'] );
				++$added;
				$kind = Kind::of( $key );
				if ( isset( $types[ $kind ] ) ) {
					++$types[ $kind ];
				}
			}

			if ( $batch ) {
				$written = $this->index->upsert_remote( $profile, $scan_id, $batch );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}

			$base  += $added;
			$token  = (string) $page['next_token'];
			++$pages;
			Metrics::bump( array( 'pages' => 1 ) );

			$this->index->update_scan(
				$scan_id,
				array(
					'processed'    => (int) $scan->total_local + $base,
					'total_remote' => $base,
					'remote_bytes' => $bytes,
					'cloud_image'  => $types['image'],
					'cloud_video'  => $types['video'],
					'cloud_archive'=> $types['archive'],
					'scan_cursor'  => $token,
				)
			);

			if ( 'cancelled' === $this->index->state( $scan_id ) ) {
				return array( 'done' => false, 'cancelled' => true );
			}
			if ( $max_pages > 0 && $pages >= $max_pages ) {
				break;
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				break;
			}
		} while ( '' !== $token );

		return array(
			'done'     => '' === $token,
			'objects'  => $base,
			'bytes'    => $bytes,
			'estimate' => max( $base + ( '' === $token ? 0 : self::PAGE ), $before ),
		);
	}

	/**
	 * List one page with 1/2/4 second backoff retries.
	 *
	 * @param string $prefix Key prefix.
	 * @param string $token  Continuation token.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function list_page_with_retry( string $prefix, string $token ) {
		$delays = array( 0, 1, 2, 4 );
		$last   = null;
		foreach ( $delays as $delay ) {
			if ( $delay > 0 ) {
				sleep( $delay );
			}
			$page = $this->adapter->list_page( $prefix, $token, self::PAGE );
			if ( ! is_wp_error( $page ) ) {
				return $page;
			}
			$message = strtolower( $page->get_error_message() );
			if ( str_contains( $message, '429' ) || str_contains( $message, 'slow down' ) || str_contains( $message, 'throttl' ) ) {
				Metrics::bump( array( 's3_429' => 1 ) );
			} elseif ( preg_match( '/\b5\d\d\b/', $message ) ) {
				Metrics::bump( array( 's3_5xx' => 1 ) );
			}
			$last = $page;
		}
		return $last;
	}

	/**
	 * Reconciliation and counters, then close the scan.
	 *
	 * @param int    $scan_id Scan.
	 * @param string $profile Profile.
	 * @return array<string,mixed>
	 */
	private function finalize( int $scan_id, string $profile ): array {
		$scan      = $this->index->scan( $scan_id );
		if ( $scan && 'full' === (string) $scan->mode ) {
			update_option( 'sakura_imgopt_sync_full_at', time(), false );
		}
		$started   = $scan && $scan->started_at ? strtotime( (string) $scan->started_at . ' UTC' ) : time();
		$elapsed   = max( 0, time() - (int) $started );
		Metrics::bump( array( 'scans' => 1, 'scan_ms' => $elapsed * 1000 ) );
		$this->index->reconcile( $profile, $scan_id );
		$this->index->purge_stale( $profile, $scan_id );
		$this->index->rebuild_counts( $profile );
		$this->index->optimize();
		$summary = $this->index->summary( $profile );
		$this->index->finish_scan( $scan_id, (int) $summary['total'] );
		$summary = $this->index->summary( $profile );
		$this->index->event(
			$profile,
			'info',
			'scan_done',
			sprintf(
				/* translators: 1: local files, 2: cloud objects, 3: differences. */
				__( '全量对账完成：本地 %1$d 个、云端 %2$d 个、差异 %3$d 个。', 'sakura-imgopt' ),
				(int) $summary['local_files'],
				(int) $summary['cloud_objects'],
				(int) $summary['total']
			),
			array( 'scan_id' => $scan_id )
		);

		return array(
			'state'    => 'done',
			'progress' => 100,
			'summary'  => $summary,
			'message'  => __( '对账完成。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Mark failed and emit the failure event.
	 *
	 * @param int       $scan_id Scan.
	 * @param string    $profile Profile.
	 * @param \WP_Error $error   Error.
	 * @return \WP_Error
	 */
	private function abort( int $scan_id, string $profile, \WP_Error $error ): \WP_Error {
		$this->index->fail_scan( $scan_id, $error->get_error_code(), $error->get_error_message() );
		$this->index->event(
			$profile,
			'error',
			'scan_failed',
			$error->get_error_message(),
			array( 'scan_id' => $scan_id, 'code' => $error->get_error_code() )
		);
		return $error;
	}

	/**
	 * Cancel path: release the profile lock and emit an event.
	 *
	 * @param int    $scan_id Scan.
	 * @param string $profile Profile.
	 * @return array<string,mixed>
	 */
	private function cancelled( int $scan_id, string $profile ): array {
		$this->index->release_scan_lock( $scan_id );
		$this->index->event( $profile, 'warning', 'scan_cancelled', __( '对账已取消。', 'sakura-imgopt' ), array( 'scan_id' => $scan_id ) );
		return array(
			'state'    => 'cancelled',
			'progress' => $this->progress_for( $this->index->scan( $scan_id ) ),
			'message'  => __( '对账已取消，可稍后继续。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Progress percentage from persisted phase/counters.
	 *
	 * @param object|null $scan Scan row.
	 */
	public function progress_for( ?object $scan ): int {
		if ( ! $scan ) {
			return 0;
		}
		$phase = (string) $scan->phase;
		if ( 'done' === $scan->state || 'done' === $phase ) {
			return 100;
		}
		if ( '' === $phase ) {
			return 2;
		}
		if ( 'local' === $phase ) {
			$estimate = (int) $scan->total_local;
			if ( $estimate <= 0 && isset( $scan->id ) ) {
				$previous = $this->index->completed_scan_before( (string) $scan->profile_id, (int) $scan->id );
				$estimate = $previous ? (int) $previous->total_local : 0;
			}
			if ( $estimate <= 0 ) {
				return 10;
			}
			return (int) min( 45, 5 + ( 40 * ( (int) $scan->processed / max( 1, $estimate ) ) ) );
		}
		if ( 'remote' === $phase ) {
			$before   = $this->progress_estimate( $scan );
			$estimate = max( $before, (int) $scan->total_remote );
			if ( $estimate <= 0 ) {
				return 55;
			}
			return (int) min( 98, 50 + ( 48 * ( (int) $scan->total_remote / max( 1, $estimate ) ) ) );
		}
		return 95;
	}

	/**
	 * Best cloud object estimate from the previous completed scan.
	 *
	 * @param object $scan Current scan.
	 */
	private function progress_estimate( object $scan ): int {
		$previous = $this->index->completed_scan_before( (string) $scan->profile_id, (int) $scan->id );
		return $previous ? (int) $previous->total_remote : 0;
	}

	/**
	 * Path prefixes that must never enter the sync index.
	 *
	 * @return string[]
	 */
	private function ignored_prefixes(): array {
		if ( null !== $this->ignored_cache ) {
			return $this->ignored_cache;
		}

		$defaults = array(
			'/sakura-imgopt-backup/',
			'/.sakura-s3-cache/',
		);

		// Extra rules saved on the sync center page.
		$defaults = array_merge( $defaults, Ignored_Paths::all() );

		$this->ignored_cache = (array) apply_filters( 'sakura_imgopt_sync_ignored_prefixes', $defaults );

		return $this->ignored_cache;
	}

	/**
	 * Whether an object key belongs to an ignored path.
	 *
	 * A needle matches the key itself or any of its parents, so both
	 * `/2024/05/` (folder) and `/2024/05/photo.jpg` (single file) work.
	 *
	 * @param string $key Object key.
	 */
	private function is_ignored_key( string $key ): bool {
		$key = '/' . trim( str_replace( '\\', '/', $key ), '/' );

		foreach ( $this->ignored_prefixes() as $needle ) {
			$needle = '/' . trim( (string) $needle, '/' );

			if ( '/' === $needle ) {
				continue;
			}

			if ( $key === $needle || 0 === strpos( $key, $needle . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Object-key prefix for the current upload path.
	 */
	private function key_prefix(): string {
		$path = trim( (string) $this->options->upload_path(), '/' );
		return '.' === $path ? '' : $path;
	}
}
