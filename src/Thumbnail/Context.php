<?php
/**
 * Request-scoped rendering context.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks whether the current image is rendered in an archive loop, a
 * singular loop, a sidebar or somewhere else, without inspecting theme CSS.
 */
final class Context {

	public const ARCHIVE  = 'archive';
	public const SINGULAR = 'singular';
	public const SIDEBAR  = 'sidebar';
	public const OTHER    = 'other';

	/**
	 * Context stack.
	 *
	 * @var array<int,string>
	 */
	private static array $stack = array();

	/**
	 * Push a context.
	 *
	 * @param string $context Context name.
	 */
	public static function push( string $context ): void {
		if ( ! in_array( $context, array( self::ARCHIVE, self::SINGULAR, self::SIDEBAR, self::OTHER ), true ) ) {
			$context = self::OTHER;
		}
		self::$stack[] = $context;
	}

	/**
	 * Pop the current context.
	 */
	public static function pop(): void {
		array_pop( self::$stack );
	}

	/**
	 * Clear the stack (tests, long-running workers).
	 */
	public static function reset(): void {
		self::$stack = array();
	}

	/**
	 * Current context, inferred from the main query when no hook has fired.
	 */
	public static function current(): string {
		if ( array() !== self::$stack ) {
			return self::$stack[ count( self::$stack ) - 1 ];
		}

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			return self::SINGULAR;
		}

		if ( function_exists( 'is_archive' ) && ( is_archive() || ( function_exists( 'is_home' ) && is_home() ) || ( function_exists( 'is_search' ) && is_search() ) ) ) {
			return self::ARCHIVE;
		}

		return self::OTHER;
	}

	/**
	 * `loop_start` handler.
	 *
	 * @param mixed $query WP_Query instance.
	 */
	public static function handle_loop_start( $query ): void {
		$is_main = is_object( $query ) && method_exists( $query, 'is_main_query' ) ? (bool) $query->is_main_query() : false;
		if ( ! $is_main ) {
			// Custom loops inherit the surrounding context (e.g. a hot-posts
			// widget query inside the sidebar) instead of degrading to other.
			self::push( array() !== self::$stack ? self::current() : self::OTHER );
			return;
		}

		if ( function_exists( 'is_singular' ) && is_singular() ) {
			self::push( self::SINGULAR );
			return;
		}

		if ( function_exists( 'is_archive' ) && ( is_archive() || ( function_exists( 'is_home' ) && is_home() ) || ( function_exists( 'is_search' ) && is_search() ) ) ) {
			self::push( self::ARCHIVE );
			return;
		}

		self::push( self::OTHER );
	}

	/**
	 * `dynamic_sidebar_before` handler.
	 */
	public static function push_sidebar(): void {
		self::push( self::SIDEBAR );
	}

	/**
	 * Whether a scope is enabled.
	 *
	 * @param array<string,mixed> $scopes Scope map.
	 * @param string              $context Context.
	 */
	public static function enabled( array $scopes, string $context ): bool {
		return ! empty( $scopes[ $context ] );
	}
}
