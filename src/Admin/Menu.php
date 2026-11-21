<?php
/**
 * Renders the plugin menu from the registry and dispatches routes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Support\Profiler;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single top-level menu and its four module screens.
 *
 * Modules no longer register their own submenu: they only register a renderer
 * in the registry, so the menu order and labels live in one place.
 */
final class Menu implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_menu', array( $this, 'register_menu' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_icon' ) );

		// Legacy slugs must be caught before WordPress validates ?page= against
		// the registered screens, otherwise it dies with "not allowed" first.
		$hooks->add_action( 'init', array( $this, 'redirect_legacy' ), 5 );
		$hooks->add_action( 'admin_page_access_denied', array( $this, 'redirect_legacy' ), 5 );
	}

	/**
	 * Build the menu from the registry.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$first = true;

		foreach ( Registry::modules() as $definition ) {
			$label = (string) $definition['label'];
			$slug  = (string) $definition['slug'];
			$cap   = $this->capability( (string) $definition['caps'] );

			if ( $first ) {
				add_menu_page(
					$label,
					__( '樱花工具箱', 'sakura-imgopt' ),
					$cap,
					$slug,
					array( $this, 'render' ),
					// 'none' keeps WordPress from printing its own glyph and,
					// because the value is not empty, it also skips the
					// menu-icon-generic class — so the empty icon div is ours to
					// paint (see assets/ui/admin-menu-icon.css).
					'none',
					80
				);

				// Replace the auto-generated first submenu label.
				add_submenu_page(
					$slug,
					$label,
					$label,
					$cap,
					$slug,
					array( $this, 'render' )
				);

				$first = false;
				continue;
			}

			add_submenu_page(
				Registry::PARENT,
				$label,
				$label,
				$cap,
				$slug,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Load the stylesheet that paints the plugin's admin menu icon.
	 *
	 * The icon has to render on every admin screen, not just on ours, so this
	 * runs for the whole dashboard and only adds one small CSS file.
	 *
	 * @return void
	 */
	public function enqueue_menu_icon(): void {
		wp_enqueue_style(
			'sakura-admin-menu-icon',
			SAKURA_TOOLS_URL . 'assets/ui/admin-menu-icon.css',
			array(),
			Page_Assets::asset_version( 'assets/ui/admin-menu-icon.css' )
		);
	}

	/**
	 * Resolve the current route and call its renderer.
	 *
	 * @return void
	 */
	public function render(): void {
		list( $module, $tab ) = Registry::current();

		$modules = Registry::modules();
		$cap     = $this->capability( (string) ( $modules[ $module ]['caps'] ?? 'manage_options' ) );

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( '你没有权限访问这个页面。', 'sakura-imgopt' ) );
		}

		// Tab level gate: theme environment + tab level capability.
		$gate = Registry::tab_gate( $module, $tab );

		if ( ! $gate['capable'] ) {
			wp_die( esc_html__( '你没有权限访问这个页面。', 'sakura-imgopt' ) );
		}

		if ( ! $gate['enabled'] ) {
			status_header( 403 );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			self::render_locked( $module, $tab, $gate );
			return;
		}

		$renderer = Registry::renderer( $module, $tab );

		if ( is_callable( $renderer ) ) {
			Profiler::measure(
				'admin page ' . $module . '/' . $tab,
				static function () use ( $renderer ): void {
					call_user_func( $renderer );
				}
			);
			return;
		}

		Shell::missing();
	}

	/**
	 * Renders the locked page for an unavailable tab.
	 *
	 * Contract: no form, no nonce, no business value. Just why, what is missing and a way back.
	 *
	 * @param string              $module Module id.
	 * @param string              $tab    Tab id.
	 * @param array<string,mixed> $gate   Result of Registry::tab_gate().
	 * @return void
	 */
	private static function render_locked( string $module, string $tab, array $gate ): void {
		$note  = isset( $gate['note'] ) ? (string) $gate['note'] : '';
		$theme = \Nboke\UserFiles\Services\Zibll_Bridge::instance()->theme();

		$theme_name    = '' !== (string) $theme['name'] ? (string) $theme['name'] : __( '未取到', 'sakura-imgopt' );
		$theme_version = '' !== (string) $theme['version'] ? (string) $theme['version'] : __( '未取到', 'sakura-imgopt' );

		UI::page(
			array(
				'id'      => Route::current_page(),
				'module'  => $module,
				'tab'     => $tab,
				'eyebrow' => __( '子比专属', 'sakura-imgopt' ),
				'title'   => __( '子比专属功能不可用', 'sakura-imgopt' ),
				'lede'    => $note,
			),
			static function () use ( $theme_name, $theme_version ): void {
				UI::card(
					array(
						'title'       => __( '主题环境', 'sakura-imgopt' ),
						'description' => __( '这个页面仅在子比主题启用时可用。', 'sakura-imgopt' ),
					),
					static function () use ( $theme_name, $theme_version ): void {
						?>
						<div class="field-list">
							<div class="field">
								<div class="field__main"><span class="field__label"><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></span></div>
								<div class="field__control"><?php echo esc_html( $theme_name ); ?></div>
							</div>
							<div class="field">
								<div class="field__main"><span class="field__label"><?php esc_html_e( '主题版本', 'sakura-imgopt' ); ?></span></div>
								<div class="field__control"><?php echo esc_html( $theme_version ); ?></div>
							</div>
						</div>
						<div class="button-row">
							<a class="btn btn--ghost" href="<?php echo esc_url( Registry::url( 'files', 'settings' ) ); ?>"><?php esc_html_e( '返回样式与策略', 'sakura-imgopt' ); ?></a>
						</div>
						<?php
					}
				);
			}
		);
	}

	/**
	 * Send old menu slugs to their new home, preserving query arguments.
	 *
	 * Only GET requests are redirected so a POST to a legacy screen is never
	 * silently turned into a 302.
	 *
	 * @return void
	 */
	public function redirect_legacy(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing parameter.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$map  = Registry::legacy();

		if ( '' === $slug || ! isset( $map[ $slug ] ) ) {
			return;
		}

		list( $module, $tab ) = $map[ $slug ];

		$extra = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Forwarding read-only query arguments.
		foreach ( (array) wp_unslash( $_GET ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || in_array( $key, array( 'page', 't' ), true ) ) {
				continue;
			}
			$extra[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( (string) $value );
		}

		wp_safe_redirect( Registry::url( $module, $tab, $extra ) );
		exit;
	}

	/**
	 * Resolve the capability a module needs.
	 *
	 * @param string $caps Declared capability, or "review" for the file space.
	 * @return string
	 */
	private function capability( string $caps ): string {
		if ( 'review' !== $caps ) {
			return '' !== $caps ? $caps : 'manage_options';
		}

		if ( class_exists( '\Nboke\UserFiles\Capabilities' ) ) {
			$review = constant( '\Nboke\UserFiles\Capabilities::REVIEW' );

			return ( \Nboke\UserFiles\Capabilities::can_review() || current_user_can( 'manage_options' ) ) ? (string) $review : 'manage_options';
		}

		return 'manage_options';
	}
}
