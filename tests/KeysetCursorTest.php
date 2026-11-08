<?php
/**
 * Keyset cursor tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Keyset_Cursor;

/**
 * Covers the opaque cursor that bounds `object_key_hash > ?` queries.
 */
final class KeysetCursorTest extends TestCase {

	/**
	 * A key always maps to the same 32-byte hash wrapped in base64.
	 */
	public function test_from_key_is_stable_and_decodable(): void {
		$key    = 'wp-content/uploads/2024/05/photo.jpg';
		$cursor = Keyset_Cursor::from_key( $key );

		$this->assertSame( $cursor, Keyset_Cursor::from_key( $key ) );
		$this->assertSame( hash( 'sha256', $key, true ), Keyset_Cursor::decode( $cursor ) );
		$this->assertSame( Keyset_Cursor::LENGTH, strlen( (string) Keyset_Cursor::decode( $cursor ) ) );
	}

	/**
	 * Different keys produce different cursors, so pages never overlap.
	 */
	public function test_cursors_differ_per_key(): void {
		$this->assertNotSame(
			Keyset_Cursor::from_key( 'a/one.jpg' ),
			Keyset_Cursor::from_key( 'a/two.jpg' )
		);
	}

	/**
	 * An empty cursor means "first page".
	 */
	public function test_empty_cursor_is_null(): void {
		$this->assertNull( Keyset_Cursor::decode( '' ) );
	}

	/**
	 * Garbage and non-base64 input is rejected instead of widening the range.
	 */
	public function test_invalid_cursor_is_null(): void {
		$this->assertNull( Keyset_Cursor::decode( 'not base64!' ) );
		$this->assertNull( Keyset_Cursor::decode( base64_encode( 'short' ) ) );
		$this->assertNull( Keyset_Cursor::decode( base64_encode( str_repeat( 'x', 31 ) ) ) );
	}

	/**
	 * Search cursors carry the object key boundary and decode unchanged.
	 */
	public function test_value_cursor_round_trip(): void {
		$key    = 'wp-content/uploads/2024/05/照片.jpg';
		$cursor = Keyset_Cursor::from_value( $key );

		$this->assertSame( $key, Keyset_Cursor::decode_value( $cursor ) );
		$this->assertNull( Keyset_Cursor::decode( $cursor ) );
	}

	/**
	 * Empty, malformed and oversized value cursors are rejected.
	 */
	public function test_value_cursor_invalid(): void {
		$this->assertNull( Keyset_Cursor::decode_value( '' ) );
		$this->assertNull( Keyset_Cursor::decode_value( 'not base64!' ) );
		$this->assertNull( Keyset_Cursor::decode_value( base64_encode( '\x02' ) ) );
		$this->assertNull( Keyset_Cursor::decode_value( Keyset_Cursor::from_value( str_repeat( 'a', Keyset_Cursor::MAX_KEY + 1 ) ) ) );
	}

	/**
	 * Hash cursors and value cursors are not interchangeable.
	 */
	public function test_cursor_modes_do_not_cross_decode(): void {
		$hash_cursor  = Keyset_Cursor::from_key( 'a/one.jpg' );
		$value_cursor = Keyset_Cursor::from_value( 'a/one.jpg' );

		$this->assertNull( Keyset_Cursor::decode_value( $hash_cursor ) );
		$this->assertNull( Keyset_Cursor::decode( $value_cursor ) );
	}

	/**
	 * Recent-first cursors carry the datetime and hash boundary.
	 */
	public function test_recent_cursor_round_trip(): void {
		$hash   = hash( 'sha256', 'a/one.jpg', true );
		$cursor = Keyset_Cursor::from_recent( '2026-10-25 12:34:56', $hash );

		$this->assertSame(
			array( '2026-10-25 12:34:56', bin2hex( $hash ) ),
			Keyset_Cursor::decode_recent( $cursor )
		);
		$this->assertNull( Keyset_Cursor::decode( $cursor ) );
		$this->assertNull( Keyset_Cursor::decode_value( $cursor ) );
	}

	/**
	 * Malformed recent cursors are rejected.
	 */
	public function test_recent_cursor_invalid(): void {
		$this->assertNull( Keyset_Cursor::decode_recent( '' ) );
		$this->assertNull( Keyset_Cursor::decode_recent( 'not base64!' ) );
		$this->assertNull( Keyset_Cursor::decode_recent( base64_encode( "\x03bad|" . str_repeat( 'a', 64 ) ) ) );
		$this->assertNull( Keyset_Cursor::decode_recent( base64_encode( "\x032026-10-25 12:34:56|xyz" ) ) );
		$this->assertNull( Keyset_Cursor::decode_recent( Keyset_Cursor::from_key( 'a/one.jpg' ) ) );
	}

	/**
	 * A 32-byte base64 value survives a round trip unchanged.
	 */
	public function test_decode_round_trip(): void {
		$raw    = str_repeat( "\x00\xff", 16 );
		$cursor = base64_encode( $raw );

		$this->assertSame( $raw, Keyset_Cursor::decode( $cursor ) );
	}
}
