<?php
/**
 * Sync index feature switch.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime switch for the persistent sync index.
 *
 * Turning it off keeps the tables intact and makes the dashboard fall back to
 * the cached compatibility snapshot; attachment dual-write stops as well.
 */
final class Feature {

	/**
	 * Option name.
	 */
	public const OPTION = 'sakura_imgopt_sync_index_enabled';

	/**
	 * Whether the index is enabled (default on; filterable).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$value   = get_option( self::OPTION, '1' );
		$enabled = ! in_array( (string) $value, array( '0', 'off', 'no', 'false' ), true );
		return (bool) apply_filters( 'sakura_imgopt_sync_index_enabled', $enabled );
	}

	/**
	 * Persist the switch.
	 *
	 * @param bool $enabled Enabled.
	 * @return void
	 */
	public static function set( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? '1' : '0', false );
	}
}
