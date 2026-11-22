<?php
/**
 * Undo everything p1-prod.php created.
 *
 * Pairs with `p1-prod.php`: that script creates two disposable subscribers
 * (`sufm_test_sanction`, `sufm_test_sanction_b`) plus sanctions, events,
 * notifications, ledger rows, file tokens and zibll messages keyed to them.
 * This removes all of it and then the accounts themselves.
 *
 * Two guards, both of which abort instead of guessing:
 *
 *   - the accounts must be named `sufm_test_sanction` / `sufm_test_sanction_b`;
 *   - both must be plain subscribers owning no posts and no comments.
 *
 * Dry run by default, so a first pass always shows the plan:
 *
 *   wp eval-file p1-prod-cleanup.php --allow-root
 *   SUFM_APPLY=1 wp eval-file p1-prod-cleanup.php --allow-root
 *
 * Nothing here is hardcoded except the two login names: ids are looked up by
 * login and ledger rows by owner, so a rerun after a partial failure converges.
 */

/* `wp eval-file` evaluates this inside a method and `wp` rejects unknown flags,
 * so both the site and the apply switch come from the environment. */
$sufm_site = (string) ( getenv( 'SUFM_SITE' ) ?: '/www/wwwroot/www.nboke.com' );
require_once rtrim( $sufm_site, '/' ) . '/wp-load.php';

$sufm_apply = '1' === (string) getenv( 'SUFM_APPLY' );

global $wpdb;

echo $sufm_apply ? "== APPLY ==\n" : "== DRY RUN (SUFM_APPLY=1 to delete) ==\n";

/* ---- resolve the probes by login, never by id ---- */
$sufm_logins = array( 'sufm_test_sanction', 'sufm_test_sanction_b' );
$sufm_ids    = array();

foreach ( $sufm_logins as $sufm_login ) {
	$sufm_user = get_user_by( 'login', $sufm_login );

	if ( ! $sufm_user ) {
		continue;
	}

	$sufm_ids[] = (int) $sufm_user->ID;
	echo "user {$sufm_user->ID} {$sufm_user->user_login}\n";

	if ( array( 'subscriber' ) !== array_values( (array) $sufm_user->roles ) ) {
		fwrite( STDERR, "REFUSING: {$sufm_login} is not a plain subscriber\n" );
		exit( 2 );
	}
}

if ( ! $sufm_ids ) {
	echo "nothing to do: neither probe account exists\n";
	exit( 0 );
}

/* ---- refuse if the probes own content ---- */
$sufm_in      = implode( ',', array_map( 'intval', $sufm_ids ) );
$sufm_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author IN ({$sufm_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$sufm_comments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id IN ({$sufm_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo "posts owned: {$sufm_posts}\ncomments owned: {$sufm_comments}\n";

if ( $sufm_posts > 0 || $sufm_comments > 0 ) {
	fwrite( STDERR, "REFUSING: the probes own content; deleting would destroy it\n" );
	exit( 2 );
}

/* ---- the ledger rows these accounts own, and therefore their tokens ---- */
$sufm_file_ids = $wpdb->get_col( "SELECT id FROM nb_sufm_files WHERE owner_id IN ({$sufm_in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$sufm_token_in = $sufm_file_ids ? implode( ',', array_map( 'intval', $sufm_file_ids ) ) : '';

/* Order matters: tokens reference the ledger rows, so they go first. */
$sufm_steps = array(
	'tokens' => array(
		'count'  => $sufm_token_in ? "SELECT COUNT(*) FROM nb_sufm_file_tokens WHERE file_id IN ({$sufm_token_in})" : 'SELECT 0',
		'delete' => $sufm_token_in ? "DELETE FROM nb_sufm_file_tokens WHERE file_id IN ({$sufm_token_in})" : 'SELECT 1',
	),
	'files'         => array(
		'count'  => "SELECT COUNT(*) FROM nb_sufm_files WHERE owner_id IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_sufm_files WHERE owner_id IN ({$sufm_in})",
	),
	'usage'         => array(
		'count'  => "SELECT COUNT(*) FROM nb_sufm_usage WHERE owner_id IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_sufm_usage WHERE owner_id IN ({$sufm_in})",
	),
	'notifications' => array(
		'count'  => "SELECT COUNT(*) FROM nb_sufm_notifications WHERE owner_id IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_sufm_notifications WHERE owner_id IN ({$sufm_in})",
	),
	'events'        => array(
		'count'  => "SELECT COUNT(*) FROM nb_sufm_events WHERE owner_id IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_sufm_events WHERE owner_id IN ({$sufm_in})",
	),
	'sanctions'     => array(
		'count'  => "SELECT COUNT(*) FROM nb_sufm_sanctions WHERE owner_id IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_sufm_sanctions WHERE owner_id IN ({$sufm_in})",
	),
	'messages'      => array(
		'count'  => "SELECT COUNT(*) FROM nb_zib_message WHERE receive_user IN ({$sufm_in})",
		'delete' => "DELETE FROM nb_zib_message WHERE receive_user IN ({$sufm_in})",
	),
);

foreach ( $sufm_steps as $sufm_label => $sufm_step ) {
	echo '-- ' . str_pad( $sufm_label, 14 );

	if ( ! $sufm_apply ) {
		echo (int) $wpdb->get_var( $sufm_step['count'] ) . " row(s) would go\n"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		continue;
	}

	$sufm_n = $wpdb->query( $sufm_step['delete'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	if ( false === $sufm_n ) {
		echo "FAILED: {$wpdb->last_error}\n";
		exit( 1 );
	}

	echo "{$sufm_n} deleted\n";
}

/* ---- the accounts themselves ---- */
require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $sufm_ids as $sufm_id ) {
	$sufm_user = get_user_by( 'id', $sufm_id );

	if ( ! $sufm_user ) {
		echo "user {$sufm_id}: already gone\n";
		continue;
	}

	if ( ! $sufm_apply ) {
		echo "user {$sufm_id} {$sufm_user->user_login}: would be deleted\n";
		continue;
	}

	echo "user {$sufm_id} {$sufm_user->user_login}: " . ( wp_delete_user( $sufm_id ) ? 'deleted' : 'FAILED' ) . "\n";
}

/* ---- verify ---- */
echo "\n== leftovers ==\n";

$sufm_checks = array(
	'tokens'        => $sufm_token_in ? "SELECT COUNT(*) FROM nb_sufm_file_tokens WHERE file_id IN ({$sufm_token_in})" : 'SELECT 0',
	'files'         => "SELECT COUNT(*) FROM nb_sufm_files WHERE owner_id IN ({$sufm_in})",
	'usage'         => "SELECT COUNT(*) FROM nb_sufm_usage WHERE owner_id IN ({$sufm_in})",
	'notifications' => "SELECT COUNT(*) FROM nb_sufm_notifications WHERE owner_id IN ({$sufm_in})",
	'events'        => "SELECT COUNT(*) FROM nb_sufm_events WHERE owner_id IN ({$sufm_in})",
	'sanctions'     => "SELECT COUNT(*) FROM nb_sufm_sanctions WHERE owner_id IN ({$sufm_in})",
	'messages'      => "SELECT COUNT(*) FROM nb_zib_message WHERE receive_user IN ({$sufm_in})",
	'usermeta'      => "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id IN ({$sufm_in})",
	'users'         => "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID IN ({$sufm_in})",
);

$sufm_dirty = 0;

foreach ( $sufm_checks as $sufm_label => $sufm_sql ) {
	$sufm_n = (int) $wpdb->get_var( $sufm_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	echo str_pad( $sufm_label, 15 ) . $sufm_n . "\n";

	if ( $sufm_n > 0 ) {
		++$sufm_dirty;
	}
}

echo $sufm_dirty ? "\nNOT CLEAN: {$sufm_dirty} table(s) still hold probe rows\n" : "\nclean\n";
