<?php
/**
 * Front end panel helper for the space expansion block.
 *
 * Keeps the theme mount points thin: the panel is appended to the file-space tab
 * content through the theme filter `main_user_tab_content_files`, so no theme
 * file and no Tab.php change is required.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles;

use Nboke\UserFiles\Services\Space_Rules;
use Nboke\UserFiles\Services\Space_Store;
use Nboke\UserFiles\Services\Zibll_Bridge;

/**
 * Server side rendering + asset loading for the front end panel.
 */
final class Space_Panel {

	/**
	 * Theme filter that renders the file-space tab content.
	 */
	public const FILTER = 'main_user_tab_content_files';

	/**
	 * Wires every front end hook. Called once from the module bootstrap.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Priority 5 runs before the theme prints late assets for AJAX tab loads.
		add_filter( self::FILTER, array( __CLASS__, 'enqueue_late' ), 5, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'payment_order_success', array( __CLASS__, 'on_payment_success' ), 12, 1 );

		// Ledger hooks (refund + minute cron) live in Space_Store.
		if ( class_exists( '\\Nboke\\UserFiles\\Services\\Space_Store' ) ) {
			\Nboke\UserFiles\Services\Space_Store::register_hooks();
		}

		// File manager card height is inlined in the tab view: a separate stylesheet
		// proved unreliable on this stack (it also broke the tab assets once).
	}

	/**
	 * Registers the space REST routes.
	 *
	 * @return void
	 */
	public static function register_rest(): void {
		$class = 'Nboke\\UserFiles\\Rest\\Controller';

		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'register_space_routes' ) ) ) {
			return;
		}

		call_user_func( array( $class, 'register_space_routes' ) );
	}

	/**
	 * Forwards the theme payment hook to the store.
	 *
	 * @param mixed $order Theme order row.
	 * @return void
	 */
	public static function on_payment_success( $order ): void {
		Space_Store::instance()->on_payment_success( $order );
	}

	/**
	 * Panel data, or null when the panel must not render at all.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function data(): ?array {
		static $cache    = null;
		static $resolved = false;

		if ( $resolved ) {
			return $cache;
		}

		$resolved = true;
		$cache    = self::build_data();

		return $cache;
	}

	/**
	 * Whether the toolbar should render the expansion entry.
	 *
	 * Called once per page from the file-space toolbar, so it must stay cheap:
	 * the payload itself is built only once thanks to data()'s static cache.
	 *
	 * @return bool
	 */
	public static function has_panel(): bool {
		return null !== self::data();
	}

	/**
	 * Builds the dialog payload.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function build_data(): ?array {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return null;
		}

		$bridge = Zibll_Bridge::instance();

		if ( ! $bridge->available() ) {
			return null;
		}

		if ( ! Space_Settings::enabled() ) {
			return null;
		}

		$store   = Space_Store::instance();
		$plans   = $store->plans();
		$methods = $store->payment_methods();

		if ( array() === $plans || array() === $methods ) {
			return null;
		}

		$uid       = (int) get_current_user_id();
		$breakdown = Space_Rules::breakdown( $uid );
		$vip_labels = Space_Rules::vip_labels();
		$vip_level  = (int) ( $breakdown['vip'] ?? 0 );

		$parts = array(
			array(
				'key'   => 'base',
				'label' => __( '基础', 'sakura-imgopt' ),
				'bytes' => (int) ( $breakdown['base'] ?? 0 ),
			),
			array(
				'key'   => 'level',
				/* translators: %s: level number. */
				'label' => sprintf( __( '等级 LV%s', 'sakura-imgopt' ), (string) ( $breakdown['level'] ?? 0 ) ),
				'bytes' => (int) ( $breakdown['level_bytes'] ?? 0 ),
			),
			array(
				'key'   => 'vip',
				'label' => isset( $vip_labels[ $vip_level ] ) ? $vip_labels[ $vip_level ] : __( '会员', 'sakura-imgopt' ),
				'bytes' => (int) ( $breakdown['vip_bytes'] ?? 0 ),
			),
			array(
				'key'   => 'granted',
				'label' => __( '已购', 'sakura-imgopt' ),
				'bytes' => (int) ( $breakdown['granted_bytes'] ?? 0 ),
			),
		);

		foreach ( $parts as $index => $part ) {
			$parts[ $index ]['human'] = Space_Store::bytes_human( (int) $part['bytes'] );
		}

		return array(
			'uid'             => $uid,
			'plans'           => $plans,
			'methods'         => $methods,
			'breakdown'       => $breakdown,
			'breakdown_parts' => $parts,
			'quota_human'     => Space_Store::bytes_human( (int) ( $breakdown['final'] ?? 0 ) ),
			'used_human'      => '',
			'points'          => Space_Store::instance()->points_state( $uid ),
			'points_plans'    => self::points_plans(),
			'vip_labels'      => $vip_labels,
			'grants'          => $store->my_grants( $uid, 5 ),
			'strings'         => array(
				'confirm'   => __( '确认支付', 'sakura-imgopt' ),
				'busy'      => __( '处理中…', 'sakura-imgopt' ),
				'permanent' => __( '永久', 'sakura-imgopt' ),
				'needPlan'  => __( '请先选择套餐。', 'sakura-imgopt' ),
				/* translators: %s: granted size, e.g. 10 GB. */
				'success'   => __( '已到账：%s', 'sakura-imgopt' ),
				'pending'   => __( '订单已创建，但尚未完成支付，请稍后到「授权流水」核对。', 'sakura-imgopt' ),
				'network'   => __( '网络异常，请稍后重试。', 'sakura-imgopt' ),
			),
			'config'          => array(
				'restUrl' => function_exists( 'rest_url' ) ? (string) rest_url( 'sufm/v1/' ) : '',
				'nonce'   => function_exists( 'wp_create_nonce' ) ? (string) wp_create_nonce( 'wp_rest' ) : '',
			),
		);
	}

	/**
	 * Filter callback: appends the panel markup to the tab content.
	 *
	 * @param mixed $content Tab content.
	 * @param mixed $args    Theme args (unused).
	 * @return mixed
	 */
	public static function render( $content = '', $args = array() ) {
		unset( $args );

		$data = self::data();

		if ( null === $data ) {
			return $content;
		}

		$markup = self::markup( $data );

		if ( ! is_string( $content ) ) {
			return $markup;
		}

		return $content . $markup;
	}

	/**
	 * Filter callback: makes sure the assets are queued before the theme prints them.
	 *
	 * @param mixed $content Tab content.
	 * @param mixed $args    Theme args (unused).
	 * @return mixed
	 */
	public static function enqueue_late( $content = '', $args = array() ) {
		unset( $args );

		self::enqueue();

		return $content;
	}

	/**
	 * Builds the panel markup.
	 *
	 * @param array<string,mixed>|null $data Panel data.
	 * @return string
	 */
	public static function markup( ?array $data = null ): string {
		if ( null === $data ) {
			$data = self::data();
		}

		if ( null === $data ) {
			return '';
		}

		$view = defined( 'SAKURA_TOOLS_PATH' ) ? SAKURA_TOOLS_PATH . 'includes/views/userfiles-space-panel.php' : '';

		if ( '' === $view || ! file_exists( $view ) ) {
			return '';
		}

		ob_start();
		include $view;

		$html = ob_get_clean();

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Enqueues the panel assets (deps intentionally empty: no style handle deps).
	 *
	 * @return void
	 */
	/**
	 * Active points packages that are sellable for points.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function points_plans(): array {
		$max  = (float) ( Space_Settings::points()['max_gb_per_order'] ?? 10 );
		$rows = array();

		foreach ( Space_Settings::points_packages() as $package ) {
			if ( empty( $package['active'] ) ) {
				continue;
			}

			$gb = (float) ( $package['gb'] ?? 0 );

			if ( $gb <= 0 || ( $max > 0 && $gb > $max ) ) {
				continue;
			}

			$days = (int) ( $package['days'] ?? 365 );

			$rows[] = array(
				'id'         => (string) ( $package['id'] ?? '' ),
				'gb'         => $gb,
				'points'     => (int) ( $package['points'] ?? 0 ),
				'days'       => $days,
				'label'      => sprintf( '%s GB', number_format( $gb, 2 ) ),
				/* translators: %d: number of days */
				'days_label' => $days > 0 ? sprintf( __( '%d 天', 'sakura-imgopt' ), $days ) : __( '永久', 'sakura-imgopt' ),
			);
		}

		return $rows;
	}

	public static function enqueue(): void {
		if ( ! Space_Settings::enabled() ) {
			return;
		}

		if ( ! Zibll_Bridge::instance()->available() ) {
			return;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}

		if ( ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		$base = defined( 'SAKURA_TOOLS_URL' ) ? (string) SAKURA_TOOLS_URL : '';
		$style_ver  = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-space.css' );
		$script_ver = \Nboke\UserFiles\Support\Assets::version( 'assets/userfiles-space.js' );

		wp_enqueue_style( 'sufm-space', $base . 'assets/userfiles-space.css', array(), $style_ver );

		if ( function_exists( 'wp_enqueue_script' ) ) {
			wp_enqueue_script( 'sufm-space', $base . 'assets/userfiles-space.js', array(), $script_ver, true );
		}
	}

}
