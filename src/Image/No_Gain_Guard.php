<?php
/**
 * Keep-the-original guard for re-encoded images.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

defined( 'ABSPATH' ) || exit;

/**
 * Discards a re-encoded candidate that is not smaller than the original.
 *
 * Handlers write to a temporary file first, compare its size with the source,
 * and only replace the original when the candidate actually saves bytes.
 */
final class No_Gain_Guard {

	/**
	 * Skip reason recorded in results and stats.
	 */
	public const REASON = 'no_gain';

	/**
	 * Whether the freshly written candidate should be thrown away.
	 *
	 * Unknown sizes (0) never trigger the guard: a missing filesize must not be
	 * mistaken for "no gain", otherwise a failed write would look like a policy
	 * decision.
	 *
	 * @param int    $size_before Original file size in bytes.
	 * @param int    $size_after  Candidate file size in bytes.
	 * @param string $source_path Source file path, used by the filter only.
	 * @return bool
	 */
	public static function should_keep_original( int $size_before, int $size_after, string $source_path = '' ): bool {
		if ( $size_before <= 0 || $size_after <= 0 ) {
			return false;
		}

		$keep = $size_after >= $size_before;

		/**
		 * Filters whether an output that is not smaller is discarded.
		 *
		 * Return false to keep the historical behaviour and replace the original
		 * even when the new file is equal or larger.
		 *
		 * @param bool   $keep        Whether to keep the original image.
		 * @param int    $size_before Original size in bytes.
		 * @param int    $size_after  Candidate size in bytes.
		 * @param string $source_path Source file path.
		 */
		return (bool) apply_filters( 'sakura_imgopt_keep_original_when_larger', $keep, $size_before, $size_after, $source_path );
	}

	/**
	 * Build the normalized "kept the original" result.
	 *
	 * @param string $source_path Source file path.
	 * @param int    $size_before Original size in bytes.
	 * @param int    $size_after  Candidate size in bytes (for the message only).
	 * @param string $engine      Engine name (imagick|gd).
	 * @return array<string,mixed>
	 */
	public static function skipped_result( string $source_path, int $size_before, int $size_after, string $engine ): array {
		return array(
			'success'     => false,
			'path'        => $source_path,
			'error'       => sprintf(
				/* translators: 1: original size in bytes, 2: candidate size in bytes. */
				__( '处理后体积未减小（%1$d → %2$d 字节），已保留原图。', 'sakura-imgopt' ),
				$size_before,
				$size_after
			),
			'skipped'     => true,
			'skip_reason' => self::REASON,
			'size_before' => $size_before,
			'size_after'  => 0,
			'engine'      => $engine,
		);
	}
}
