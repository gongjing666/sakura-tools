<?php
/**
 * Coarse object kind classifier.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Maps object keys to the coarse `kind` stored in the sync index.
 *
 * The index stores the classification so the table can filter by kind without
 * running a leading-wildcard LIKE or a REGEXP over a million rows.
 */
final class Kind {

	/**
	 * Extension map.
	 *
	 * @var array<string,string>
	 */
	private const MAP = array(
		'jpg'   => 'image',
		'jpeg'  => 'image',
		'png'   => 'image',
		'gif'   => 'image',
		'webp'  => 'image',
		'avif'  => 'image',
		'svg'   => 'image',
		'apng'  => 'image',
		'bmp'   => 'image',
		'tif'   => 'image',
		'tiff'  => 'image',
		'ico'   => 'image',
		'mp4'   => 'video',
		'webm'  => 'video',
		'mov'   => 'video',
		'avi'   => 'video',
		'mkv'   => 'video',
		'm4v'   => 'video',
		'zip'   => 'archive',
		'rar'   => 'archive',
		'7z'    => 'archive',
		'gz'    => 'archive',
		'tar'   => 'archive',
		'bz2'   => 'archive',
	);

	/**
	 * Classify one object key.
	 *
	 * @param string $object_key Object key.
	 */
	public static function of( string $object_key ): string {
		$dot = strrpos( $object_key, '.' );
		if ( false === $dot ) {
			return 'other';
		}
		$slash = strrpos( $object_key, '/' );
		if ( false !== $slash && $slash > $dot ) {
			return 'other';
		}
		$extension = strtolower( substr( $object_key, $dot + 1 ) );
		return self::MAP[ $extension ] ?? 'other';
	}

	/**
	 * All extensions for a kind, used by legacy/fallback filters.
	 *
	 * @param string $kind image|video|archive.
	 * @return string[]
	 */
	public static function extensions( string $kind ): array {
		$out = array();
		foreach ( self::MAP as $extension => $mapped ) {
			if ( $mapped === $kind ) {
				$out[] = $extension;
			}
		}
		return $out;
	}
}
