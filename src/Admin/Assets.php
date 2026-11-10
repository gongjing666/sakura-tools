<?php
/**
 * Admin asset loading for plugin pages.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the shared admin stylesheet and the compression-mode form script.
 */
final class Assets {

	/**
	 * Enqueue assets only on plugin pages.
	 *
	 * @param string             $hook       Current admin page hook.
	 * @param array<int, string> $page_hooks Registered plugin page hooks.
	 * @return void
	 */
	public static function enqueue( string $hook, array $page_hooks ): void {
		if ( ! in_array( $hook, $page_hooks, true ) ) {
			return;
		}

		self::enqueue_style();

		wp_enqueue_script(
			'sakura-imgopt-admin',
			SAKURA_TOOLS_URL . 'assets/admin.js',
			array( 'jquery' ),
			self::script_version(),
			true
		);
		wp_set_script_translations( 'sakura-imgopt-admin', 'sakura-imgopt', SAKURA_TOOLS_PATH . 'languages' );

		self::enqueue_layout();
	}

	/**
	 * Enqueue the shared stylesheet and the extension stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		wp_enqueue_style(
			'sakura-imgopt-admin',
			SAKURA_TOOLS_URL . 'assets/admin.css',
			array(),
			self::version()
		);

		wp_enqueue_style(
			'sakura-imgopt-admin-ext',
			SAKURA_TOOLS_URL . 'assets/admin-ext.css',
			array( 'sakura-imgopt-admin' ),
			self::ext_version()
		);

		// Shared chrome (module switcher, tab strip) rides along with the theme.
		Shell::enqueue();
	}

	/**
	 * Enqueue everything a plugin screen needs, in one call.
	 *
	 * Modules used to assemble this list themselves and each one picked a
	 * slightly different subset, which is how screens ended up inconsistent.
	 *
	 * @return void
	 */
	public static function enqueue_page(): void {
		self::enqueue_style();

		wp_enqueue_script(
			'sakura-imgopt-admin',
			SAKURA_TOOLS_URL . 'assets/admin.js',
			array( 'jquery' ),
			self::script_version(),
			true
		);
		wp_set_script_translations( 'sakura-imgopt-admin', 'sakura-imgopt', SAKURA_TOOLS_PATH . 'languages' );

		self::enqueue_layout();
	}

	/**
	 * Version the extension stylesheet by file modification time.
	 *
	 * @return string
	 */
	public static function ext_version(): string {
		$path = SAKURA_TOOLS_PATH . 'assets/admin-ext.css';
		return is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION;
	}

	/**
	 * Version the shared stylesheet by file modification time.
	 *
	 * @return string
	 */
	public static function layout_version(): string {
		// The layout engine ships a stylesheet and a script: version both, or a
		// CSS-only fix keeps being served from the browser cache.
		$stamp = array();

		foreach ( array( 'assets/admin-layout.js', 'assets/admin-layout.css' ) as $relative ) {
			$path = SAKURA_TOOLS_PATH . $relative;
			if ( is_readable( $path ) ) {
				$stamp[] = (string) filemtime( $path ) . '.' . (string) filesize( $path );
			}
		}

		return '' === implode( '', $stamp ) ? SAKURA_TOOLS_VERSION : SAKURA_TOOLS_VERSION . '.' . implode( '.', $stamp );
	}

	public static function version(): string {
		$path = SAKURA_TOOLS_PATH . 'assets/admin.css';
		return is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION;
	}

	/**
	 * Version the admin script by file modification time.
	 *
	 * @return string
	 */
	public static function script_version(): string {
		$path = SAKURA_TOOLS_PATH . 'assets/admin.js';
		return is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION;
	}

	/**
	 * Load the graphite theme on every plugin screen.
	 *
	 * The theme is scoped by admin page slug so it never touches other admin
	 * screens; all four modules share the same visual layer.
	 *
	 * @return void
	 */
	public static function enqueue_theme(): void {
		if ( ! wp_style_is( 'sakura-imgopt-admin', 'enqueued' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection for asset loading.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		if ( ! in_array( $page, array( 'sakura-tools', 'sakura-tools-image', 'sakura-tools-storage', 'sakura-tools-files' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'sakura-imgopt-theme',
			SAKURA_TOOLS_URL . 'assets/admin-theme.css',
			array( 'sakura-imgopt-unify' ),
			self::theme_version()
		);
	}

	/**
	 * Version the graphite theme by file modification time.
	 *
	 * @return string
	 */
	public static function theme_version(): string {
		$path = SAKURA_TOOLS_PATH . 'assets/admin-theme.css';

		return is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION;
	}

	/**
	 * Version the unified visual layer by file modification time.
	 *
	 * @return string
	 */
	public static function unify_version(): string {
		$path = SAKURA_TOOLS_PATH . 'assets/admin-unify.css';
		return is_readable( $path ) ? SAKURA_TOOLS_VERSION . '.' . (string) filemtime( $path ) . '.' . (string) filesize( $path ) : SAKURA_TOOLS_VERSION;
	}

	/**
	 * Load the unified visual layer last.
	 *
	 * The layer only overrides shape / type / border tokens, so it has to print
	 * after every other plugin stylesheet. It runs on a very late hook priority
	 * and is gated on the shared admin sheet, which only plugin screens load, so
	 * the queue order is guaranteed without depending on optional handles: a
	 * dependency that is not registered yet would silently drop the style.
	 *
	 * @return void
	 */
	public static function enqueue_unify(): void {
		if ( ! wp_style_is( 'sakura-imgopt-admin', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'sakura-imgopt-unify',
			SAKURA_TOOLS_URL . 'assets/admin-unify.css',
			array(),
			self::unify_version()
		);
	}

	/**
	 * Load the card-layout engine on screens that expose layout groups.
	 */
	public static function enqueue_layout(): void {
		wp_enqueue_style(
			'sakura-imgopt-layout',
			SAKURA_TOOLS_URL . 'assets/admin-layout.css',
			array( 'sakura-imgopt-admin' ),
			self::layout_version()
		);

		$gaps = Layout::gaps();
		wp_add_inline_style(
			'sakura-imgopt-layout',
			sprintf(
				'.sakura-sync-app{--gap-x:%1$dpx;--gap-y:%2$dpx;}',
				(int) $gaps['gapX'],
				(int) $gaps['gapY']
			)
		);

		wp_enqueue_script(
			'sakura-imgopt-layout',
			SAKURA_TOOLS_URL . 'assets/admin-layout.js',
			array(),
			self::layout_version(),
			true
		);

		wp_localize_script(
			'sakura-imgopt-layout',
			'sakuraLayout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Layout::NONCE ),
				'pages'   => Layout::saved(),
				'strings' => array(
					'button' => __( '布局', 'sakura-imgopt' ),
					'edit'   => __( '布局修改', 'sakura-imgopt' ),
					'save'   => __( '布局保存', 'sakura-imgopt' ),
					'drag'   => __( '按住标题拖动，调整卡片顺序', 'sakura-imgopt' ),
					'wider'  => __( '左右拖动调整宽度', 'sakura-imgopt' ),
					'taller' => __( '上下拖动调整高度', 'sakura-imgopt' ),
					'reset'  => __( '恢复默认布局', 'sakura-imgopt' ),
					'grid'   => __( '显示网格', 'sakura-imgopt' ),
					'hint'   => __( '拖动卡片标题可排序；拖动卡片右侧、底部或右下角可调整宽高。', 'sakura-imgopt' ),
				),
			)
		);
	}

}
