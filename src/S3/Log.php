<?php
/**
 * S3 debug logger.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Emits an S3 log line only when WP_DEBUG is enabled.
 */
final class Log {

	/**
	 * Write one sanitized log line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function write( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			do_action( 'sakura_imgopt_s3_log', preg_replace( '/[\r\n]+/', ' ', $message ) );
		}
	}
}
