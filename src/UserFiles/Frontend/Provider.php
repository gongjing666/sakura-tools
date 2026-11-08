<?php
/**
 * User file space: front-end entry point contract.
 *
 * The file space itself is theme agnostic. What differs per theme is *where*
 * the surface is mounted: Zibll has a user-center tab, a block theme may use a
 * shortcode inside a page template. Each theme adapter implements this
 * interface so the module never references a theme function directly.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Frontend;

use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * One way of mounting the user file space on the front end.
 */
interface Provider {

	/**
	 * Stable machine id, used in status output and the filter.
	 */
	public function id(): string;

	/**
	 * Human label shown on the admin screen.
	 */
	public function label(): string;

	/**
	 * Whether this adapter can mount on the current site right now.
	 */
	public function supports(): bool;

	/**
	 * Register this entry point's hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @param Tab         $tab   Renderer and asset owner.
	 */
	public function register( Hook_Loader $hooks, Tab $tab ): void;

	/**
	 * Whether the current request renders the file space.
	 */
	public function is_entry_screen(): bool;

	/**
	 * Absolute URL a user opens to reach the file space, or '' when the site
	 * has not published an embed page yet.
	 */
	public function entry_url(): string;
}
