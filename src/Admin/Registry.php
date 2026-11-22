<?php
/**
 * Admin information architecture: modules, tabs, routes and renderers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the plugin's admin navigation.
 *
 * Adding a screen should only require one entry here plus a view file: the
 * menu, the module switcher, the tab strip, the breadcrumb and the deep links
 * are all derived from this structure.
 */
final class Registry {

	/**
	 * Top-level menu slug. The first module keeps it so old bookmarks to
	 * `admin.php?page=sakura-tools` keep working.
	 */
	public const PARENT = 'sakura-tools';

	/**
	 * Runtime renderers, keyed "module:tab".
	 *
	 * @var array<string, callable>
	 */
	private static array $renderers = array();

	/**
	 * Module definitions in menu order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function modules(): array {
		return array(
			'system'  => array(
				'label' => __( '系统设置', 'sakura-imgopt' ),
				'slug'  => self::PARENT,
				'caps'  => 'manage_options',
				'tabs'  => array(
					'settings' => array( 'label' => __( '环境自检', 'sakura-imgopt' ) ),
				),
			),
			'image'   => array(
				'label' => __( '图片优化', 'sakura-imgopt' ),
				'slug'  => 'sakura-tools-image',
				'caps'  => 'manage_options',
				'tabs'  => array(
					'rules'  => array( 'label' => __( '优化规则', 'sakura-imgopt' ) ),
					'thumb'  => array( 'label' => __( '缩略图', 'sakura-imgopt' ) ),
					'backup' => array( 'label' => __( '备份还原', 'sakura-imgopt' ) ),
					'stats'  => array( 'label' => __( '统计', 'sakura-imgopt' ) ),
				),
			),
			'storage' => array(
				'label' => __( '对象存储', 'sakura-imgopt' ),
				'slug'  => 'sakura-tools-storage',
				'caps'  => 'manage_options',
				'tabs'  => array(
					'connection' => array( 'label' => __( '连接配置', 'sakura-imgopt' ) ),
					'sync'       => array( 'label' => __( '同步中心', 'sakura-imgopt' ) ),
					'deletes'    => array( 'label' => __( '删除队列', 'sakura-imgopt' ) ),
					'rewrite'    => array( 'label' => __( '链接改写', 'sakura-imgopt' ) ),
					'cost'       => array( 'label' => __( '费用估算', 'sakura-imgopt' ) ),
				),
			),
			'files'   => array(
				'label' => __( '文件空间', 'sakura-imgopt' ),
				'slug'  => 'sakura-tools-files',
				'caps'  => 'review',
				'tabs'  => array(
					'settings' => array( 'label' => __( '样式与策略', 'sakura-imgopt' ) ),
					'media'    => array(
						'label' => __( '媒体管理', 'sakura-imgopt' ),
						'gate'  => array( 'caps' => 'review' ),
					),
					'users'    => array(
						'label' => __( '用户容量', 'sakura-imgopt' ),
						'gate'  => array( 'caps' => 'execute' ),
					),
					'queue'    => array(
						'label' => __( '审核与执行', 'sakura-imgopt' ),
						'gate'  => array( 'caps' => 'execute' ),
					),
					'log'      => array(
						'label' => __( '最近操作', 'sakura-imgopt' ),
						'gate'  => array( 'caps' => 'execute' ),
					),
					'zibll'    => array(
						'label' => __( '子比专属', 'sakura-imgopt' ),
						'gate'  => array(
							'when' => static fn(): bool => \Nboke\UserFiles\Services\Zibll_Bridge::instance()->available(),
							'note' => static fn(): string => \Nboke\UserFiles\Services\Zibll_Bridge::instance()->note(),
							'code' => 'zibll',
							'caps' => 'manage',
						),
					),
				),
			),
			'zibll'   => array(
				'label' => __( '子比增强', 'sakura-imgopt' ),
				'slug'  => 'sakura-tools-zibll',
				'caps'  => 'manage_options',
				'tabs'  => array(
					'performance' => array(
						'label' => __( '性能优化', 'sakura-imgopt' ),
						'gate'  => array(
							'when' => static fn(): bool => \Sakura\ImageOptimizer\Zibll\License::is_zibll_theme(),
							'note' => static fn(): string => __( '当前主题不是子比（zibll），没有可缓存的授权校验。切换到子比主题后本页才可用。', 'sakura-imgopt' ),
							'code' => 'zibll',
							'caps' => 'manage',
						),
					),
				),
			),
		);
	}

	/**
	 * Old menu slugs and where they now live.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function legacy(): array {
		return array(
			'sakura-tools-optimize' => array( 'image', 'rules' ),
			'sakura-tools-thumb'    => array( 'image', 'thumb' ),
			'sakura-tools-backup'   => array( 'image', 'backup' ),
			'sakura-tools-stats'    => array( 'image', 'stats' ),
			'sakura-s3-storage'     => array( 'storage', 'connection' ),
			'sakura-tools-s3-sync'  => array( 'storage', 'sync' ),
		);
	}

	/**
	 * All tab ids of one module.
	 *
	 * @param string $module Module id.
	 * @return array<int, string>
	 */
	public static function tabs( string $module ): array {
		$modules = self::modules();

		return isset( $modules[ $module ] ) ? array_keys( (array) $modules[ $module ]['tabs'] ) : array();
	}

	/**
	 * Default (first) tab of one module.
	 *
	 * @param string $module Module id.
	 * @return string
	 */
	public static function default_tab( string $module ): string {
		$tabs = self::tabs( $module );

		return isset( $tabs[0] ) ? (string) $tabs[0] : '';
	}

	/**
	 * Module that owns a WordPress page slug.
	 *
	 * @param string $slug Page slug from ?page=.
	 * @return string Empty when the slug is not ours.
	 */
	public static function module_for_slug( string $slug ): string {
		foreach ( self::modules() as $id => $module ) {
			if ( (string) $module['slug'] === $slug ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Route of the current admin request.
	 *
	 * @return array{0:string,1:string} Module id and tab id.
	 */
	public static function current(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing parameters.
		$slug   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab    = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$module = self::module_for_slug( $slug );
		if ( '' === $module ) {
			// Any other admin screen (dashboard, options-general, media library…)
			// is not ours. Returning "system" here made every non-plugin page look
			// like the System tab, so the shared admin CSS/JS were enqueued on
			// screens that have none of our markup (audit A09).
			return array( '', '' );
		}

		$tabs = self::tabs( $module );
		if ( ! in_array( $tab, $tabs, true ) ) {
			$tab = self::default_tab( $module );
		}

		return array( $module, (string) $tab );
	}

	/**
	 * Whether the current request belongs to a module, optionally a tab.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Optional tab id.
	 * @return bool
	 */
	public static function on( string $module, string $tab = '' ) : bool {
		list( $current_module, $current_tab ) = self::current();

		if ( $module !== $current_module ) {
			return false;
		}

		return '' === $tab || $tab === $current_tab;
	}

	/**
	 * Build an admin URL for one route.
	 *
	 * @param string              $module Module id.
	 * @param string              $tab    Tab id; default tab is omitted for a clean URL.
	 * @param array<string,mixed> $extra  Extra query arguments.
	 * @return string
	 */
	public static function url( string $module, string $tab = '', array $extra = array() ): string {
		$modules = self::modules();

		if ( ! isset( $modules[ $module ] ) ) {
			return admin_url( 'admin.php?page=' . self::PARENT );
		}

		$args = array( 'page' => (string) $modules[ $module ]['slug'] );

		if ( '' !== $tab && $tab !== self::default_tab( $module ) && count( self::tabs( $module ) ) > 1 ) {
			$args['t'] = $tab;
		}

		$args = array_merge( $args, $extra );

		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	/**
	 * Attach a callable to a route.
	 *
	 * @param string   $module Module id.
	 * @param string   $tab    Tab id.
	 * @param callable $renderer Renderer.
	 * @return void
	 */
	public static function register_renderer( string $module, string $tab, callable $renderer ): void {
		self::$renderers[ $module . ':' . $tab ] = $renderer;
	}

	/**
	 * Renderer for a route, or null when nothing was registered.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return callable|null
	 */
	/**
	 * Resolves the gate state of one tab: environment (theme) + tab level capability.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return array{enabled:bool,note:string,code:string,caps:string,capable:bool}
	 */
	public static function tab_gate( string $module, string $tab ): array {
		$modules     = self::modules();
		$definition  = $modules[ $module ]['tabs'][ $tab ] ?? null;
		$module_caps = (string) ( $modules[ $module ]['caps'] ?? 'manage' );
		$caps        = ( is_array( $definition ) && isset( $definition['gate']['caps'] ) )
			? (string) $definition['gate']['caps']
			: $module_caps;
		$capable     = self::tab_can( $module, $tab );

		// Fast path: tabs without a gate behave exactly as before.
		if ( ! is_array( $definition ) || ! isset( $definition['gate']['when'] ) ) {
			return array(
				'enabled' => true,
				'note'    => '',
				'code'    => '',
				'caps'    => $caps,
				'capable' => $capable,
			);
		}

		try {
			$enabled = (bool) call_user_func( $definition['gate']['when'] );
		} catch ( \Throwable $e ) {
			$enabled = false;
		}

		$note = '';

		if ( ! $enabled && isset( $definition['gate']['note'] ) ) {
			try {
				$note = (string) call_user_func( $definition['gate']['note'] );
			} catch ( \Throwable $e ) {
				$note = '';
			}
		}

		return array(
			'enabled' => $enabled,
			'note'    => $note,
			'code'    => (string) ( $definition['gate']['code'] ?? '' ),
			'caps'    => $caps,
			'capable' => $capable,
		);
	}

	/**
	 * Raw definition of one tab (label + optional gate); empty array when unknown.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return array<string,mixed>
	 */
	public static function tab_meta( string $module, string $tab ): array {
		$modules    = self::modules();
		$definition = $modules[ $module ]['tabs'][ $tab ] ?? null;

		return is_array( $definition ) ? $definition : array();
	}

	/**
	 * Tab level capability check; gate.caps defaults to the module caps.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return bool
	 */
	public static function tab_can( string $module, string $tab ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$meta = self::tab_meta( $module, $tab );
		$caps = isset( $meta['gate']['caps'] )
			? (string) $meta['gate']['caps']
			: (string) ( self::modules()[ $module ]['caps'] ?? 'manage' );

		if ( 'review' === $caps && class_exists( '\\Nboke\\UserFiles\\Capabilities' ) ) {
			return \Nboke\UserFiles\Capabilities::can_review();
		}

		if ( 'execute' === $caps && class_exists( '\\Nboke\\UserFiles\\Capabilities' ) ) {
			return \Nboke\UserFiles\Capabilities::can_execute();
		}

		return false;
	}

	public static function renderer( string $module, string $tab ): ?callable {
		$key = $module . ':' . $tab;

		return isset( self::$renderers[ $key ] ) ? self::$renderers[ $key ] : null;
	}
}
