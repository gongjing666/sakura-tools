<?php
/**
 * Read-only server, PHP and image engine report.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the values shown on the Basic Settings page.
 *
 * Everything here is read-only: no option is written, no PHP setting changes
 * and no image is processed. Values that PHP does not expose are reported as
 * "not available" instead of being guessed.
 */
final class Environment_Report {

	/**
	 * Collect the current environment report.
	 *
	 * @return array<string, mixed>
	 */
	public static function collect(): array {
		$memory_raw = (string) ini_get( 'memory_limit' );
		$upload_raw = (string) ini_get( 'upload_max_filesize' );
		$post_raw   = (string) ini_get( 'post_max_size' );
		$time_raw   = (string) ini_get( 'max_execution_time' );
		$memory     = self::bytes( $memory_raw );
		$upload     = self::bytes( $upload_raw );
		$post       = self::bytes( $post_raw );
		$imagick    = self::imagick_version();
		$gd         = self::gd_version();
		$primary    = '' !== $imagick ? __( 'Imagick', 'sakura-imgopt' ) : ( '' !== $gd ? __( 'GD', 'sakura-imgopt' ) : __( '未检测到', 'sakura-imgopt' ) );
		$webp       = self::editor_supports( 'image/webp' );
		$avif       = self::editor_supports( 'image/avif' );
		$opcache    = self::opcache_state();
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		$db_version = self::db_version();
		$timezone   = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : (string) get_option( 'timezone_string' );

		$formats = array(
			'JPEG' => self::editor_supports( 'image/jpeg' ),
			'PNG'  => self::editor_supports( 'image/png' ),
			'WebP' => $webp,
			'AVIF' => $avif,
		);

		$checks = array(
			self::check( 'php', __( 'PHP 版本', 'sakura-imgopt' ), version_compare( PHP_VERSION, '8.2', '>=' ) ? 'ok' : 'fail', PHP_VERSION, __( '插件要求 PHP 8.2 或更高。', 'sakura-imgopt' ) ),
			self::check( 'wordpress', __( 'WordPress', 'sakura-imgopt' ), version_compare( $wp_version, '6.4', '>=' ) ? 'ok' : 'fail', $wp_version, __( '插件要求 WordPress 6.4 或更高。', 'sakura-imgopt' ) ),
			self::check( 'memory', __( '内存上限', 'sakura-imgopt' ), ( $memory < 0 || $memory >= 256 * 1024 * 1024 ) ? 'ok' : 'warn', $memory_raw, __( '建议 256M 或更高，处理大图更稳。', 'sakura-imgopt' ) ),
			self::check( 'upload', __( '上传上限', 'sakura-imgopt' ), ( $upload < 0 || $upload >= 32 * 1024 * 1024 ) ? 'ok' : 'warn', $upload_raw, __( '建议 32M 或更高，避免大图上传失败。', 'sakura-imgopt' ) ),
			self::check( 'engine', __( '图像引擎', 'sakura-imgopt' ), ( '' !== $imagick || '' !== $gd ) ? 'ok' : 'fail', $primary, __( '需要 Imagick 或 GD 扩展。', 'sakura-imgopt' ) ),
			self::check( 'webp', __( 'WebP 支持', 'sakura-imgopt' ), $webp ? 'ok' : 'warn', $webp ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), __( '启用后可输出更小的 WebP 图片。', 'sakura-imgopt' ) ),
			self::check( 'avif', __( 'AVIF 支持', 'sakura-imgopt' ), $avif ? 'ok' : 'warn', $avif ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), __( '缺少 AVIF 时会回退到 WebP 或原格式。', 'sakura-imgopt' ) ),
			self::check( 'opcache', __( 'OPcache', 'sakura-imgopt' ), $opcache['enabled'] ? 'ok' : 'warn', $opcache['label'], __( '开启后 PHP 不必每次请求都重新编译。', 'sakura-imgopt' ) ),
			self::check( 'curl', __( 'cURL', 'sakura-imgopt' ), extension_loaded( 'curl' ) ? 'ok' : 'warn', extension_loaded( 'curl' ) ? __( '已启用', 'sakura-imgopt' ) : __( '未启用', 'sakura-imgopt' ), __( 'S3 上传和远程检测会用到这里。', 'sakura-imgopt' ) ),
		);

		$summary = self::summarize( $checks );

		$notes = array();

		if ( ! $opcache['enabled'] ) {
			$notes[] = __( 'OPcache 未启用。PHP 每次请求都会重新编译，建议在宝塔面板的 PHP 设置里开启。', 'sakura-imgopt' );
		}
		if ( $memory >= 0 && $memory < 256 * 1024 * 1024 ) {
			$notes[] = __( '内存上限低于 256M，处理大图或批量优化时可能失败。', 'sakura-imgopt' );
		}
		if ( $upload >= 0 && $upload < 32 * 1024 * 1024 ) {
			$notes[] = __( '上传上限低于 32M，大图可能无法上传；可在 PHP 设置里提高 upload_max_filesize 和 post_max_size。', 'sakura-imgopt' );
		}
		if ( '' === $imagick ) {
			$notes[] = __( '未检测到 Imagick，WordPress 会使用 GD；AVIF 和部分 WebP 能力可能受限。', 'sakura-imgopt' );
		}
		if ( ! $webp ) {
			$notes[] = __( 'WebP 不可用，格式转换会回退到原格式。', 'sakura-imgopt' );
		}
		if ( ! $avif ) {
			$notes[] = __( 'AVIF 不可用，插件会自动回退到 WebP 或原格式。', 'sakura-imgopt' );
		}
		if ( 'cli' === PHP_SAPI ) {
			$notes[] = __( '当前是 CLI 环境；Web 服务器（PHP-FPM）可能使用不同的 php.ini，请以后台页面显示为准。', 'sakura-imgopt' );
		}

		return array(
			'php'        => array(
				'version'            => PHP_VERSION,
				'sapi'               => PHP_SAPI,
				'sapi_label'         => self::sapi_label( PHP_SAPI ),
				'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '',
				'memory_limit'       => $memory_raw,
				'memory_limit_bytes' => $memory,
				'upload_limit_bytes' => $upload,
				'post_limit_bytes'   => $post,
				'max_execution_time' => $time_raw,
				'upload_limit'       => $upload_raw,
				'post_limit'         => $post_raw,
				'opcache'            => $opcache,
			),
			'wordpress'  => array(
				'version'     => $wp_version,
				'db_version'  => $db_version,
				'timezone'    => $timezone,
				'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			),
			'engine'     => array(
				'primary' => $primary,
				'imagick' => $imagick,
				'gd'      => $gd,
				'formats' => $formats,
			),
			'formats'    => array(
				'webp' => $webp,
				'avif' => $avif,
			),
			'extensions' => self::extensions(),
			'checks'     => $checks,
			'summary'    => $summary,
			'notes'      => $notes,
		);
	}

	/**
	 * Count checks by display state.
	 *
	 * Pure on purpose: the view needs pass/warn/fail totals, while check rows
	 * use the more conventional ok/warn/fail states.
	 *
	 * @param array<int, array<string, string>> $checks Check rows.
	 * @return array{pass:int,warn:int,fail:int}
	 */
	public static function summarize( array $checks ): array {
		$summary = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		);

		foreach ( $checks as $check ) {
			$state = isset( $check['state'] ) && 'ok' === $check['state'] ? 'pass' : (string) ( $check['state'] ?? '' );
			if ( isset( $summary[ $state ] ) ) {
				++$summary[ $state ];
			}
		}

		return $summary;
	}

	/**
	 * Build one check row.
	 *
	 * @param string $key   Stable key.
	 * @param string $label Human label.
	 * @param string $state ok|warn|fail.
	 * @param string $value Displayed value.
	 * @param string $note  Explanation shown on hover.
	 * @return array<string, string>
	 */
	private static function check( string $key, string $label, string $state, string $value, string $note ): array {
		return array(
			'key'   => $key,
			'label' => $label,
			'state' => $state,
			'value' => $value,
			'note'  => $note,
		);
	}

	/**
	 * Parse a php.ini size value into bytes. -1 means "no limit".
	 *
	 * @param string $value Raw ini value, e.g. 512M.
	 * @return int
	 */
	private static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}

		$number = (float) $value;
		$unit   = strtolower( substr( $value, -1 ) );

		if ( 'g' === $unit ) {
			$number *= 1024 * 1024 * 1024;
		} elseif ( 'm' === $unit ) {
			$number *= 1024 * 1024;
		} elseif ( 'k' === $unit ) {
			$number *= 1024;
		}

		return (int) $number;
	}

	/**
	 * Human label for the current SAPI.
	 *
	 * @param string $sapi PHP_SAPI value.
	 * @return string
	 */
	private static function sapi_label( string $sapi ): string {
		$map = array(
			'fpm-fcgi'        => __( 'PHP-FPM', 'sakura-imgopt' ),
			'apache2handler'  => __( 'Apache 模块', 'sakura-imgopt' ),
			'cgi-fcgi'        => __( 'CGI / FastCGI', 'sakura-imgopt' ),
			'litespeed'       => __( 'LiteSpeed', 'sakura-imgopt' ),
			'cli-server'      => __( 'PHP 内置服务器', 'sakura-imgopt' ),
			'cli'             => __( 'CLI', 'sakura-imgopt' ),
		);

		return $map[ $sapi ] ?? $sapi;
	}

	/**
	 * Imagick version string, shortened to the ImageMagick version.
	 *
	 * @return string Empty when unavailable.
	 */
	private static function imagick_version(): string {
		if ( ! class_exists( 'Imagick' ) ) {
			return '';
		}

		$version = \Imagick::getVersion();
		$string  = isset( $version['versionString'] ) ? (string) $version['versionString'] : '';

		if ( preg_match( '/ImageMagick\s+([0-9][0-9.\-]*)/i', $string, $matches ) ) {
			return 'ImageMagick ' . $matches[1];
		}

		return $string;
	}

	/**
	 * GD version string.
	 *
	 * @return string Empty when unavailable.
	 */
	private static function gd_version(): string {
		if ( ! function_exists( 'gd_info' ) ) {
			return '';
		}

		$info = gd_info();

		return isset( $info['GD Version'] ) ? (string) $info['GD Version'] : '';
	}

	/**
	 * Ask WordPress whether any registered editor supports a mime type.
	 *
	 * @param string $mime Mime type.
	 * @return bool
	 */
	private static function editor_supports( string $mime ): bool {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			return false;
		}

		return (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
	}

	/**
	 * OPcache state, read without changing any configuration.
	 *
	 * @return array{enabled:bool,label:string}
	 */
	private static function opcache_state(): array {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return array(
				'enabled' => false,
				'label'   => __( '未安装', 'sakura-imgopt' ),
			);
		}

		$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- opcache.restrict_api may warn.

		if ( ! is_array( $status ) ) {
			return array(
				'enabled' => false,
				'label'   => __( '不可读', 'sakura-imgopt' ),
			);
		}

		$enabled = ! empty( $status['opcache_enabled'] );

		return array(
			'enabled' => $enabled,
			'label'   => $enabled ? __( '已启用', 'sakura-imgopt' ) : __( '未启用', 'sakura-imgopt' ),
		);
	}

	/**
	 * Database server version, when $wpdb exposes one.
	 *
	 * @return string
	 */
	private static function db_version(): string {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'db_version' ) ) {
			return '';
		}

		return (string) $wpdb->db_version();
	}

	/**
	 * Extensions the plugin can use, with their loaded state.
	 *
	 * @return array<int, array{name:string,loaded:bool}>
	 */
	private static function extensions(): array {
		$extensions = array(
			'imagick'  => 'Imagick',
			'gd'       => 'GD',
			'exif'     => 'EXIF',
			'fileinfo' => 'Fileinfo',
			'curl'     => 'cURL',
			'mbstring' => 'Mbstring',
			'zip'      => 'Zip',
			'openssl'  => 'OpenSSL',
		);

		$rows = array();

		foreach ( $extensions as $extension => $label ) {
			$rows[] = array(
				'name'   => $label,
				'loaded' => extension_loaded( $extension ),
			);
		}

		return $rows;
	}
}
