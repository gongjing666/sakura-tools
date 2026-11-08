<?php
/**
 * Fast sync query builder tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Fast_Search_Query;
use Sakura\ImageOptimizer\Sync\Keyset_Cursor;

/**
 * Regression coverage for the direct-access endpoint's list query.
 *
 * A duplicated statement once dropped `recent_at` (breaking the days-filter
 * cursor) and appended the LIMIT argument twice, which made
 * `$wpdb->prepare()` return null. These tests pin the columns, the index hint
 * and the placeholder/argument parity.
 */
final class FastSearchQueryTest extends TestCase {

	/**
	 * Table name used by the endpoint.
	 */
	private const TABLE = 'wp_sio_sync_index';

	/**
	 * The recent ordering must select recent_at and use the covering index.
	 */
	public function test_recent_query_keeps_recent_column_and_index(): void {
		list( $sql ) = $this->build( true );

		$this->assertStringContainsString( 'recent_at', $this->select_list( $sql ) );
		$this->assertStringContainsString( 'FORCE INDEX (profile_recent)', $sql );
		$this->assertStringContainsString( 'ORDER BY recent_at DESC, object_key_hash DESC', $sql );
	}

	/**
	 * The LIMIT placeholder must be bound exactly once.
	 */
	public function test_recent_query_binds_limit_once(): void {
		list( $sql, $args ) = $this->build( true );

		$this->assertSame( 1, substr_count( $sql, 'LIMIT %d' ) );
		$this->assertSame( $this->placeholder_count( $sql ), count( $args ) );
	}

	/**
	 * Hash-keyed pages must not force the recent index.
	 */
	public function test_key_query_has_no_force_index(): void {
		list( $sql ) = $this->build( false );

		$this->assertStringNotContainsString( 'FORCE INDEX', $sql );
		$this->assertStringContainsString( 'ORDER BY object_key_hash ASC', $sql );
	}

	/**
	 * Placeholder/argument parity holds for every filter the endpoint supports.
	 *
	 * @dataProvider filter_combinations
	 *
	 * @param array<string>       $where WHERE fragments.
	 * @param array<int, mixed>   $args  Bound arguments.
	 * @param bool                $recent Whether the recent ordering is active.
	 */
	public function test_placeholders_match_arguments( array $where, array $args, bool $recent ): void {
		list( $sql, $bound ) = Fast_Search_Query::build(
			self::TABLE,
			implode( ' AND ', $where ),
			$args,
			$recent ? 'recent_at DESC, object_key_hash DESC' : 'object_key_hash ASC',
			$recent,
			25
		);

		$this->assertSame( $this->placeholder_count( $sql ), count( $bound ) );
		$this->assertSame( 26, (int) $bound[ count( $bound ) - 1 ], 'LIMIT binds $limit + 1.' );
	}

	/**
	 * Filter shapes the endpoint can receive.
	 *
	 * @return array<string, array{0:array<string>,1:array<int,mixed>,2:bool}>
	 */
	public static function filter_combinations(): array {
		$cut = '2026-10-01 00:00:00';

		return array(
			'profile only'                => array( array( 'profile_id=%s' ), array( 'prof_1' ), false ),
			'status and type'             => array(
				array( 'profile_id=%s', 'status=%s', 'kind=%s' ),
				array( 'prof_1', 'synced', 'image' ),
				false,
			),
			'days (legacy mtime columns)' => array(
				array( 'profile_id=%s', '(local_mtime >= %s OR remote_mtime >= %s)' ),
				array( 'prof_1', $cut, $cut ),
				false,
			),
			'days + recent cursor'        => array(
				array( 'profile_id=%s', 'recent_at >= %s', '(recent_at < %s OR (recent_at = %s AND object_key_hash < UNHEX(%s)))' ),
				array( 'prof_1', $cut, $cut, $cut, str_repeat( 'a', 64 ) ),
				true,
			),
			'search + hash cursor'        => array(
				array( 'profile_id=%s', 'object_key LIKE %s', 'object_key_hash > UNHEX(%s)' ),
				array( 'prof_1', 'wp-content/uploads/2026/%', str_repeat( 'b', 64 ) ),
				false,
			),
			'search + key cursor'         => array(
				array( 'profile_id=%s', 'object_key LIKE %s', 'object_key > %s' ),
				array( 'prof_1', 'wp-content/uploads/2026/%', 'wp-content/uploads/2026/b.webp' ),
				false,
			),
		);
	}

	/**
	 * A recent row must produce a cursor Keyset_Cursor can decode again.
	 */
	public function test_recent_cursor_round_trips(): void {
		$row = array(
			'object_key' => 'wp-content/uploads/2026/10/b.webp',
			'recent_at'  => '2026-10-26 12:34:56',
		);

		$cursor = Fast_Search_Query::next_cursor( $row, true, false );

		$this->assertNotSame( '', $cursor );
		$this->assertSame(
			array( '2026-10-26 12:34:56', hash( 'sha256', $row['object_key'] ) ),
			Keyset_Cursor::decode_recent( $cursor )
		);
	}

	/**
	 * Without a timestamp the cursor cannot be decoded, so none is emitted.
	 *
	 * Emitting one would send the client back to page one.
	 */
	public function test_recent_cursor_is_empty_without_timestamp(): void {
		$row = array( 'object_key' => 'wp-content/uploads/2026/10/b.webp' );

		$this->assertSame( '', Fast_Search_Query::next_cursor( $row, true, false ) );
	}

	/**
	 * The endpoint must not hand-build its statement again.
	 *
	 * fast-search.php is a direct-access SHORTINIT script that cannot be booted
	 * in-process, so this is the only cheap guard against the duplicated block
	 * that caused the regression.
	 */
	public function test_endpoint_delegates_to_the_builder(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/fast-search.php' );

		$this->assertStringContainsString( 'Fast_Search_Query::build(', $source );
		$this->assertStringContainsString( 'Fast_Search_Query::next_cursor(', $source );
		$this->assertSame( 0, substr_count( $source, '$args[] = $limit + 1' ), 'LIMIT must only be bound by the builder.' );
	}

	/**
	 * Key-ordered and hash-ordered cursors keep their existing encoding.
	 */	public function test_key_cursors_round_trip(): void {
		$row = array( 'object_key' => 'wp-content/uploads/照片.jpg' );

		$value_cursor = Fast_Search_Query::next_cursor( $row, false, true );
		$this->assertSame( $row['object_key'], Keyset_Cursor::decode_value( $value_cursor ) );

		$hash_cursor = Fast_Search_Query::next_cursor( $row, false, false );
		$this->assertSame( hash( 'sha256', $row['object_key'], true ), Keyset_Cursor::decode( $hash_cursor ) );
	}

	/**
	 * Build the default recent or hash query.
	 *
	 * @param bool $recent Recent ordering.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function build( bool $recent ): array {
		return Fast_Search_Query::build(
			self::TABLE,
			'profile_id=%s',
			array( 'prof_1' ),
			$recent ? 'recent_at DESC, object_key_hash DESC' : 'object_key_hash ASC',
			$recent,
			25
		);
	}

	/**
	 * Extract the SELECT list from a query.
	 *
	 * @param string $sql Query.
	 */
	private function select_list( string $sql ): string {
		$this->assertSame( 1, preg_match( '/SELECT (.+?) FROM /s', $sql, $matches ) );

		return $matches[1];
	}

	/**
	 * Count `%s`/`%d` placeholders.
	 *
	 * @param string $sql Query.
	 */
	private function placeholder_count( string $sql ): int {
		return (int) preg_match_all( '/%[sd]/', $sql );
	}
}
