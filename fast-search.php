<?php
/**
 * Fast, token-authenticated sync table endpoint.
 *
 * admin-ajax.php boots the whole admin stack (~150 ms) even though the query
 * itself takes under a millisecond. This endpoint loads WordPress with
 * SHORTINIT (~2 ms), verifies a short-lived HMAC token plus the administrator
 * role, and answers the same paging/search contract as the AJAX endpoint.
 *
 * Direct file access is intentional: it is the only way to skip the full
 * bootstrap. The token is bound to one user, one profile and a 10 minute
 * expiry; it only grants read-only access to object metadata.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Blacklisted -- Never leak paths from a public endpoint.

/**
 * Send a JSON error and stop.
 *
 * @param int    $status  HTTP status.
 * @param string $message Message.
 */
function sakura_fast_search_fail( int $status, string $message ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, private, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );
	echo json_encode( array( 'success' => false, 'data' => array( 'message' => $message ) ), JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WP bootstrap.
	exit;
}

if ( ! isset( $_GET['u'], $_GET['e'], $_GET['p'], $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token below.
	sakura_fast_search_fail( 403, 'Forbidden.' );
}

define( 'SHORTINIT', true );
require_once dirname( __FILE__, 4 ) . '/wp-load.php';
require_once __DIR__ . '/src/Sync/Kind.php';
require_once __DIR__ . '/src/Sync/Keyset_Cursor.php';
require_once __DIR__ . '/src/Sync/Fast_Search_Query.php';

$user_id   = (int) $_GET['u']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token below.
$expires   = (int) $_GET['e']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token below.
$profile   = (string) $_GET['p']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token below.
$signature = strtolower( (string) $_GET['s'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signed token below.

if ( $user_id <= 0 || $expires < time() || ! preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $profile ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
	sakura_fast_search_fail( 403, 'Forbidden.' );
}

$secret   = hash(
	'sha256',
	'sakura-sync-search|'
		. ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
		. '|'
		. ( defined( 'SECURE_AUTH_SALT' ) ? (string) SECURE_AUTH_SALT : '' )
);
$expected = hash_hmac( 'sha256', 'v1|' . $user_id . '|' . $expires . '|' . $profile, $secret );

if ( ! hash_equals( $expected, $signature ) ) {
	sakura_fast_search_fail( 403, 'Forbidden.' );
}

global $wpdb;

$roles = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-authenticated admin check.
	$wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s LIMIT 1",
		$user_id,
		$wpdb->prefix . 'capabilities'
	)
);

if ( ! is_string( $roles ) || false === strpos( $roles, 'administrator' ) ) {
	sakura_fast_search_fail( 403, 'Forbidden.' );
}

$index_table  = $wpdb->prefix . 'sio_sync_index';
$counts_table = $wpdb->prefix . 'sio_sync_counts';

$status = strtolower( (string) ( $_GET['status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.
$search = trim( (string) ( $_GET['search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.
$type   = strtolower( (string) ( $_GET['type'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.
$days   = max( 0, min( 3650, (int) ( $_GET['days'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.
$cursor = (string) ( $_GET['cursor'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.
$limit  = max( 1, min( 100, (int) ( $_GET['limit'] ?? 50 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated read-only request.

if ( ! in_array( $status, array( 'all', 'synced', 'pending', 'failed', 'remote', 'mismatch', 'ignored' ), true ) ) {
	$status = 'all';
}
if ( ! in_array( $type, array( 'all', 'image', 'video', 'archive', 'other' ), true ) ) {
	$type = 'all';
}

$upload_path = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', 'wp-content/uploads' ) ), '/' );
if ( '' === $upload_path ) {
	$upload_path = 'wp-content/uploads';
}
if ( '' !== $search && 0 !== strpos( $search, $upload_path . '/' ) ) {
	$search = $upload_path . '/' . $search;
}
$by_key    = '' !== $search;
$by_recent = $days > 0 && '1' === (string) get_option( 'sakura_imgopt_sync_recent_ready', '' );

$where = array( 'profile_id=%s' );
$args  = array( $profile );

if ( 'all' !== $status ) {
	$where[] = 'status=%s';
	$args[]  = $status;
}
if ( $by_key ) {
	$where[] = 'object_key LIKE %s';
	$args[]  = $wpdb->esc_like( $search ) . '%';
}
if ( 'all' !== $type ) {
	$where[] = 'kind=%s';
	$args[]  = $type;
}
if ( $days > 0 ) {
	$cut = gmdate( 'Y-m-d H:i:s', time() - ( $days * 86400 ) );
	if ( $by_recent ) {
		$where[] = 'recent_at >= %s';
		$args[]  = $cut;
	} else {
		$where[] = '(local_mtime >= %s OR remote_mtime >= %s)';
		$args[]  = $cut;
		$args[]  = $cut;
	}
}

if ( '' !== $cursor ) {
	if ( $by_recent ) {
		$boundary = \Sakura\ImageOptimizer\Sync\Keyset_Cursor::decode_recent( $cursor );
		if ( null !== $boundary ) {
			$where[] = '(recent_at < %s OR (recent_at = %s AND object_key_hash < UNHEX(%s)))';
			$args[]  = $boundary[0];
			$args[]  = $boundary[0];
			$args[]  = $boundary[1];
		}
	} else {
		$raw = base64_decode( $cursor, true );
		if ( is_string( $raw ) && '' !== $raw ) {
			if ( $by_key && "\x02" === $raw[0] ) {
				$boundary = substr( $raw, 1 );
				if ( '' !== $boundary ) {
					$where[] = 'object_key > %s';
					$args[]  = $boundary;
				}
			} elseif ( ! $by_key && 32 === strlen( $raw ) ) {
				$where[] = 'object_key_hash > UNHEX(%s)';
				$args[]  = bin2hex( $raw );
			}
		}
	}
}

if ( $by_recent ) {
	$order = 'recent_at DESC, object_key_hash DESC';
} else {
	$order = $by_key ? 'object_key ASC' : 'object_key_hash ASC';
}

// Single source of truth for the SELECT list, the recent index and the LIMIT
// binding: a duplicated statement here previously dropped recent_at (breaking
// the paging cursor) and left one argument more than the placeholders.
list( $sql, $args ) = \Sakura\ImageOptimizer\Sync\Fast_Search_Query::build(
	$index_table,
	implode( ' AND ', $where ),
	$args,
	$order,
	$by_recent,
	$limit
);

$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Where clauses use placeholders.
$rows = is_array( $rows ) ? $rows : array();

$has_more = count( $rows ) > $limit;
if ( $has_more ) {
	array_pop( $rows );
}

$out = array();
foreach ( $rows as $row ) {
	$key      = (string) $row['object_key'];
	$kind     = (string) ( $row['kind'] ?? '' );
	$local    = (string) ( $row['local_mtime'] ?? '' );
	$remote   = (string) ( $row['remote_mtime'] ?? '' );
	$local_ts  = '' !== $local ? (int) strtotime( $local . ' UTC' ) : 0;
	$remote_ts = '' !== $remote ? (int) strtotime( $remote . ' UTC' ) : 0;
	$mtime     = max( $local_ts, $remote_ts );

	$out[] = array(
		'key'        => $key,
		'name'       => basename( $key ),
		'path'       => '' !== (string) $row['local_path'] ? (string) $row['local_path'] : $key,
		'type'       => '' !== $kind ? $kind : \Sakura\ImageOptimizer\Sync\Kind::of( $key ),
		'size'       => (int) $row['local_size'],
		'cloud_size' => (int) $row['remote_size'],
		'mtime'      => $mtime,
		'status'     => (string) $row['status'],
		'error'      => (string) ( $row['error_message'] ?? '' ),
		'local'      => (bool) $row['seen_local'],
		'cloud'      => (bool) $row['seen_remote'],
		'id'         => 0,
	);
}

$next = '';
if ( $has_more && $rows ) {
	$next = \Sakura\ImageOptimizer\Sync\Fast_Search_Query::next_cursor( $rows[ count( $rows ) - 1 ], $by_recent, $by_key );

	// A row without recent_at (index backfill still running for this profile)
	// cannot produce a decodable cursor; stop here instead of serving page 1 again.
	if ( '' === $next ) {
		$has_more = false;
	}
}

$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(item_count),0) FROM {$counts_table} WHERE profile_id=%s", $profile ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix.

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, private, max-age=0' );
header( 'X-Accel-Expires: 0' );
header( 'X-Robots-Tag: noindex, nofollow' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );

echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WP bootstrap.
	array(
		'success' => true,
		'data'    => array(
			'rows'     => $out,
			'cursor'   => $next,
			'has_more' => $has_more,
			'total'    => $total,
		),
	),
	JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
