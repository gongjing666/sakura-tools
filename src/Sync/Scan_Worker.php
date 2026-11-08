<?php
/**
 * Background scan worker.
 *
 * @package Sakura\ImageOptimizer
 */

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advances a running scan through WP-Cron without depending on an open browser.
 *
 * Each run consumes a bounded time budget (Scan_Service::TICK_SECONDS) and then
 * schedules the next run two seconds later, so a million-object listing keeps
 * moving even when the browser tab is closed. Transient failures are retried up
 * to MAX_ATTEMPTS with backoff before the scan is marked failed. The worker also
 * owns the daily retention cleanup, the kind backfill and the lightweight daily
 * reconciliation.
 */
final class Scan_Worker implements Module {

	/**
	 * Daily retention/cleanup event.
	 */
	public const CLEANUP_HOOK = 'sakura_imgopt_sync_cleanup';

	/**
	 * Daily lightweight reconcile event (no S3 listing).
	 */
	public const DAILY_HOOK = 'sakura_imgopt_sync_daily_reconcile';

	/**
	 * Failed attempts before a scan is marked failed.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Error codes that will not succeed on retry.
	 *
	 * @var string[]
	 */
	private const FATAL_CODES = array(
		'sio_local_limit',
		'sio_local_cursor',
		'sio_uploads_unavailable',
		'sio_s3_not_configured',
		'sio_no_profile',
		'sio_unknown_profile',
	);

	private Scan_Service $scanner;
	private Index_Repository $index;

	/**
	 * Constructor.
	 *
	 * @param Scan_Service     $scanner Scanner.
	 * @param Index_Repository $index   Index.
	 */
	public function __construct( Scan_Service $scanner, Index_Repository $index ) {
		$this->scanner = $scanner;
		$this->index   = $index;
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'sakura_imgopt_sync_scan_tick', array( $this, 'run' ), 10, 1 );
		$hooks->add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		$hooks->add_action( self::DAILY_HOOK, array( $this, 'daily_reconcile' ) );
		$hooks->add_action( Schema::BACKFILL_HOOK, array( $this, 'kind_backfill' ) );
		$hooks->add_action( Schema::BACKFILL_RECENT_HOOK, array( $this, 'recent_backfill' ) );
	}

	/**
	 * Schedule one worker run.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $delay   Delay seconds.
	 */
	public function schedule( int $scan_id, int $delay = 2 ): void {
		if ( $scan_id <= 0 || wp_next_scheduled( 'sakura_imgopt_sync_scan_tick', array( $scan_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), 'sakura_imgopt_sync_scan_tick', array( $scan_id ) );
	}

	/**
	 * Schedule the daily maintenance jobs if they are missing.
	 */
	public static function schedule_maintenance(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Advance one scan by a bounded slice.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public function run( int $scan_id ): void {
		$scan = $this->index->scan( $scan_id );
		if ( ! $scan || ! in_array( (string) $scan->state, array( 'queued', 'running' ), true ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( Scan_Service::TICK_SECONDS + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort under safe_mode/hosting limits.
		}

		try {
			$result = $this->scanner->tick( $scan_id, 0, Scan_Service::TICK_SECONDS );
		} catch ( \Throwable $e ) {
			$this->handle_failure( $scan_id, (string) $scan->profile_id, 'sio_scan_exception', $e->getMessage() );
			return;
		}

		if ( is_wp_error( $result ) ) {
			if ( 'sio_scan_failed' !== $result->get_error_code() ) {
				$this->handle_failure( $scan_id, (string) $scan->profile_id, $result->get_error_code(), $result->get_error_message() );
			}
			return;
		}

		$scan = $this->index->scan( $scan_id );
		if ( $scan && 'running' === (string) $scan->state ) {
			// Keep the chain going; a busy tick (another worker holds the lock)
			// retries a little later instead of spinning.
			$this->schedule( $scan_id, empty( $result['busy'] ) ? 2 : 4 );
		}
	}

	/**
	 * Record a failed attempt and either retry or fail the scan.
	 *
	 * @param int    $scan_id Scan id.
	 * @param string $profile Profile id.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	private function handle_failure( int $scan_id, string $profile, string $code, string $message ): void {
		if ( in_array( $code, self::FATAL_CODES, true ) ) {
			$this->index->fail_scan( $scan_id, $code, $message );
			$this->index->event( $profile, 'error', 'scan_failed', $message, array( 'scan_id' => $scan_id ) );
			return;
		}

		$attempts = $this->index->record_scan_failure( $scan_id, $code, $message );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->index->fail_scan( $scan_id, $code, sprintf( /* translators: 1: attempts, 2: error message. */ __( '%1$d 次尝试后失败：%2$s', 'sakura-imgopt' ), $attempts, $message ) );
			$this->index->event( $profile, 'error', 'scan_failed', $message, array( 'scan_id' => $scan_id, 'attempts' => $attempts ) );
			return;
		}

		$this->schedule( $scan_id, 10 * $attempts );
	}

	/**
	 * Delete expired events, dead jobs and old finished scans.
	 */
	public function cleanup(): void {
		$this->index->cleanup();
		self::schedule_maintenance();
	}

	/**
	 * Recalculate statuses for every configured profile without listing S3.
	 *
	 * The daily event keeps counts correct after manual actions and partial
	 * failures without spending object-storage requests.
	 */
	public function daily_reconcile(): void {
		$this->scanner->refresh_all();
		self::schedule_maintenance();
	}

	/**
	 * Backfill the kind column in bounded batches after the schema upgrade.
	 */
	public function kind_backfill(): void {
		if ( $this->index->pending_kind_count() <= 0 ) {
			return;
		}
		$this->index->backfill_kind( 20000 );
		if ( $this->index->pending_kind_count() > 0 && ! wp_next_scheduled( Schema::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Schema::BACKFILL_HOOK );
		}
	}

	/**
	 * Backfill the derived recent_at column in bounded batches.
	 *
	 * Until it finishes the repository keeps using the legacy days predicate;
	 * the flag is only set when no row is left with recent_at = 0.
	 */
	public function recent_backfill(): void {
		if ( $this->index->pending_recent_count() <= 0 ) {
			update_option( Schema::RECENT_READY, '1', false );
			return;
		}

		$this->index->backfill_recent( 20000 );

		if ( $this->index->pending_recent_count() > 0 ) {
			if ( ! wp_next_scheduled( Schema::BACKFILL_RECENT_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Schema::BACKFILL_RECENT_HOOK );
			}
			return;
		}

		update_option( Schema::RECENT_READY, '1', false );
	}
}
