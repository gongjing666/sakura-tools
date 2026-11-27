<?php
namespace Sakura\ImageOptimizer\Image\Handlers;

use Sakura\ImageOptimizer\Image\No_Gain_Guard;
use Sakura\ImageOptimizer\Filesystem\File_System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imagick 图片处理器
 *
 * 支持：JPEG、PNG、GIF → 压缩 / 转 WebP / 转 AVIF
 * 支持无损压缩（WebP / AVIF）
 */
class Imagick_Handler {

	/**
	 * 检测 Imagick 是否可用且支持所需格式
	 *
	 * @param string $format 目标格式（webp / avif / '' 表示原格式）
	 * @return bool
	 */
	public static function is_available( $format = '' ) {
		if ( ! extension_loaded( 'imagick' ) ) {
			return false;
		}
		if ( empty( $format ) ) {
			return true;
		}
		try {
			$img  = new \Imagick();
			$fmts = $img->queryFormats();
			return in_array( strtoupper( $format ), $fmts, true );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * 计算单张图片处理时 ImageMagick 可用的最优线程数（自适应多核）。
	 *
	 * 策略：
	 *   - 探测 CPU 逻辑核心数（失败回退到 2，保守起见）。
	 *   - 单核 / 双核：用 1 线程，给系统留余地，避免拖死整站。
	 *   - 多核：核心数 ÷ 2，吃一半核保证速度又不独占。
	 *   - 封顶 8 线程：防止 48 核这类巨核机器单张图就吃满几十核，
	 *     在并发上传时把所有核抢光导致整站卡死。
	 *
	 * 结果在请求内静态缓存，避免每张图都重复探测。
	 *
	 * @return int 1–8 之间的线程数
	 */
	public static function get_optimal_threads() {
		static $threads = null;
		if ( null !== $threads ) {
			return $threads;
		}

		$cores = self::detect_cpu_cores();

		if ( $cores <= 2 ) {
			$threads = 1;
		} else {
			$half    = (int) floor( $cores / 2 );
			$threads = max( 1, min( $half, 8 ) ); // ÷2 后封顶 8
		}

		/**
		 * 允许通过 filter 覆盖自适应结果（高级用户/特殊环境）。
		 *
		 * @param int $threads 自适应计算出的线程数
		 * @param int $cores   探测到的 CPU 核心数
		 */
		$threads = (int) apply_filters( 'sakura_imgopt_imagick_threads', $threads, $cores );
		$threads = max( 1, $threads );

		return $threads;
	}

	/**
	 * is_readable() 但不触发 open_basedir 警告。
	 *
	 * 主机开启 open_basedir 时，探测 /proc/cpuinfo 会留下一条 PHP warning，
	 * 每次真实上传都会写一条进错误日志。项目约定不使用 `@`，
	 * 这里只在探测期间临时吞掉 E_WARNING。
	 *
	 * @param string $path 路径。
	 * @return bool
	 */
	private static function readable( string $path ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped suppression of the expected open_basedir warning; restored immediately.
		set_error_handler(
			static function () {
				return true;
			},
			E_WARNING
		);
		$readable = is_readable( $path );
		restore_error_handler();

		return $readable;
	}

	/**
	 * 探测 CPU 逻辑核心数，多种方式回退，全部失败返回 2。
	 *
	 * 因服务器普遍禁用 exec/shell_exec，这里优先用纯 PHP 可读的途径：
	 *   ① /proc/cpuinfo（Linux，无需任何危险函数）
	 *   ② nproc 环境变量
	 *   ③ 回退 2
	 *
	 * @return int
	 */
	private static function detect_cpu_cores() {
		// ① Linux：直接读 /proc/cpuinfo，统计 processor 行数。
		if ( self::readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = File_System::read( '/proc/cpuinfo' );
			if ( false !== $cpuinfo ) {
				$count = preg_match_all( '/^processor\s*:/m', $cpuinfo );
				if ( 0 < $count ) {
					return $count;
				}
			}
		}

		// ② 环境变量（部分容器会注入）。
		$env = getenv( 'NUMBER_OF_PROCESSORS' );
		if ( false !== $env && 0 < (int) $env ) {
			return (int) $env;
		}

		// ③ 保守回退。
		return 2;
	}

	/**
	 * 处理单张图片
	 *
	 * @param string $source_path  源文件路径
	 * @param string $dest_path    输出路径（可为空，默认覆盖源文件）
	 * @param array  $opts         选项
	 *                             - quality: int     压缩质量 1–100
	 *                             - format: string   目标格式（webp/avif/空=原格式）
	 * @return array ['success' => bool, 'path' => string, 'size_before' => int, 'size_after' => int]
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
			$img = new \Imagick( $source_path );

			// ── 放开 ImageMagick OpenMP 多核（自适应线程，吃半核封顶 8）──
			// 底层 ImageMagick 编译时带 OpenMP，缩放/转码/滤镜会自动分摊到多核。
			// setResourceLimit(THREAD) 控制单张图可用的线程数；常量不存在则静默跳过。
			if ( defined( '\Imagick::RESOURCETYPE_THREAD' ) ) {
				try {
					$img->setResourceLimit( \Imagick::RESOURCETYPE_THREAD, self::get_optimal_threads() );
				} catch ( \Throwable $e ) {
						// 设置失败不影响处理，退回引擎默认线程数。
						unset( $e );
				}
			}

			// 处理透明度（PNG/GIF 转其他格式时保留背景）
			$img->setImageBackgroundColor( 'white' );
			$alpha_channel = $img->getImageAlphaChannel();

			$target_format = empty( $format ) ? strtoupper( $img->getImageFormat() ) : strtoupper( $format );

			// ── 修复：格式转换时同步修改文件扩展名 ──
			// writeImage() 输出格式由文件扩展名决定，不按 setImageFormat()
			$old_ext    = strtolower( pathinfo( $dest_path, PATHINFO_EXTENSION ) );
			$ext_map    = array(
				'JPEG' => 'jpg',
				'JPG'  => 'jpg',
				'PNG'  => 'png',
				'GIF'  => 'gif',
				'WEBP' => 'webp',
				'AVIF' => 'avif',
			);
			$target_ext = $ext_map[ $target_format ] ?? $old_ext;
			if ( $target_ext !== $old_ext ) {
				$dest_path = preg_replace( '/\.' . preg_quote( $old_ext, '/' ) . '$/i', '.' . $target_ext, $dest_path );
			}

			switch ( $target_format ) {
				case 'WEBP':
					$img->setImageFormat( 'WEBP' );
					$img->setImageCompressionQuality( $quality );
					$img->setOption( 'webp:lossless', 'false' );
					$img->setOption( 'webp:method', '4' );
					// 处理透明度
					if ( $alpha_channel ) {
						$img->setOption( 'webp:alpha-quality', '100' );
					}
					break;

				case 'AVIF':
					$img->setImageFormat( 'AVIF' );
					// AVIF 使用 setCompressionQuality 而非 setImageCompressionQuality
					$img->setCompressionQuality( $quality );
					$img->setOption( 'avif:lossless', 'false' );
					// AVIF 自动处理透明度
					break;

				default:
					// JPEG / PNG 压缩
					$img->setImageFormat( $target_format );
					// 强制重编码：Imagick 读 JPEG→写 JPEG 时默认不重编码
					if ( 'JPEG' === $target_format || 'JPG' === $target_format ) {
						$img->setImageCompression( \Imagick::COMPRESSION_JPEG );
					}
					$img->setImageCompressionQuality( $quality );
					// JPEG 使用更高效的压缩
					if ( in_array( $target_format, array( 'JPEG', 'JPG' ), true ) ) {
						$img->setOption( 'jpeg:optimize-coding', 'true' );
					}
					break;
			}

			// ICC 色彩配置是「元数据」中会影响成色的部分：直接 stripImage() 会让
			// Display P3 / Adobe RGB / CMYK 图片在浏览器里明显偏色。仅保留 ICC，
			// 其余 EXIF/注释仍剥离以控制体积；可用过滤器关闭。
			$icc_profile = '';
			if ( apply_filters( 'sakura_imgopt_preserve_icc', true, $source_path, $dest_path ) ) {
				try {
					$icc_profile = (string) $img->getImageProfile( 'icc' );
				} catch ( \Throwable $e ) {
					$icc_profile = '';
				}
			}

			// 条带化（移除配置文件元数据，减小体积）
			$img->stripImage();

			if ( '' !== $icc_profile ) {
				try {
					$img->setImageProfile( 'icc', $icc_profile );
				} catch ( \Throwable $e ) {
					// 目标格式不支持 ICC 时不让整张图失败，仅放弃配置文件。
					unset( $e );
				}
			}

			// Imagick 无法可靠覆盖自身读取的文件（文件锁），先写临时文件再 rename
			$tmp_path = $dest_path . '.sakuratmp_' . bin2hex( random_bytes( 4 ) );
			$img->writeImage( $tmp_path );
			$img->clear();
			$img->destroy();

			// M-08：候选文件不小于原图时丢弃候选，原图与备份都不改动。
			$candidate_size = file_exists( $tmp_path ) ? (int) filesize( $tmp_path ) : 0;
			if ( No_Gain_Guard::should_keep_original( (int) $size_before, $candidate_size, $source_path ) ) {
				File_System::delete( $tmp_path );
				$tmp_path = '';
				return No_Gain_Guard::skipped_result( $source_path, (int) $size_before, $candidate_size, 'imagick' );
			}

			if ( $dest_path !== $source_path ) {
				// 目标名可能已被并发上传占用：先原子占位再落盘，避免同名覆盖。
				$moved = File_System::move_to_unique( $tmp_path, $dest_path );
				if ( ! $moved['ok'] ) {
					throw new \RuntimeException( __( '无法原子替换处理后的图片文件', 'sakura-imgopt' ) );
				}
				$dest_path = $moved['path'];
			} elseif ( ! File_System::move( $tmp_path, $dest_path ) ) {
				// 原地压缩：rename 跨分区可能失败，用 copy + delete 兜底；任一失败都不能伪报成功。
				if ( ! File_System::copy( $tmp_path, $dest_path ) || ! File_System::delete( $tmp_path ) ) {
					throw new \RuntimeException( __( '无法原子替换处理后的图片文件', 'sakura-imgopt' ) );
				}
			}

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
			// 写入失败、rename 失败或进程异常时都可能残留 .sakuratmp_*，
			// 这里兜底清理；成功路径上 rename 已移走文件，此判断为空操作。
			if ( '' !== $tmp_path && file_exists( $tmp_path ) ) {
				File_System::delete( $tmp_path );
			}
		}
	}
}
