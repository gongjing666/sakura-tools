<?php
/**
 * 动图检测：GIF / APNG / 动态 WebP。
 *
 * Imagick 与 GD 的现有处理链路都只写首帧；一旦对动图做压缩/转码，动画会静默丢失。
 * 本类只读取文件头与块结构，不加载整幅图片，用于在处理前安全拦截动图。
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\Image;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 轻量动图探测。
 */
class Animation_Detector {

	/**
	 * 判断文件是否为多帧动图。
	 *
	 * @param string $file_path 绝对路径。
	 * @return bool
	 */
	public static function is_animated( $file_path ) {
		if ( ! is_string( $file_path ) || ! is_file( $file_path ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		switch ( $ext ) {
			case 'gif':
				$animated = self::gif_is_animated( $file_path );
				break;
			case 'png':
			case 'apng':
				$animated = self::png_is_apng( $file_path );
				break;
			case 'webp':
				$animated = self::webp_is_animated( $file_path );
				break;
			default:
				$animated = false;
		}

		/**
		 * 过滤动图判定结果，便于特殊场景放行/拦截。
		 *
		 * @param bool   $animated  是否动图。
		 * @param string $file_path 文件路径。
		 */
		return (bool) apply_filters( 'sakura_imgopt_is_animated_image', $animated, $file_path );
	}

	/**
	 * GIF：解析块结构并统计 Image Descriptor 数量（不依赖文件大小或内存）。
	 *
	 * @param string $file_path
	 * @return bool
	 */
	private static function gif_is_animated( $file_path ) {
		$handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		try {
			$header = fread( $handle, 6 );
			if ( 6 !== strlen( $header ) || ! in_array( $header, array( 'GIF87a', 'GIF89a' ), true ) ) {
				return false;
			}

			$screen = fread( $handle, 7 );
			if ( 7 !== strlen( $screen ) ) {
				return false;
			}

			$packed = ord( $screen[4] );
			if ( $packed & 0x80 ) {
				$table_size = 3 * ( 1 << ( ( $packed & 0x07 ) + 1 ) );
				if ( fseek( $handle, $table_size, SEEK_CUR ) !== 0 ) {
					return false;
				}
			}

			$frames = 0;
			while ( ! feof( $handle ) ) {
				$byte = fread( $handle, 1 );
				if ( '' === $byte ) {
					break;
				}

				$type = ord( $byte );
				if ( 0x3B === $type ) { // Trailer.
					break;
				}

				if ( 0x21 === $type ) { // Extension.
					$label = fread( $handle, 1 );
					if ( 1 !== strlen( $label ) || ! self::skip_sub_blocks( $handle ) ) {
						break;
					}
					continue;
				}

				if ( 0x2C === $type ) { // Image Descriptor.
					$descriptor = fread( $handle, 9 );
					if ( 9 !== strlen( $descriptor ) ) {
						break;
					}

					$image_packed = ord( $descriptor[8] );
					if ( $image_packed & 0x80 ) {
						$table_size = 3 * ( 1 << ( ( $image_packed & 0x07 ) + 1 ) );
						if ( fseek( $handle, $table_size, SEEK_CUR ) !== 0 ) {
							break;
						}
					}

					// LZW minimum code size，随后是数据子块。
					if ( 1 !== strlen( fread( $handle, 1 ) ) || ! self::skip_sub_blocks( $handle ) ) {
						break;
					}

					++$frames;
					if ( $frames > 1 ) {
						return true;
					}
					continue;
				}

				// 未知块，保守判定为不可安全处理。
				break;
			}

			return false;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * APNG：acTL 块必须在 IDAT 之前出现。
	 *
	 * @param string $file_path
	 * @return bool
	 */
	private static function png_is_apng( $file_path ) {
		$handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		try {
			$signature = fread( $handle, 8 );
			if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
				return false;
			}

			while ( ! feof( $handle ) ) {
				$length_bytes = fread( $handle, 4 );
				$type         = fread( $handle, 4 );
				if ( 4 !== strlen( $length_bytes ) || 4 !== strlen( $type ) ) {
					break;
				}

				$unpacked = unpack( 'N', $length_bytes );
				$length   = isset( $unpacked[1] ) ? (int) $unpacked[1] : 0;

				if ( 'acTL' === $type ) {
					return true;
				}
				if ( 'IDAT' === $type || 'IEND' === $type ) {
					return false;
				}

				// 跳过数据与 4 字节 CRC。
				if ( fseek( $handle, $length + 4, SEEK_CUR ) !== 0 ) {
					return false;
				}
			}

			return false;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * 动态 WebP：存在 ANIM/ANMF 块，或 VP8X 的 animation 标志置位。
	 *
	 * @param string $file_path
	 * @return bool
	 */
	private static function webp_is_animated( $file_path ) {
		$handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $handle ) {
			return false;
		}

		try {
			$header = fread( $handle, 12 );
			if ( 12 !== strlen( $header ) || 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
				return false;
			}

			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 8 );
				if ( 8 !== strlen( $chunk ) ) {
					break;
				}

				$fourcc = substr( $chunk, 0, 4 );
				$size   = unpack( 'V', substr( $chunk, 4, 4 ) );
				$size   = isset( $size[1] ) ? (int) $size[1] : 0;

				if ( 'ANIM' === $fourcc || 'ANMF' === $fourcc ) {
					return true;
				}

				$consumed = 0;
				if ( 'VP8X' === $fourcc ) {
					$flags = fread( $handle, 1 );
					if ( 1 === strlen( $flags ) && ( ord( $flags ) & 0x02 ) ) {
						return true;
					}
					$consumed = 1;
				}

				$skip = $size - $consumed;
				if ( $skip < 0 || fseek( $handle, $skip + ( $size % 2 ), SEEK_CUR ) !== 0 ) {
					return false;
				}
			}

			return false;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * 跳过 GIF 数据子块（长度字节 + 数据，直到 0 长度）。
	 *
	 * @param resource $handle
	 * @return bool
	 */
	private static function skip_sub_blocks( $handle ) {
		while ( ! feof( $handle ) ) {
			$size = fread( $handle, 1 );
			if ( 1 !== strlen( $size ) ) {
				return false;
			}

			$length = ord( $size );
			if ( 0 === $length ) {
				return true;
			}
			if ( fseek( $handle, $length, SEEK_CUR ) !== 0 ) {
				return false;
			}
		}

		return false;
	}
}
