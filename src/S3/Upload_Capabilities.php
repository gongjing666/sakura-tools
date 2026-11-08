<?php
/**
 * Runtime capabilities for in-request parallel uploads.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the packaged SDK can run several HTTP requests at once.
 *
 * A configured concurrency is only a request. When the host has no usable
 * cURL multi handler the effective value is 1 and the settings page says so,
 * instead of silently sending more requests than the stack can carry.
 */
final class Upload_Capabilities {

	/**
	 * Cached probe result for this request.
	 *
	 * @var bool|null
	 */
	private static ?bool $parallel = null;

	/**
	 * Whether parallel HTTP uploads are available.
	 */
	public static function supports_parallel(): bool {
		if ( null !== self::$parallel ) {
			return self::$parallel;
		}

		$supports = extension_loaded( 'curl' ) && function_exists( 'curl_multi_exec' );
		if ( $supports ) {
			$autoload = SAKURA_TOOLS_PATH . 'vendor/s3/vendor-prefixed/autoload.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
				$supports = class_exists( 'Sakura\\Vendor\\GuzzleHttp\\Promise\\EachPromise' )
					&& class_exists( 'Sakura\\Vendor\\GuzzleHttp\\Handler\\CurlMultiHandler' );
			} else {
				$supports = false;
			}
		}

		self::$parallel = $supports;
		return $supports;
	}

	/**
	 * Effective concurrency for this request (1-4).
	 *
	 * @param int $requested Stored preference.
	 */
	public static function effective( int $requested ): int {
		if ( $requested < 2 || ! self::supports_parallel() ) {
			return 1;
		}
		return max( 1, min( Settings_Repository::MAX_UPLOAD_CONCURRENCY, $requested ) );
	}

	/**
	 * Stored preference, clamped to the supported range.
	 */
	public static function requested(): int {
		return ( new Settings_Repository() )->upload_concurrency();
	}

}
