<?php
/**
 * Admin screen for review, execution and quotas.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Frontend\Entry;
use Nboke\UserFiles\Frontend\Providers\Generic;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered shell; the table and confirmation widgets are hydrated by
 * userfiles-admin.js against sufm/v1.
 */
final class Page {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'sakura-tools-files';

	/**
	 * Layout page key (matches data-sakura-layout).
	 */
	public const PAGE = 'files';

	/**
	/**
	 * The screen is stateless: everything it needs comes from Settings and the
	 * REST endpoints, so no service has to be injected here.
	 */
	public function __construct() {}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		$hooks->add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush_entry_cache' ) );

		/*
		 * Registering the renderers touches Registry::tabs(), which builds the
		 * translated module labels. Doing that here would run __() during
		 * plugins_loaded — before init — and WordPress 6.7+ flags it with
		 * `_load_textdomain_just_in_time` on every admin page. The textdomain
		 * loads on init, so wait for it; Menu only reads the renderers later,
		 * on admin_menu.
		 */
		$hooks->add_action( 'init', array( $this, 'register_renderers' ), 20 );
	}

	/**
	 * Claim the renderer for every tab of the file space screen.
	 *
	 * The menu lives in Sakura\ImageOptimizer\Admin\Menu; this screen only
	 * claims its routes. Every tab of the file space renders this same page.
	 */
	public function register_renderers(): void {
		foreach ( \Sakura\ImageOptimizer\Admin\Registry::tabs( 'files' ) as $sufm_tab_id ) {
			// The Zibll tab registers its own renderer (Nboke\UserFiles\Admin\Space_Page).
			if ( 'zibll' === (string) $sufm_tab_id ) {
				continue;
			}

			\Sakura\ImageOptimizer\Admin\Registry::register_renderer( 'files', (string) $sufm_tab_id, array( $this, 'render' ) );
		}
	}

	/**
	 * The embed page is cached by the shortcode provider; drop it when the
	 * settings change so a re-published page is found immediately.
	 */
	public function flush_entry_cache(): void {
		if ( class_exists( Generic::class ) ) {
			Generic::flush_page_cache();
		}
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'sufm_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Load the shared shell styles, the layout engine and this screen's script.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'files' ) ) {
			return;
		}

		unset( $hook );

		Assets::enqueue_style();
		Assets::enqueue_layout();

		wp_enqueue_style(
			'sufm-admin',
			SAKURA_TOOLS_URL . 'assets/userfiles-admin.css',
			array( 'sakura-imgopt-admin' ),
			\Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-admin.css' )
		);

		wp_enqueue_script(
			'sufm-admin',
			SAKURA_TOOLS_URL . 'assets/userfiles-admin.js',
			array( 'jquery', 'sakura-imgopt-layout' ),
			\Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-admin.js' ),
			true
		);

		wp_localize_script(
			'sufm-admin',
			'sufmAdmin',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'sufm/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'enabled'  => true,
				'settings' => Settings::get(),
				'strings'  => array(
					'loading'   => __( '加载中…', 'sakura-imgopt' ),
					'empty'     => __( '暂无待处理申请。', 'sakura-imgopt' ),
					'approve'   => __( '批准', 'sakura-imgopt' ),
					'reject'    => __( '驳回', 'sakura-imgopt' ),
					'revoke'    => __( '撤销批准', 'sakura-imgopt' ),
					'confirm'   => __( '确认执行', 'sakura-imgopt' ),
					'preview'   => __( '预览删除范围', 'sakura-imgopt' ),
					'stopped'   => __( '已停止', 'sakura-imgopt' ),
					'failed'    => __( '操作失败，请重试。', 'sakura-imgopt' ),
					'phrase'    => __( '请输入“删除”两个字', 'sakura-imgopt' ),
					'saved'     => __( '已保存', 'sakura-imgopt' ),
					'quota'     => __( '容量（字节）', 'sakura-imgopt' ),
					'items'     => __( '项', 'sakura-imgopt' ),
				),
			)
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Capabilities::can_review() ) {
			wp_die( esc_html__( '你没有权限管理文件空间。', 'sakura-imgopt' ) );
		}

		$view = SAKURA_TOOLS_PATH . 'includes/views/userfiles-admin.php';

		if ( ! is_readable( $view ) ) {
			return;
		}

		$enabled      = Settings::entry_enabled();
		$settings     = Settings::get();
		$entry        = new Entry();
		$entry_status = $entry->status();

		include $view;
	}
}
