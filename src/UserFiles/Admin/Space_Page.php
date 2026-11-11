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

use Nboke\UserFiles\Services\Space_Rules;
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

		if ( function_exists( 'wp_enqueue_media' ) ) {
			// The package card lets each plan pick its order icon from the library.
			wp_enqueue_media();
		}

		if ( class_exists( Page_Assets::class ) ) {
			Page_Assets::set_data( 'files-zibll', $this->page_data() );
		}

		if ( class_exists( Assets::class ) && method_exists( Assets::class, 'enqueue_page' ) ) {
			Assets::enqueue_page();
		}

		// files.css belongs to the four core file pages and may not exist yet. In
		// that case the tab stylesheet cannot resolve its declared dependency, so
		// load it once against the shared component layer instead.
		$page_css = defined( 'SAKURA_TOOLS_PATH' ) ? SAKURA_TOOLS_PATH . 'assets/ui/pages/files-zibll.css' : '';

		if ( '' !== $page_css && function_exists( 'wp_style_is' ) && function_exists( 'wp_enqueue_style' ) && ! wp_style_is( 'sakura-admin-page-files-zibll', 'enqueued' ) && is_readable( $page_css ) ) {
			wp_enqueue_style(
				'sakura-admin-page-files-zibll-fallback',
				( defined( 'SAKURA_TOOLS_URL' ) ? SAKURA_TOOLS_URL : '' ) . 'assets/ui/pages/files-zibll.css',
				array( 'sakura-admin-components' ),
				defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : ''
			);
		}
	}

	/**
	 * Controller data for assets/ui/js/pages/files-zibll.js.
	 *
	 * All user-facing strings live here so the script never embeds untranslated
	 * text and the page keeps the same copy when the locale changes.
	 *
	 * @return array<string,mixed>
	 */
	private function page_data(): array {
		return array(
			'restUrl' => function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'sufm/v1/' ) ) : '',
			'nonce'   => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
			'strings' => array(
				'unitMb'             => __( 'MB', 'sakura-imgopt' ),
				'unitGb'             => __( 'GB', 'sakura-imgopt' ),
				'enableWarn'         => __( '启用后将开启扩容售卖入口，用户可付费购买容量。确认继续？', 'sakura-imgopt' ),
				'disableWarn'        => __( '关闭后前台扩容入口会立即消失，已售出的授权仍然有效。确认继续？', 'sakura-imgopt' ),
				'flashWarn'          => __( '关闭「计算加成」会让所有已购容量立即停止生效（不删任何文件）。确认继续？', 'sakura-imgopt' ),
				'confirmTitle'       => __( '请确认', 'sakura-imgopt' ),
				'confirm'            => __( '确认', 'sakura-imgopt' ),
				'cancel'             => __( '取消', 'sakura-imgopt' ),
				'close'              => __( '关闭', 'sakura-imgopt' ),
				'chooseIcon'         => __( '选择订单图标', 'sakura-imgopt' ),
				'grantsEmpty'        => __( '暂无授权记录', 'sakura-imgopt' ),
				'grantsLoading'      => __( '正在读取授权记录…', 'sakura-imgopt' ),
				'grantsError'        => __( '授权记录加载失败，请稍后重试。', 'sakura-imgopt' ),
				'grantExtendTitle'   => __( '延长授权', 'sakura-imgopt' ),
				'grantExtendPrompt'  => __( '请输入延长天数（1–3650）', 'sakura-imgopt' ),
				'grantExtend'        => __( '延长', 'sakura-imgopt' ),
				'grantExtendDone'    => __( '已延长授权。', 'sakura-imgopt' ),
				'grantRevokeTitle'   => __( '撤销授权', 'sakura-imgopt' ),
				'grantRevokePrompt'  => __( '输入「撤销」两个字确认本次操作', 'sakura-imgopt' ),
				'grantRevoke'        => __( '撤销', 'sakura-imgopt' ),
				'grantRevokeDone'    => __( '已撤销授权。', 'sakura-imgopt' ),
				'grantActionFailed'  => __( '操作失败，请重试。', 'sakura-imgopt' ),
				'revokeWord'         => __( '撤销', 'sakura-imgopt' ),
				'permanent'         => __( '永久', 'sakura-imgopt' ),
				'mediaUnavailable'  => __( '媒体库未加载，请刷新页面后重试。', 'sakura-imgopt' ),
				'invalidDays'        => __( '请输入 1–3650 之间的天数。', 'sakura-imgopt' ),
				'invalidWord'        => __( '输入不匹配，操作已取消。', 'sakura-imgopt' ),
				'reconcileNotChecked' => __( '尚未检查。点击「检查差异」比对已付款订单与授权记录。', 'sakura-imgopt' ),
				'reconcileChecking'  => __( '正在比对订单与授权…', 'sakura-imgopt' ),
				'reconcileMissing'   => __( '发现 %s 笔「已付款但无授权」的订单。', 'sakura-imgopt' ),
				'reconcileClean'     => __( '未发现差异。', 'sakura-imgopt' ),
				'reconcileHealing'   => __( '正在补发授权…', 'sakura-imgopt' ),
				'reconcileHealed'    => __( '已补发 %s 笔授权。', 'sakura-imgopt' ),
				'reconcileFailed'    => __( '对账查询失败。', 'sakura-imgopt' ),
				'reconcileConfirm'   => __( '将按订单快照补发 %s 笔授权，确认？', 'sakura-imgopt' ),
				'orderNum'           => __( '订单号', 'sakura-imgopt' ),
				'user'               => __( '用户', 'sakura-imgopt' ),
				'price'              => __( '金额', 'sakura-imgopt' ),
				'payTime'            => __( '支付时间', 'sakura-imgopt' ),
				'reconcileNoItems'   => __( '没有需要补发的订单。', 'sakura-imgopt' ),
				'grantTimeUser'      => __( '时间 / 用户', 'sakura-imgopt' ),
				'grantPackageSize'   => __( '套餐 / 容量', 'sakura-imgopt' ),
				'grantExpiry'        => __( '到期', 'sakura-imgopt' ),
				'grantState'         => __( '状态', 'sakura-imgopt' ),
				'grantActions'       => __( '操作', 'sakura-imgopt' ),
			),
		);
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

		$settings         = Space_Settings::get();
		$breakdown_for_me = Space_Rules::breakdown( (int) get_current_user_id() );

		// Master switch + front-end entry snapshot: owned by Settings, displayed here
		// because the only entry point it controls is the Zibll user centre.
		$files_enabled = \Nboke\UserFiles\Settings::entry_enabled();
		$files_entry   = ( new \Nboke\UserFiles\Frontend\Entry() )->status();
		$entry_status     = array(
			'available'     => $bridge->available(),
			'enabled'       => Space_Settings::enabled(),
			'apply_bonuses' => Space_Settings::apply_bonuses(),
			'mode'          => Space_Settings::mode(),
			'packages'      => count( Space_Settings::packages() ),
		);

		$view = defined( 'SAKURA_TOOLS_PATH' ) ? SAKURA_TOOLS_PATH . 'includes/views/userfiles-space-admin.php' : '';

		if ( '' === $view || ! file_exists( $view ) ) {
			return;
		}

		include $view;
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
				<div class="card-grid" data-sakura-layout="files-zibll" data-sakura-group="main">
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
