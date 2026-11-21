<?php
/**
 * Real Zibll license probe.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the theme's own authorization check exactly once per save.
 *
 * The theme prints its verdict and, on failure, can exit mid-request. The probe
 * therefore buffers that output, catches throwables and keeps a shutdown
 * handler so an exit after the check surface a useful notice on the next
 * request instead of a blank settings screen.
 */
final class Probe {

	/**
	 * Whether the probe returned control to the caller.
	 *
	 * @var bool
	 */
	private static bool $finished = true;

	/**
	 * Whether the shutdown handler was already attached.
	 *
	 * @var bool
	 */
	private static bool $watching = false;

	/**
	 * Run the theme check.
	 *
	 * @return bool|\WP_Error True when the theme accepted the license.
	 */
	public static function run() {
		if ( ! License::has_credentials() ) {
			return new \WP_Error(
				'sakura_zibll_no_license',
				__( '站点没有可用的子比授权信息（zibll_code / post_zat_end_time 为空）。请先在“子比主题设置 → 主题&授权”完成授权。', 'sakura-imgopt' )
			);
		}

		self::load_zibaut();

		if ( ! class_exists( '\ZibAut' ) ) {
			return new \WP_Error(
				'sakura_zibll_no_zibaut',
				__( '没有找到子比的授权类 ZibAut，无法验证授权。请确认主题文件完整。', 'sakura-imgopt' )
			);
		}

		if ( ! self::$watching ) {
			self::$watching = true;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}

		self::$finished = false;
		$level          = ob_get_level();
		ob_start();
		$verdict = false;
		$failure = null;

		try {
			$verdict = (bool) \ZibAut::is_aut();
		} catch ( \Throwable $e ) {
			$failure = $e;
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			self::$finished = true;
		}

		if ( null !== $failure ) {
			return new \WP_Error(
				'sakura_zibll_probe_failed',
				/* translators: %s: exception message raised by the theme. */
				sprintf( __( '子比授权校验失败：%s', 'sakura-imgopt' ), $failure->getMessage() )
			);
		}

		// The theme answers with a boolean as well as by exiting on some
		// failures; a false verdict is a refusal, never a cached success.
		if ( ! $verdict ) {
			return new \WP_Error(
				'sakura_zibll_rejected',
				__( '子比授权校验没有通过（ZibAut::is_aut 返回 false）。请先在“子比主题设置 → 主题&授权”确认授权状态。', 'sakura-imgopt' )
			);
		}

		return true;
	}

	/**
	 * Report a theme that exited before the probe finished.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		if ( self::$finished ) {
			return;
		}

		Notices::set(
			'error',
			__( '子比授权校验在输出后被主题提前终止（ZibAut::is_aut 里执行了 exit），缓存没有写入。请先在子比主题设置里确认授权状态。', 'sakura-imgopt' )
		);
	}

	/**
	 * Load the theme classes ZibAut lives in when the theme did not already.
	 *
	 * @return void
	 */
	private static function load_zibaut(): void {
		if ( class_exists( '\ZibAut' ) || ! function_exists( 'get_template_directory' ) ) {
			return;
		}

		$directory = rtrim( (string) get_template_directory(), '/\\' );

		foreach ( array( '/inc/code/file.php', '/inc/code/code.php' ) as $relative ) {
			$file = $directory . $relative;

			if ( ! is_readable( $file ) ) {
				continue;
			}

			require_once $file; // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Theme path resolved at runtime.
		}
	}
}
