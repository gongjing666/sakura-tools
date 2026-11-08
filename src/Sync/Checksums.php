<?php
/**
 * Object checksum helpers shared by reconcile and the verification worker.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises S3 ETags and decides whether one can be compared with an MD5.
 */
final class Checksums {

	/**
	 * Strip quotes/whitespace and lower-case an ETag.
	 *
	 * @param string $etag Raw ETag.
	 */
	public static function normalize_etag( string $etag ): string {
		return strtolower( trim( trim( $etag ), '"' ) );
	}

	/**
	 * Whether an ETag is a plain 32-hex MD5 (single-part upload).
	 *
	 * @param string $etag Raw or normalised ETag.
	 */
	public static function is_md5_etag( string $etag ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}$/', self::normalize_etag( $etag ) );
	}

	/**
	 * Stream a file through MD5 while respecting a wall-clock deadline.
	 *
	 * Reads 1 MiB chunks and aborts before the next chunk when the deadline is
	 * reached, so one huge file cannot monopolise a worker tick.
	 *
	 * @param string   $filename Absolute file path.
	 * @param float|null $deadline Unix timestamp with microseconds, or null.
	 * @return string|false 32-hex MD5, `''` when the deadline was hit, or false on read failure.
	 */
	public static function hash_file( string $filename, ?float $deadline = null ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed checksum.
		$handle = @fopen( $filename, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing files are an expected outcome.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed checksum.
		if ( ! is_resource( $handle ) ) {
			return false;
		}
		$context = hash_init( 'md5' );
		try {
			while ( ! feof( $handle ) ) {
				if ( null !== $deadline && microtime( true ) >= $deadline ) {
					return '';
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streamed checksum.
				$chunk = fread( $handle, 1048576 );
				if ( false === $chunk ) {
					return false;
				}
				if ( '' === $chunk ) {
					break;
				}
				hash_update( $context, $chunk );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream opened above.
			fclose( $handle );
		}
		return hash_final( $context );
	}
}
