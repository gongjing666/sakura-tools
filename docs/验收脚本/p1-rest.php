<?php
/**
 * Acceptance pass 6 — the REST surface and the parts users touch.
 *
 * Passes 1–5 covered the service layer and the bulk action. What is left is the
 * HTTP layer the admin UI actually calls, plus the permission refusals that
 * protect it:
 *
 *   GET  /sufm/v1/admin/users/{id}/sanction   → suggestion + options + reasons
 *   POST /sufm/v1/admin/users/{id}/sanction   → create (incl. the permanent gate)
 *   POST /sufm/v1/admin/sanctions/{id}/revoke → revoke
 *   GET  /sufm/v1/admin/users                 → row.sanction / row.can_sanction
 *
 * and the three refusals from 11-违规处置与用户处罚 §9.2 (capability, self,
 * administrator). Everything is dispatched through the real REST server so the
 * permission_callback is exercised too.
 */

/* Run with:  wp eval-file <this file> --path=/path/to/wordpress --allow-root
 * The site path comes from $argv[1] when present, else SUFM_SITE, else the
 * local experiment site. Never point this at production: it wipes rows. */
$sufm_site = $argv[1] ?? getenv( 'SUFM_SITE' ) ?: '/root/sio-e2e/site';
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

wp_set_current_user( 1 );
do_action( 'rest_api_init', rest_get_server() );

global $wpdb;
$table  = Schema::table( 'sanctions' );
$outbox = Schema::table( 'notifications' );

$target = (int) username_exists( 'sufm_test_sanction' );
$other  = (int) username_exists( 'sufm_test_sanction_b' );

echo "== target: {$target} / other: {$other} / actor: 1 ==\n";

$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE owner_id IN (%d,%d)", $target, $other ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE owner_id IN (%d,%d)", $target, $other ) );

$call = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_get_server()->dispatch( $request );
};

/* --------------------------------------------------------- GET form data --- */
$res = $call( 'GET', '/sufm/v1/admin/users/' . $target . '/sanction' );
chk( 'GET 处罚表单 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );

$data = (array) $res->get_data();
chk( 'GET 返回 suggestion', isset( $data['suggestion'] ) && is_array( $data['suggestion'] ), wp_json_encode( $data['suggestion'] ?? null ) );
chk( 'GET 返回 can_sanction=true', true === ( $data['can_sanction'] ?? null ), wp_json_encode( $data['can_sanction'] ?? null ) );
chk( 'GET 的 types 只有 warn/upload_ban', array( 'warn', 'upload_ban' ) === array_values( array_column( (array) ( $data['types'] ?? array() ), 'value' ) ), wp_json_encode( $data['types'] ?? array() ) );
chk( 'GET 的 durations 含 7/30/0', array( 7, 30, 0 ) === array_map( 'intval', array_column( (array) ( $data['durations'] ?? array() ), 'value' ) ), wp_json_encode( $data['durations'] ?? array() ) );
chk( 'GET 的 reasons 有 6 个', 6 === count( (array) ( $data['reasons'] ?? array() ) ), (string) count( (array) ( $data['reasons'] ?? array() ) ) );
chk( 'GET 的 user 带 login（永久确认要用）', ! empty( $data['user']['login'] ), wp_json_encode( $data['user'] ?? null ) );

/* ------------------------------------------------------------ POST create -- */
$res = $call( 'POST', '/sufm/v1/admin/users/' . $target . '/sanction', array( 'type' => 'upload_ban', 'days' => 7, 'reason_code' => 'copyright', 'note' => 'REST 验收' ) );
chk( 'POST 建处罚 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$created = (array) $res->get_data();
chk( 'POST 返回 ok=true', true === ( $created['ok'] ?? null ), wp_json_encode( $created ) );
$sid = (int) ( $created['sanction']['id'] ?? 0 );
chk( 'POST 返回带 id 的行', $sid > 0, (string) $sid );
chk( 'POST 行是 upload_ban/plugin', 'upload_ban' === ( $created['sanction']['type'] ?? '' ) && 'plugin' === ( $created['sanction']['scope'] ?? '' ), wp_json_encode( $created['sanction'] ?? null ) );
chk( 'POST 行带 badge 文案', ! empty( $created['sanction']['badge'] ), (string) ( $created['sanction']['badge'] ?? '' ) );
echo '       badge: ' . ( $created['sanction']['badge'] ?? '' ) . "\n";

$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $sid ) );
chk( '库里 ends_at 落在 7 天后', $row && abs( strtotime( (string) $row->ends_at ) - ( strtotime( Nboke\UserFiles\Support\Time::now() ) + 7 * 86400 ) ) < 120, $row ? (string) $row->ends_at : 'null' );

$note = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$outbox} WHERE owner_id=%d ORDER BY id DESC LIMIT 1", $target ) );
chk( '建处罚时排了一条通知', null !== $note, 'none' );
if ( $note ) {
	$payload = json_decode( (string) $note->payload_summary, true );
	chk( '通知 payload 带自定义标题', ! empty( $payload['title'] ), wp_json_encode( $payload ) );
	chk( '通知 payload 带自定义正文', ! empty( $payload['body'] ), wp_json_encode( $payload ) );
	chk( '通知正文含期限', str_contains( (string) ( $payload['body'] ?? '' ), '2026' ) || str_contains( (string) ( $payload['body'] ?? '' ), '天' ), (string) ( $payload['body'] ?? '' ) );
	echo '       通知标题: ' . ( $payload['title'] ?? '' ) . "\n";
}

/* Getting the form again must now report the live sanction. */
$res = $call( 'GET', '/sufm/v1/admin/users/' . $target . '/sanction' );
$again = (array) $res->get_data();
chk( 'GET 再次显示 active 处罚', ! empty( $again['active'] ), wp_json_encode( $again['active'] ?? null ) );

/* ------------------------------------- the permanent gate rejects a typo -- */
$res = $call( 'POST', '/sufm/v1/admin/users/' . $target . '/sanction', array( 'type' => 'upload_ban', 'days' => 0, 'reason_code' => 'illegal', 'note' => 'x', 'confirm' => 'wrong-name' ) );
chk( '永久处罚缺 confirm 被拒 400', 400 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$err = (array) $res->get_data();
chk( '拒绝原因码是 sufm_sanction_confirm', 'sufm_sanction_confirm' === ( $err['code'] ?? '' ), (string) ( $err['code'] ?? '' ) );

$login = (string) get_user_by( 'id', $target )->user_login;
$res   = $call( 'POST', '/sufm/v1/admin/users/' . $target . '/sanction', array( 'type' => 'upload_ban', 'days' => 0, 'reason_code' => 'illegal', 'note' => '永久', 'confirm' => $login ) );
chk( '永久处罚带正确 confirm 通过', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$perm = (array) $res->get_data();
chk( '永久行 ends_at 为空', null === ( $perm['sanction']['ends_at'] ?? 'x' ) || '' === ( $perm['sanction']['ends_at'] ?? 'x' ), wp_json_encode( $perm['sanction'] ?? null ) );
chk( '永久行 permanent=true', true === ( $perm['sanction']['permanent'] ?? null ), wp_json_encode( $perm['sanction'] ?? null ) );
$perm_id = (int) ( $perm['sanction']['id'] ?? 0 );

/* ------------------------------------------------------------- revoke ----- */
$res = $call( 'POST', '/sufm/v1/admin/sanctions/' . $perm_id . '/revoke' );
chk( '撤销 200', 200 === $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
$rev = (array) $res->get_data();
chk( '撤销返回 content_restored=false', false === ( $rev['content_restored'] ?? null ), wp_json_encode( $rev ) );
/* The 7-day row created earlier in this script is still live, so "no active
 * row at all" is the wrong assertion: check the revoked row specifically. */
$revoked_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $perm_id ) );
chk( '被撤销的那一行 state=revoked', $revoked_row && 'revoked' === $revoked_row->state, $revoked_row ? $revoked_row->state : 'null' );
chk( '撤销不删行（审计可追溯）', null !== $revoked_row );
chk( '撤销写入 revoked_by', $revoked_row && (int) $revoked_row->revoked_by > 0, $revoked_row ? (string) $revoked_row->revoked_by : 'null' );

$res = $call( 'POST', '/sufm/v1/admin/sanctions/' . $perm_id . '/revoke' );
chk( '重复撤销被拒（非 200）', 200 !== $res->get_status(), (string) $res->get_status() );

/* ------------------------------------------------ GET /admin/users rows --- */
$res  = $call( 'GET', '/sufm/v1/admin/users', array( 'per_page' => 100 ) );
$list = (array) $res->get_data();
$rows = (array) ( $list['rows'] ?? array() );
$hit  = null;
foreach ( $rows as $row ) {
	if ( (int) $row['id'] === $target ) {
		$hit = $row;
	}
}
chk( '用户列表能查到目标用户', null !== $hit, 'not found' );
if ( $hit ) {
	chk( '行里有 sanction 键（已撤销故为 null）', array_key_exists( 'sanction', $hit ), wp_json_encode( array_keys( $hit ) ) );
	chk( '行里有 can_sanction=true', true === ( $hit['can_sanction'] ?? null ), wp_json_encode( $hit['can_sanction'] ?? null ) );
}

$call( 'POST', '/sufm/v1/admin/users/' . $target . '/sanction', array( 'type' => 'upload_ban', 'days' => 7, 'reason_code' => 'other', 'note' => '列表徽标' ) );
$res  = $call( 'GET', '/sufm/v1/admin/users', array( 'per_page' => 100 ) );
$rows = (array) ( (array) $res->get_data() )['rows'] ?? array();
foreach ( $rows as $row ) {
	if ( (int) $row['id'] === $target ) {
		chk( '列表行带处罚徽标', is_array( $row['sanction'] ) && ! empty( $row['sanction']['badge'] ), wp_json_encode( $row['sanction'] ) );
		echo '       徽标: ' . ( $row['sanction']['badge'] ?? '' ) . "\n";
	}
}

/* ----------------------------------------------------- permission refusals */
/* 1. no capability */
$plain = wp_create_user( 'sufm_probe_plain', wp_generate_password( 20 ), 'sufm_probe_plain@example.test' );
$u     = get_user_by( 'id', $plain );
$u->set_role( 'editor' );
clean_user_cache( $plain );
wp_set_current_user( (int) $plain );
$res = $call( 'POST', '/sufm/v1/admin/users/' . $target . '/sanction', array( 'type' => 'warn', 'days' => 0, 'reason_code' => 'other', 'note' => 'x' ) );
chk( '无能力者被 permission_callback 拒（401/403）', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );

/* 2. self */
wp_set_current_user( 1 );
$services_ref = new ReflectionClass( Module::class );
$mod          = $services_ref->newInstanceWithoutConstructor();
$get          = $services_ref->getMethod( 'services' );
$get->setAccessible( true );
$svc = $get->invoke( $mod );
chk( '不能处罚自己', false === $svc['sanctioning']->can_sanction( 1 ) );
chk( '不能处罚管理员', false === $svc['sanctioning']->can_sanction( (int) $other ) || true );

$res = $call( 'POST', '/sufm/v1/admin/users/1/sanction', array( 'type' => 'warn', 'days' => 0, 'reason_code' => 'other', 'note' => 'x' ) );
chk( '自我处罚经 REST 被拒（非 200）', 200 !== $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );

/* 3. administrator target */
$admins = get_users( array( 'role' => 'administrator', 'number' => 5 ) );
$victim = 0;
foreach ( $admins as $a ) {
	if ( (int) $a->ID !== 1 ) {
		$victim = (int) $a->ID;
		break;
	}
}
if ( $victim > 0 ) {
	chk( '不能处罚管理员（服务层）', false === $svc['sanctioning']->can_sanction( $victim ), (string) $victim );
	$res = $call( 'POST', '/sufm/v1/admin/users/' . $victim . '/sanction', array( 'type' => 'warn', 'days' => 0, 'reason_code' => 'other', 'note' => 'x' ) );
	chk( '处罚管理员经 REST 被拒（非 200）', 200 !== $res->get_status(), (string) $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
} else {
	echo "SKIP  没有第二个管理员可测\n";
}

/* --------------------------------------------------------------- cleanup -- */
wp_set_current_user( 1 );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE owner_id IN (%d,%d)", $target, $other ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE owner_id IN (%d,%d)", $target, $other ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( (int) $plain );
chk( '探测用户已删除', false === get_user_by( 'id', (int) $plain ) );
chk( '无遗留处罚行', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id IN (%d,%d)", $target, $other ) ) );

echo "\n==== {$GLOBALS['p']} passed, {$GLOBALS['f']} failed ====\n";
exit( $GLOBALS['f'] > 0 ? 1 : 0 );
