<?php
/**
 * Asset loading for pages on the refactored UI stack.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared CSS/JS stack for plugin pages.
 */
final class Page_Assets {

	/**
	 * Per-page data passed to the page controller.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $data = array();

	/**
	 * Attach data to one page. Must run before Assets::enqueue_page().
	 *
	 * @param string              $page Page id.
	 * @param array<string,mixed> $data Data merged into SakuraAdmin.page.data.
	 * @return void
	 */
	public static function set_data( string $page, array $data ): void {
		if ( ! isset( self::$data[ $page ] ) || ! is_array( self::$data[ $page ] ) ) {
			self::$data[ $page ] = array();
		}

		self::$data[ $page ] = array_merge( self::$data[ $page ], $data );
	}

	/**
	 * Enqueue the stack for one page.
	 *
	 * @param string $page Page id.
	 * @return void
	 */
	public static function enqueue( string $page ): void {
		$module = explode( '-', $page, 2 )[0];
		$base   = 'sakura-admin';

		wp_enqueue_style(
			$base . '-tokens',
			SAKURA_TOOLS_URL . 'assets/ui/tokens.css',
			array(),
			self::group_version( array( 'assets/ui/tokens.css' ) )
		);

		// The gap fields on the system page are site-wide, so feed the saved
		// values into the grid instead of relying on the token fallbacks.
		$gaps = Layout::gaps();
		wp_add_inline_style(
			$base . '-tokens',
			sprintf(
				'.sakura-admin{--g-grid-gap-x:%1$dpx;--g-grid-gap-y:%2$dpx;}',
				(int) $gaps['gapX'],
				(int) $gaps['gapY']
			)
		);

		wp_enqueue_style(
			$base . '-base',
			SAKURA_TOOLS_URL . 'assets/ui/base.css',
			array( $base . '-tokens' ),
			self::group_version( array( 'assets/ui/base.css' ) )
		);

		wp_enqueue_style(
			$base . '-components',
			SAKURA_TOOLS_URL . 'assets/ui/components.css',
			array( $base . '-base' ),
			self::group_version( array( 'assets/ui/components.css' ) )
		);

		/*
		 * Card placement needs no stylesheet: UI::card() writes grid-column and
		 * order straight onto each card from the table in src/Admin/Layout.php,
		 * so the first paint already has the final arrangement. There is no drag
		 * engine, no absolute positioning and no script to run on load.
		 */
		$page_css      = 'assets/ui/pages/' . $module . '.css';
		$module_handle = $base . '-page-' . $module;
		$module_loaded = is_readable( SAKURA_TOOLS_PATH . $page_css );

		if ( $module_loaded ) {
			wp_enqueue_style(
				$module_handle,
				SAKURA_TOOLS_URL . $page_css,
				array( $base . '-components' ),
				self::group_version( array( $page_css ) )
			);
		}

		/*
		 * The shell swaps modules in place, so every stylesheet it can reach has to
		 * be in the document before the first swap — otherwise the incoming page
		 * paints unstyled for one frame. The handles mirror the normal path, so a
		 * page that already loaded its own module sheet is not charged twice.
		 */
		foreach ( App_Manifest::styles() as $shared_css ) {
			if ( ! is_readable( SAKURA_TOOLS_PATH . $shared_css ) ) {
				continue;
			}

			wp_enqueue_style(
				$base . '-page-' . basename( $shared_css, '.css' ),
				SAKURA_TOOLS_URL . $shared_css,
				array( $base . '-components' ),
				self::group_version( array( $shared_css ) )
			);
		}

		// Optional page-specific stylesheet for tabs that need more than the
		// shared module styles (for example files-zibll.css). A module with a
		// single page may ship only this file, so the dependency is added only
		// when the module stylesheet was actually enqueued — a missing handle
		// makes WP_Styles warn about an unregistered dependency.
		$tab_css = 'assets/ui/pages/' . $page . '.css';
		if ( is_readable( SAKURA_TOOLS_PATH . $tab_css ) ) {
			$tab_deps = array( $base . '-components' );

			if ( $module_loaded ) {
				$tab_deps[] = $module_handle;
			}

			wp_enqueue_style(
				$base . '-page-' . $page,
				SAKURA_TOOLS_URL . $tab_css,
				$tab_deps,
				self::group_version( array( $tab_css ) )
			);
		}

		$scripts = array(
			'core' => array( 'assets/ui/js/core.js', array(), true ),
			'api'  => array( 'assets/ui/js/api.js', array( $base . '-core' ), true ),
			'ui'   => array( 'assets/ui/js/ui.js', array( $base . '-api' ), true ),
		);

		foreach ( $scripts as $name => $definition ) {
			wp_enqueue_script(
				$base . '-' . $name,
				SAKURA_TOOLS_URL . $definition[0],
				$definition[1],
				self::group_version( array( $definition[0] ) ),
				(bool) $definition[2]
			);
		}

		/*
		 * The shell owns the content root on every route; there is no second
		 * renderer, so this is the only React bundle the admin loads. Screens
		 * outside the route table (the media progress sheet) keep the plain
		 * classic scripts and must not pay for React.
		 */
		$shell_bundle = 'assets/ui/dist/admin-app.js';

		if ( App_Manifest::is_route( $page ) && is_readable( SAKURA_TOOLS_PATH . $shell_bundle ) ) {
			wp_enqueue_script(
				$base . '-app',
				SAKURA_TOOLS_URL . $shell_bundle,
				array( $base . '-core' ),
				self::asset_version( $shell_bundle ),
				true
			);
		}

		/*
		 * Classic controller scripts still serve the screens outside the shell's
		 * route table (the media progress sheet, for example). Every route in
		 * App_Manifest is React now, so those never load two handlers.
		 */
		$page_js = 'assets/ui/js/pages/' . $page . '.js';
		if ( is_readable( SAKURA_TOOLS_PATH . $page_js ) && ! App_Manifest::is_route( $page ) ) {
			wp_enqueue_script(
				$base . '-page-' . $page,
				SAKURA_TOOLS_URL . $page_js,
				array( $base . '-ui' ),
				self::group_version( array( $page_js ) ),
				true
			);
		}

		$config = array(
			'page'    => $page,
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'restUrl' => esc_url_raw( rest_url() ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'data'    => self::data( $page ),
			'strings' => array(
				'saved'      => __( '已保存', 'sakura-imgopt' ),
				'saveFailed' => __( '保存失败，请稍后重试。', 'sakura-imgopt' ),
				'confirm'    => __( '确认', 'sakura-imgopt' ),
				'cancel'     => __( '取消', 'sakura-imgopt' ),
				'copied'     => __( '已复制', 'sakura-imgopt' ),
				'loading'    => __( '处理中…', 'sakura-imgopt' ),
			),
		);

		wp_localize_script( $base . '-core', 'sakuraPage', $config );
		wp_set_script_translations( $base . '-core', 'sakura-imgopt', SAKURA_TOOLS_PATH . 'languages' );
	}

	/**
	 * Data for one page.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>
	 */
	public static function data( string $page ): array {
		return isset( self::$data[ $page ] ) ? self::$data[ $page ] : array();
	}

	/**
	 * Cache-busting version for a single asset, for callers outside this class.
	 *
	 * @param string $relative Plugin-relative path.
	 * @return string
	 */
	public static function asset_version( string $relative ): string {
		return self::group_version( array( $relative ) );
	}

	/**
	 * Enqueue just the pieces a cross-page admin notice needs.
	 *
	 * `Feedback::admin_notice_html()` is rendered from `admin_notices`, which
	 * fires on screens that never load the plugin UI stack (media library,
	 * dashboard, users). Those notices used to arrive as bare text and read
	 * like a native WordPress notice. Tokens and components are enough for the
	 * alert skin; base.css owns the page canvas and must stay off those
	 * screens, so it is deliberately not a dependency here.
	 *
	 * @return void
	 */
	public static function enqueue_notice_style(): void {
		$base = 'sakura-admin';

		if ( wp_style_is( $base . '-components', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			$base . '-tokens',
			SAKURA_TOOLS_URL . 'assets/ui/tokens.css',
			array(),
			self::group_version( array( 'assets/ui/tokens.css' ) )
		);

		wp_enqueue_style(
			$base . '-components',
			SAKURA_TOOLS_URL . 'assets/ui/components.css',
			array( $base . '-tokens' ),
			self::group_version( array( 'assets/ui/components.css' ) )
		);
	}

	/**
	 * Cache-busting version from mtime and size.
	 *
	 * @param array<int, string> $relative Plugin-relative paths.
	 * @return string
	 */
	private static function group_version( array $relative ): string {
		$stamp = SAKURA_TOOLS_VERSION;
		$size  = 0;

		foreach ( $relative as $path ) {
			$absolute = SAKURA_TOOLS_PATH . $path;
			if ( is_readable( $absolute ) ) {
				$stamp .= '.' . (string) filemtime( $absolute );
				$size  += (int) filesize( $absolute );
			}
		}

		return $stamp . '.' . (string) $size;
	}
}
