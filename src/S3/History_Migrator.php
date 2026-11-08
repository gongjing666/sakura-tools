<?php
/**
 * Batched, resumable migration of the existing media library to object storage.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans attachments by ascending ID and uploads each attachment inside the
 * request. A large library becomes a sequence of short, resumable batches with
 * a per-batch time budget instead of one unbounded AJAX call.
 */
final class History_Migrator {

	public const STATE_OPTION = 'sakura_imgopt_s3_migrate_state';
	public const DEFAULT_BATCH = 500;
	public const MAX_BATCH     = 2000;
	public const DEFAULT_SECONDS = 20.0;
	public const LOCK_PREFIX   = 'sio_hist_';

	private Options $options;
	private Storage_Adapter $adapter;
	private Sync_Status $status;

	/**
	 * Constructor.
	 *
	 * @param Options              $options Options.
	 * @param Storage_Adapter      $adapter Adapter.
	 * @param Sync_Status|null     $status  Status store.
	 */
	public function __construct( Options $options, Storage_Adapter $adapter, ?Sync_Status $status = null ) {
		$this->options = $options;
		$this->adapter = $adapter;
		$this->status  = $status ?? new Sync_Status();
	}

	/**
	 * Default persisted state.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'status'      => 'idle',
			'cursor'      => 0,
			'target_hash' => '',
			'total'       => 0,
			'processed'   => 0,
			'queued'      => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'started_at'  => 0,
			'updated_at'  => 0,
			'finished_at' => 0,
			'last_error'  => '',
		);
	}

	/**
	 * Normalize a stored state.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string,mixed>
	 */
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::defaults();
		foreach ( $out as $key => $default ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			$out[ $key ] = is_int( $default ) ? max( 0, (int) $value ) : ( is_scalar( $value ) ? (string) $value : $default );
		}
		if ( ! in_array( $out['status'], array( 'idle', 'running', 'done', 'failed' ), true ) ) {
			$out['status'] = 'idle';
		}
		return $out;
	}

	/**
	 * Persisted state for UI/CLI without constructing services.
	 *
	 * @return array<string,mixed>
	 */
	public static function saved_state(): array {
		return self::normalize( get_option( self::STATE_OPTION, array() ) );
	}

	/**
	 * Current state.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		return self::saved_state();
	}

	/**
	 * Reset the cursor so the next batch starts from zero.
	 */
	public function reset( bool $persist = true ): void {
		if ( ! $persist ) {
			return;
		}
		$this->save(
			array_merge(
				self::defaults(),
				array(
					'status'     => 'idle',
					'updated_at' => time(),
				)
			)
		);
	}

	/**
	 * Process attachments until the time budget or ID space is exhausted.
	 *
	 * Each selected attachment is uploaded and verified before the batch moves on.
	 *
	 * @param bool  $reset        Restart from ID 0.
	 * @param int   $batch_size   IDs per page (clamped).
	 * @param float $max_seconds  Wall-clock budget for this call.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function run_batch( bool $reset = false, int $batch_size = self::DEFAULT_BATCH, float $max_seconds = self::DEFAULT_SECONDS, bool $dry_run = false ) {
		if ( ! $this->options->is_enabled() ) {
			return new \WP_Error( 'sio_migrate_disabled', __( 'S3 存储未启用，已拒绝同步历史附件。', 'sakura-imgopt' ) );
		}
		if ( ! $this->options->is_configured() ) {
			return new \WP_Error( 'sio_migrate_config', __( 'S3 配置不完整，无法同步历史附件。', 'sakura-imgopt' ) );
		}

		$batch_size  = max( 25, min( self::MAX_BATCH, $batch_size ) );
		$max_seconds = max( 1.0, min( 120.0, $max_seconds ) );
		$deadline    = microtime( true ) + $max_seconds;

		if ( ! $this->lock() ) {
			return new \WP_Error( 'sio_migrate_busy', __( '另一个迁移批次正在运行，请稍后重试。', 'sakura-imgopt' ) );
		}

		try {
			$state = $this->state();

			// A cursor only means something for the bucket it was collected from.
			// Switching provider/bucket/endpoint starts a fresh walk instead of
			// silently skipping everything below the old cursor.
			$target = \Sakura\ImageOptimizer\S3\Synchronous_Uploader::target_hash_for( $this->options );

			if ( '' !== $target && $target !== (string) $state['target_hash'] ) {
				$state                = self::defaults();
				$state['target_hash'] = $target;
			}

			if ( $reset || 'idle' === $state['status'] ) {
				$state = array_merge(
					self::defaults(),
					array(
						'status'     => 'running',
						'total'      => $this->count_attachments(),
						'started_at' => time(),
					)
				);
			} elseif ( 'done' === $state['status'] ) {
				return $this->response( $state, true );
			} else {
				$state['status'] = 'running';
				if ( 0 === $state['total'] ) {
					$state['total'] = $this->count_attachments();
				}
			}

			$state['updated_at'] = time();
			$done                = false;

			while ( microtime( true ) < $deadline ) {
				$ids = $this->batch_ids( (int) $state['cursor'], $batch_size );
				if ( ! $ids ) {
					$done = true;
					break;
				}

				foreach ( $ids as $attachment_id ) {
					if ( microtime( true ) >= $deadline ) {
						break 2;
					}
					$attachment_id = (int) $attachment_id;
					++$state['processed'];

					$outcome = $this->enqueue_attachment( $attachment_id, $dry_run );
					if ( 'synced' === $outcome ) {
						++$state['queued'];
					} elseif ( 'failed' === $outcome ) {
						++$state['failed'];
						$state['last_error'] = sprintf(
							/* translators: %d: attachment ID. */
							__( '附件 #%d 的文件无法映射或入队失败。', 'sakura-imgopt' ),
							$attachment_id
						);
					} else {
						++$state['skipped'];
					}

					$state['cursor'] = $attachment_id;
				}

				$state['updated_at'] = time();
				if ( ! $dry_run ) {
					$this->save( $state );
				}

				if ( count( $ids ) < $batch_size ) {
					$done = true;
					break;
				}
			}

			if ( $done ) {
				$state['status']      = 'done';
				$state['finished_at'] = time();
			}

			if ( ! $dry_run ) {
				$this->save( $state );
			}
			$response           = $this->response( $state, $done );
			$response['dry_run'] = $dry_run;
			return $response;
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Query attachment IDs after a cursor.
	 *
	 * @param int $cursor Last processed ID.
	 * @param int $limit  Page size.
	 * @return int[]
	 */
	private function batch_ids( int $cursor, int $limit ): array {
		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => $limit,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		add_filter(
			'posts_where',
			$where_filter = static function ( $where ) use ( $cursor ) {
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor );
			}
		);
		$query = new \WP_Query();
		$ids   = $query->query( $query_args );
		remove_filter( 'posts_where', $where_filter );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Attachment count shown in progress.
	 */
	private function count_attachments(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'" );
	}

	/**
	 * Whether a migration may remove the local copy after uploading it.
	 *
	 * H-03: the S3 page and the CLI both promise "迁移始终保留本地文件". Reading
	 * `no_local_saving` here silently deleted every local file right after its
	 * historical copy was uploaded — one click turned the library into
	 * remote-only with no rollback. Keep the decision in one testable place.
	 */
	public static function deletes_local_after_migration(): bool {
		return false;
	}

	/**
	 * Upload and verify one attachment's existing files in this request.
	 *
	 * Historical migration always preserves the local copy, so it can be
	 * repeated and rolled back without a second remote copy.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $dry_run       Count without writing.
	 * @return string synced|skipped|failed
	 */
	private function enqueue_attachment( int $attachment_id, bool $dry_run = false ): string {
		$paths = (array) $this->adapter->attachment_files( $attachment_id );
		$files = 0;
		$bad   = false;

		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( '' === $path || ! is_file( $path ) ) {
				continue;
			}
			$key = $this->adapter->key_from_path( $path );
			if ( ! is_string( $key ) || '' === $key ) {
				$bad = true;
				continue;
			}
			++$files;
		}

		if ( 0 === $files ) {
			return $bad ? 'failed' : 'skipped';
		}

		if ( $dry_run ) {
			return 'synced';
		}

		$uploader = new Synchronous_Uploader( $this->options, $this->adapter, $this->status );
		$result   = $uploader->finalize( $attachment_id, null, true, true );
		return in_array( $result['status'], array( 'synced', 'skipped' ), true ) ? 'synced' : 'failed';
	}

	/**
	 * Shape the JSON/CLI payload.
	 *
	 * @param array<string,mixed> $state State.
	 * @param bool                $done  Done.
	 * @return array<string,mixed>
	 */
	private function response( array $state, bool $done ): array {
		$total     = max( 0, (int) $state['total'] );
		$processed = max( 0, (int) $state['processed'] );

		return array(
			'status'      => $state['status'],
			'cursor'      => (int) $state['cursor'],
			'total'       => $total,
			'processed'   => $processed,
			'queued'      => (int) $state['queued'],
			'skipped'     => (int) $state['skipped'],
			'failed'      => (int) $state['failed'],
			'percent'     => $total > 0 ? min( 100, (int) round( $processed / $total * 100 ) ) : 0,
			'done'        => $done,
			'started_at'  => (int) $state['started_at'],
			'finished_at' => (int) $state['finished_at'],
			'message'     => $done
				? sprintf(
					/* translators: 1: processed attachments, 2: queued attachments, 3: skipped, 4: failed. */
					__( '扫描完成：%1$d 个附件，已上传 %2$d，跳过 %3$d，失败 %4$d。本地文件已保留。', 'sakura-imgopt' ),
					$processed,
					(int) $state['queued'],
					(int) $state['skipped'],
					(int) $state['failed']
				)
				: sprintf(
					/* translators: 1: processed attachments, 2: total attachments. */
					__( '已扫描 %1$d / %2$d 个附件…', 'sakura-imgopt' ),
					$processed,
					$total
				),
		);
	}

	/**
	 * Persist state.
	 *
	 * @param array<string,mixed> $state State.
	 */
	private function save( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Cross-request lock so AJAX and CLI cannot scan the same range twice.
	 */
	private function lock(): bool {
		global $wpdb;
		$name = self::LOCK_PREFIX . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . $wpdb->prefix ), 0, 20 );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
	}

	/**
	 * Release the lock.
	 */
	private function unlock(): void {
		global $wpdb;
		$name = self::LOCK_PREFIX . substr( md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . $wpdb->prefix ), 0, 20 );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
