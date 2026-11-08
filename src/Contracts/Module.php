<?php
/**
 * Module contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Contracts;

use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * A plugin module registers its hooks through the central loader.
 *
 * Implementations must not add WordPress hooks in their constructors, so the
 * composition root can decide which modules to load and tests can inspect the
 * complete hook surface without booting WordPress.
 */
interface Module {

	/**
	 * Queue this module's hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void;
}
