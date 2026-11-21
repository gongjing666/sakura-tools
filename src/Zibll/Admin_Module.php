<?php
/**
 * Zibll module admin screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * The wp-admin side: settings form, manual re-check and the status readout.
 */
final class Admin_Module implements Module {

	/**
	 * AJAX action that re-runs the license check.
	 */
	public const ACTION = 'sakura_zibll_warm';

	/**
	 * Nonce action for that request.
	 */
	public const NONCE = 'sakura_zibll_license_cache';

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		$hooks->add_action( 'admin_notices', array( Notices::class, 'render' ) );
		$hooks->add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_warm' ) );
	}

	/**
	 * Register the option and the per-card guard.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		Card_Form::guard_options( Settings::GROUP, array( Settings::CARD => array( Settings::OPTION ) ) );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require __DIR__ . '/../../includes/views/zibll-performance.php';
	}

	/**
	 * Enqueue page assets and the AJAX configuration.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook = '' ): void {
		unset( $hook );

		if ( ! Registry::on( 'zibll', 'performance' ) ) {
			return;
		}

		Page_Assets::set_data(
			'zibll-performance',
			array(
				'action'  => self::ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
				'enabled' => Settings::enabled(),
				'valid'   => License_Cache::is_valid(),
				'strings' => array(
					'checking' => __( '正在验证…', 'sakura-imgopt' ),
					'checked'  => __( '验证通过，缓存已刷新。', 'sakura-imgopt' ),
					'failed'   => __( '验证没有通过，缓存已清除。', 'sakura-imgopt' ),
					'disabled' => __( '请先启用授权缓存，再重新验证。', 'sakura-imgopt' ),
				),
			)
		);

		Assets::enqueue_page();
	}

	/**
	 * Re-run the license check on demand.
	 *
	 * @return void
	 */
	public function ajax_warm(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! Settings::enabled() ) {
			wp_send_json_error( array( 'message' => __( '请先启用授权缓存，再重新验证。', 'sakura-imgopt' ) ), 400 );
		}

		$cache = License_Cache::warm( 'ajax_manual' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();

			wp_send_json_error( array( 'message' => $cache->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( '验证通过，缓存已刷新。', 'sakura-imgopt' ),
				'cache'   => self::cache_payload(),
			)
		);
	}

	/**
	 * Cache facts the page and the AJAX response both need.
	 *
	 * @return array<string,mixed>
	 */
	public static function cache_payload(): array {
		$cache = License_Cache::get();

		return array(
			'enabled'    => Settings::enabled(),
			'valid'      => License_Cache::is_valid(),
			'ok'         => ! empty( $cache['ok'] ),
			'created_at' => (int) ( $cache['created_at'] ?? 0 ),
			'created_by' => (int) ( $cache['created_by'] ?? 0 ),
			'source'     => (string) ( $cache['source'] ?? '' ),
			'expires_at' => (int) ( $cache['expires_at'] ?? 0 ),
			'cache_until' => (int) ( $cache['cache_until'] ?? 0 ),
			'lifetime'    => Settings::lifetime_seconds(),
			'lifetime_label' => Settings::lifetime_label(),
			'theme'      => License::theme_version(),
			'supported'  => License::is_supported( License::theme_version() ),
			'next_run'   => (int) ( wp_next_scheduled( Revalidate::HOOK ) ?: 0 ),
		);
	}
}
