<?php
/**
 * Lightweight sync-center counters.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Rolling counters for scanning and queued actions.
 *
 * Stored in one autoload-disabled option so the dashboard can render them
 * without a query; numbers are cumulative since install and reset on demand.
 */
final class Metrics {

	/**
	 * Option name.
	 */
	public const OPTION = 'sakura_imgopt_sync_metrics';

	/**
	 * Counter defaults.
	 *
	 * @var array<string,int>
	 */
	private const DEFAULTS = array(
		'pages'         => 0,
		'rows'          => 0,
		'batches'       => 0,
		'batch_ms'      => 0,
		's3_429'        => 0,
		's3_5xx'        => 0,
		'scans'         => 0,
		'scan_ms'       => 0,
		'actions_ok'    => 0,
		'actions_failed' => 0,
		'jobs_dead'     => 0,
	);

	/**
	 * Add deltas and persist.
	 *
	 * @param array<string,int> $deltas Counter deltas.
	 * @return void
	 */
	public static function bump( array $deltas ): void {
		$current = self::snapshot();
		foreach ( $deltas as $key => $value ) {
			if ( isset( self::DEFAULTS[ $key ] ) ) {
				$current[ $key ] = max( 0, (int) $current[ $key ] + (int) $value );
			}
		}
		$current['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( self::OPTION, $current, false );
	}

	/**
	 * Current counters merged over defaults.
	 *
	 * @return array<string,int|string>
	 */
	public static function snapshot(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array_merge( self::DEFAULTS, array_intersect_key( $saved, self::DEFAULTS ) );
		foreach ( $out as $key => $value ) {
			$out[ $key ] = (int) $value;
		}
		$out['updated_at'] = isset( $saved['updated_at'] ) ? (string) $saved['updated_at'] : '';
		return $out;
	}

	/**
	 * Reset all counters.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
