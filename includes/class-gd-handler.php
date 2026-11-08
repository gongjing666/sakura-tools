<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Image\No_Gain_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GD 图片处理器（后备方案）
 *
 * 支持：JPEG、PNG、GIF、WEBP → 压缩 / 转 WebP / AVIF（PHP 8.1+ 且 GD 编译了 AVIF）
 * 注意：AVIF 输出依赖 imageavif()；不可用时 process() 返回失败，不再降级为 JPEG。
 */
class GD_Handler {

	/**
	 * 检测 GD 是否可用
	 *
	 * @param string $format 目标格式（webp / '' 表示原格式）
	 * @return bool
	 */
	public static function is_available( $format = '' ) {
		if ( ! extension_loaded( 'gd' ) ) {
			return false;
		}
		$format = strtolower( $format );
		if ( 'avif' === $format ) {
			return function_exists( 'imageavif' );
		}
		if ( 'webp' === $format ) {
			return function_exists( 'imagewebp' );
		}
		return true;
	}

	/**
	 * 处理单张图片
	 *
	 * @param string $source_path
	 * @param string $dest_path
	 * @param array  $opts
	 * @return array
	 */
	public static function process( $source_path, $dest_path = '', $opts = array() ) {
		$defaults = array(
			'quality' => 82,
			'format'  => '',
		);
		$opts     = wp_parse_args( $opts, $defaults );
		$quality  = max( 1, min( 100, absint( $opts['quality'] ) ) );
		$format   = strtolower( trim( $opts['format'] ) );

		if ( empty( $dest_path ) ) {
			$dest_path = $source_path;
		}

		$size_before = file_exists( $source_path ) ? filesize( $source_path ) : 0;
		$tmp_path    = '';

		try {
			// 1. 读取源图
			$info = getimagesize( $source_path );
			if ( ! $info ) {
				throw new \Exception( __( '无法读取图片信息', 'sakura-imgopt' ) );
			}

			$src_mime = $info['mime'];
			$width    = $info[0];
			$height   = $info[1];

			// 像素上限：GD 会为整幅图分配真彩色画布（约 4 字节/像素），
			// 没有上限时一张超大图就可能触发不可捕获的内存耗尽。
			$max_pixels = (int) apply_filters( 'sakura_imgopt_max_pixels', 24000000 );
			if ( $max_pixels > 0 && ( (float) $width * (float) $height ) > $max_pixels ) {
				throw new \Exception( __( '图片像素超过处理上限，已跳过以避免内存耗尽。', 'sakura-imgopt' ) );
			}
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'image' );
			}

			// 创建 GD 图像资源
			$src_img = self::create_from_mime( $source_path, $src_mime );
			if ( ! $src_img ) {
				throw new \Exception( __( 'GD 无法创建图片资源', 'sakura-imgopt' ) );
			}

			// 2. 创建真彩色画布（处理 GIF/PNG 透明度）
			$dst_img = imagecreatetruecolor( $width, $height );
			if ( ! $dst_img ) {
				throw new \Exception( __( 'GD 无法分配画布内存', 'sakura-imgopt' ) );
			}

			// 保留透明度
			if ( 'png' === $format || 'webp' === $format || empty( $format ) ) {
				imagealphablending( $dst_img, false );
				imagesavealpha( $dst_img, true );
				$transparent = imagecolorallocatealpha( $dst_img, 0, 0, 0, 127 );
				imagefilledrectangle( $dst_img, 0, 0, $width, $height, $transparent );
			} else {
				// JPEG 等：白色背景
				$bg = imagecolorallocate( $dst_img, 255, 255, 255 );
				imagefilledrectangle( $dst_img, 0, 0, $width, $height, $bg );
			}

			imagecopyresampled( $dst_img, $src_img, 0, 0, 0, 0, $width, $height, $width, $height );
			imagedestroy( $src_img );

			// 3. 根据目标格式输出到临时文件，便于在替换原图前比较体积。
			$target_format = empty( $format ) ? self::mime_to_format( $src_mime ) : $format;
			$ext_map       = array(
				'webp' => 'webp',
				'avif' => 'avif',
				'png'  => 'png',
				'gif'  => 'gif',
				'jpg'  => 'jpg',
				'jpeg' => 'jpg',
			);
			$dest_path = self::ensure_extension( $dest_path, $ext_map[ $target_format ] ?? 'jpg' );
			$tmp_path  = $dest_path . '.sakuratmp_' . bin2hex( random_bytes( 4 ) );

			$saved         = false;
			$error_message = '';
			switch ( $target_format ) {
				case 'webp':
					$gd_quality = $quality; // GD 的 quality 范围也是 0–100
					$saved      = imagewebp( $dst_img, $tmp_path, $gd_quality );
					break;

				case 'avif':
					if ( ! function_exists( 'imageavif' ) ) {
						$error_message = __( '当前 GD 不支持 AVIF 输出', 'sakura-imgopt' );
						break;
					}
					$saved = imageavif( $dst_img, $tmp_path, $quality );
					break;

				case 'png':
					$png_quality = max( 0, min( 9, (int) round( ( 100 - $quality ) / 11.1 ) ) ); // 0–9 映射
					$saved       = imagepng( $dst_img, $tmp_path, $png_quality );
					break;

				case 'gif':
					$saved = imagegif( $dst_img, $tmp_path );
					break;

				case 'jpg':
				case 'jpeg':
				default:
					$saved = imagejpeg( $dst_img, $tmp_path, $quality );
					break;
			}

			imagedestroy( $dst_img );

			if ( ! $saved ) {
				throw new \Exception( '' !== $error_message ? $error_message : __( 'GD 写入图片失败', 'sakura-imgopt' ) );
			}

			// M-08：候选文件不小于原图时丢弃候选，原图与备份都不改动。
			$candidate_size = file_exists( $tmp_path ) ? (int) filesize( $tmp_path ) : 0;
			if ( No_Gain_Guard::should_keep_original( (int) $size_before, $candidate_size, $source_path ) ) {
				File_System::delete( $tmp_path );
				$tmp_path = '';
				return No_Gain_Guard::skipped_result( $source_path, (int) $size_before, $candidate_size, 'gd' );
			}

			if ( $dest_path !== $source_path ) {
				// 目标名可能已被并发上传占用：先原子占位再落盘，避免同名覆盖。
				$moved = File_System::move_to_unique( $tmp_path, $dest_path );
				if ( ! $moved['ok'] ) {
					throw new \RuntimeException( __( '无法原子替换处理后的图片文件', 'sakura-imgopt' ) );
				}
				$dest_path = $moved['path'];
			} elseif ( ! File_System::move( $tmp_path, $dest_path ) ) {
				// 原地压缩：rename 跨分区可能失败，用 copy + delete 兜底。
				if ( ! File_System::copy( $tmp_path, $dest_path ) || ! File_System::delete( $tmp_path ) ) {
					throw new \RuntimeException( __( '无法原子替换处理后的图片文件', 'sakura-imgopt' ) );
				}
			}
			$tmp_path   = '';
			$size_after = file_exists( $dest_path ) ? filesize( $dest_path ) : 0;

			return array(
				'success'     => true,
				'path'        => $dest_path,
				'size_before' => $size_before,
				'size_after'  => $size_after,
			);

		} catch ( \Throwable $e ) {
			return array(
				'success'     => false,
				'path'        => $dest_path,
				'error'       => $e->getMessage(),
				'size_before' => $size_before,
				'size_after'  => 0,
			);
		} finally {
			// 写入中途失败时可能残留 .sakuratmp_*，这里兜底清理。
			if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
				File_System::delete( $tmp_path );
			}
		}
	}

	/**
	 * 根据 MIME 创建 GD 图像资源
	 */
	private static function create_from_mime( $path, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/jpg':
				return imagecreatefromjpeg( $path );
			case 'image/png':
				return imagecreatefrompng( $path );
			case 'image/gif':
				return imagecreatefromgif( $path );
			case 'image/webp':
				return imagecreatefromwebp( $path );
			default:
				return false;
		}
	}

	/**
	 * MIME → 格式名
	 */
	private static function mime_to_format( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);
		return $map[ $mime ] ?? 'jpg';
	}

	/**
	 * 确保文件扩展名与格式一致
	 */
	private static function ensure_extension( $path, $ext ) {
		$info = pathinfo( $path );
		$current_ext = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';
		if ( $current_ext !== $ext ) {
			$path = $info['dirname'] . '/' . $info['filename'] . '.' . $ext;
		}
		return $path;
	}
}
