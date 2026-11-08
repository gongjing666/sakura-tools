<?php
/**
 * Custom ignore rules for the S3 sync index.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the extra path prefixes that the sync scanner must skip.
 *
 * Rules are normalized to object-key needles such as `/2024/05/`, so they can
 * be compared against both local relative paths and remote object keys.
 */
final class Ignored_Paths {

	/**
	 * Option name.
	 */
	public const OPTION = 'sakura_imgopt_sync_ignored';

	/**
	 * Maximum number of rules.
	 */
	public const MAX_RULES = 50;

	/**
	 * Maximum length of a single rule, in bytes.
	 */
	public const MAX_LENGTH = 120;

	/**
	 * Currently configured rules.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		return self::sanitize( (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Persist rules from raw form input.
	 *
	 * @param mixed $raw Textarea contents or an array of rules.
	 * @return array<int,string>
	 */
	public static function save( $raw ): array {
		$rules = self::sanitize( $raw );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $rules, false );
		}

		return $rules;
	}

	/**
	 * Normalize raw input into unique `/path/` needles.
	 *
	 * @param mixed $raw Textarea contents or an array of rules.
	 * @return array<int,string>
	 */
	public static function sanitize( $raw ): array {
		$items = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$rules = array();

		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );

			if ( '' === $item || 0 === strpos( $item, '#' ) ) {
				continue;
			}

			$item = str_replace( '\\', '/', $item );
			$item = trim( $item, '/' );
			$item = (string) preg_replace( '#/{2,}#', '/', $item );

			if ( '' === $item || false !== strpos( $item, '..' ) || preg_match( '/^[a-zA-Z]:/', $item ) ) {
				continue;
			}

			if ( strlen( $item ) > self::MAX_LENGTH ) {
				$item = rtrim( substr( $item, 0, self::MAX_LENGTH ), '/' );
			}

			$needle = '/' . $item . '/';

			if ( ! in_array( $needle, $rules, true ) ) {
				$rules[] = $needle;
			}

			if ( count( $rules ) >= self::MAX_RULES ) {
				break;
			}
		}

		return $rules;
	}

	/**
	 * Rules rendered for the textarea, one per line and without slashes.
	 */
	public static function to_text(): string {
		$lines = array();

		foreach ( self::all() as $rule ) {
			$lines[] = trim( $rule, '/' );
		}

		return implode( "\n", $lines );
	}
}
