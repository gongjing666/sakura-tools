<?php
/**
 * Thumbnail optimization module (v2 URL engine + HTML rollback).
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Oss\Html_Filter;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Thumbnail\Admin_Thumbnails;
use Sakura\ImageOptimizer\Thumbnail\Context;
use Sakura\ImageOptimizer\Thumbnail\Decorator;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail\Stats;
use Sakura\ImageOptimizer\Thumbnail\Theme_Bridge;
use Sakura\ImageOptimizer\Thumbnail\Theme_Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites attachment image URLs according to the v2 policy.
 *
 * The old whole-page output buffer remains available as `engine=html` for one
 * minor release. The default `url` engine hooks
 * `wp_get_attachment_image_src` instead and never buffers the page.
 */
class Thumbnail_Optimizer_Module implements Module {

	const OPTION_KEY = 'sakura_imgopt_oss_thumb';

	/**
	 * Cached Zibll theme detection result.
	 *
	 * @var bool|null
	 */
	private static $is_zibll_cache = null;

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		Settings::maybe_migrate();

		// Kept for the HTML rollback engine and backward compatibility.
		$hooks->add_action( 'template_redirect', array( $this, 'start_scoped_buffer' ), 0 );
		$hooks->add_action( 'wp_ajax_ajax_widget_ui', array( $this, 'start_sidebar_ajax_buffer' ), 0 );
		$hooks->add_action( 'wp_ajax_nopriv_ajax_widget_ui', array( $this, 'start_sidebar_ajax_buffer' ), 0 );

		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		$hooks->add_action( 'update_option_s3_options', array( $this, 'on_s3_options_changed' ), 10, 2 );
		$hooks->add_action( 'admin_notices', array( $this, 'render_conflict_notice' ) );

		$hooks->add_action( 'wp', array( Context::class, 'reset' ) );
		$hooks->add_action( 'loop_start', array( Context::class, 'handle_loop_start' ), 1, 1 );
		$hooks->add_action( 'the_post', array( Theme_Cache::class, 'guard' ), 1, 1 );
		$hooks->add_action( 'loop_end', array( Context::class, 'pop' ), 1, 1 );
		$hooks->add_action( 'dynamic_sidebar_before', array( Context::class, 'push_sidebar' ), 1, 1 );
		$hooks->add_action( 'dynamic_sidebar_after', array( Context::class, 'pop' ), 1, 1 );

		$hooks->add_filter( 'wp_get_attachment_image_src', array( Decorator::class, 'filter' ), 20, 4 );
		$hooks->add_filter( 'image_downsize', array( Admin_Thumbnails::class, 'filter' ), 20, 3 );
		$hooks->add_filter( 'wp_content_img_tag', array( Decorator::class, 'filter_content_image' ), 20, 3 );
		$hooks->add_filter( 'sakura_imgopt_spare_thumb', array( Decorator::class, 'filter_spare_thumb' ), 20, 1 );
		$hooks->add_filter( 'option_zibll_options', array( Theme_Bridge::class, 'filter_options' ), 20, 1 );
		$hooks->add_action( 'update_option_' . Settings::OPTION, array( Settings::class, 'after_option_update' ), 10, 2 );
	}

	/**
	 * Legacy default options (also used by the HTML engine).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return Settings::legacy_options();
	}

	/**
	 * Legacy options accessor used by the HTML engine and old integrations.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_options() {
		return Settings::legacy_options();
	}

	/**
	 * Start the scoped HTML buffer for the rollback engine only.
	 */
	public function start_scoped_buffer(): void {
		$options = Settings::get();
		if ( 'html' !== ( $options['engine'] ?? 'off' ) ) {
			return;
		}

		if ( ! $this->can_filter() ) {
			return;
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return;
		}
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return;
		}
		if ( function_exists( 'is_trackback' ) && is_trackback() ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		ob_start( array( $this, 'filter_list_html' ) );
	}

	/**
	 * Start the scoped buffer for a sidebar widget AJAX response.
	 */
	public function start_sidebar_ajax_buffer(): void {
		$options = Settings::get();
		if ( 'html' !== ( $options['engine'] ?? 'off' ) ) {
			return;
		}

		if ( ! $this->can_filter() || empty( $options['scopes']['sidebar'] ) ) {
			return;
		}

		if ( ! $this->requested_widget_is_in_sidebar() ) {
			return;
		}

		ob_start( array( $this, 'filter_sidebar_ajax_html' ) );
	}

	/**
	 * Filter a full page.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function filter_list_html( $html ) {
		return $this->filter_scoped_html( (string) $html, false );
	}

	/**
	 * Filter a sidebar widget AJAX fragment.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function filter_sidebar_ajax_html( $html ) {
		return $this->filter_scoped_html( (string) $html, true );
	}

	/**
	 * Run the legacy HTML filter.
	 *
	 * @param string $html          HTML.
	 * @param bool   $force_sidebar Force sidebar scope.
	 * @return string
	 */
	private function filter_scoped_html( string $html, bool $force_sidebar ): string {
		return ( new Html_Filter( Settings::legacy_options() ) )->filter( $html, $force_sidebar );
	}

	/**
	 * Whether the HTML engine has enough configuration to run.
	 */
	private function can_filter(): bool {
		$options = Settings::legacy_options();

		return ! empty( $options['enabled'] ) && '' !== (string) $options['oss_host'];
	}

	/**
	 * Whether the requested widget really sits in a sidebar position.
	 */
	private function requested_widget_is_in_sidebar(): bool {
		$request_id    = isset( $_REQUEST['id'] ) ? sanitize_key( wp_unslash( $_REQUEST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request_index = isset( $_REQUEST['index'] ) ? absint( $_REQUEST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $request_id || ! $request_index ) {
			return false;
		}

		$widget_id = $request_id . '-' . $request_index;
		$sidebars  = wp_get_sidebars_widgets();
		foreach ( (array) $sidebars as $sidebar_id => $widgets ) {
			if ( false === strpos( strtolower( (string) $sidebar_id ), 'sidebar' ) || ! is_array( $widgets ) ) {
				continue;
			}
			if ( in_array( $widget_id, $widgets, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the active theme is Zibll or a Zibll child theme.
	 */
	private function is_zibll_theme(): bool {
		if ( null !== self::$is_zibll_cache ) {
			return self::$is_zibll_cache;
		}

		if ( ! function_exists( 'wp_get_theme' ) ) {
			self::$is_zibll_cache = defined( 'ZIB_TEMPLATE_DIRECTORY_URI' );
			return self::$is_zibll_cache;
		}

		$theme = wp_get_theme();
		$check = static function ( $candidate ): bool {
			if ( ! is_object( $candidate ) ) {
				return false;
			}
			$template   = strtolower( (string) $candidate->get_template() );
			$stylesheet = strtolower( (string) $candidate->get_stylesheet() );
			$name       = strtolower( (string) $candidate->get( 'Name' ) );
			$textdomain = strtolower( (string) $candidate->get( 'TextDomain' ) );

			return 'zibll' === $template || 'zibll' === $stylesheet || 'zibll' === $textdomain || false !== strpos( $name, 'zibll' ) || false !== strpos( $name, '子比' );
		};

		self::$is_zibll_cache = $check( $theme ) || ( method_exists( $theme, 'parent' ) && $check( $theme->parent() ) );
		if ( ! self::$is_zibll_cache ) {
			self::$is_zibll_cache = defined( 'ZIB_TEMPLATE_DIRECTORY_URI' ) || function_exists( 'zib_posts_list' );
		}

		return self::$is_zibll_cache;
	}

	/**
	 * Load admin assets only on the thumbnail screen.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'image', 'thumb' ) ) {
			return;
		}

		unset( $hook );

		\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();
	}

	/**
	 * Register the option.
	 */
	public function register_settings(): void {
		// 1.28.2 retired the capability probe. Drop its leftover option so a site
		// that upgrades does not keep a stale verdict nobody reads any more.
		if ( false !== get_option( 'sakura_imgopt_thumb_probe', false ) ) {
			delete_option( 'sakura_imgopt_thumb_probe' );
		}

		register_setting(
			'sakura_imgopt_oss_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
			)
		);

		$options = Settings::get();
		if ( ! empty( $options['enabled'] ) && '' === Settings::media_host() ) {
			add_settings_error(
				Settings::OPTION,
				'sakura_imgopt_media_host_required',
				__( '已保存设置，但未从对象存储配置中解析出图片域名，前台不会处理任何图片。', 'sakura-imgopt' ),
				'warning'
			);
		}
	}

	/**
	 * Sanitize callback (delegates to v2 settings).
	 *
	 * @param mixed $input Raw form input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		return Settings::sanitize( $input );
	}

	/**
	 * Turn the thumbnail rewrite off when the off-site target is replaced.
	 *
	 * A named style, a media host and a processing template all encode one
	 * carrier. Keeping the switch on across a switch means the site keeps
	 * emitting URLs for the bucket that is no longer active, and the only way
	 * to notice is a 404 in the browser. The rewrite is therefore switched off
	 * and the user re-decides on its own page, where the new host is visible.
	 *
	 * Only identity changes count: rotating a key, changing object ACL or the
	 * upload concurrency must not silently disable anything.
	 *
	 * @param mixed $old          Previous option value.
	 * @param mixed $new_settings New option value.
	 */
	public function on_s3_options_changed( $old, $new_settings ): void {
		$old          = is_array( $old ) ? $old : array();
		$new_settings = is_array( $new_settings ) ? $new_settings : array();

		if ( ! self::storage_target_changed( $old, $new_settings ) ) {
			return;
		}

		$raw = get_option( Settings::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		if ( empty( $raw['enabled'] ) ) {
			Settings::reset();
			Settings::flush_caches();
			return;
		}

		$raw['enabled'] = 0;
		update_option( Settings::OPTION, $raw );
		Settings::reset();
		set_transient( 'sakura_imgopt_thumb_switch_notice', 1, 300 );
	}

	/**
	 * Whether the two option sets point at different storage.
	 *
	 * @param array<string,mixed> $old         Previous option value.
	 * @param array<string,mixed> $new_settings New option value.
	 */
	private static function storage_target_changed( array $old, array $new_settings ): bool {
		foreach ( array( 'provider', 'bucket', 'space', 'region', 'endpoint', 'upload_path', 'upload_url_path', 'active_profile_id' ) as $key ) {
			if ( (string) ( $old[ $key ] ?? '' ) !== (string) ( $new_settings[ $key ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Warn about switch combinations that would silently break images.
	 *
	 * Shown on both pages that own half of the pair, so the warning follows the
	 * user back to whichever screen they saved from.
	 */
	public function render_conflict_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$on_thumb     = \Sakura\ImageOptimizer\Admin\Registry::on( 'image', 'thumb' );
		$on_connection = \Sakura\ImageOptimizer\Admin\Registry::on( 'storage', 'connection' );

		if ( ! $on_thumb && ! $on_connection ) {
			return;
		}

		if ( get_transient( 'sakura_imgopt_thumb_switch_notice' ) ) {
			delete_transient( 'sakura_imgopt_thumb_switch_notice' );
			echo \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
				esc_html__( '存储配置（厂商 / 桶 / 端点 / 图片域名）已变更，缩略图地址改写已自动关闭，缓存的缩略图地址也已作废。请到「缩略图优化」确认图片域名后重新开启。', 'sakura-imgopt' ),
				'info'
			);
		}

		foreach ( Settings::conflicts() as $conflict ) {
			$severity = (string) ( $conflict['severity'] ?? 'warning' );
			echo \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
				esc_html( (string) ( $conflict['message'] ?? '' ) ),
				'error' === $severity ? 'bad' : ( 'info' === $severity ? 'info' : 'warn' )
			);
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options  = Settings::get();
		$is_zibll = $this->is_zibll_theme();
		$mode     = Settings::effective_mode();
		$stats    = Stats::summary();
		$host     = Settings::media_host();
		$cache_report = Settings::cache_flush_report();

		require __DIR__ . '/views/thumb-settings.php';
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\Thumbnail_Optimizer_Module', __NAMESPACE__ . '\\Oss_Thumbnail' );
