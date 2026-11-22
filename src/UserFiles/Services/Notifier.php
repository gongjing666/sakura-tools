<?php
/**
 * Theme message delivery for the notification outbox.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Repositories\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery is best-effort: the outbox row stays pending when the theme message
 * cannot be written, and the domain transition is never rolled back for it.
 */
final class Notifier {

	/**
	 * Outbox.
	 *
	 * @var Notifications
	 */
	private Notifications $outbox;

	/**
	 * Wire dependencies.
	 *
	 * @param Notifications $outbox Outbox repository.
	 */
	public function __construct( Notifications $outbox ) {
		$this->outbox = $outbox;
	}

	/**
	 * Human title per request status.
	 *
	 * @param string $status Request status.
	 * @return string
	 */
	public static function title( string $status ): string {
		switch ( $status ) {
			case 'pending_review':
				return __( '删除申请已提交', 'sakura-imgopt' );
			case 'approved':
				return __( '删除申请已通过审核', 'sakura-imgopt' );
			case 'rejected':
				return __( '删除申请未通过', 'sakura-imgopt' );
			case 'revoked':
				return __( '删除申请已撤销批准', 'sakura-imgopt' );
			case 'withdrawn':
				return __( '删除申请已撤回', 'sakura-imgopt' );
			case 'completed':
				return __( '删除已完成', 'sakura-imgopt' );
			case 'partial_failed':
				return __( '删除部分完成', 'sakura-imgopt' );
			case 'failed':
				return __( '删除失败', 'sakura-imgopt' );
			case 'stopped':
				return __( '删除已停止', 'sakura-imgopt' );
			case 'queued':
				return __( '删除已排队', 'sakura-imgopt' );
			case 'executing':
				return __( '删除执行中', 'sakura-imgopt' );
			case 'moderation':
				return __( '文件已被管理员下架', 'sakura-imgopt' );
			case 'sanctioned':
				return __( '账号已被限制上传', 'sakura-imgopt' );
			case 'sanction_revoked':
				return __( '账号处罚已撤销', 'sakura-imgopt' );
			case 'sanction_expired':
				return __( '账号处罚已到期', 'sakura-imgopt' );
			default:
				return __( '文件管理通知', 'sakura-imgopt' );
		}
	}

	/**
	 * Body text: counts and status only, never paths or object keys.
	 *
	 * @param string               $status  Request status.
	 * @param array<string, mixed> $summary Summary.
	 * @return string
	 */
	public static function body( string $status, array $summary ): string {
		$count  = (int) ( $summary['count'] ?? 0 );
		$reason = trim( (string) ( $summary['reason'] ?? '' ) );
		$reason = '' !== $reason ? ' ' . sprintf(
			/* translators: %s: review reason */
			__( '原因：%s', 'sakura-imgopt' ),
			mb_substr( $reason, 0, 120 )
		) : '';

		switch ( $status ) {
			case 'pending_review':
				return sprintf(
					/* translators: %d: number of items */
					__( '您的删除申请包含 %d 个对象，管理员审核通过后将在后台统一执行。', 'sakura-imgopt' ),
					$count
				);
			case 'approved':
				return __( '管理员已通过您的删除申请。通过审核不会立即删除文件，后台确认执行后才会处理。', 'sakura-imgopt' );
			case 'rejected':
				return __( '管理员未通过您的删除申请，文件保持不变。', 'sakura-imgopt' ) . $reason;
			case 'completed':
				return __( '您申请删除的内容已执行完成。', 'sakura-imgopt' );
			case 'partial_failed':
				return __( '部分内容删除失败，管理员可在后台查看明细。', 'sakura-imgopt' );
			case 'revoked':
				return __( '管理员已撤销先前的批准，文件保持不变。', 'sakura-imgopt' ) . $reason;
			case 'moderation':
				return sprintf(
					/* translators: %d: number of files */
					__( '管理员已下架您文件空间中的 %d 个文件，占用容量已相应释放。如有疑问请联系站点管理员。', 'sakura-imgopt' ),
					$count
				) . $reason;
			case 'sanctioned':
				/* The sanction row is the authority for the type and the window;
				 * the outbox only carries the sentence the operator's decision
				 * produced, so the copy lives with the decision, not here. */
				$custom = trim( (string) ( $summary['body'] ?? '' ) );

				return '' !== $custom
					? $custom
					: __( '管理员已对您的账号作出处罚，处罚期间不能上传新文件，已有文件仍可自行删除。', 'sakura-imgopt' );
			case 'sanction_revoked':
				$custom = trim( (string) ( $summary['body'] ?? '' ) );

				return '' !== $custom
					? $custom
					: __( '管理员已撤销对您账号的处罚。此前下架的文件不会恢复。', 'sakura-imgopt' );
			case 'sanction_expired':
				return __( '您账号的处罚已到期，上传功能已恢复。', 'sakura-imgopt' );
			default:
				return __( '您的文件空间有新的状态更新。', 'sakura-imgopt' );
		}
	}

	/**
	 * Queue the owner-facing summary for one request transition, then try to
	 * deliver immediately. Delivery failure keeps the row retryable.
	 *
	 * @param object $request Request row.
	 * @param string $status  New status.
	 * @param int    $count   Item count.
	 * @return void
	 */
	public function after( object $request, string $status, int $count ): void {
		$this->outbox->enqueue(
			(int) $request->owner_id,
			(int) $request->id,
			$status,
			array(
				'count'    => $count,
				'event_id' => 0,
				'revision' => (int) ( $request->approval_revision ?? 1 ),
			)
		);

		$this->deliver_due( 5 );
	}

	/**
	 * Queue an owner-visible review outcome (approve / reject / revoke).
	 *
	 * @param object $request Request row after the transition.
	 * @param string $status  New status.
	 * @param string $reason  Reviewer reason.
	 * @return void
	 */
	public function review( object $request, string $status, string $reason = '' ): void {
		$this->outbox->enqueue(
			(int) $request->owner_id,
			(int) $request->id,
			$status,
			array(
				'count'    => 0,
				'reason'   => mb_substr( $reason, 0, 120 ),
				'event_id' => 0,
				'revision' => (int) ( $request->approval_revision ?? 0 ),
			)
		);

		$this->deliver_due( 5 );
	}

	/**
	 * Deliver every due outbox row.
	 *
	 * @param int $limit Row cap.
	 * @return int Delivered count.
	 */
	public function deliver_due( int $limit = 20 ): int {
		$sent = 0;

		$this->outbox->reclaim_stale();

		foreach ( $this->outbox->claim_due( $limit ) as $row ) {
			$summary = json_decode( (string) $row->payload_summary, true );
			$summary = is_array( $summary ) ? $summary : array();
			$status  = (string) ( $summary['status'] ?? 'pending_review' );
			$owner   = (int) $row->owner_id;

			/**
			 * Allow another adapter to deliver; return an int message id or WP_Error.
			 *
			 * @param int|null $message_id Message id.
			 * @param object   $row        Outbox row.
			 */
			$result = apply_filters( 'sufm_deliver_notification', null, $row );

			if ( is_wp_error( $result ) ) {
				$this->outbox->mark_failed( (int) $row->id, (string) $result->get_error_code(), (int) $row->attempts + 1 );
				continue;
			}

			if ( is_int( $result ) ) {
				$this->outbox->mark_sent( (int) $row->id, $result );
				++$sent;
				continue;
			}

			$message_id = $this->theme_message( $owner, $status, $summary );

			if ( $message_id > 0 ) {
				$this->outbox->mark_sent( (int) $row->id, $message_id );
				++$sent;
				continue;
			}

			$this->outbox->mark_failed( (int) $row->id, 'no_adapter', (int) $row->attempts + 1 );
		}

		return $sent;
	}

	/**
	 * Zibll in-site message, when the theme is active.
	 *
	 * @param int                  $owner   Owner user id.
	 * @param string               $status  Status.
	 * @param array<string, mixed> $summary Summary.
	 * @return int Message id, 0 when unavailable.
	 */
	private function theme_message( int $owner, string $status, array $summary ): int {
		global $wpdb;

		// The theme's notifier only exists at runtime, so both names are
		// filterable and looked up dynamically.
		$class  = (string) apply_filters( 'sufm_notifier_class', '\ZibMsg' );
		$method = (string) apply_filters( 'sufm_notifier_method', 'add' );

		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			return 0;
		}

		$title  = trim( (string) ( $summary['title'] ?? '' ) );
		$body   = trim( (string) ( $summary['body'] ?? '' ) );

		$message_id = call_user_func(
			array( $class, $method ),
			array(
				'receive_user' => $owner,
				'type'         => 'system',
				'title'        => '' !== $title ? $title : self::title( $status ),
				'content'      => '' !== $body ? $body : self::body( $status, $summary ),
			)
		);

		// Zibll 9.2 returns the normalised values array without the row id, so the
		// insert id of the message row is the only reliable receipt.
		if ( is_array( $message_id ) ) {
			$message_id = (int) ( $message_id['id'] ?? $wpdb->insert_id );
		}

		return is_numeric( $message_id ) ? (int) $message_id : 0;
	}
}
