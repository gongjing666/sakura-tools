<?php
/**
 * Route manifest for the experimental persistent admin shell.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Nboke\UserFiles\Admin\Files_Admin_Payload;
use Nboke\UserFiles\Admin\Files_Settings_Payload;
use Nboke\UserFiles\Admin\Space_Payload;
use Sakura\ImageOptimizer\Admin\Rest\Page_Controller;
use Sakura\ImageOptimizer\S3\Sync_Center_Module;
use Sakura\ImageOptimizer\Stats\Stats_Repository;
use Sakura\ImageOptimizer\Zibll\Performance_Payload;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist behind `sakura_imgopt_admin_app`, and the payload dispatch.
 *
 * Phase 1 only covers the pages whose every action already speaks REST: the
 * system sheet and the four image tabs. Registry stays the single source of the
 * information architecture — this class never invents a URL, it lists page ids
 * that Registry already describes and hands the matching payload builder back.
 *
 * Everything here is inert while the option is off: default `false`, no UI,
 * one `delete_option()` in the uninstaller.
 */
final class App_Manifest {

	/**
	 * Pages the shell may navigate to, and the route each one renders.
	 *
	 * @var array<string, array{module:string, tab:string}>
	 */
	private const ROUTES = array(
		'system-settings' => array(
			'module' => 'system',
			'tab'    => 'settings',
		),
		'image-rules'     => array(
			'module' => 'image',
			'tab'    => 'rules',
		),
		'image-thumb'     => array(
			'module' => 'image',
			'tab'    => 'thumb',
		),
		'image-backup'    => array(
			'module' => 'image',
			'tab'    => 'backup',
		),
		'image-stats'     => array(
			'module' => 'image',
			'tab'    => 'stats',
		),
		// Object storage.
		'storage-connection' => array(
			'module' => 'storage',
			'tab'    => 'connection',
		),
		'storage-sync'       => array(
			'module' => 'storage',
			'tab'    => 'sync',
		),
		'storage-deletes'    => array(
			'module' => 'storage',
			'tab'    => 'deletes',
		),
		'storage-rewrite'    => array(
			'module' => 'storage',
			'tab'    => 'rewrite',
		),
		'storage-cost'       => array(
			'module' => 'storage',
			'tab'    => 'cost',
		),
		// User file space (the three list tabs are gated by `execute`).
		'files-settings'     => array(
			'module' => 'files',
			'tab'    => 'settings',
		),
		'files-queue'        => array(
			'module' => 'files',
			'tab'    => 'queue',
		),
		'files-log'          => array(
			'module' => 'files',
			'tab'    => 'log',
		),
		'files-zibll'        => array(
			'module' => 'files',
			'tab'    => 'zibll',
		),
		// Zibll bridge.
		'zibll-performance'  => array(
			'module' => 'zibll',
			'tab'    => 'performance',
		),
	);

	/**
	 * The allowlist.
	 *
	 * @return array<string, array{module:string, tab:string}>
	 */
	public static function routes(): array {
		return self::ROUTES;
	}

	/**
	 * Whether a page id belongs to the experiment.
	 *
	 * @param string $page Page id.
	 * @return bool
	 */
	public static function is_route( string $page ): bool {
		return isset( self::ROUTES[ $page ] );
	}

	/**
	 * Whether the current request may read one route.
	 *
	 * The gate is the same one the tab strip uses (Registry::tab_gate()), so a
	 * page that is hidden from the strip can never be fetched from here either.
	 *
	 * @param string $page Page id.
	 * @return bool
	 */
	public static function can_view( string $page ): bool {
		if ( ! self::is_route( $page ) ) {
			return false;
		}

		$route = self::ROUTES[ $page ];
		$gate  = Registry::tab_gate( $route['module'], $route['tab'] );

		/*
		 * Capable *and* open. A tab the environment currently blocks (子比专属 and
		 * 子比增强 are gated on the zibll theme) must not be fetchable either, or
		 * the shell would render a page whose own copy says the feature is not
		 * available on this site.
		 */
		return ! empty( $gate['capable'] ) && ! empty( $gate['enabled'] );
	}

	/**
	 * Whether this admin request should boot the React shell.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$page = Route::current_page();

		return '' !== $page && self::can_view( $page );
	}

	/**
	 * The manifest the island boots from.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		$routes = array();

		foreach ( self::ROUTES as $page => $route ) {
			if ( ! self::can_view( $page ) ) {
				continue;
			}

			$routes[ $page ] = array(
				'module' => $route['module'],
				'tab'    => $route['tab'],
				'label'  => self::label( $route['module'], $route['tab'] ),
				'url'    => Registry::url( $route['module'], $route['tab'] ),
			);
		}

		return array(
			'enabled'  => self::enabled(),
			'page'     => Route::current_page(),
			'endpoint' => esc_url_raw( rest_url( Page_Controller::NS . '/pages' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'siteName' => self::site_name(),
			'routes'   => $routes,
		);
	}

	/**
	 * The contract-checked payload for one route.
	 *
	 * Returns the same array the page's own bootstrap carries, so a fetch after
	 * navigation and a full reload can never disagree about a value.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>|null Null when the page is not a route.
	 */
	public static function payload( string $page ): ?array {
		$payload = self::raw_payload( $page );

		return null === $payload ? null : Payload_Contract::normalize( $payload, $page );
	}

	/**
	 * The payload exactly as the builder returns it.
	 *
	 * The contract test inspects this without the normaliser, so a missing key
	 * fails the test instead of being papered over.
	 *
	 * @param string $page Page id.
	 * @return array<string,mixed>|null Null when the page is not a route.
	 */
	public static function raw_payload( string $page ): ?array {
		switch ( $page ) {
			case 'system-settings':
				return System_Payload::build();

			case 'image-rules':
				return ( new Image_Payload() )->build( 'rules' );

			case 'image-thumb':
				return ( new Thumb_Payload() )->build();

			case 'image-backup':
				return ( new Backup_Payload() )->build();

			case 'image-stats':
				return ( new Stats_Payload( new Stats_Repository() ) )->build();

			case 'storage-connection':
				return ( new Storage_Payload() )->build();

			case 'storage-sync':
				return ( new Sync_Center_Module() )->payload();

			case 'storage-deletes':
				return ( new Deletes_Payload() )->build();

			case 'storage-rewrite':
				return ( new Rewrite_Payload() )->build();

			case 'storage-cost':
				return ( new Cost_Payload() )->build();

			case 'files-settings':
				return ( new Files_Settings_Payload() )->build();

			case 'files-queue':
			case 'files-log':
				return ( new Files_Admin_Payload() )->build( $page );

			case 'files-zibll':
				return ( new Space_Payload() )->build();

			case 'zibll-performance':
				return ( new Performance_Payload() )->build();
		}

		return null;
	}

	/**
	 * Every stylesheet the shell may paint, candidates only.
	 *
	 * The shell swaps modules in place, so every reachable module sheet has to
	 * be in the document before the first swap. Callers drop the ones that do
	 * not exist on disk (a module may ship only page level sheets).
	 *
	 * @return array<int,string> Plugin-relative paths.
	 */
	public static function styles(): array {
		$files = array();

		foreach ( self::ROUTES as $page => $route ) {
			$files[] = 'assets/ui/pages/' . $route['module'] . '.css';
			$files[] = 'assets/ui/pages/' . $page . '.css';
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Tab label, falling back to the module label.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return string
	 */
	private static function label( string $module, string $tab ): string {
		$modules = Registry::modules();
		$meta    = Registry::tab_meta( $module, $tab );

		if ( isset( $meta['label'] ) ) {
			return (string) $meta['label'];
		}

		return isset( $modules[ $module ]['label'] ) ? (string) $modules[ $module ]['label'] : '';
	}

	/**
	 * Site name for the document title.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		if ( function_exists( 'get_bloginfo' ) ) {
			$name = (string) get_bloginfo( 'name' );

			if ( '' !== $name ) {
				return $name;
			}
		}

		return (string) get_option( 'blogname', 'WordPress' );
	}
}
