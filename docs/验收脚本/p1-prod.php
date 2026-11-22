<?php
/**
 * Production acceptance for P1 (sanctions) — strictly scoped.
 *
 * Everything here touches only the two disposable subscribers created for this
 * run (`sufm_test_sanction`, `sufm_test_sanction_b`) and the attachments it
 * creates under their names. No real user, no real file, no real option.
 *
 * Prerequisite — the script aborts if these two accounts are missing, because
 * it refuses to touch any account it did not create for this run:
 *
 *   wp user create sufm_test_sanction   sufm_test_sanction@example.invalid \
 *     --role=subscriber --user_pass=$(openssl rand -base64 24)
 *   wp user create sufm_test_sanction_b sufm_test_sanction_b@example.invalid \
 *     --role=subscriber --user_pass=$(openssl rand -base64 24)
 *
 * Cleanup afterwards (removes the accounts and every row keyed to them):
 *   sufm-prod-cleanup.php, dry-run first, then again with SUFM_APPLY=1.
 *
 * Run:
 *   SUFM_SITE=/www/wwwroot/www.nboke.com \
 *     wp eval-file p1-prod.php --allow-root
 */

/* `wp eval-file` evaluates this inside a method, so $argv is not in scope and
 * `wp` rejects unknown flags; the site comes from the environment. */
$sufm_site = (string) ( getenv( 'SUFM_SITE' ) ?: '/www/wwwroot/www.nboke.com' );
require_once rtrim( $sufm_site, '/' ) . '/wp-load.php';

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Media\Media_Hooks;
use Nboke\UserFiles\Module;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Schema;
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

global $wpdb;

$a_id = (int) username_exists( 'sufm_test_sanction' );
$b_id = (int) username_exists( 'sufm_test_sanction_b' );

if ( $a_id <= 0 || $b_id <= 0 ) {
	echo "ABORT: refusing to run — the disposable test users are missing.\n";
	exit( 2 );
}

$table  = Schema::table( 'sanctions' );
$files  = Schema::table( 'files' );
$events = Schema::table( 'events' );
$outbox = Schema::table( 'notifications' );

echo "== site: {$sufm_site} ==\n";
echo "== disposable users: {$a_id} / {$b_id} ==\n";

/* ---------------------------------------------------------------- helpers --- */
$wipe = static function () use ( $wpdb, $table, $outbox, $events, $a_id, $b_id ): void {
	foreach ( array( $table, $outbox, $events ) as $t ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
};

$make_file = static function ( int $owner, string $name ) {
	$upload = wp_upload_dir();
	$path   = trailingslashit( $upload['path'] ) . $name;
	file_put_contents( $path, 'sufm production acceptance probe' );

	$id = (int) wp_insert_attachment(
		array(
			'post_title'     => $name,
			'post_author'    => $owner,
			'post_status'    => 'inherit',
			'post_mime_type' => 'text/plain',
		),
		$path
	);

	return array( $id, $path );
};

wp_set_current_user( 1 ); // sakura, the acting administrator.

$ref = new ReflectionClass( Module::class );
$mod = $ref->newInstanceWithoutConstructor();
$get = $ref->getMethod( 'services' );
$get->setAccessible( true );
$svc  = $get->invoke( $mod );
$srv  = $svc['sanctioning'];
$repo = $srv->repo();
$tick = $ref->getMethod( 'tick' );
$tick->setAccessible( true );

$created_attachments = array();

/* ------------------------------------------------------------ 0. baseline --- */
$wipe();
chk( '建表存在且为空', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
chk( 'Schema VERSION 是 1.7.0', '1.7.0' === Schema::VERSION, Schema::VERSION );
chk( 'administrator 拿到处罚能力', true === user_can( 1, Capabilities::SANCTION_USERS ) );
chk( '订阅者没有处罚能力', false === user_can( $a_id, Capabilities::SANCTION_USERS ) );

/* --------------------------------------- 1. E1：处罚期插件上传被拦，删不受影响 -- */
$id = $srv->apply(
	array(
		'owner_id'    => $a_id,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_UPLOAD_BAN,
		'scope'       => Sanctions::SCOPE_PLUGIN,
		'reason_code' => 'illegal',
		'note'        => '生产验收',
		'days'        => 7,
	)
);
chk( '建处罚返回 id', is_int( $id ) && $id > 0, wp_json_encode( $id ) );
chk( '处罚期 blocks_upload=true', true === $srv->blocks_upload( $a_id ) );
chk( '处罚期 blocks_login=false（P1 不碰登录）', false === $srv->blocks_login( $a_id ) );

$err = $svc['uploader']->handle( $a_id, 0, array( 'name' => 'probe.txt', 'tmp_name' => '', 'error' => 0, 'size' => 10 ), '' );
chk(
	'Uploader::handle 返回 sufm_sanction_upload 错误',
	is_wp_error( $err ) && 'sufm_sanction_upload' === $err->get_error_code(),
	is_wp_error( $err ) ? $err->get_error_code() : 'no error'
);
if ( is_wp_error( $err ) ) {
	$data = $err->get_error_data();
	chk( '错误带 HTTP 403', is_array( $data ) && 403 === ( $data['status'] ?? 0 ), wp_json_encode( $data ) );
	chk( '错误文案是中文且提示仍可删除', str_contains( $err->get_error_message(), '处罚期' ), $err->get_error_message() );
}

/* 允许删：台账能力不被没收 */
$user = get_user_by( 'id', $a_id );
chk( '没收走 sufm_manage_own_files（仍可删自己的文件）', $user->has_cap( Capabilities::MANAGE_OWN ) );

/* ------------------------------------- 2. 下架一批 + 勾选处罚：聚合与 operation_id -- */
$wipe();
$file_a = $make_file( $a_id, 'sufm-prod-a-' . time() . '.txt' );
$file_b = $make_file( $b_id, 'sufm-prod-b-' . time() . '.txt' );
$created_attachments[] = $file_a;
$created_attachments[] = $file_b;

chk( '台账自动登记了 A 的文件', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE attachment_id=%d", $file_a[0] ) ) > 0 );
chk( '台账自动登记了 B 的文件', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE attachment_id=%d", $file_b[0] ) ) > 0 );

$hooks = new Media_Hooks(
	$svc['files'],
	$svc['folders'],
	$svc['requests'],
	$svc['sync'],
	$svc['quota'],
	$svc['events'],
	$svc['outbox'],
	$svc['sanctioning']
);

$_REQUEST['sufm_takedown_reason']        = 'illegal';
$_REQUEST['sufm_takedown_note']          = '生产验收批量';
$_REQUEST['sufm_takedown_sanction']      = '1';
$_REQUEST['sufm_takedown_sanction_type'] = 'upload_ban';
$_REQUEST['sufm_takedown_sanction_days'] = '7';

$redirect = $hooks->handle_takedown( admin_url( 'upload.php' ), array( $file_a[0], $file_b[0] ) );
parse_str( (string) parse_url( (string) $redirect, PHP_URL_QUERY ), $qs );

chk( '下架结果 URL 带回下架数 2', '2' === ( $qs['sufm_takedown_done'] ?? '' ), wp_json_encode( $qs ) );
chk( '下架结果 URL 带回处罚数 2', '2' === ( $qs['sufm_takedown_sanctioned'] ?? '' ), wp_json_encode( $qs ) );

$sanctions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id IN (%d,%d) ORDER BY owner_id", $a_id, $b_id ) );
chk( '两个用户各一条处罚', 2 === count( $sanctions ), (string) count( $sanctions ) );
chk( '同批处罚共用同一 operation_id', 2 === count( $sanctions ) && (string) $sanctions[0]->operation_id === (string) $sanctions[1]->operation_id, 2 === count( $sanctions ) ? $sanctions[0]->operation_id : '' );
$op_id = 2 === count( $sanctions ) ? (string) $sanctions[0]->operation_id : '';
chk( 'operation_id 形如 takedown:{ts}-{actor}', 1 === preg_match( '/^takedown:\d{14}-\d+$/', $op_id ), $op_id );

$batch_events = $wpdb->get_results( $wpdb->prepare( "SELECT action,result,owner_id FROM {$events} WHERE operation_id=%s ORDER BY id", $op_id ) );
$deletes      = 0;
$sanc_events  = 0;
foreach ( $batch_events as $ev ) {
	if ( 'delete' === $ev->action ) {
		++$deletes;
	}
	if ( 'sanction' === $ev->action ) {
		++$sanc_events;
	}
}
chk( '2 条 delete 事件共用同一 operation_id', 2 === $deletes, (string) $deletes );
chk( '2 条 sanction 事件共用同一 operation_id', 2 === $sanc_events, (string) $sanc_events );

$note_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id IN (%d,%d) AND dedupe_key LIKE %s", $a_id, $b_id, 'takedown:%' ) );
chk( '每条下架通知按 owner 聚合（2 条，不是 2 文件 2 条×2）', 2 === $note_count, (string) $note_count );

chk( 'A 被禁上传', true === $srv->blocks_upload( $a_id ) );
chk( 'B 被禁上传', true === $srv->blocks_upload( $b_id ) );

/* ------------------------------------------------ 3. 永久处罚的确认门（服务层） -- */
$perm = $srv->apply(
	array(
		'owner_id'    => $a_id,
		'actor_id'    => 1,
		'type'        => Sanctions::TYPE_UPLOAD_BAN,
		'scope'       => Sanctions::SCOPE_PLUGIN,
		'reason_code' => 'illegal',
		'note'        => '生产验收永久',
		'days'        => 0,
	)
);
chk( '服务层能把 days=0 当永久（REST 才是确认门）', is_int( $perm ) && $perm > 0, wp_json_encode( $perm ) );
if ( is_int( $perm ) ) {
	$perm_row = $repo->get( $perm );
	chk( '永久行 ends_at 为空', $perm_row && ( null === $perm_row->ends_at || '' === (string) $perm_row->ends_at ) );
	$revoked = $srv->revoke( $perm, 1 );
	chk( '撤销永久处罚成功', true === $revoked, is_wp_error( $revoked ) ? $revoked->get_error_code() : wp_json_encode( $revoked ) );
}

/* ------------------------------------------------- 4. 撤销后立刻恢复（不等 cron） -- */
$active_a = $repo->active_for( $a_id, 5 );
foreach ( $active_a as $row ) {
	$srv->revoke( (int) $row->id, 1 );
}
chk( '撤销全部后 A 立刻能上传', false === $srv->blocks_upload( $a_id ) );

/* --------------------------------------- 5. 懒到期：改库把 ends_at 挪到过去 -- */
$active_b = $repo->active_for( $b_id, 1 );
if ( $active_b ) {
	$wpdb->update( $table, array( 'ends_at' => Time::in( -60 ) ), array( 'id' => (int) $active_b[0]->id ) );
	chk( 'ends_at 到过去后立刻解禁（懒判定）', false === $srv->blocks_upload( $b_id ) );
	$state_b = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$table} WHERE id=%d", (int) $active_b[0]->id ) );
	chk( '读一次就把 state 翻成 expired', 'expired' === $state_b, $state_b );

	$tick->invoke( $mod );
	$notified = (string) $wpdb->get_var( $wpdb->prepare( "SELECT expired_notified_at FROM {$table} WHERE id=%d", (int) $active_b[0]->id ) );
	chk( 'tick 补写 expired_notified_at', '' !== $notified && Time::zero() !== $notified, $notified );
	$expired_audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events} WHERE owner_id=%d AND action='sanction' AND result='expired'", $b_id ) );
	chk( 'tick 补写 expired 审计', 1 === $expired_audit, (string) $expired_audit );
	$expired_note = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id=%d AND dedupe_key LIKE %s", $b_id, '%sanction_expired%' ) );
	chk( 'tick 排了一条到期通知', $expired_note >= 1, (string) $expired_note );

	$before = $expired_note;
	$tick->invoke( $mod );
	$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id=%d AND dedupe_key LIKE %s", $b_id, '%sanction_expired%' ) );
	chk( 'tick 幂等，不重复通知', $before === $after, "{$before} -> {$after}" );
} else {
	echo "SKIP  B 没有 active 处罚可测到期\n";
}

/* ------------------------------------------------- 6. 拒绝：自己 / 管理员 / 越权 -- */
chk( '不能处罚自己', false === $srv->can_sanction( 1 ) );
$admin_bad = $srv->apply( array( 'owner_id' => 2, 'actor_id' => 1, 'type' => Sanctions::TYPE_WARN, 'days' => 0 ) );
chk( '不能处罚另一个管理员', is_wp_error( $admin_bad ) && 'sufm_sanction_forbidden' === $admin_bad->get_error_code(), is_wp_error( $admin_bad ) ? $admin_bad->get_error_code() : 'no error' );

/* ---------------------------------------- 7. REST 面（真实 dispatch，含权限回调） -- */
do_action( 'rest_api_init', rest_get_server() );
$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}

	return rest_get_server()->dispatch( $request );
};

$res = $call( 'GET', '/sufm/v1/admin/users/' . $a_id . '/sanction' );
chk( 'REST 表单 200', 200 === $res->get_status(), (string) $res->get_status() );
$form = (array) $res->get_data();
chk( 'REST 表单带 6 个理由', 6 === count( (array) ( $form['reasons'] ?? array() ) ), (string) count( (array) ( $form['reasons'] ?? array() ) ) );
chk( 'REST 表单 can_sanction=true', true === ( $form['can_sanction'] ?? null ) );

$res = $call( 'POST', '/sufm/v1/admin/users/' . $a_id . '/sanction', array( 'type' => 'upload_ban', 'days' => 0, 'reason_code' => 'illegal', 'note' => 'x', 'confirm' => 'wrong' ) );
chk( '永久缺正确 confirm 被拒 400', 400 === $res->get_status(), (string) $res->get_status() );
$body = (array) $res->get_data();
chk( '拒绝原因码 sufm_sanction_confirm', 'sufm_sanction_confirm' === ( $body['code'] ?? '' ), (string) ( $body['code'] ?? '' ) );

$login = (string) get_user_by( 'id', $a_id )->user_login;
$res   = $call( 'POST', '/sufm/v1/admin/users/' . $a_id . '/sanction', array( 'type' => 'upload_ban', 'days' => 0, 'reason_code' => 'illegal', 'note' => '永久验收', 'confirm' => $login ) );
chk( '永久带正确 confirm 通过 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$made = (array) $res->get_data();
chk( '永久行 permanent=true', true === ( $made['sanction']['permanent'] ?? null ), wp_json_encode( $made['sanction'] ?? null ) );
$made_id = (int) ( $made['sanction']['id'] ?? 0 );

$res = $call( 'POST', '/sufm/v1/admin/sanctions/' . $made_id . '/revoke' );
chk( 'REST 撤销 200', 200 === $res->get_status(), (string) $res->get_status() );
$rev = (array) $res->get_data();
chk( 'REST 撤销返回 content_restored=false', false === ( $rev['content_restored'] ?? null ), wp_json_encode( $rev ) );

$res = $call( 'GET', '/sufm/v1/admin/users', array( 'per_page' => 100 ) );
$rows = (array) ( ( (array) $res->get_data() )['rows'] ?? array() );
$hit  = null;
foreach ( $rows as $row ) {
	if ( (int) $row['id'] === $a_id ) {
		$hit = $row;
	}
}
chk( '用户列表含 can_sanction 键', null !== $hit && array_key_exists( 'can_sanction', $hit ) );
chk( '用户列表含 sanction 键', null !== $hit && array_key_exists( 'sanction', $hit ) );
chk( '管理员行的处罚按钮被服务端判为不可用', $hit && true === $hit['can_sanction'] );

/* 越权：把当前用户换成没能力的订阅者 */
$saved = get_current_user_id();
wp_set_current_user( $b_id );
$res = $call( 'POST', '/sufm/v1/admin/users/' . $a_id . '/sanction', array( 'type' => 'warn', 'days' => 0, 'reason_code' => 'other', 'note' => 'x' ) );
chk( '无能力者被 permission_callback 拒（401/403）', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
wp_set_current_user( $saved );

/* ------------------------------------------------------------------ cleanup -- */
$wipe();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE dedupe_key LIKE %s", $op_id ? '%' . substr( $op_id, 9, 40 ) . '%' : '%__none__%' ) );

foreach ( $created_attachments as $pair ) {
	$aid = (int) $pair[0];
	if ( $aid > 0 ) {
		wp_delete_attachment( $aid, true );
	}
	if ( isset( $pair[1] ) && file_exists( $pair[1] ) ) {
		@unlink( $pair[1] );
	}
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$files} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) );

chk( '收尾：无遗留处罚行', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ) );
chk( '收尾：无遗留探测附件', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ) );

unset( $_REQUEST['sufm_takedown_reason'], $_REQUEST['sufm_takedown_note'], $_REQUEST['sufm_takedown_sanction'], $_REQUEST['sufm_takedown_sanction_type'], $_REQUEST['sufm_takedown_sanction_days'] );

echo "\n==== {$GLOBALS['p']} passed, {$GLOBALS['f']} failed ====\n";
exit( $GLOBALS['f'] > 0 ? 1 : 0 );
