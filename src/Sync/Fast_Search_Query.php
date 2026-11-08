<?php
/**
 * Read-only list query builder for the fast sync endpoint.
 *
 * fast-search.php is a direct-access SHORTINIT script and cannot use the
 * plugin autoloader, so this class is required by hand. It exists to keep the
 * paging contract in one testable place: the selected columns must include
 * `recent_at` (the next cursor is derived from the last row), the recent
 * ordering must use the profile_recent index, and the LIMIT placeholder must
 * be bound exactly once.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the object list query used by fast-search.php.
 */
final class Fast_Search_Query {

	/**
	 * Columns returned to the endpoint.
	 *
	 * Do not drop `recent_at`: the days-filter cursor is built from it.
	 */
	public const COLUMNS = 'object_key,kind,local_path,local_size,remote_size,local_mtime,remote_mtime,recent_at,status,error_message,seen_local,seen_remote';

	/**
	 * Build the list SQL and its bound arguments.
	 *
	 * @param string             $table     Prefixed index table name.
	 * @param string             $where     Already joined WHERE clause with placeholders.
	 * @param array<int, mixed>  $args      Arguments matching the WHERE placeholders.
	 * @param string             $order     ORDER BY expression without the keyword.
	 * @param bool               $by_recent Whether recent_at drives the ordering.
	 * @param int                $limit     Requested page size.
	 * @return array{0:string,1:array<int,mixed>} SQL plus arguments (LIMIT appended once).
	 */
	public static function build( string $table, string $where, array $args, string $order, bool $by_recent, int $limit ): array {
		$force = $by_recent ? ' FORCE INDEX (profile_recent)' : '';
		$sql   = 'SELECT ' . self::COLUMNS . " FROM {$table}{$force} WHERE {$where} ORDER BY {$order} LIMIT %d";

		$args[] = $limit + 1;

		return array( $sql, $args );
	}

	/**
	 * Build the cursor that continues after the last row of a page.
	 *
	 * @param array<string, mixed> $row       Last row of the page.
	 * @param bool                 $by_recent Recent ordering.
	 * @param bool                 $by_key    Search ordering (object_key ASC).
	 * @return string
	 */
	public static function next_cursor( array $row, bool $by_recent, bool $by_key ): string {
		$key = (string) ( $row['object_key'] ?? '' );

		if ( $by_recent ) {
			$recent_at = (string) ( $row['recent_at'] ?? '' );

			// decode_recent() rejects an empty timestamp, so such a cursor would
			// silently restart at page one. Signal "no cursor" instead.
			if ( '' === $recent_at ) {
				return '';
			}

			return Keyset_Cursor::from_recent( $recent_at, hash( 'sha256', $key, true ) );
		}

		return $by_key
			? base64_encode( "\x02" . $key ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque keyset cursor.
			: base64_encode( hash( 'sha256', $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque keyset cursor.
	}
}
