<?php
/**
 * Background verification worker.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Slowly walks the synced index and compares local MD5 with remote ETag.
 *
 * Verification reads every byte of every file, so it runs in bounded ticks
 * (20 s per five-minute cron) instead of a single pass. A filter can schedule
 * it less often on very large libraries.
 */
final class Verify_Worker implements Module {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'sakura_imgopt_sync_verify_tick';

	/**
	 * Schedule name (installed by this module).
	 */
	public const SCHEDULE = 'sakura_imgopt_5min';

	/**
	 * Seconds per tick.
	 */
	private const SECONDS = 20;

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
	 * Constructor.
	 *
	 * @param Options|null          $options Options.
	 * @param Index_Repository|null $index   Index repository.
	 */
	public function __construct( ?Options $options = null, ?Index_Repository $index = null ) {
		$this->options = $options ?? new Options();
		$this->index   = $index ?? new Index_Repository();
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
		$hooks->add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Add the five-minute schedule when another module has not.
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Sakura S3 每五分钟', 'sakura-imgopt' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the recurring event (activation/upgrade path).
	 */
	public static function schedule(): void {
		\add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( \time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Re-schedule if a plugin cleared the events.
	 */
	public function ensure_scheduled(): void {
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			\wp_schedule_event( \time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Run one bounded verification tick.
	 */
	public function run(): void {
		$service = new Verify_Service( $this->options, $this->index );
		$result  = $service->run( self::SECONDS );

		if ( ! empty( $result['processed'] ) ) {
			\do_action(
				'sakura_imgopt_s3_log',
				sprintf(
					'[verify] processed %d, matched %d, mismatch %d, unverifiable %d',
					(int) $result['processed'],
					(int) $result['checked'],
					(int) $result['mismatched'],
					(int) $result['unverifiable']
				)
			);
		}
	}
}
