<?php
/**
 * Sanction decisions: create, revoke, expire, and answer "is this owner blocked".
 *
 * Separated from the repository on purpose: the repository owns SQL, this class
 * owns the sequence (decide → audit → notify → mirror) and the rules that make a
 * decision safe (no self-sanction, no administrator lockout, permanent bans need
 * an explicit confirmation).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Notifications;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One decision per moderation incident, enforced by readers elsewhere.
 *
 * Nothing here deletes or restores content: a sanction is written after the
 * takedown already ran, and revoking it never brings a file back. That split is
 * what lets the same table drive this plugin's upload guards, the login guards,
 * and (later) a theme adapter, without any of them depending on the others.
 */
final class Sanction_Service {

	/**
	 * Shortest and longest finite duration a single decision may carry.
	 */
	private const MIN_HOURS = 1;

	/**
	 * Upper bound in days; anything longer should be written as permanent.
	 */
	private const MAX_DAYS = 3650;

	/**
	 * Ladder window: repeat offences inside this many days escalate.
	 */
	private const LADDER_DAYS = 30;

	/**
	 * Sanction storage.
	 *
	 * @var Sanctions
	 */
	private Sanctions $repo;

	/**
	 * Audit log.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Notification outbox.
	 *
	 * @var Notifications
	 */
	private Notifications $outbox;

	/**
	 * Immediate delivery, so a warning is not stuck behind the minute tick.
	 *
	 * @var Notifier|null
	 */
	private ?Notifier $notifier;

	/**
	 * Wire dependencies.
	 *
	 * @param Sanctions     $repo     Sanction storage.
	 * @param Events        $events   Audit.
	 * @param Notifications $outbox   Outbox.
	 * @param Notifier|null $notifier Delivery.
	 */
	public function __construct( Sanctions $repo, Events $events, Notifications $outbox, ?Notifier $notifier = null ) {
		$this->repo     = $repo;
		$this->events   = $events;
		$this->outbox   = $outbox;
		$this->notifier = $notifier;
	}

	/**
	 * Storage, for callers that need to list rows.
	 *
	 * @return Sanctions
	 */
	public function repo(): Sanctions {
		return $this->repo;
	}

	/**
	 * May this owner add files anywhere this plugin controls?
	 *
	 * @param int $owner_id Owner.
	 * @return bool
	 */
	public function blocks_upload( int $owner_id ): bool {
		return $this->repo->blocks_upload( $owner_id );
	}

	/**
	 * May this owner sign in?
	 *
	 * @param int $owner_id Owner.
	 * @return bool
	 */
	public function blocks_login( int $owner_id ): bool {
		return $this->repo->blocks_login( $owner_id );
	}

	/**
	 * The live blocking row for one owner, if any.
	 *
	 * @param int $owner_id Owner.
	 * @return object|null
	 */
	public function blocking_upload( int $owner_id ): ?object {
		return $this->repo->blocking( $owner_id, Sanctions::upload_types() );
	}

	/**
	 * Record one decision.
	 *
	 * @param array<string, mixed> $data Decision fields: owner_id, type, scope,
	 *                                  reason_code, note, days (0 = permanent),
	 *                                  operation_id, source.
	 * @return int|WP_Error Sanction id.
	 */
	public function apply( array $data ) {
		$owner = (int) ( $data['owner_id'] ?? 0 );
		$type  = sanitize_key( (string) ( $data['type'] ?? '' ) );

		if ( $owner <= 0 || ! Sanctions::is_type( $type ) ) {
			return new WP_Error( 'sufm_sanction_invalid', __( '处罚参数不完整。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( ! $this->can_sanction( $owner ) ) {
			return new WP_Error( 'sufm_sanction_forbidden', __( '不能对该用户执行处罚：管理员账号不可处罚，也不能处罚自己。', 'sakura-imgopt' ), array( 'status' => 403 ) );
		}

		$days   = max( 0, (int) ( $data['days'] ?? 0 ) );
		$ends   = null;
		$starts = Time::now();

		if ( Sanctions::TYPE_WARN !== $type ) {
			if ( 0 === $days ) {
				// Permanent: the caller is expected to have asked for a typed
				// confirmation first; the row simply has no end.
				$ends = null;
			} else {
				$days = min( self::MAX_DAYS, $days );
				$ends = Time::in( max( self::MIN_HOURS, $days * 86400 ) );
			}
		} else {
			// A warning has no enforcement, so it needs no window: keeping
			// ends_at empty keeps the "permanent" column honest.
			$days = 0;
		}

		$id = $this->repo->create(
			array(
				'owner_id'     => $owner,
				'actor_id'     => (int) ( $data['actor_id'] ?? get_current_user_id() ),
				'type'         => $type,
				'scope'        => (string) ( $data['scope'] ?? Sanctions::SCOPE_PLUGIN ),
				'reason_code'  => (string) ( $data['reason_code'] ?? '' ),
				'note'         => (string) ( $data['note'] ?? '' ),
				'starts_at'    => $starts,
				'ends_at'      => $ends,
				'operation_id' => (string) ( $data['operation_id'] ?? '' ),
				'source'       => (string) ( $data['source'] ?? 'manual' ),
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error( 'sufm_sanction_write', __( '处罚写入失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$row = $this->repo->get( $id );

		if ( $row ) {
			$this->audit( $row, 'applied' );
			$this->notify( $row );
		}

		return $id;
	}

	/**
	 * Withdraw a decision.
	 *
	 * Content is not restored: the files this sanction was issued for are gone,
	 * so the caller must say so before offering the button.
	 *
	 * @param int $id    Sanction id.
	 * @param int $actor Acting user.
	 * @return bool|WP_Error
	 */
	public function revoke( int $id, int $actor ) {
		$row = $this->repo->get( $id );

		if ( ! $row ) {
			return new WP_Error( 'sufm_sanction_missing', __( '处罚记录不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( Sanctions::STATE_ACTIVE !== (string) $row->state ) {
			return new WP_Error( 'sufm_sanction_state', __( '只有生效中的处罚可以撤销。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( ! $this->repo->revoke( $id, $actor ) ) {
			return new WP_Error( 'sufm_sanction_race', __( '处罚状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$fresh = $this->repo->get( $id );

		if ( $fresh ) {
			$this->audit( $fresh, 'revoked' );
			$this->notify( $fresh, 'sanction_revoked' );
		}

		return true;
	}

	/**
	 * Close every lapsed row and tell the owner it ended.
	 *
	 * Runs from the minute tick, but it is not what makes a sanction stop
	 * applying: readers check `ends_at` themselves. This only finishes the
	 * bookkeeping so the operator screen stops showing a ban that already ran
	 * out, and the owner gets one closing message.
	 *
	 * Two paths reach a row here, and they must not be conflated: either this
	 * call wins the `active → expired` transition, or a blocked upload attempt
	 * already flipped it through `Sanctions::blocking()`. In the second case the
	 * row is no longer `active`, so it still needs its audit and its notice —
	 * which is why the candidate list is keyed on `expired_notified_at` rather
	 * than on state alone.
	 *
	 * @param int $limit Row cap.
	 * @return int Rows that got a closing notice from this call.
	 */
	public function expire_due( int $limit = 50 ): int {
		$closed = 0;

		foreach ( $this->repo->due_to_expire( $limit ) as $row ) {
			$id = (int) $row->id;

			// Idempotent: flips active -> expired, and is a no-op when a reader
			// already did it while answering an upload attempt.
			$this->repo->expire( $id );

			$fresh = $this->repo->get( $id );

			if ( ! $fresh || Sanctions::STATE_EXPIRED !== (string) $fresh->state ) {
				// Revoked between the scan and the update: not ours to close.
				continue;
			}

			$notified = (string) $fresh->expired_notified_at;

			if ( '' !== $notified && Time::zero() !== $notified ) {
				continue;
			}

			/* One watermark for both halves of the closing: whoever gets past
			 * this point writes the audit row and queues the notice, then stamps
			 * the row. Splitting them into two watermarks would let a crash in
			 * between produce an audit entry with no message, or the reverse. */
			$this->audit( $fresh, 'expired' );
			$this->notify( $fresh, 'sanction_expired' );
			$this->repo->mark_notified( $id, 1 );

			++$closed;
		}

		return $closed;
	}

	/**
	 * Suggested rung for a repeat offender.
	 *
	 * A suggestion only: the operator still has to confirm it. Counting live
	 * decisions (not files, not events) keeps one bad batch from looking like
	 * three separate incidents.
	 *
	 * @param int    $owner_id    Owner.
	 * @param string $reason_code Takedown reason.
	 * @return array{type:string,days:int,reason:string}
	 */
	public function suggest( int $owner_id, string $reason_code ): array {
		$seen = $this->repo->count_since( $owner_id, self::LADDER_DAYS );

		if ( 'copyright' === $reason_code || 'illegal' === $reason_code ) {
			$seen = max( $seen, 2 );
		}

		if ( 0 === $seen ) {
			return array(
				'type'   => Sanctions::TYPE_WARN,
				'days'   => 0,
				'reason' => __( '首次违规：只发送通知，不限制账号。', 'sakura-imgopt' ),
			);
		}

		if ( 1 === $seen ) {
			return array(
				'type'   => Sanctions::TYPE_UPLOAD_BAN,
				'days'   => 7,
				'reason' => __( '30 天内第 2 次：建议禁止上传 7 天。', 'sakura-imgopt' ),
			);
		}

		return array(
			'type'   => Sanctions::TYPE_UPLOAD_BAN,
			'days'   => 30,
			'reason' => __( '30 天内第 3 次或性质严重：建议禁止上传 30 天。', 'sakura-imgopt' ),
		);
	}

	/**
	 * May the current user sanction this target?
	 *
	 * Three independent refusals, all of them about safety rather than policy:
	 * no self-sanction, no sanctioning an administrator (that is a lockout
	 * waiting to happen: two administrators can otherwise ban each other until
	 * nobody can get in), and the capability itself.
	 *
	 * @param int $target_id Target user.
	 * @return bool
	 */
	public function can_sanction( int $target_id ): bool {
		if ( $target_id <= 0 || $target_id === get_current_user_id() ) {
			return false;
		}

		if ( ! current_user_can( Capabilities::SANCTION_USERS ) ) {
			return false;
		}

		return ! user_can( $target_id, 'manage_options' );
	}

	/**
	 * Human sentence describing a row, used by the notice and the badge.
	 *
	 * @param object $row Sanction row.
	 * @return string
	 */
	public static function describe( object $row ): string {
		$type = (string) ( $row->type ?? '' );
		$ends = (string) ( $row->ends_at ?? '' );

		switch ( $type ) {
			case Sanctions::TYPE_WARN:
				$what = __( '警告', 'sakura-imgopt' );
				break;
			case Sanctions::TYPE_UPLOAD_BAN:
				$what = Sanctions::SCOPE_SITE === (string) ( $row->scope ?? '' )
					? __( '禁止上传（全站）', 'sakura-imgopt' )
					: __( '禁止上传（插件内）', 'sakura-imgopt' );
				break;
			case Sanctions::TYPE_LOGIN_BAN:
				$what = __( '禁止登录', 'sakura-imgopt' );
				break;
			case Sanctions::TYPE_FULL_BAN:
				$what = __( '禁止登录与上传', 'sakura-imgopt' );
				break;
			default:
				$what = __( '处罚', 'sakura-imgopt' );
		}

		/* Same reason as badge(): a warning has no window, so "(永久)" would be
		 * wrong — it reads as "permanently warned", which is not a thing. */
		if ( Sanctions::TYPE_WARN === $type ) {
			return $what;
		}

		if ( '' === $ends || Time::zero() === $ends ) {
			return sprintf(
				/* translators: %s: sanction type */
				__( '%s（永久）', 'sakura-imgopt' ),
				$what
			);
		}

		return sprintf(
			/* translators: 1: sanction type, 2: end time */
			__( '%1$s（至 %2$s）', 'sakura-imgopt' ),
			$what,
			$ends
		);
	}

	/**
	 * Badge text for the users tab: type plus what is left.
	 *
	 * @param object $row Sanction row.
	 * @return string
	 */
	public static function badge( object $row ): string {
		$type = (string) ( $row->type ?? '' );

		switch ( $type ) {
			case Sanctions::TYPE_WARN:
				$short = __( '已警告', 'sakura-imgopt' );
				break;
			case Sanctions::TYPE_LOGIN_BAN:
				$short = __( '禁登录', 'sakura-imgopt' );
				break;
			case Sanctions::TYPE_FULL_BAN:
				$short = __( '禁登录+上传', 'sakura-imgopt' );
				break;
			default:
				$short = __( '禁上传', 'sakura-imgopt' );
		}

		$ends = (string) ( $row->ends_at ?? '' );

		/* A warning is a point event, not a window. It is stored with no end
		 * date precisely because it enforces nothing, so rendering that as
		 * "permanent" would tell the operator the opposite of the truth. */
		if ( Sanctions::TYPE_WARN === $type ) {
			return $short;
		}

		if ( '' === $ends || Time::zero() === $ends ) {
			return $short . ' · ' . __( '永久', 'sakura-imgopt' );
		}

		$left = strtotime( $ends . ' UTC' ) - time();

		if ( $left <= 0 ) {
			return $short;
		}

		$days = (int) ceil( $left / 86400 );

		return $days >= 1
			/* translators: 1: sanction short label, 2: days left */
			? sprintf( __( '%1$s · 剩 %2$d 天', 'sakura-imgopt' ), $short, $days )
			/* translators: 1: sanction short label, 2: hours left */
			: sprintf( __( '%1$s · 剩 %2$d 小时', 'sakura-imgopt' ), $short, max( 1, (int) ceil( $left / 3600 ) ) );
	}

	/**
	 * One audit row per decision change.
	 *
	 * `safe_detail` carries the type and the reason code only: the note can name
	 * a complaint or a person, and the audit log is not the place for either.
	 *
	 * @param object $row    Sanction row.
	 * @param string $result applied|revoked|expired.
	 * @return void
	 */
	private function audit( object $row, string $result ): void {
		$id = (int) $row->id;

		$this->events->log(
			array(
				'actor_id'     => (int) ( $row->actor_id ?? 0 ),
				'owner_id'     => (int) ( $row->owner_id ?? 0 ),
				'subject_id'   => (int) ( $row->owner_id ?? 0 ),
				'operation_id' => '' !== (string) ( $row->operation_id ?? '' ) ? (string) $row->operation_id : 'sanction:' . $id,
				'action'       => 'sanction',
				'result'       => $result,
				'safe_detail'  => 'plugin:sanction|' . (string) ( $row->type ?? '' ) . '|' . (string) ( $row->reason_code ?? '' ),
			)
		);
	}

	/**
	 * Queue one owner-facing message for this decision.
	 *
	 * The outbox dedupes on the key, so a retried request cannot send the same
	 * decision twice; the row also records when it was queued so the operator
	 * screen can tell "sent" from "waiting for a channel" without reading the
	 * outbox.
	 *
	 * @param object $row    Sanction row.
	 * @param string $status Outbox status.
	 * @return void
	 */
	private function notify( object $row, string $status = 'sanctioned' ): void {
		$id     = (int) $row->id;
		$owner  = (int) $row->owner_id;
		$detail = self::describe( $row );

		if ( $owner <= 0 ) {
			return;
		}

		$body = $this->notice_body( $status, $row, $detail );

		$this->outbox->enqueue(
			$owner,
			0,
			$status,
			array(
				'count'  => 0,
				'reason' => $detail,
				'title'  => self::notice_title( $status, (string) $row->type ),
				'body'   => $body,
			),
			'sanction:' . $id . ':' . $status
		);

		$this->repo->mark_notified( $id );

		if ( $this->notifier ) {
			$this->notifier->deliver_due( 5 );
		}
	}

	/**
	 * Body copy for one decision notice.
	 *
	 * @param string $status Outbox status.
	 * @param object $row    Sanction row.
	 * @param string $detail Description of the sanction.
	 * @return string
	 */
	private function notice_body( string $status, object $row, string $detail ): string {
		if ( 'sanction_revoked' === $status ) {
			return sprintf(
				/* translators: %s: sanction description */
				__( '管理员已撤销对您账号的处罚：%s。此前下架的文件不会恢复。', 'sakura-imgopt' ),
				$detail
			);
		}

		if ( 'sanction_expired' === $status ) {
			return __( '您账号的处罚已到期，上传功能已恢复。', 'sakura-imgopt' );
		}

		if ( Sanctions::TYPE_WARN === (string) $row->type ) {
			return __( '管理员已对您的上传内容作出处理并发出警告，请确认上传的文件符合站点规则。', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: %s: sanction description */
			__( '管理员已对您的账号作出处罚：%s。处罚期间不能上传新文件，已有文件仍可自行删除。', 'sakura-imgopt' ),
			$detail
		);
	}

	/**
	 * Title for the in-app message.
	 *
	 * @param string $status Outbox status.
	 * @param string $type   Sanction type.
	 * @return string
	 */
	private static function notice_title( string $status, string $type ): string {
		if ( 'sanction_revoked' === $status ) {
			return __( '账号处罚已撤销', 'sakura-imgopt' );
		}

		if ( 'sanction_expired' === $status ) {
			return __( '账号处罚已到期', 'sakura-imgopt' );
		}

		return Sanctions::TYPE_WARN === $type
			? __( '上传内容处理提醒', 'sakura-imgopt' )
			: __( '账号已被限制上传', 'sakura-imgopt' );
	}
}
