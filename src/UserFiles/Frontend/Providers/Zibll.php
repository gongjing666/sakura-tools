<?php
/**
 * User file space: Zibll user-center entry point.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Frontend\Providers;

use Nboke\UserFiles\Frontend\Provider;
use Nboke\UserFiles\Frontend\Tab;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Mounts the file space as a tab in the Zibll user center.
 */
final class Zibll implements Provider {

	/**
	 * Renderer shared with the module.
	 *
	 * @var Tab
	 */
	private Tab $tab;

	/**
	 * Constructor.
	 *
	 * @param Tab $tab Renderer.
	 */
	public function __construct( Tab $tab ) {
		$this->tab = $tab;
	}

	/**
	 * Machine id.
	 */
	public function id(): string {
		return 'zibll';
	}

	/**
	 * Admin label.
	 */
	public function label(): string {
		return __( '子比主题 · 用户中心标签', 'sakura-imgopt' );
	}

	/**
	 * Only when the active theme (child or parent) is Zibll and its URL helper
	 * exists. Checking both means a renamed Zibll fork or a half-switched site
	 * fails closed instead of fataling on a missing function.
	 */
	public function supports(): bool {
		// Single theme detection source; the function_exists guard keeps old boot orders working.
		return \Nboke\UserFiles\Services\Zibll_Bridge::instance()->is_zibll_theme()
			&& function_exists( 'zib_get_user_center_url' );
	}

	/**
	 * The tab, sidebar and content filters all live in Tab.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @param Tab         $tab   Renderer.
	 */
	public function register( Hook_Loader $hooks, Tab $tab ): void {
		$tab->register_hooks( $hooks );
	}

	/**
	 * Zibll routes the tab through `tab=files` (or `user_center=files`).
	 */
	public function is_entry_screen(): bool {
		return $this->tab->is_files_screen();
	}

	/**
	 * Direct link to the files tab.
	 */
	public function entry_url(): string {
		if ( function_exists( 'zib_get_user_center_url' ) ) {
			$url = (string) zib_get_user_center_url( 'files' );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/user/files' );
	}
}
