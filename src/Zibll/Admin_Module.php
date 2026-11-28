<?php
/**
 * Zibll module admin screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * The wp-admin side: assets and the status readout.
 */
final class Admin_Module implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		$hooks->add_action( 'admin_notices', array( Notices::class, 'render' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( Notices::class, 'enqueue_style' ), 99 );
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

		$data = ( new Performance_Payload() )->build();

		require __DIR__ . '/../../includes/views/zibll-performance-island.php';
	}

	/**
	 * Load the shared admin stack on this page only; the shell bundle is
	 * enqueued by Page_Assets::enqueue() and this screen keeps none of its own.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook = '' ): void {
		unset( $hook );

		if ( ! Registry::on( 'zibll', 'performance' ) ) {
			return;
		}

		Assets::enqueue_page();
	}

	/**
	 * Cache facts the page and the REST response both need.
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
