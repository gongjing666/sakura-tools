<?php
/** Durable transfer/adoption notifications; business commits survive delivery failure. */
declare( strict_types=1 );
namespace Nboke\UserFiles\Services;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;
defined( 'ABSPATH' ) || exit;
final class Media_Notifications {
	/** One operation is a merged message to each affected owner. */
	public function deliver_due( int $limit = 10 ): int {
		global $wpdb;
		$table = Schema::table( 'media_operations' );
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE notification_status='pending' AND next_at<=%s ORDER BY id LIMIT %d", Time::now(), max( 1, min( 20, $limit ) ) ) );
		$sent = 0;
		foreach ( $ids as $id ) {
			$lock = 'sufm-media-msg:' . get_current_blog_id() . ':' . (int) $id;
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { continue; }
			try {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND notification_status='pending'", (int) $id ) );
				if ( ! $row ) { continue; }
				$result = json_decode( (string) $row->result_json, true );
				if ( ! is_array( $result ) || ! isset( $result['owner_id'] ) ) { continue; }
				$owners = array_map( 'intval', (array) ( $result['notification_owners'] ?? array( (int) $result['owner_id'] ) ) );
				foreach ( (array) ( $result['outgoing'] ?? array() ) as $source ) { $owners[] = (int) $source['owner_id']; }
				$owners = array_unique( $owners ); $failed = false;
				foreach ( $owners as $owner ) {
					if ( ! get_userdata( $owner ) || ! empty( $result['notification_receipts'][ $owner ] ) ) { continue; }
					$dedupe = 'sufm-media:' . get_current_blog_id() . ':' . (int) $id . ':' . $owner;
					$receipt = apply_filters( 'sufm_media_transfer_notification', null, $owner, $result, $dedupe );
					if ( null === $receipt && is_callable( array( 'ZibMsg', 'add' ) ) && is_callable( array( 'ZibMsg', 'get_row' ) ) ) {
						$existing = call_user_func( array( 'ZibMsg', 'get_row' ), array( 'receive_user' => $owner, 'other' => $dedupe ) );
						if ( $existing ) { $receipt = true; }
						else {
							$title = __( '文件空间整理完成', 'sakura-imgopt' );
							/* translators: 1: file count, 2: target user ID, 3: operation time, 4: operation ID */
							$content = sprintf( __( '管理员已完成一批文件转交或纳管，共%1$d个文件。目标用户：#%2$d；整理时间：%3$s；操作编号：%4$d。文件地址保持不变。', 'sakura-imgopt' ), count( (array) $result['moved'] ), (int) $result['owner_id'], (string) $result['created_at'], (int) $id );
							$receipt = call_user_func( array( 'ZibMsg', 'add' ), array( 'send_user' => (int) $result['actor_id'], 'receive_user' => $owner, 'type' => 'system', 'title' => $title, 'content' => esc_html( $content ), 'other' => $dedupe ) );
						}
					}
					// Zibll returns the inserted values array, not an integer ID.
					if ( ! is_wp_error( $receipt ) && ( true === $receipt || is_array( $receipt ) || ( is_numeric( $receipt ) && (int) $receipt > 0 ) ) ) {
						$result['notification_receipts'][ $owner ] = true;
						if ( false === $wpdb->update( $table, array( 'result_json' => wp_json_encode( $result ) ), array( 'id' => (int) $id ) ) ) { $failed = true; break; }
					} else { $failed = true; }
				}
				$attempts = (int) $row->attempts + 1;
				$status = $failed ? 'pending' : 'sent';
				$result['notification_status'] = $status;
				$wpdb->update( $table, array( 'notification_status' => $status, 'result_json' => wp_json_encode( $result ), 'attempts' => $attempts, 'next_at' => Time::in( min( 3600, 60 * ( 2 ** min( 6, $attempts - 1 ) ) ) ), 'last_error' => $failed ? 'notification_adapter_unavailable_or_failed' : '' ), array( 'id' => (int) $id ) );
				if ( ! $failed ) { ++$sent; }
			} catch ( \Throwable $e ) {
				$wpdb->update( $table, array( 'next_at' => Time::in( 300 ), 'last_error' => 'notification_delivery_failed' ), array( 'id' => (int) $id ) );
			} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
		}
		return $sent;
	}
}
