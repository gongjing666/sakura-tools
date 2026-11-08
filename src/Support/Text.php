<?php
/**
 * Byte-budget string helpers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Support;

/**
 * Clip text to a byte budget without splitting a UTF-8 character.
 *
 * `substr( $text, 0, 500 )` on Chinese text can land in the middle of a
 * three-byte character: the stored value then carries a stray lead byte and
 * every later `json_encode()` / `htmlspecialchars()` of it fails or renders
 * "�". Database limits are expressed in bytes, so clip by bytes but stop on
 * a character boundary instead of switching to a character count.
 */
final class Text {

	/**
	 * Clip a string to at most $max_bytes bytes, keeping whole UTF-8 characters.
	 *
	 * @param string $text      Input text.
	 * @param int    $max_bytes Byte budget; 0 or less returns an empty string.
	 * @return string
	 */
	public static function clip( string $text, int $max_bytes ): string {
		if ( $max_bytes <= 0 || '' === $text ) {
			return '';
		}

		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		$cut = substr( $text, 0, $max_bytes );

		// Walk back over the partial character; at most 3 bytes for UTF-8,
		// but the loop also repairs input that was already invalid.
		while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}

		return $cut;
	}
}
