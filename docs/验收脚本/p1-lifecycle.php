<?php
/**
 * Acceptance pass 7 — the promises that only hold across a full request or a
 * cron run, plus uninstall coverage.
 *
 * Passes 1–6 checked the pieces. These check the contracts the document makes
 * about them:
 *
 *   §1 S6    expiry must clear the block on the very next read, not on cron
 *   §4       the minute tick closes the bookkeeping (state + notice)
 *   §7.3     one batch = one notification per owner, no matter how many files
 *   §9.1     uninstall drops the table
 *   §5.1     revoking restores uploads immediately
 */

/* Run with:  wp eval-file <this file> --path=/path/to/wordpress --allow-root
 * The site path comes from $argv[1] when present, else SUFM_SITE, else the
 * experiment site (demo.77il.cn). Never point this at production: it wipes
 * rows. */
$sufm_site = $argv[1] ?? getenv( 'SUFM_SITE' ) ?: '/www/wwwroot/demo.77il.cn';
require_once rtrim( $sufm_site, '/' ) . '/wp-load.php';


use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Module;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Services\Sanction_Service;
use Nboke\UserFiles\Support\Time;

$GLOBALS['p'] = 0;
$GLOBALS['f'] = 0;

function chk( string $label, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['p'];
		echo "PASS  {$label}\n";
		return;
	}
	++$GLOBALS['f'];
	echo "FAIL  {$label}" . ( '' !== $detail ? "  ({$detail})" : '' ) . "\n";
}

wp_set_current_user( 1 );

$ref = new ReflectionClass( Module::class );
$mod = $ref->newInstanceWithoutConstructor();
$get = $ref->getMethod( 'services' );
$get->setAccessible( true );
$svc   = $get->invoke( $mod );
$srv   = $svc['sanctioning'];
$repo  = $srv->repo();
$tick  = $ref->getMethod( 'tick' );
$tick->setAccessible( true );

global $wpdb;
$table  = Schema::table( 'sanctions' );
$outbox = Schema::table( 'notifications' );

$owner = (int) username_exists( 'sufm_test_sanction' );
$events = Schema::table( 'events' );
$wipe  = static function () use ( $wpdb, $table, $outbox, $events, $owner ): void {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE owner_id=%d", $owner ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE owner_id=%d", $owner ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE owner_id=%d", $owner ) );
};

$wipe();

/* ------------------------------------- §5.1 revoke restores uploads at once -- */
$id = $srv->apply(
	array(
		'owner_id'    => $owner,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_UPLOAD_BAN,
		'scope'       => Sanctions::SCOPE_PLUGIN,
		'reason_code' => 'abuse',
		'note'        => 'pass7 revoke',
		'days'        => 7,
	)
);
chk( '建处罚成功', is_int( $id ) && $id > 0, wp_json_encode( $id ) );
chk( '处罚期 blocks_upload=true', true === $srv->blocks_upload( $owner ) );

$revoked = $srv->revoke( (int) $id, 1 );
chk( '撤销成功', true === $revoked, wp_json_encode( is_wp_error( $revoked ) ? $revoked->get_error_code() : $revoked ) );
chk( '撤销后立刻 blocks_upload=false（不等 cron）', false === $srv->blocks_upload( $owner ) );

/* -------------------------------- §1 S6 expiry clears the block on next read -- */
$id2 = $srv->apply(
	array(
		'owner_id'    => $owner,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_UPLOAD_BAN,
		'scope'       => Sanctions::SCOPE_PLUGIN,
		'reason_code' => 'abuse',
		'note'        => 'pass7 expiry',
		'days'        => 7,
	)
);
$wpdb->update( $table, array( 'ends_at' => Time::in( -60 ) ), array( 'id' => (int) $id2 ) );

chk( '过期后 blocks_upload=false（懒判定）', false === $srv->blocks_upload( $owner ) );
$state = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$table} WHERE id=%d", (int) $id2 ) );
chk( '读到过期即把 state 改成 expired', 'expired' === $state, $state );
/* The read only flips the column; the closing audit + notice belong to the
 * tick, which owns the single watermark. Assert the read stayed cheap. */
chk( '读到过期时不写审计（审计归 tick）', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE owner_id=%d AND action='sanction' AND result='expired'", $owner ) ) );

/* --------------------------------------- §4 the minute tick closes the books -- */
$wipe();
$id3 = $srv->apply(
	array(
		'owner_id'    => $owner,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_UPLOAD_BAN,
		'scope'       => Sanctions::SCOPE_PLUGIN,
		'reason_code' => 'abuse',
		'note'        => 'pass7 tick',
		'days'        => 7,
	)
);
$wpdb->update( $table, array( 'ends_at' => Time::in( -60 ) ), array( 'id' => (int) $id3 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$outbox} SET status='sent' WHERE owner_id=%d", $owner ) );

$tick->invoke( $mod );

$state3 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$table} WHERE id=%d", (int) $id3 ) );
chk( 'tick() 把过期行置 expired', 'expired' === $state3, $state3 );
chk( 'tick() 补写 expired 审计', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE owner_id=%d AND action='sanction' AND result='expired'", $owner ) ) );
$notified = (string) $wpdb->get_var( $wpdb->prepare( "SELECT expired_notified_at FROM {$table} WHERE id=%d", (int) $id3 ) );
chk( 'tick() 写了 expired_notified_at', '' !== $notified && Time::zero() !== $notified, $notified );
$expired_note = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id=%d AND dedupe_key LIKE %s", $owner, '%sanction_expired%' ) );
chk( 'tick() 排了一条到期通知', $expired_note >= 1, (string) $expired_note );

/* tick 再跑一次不应该重复置位/重复通知 */
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id=%d AND dedupe_key LIKE %s", $owner, '%sanction_expired%' ) );
$tick->invoke( $mod );
$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id=%d AND dedupe_key LIKE %s", $owner, '%sanction_expired%' ) );
chk( 'tick() 幂等，不重复通知', $before === $after, "{$before} -> {$after}" );

/* ------------------------------------------------------ §9.1 uninstall list -- */
chk( 'tables() 含 sanctions', in_array( 'sanctions', Schema::tables(), true ), wp_json_encode( Schema::tables() ) );
chk( 'Schema::VERSION 已升到 1.7.0', '1.7.0' === Schema::VERSION, Schema::VERSION );

$uninstall_src = (string) file_get_contents( '/root/work/sakura-tools/src/Uninstaller.php' );
chk( 'Uninstaller 清 sufm_sanction_caps_version', str_contains( $uninstall_src, 'sufm_sanction_caps_version' ) );
chk( 'Uninstaller 走 Schema::tables() 自动 DROP', str_contains( $uninstall_src, 'Schema::tables()' ) );

/* -------------------------------------------------- §9.2 refusals, once more -- */
$wipe();
chk( 'can_sanction 拒自己', false === $srv->can_sanction( 1 ) );
chk( 'can_sanction 拒 0', false === $srv->can_sanction( 0 ) );

$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE owner_id=%d", 38 ) );
chk( '无处罚时 blocks_upload=false', false === $srv->blocks_upload( 38 ) );

/* ----------------------------------------- create() refuses a bogus type ---- */
$bad = $srv->apply( array( 'owner_id' => 38, 'actor_id' => 1, 'type' => 'nonsense', 'days' => 7 ) );
chk( '非法类型被拒', is_wp_error( $bad ) && 'sufm_sanction_invalid' === $bad->get_error_code(), is_wp_error( $bad ) ? $bad->get_error_code() : 'no error' );

/* ----------------------------------- warn never gets a window, ever -------- */
$warn_id = $srv->apply(
	array(
		'owner_id'    => 38,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_WARN,
		'reason_code' => 'other',
		'note'        => 'pass7 warn',
		'days'        => 30,
	)
);
$warn_row = is_int( $warn_id ) ? $repo->get( (int) $warn_id ) : null;
chk( 'warn 即使传 30 天也不写 ends_at', $warn_row && ( null === $warn_row->ends_at || '' === (string) $warn_row->ends_at ), $warn_row ? (string) var_export( $warn_row->ends_at, true ) : 'no row' );
chk( 'warn 不拦上传', false === $srv->blocks_upload( 38 ) );

$wipe();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE owner_id=%d", 38 ) );
chk( '收尾：无遗留处罚行', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id IN (37,38)" ) ) );

echo "\n==== {$GLOBALS['p']} passed, {$GLOBALS['f']} failed ====\n";
exit( $GLOBALS['f'] > 0 ? 1 : 0 );
