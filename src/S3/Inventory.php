<?php
/**
 * Legacy size formatting helper.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Human readable byte widths.
 *
 * The transient snapshot this class used to build was replaced by the
 * persistent sync index (see {@see \Sakura\ImageOptimizer\Sync\Index_Repository})
 * in 1.4.0. Only the static formatter is kept because the sync center view
 * still renders sizes with it; new code should prefer the dashboard payload.
 */
final class Inventory {

	/**
	 * Width of a binary size in bytes, grouped for human output.
	 *
	 * @param int $bytes Bytes.
	 */
	public static function format_bytes( int $bytes ): string {
		if ( $bytes >= 1073741824 ) {
			return number_format_i18n( $bytes / 1073741824, 2 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format_i18n( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format_i18n( $bytes / 1024, 2 ) . ' KB';
		}
		return number_format_i18n( $bytes ) . ' B';
	}
}
