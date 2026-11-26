<?php
/**
 * Admin page controller for the "Zibll only" tab of the file space module.
 *
 * The page is registered as a renderer for the `files` module / `zibll` tab. Its
 * assets are enqueued independently from {@see \Nboke\UserFiles\Settings::entry_enabled()}
 * because the file-space master switch and the expansion switch are unrelated.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Services\Zibll_Bridge;
use Nboke\UserFiles\Space_Settings;
use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Support\Hook_Loader;

/**
 * Registers the settings group, the assets and the view of the Zibll tab.
 */
final class Space_Page {

	/**
	 * Tab id inside the files module.
	 */
	public const TAB = 'zibll';

	/**
	 * Settings group used by settings_fields() / options.php.
	 */
	public const GROUP = 'sufm_space_group';

	/**
	 * Hook registration.
	 *
	 * @param Hook_Loader $hooks Plugin hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_init', array( $this, 'register_settings' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// Settings write => drop the request scoped memos (option + derived quota).
		$hooks->add_action( 'add_option_' . Space_Settings::OPTION, array( $this, 'flush_caches' ) );
		$hooks->add_action( 'update_option_' . Space_Settings::OPTION, array( $this, 'flush_caches' ) );
		$hooks->add_action( 'delete_option_' . Space_Settings::OPTION, array( $this, 'flush_caches' ) );

		// Registered immediately: Menu::render() resolves renderers on admin_menu,
		// so waiting for admin_init would be too late on some requests.
		Registry::register_renderer( 'files', self::TAB, array( $this, 'render' ) );
	}

	/**
	 * Drops the request scoped caches after the option changed.
	 *
	 * add_option_* / update_option_* / delete_option_* pass ($option, $value);
	 * no parameter is declared on purpose so accepted_args=1 stays valid.
	 *
	 * @return void
	 */
	public function flush_caches(): void {
		Space_Settings::flush();

		if ( class_exists( '\\Nboke\\UserFiles\\Services\\Space_Rules' ) ) {
			\Nboke\UserFiles\Services\Space_Rules::flush();
		}
	}

	/**
	 * Registers the option with the settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}

		register_setting(
			self::GROUP,
			Space_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Space_Settings::class, 'sanitize' ),
				'default'           => Space_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Enqueues the page assets, only on the Zibll tab.
	 *
	 * @param string $hook_suffix Current admin page hook (unused).
	 * @return void
	 */
	public function enqueue( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! class_exists( Registry::class ) ) {
			return;
		}

		if ( ! Registry::on( 'files', self::TAB ) ) {
			return;
		}

		$available = Zibll_Bridge::instance()->available();

		if ( $available && function_exists( 'wp_enqueue_media' ) ) {
			// The package card lets each plan pick its order icon from the library.
			wp_enqueue_media();
		}

		Assets::enqueue_page();

		$bundle = Page_Assets::island_bundle( 'files-zibll' );

		if ( $available && '' !== $bundle && is_readable( SAKURA_TOOLS_PATH . $bundle ) ) {
			wp_enqueue_script(
				'sakura-admin-files-zibll',
				SAKURA_TOOLS_URL . $bundle,
				array( 'sakura-admin-core' ),
				Page_Assets::asset_version( $bundle ),
				true
			);
		}
	}

	/**
	 * Renders the page.
	 *
	 * Menu::render() already blocks unavailable tabs with a 403; this guard only
	 * covers direct renderer invocation from other code paths.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '你没有权限访问这个页面。', 'sakura-imgopt' ) );
		}

		$bridge = Zibll_Bridge::instance();

		if ( ! $bridge->available() ) {
			$this->render_unavailable_notice( $bridge );

			return;
		}

		$bundle = Page_Assets::island_bundle( 'files-zibll' );

		if ( '' !== $bundle && is_readable( SAKURA_TOOLS_PATH . $bundle ) ) {
			$data = ( new Space_Payload() )->build();

			require SAKURA_TOOLS_PATH . 'includes/views/files-zibll-island.php';

			return;
		}

		$this->render_missing_bundle_notice();
	}

	/**
	 * Fallback when the island bundle is missing from the install.
	 *
	 * The old server-rendered settings view was removed together with its
	 * controller, so this path must still render a clear, safe page instead of a
	 * fatal include.
	 *
	 * @return void
	 */
	private function render_missing_bundle_notice(): void {
		UI::page(
			array(
				'id'      => 'files-zibll',
				'module'  => 'files',
				'tab'     => self::TAB,
				'eyebrow' => 'ZIBLL BRIDGE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
				'title'   => __( '子比专属', 'sakura-imgopt' ),
				'lede'    => __( '主题能力、支付通道、前台入口与等级加成集中在这里。', 'sakura-imgopt' ),
				'status'  => array(
					'label' => __( '子比桥接', 'sakura-imgopt' ),
					'value' => __( '资源缺失', 'sakura-imgopt' ),
					'sub'   => __( '请重新构建或安装完整插件包', 'sakura-imgopt' ),
					'tone'  => 'bad',
				),
			),
			static function (): void {
				?>
				<div class="card-grid" data-sakura-layout="files-space" data-sakura-group="main">
					<div class="zibll-span-all">
						<?php UI::alert( __( '页面资源未找到，无法打开子比专属设置。请重新执行构建并确认 assets/ui/dist/zibll.js 存在。', 'sakura-imgopt' ), 'bad', __( '资源缺失', 'sakura-imgopt' ) ); ?>
					</div>
				</div>
				<?php
			}
		);
	}

	/**
	 * Fallback page when the current theme is not Zibll.
	 *
	 * Intentionally contains no form, no nonce and no business value.
	 *
	 * @param Zibll_Bridge $bridge Theme bridge.
	 * @return void
	 */
	private function render_unavailable_notice( Zibll_Bridge $bridge ): void {
		$theme = $bridge->theme();
		$name  = '' !== $theme['name'] ? $theme['name'] : __( '未知主题', 'sakura-imgopt' );
		$ver   = '' !== $theme['version'] ? $theme['version'] : __( '未知', 'sakura-imgopt' );

		UI::page(
			array(
				'id'      => 'files-zibll',
				'module'  => 'files',
				'tab'     => self::TAB,
				'eyebrow' => 'ZIBLL BRIDGE / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
				'title'   => __( '子比专属', 'sakura-imgopt' ),
				'lede'    => __( '主题能力、支付通道、前台入口与等级加成集中在这里。', 'sakura-imgopt' ),
				'status'  => array(
					'label' => __( '子比桥接', 'sakura-imgopt' ),
					'value' => __( '不可用', 'sakura-imgopt' ),
					'sub'   => $name,
					'tone'  => 'bad',
				),
			),
			static function () use ( $bridge, $name, $ver ) {
				?>
				<div class="card-grid" data-sakura-layout="files-space" data-sakura-group="main">
					<?php
					UI::card(
						array(
							'id'          => 'space_unavailable',
							'kicker'      => 'ZIBLL BRIDGE',
							'title'       => __( '子比专属功能不可用', 'sakura-imgopt' ),
							'description' => __( '本页只在子比主题处于启用状态时开放；不会修改任何主题设置。', 'sakura-imgopt' ),
							'wide'        => true,
						),
						static function () use ( $bridge, $name, $ver ) {
							UI::alert( $bridge->note(), 'warn', __( '主题能力未就绪', 'sakura-imgopt' ) );
							UI::empty_state(
								__( '当前主题不是子比主题', 'sakura-imgopt' ),
								sprintf(
									/* translators: 1: theme name, 2: theme version. */
									__( '当前主题：%1$s（版本 %2$s）。切换到子比主题后重新打开本页即可。', 'sakura-imgopt' ),
									$name,
									$ver
								),
								array(
									'label' => __( '返回样式与策略', 'sakura-imgopt' ),
									'url'   => Registry::url( 'files', 'settings' ),
								)
							);
						}
					);
					?>
				</div>
				<?php
			}
		);
	}
}
