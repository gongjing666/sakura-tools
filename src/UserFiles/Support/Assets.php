<?php
/**
 * Versioned asset URLs.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One definition of the cache-busting version for a bundled asset.
 *
 * Every enqueue used to build the string itself, with three different
 * fallbacks: a missing file produced a version that never changed, a warning
 * from filemtime(), or a bare version with no mtime at all.
 */
final class Assets {

	/**
	 * Cache-busting version for one asset file.
	 *
	 * @param string $relative Path below the plugin root, e.g. assets/userfiles.css.
	 * @return string
	 */
	public static function version( string $relative ): string {
		$version = defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '0';
		$path    = defined( 'SAKURA_TOOLS_PATH' ) ? (string) SAKURA_TOOLS_PATH : '';

		if ( '' === $path || '' === $relative ) {
			return $version;
		}

		$file = $path . ltrim( $relative, '/' );

		if ( ! is_readable( $file ) ) {
			return $version;
		}

		$mtime = filemtime( $file );
		$size  = filesize( $file );

		return $version . '.' . ( false === $mtime ? 0 : (int) $mtime ) . '.' . ( false === $size ? 0 : (int) $size );
	}
}
