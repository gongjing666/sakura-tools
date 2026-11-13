<?php
/**
 * Periodic cloud reconciliation.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Re-runs the reconciliation scan once a month so the dashboard cannot drift.
 *
 * The sync center reads only the local index: numbers survive page loads and
 * never touch the bucket, but they also stop describing the bucket as soon as
 * objects are added or removed outside WordPress. This module starts an
 * incremental scan when the newest finished scan is older than
 * STALE_SECONDS, and the sync screen asks for the same event on page load
 * when a site has no working traffic for months.
 *
 * Only the scan is scheduled here; the scan itself runs through
 * Scan_Worker::tick() so a month-long job cannot exceed one request.
 */
final class Sync_Calibrator implements Module {

	/**
	 * Cron hook shared by the monthly event and the on-demand fallback.
	 */
	public const CALIBRATE_HOOK = 'sakura_imgopt_sync_calibrate';

	/**
	 * One-off hook used when a visitor finds stale numbers between two
	 * monthly runs. It has its own name so wp_next_scheduled() can tell the
	 * recurring event and the queued kick apart.
	 */
	public const KICK_HOOK = 'sakura_imgopt_sync_calibrate_now';

	/**
	 * Schedule name installed by this module.
	 */
	public const CALIBRATE_SCHEDULE = 'sakura_imgopt_monthly';

	/**
	 * Age after which the stored cloud numbers count as stale (30 days).
	 */
	public const STALE_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * How long the first run waits after activation, in seconds.
	 */
	private const FIRST_RUN_DELAY = DAY_IN_SECONDS;

	/**
	 * Options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Index repository.
	 *
	 * @var Index_Repository
	 */
	private Index_Repository $index;

	/**
	 * Scan service.
	 *
	 * @var Scan_Service
	 */
	private Scan_Service $scanner;

	/**
	 * Background worker that advances the scan.
	 *
	 * @var Scan_Worker
	 */
	private Scan_Worker $worker;

	/**
	 * Constructor.
	 *
	 * @param Options|null          $options Options.
	 * @param Index_Repository|null $index   Index repository.
	 * @param Scan_Service|null     $scanner Scan service.
	 * @param Scan_Worker|null      $worker  Background worker.
	 */
	public function __construct( ?Options $options = null, ?Index_Repository $index = null, ?Scan_Service $scanner = null, ?Scan_Worker $worker = null ) {
		$this->options = $options ?? new Options();
		$this->index   = $index ?? new Index_Repository();
		$this->scanner = $scanner ?? new Scan_Service( $this->options, new Storage_Adapter( $this->options ), $this->index );
		$this->worker  = $worker ?? new Scan_Worker( $this->scanner, $this->index );
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		$hooks->add_action( 'init', array( $this, 'ensure_scheduled' ) );
		$hooks->add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		$hooks->add_action( self::CALIBRATE_HOOK, array( $this, 'calibrate' ) );
		$hooks->add_action( self::KICK_HOOK, array( $this, 'calibrate' ) );
	}

	/**
	 * Add the monthly schedule when another module has not.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::CALIBRATE_SCHEDULE ] ) ) {
			$schedules[ self::CALIBRATE_SCHEDULE ] = array(
				// The sniff below wants a literal it can read; STALE_SECONDS is
				// defined as exactly this expression.
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( '每月（Sakura 云端校准）', 'sakura-imgopt' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the recurring event (activation/upgrade path).
	 */
	public static function schedule(): void {
		\add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		if ( ! \wp_next_scheduled( self::CALIBRATE_HOOK ) ) {
			\wp_schedule_event( \time() + self::FIRST_RUN_DELAY, self::CALIBRATE_SCHEDULE, self::CALIBRATE_HOOK );
		}
	}

	/**
	 * Re-schedule if a plugin cleared the events.
	 */
	public function ensure_scheduled(): void {
		if ( ! \wp_next_scheduled( self::CALIBRATE_HOOK ) ) {
			\wp_schedule_event( \time() + self::FIRST_RUN_DELAY, self::CALIBRATE_SCHEDULE, self::CALIBRATE_HOOK );
		}
	}

	/**
	 * Queue a one-off calibration run for the next cron pass.
	 *
	 * The sync screen calls this when the stored numbers look stale but the
	 * recurring event is still days away. WordPress drops duplicate single
	 * events inside the same ten-minute window, so a page reload cannot pile
	 * up runs. The recurring event shares the hook name, which makes
	 * wp_next_scheduled() a truthful "something is already queued" check.
	 */
	public static function schedule_soon(): void {
		if ( \wp_next_scheduled( self::KICK_HOOK ) ) {
			return;
		}
		\wp_schedule_single_event( \time() + MINUTE_IN_SECONDS, self::KICK_HOOK );
	}

	/**
	 * Age of the newest finished scan, in seconds.
	 *
	 * @return int Zero when no scan has ever finished.
	 */
	public function stale_age(): int {
		$scan = $this->index->latest_scan( (string) $this->options->active_profile_id() );

		if ( ! $scan ) {
			return 0;
		}

		// A finished scan is the honest timestamp; an interrupted one only has
		// updated_at, and using it keeps a permanently broken scan retrying
		// instead of freezing the dashboard forever.
		$stamp = ! empty( $scan->finished_at ) ? (string) $scan->finished_at : (string) ( $scan->updated_at ?? '' );
		if ( '' === $stamp ) {
			return self::STALE_SECONDS;
		}

		$time = (int) \strtotime( $stamp . ' UTC' );
		if ( $time <= 0 ) {
			return self::STALE_SECONDS;
		}

		return max( 0, \time() - $time );
	}

	/**
	 * Whether a calibration run would do something useful right now.
	 *
	 * @return bool
	 */
	public function should_calibrate(): bool {
		if ( ! Feature::enabled() ) {
			return false;
		}

		if ( ! $this->options->is_enabled() || ! $this->options->is_configured() ) {
			return false;
		}

		$scan = $this->index->latest_scan( (string) $this->options->active_profile_id() );
		if ( $scan && \in_array( (string) $scan->state, array( 'queued', 'running' ), true ) ) {
			return false;
		}

		// A site that never scanned still gets one run: the dashboard exists
		// to answer "what is in the bucket", and an empty screen answers
		// nothing. FIRST_RUN_DELAY keeps that from happening on the same
		// request as activation.
		if ( ! $scan ) {
			return true;
		}

		return $this->stale_age() >= self::STALE_SECONDS;
	}

	/**
	 * Start an unattended incremental scan when the numbers went stale.
	 */
	public function calibrate(): void {
		if ( ! $this->should_calibrate() ) {
			return;
		}

		$profile = (string) $this->options->active_profile_id();
		$result  = $this->scanner->start( $profile, 'incremental' );

		if ( \is_wp_error( $result ) ) {
			$this->index->event(
				$profile,
				'warning',
				'calibrate',
				\sprintf(
					/* translators: %s: error message. */
					__( '每月自动校准未能启动：%s', 'sakura-imgopt' ),
					$result->get_error_message()
				)
			);
			return;
		}

		$this->worker->schedule( (int) $result, 2 );
	}
}
