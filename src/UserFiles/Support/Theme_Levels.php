<?php
/**
 * Read-only view of the theme's membership levels.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One source of truth for a level's price and label.
 *
 * The theme owns the ladder: `user_level_opt` carries each level's name and
 * the points needed to reach it. Hard-coding those values here drifts the
 * moment the site owner edits the theme, which is exactly how the 10-vs-20
 * mismatch happened.
 */
final class Theme_Levels {

	/**
	 * Theme options, memoised per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $options = null;

	/**
	 * Points needed to reach a level (0 for the first one).
	 *
	 * @param int $level Level.
	 * @return int
	 */
	public static function threshold( int $level ): int {
		$options = self::options();
		$opt     = isset( $options['user_level_opt'] ) && is_array( $options['user_level_opt'] ) ? $options['user_level_opt'] : array();
		$key     = 'upgrade_integral_' . max( 0, $level );

		if ( ! isset( $opt[ $key ] ) || ! is_scalar( $opt[ $key ] ) ) {
			return 0;
		}

		return max( 0, (int) $opt[ $key ] );
	}

	/**
	 * Display name of a level.
	 *
	 * @param int $level Level.
	 * @return string
	 */
	public static function name( int $level ): string {
		$options = self::options();
		$opt     = isset( $options['user_level_opt'] ) && is_array( $options['user_level_opt'] ) ? $options['user_level_opt'] : array();
		$key     = 'name_' . max( 0, $level );

		if ( isset( $opt[ $key ] ) && is_scalar( $opt[ $key ] ) && '' !== (string) $opt[ $key ] ) {
			return (string) $opt[ $key ];
		}

		return 'LV' . max( 0, $level );
	}

	/**
	 * Theme options array.
	 *
	 * @return array<string,mixed>
	 */
	private static function options(): array {
		if ( null === self::$options ) {
			$raw           = function_exists( 'get_option' ) ? get_option( 'zibll_options' ) : array();
			self::$options = is_array( $raw ) ? $raw : array();
		}

		return self::$options;
	}

	/**
	 * Forget the memo (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$options = null;
	}
}
