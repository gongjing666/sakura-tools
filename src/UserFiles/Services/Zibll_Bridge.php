<?php
/**
 * Theme gate for Zibll-only features.
 *
 * Single source of truth for the question "is the Zibll theme present and are the
 * functions we derive quota from actually available?". Deliberately shaped like
 * {@see \Nboke\UserFiles\Services\Theme_Limits}: final class, singleton accessor,
 * request-scoped cache and fail-closed behaviour (any doubt => not available).
 *
 * No transient / option cache on purpose: probing costs two function_exists()
 * calls, while a persistent cache would keep reporting the old theme after a switch.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles\Services;

/**
 * Request-scoped Zibll theme probe plus capability matrix.
 */
final class Zibll_Bridge {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Probe result cache for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Private constructor: use {@see self::instance()}.
	 */
	private function __construct() {}

	/**
	 * Returns the shared bridge instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Single detection primitive: is the active theme (parent or child) Zibll?
	 *
	 * @return bool
	 */
	public function is_zibll_theme(): bool {
		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_template' ) ) {
			return false;
		}

		try {
			$stylesheet = (string) get_stylesheet();
			$template   = (string) get_template();
		} catch ( \Throwable $e ) {
			return false;
		}

		return ( 'zibll' === $stylesheet || 'zibll' === $template );
	}

	/**
	 * Hard gate: Zibll theme AND both quota-relevant readers exist.
	 *
	 * @return bool
	 */
	public function available(): bool {
		$state = $this->state();

		return true === (bool) ( $state['available'] ?? false );
	}

	/**
	 * Soft capability matrix used by the diagnostics card and channel switches.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array {
		$state = $this->state();
		$caps  = $state['caps'] ?? null;

		if ( ! is_array( $caps ) ) {
			return $this->empty_capabilities();
		}

		return array(
			'theme'        => true === (bool) ( $caps['theme'] ?? false ),
			'level'        => true === (bool) ( $caps['level'] ?? false ),
			'vip'          => true === (bool) ( $caps['vip'] ?? false ),
			'points'       => true === (bool) ( $caps['points'] ?? false ),
			'pay_methods'  => true === (bool) ( $caps['pay_methods'] ?? false ),
			'pay_initiate' => true === (bool) ( $caps['pay_initiate'] ?? false ),
			'orders'       => true === (bool) ( $caps['orders'] ?? false ),
			'user_center'  => true === (bool) ( $caps['user_center'] ?? false ),
		);
	}

	/**
	 * Machine readable reason code: ok | theme_not_zibll | missing:fn1,fn2 | exception.
	 *
	 * @return string
	 */
	public function reason(): string {
		$state = $this->state();

		return (string) ( $state['reason'] ?? 'theme_not_zibll' );
	}

	/**
	 * Translated, human readable explanation (used for tooltips and the locked page).
	 *
	 * @return string
	 */
	public function note(): string {
		$reason = $this->reason();

		if ( 'ok' === $reason ) {
			return '';
		}

		$theme = $this->theme();
		$name  = '' !== $theme['name'] ? $theme['name'] : __( '未知主题', 'sakura-imgopt' );

		if ( 0 === strpos( $reason, 'missing:' ) ) {
			$missing = substr( $reason, 8 );

			return sprintf(
				/* translators: 1: theme name, 2: comma separated list of missing Zibll functions. */
				__( '当前主题「%1$s」缺少必需的主题函数：%2$s', 'sakura-imgopt' ),
				$name,
				$missing
			);
		}

		if ( 'exception' === $reason ) {
			return sprintf(
				/* translators: %s: theme name. */
				__( '探测主题时发生异常，已按「不可用」关闭本页（当前主题「%s」）。', 'sakura-imgopt' ),
				$name
			);
		}

		return sprintf(
			/* translators: %s: theme name. */
			__( '当前主题「%s」不是子比主题，本页不可用。', 'sakura-imgopt' ),
			$name
		);
	}

	/**
	 * Theme descriptor used by the diagnostics card and the locked page.
	 *
	 * @return array{name:string,version:string,stylesheet:string,template:string,is_child:bool}
	 */
	public function theme(): array {
		$state = $this->state();
		$theme = $state['theme'] ?? null;

		if ( ! is_array( $theme ) ) {
			return $this->empty_theme();
		}

		return array(
			'name'       => (string) ( $theme['name'] ?? '' ),
			'version'    => (string) ( $theme['version'] ?? '' ),
			'stylesheet' => (string) ( $theme['stylesheet'] ?? '' ),
			'template'   => (string) ( $theme['template'] ?? '' ),
			'is_child'   => true === (bool) ( $theme['is_child'] ?? false ),
		);
	}

	/**
	 * Aggregated payload for the admin diagnostics card.
	 *
	 * Payment methods are read in the current user context on purpose: the theme
	 * hides the balance channel when no user is logged in, so callers must not
	 * rely on this during cron / CLI without setting up a user first.
	 *
	 * @return array{theme:array<string,mixed>,capabilities:array<string,bool>,payment_methods:array<string,array<string,string>>}
	 */
	public function diagnostics(): array {
		return array(
			'theme'           => $this->theme(),
			'capabilities'    => $this->capabilities(),
			'payment_methods' => $this->payment_methods(),
		);
	}

	/**
	 * Drops the request-scoped cache.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Normalised payment method list: [ id => [ 'name' => label ] ].
	 *
	 * @return array<string,array<string,string>>
	 */
	private function payment_methods(): array {
		if ( ! function_exists( 'zibpay_get_payment_methods' ) ) {
			return array();
		}

		try {
			$raw = zibpay_get_payment_methods();
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$methods = array();

		foreach ( $raw as $key => $method ) {
			$name = '';
			$id   = is_string( $key ) ? $key : (string) $key;

			if ( is_array( $method ) ) {
				if ( isset( $method['name'] ) ) {
					$name = (string) $method['name'];
				}
				if ( ! is_string( $key ) && isset( $method['id'] ) ) {
					$id = (string) $method['id'];
				}
			} elseif ( is_object( $method ) && isset( $method->name ) ) {
				$name = (string) $method->name;
			}

			$methods[ $id ] = array( 'name' => $name );
		}

		return $methods;
	}

	/**
	 * Cached probe entry point.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		if ( null === $this->cache ) {
			$this->cache = $this->probe();
		}

		return $this->cache;
	}

	/**
	 * Runs the probe once, fail-closed on any throwable.
	 *
	 * @return array<string,mixed>
	 */
	private function probe(): array {
		try {
			$caps = array(
				'theme'        => $this->is_zibll_theme(),
				'level'        => function_exists( 'zib_get_user_level' ),
				'vip'          => function_exists( 'zib_get_user_vip_level' ),
				'points'       => function_exists( 'zibpay_get_user_points' ) && function_exists( 'zibpay_update_user_points' ),
				'pay_methods'  => function_exists( 'zibpay_get_payment_methods' ),
				'pay_initiate' => function_exists( 'zibpay_initiate_pay' ),
				'orders'       => class_exists( 'ZibPay' ),
				'user_center'  => function_exists( 'zib_get_user_center_url' ),
			);

			// Derived quota only needs the three readers below.
			$available = ( true === $caps['theme'] && true === $caps['level'] && true === $caps['vip'] );
			$reason    = 'ok';

			if ( ! $caps['theme'] ) {
				$reason = 'theme_not_zibll';
			} elseif ( ! $available ) {
				$missing = array();

				if ( ! $caps['level'] ) {
					$missing[] = 'zib_get_user_level';
				}
				if ( ! $caps['vip'] ) {
					$missing[] = 'zib_get_user_vip_level';
				}

				$reason = 'missing:' . implode( ',', $missing );
			}

			return array(
				'available' => $available,
				'caps'      => $caps,
				'reason'    => $reason,
				'theme'     => $this->read_theme(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'available' => false,
				'caps'      => $this->empty_capabilities(),
				'reason'    => 'exception',
				'theme'     => $this->empty_theme(),
			);
		}
	}

	/**
	 * Reads the active theme descriptor.
	 *
	 * @return array{name:string,version:string,stylesheet:string,template:string,is_child:bool}
	 */
	private function read_theme(): array {
		$theme = $this->empty_theme();

		if ( ! function_exists( 'get_stylesheet' ) || ! function_exists( 'get_template' ) ) {
			return $theme;
		}

		$theme['stylesheet'] = (string) get_stylesheet();
		$theme['template']   = (string) get_template();
		$theme['is_child']   = ! ( $theme['template'] === $theme['stylesheet'] );

		if ( function_exists( 'wp_get_theme' ) ) {
			$object = wp_get_theme();

			if ( is_object( $object ) && method_exists( $object, 'get' ) ) {
				$theme['name']    = (string) $object->get( 'Name' );
				$theme['version'] = (string) $object->get( 'Version' );
			}
		}

		return $theme;
	}

	/**
	 * Empty theme descriptor.
	 *
	 * @return array{name:string,version:string,stylesheet:string,template:string,is_child:bool}
	 */
	private function empty_theme(): array {
		return array(
			'name'       => '',
			'version'    => '',
			'stylesheet' => '',
			'template'   => '',
			'is_child'   => false,
		);
	}

	/**
	 * Empty capability matrix (every channel closed).
	 *
	 * @return array<string,bool>
	 */
	private function empty_capabilities(): array {
		return array(
			'theme'        => false,
			'level'        => false,
			'vip'          => false,
			'points'       => false,
			'pay_methods'  => false,
			'pay_initiate' => false,
			'orders'       => false,
			'user_center'  => false,
		);
	}
}
