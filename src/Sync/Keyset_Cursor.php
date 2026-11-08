<?php
/**
 * Keyset cursor encoding.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes the object_key_hash boundary used by keyset pagination.
 *
 * The cursor is an opaque base64 string wrapping the raw 32-byte SHA-256 of
 * the last object key. Keeping the type boundary in one place prevents a
 * malformed cursor from widening or breaking the `object_key_hash > ?` range.
 */
final class Keyset_Cursor {

	/**
	 * Raw hash length.
	 */
	public const LENGTH = 32;

	/**
	 * Maximum object key length accepted in a value cursor.
	 */
	public const MAX_KEY = 1024;

	/**
	 * Leading byte marking a value cursor (as opposed to a hash cursor).
	 */
	private const VALUE_MARKER = "\x02";

	/**
	 * Leading byte marking a (recent_at, hash) cursor.
	 */
	private const RECENT_MARKER = "\x03";

	/**
	 * Build a cursor from an object key.
	 *
	 * @param string $object_key Object key.
	 */
	public static function from_key( string $object_key ): string {
		return base64_encode( hash( 'sha256', $object_key, true ) );
	}

	/**
	 * Build a cursor from an object key value (search mode, ordered by key).
	 *
	 * @param string $value Object key.
	 */
	public static function from_value( string $value ): string {
		return base64_encode( self::VALUE_MARKER . $value );
	}

	/**
	 * Decode a value cursor back into the object key boundary.
	 *
	 * @param string $cursor Opaque cursor.
	 * @return string|null Object key, or null when empty/invalid.
	 */
	public static function decode_value( string $cursor ): ?string {
		if ( '' === $cursor ) {
			return null;
		}
		$binary = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque keyset cursor.
		if ( ! is_string( $binary ) || '' === $binary || self::VALUE_MARKER !== $binary[0] ) {
			return null;
		}
		$value = substr( $binary, 1 );
		if ( '' === $value || strlen( $value ) > self::MAX_KEY || false !== strpos( $value, "\0" ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Build a cursor for the recent-first ordering used by the days filter.
	 *
	 * @param string $recent_at  MySQL datetime of the last row.
	 * @param string $binary_hash Raw 32-byte object key hash.
	 */
	public static function from_recent( string $recent_at, string $binary_hash ): string {
		return base64_encode( self::RECENT_MARKER . $recent_at . '|' . bin2hex( $binary_hash ) );
	}

	/**
	 * Decode a recent-first cursor.
	 *
	 * @param string $cursor Opaque cursor.
	 * @return array{0:string,1:string}|null Datetime plus hex hash, or null.
	 */
	public static function decode_recent( string $cursor ): ?array {
		if ( '' === $cursor ) {
			return null;
		}
		$binary = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque keyset cursor.
		if ( ! is_string( $binary ) || '' === $binary || self::RECENT_MARKER !== $binary[0] ) {
			return null;
		}
		$payload = substr( $binary, 1 );
		$parts   = explode( '|', $payload, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		list( $recent_at, $hash ) = $parts;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $recent_at ) || ! preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return null;
		}
		return array( $recent_at, $hash );
	}

	/**
	 * Decode a cursor into its raw binary hash.
	 *
	 * @param string $cursor Opaque cursor.
	 * @return string|null Raw 32-byte hash, or null when the cursor is empty or invalid.
	 */
	public static function decode( string $cursor ): ?string {
		if ( '' === $cursor ) {
			return null;
		}
		$binary = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque keyset cursor.
		if ( ! is_string( $binary ) || self::LENGTH !== strlen( $binary ) ) {
			return null;
		}
		return $binary;
	}
}
