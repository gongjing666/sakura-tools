<?php
/**
 * Admin asset loading for plugin pages.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared admin stack for the current plugin screen.
 */
final class Assets {

	/**
	 * Enqueue everything the current plugin screen needs.
	 *
	 * @return void
	 */
	public static function enqueue_page(): void {
		Page_Assets::enqueue( Route::current_page() );
	}
}
