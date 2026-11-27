<?php
/**
 * Image engine selection.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Image\Handlers\GD_Handler;
use Sakura\ImageOptimizer\Image\Handlers\Imagick_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Tries Imagick first and falls back to GD.
 *
 * Both handlers keep their existing behavior; this service only owns the
 * fallback policy and the normalized result shape.
 */
final class Engine_Manager {

	/**
	 * Process a file with the best available engine.
	 *
	 * @param string               $file_path Absolute file path.
	 * @param array<string,mixed>  $opts      Quality / target format options.
	 * @return array<string,mixed>
	 */
	public function process( string $file_path, array $opts ): array {
		$format = isset( $opts['format'] ) ? (string) $opts['format'] : '';

		$skipped    = null;
		$last_error = '';

		$result = Imagick_Handler::process( $file_path, '', $opts );
		if ( ! empty( $result['success'] ) ) {
			$result['engine'] = 'imagick';
			return $result;
		}
		if ( ! empty( $result['skipped'] ) ) {
			// 结果合法但不够小：保留原图，同时仍让 GD 试一次（编码器不同，可能更小）。
			$result['engine'] = 'imagick';
			$skipped          = $result;
		} else {
			$last_error = isset( $result['error'] ) ? (string) $result['error'] : '';
		}

		if ( GD_Handler::is_available( $format ) ) {
			$result = GD_Handler::process( $file_path, '', $opts );
			if ( ! empty( $result['success'] ) ) {
				$result['engine'] = 'gd';
				return $result;
			}
			if ( ! empty( $result['skipped'] ) ) {
				$result['engine'] = 'gd';
				$skipped          = $result;
			} elseif ( isset( $result['error'] ) && '' !== $result['error'] ) {
				$last_error = (string) $result['error'];
			}
		}

		if ( null !== $skipped ) {
			return $skipped;
		}

		return array(
			'success'     => false,
			'path'        => $file_path,
			'error'       => '' !== $last_error ? $last_error : __( '无可用图片处理引擎', 'sakura-imgopt' ),
			'size_before' => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
			'size_after'  => 0,
			'engine'      => '',
		);
	}
}
