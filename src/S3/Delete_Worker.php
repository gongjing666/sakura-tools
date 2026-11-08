<?php
/**
 * Bounded remote-delete compensation worker.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Drains only Delete_Jobs. No upload, upload retry or watchdog entry points.
 */
final class Delete_Worker implements Module {

	public const HOOK = 'sakura_imgopt_s3_delete_tick';
	public const SCHEDULE = 'sakura_imgopt_1min';
	private const MAX_SECONDS = 20;
	private const MAX_JOBS = 20;
	private const STALE_SECONDS = 600;
	private const DELETE_RECHECK_SECONDS = 300;
	private const LOCK_OPTION = 'sakura_imgopt_lock_';
	private const LOCK_TTL = 120;

	private Options $options;
	private Storage_Adapter $adapter;
	private Sync_Status $status;
	private Delete_Jobs $deletes;

	/** @var array<string,Storage_Adapter> */
	private array $profile_adapters = array();

	public function __construct( ?Options $options = null, ?Storage_Adapter $adapter = null, ?Sync_Status $status = null, ?Delete_Jobs $deletes = null ) {
		$this->options = $options ?? new Options();
		$this->adapter = $adapter ?? new Storage_Adapter( $this->options );
		$this->status  = $status ?? new Sync_Status();
		$this->deletes = $deletes ?? new Delete_Jobs();
	}

	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		$hooks->add_action( 'init', array( $this, 'ensure_scheduled' ) );
		$hooks->add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		$hooks->add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Idempotent upgrade path, including sites already migrated to sync uploads.
	 * Never executes or converts an old upload job. Historical rows stay intact.
	 */
	public static function retire_legacy_hooks(): void {
		foreach ( array( 'sakura_imgopt_s3_upload_tick', 'sakura_imgopt_s3_upload_watchdog', 'sakura_imgopt_s3_retry' ) as $hook ) {
			if ( false !== wp_next_scheduled( $hook ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}

	/** Install the delete store and schedule its dedicated recurring tick. */
	public static function schedule(): void {
		Delete_Jobs::maybe_install();
		self::retire_legacy_hooks();
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public function ensure_scheduled(): void {
		self::schedule();
	}

	/** Wake deletion compensation only; never attach work to PHP shutdown. */
	public static function kick(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( false === $next || $next > time() + 5 ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		if ( ! wp_doing_cron() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** @param array<string,array<string,mixed>> $schedules Cron intervals. */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( '每分钟（Sakura S3 删除补偿）', 'sakura-imgopt' ),
			);
		}
		return $schedules;
	}

	/** Process due deletions within the existing time and batch bounds. */
	public function run( $max_seconds = self::MAX_SECONDS ): void {
		// Cron invokes this hook with the event's (empty) argument list; never
		// let an empty string turn the time budget into a TypeError.
		if ( ! is_numeric( $max_seconds ) ) {
			$max_seconds = self::MAX_SECONDS;
		}
		$max_seconds = max( 1, (int) $max_seconds );
		if ( ! $this->options->is_enabled() || ! $this->options->is_configured() || ! $this->lock( 'run' ) ) {
			return;
		}
		$start = time();
		try {
			$deleted = 0;
			while ( $deleted < self::MAX_JOBS && ( time() - $start ) < $max_seconds ) {
				$rows = $this->deletes->due( 5, self::STALE_SECONDS );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $row ) {
					if ( ( time() - $start ) >= $max_seconds || $deleted >= self::MAX_JOBS ) {
						break 2;
					}
					$this->process_delete( $row );
					++$deleted;
				}
			}
		} finally {
			$this->unlock( 'run' );
		}
		if ( $this->deletes->due_count() > 0 ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/** @return Storage_Adapter|null Null when the saved profile is missing. */
	private function adapter_for_profile( string $profile_id ): ?Storage_Adapter {
		if ( '' === $profile_id || $profile_id === (string) $this->options->active_profile_id() ) {
			return $this->adapter;
		}
		if ( isset( $this->profile_adapters[ $profile_id ] ) ) {
			return $this->profile_adapters[ $profile_id ];
		}
		$profile = $this->options->profile_options( $profile_id );
		if ( ! is_array( $profile ) ) {
			return null;
		}
		$options = new Options( $profile );
		if ( ! $options->is_configured() ) {
			return null;
		}
		$this->profile_adapters[ $profile_id ] = new Storage_Adapter( $options );
		return $this->profile_adapters[ $profile_id ];
	}

	/** @param array<string,mixed> $row Delete queue row. */
	private function process_delete( array $row ): void {
		$id  = (int) ( $row['id'] ?? 0 );
		$key = (string) ( $row['object_key'] ?? '' );
		if ( $id <= 0 || '' === $key ) {
			return;
		}
		$claim_token = $this->deletes->start( $id, self::STALE_SECONDS );
		if ( false === $claim_token ) {
			return;
		}
		$attachment_id = (int) ( $row['attachment_id'] ?? 0 );
		$state = '' !== (string) ( $row['profile_id'] ?? '' ) ? $this->status->status( $attachment_id ) : '';
		// Wait until replacement upload settles; never delete the only copy.
		if ( $attachment_id > 0 && '' !== $state && Sync_Status::SYNCED !== $state ) {
			if ( $this->deletes->postpone( $id, $claim_token, self::DELETE_RECHECK_SECONDS ) ) {
				do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] postponed %s: attachment %d is %s, replacement not settled', $key, $attachment_id, $state ) );
			}
			return;
		}
		$adapter = $this->adapter_for_profile( (string) ( $row['profile_id'] ?? '' ) );
		if ( null === $adapter ) {
			$error = __( '该任务创建时的存储配置已不存在或不可用，已停止删除以避免操作错误的存储桶。', 'sakura-imgopt' );
			$status = $this->deletes->fail( $id, $error, $claim_token );
			if ( ! empty( $status['applied'] ) ) {
				do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] %s: %s', $key, $error ) );
			}
			return;
		}

		// The profile is edited in place when its bucket/endpoint changes, so a
		// job enqueued earlier would otherwise delete in the new target (audit
		// A03). Prefer the archived configuration captured at save time: the keys
		// still point at the bucket the job was created for, so the orphan can be
		// removed instead of being stranded forever.
		$stamp      = (string) ( $row['target_hash'] ?? '' );
		$profile_id = (string) ( $row['profile_id'] ?? '' );
		if ( '' !== $stamp ) {
			$current = Delete_Jobs::target_hash( (array) $this->options->profile_options( $profile_id ) );
			if ( ! hash_equals( $stamp, $current ) ) {
				$archived = $this->options->retired_target( $profile_id, $stamp );
				$retired  = null !== $archived ? new Options( $archived ) : null;
				if ( null === $retired || ! $retired->is_configured() ) {
					$error  = __( '该任务创建后存储目标（provider/bucket/endpoint/region）已变更，且旧配置未存档，已停止删除以避免误删。', 'sakura-imgopt' );
					$status = $this->deletes->fail( $id, $error, $claim_token );
					if ( ! empty( $status['applied'] ) ) {
						do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] %s: %s', $key, $error ) );
					}
					return;
				}
				$adapter = new Storage_Adapter( $retired );
				do_action(
					'sakura_imgopt_s3_log',
					sprintf(
						/* translators: 1: object key, 2: bucket name, 3: profile id. */
						__( '[delete queue] %1$s: 使用归档目标 %2$s 删除（profile %3$s）', 'sakura-imgopt' ),
						$key,
						(string) ( $archived['bucket'] ?? '' ),
						$profile_id
					)
				);
			}
		}
		$skip       = (bool) apply_filters( 'sakura_imgopt_skip_object_delete', false, $key, $attachment_id );
		$referenced = '' !== $this->status->status( $attachment_id ) && $this->is_still_referenced( $attachment_id, $key );
		if ( $skip || $referenced ) {
			if ( $this->deletes->complete( $id, $claim_token ) ) {
				do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] kept %s: still referenced by attachment %d', $key, $attachment_id ) );
			}
			return;
		}
		$result = $adapter->with_mutation_check(
			fn(): bool => $this->deletes->renew( $id, $claim_token ),
			fn() => $adapter->delete_keys( array( $key ) )
		);
		if ( true === $result ) {
			if ( $this->deletes->complete( $id, $claim_token ) ) {
				do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] removed orphan %s', $key ) );
			}
			return;
		}
		$error  = $result instanceof \WP_Error ? $result->get_error_message() : __( '远端对象删除失败（未返回具体原因）。', 'sakura-imgopt' );
		$status = $this->deletes->fail( $id, $error, $claim_token );
		if ( empty( $status['applied'] ) ) {
			return;
		}
		do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] %s: %s', $key, $error ) );
		if ( ! empty( $status['dead'] ) ) {
			do_action( 'sakura_imgopt_s3_log', sprintf( '[delete queue] orphan %s marked dead after %d attempts', $key, (int) $status['attempts'] ) );
			do_action( 'sakura_imgopt_s3_delete_dead', $key, $row, $error );
		}
	}

	/** Metadata-based guard also works when local files were removed. */
	private function is_still_referenced( int $attachment_id, string $key ): bool {
		if ( $attachment_id <= 0 || '' === $key ) {
			return false;
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return false;
		}
		$desired = array();
		foreach ( $this->adapter->attachment_files( $attachment_id ) as $path ) {
			$candidate = $this->adapter->key_from_path( (string) $path );
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$desired[] = $candidate;
			}
		}
		return Restore_Plan::is_referenced( $key, $desired );
	}

	private function lock( string $name ): bool {
		global $wpdb;
		$key = $this->lock_key( $name );
		$result = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) );
		if ( '1' === $result ) {
			return true;
		}
		if ( '0' === $result ) {
			return false;
		}
		return $this->acquire_option_lock( $key );
	}

	private function unlock( string $name ): void {
		global $wpdb;
		$key = $this->lock_key( $name );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		delete_option( self::LOCK_OPTION . $key );
	}

	private function lock_key( string $name ): string {
		global $wpdb;
		// Keep the previous lock namespace to exclude an already-running old tick.
		return 'sio_ul_' . substr( hash( 'sha256', $name . '|' . $wpdb->prefix ), 0, 24 );
	}

	private function acquire_option_lock( string $key ): bool {
		$option = self::LOCK_OPTION . $key;
		$now = time();
		if ( add_option( $option, $now, '', false ) ) {
			return true;
		}
		$held = (int) get_option( $option, 0 );
		if ( $held > 0 && $held > $now - self::LOCK_TTL ) {
			return false;
		}
		update_option( $option, $now, false );
		return true;
	}
}
