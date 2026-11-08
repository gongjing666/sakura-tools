<?php
/**
 * Pure key-set planning for attachment restores.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the object keys recorded for an attachment with the keys its
 * current metadata references.
 *
 * Restoring an original replaces the local file set: compressed formats and old
 * sub-sizes disappear from the metadata while the restored original reappears.
 * The bucket still holds the old keys, so a restore has to reconcile the two
 * sets instead of deleting everything (breaks the live URLs) or nothing
 * (keeps paying for orphans).
 *
 * This class deliberately contains no WordPress calls so the diff rules can be
 * unit tested in isolation.
 */
final class Restore_Plan {

	/**
	 * Normalize a list of object keys.
	 *
	 * @param array<int,mixed> $keys Raw keys.
	 * @return array<int,string>
	 */
	public static function normalize( array $keys ): array {
		$normalized = array();

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = trim( $key );
			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = true;
		}

		return array_keys( $normalized );
	}

	/**
	 * Keys recorded remotely that the restored attachment no longer references.
	 *
	 * Returns an empty list when the attachment has no metadata, because the
	 * desired set would be unknown and deleting blind could remove live objects.
	 *
	 * @param array<int,mixed> $recorded Keys already uploaded for the attachment.
	 * @param array<int,mixed> $desired  Keys the current metadata references.
	 * @return array<int,string>
	 */
	public static function stale( array $recorded, array $desired ): array {
		$recorded = self::normalize( $recorded );
		$desired  = self::normalize( $desired );

		if ( ! $recorded || ! $desired ) {
			return array();
		}

		return array_values( array_diff( $recorded, $desired ) );
	}

	/**
	 * Whether a key is still referenced by the attachment's metadata.
	 *
	 * @param string           $key     Object key.
	 * @param array<int,mixed> $desired Current desired keys.
	 */
	public static function is_referenced( string $key, array $desired ): bool {
		$key = trim( $key );

		return '' !== $key && in_array( $key, self::normalize( $desired ), true );
	}
}
