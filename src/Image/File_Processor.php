<?php
/**
 * Single-file image processing policy and engine dispatch.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a file is processable, which options apply, and which engine wins.
 */
class File_Processor {

	/**
	 * Engine selection service.
	 *
	 * @var Engine_Manager
	 */
	private Engine_Manager $engine;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param Engine_Manager|null                 $engine   Engine manager.
	 * @param Settings_Repository_Interface|null  $settings Settings repository.
	 */
	public function __construct( ?Engine_Manager $engine = null, ?Settings_Repository_Interface $settings = null ) {
		$this->engine   = $engine ?? new Engine_Manager();
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * Process one file.
	 *
	 * @param string $file_path Absolute path.
	 * @return array<string,mixed>
	 */
	public function process_file( $file_path ) {
		$skip_reason = $this->get_processing_skip_reason( $file_path );
		if ( '' !== $skip_reason ) {
			return array(
				'success'     => false,
				'path'        => $file_path,
				'error'       => $this->get_skip_message( $skip_reason, $file_path ),
				'skipped'     => true,
				'skip_reason' => $skip_reason,
				'size_before' => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
				'size_after'  => 0,
				'engine'      => '',
			);
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$opts = $this->get_processing_options();
		$mode = $this->settings->compression_mode();

		if ( 'compress_convert' === $mode ) {
			$opts['format'] = $this->settings->convert_format();
		} elseif ( 'convert_only' === $mode ) {
			$opts['format']  = $this->settings->convert_format();
			$opts['quality'] = 92;
		} else {
			$opts['format'] = '';
		}

		return $this->engine->process( $file_path, $opts );
	}

	/**
	 * Whether the current source extension is enabled for processing.
	 *
	 * @param string $file_path Absolute path.
	 * @return bool
	 */
	public function should_process_file( $file_path ) {
		$ext = strtolower( pathinfo( (string) $file_path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		$selected = $this->settings->source_formats();
		return in_array( $ext, $selected, true );
	}

	/**
	 * Return why a file must be skipped; empty means processable.
	 *
	 * @param string $file_path Absolute path.
	 * @return string unsupported|animated|too_large|''
	 */
	public function get_processing_skip_reason( $file_path ) {
		if ( ! $this->should_process_file( $file_path ) ) {
			return 'unsupported';
		}

		if ( $this->exceeds_file_size_limit( $file_path ) ) {
			return 'too_large_file';
		}

		$info = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $info ) && ! empty( $info[0] ) && ! empty( $info[1] ) ) {
			if ( $this->exceeds_resolution_limit( (int) $info[0], (int) $info[1] ) ) {
				return 'too_large_resolution';
			}

			$pixels = (float) $info[0] * (float) $info[1];
			if ( $pixels > self::get_max_pixels() ) {
				return 'too_large';
			}
		}

		if ( Animation_Detector::is_animated( $file_path ) ) {
			return 'animated';
		}

		return '';
	}

	/**
	 * Whether the file exceeds the configured megabyte ceiling.
	 *
	 * @param string $file_path Absolute path.
	 * @return bool
	 */
	private function exceeds_file_size_limit( $file_path ) {
		$limit = max( 0, min( 1024, (int) $this->settings->get( 'max_file_size_mb', Settings_Repository::DEFAULT_MAX_FILE_SIZE_MB ) ) );
		if ( 0 === $limit || ! is_file( $file_path ) ) {
			return false;
		}

		$size = filesize( $file_path );
		if ( false === $size ) {
			return false;
		}

		return $size > $limit * 1048576;
	}

	/**
	 * Whether the image does not fit the configured 4K-style frame.
	 *
	 * The frame is orientation-agnostic: 3840×2160 and 2160×3840 both fit.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return bool
	 */
	private function exceeds_resolution_limit( int $width, int $height ) {
		$max_width  = max( 0, min( 20000, (int) $this->settings->get( 'max_image_width', Settings_Repository::DEFAULT_MAX_IMAGE_WIDTH ) ) );
		$max_height = max( 0, min( 20000, (int) $this->settings->get( 'max_image_height', Settings_Repository::DEFAULT_MAX_IMAGE_HEIGHT ) ) );

		if ( 0 === $max_width && 0 === $max_height ) {
			return false;
		}

		$width_limit  = $max_width > 0 ? $max_width : PHP_INT_MAX;
		$height_limit = $max_height > 0 ? $max_height : PHP_INT_MAX;

		$fits = ( $width <= $width_limit && $height <= $height_limit )
			|| ( $width <= $height_limit && $height <= $width_limit );

		return ! $fits;
	}

	/**
	 * Maximum pixels per image.
	 *
	 * @return int
	 */
	public static function get_max_pixels() {
		$max = (int) apply_filters( 'sakura_imgopt_max_pixels', 24000000 );
		return max( 1, $max );
	}

	/**
	 * Whether compression is enabled globally.
	 *
	 * @return bool
	 */
	public function is_compression_enabled() {
		return (bool) $this->settings->compression_enabled();
	}

	/**
	 * Fast MIME lookup by resulting extension.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public function mime_from_path( $path ) {
		$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);
		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}
		$type = wp_check_filetype( $path );
		return ! empty( $type['type'] ) ? $type['type'] : '';
	}

	/**
	 * Quality/format options for the current mode.
	 *
	 * @return array{quality:int,format:string}
	 */
	private function get_processing_options() {
		return array(
			'quality' => (int) $this->settings->quality(),
			'format'  => '',
		);
	}

	/**
	 * Human-readable skip message.
	 *
	 * @param string $reason Skip reason.
	 * @return string
	 */
	private function get_skip_message( $reason, $file_path = '' ) {
		if ( 'animated' === $reason ) {
			return __( '检测到动图（GIF/APNG/动态 WebP），已跳过处理以保留全部帧。', 'sakura-imgopt' );
		}

		if ( 'too_large_file' === $reason ) {
			$limit = max( 0, min( 1024, (int) $this->settings->get( 'max_file_size_mb', Settings_Repository::DEFAULT_MAX_FILE_SIZE_MB ) ) );
			$size  = ( is_string( $file_path ) && is_file( $file_path ) ) ? (float) filesize( $file_path ) : 0.0;

			return sprintf(
				/* translators: 1: current size in MB, 2: configured limit in MB. */
				__( '文件体积 %1$s MB 超过上限 %2$d MB，已直接放行（不备份、不压缩、不转换）。', 'sakura-imgopt' ),
				sprintf( '%.1f', $size / 1048576 ),
				$limit
			);
		}

		if ( 'too_large_resolution' === $reason ) {
			$info       = ( is_string( $file_path ) && is_file( $file_path ) ) ? @getimagesize( $file_path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$width      = is_array( $info ) ? (int) $info[0] : 0;
			$height     = is_array( $info ) ? (int) $info[1] : 0;
			$max_width  = max( 0, min( 20000, (int) $this->settings->get( 'max_image_width', Settings_Repository::DEFAULT_MAX_IMAGE_WIDTH ) ) );
			$max_height = max( 0, min( 20000, (int) $this->settings->get( 'max_image_height', Settings_Repository::DEFAULT_MAX_IMAGE_HEIGHT ) ) );

			return sprintf(
				/* translators: 1: width, 2: height, 3: max width, 4: max height. */
				__( '分辨率 %1$d×%2$d 超过上限 %3$d×%4$d，已直接放行（不备份、不压缩、不转换）。', 'sakura-imgopt' ),
				$width,
				$height,
				$max_width,
				$max_height
			);
		}

		if ( 'too_large' === $reason ) {
			return __( '图片像素超过处理上限，已跳过以避免内存耗尽。', 'sakura-imgopt' );
		}

		return __( '文件格式不在允许处理的列表中，已跳过。', 'sakura-imgopt' );
	}
}
