<?php
/**
 * Cloud storage cost estimator screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Cost_Payload;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the storage/cost tab: one form, one estimate, no persistence.
 *
 * The calculator is stateless on purpose. Everything it needs comes from the
 * submitted form, so a repeated request costs one CPU-only run and writes
 * nothing to the database.
 */
final class Cost_Panel implements Module {

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the shared admin stack on this tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		unset( $hook );

		if ( ! Registry::on( 'storage', 'cost' ) ) {
			return;
		}

		Assets::enqueue_page();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$data = App_Manifest::payload( Cost_Payload::PAGE );

		if ( null === $data ) {
			return;
		}

		require __DIR__ . '/../../includes/views/cost-island.php';
	}
}
