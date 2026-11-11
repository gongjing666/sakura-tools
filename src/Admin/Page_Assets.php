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
 * Loads the new CSS/JS stack for whitelisted pages only.
 *
 * Legacy screens keep Assets::enqueue_legacy() until they are migrated. The
 * whitelist is the migration switch: adding a page must never change how an
 * unmigrated page is loaded.
 */
final class Page_Assets {

	/**
	 * Pages that have completed the UI migration.
	 *
	 * @var array<int, string>
	 */
	public const UI_PAGES = array( 'image-rules', 'image-thumb', 'image-backup', 'image-stats' );

	/**
	 * Per-page data passed to the page controller.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $data = array();

	/**
	 * Page id to layout id.
	 *
	 * @var array<string, string>
	 */
	private const LAYOUT_PAGES = array(
		'image-rules'  => 'image',
		'image-thumb'  => 'thumb',
		'image-backup' => 'backup',
	);

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
	 * Whether a page uses the new stack.
	 *
	 * @param string $page Page id; current request when omitted.
	 * @return bool
	 */
	public static function is_ui_page( string $page = '' ): bool {
		if ( '' === $page ) {
			$page = Route::current_page();
		}

		return in_array( $page, self::UI_PAGES, true );
	}

	/**
	 * Enqueue the new stack for one page.
	 *
	 * @param string $page Page id.
	 * @return void
	 */
	public static function enqueue( string $page ): void {
		if ( ! self::is_ui_page( $page ) ) {
			return;
		}

		$module = explode( '-', $page, 2 )[0];
		$base   = 'sakura-admin';

		wp_enqueue_style(
			$base . '-tokens',
			SAKURA_TOOLS_URL . 'assets/ui/tokens.css',
			array(),
			self::group_version( array( 'assets/ui/tokens.css' ) )
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

		$has_layout = isset( self::LAYOUT_PAGES[ $page ] );

		if ( $has_layout ) {
			wp_enqueue_style(
				$base . '-layout',
				SAKURA_TOOLS_URL . 'assets/ui/layout.css',
				array( $base . '-components' ),
				self::group_version( array( 'assets/ui/layout.css' ) )
			);
		}

		$page_css = 'assets/ui/pages/' . $module . '.css';
		if ( is_readable( SAKURA_TOOLS_PATH . $page_css ) ) {
			wp_enqueue_style(
				$base . '-page-' . $module,
				SAKURA_TOOLS_URL . $page_css,
				array( $has_layout ? $base . '-layout' : $base . '-components' ),
				self::group_version( array( $page_css ) )
			);
		}

		// Optional page-specific stylesheet for tabs that need more than the
		// shared module styles (for example files-zibll.css).
		$tab_css = 'assets/ui/pages/' . $page . '.css';
		if ( is_readable( SAKURA_TOOLS_PATH . $tab_css ) ) {
			wp_enqueue_style(
				$base . '-page-' . $page,
				SAKURA_TOOLS_URL . $tab_css,
				array( $base . '-page-' . $module ),
				self::group_version( array( $tab_css ) )
			);
		}

		$scripts = array(
			'core' => array( 'assets/ui/js/core.js', array(), true ),
			'api'  => array( 'assets/ui/js/api.js', array( $base . '-core' ), true ),
			'ui'   => array( 'assets/ui/js/ui.js', array( $base . '-api' ), true ),
		);

		if ( $has_layout ) {
			$scripts['layout'] = array( 'assets/ui/js/layout.js', array( $base . '-ui' ), true );
		}

		foreach ( $scripts as $name => $definition ) {
			wp_enqueue_script(
				$base . '-' . $name,
				SAKURA_TOOLS_URL . $definition[0],
				$definition[1],
				self::group_version( array( $definition[0] ) ),
				(bool) $definition[2]
			);
		}

		$page_js = 'assets/ui/js/pages/' . $page . '.js';
		if ( is_readable( SAKURA_TOOLS_PATH . $page_js ) ) {
			wp_enqueue_script(
				$base . '-page-' . $page,
				SAKURA_TOOLS_URL . $page_js,
				array( $has_layout ? $base . '-layout' : $base . '-ui' ),
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

		if ( $has_layout ) {
			$config['layout'] = array(
				'page'    => self::LAYOUT_PAGES[ $page ],
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Layout::NONCE ),
				'pages'   => Layout::saved(),
				'strings' => array(
					'edit'  => __( '布局修改', 'sakura-imgopt' ),
					'save'  => __( '布局保存', 'sakura-imgopt' ),
					'reset' => __( '恢复默认', 'sakura-imgopt' ),
					'grid'  => __( '显示网格', 'sakura-imgopt' ),
					'drag'  => __( '拖动以调整位置', 'sakura-imgopt' ),
					'hint'  => __( '拖标题移动，拖边缘调整大小。', 'sakura-imgopt' ),
				),
			);
		}

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
