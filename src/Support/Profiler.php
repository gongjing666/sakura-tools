<?php
/**
 * Query Monitor timing bridge.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports plugin phase timings to Query Monitor's Timing panel.
 *
 * Query Monitor ships the panel but calls nothing itself: rows only appear for
 * code that fires `qm/start` / `qm/lap` / `qm/stop`. This class is that caller,
 * with two rules that keep it free when Query Monitor is absent or inactive:
 *
 * 1. Every hook fires only while Query Monitor has a `qm/start` listener, so a
 *    missing or ceased Query Monitor costs an array lookup and nothing else.
 * 2. `qm/lap` and `qm/stop` fire only for a name this class started. Query
 *    Monitor turns an unpaired stop or lap into a "Timer not started" warning
 *    row (`collectors/timing.php:81-116`), which would be noise, not data.
 *
 * The hook names belong to Query Monitor rather than to this plugin, so they
 * are named once here instead of being prefixed or repeated at call sites.
 */
final class Profiler {

	/**
	 * Query Monitor hook that opens a timer.
	 *
	 * @var string
	 */
	private const HOOK_START = 'qm/start';

	/**
	 * Query Monitor hook that records an intermediate split.
	 *
	 * @var string
	 */
	private const HOOK_LAP = 'qm/lap';

	/**
	 * Query Monitor hook that closes a timer.
	 *
	 * @var string
	 */
	private const HOOK_STOP = 'qm/stop';

	/**
	 * Query Monitor hook fired when it abandons the current request.
	 *
	 * @var string
	 */
	private const HOOK_CEASE = 'qm/cease';

	/**
	 * Prefix for every timer name, so the panel groups this plugin's rows.
	 *
	 * @var string
	 */
	private const NAME_PREFIX = 'Sakura ';

	/**
	 * Timers opened in this request and not closed yet.
	 *
	 * @var array<string, bool>
	 */
	private static array $open = array();

	/**
	 * Whether anything would receive the timing hooks right now.
	 *
	 * Deliberately not cached: the answer changes within a request. Query
	 * Monitor attaches its listeners when its collectors are registered and
	 * detaches them on `qm/cease`, which fires on `init` for a viewer who may
	 * not see the panel (customizer preview, interim login, and so on).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( ! \function_exists( 'has_action' ) || ! \function_exists( 'do_action' ) ) {
			return false;
		}

		if ( \function_exists( 'did_action' ) && \did_action( self::HOOK_CEASE ) > 0 ) {
			return false;
		}

		return false !== \has_action( self::HOOK_START );
	}

	/**
	 * Open a timer.
	 *
	 * Opening the same name twice restarts it: Query Monitor replaces the timer
	 * it holds for that name, so use a distinct name per span in flight.
	 *
	 * @param string $name Timer name.
	 * @return void
	 */
	public static function start( string $name ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$key               = self::NAME_PREFIX . $name;
		self::$open[ $key ] = true;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Query Monitor owns this hook name.
		\do_action( self::HOOK_START, $key );
	}

	/**
	 * Record an intermediate split on an open timer.
	 *
	 * @param string $name  Timer name.
	 * @param string $label Split label.
	 * @return void
	 */
	public static function lap( string $name, string $label ): void {
		$key = self::NAME_PREFIX . $name;

		if ( ! isset( self::$open[ $key ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Query Monitor owns this hook name.
		\do_action( self::HOOK_LAP, $key, $label );
	}

	/**
	 * Close a timer.
	 *
	 * Ignored when the name was never opened, and also when Query Monitor
	 * stopped listening since it was opened: a timer nobody is collecting any
	 * more is not worth reporting.
	 *
	 * @param string $name Timer name.
	 * @return void
	 */
	public static function stop( string $name ): void {
		$key = self::NAME_PREFIX . $name;

		if ( ! isset( self::$open[ $key ] ) ) {
			return;
		}

		unset( self::$open[ $key ] );

		if ( ! self::enabled() ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Query Monitor owns this hook name.
		\do_action( self::HOOK_STOP, $key );
	}

	/**
	 * Time one callback and return whatever it returns.
	 *
	 * The timer closes in a `finally`, so a throwing callback still leaves a
	 * paired start/stop behind instead of a "Timer not stopped" warning row.
	 *
	 * @template T
	 * @param string       $name     Timer name.
	 * @param callable():T $callback Work to time.
	 * @return T
	 */
	public static function measure( string $name, callable $callback ) {
		if ( ! self::enabled() ) {
			return $callback();
		}

		self::start( $name );

		try {
			return $callback();
		} finally {
			self::stop( $name );
		}
	}

	/**
	 * Forget every open timer without reporting anything.
	 *
	 * Test hook only: a real request ends long before the next one begins.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$open = array();
	}
}
