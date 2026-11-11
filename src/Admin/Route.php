<?php
/**
 * Stable page identifiers for the refactored admin UI.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the existing module/tab registry to one id per screen.
 *
 * The id is used by the UI layer, the asset whitelist and the layout engine.
 * It is deliberately derived, not stored: Registry remains the only source of
 * truth for navigation.
 */
final class Route {

	/**
	 * Page id for a module/tab pair.
	 *
	 * @param string $module Module id.
	 * @param string $tab    Tab id.
	 * @return string
	 */
	public static function page_id( string $module, string $tab ): string {
		return '' === $module || '' === $tab ? '' : $module . '-' . $tab;
	}

	/**
	 * Page id of the current admin request.
	 *
	 * @return string Empty when the request is not one of our screens.
	 */
	public static function current_page(): string {
		list( $module, $tab ) = Registry::current();

		return self::page_id( (string) $module, (string) $tab );
	}

	/**
	 * Whether the current request is one page.
	 *
	 * @param string $page Page id.
	 * @return bool
	 */
	public static function is( string $page ): bool {
		return '' !== $page && self::current_page() === $page;
	}
}
