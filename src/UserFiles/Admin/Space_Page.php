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
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\Shell;
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

		if ( class_exists( Assets::class ) ) {
			Assets::enqueue_style();
			Assets::enqueue_layout();
		}

		if ( class_exists( Shell::class ) ) {
			Shell::enqueue();
		}

		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		$base = defined( 'SAKURA_TOOLS_URL' ) ? (string) SAKURA_TOOLS_URL : '';
		$style_ver  = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-space-admin.css' );
		$script_ver = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-space-admin.js' );

		wp_enqueue_style(
			'sufm-space-admin',
			$base . 'assets/userfiles-space-admin.css',
			array( 'sakura-imgopt-admin', 'sakura-imgopt-layout' ),
			$style_ver
		);

		if ( function_exists( 'wp_enqueue_media' ) ) {
			// The package table lets each plan pick its order icon from the library.
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'sufm-space-admin',
			$base . 'assets/userfiles-space-admin.js',
			array(),
			$script_ver,
			true
		);

		if ( ! function_exists( 'wp_localize_script' ) ) {
			return;
		}

		wp_localize_script(
			'sufm-space-admin',
			'sufmSpaceCfg',
			array(
				'restUrl' => function_exists( 'esc_url_raw' ) && function_exists( 'rest_url' ) ? esc_url_raw( rest_url( 'sufm/v1/' ) ) : '',
				'nonce'   => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
				'strings' => array(
					'totalPrefix'  => __( '加成合计：', 'sakura-imgopt' ),
					'enableWarn'   => __( '启用后将开启扩容售卖入口，用户可付费购买容量。确认继续？', 'sakura-imgopt' ),
					'disableWarn'  => __( '关闭后前台扩容入口会立即消失，已售出的授权仍然有效。确认继续？', 'sakura-imgopt' ),
					'flashWarn'    => __( '关闭「计算加成」会让所有已购容量立即停止生效（不删任何文件）。确认继续？', 'sakura-imgopt' ),
					'unitMb'       => __( 'MB', 'sakura-imgopt' ),
					'unitGb'       => __( 'GB', 'sakura-imgopt' ),
					'confirmTitle' => __( '请确认', 'sakura-imgopt' ),
					'chooseIcon'   => __( '选择订单图标', 'sakura-imgopt' ),
				'defaultIcon'  => \Nboke\UserFiles\Services\Space_Store::product_image_url(),
				'grantsEmpty'  => __( '暂无授权记录', 'sakura-imgopt' ),
					'grantsError'  => __( '加载失败，请刷新重试', 'sakura-imgopt' ),
				),
			)
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
	 * Minimal fallback card when the tab is rendered without the theme gate.
	 *
	 * Intentionally contains no form, no nonce and no business value.
	 *
	 * @param Zibll_Bridge $bridge Theme bridge.
	 * @return void
	 */
	private function render_unavailable_notice( Zibll_Bridge $bridge ): void {
		$theme = $bridge->theme();
		?>
		<div class="sakura-sync-app">
			<div class="sky-layer" aria-hidden="true"></div>
			<div class="sakura-layer" aria-hidden="true"></div>
			<div class="app">
				<?php
				if ( class_exists( Shell::class ) ) {
					Shell::header( 'files', self::TAB );
				}
				?>
				<section class="card">
					<div class="card-head">
						<p class="kicker"><?php esc_html_e( '子比专属', 'sakura-imgopt' ); ?></p>
						<h2><?php esc_html_e( '子比专属功能不可用', 'sakura-imgopt' ); ?></h2>
						<p><?php echo esc_html( $bridge->note() ); ?></p>
					</div>
					<div class="card-body">
						<p class="sufm-space-muted">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: theme name, 2: theme version. */
									__( '当前主题：%1$s（版本 %2$s）', 'sakura-imgopt' ),
									'' !== $theme['name'] ? $theme['name'] : __( '未知主题', 'sakura-imgopt' ),
									'' !== $theme['version'] ? $theme['version'] : __( '未知', 'sakura-imgopt' )
								)
							);
							?>
						</p>
						<p>
							<a class="btn" href="<?php echo esc_url( Registry::url( 'files', 'settings' ) ); ?>">
								<?php esc_html_e( '返回样式与策略', 'sakura-imgopt' ); ?>
							</a>
						</p>
					</div>
				</section>
			</div>
		</div>
		<?php
	}
}
