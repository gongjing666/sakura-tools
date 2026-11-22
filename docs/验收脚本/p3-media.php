<?php
/**
 * Acceptance for the media manager page (files → 媒体管理) and the shared
 * Takedown service it introduced.
 *
 * What this covers that the existing scripts do not:
 *
 *   - the two REST endpoints the page calls (GET /admin/media, POST
 *     /admin/media/takedown), including their validation branches;
 *   - the permission split between "can browse" (can_use_files) and "can take
 *     down" (can_takedown = upload_files + can_execute);
 *   - the contract the page relies on: owner rows carry `sanction`, files carry
 *     `attachment` and `thumb`, `total` is filled;
 *   - Takedown::run() reached through REST (not through the list table), so a
 *     regression that only ships in the new entry point shows up here;
 *   - the same batch produces one sanction per owner and one operation_id
 *     shared by every delete + sanction event.
 *
 * Everything is created here and removed at the end.
 *
 * Run:
 *   SUFM_SITE=/root/sio-e2e/site wp eval-file p3-media.php --allow-root
 */

$sufm_site = (string) ( getenv( 'SUFM_SITE' ) ?: '/root/sio-e2e/site' );
require_once rtrim( $sufm_site, '/' ) . '/wp-load.php';

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Module;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Schema;

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

$table  = Schema::table( 'sanctions' );
$files  = Schema::table( 'files' );
$events = Schema::table( 'events' );
$outbox = Schema::table( 'notifications' );

echo "== site: {$sufm_site} ==\n";

/* Two disposable subscribers, created by this script under a recognisable name. */
$mk_user = static function ( string $login ): int {
	$existing = (int) username_exists( $login );

	if ( $existing > 0 ) {
		return $existing;
	}

	$id = (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $login . '@example.invalid',
			'role'       => 'subscriber',
		)
	);

	return $id > 0 ? $id : 0;
};

$a_id = $mk_user( 'sufm_mm_probe_a' );
$b_id = $mk_user( 'sufm_mm_probe_b' );

if ( $a_id <= 0 || $b_id <= 0 ) {
	echo "ABORT: could not create the disposable probe users.\n";
	exit( 2 );
}

foreach ( array( $a_id, $b_id ) as $probe ) {
	$probe_user = get_user_by( 'id', $probe );
	$probe_user->add_cap( 'upload_files' );
	$probe_user->add_cap( Capabilities::MANAGE_OWN );
	clean_user_cache( $probe );
}

wp_set_current_user( 1 );
do_action( 'rest_api_init', rest_get_server() );

$ref = new ReflectionClass( Module::class );
$mod = $ref->newInstanceWithoutConstructor();
$get = $ref->getMethod( 'services' );
$get->setAccessible( true );
$svc = $get->invoke( $mod );

$created        = array();
$created_users  = array();
$code_of        = static function ( $res ): string {
	$data = (array) $res->get_data();

	return isset( $data['code'] ) ? (string) $data['code'] : '';
};

/* Returns [attachment id, ledger id, path]. The two id spaces are different and
 * the REST endpoint takes attachment ids — passing ledger ids makes every item
 * fall out at the WP_Post check and the run reports done=0/skipped=N. */
$make_file = static function ( int $owner, string $name ) use ( &$created, $files, $wpdb ): array {
	$upload = wp_upload_dir();
	$path   = trailingslashit( $upload['path'] ) . $name;
	file_put_contents( $path, 'sufm media manager probe' );

	$id = (int) wp_insert_attachment(
		array(
			'post_title'     => $name,
			'post_author'    => $owner,
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
		),
		$path
	);

	$created[] = array( $id, $path );

	$ledger = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$files} WHERE attachment_id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array( $id, $ledger, $path );
};

$wipe = static function () use ( $wpdb, $table, $outbox, $events, $a_id, $b_id ): void {
	foreach ( array( $table, $outbox ) as $t ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_get_server()->dispatch( $request );
};

$call_json = static function ( string $route, array $body ) {
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );

	return rest_get_server()->dispatch( $request );
};

/* ------------------------------------------------- 0. contract of the page --- */
$wipe();
$a      = $make_file( $a_id, 'sufm-mm-a-' . time() . '.png' );
$b      = $make_file( $b_id, 'sufm-mm-b-' . time() . '.png' );
$a_post = $a[0];
$a_row  = $a[1];
$b_post = $b[0];

chk( '探针文件已登记台账（A）', $a_row > 0, (string) $a_row );
chk( '探针文件已登记台账（B）', $b[1] > 0, (string) $b[1] );

$res = $call( 'GET', '/sufm/v1/admin/media', array( 'owner_id' => $a_id, 'per_page' => 10 ) );
chk( 'GET /admin/media 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );

$payload = (array) $res->get_data();
foreach ( array( 'owner_id', 'folder', 'scope', 'breadcrumb', 'folders', 'files', 'total', 'next', 'reasons' ) as $key ) {
	chk( "载荷含 {$key}", array_key_exists( $key, $payload ), implode( ',', array_keys( $payload ) ) );
}
chk( '载荷 reasons 有 6 个', 6 === count( (array) ( $payload['reasons'] ?? array() ) ), (string) count( (array) ( $payload['reasons'] ?? array() ) ) );

$row = null;
foreach ( (array) ( $payload['files'] ?? array() ) as $candidate ) {
	if ( (int) ( $candidate['id'] ?? 0 ) === (int) $a_row ) {
		$row = (array) $candidate;
	}
}
chk( '列表含刚建的文件', null !== $row );
if ( $row ) {
	foreach ( array( 'id', 'attachment', 'owner_id', 'name', 'bytes', 'mime', 'url', 'folder_id', 'folder', 'created_at', 'locked', 'thumb' ) as $key ) {
		chk( "文件行含 {$key}", array_key_exists( $key, $row ), implode( ',', array_keys( $row ) ) );
	}
	chk( '文件行带 attachment（下架要用它）', (int) ( $row['attachment'] ?? 0 ) === (int) $a_post, (string) ( $row['attachment'] ?? 0 ) );
	chk( '文件行带 thumb（图片现算缩略图）', '' !== (string) ( $row['thumb'] ?? '' ), (string) ( $row['thumb'] ?? '' ) );
}

/* The page paints the sanction badge from the owner rows, so the field has to
 * be there even when it is null. `/admin/folders` answers a paginated envelope
 * (`owners`, `page`, `total_users`, `has_more`, ...), not a bare list. */
/* Narrowed by owner so the probe is found regardless of how many accounts the
 * site has (the endpoint pages 50 at a time). */
$res   = $call( 'GET', '/sufm/v1/admin/folders', array( 'owner_id' => $a_id ) );
$page  = (array) $res->get_data();
$owner = null;
foreach ( (array) ( $page['owners'] ?? array() ) as $candidate ) {
	if ( (int) ( $candidate['owner_id'] ?? 0 ) === $a_id ) {
		$owner = (array) $candidate;
	}
}
chk( 'admin/folders 返回该 owner', null !== $owner );
if ( $owner ) {
	chk( 'owner 行有 sanction 键', array_key_exists( 'sanction', $owner ), implode( ',', array_keys( $owner ) ) );
	chk( '未处罚时 sanction 为空', null === $owner['sanction'], wp_json_encode( $owner['sanction'] ) );
}

/* --------------------------------------- 1. validation branches (no writes) --- */
$res = $call_json( '/sufm/v1/admin/media/takedown', array( 'ids' => array(), 'reason' => 'illegal' ) );
chk( '空 ids 被拒 400', 400 === $res->get_status(), (string) $res->get_status() );
chk( '空 ids 原因码 sufm_takedown_empty', 'sufm_takedown_empty' === $code_of( $res ), $code_of( $res ) );

$res = $call_json( '/sufm/v1/admin/media/takedown', array( 'ids' => array( (int) $a_post ), 'reason' => 'not-a-reason' ) );
chk( '非法理由被拒 400', 400 === $res->get_status(), (string) $res->get_status() );
chk( '非法理由原因码 sufm_takedown_reason_missing', 'sufm_takedown_reason_missing' === $code_of( $res ), $code_of( $res ) );
chk( '理由被拒时文件仍在', file_exists( $a[2] ), $a[2] );

/* -------------------------------------------- 2. permissions are two layers -- */
$editor = (int) wp_insert_user(
	array(
		'user_login' => 'sufm_mm_probe_editor',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'sufm_mm_probe_editor@example.invalid',
		'role'       => 'editor',
	)
);

if ( $editor > 0 ) {
	$created_users[] = $editor;
	$ed              = get_user_by( 'id', $editor );
	$ed->add_cap( Capabilities::MANAGE_OWN );
	$ed->add_cap( 'upload_files' );

	wp_set_current_user( $editor );
	$res = $call( 'GET', '/sufm/v1/admin/media' );
	chk( '无 review 能力者不能浏览媒体管理', 200 !== $res->get_status(), (string) $res->get_status() );

	$res = $call_json( '/sufm/v1/admin/media/takedown', array( 'ids' => array( (int) $a_post ), 'reason' => 'illegal' ) );
	chk( '无执行能力者不能下架', 200 !== $res->get_status(), (string) $res->get_status() );
	chk( '越权请求没有删掉文件', file_exists( $a[2] ), $a[2] );
	wp_set_current_user( 1 );
}

/* ----------------------------------------- 3. takedown through the new entry -- */
$res = $call_json(
	'/sufm/v1/admin/media/takedown',
	array(
		'ids'           => array( (int) $a_post, (int) $b_post ),
		'reason'        => 'illegal',
		'note'          => '媒体管理页验收',
		'punish'        => true,
		'sanction_type' => 'upload_ban',
		'sanction_days' => 7,
	)
);
chk( 'REST 下架 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );

$outcome = (array) $res->get_data();
foreach ( array( 'done', 'partial', 'skipped', 'sanctioned', 'operation_id', 'denied', 'owners' ) as $key ) {
	chk( "结果含 {$key}", array_key_exists( $key, $outcome ), implode( ',', array_keys( $outcome ) ) );
}
chk( '两个文件都删掉（done=2）', 2 === (int) ( $outcome['done'] ?? -1 ), (string) ( $outcome['done'] ?? -1 ) );
chk( '两个上传者都被处罚（sanctioned=2）', 2 === (int) ( $outcome['sanctioned'] ?? -1 ), (string) ( $outcome['sanctioned'] ?? -1 ) );
chk( '两个本地文件真的不在了', ! file_exists( $a[2] ) && ! file_exists( $b[2] ) );

$op_id = (string) ( $outcome['operation_id'] ?? '' );
chk( 'operation_id 形如 takedown:{ts}-{actor}', 1 === preg_match( '/^takedown:\d{14}-\d+$/', $op_id ), $op_id );

$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id IN (%d,%d) ORDER BY owner_id", $a_id, $b_id ) );
chk( '两个 owner 各一条处罚', 2 === count( $rows ), (string) count( $rows ) );
chk( '同批处罚共用同一 operation_id', 2 === count( $rows ) && (string) $rows[0]->operation_id === (string) $rows[1]->operation_id, 2 === count( $rows ) ? $rows[0]->operation_id : '' );
chk( '处罚类型/范围/理由正确', 2 === count( $rows ) && 'upload_ban' === $rows[0]->type && 'plugin' === $rows[0]->scope && 'illegal' === $rows[0]->reason_code, 2 === count( $rows ) ? $rows[0]->type . '/' . $rows[0]->scope . '/' . $rows[0]->reason_code : '' );
chk( '处罚七天（ends_at 非空）', 2 === count( $rows ) && ! empty( $rows[0]->ends_at ), 2 === count( $rows ) ? (string) $rows[0]->ends_at : '' );

$batch = $wpdb->get_results( $wpdb->prepare( "SELECT action,result FROM {$events} WHERE operation_id=%s", $op_id ) );
$del   = 0;
$sanc  = 0;
foreach ( $batch as $ev ) {
	if ( 'delete' === $ev->action ) {
		++$del;
	}
	if ( 'sanction' === $ev->action ) {
		++$sanc;
	}
}
chk( '2 条 delete 事件同 op', 2 === $del, (string) $del );
chk( '2 条 sanction 事件同 op', 2 === $sanc, (string) $sanc );

$notes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$outbox} WHERE owner_id IN (%d,%d) AND dedupe_key LIKE %s", $a_id, $b_id, 'takedown:%' ) );
chk( '下架通知按 owner 聚合（2 条）', 2 === $notes, (string) $notes );

chk( '下架后 blocks_upload(A)=true', true === $svc['sanctioning']->blocks_upload( $a_id ) );
chk( '下架后 blocks_upload(B)=true', true === $svc['sanctioning']->blocks_upload( $b_id ) );

/* The tree badge is what tells the operator who is already punished. */
/* Asked one owner at a time: the endpoint pages 50 accounts per call, so a
 * combined query would silently miss the probes on a busy site. */
$badges = 0;
foreach ( array( $a_id, $b_id ) as $probe ) {
	$res = $call( 'GET', '/sufm/v1/admin/folders', array( 'owner_id' => $probe ) );

	foreach ( (array) ( ( (array) $res->get_data() )['owners'] ?? array() ) as $candidate ) {
		if ( (int) ( $candidate['owner_id'] ?? 0 ) === $probe && ! empty( $candidate['sanction']['badge'] ) ) {
			$badges++;
		}
	}
}
chk( '树行带处罚徽标（2 个 owner）', 2 === $badges, (string) $badges );

/* --------------------------------------- 4. a batch with nothing deletable --- */
$res = $call_json( '/sufm/v1/admin/media/takedown', array( 'ids' => array( 999999999 ), 'reason' => 'other', 'punish' => true ) );
chk( '不存在的附件不报错', 200 === $res->get_status(), (string) $res->get_status() );
$outcome = (array) $res->get_data();
chk( '不存在的附件不计入 done', 0 === (int) ( $outcome['done'] ?? -1 ), (string) ( $outcome['done'] ?? -1 ) );
chk( '不存在的附件不计入 sanctioned', 0 === (int) ( $outcome['sanctioned'] ?? -1 ), (string) ( $outcome['sanctioned'] ?? -1 ) );

/* --------------------------------------------- 5. the page's own list scoping -- */
$res = $call( 'GET', '/sufm/v1/admin/media', array( 'search' => 'sufm-mm-a-' ) );
chk( '搜索跨账户 200', 200 === $res->get_status(), (string) $res->get_status() );
$hit = (array) $res->get_data();
chk( '被删文件不再出现在列表里', 0 === count( (array) ( $hit['files'] ?? array() ) ), (string) count( (array) ( $hit['files'] ?? array() ) ) );

$res = $call( 'GET', '/sufm/v1/admin/media', array( 'folder_id' => 999999999 ) );
chk( '不存在的文件夹 404', 404 === $res->get_status(), (string) $res->get_status() );
chk( '不存在的文件夹原因码 sufm_folder_missing', 'sufm_folder_missing' === $code_of( $res ), $code_of( $res ) );

/* ---------------------------------------------------------------- cleanup --- */
wp_set_current_user( 1 );
$wipe();

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $a_id, $b_id ) as $probe ) {
	wp_delete_user( $probe );
}
if ( $created_users ) {
	foreach ( $created_users as $extra ) {
		wp_delete_user( (int) $extra );
	}
}

foreach ( $created as $pair ) {
	wp_delete_attachment( (int) $pair[0], true );
	if ( file_exists( $pair[1] ) ) {
		@unlink( $pair[1] );
	}
}

chk( '探针账号已删除', false === get_user_by( 'id', $a_id ) && false === get_user_by( 'id', $b_id ) );
chk( '无遗留处罚行', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id IN (%d,%d)", $a_id, $b_id ) ) );

echo "\n==== {$GLOBALS['p']} passed, {$GLOBALS['f']} failed ====\n";
exit( $GLOBALS['f'] > 0 ? 1 : 0 );
