<?php
/**
 * Violation takedown: one decision, one audit line per file, one verdict per uploader.
 *
 * This is the single implementation behind every takedown entry point. The media
 * library's bulk action and the media manager page both call {@see self::run()};
 * neither owns the rules. Two entry points with two copies of the loop is how
 * "delete local and cloud" and "notify the owner" drift apart, and the drift is
 * invisible until someone audits one of them.
 *
 * What this class is *not*: it is not the deletion kernel. Removal goes through
 * {@see Deletion} and keeps the same ordering, the same cloud handling and the
 * same settlement observer. This class only decides who owned what, writes the
 * audit trail and turns the outcome into per-owner messages and verdicts.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Notifications;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Applies one takedown decision to a set of attachments.
 */
final class Takedown {

	/**
	 * Audit action written for a removal that had no ledger row to settle.
	 */
	public const ACTION = 'delete';

	/**
	 * Shape of the verdict a caller may request alongside a takedown.
	 */
	public const DEFAULT_TYPE = Sanctions::TYPE_WARN;

	/**
	 * Longest window a takedown may impose.
	 *
	 * A takedown dialog knows attachment IDs but not who owns them, so it cannot
	 * ask for the typed username a permanent ban requires. Permanent verdicts are
	 * issued from the users screen, where the target is known before the button
	 * is pressed.
	 */
	public const MAX_DAYS = 30;

	private Deletion $deletion;

	/**
	 * Audit sink; absent in stripped-down tests.
	 *
	 * @var Events|null
	 */
	private ?Events $events;

	/**
	 * Outbox; absent in stripped-down tests.
	 *
	 * @var Notifications|null
	 */
	private ?Notifications $outbox;

	/**
	 * Verdict service; absent when sanctions are not wired.
	 *
	 * @var Sanction_Service|null
	 */
	private ?Sanction_Service $sanctions;

	/**
	 * Constructor.
	 *
	 * @param Deletion               $deletion  Deletion kernel.
	 * @param Events|null            $events    Audit sink.
	 * @param Notifications|null     $outbox    Notification outbox.
	 * @param Sanction_Service|null  $sanctions Verdict service.
	 */
	public function __construct(
		Deletion $deletion,
		?Events $events = null,
		?Notifications $outbox = null,
		?Sanction_Service $sanctions = null
	) {
		$this->deletion  = $deletion;
		$this->events    = $events;
		$this->outbox    = $outbox;
		$this->sanctions = $sanctions;
	}

	/**
	 * Moderation reasons offered by a takedown.
	 *
	 * A closed list keeps audit rows comparable: the key is stored, the label is
	 * rendered, and free text only ever lives in the note. A method rather than a
	 * constant because the labels are translated.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return array(
			'copyright' => __( '侵犯版权', 'sakura-imgopt' ),
			'illegal'   => __( '违法违规内容', 'sakura-imgopt' ),
			'abuse'     => __( '辱骂或骚扰', 'sakura-imgopt' ),
			'privacy'   => __( '泄露他人隐私', 'sakura-imgopt' ),
			'policy'    => __( '违反站点规则', 'sakura-imgopt' ),
			'other'     => __( '其他', 'sakura-imgopt' ),
		);
	}

	/**
	 * Whether a reason key is one of the offered reasons.
	 *
	 * @param string $reason Reason key.
	 * @return bool
	 */
	public static function is_reason( string $reason ): bool {
		return isset( self::reasons()[ $reason ] );
	}

	/**
	 * Everything the caller asked for, normalised to what may actually happen.
	 *
	 * Pure: no IO, no globals. Kept separate so the clamping rules can be tested
	 * without a database, and so both entry points agree on them by construction.
	 *
	 * @param array<string, mixed> $args Raw arguments.
	 * @return array{reason:string,note:string,punish:bool,type:string,days:int,actor_id:int}
	 */
	public static function normalise( array $args ): array {
		$reason = isset( $args['reason'] ) && is_scalar( $args['reason'] ) ? sanitize_key( (string) $args['reason'] ) : '';

		if ( ! self::is_reason( $reason ) ) {
			$reason = 'other';
		}

		$note = isset( $args['note'] ) && is_scalar( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : '';
		$note = mb_substr( trim( $note ), 0, 120 );

		$punish = ! empty( $args['punish'] );
		$type   = isset( $args['type'] ) && is_scalar( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';

		if ( ! in_array( $type, array( Sanctions::TYPE_WARN, Sanctions::TYPE_UPLOAD_BAN ), true ) ) {
			$type = self::DEFAULT_TYPE;
		}

		$days = isset( $args['days'] ) ? (int) $args['days'] : 0;

		/*
		 * `days = 0` means "the caller did not send a window", never "forever":
		 * in Sanction_Service a zero-day window is exactly what a permanent row
		 * looks like. Clamping from below keeps a hand-crafted parameter from
		 * turning one bulk action into an endless ban.
		 */
		if ( Sanctions::TYPE_WARN === $type ) {
			$days = 0;
		} else {
			$days = max( 1, min( self::MAX_DAYS, $days ) );
		}

		$actor = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : (int) get_current_user_id();

		return array(
			'reason'   => $reason,
			'note'     => $note,
			'punish'   => $punish,
			'type'     => $type,
			'days'     => $days,
			'actor_id' => max( 0, $actor ),
		);
	}

	/**
	 * Human-readable audit detail for a reason plus note.
	 *
	 * @param string $reason Reason key.
	 * @param string $note   Optional note.
	 * @return string
	 */
	public static function detail( string $reason, string $note ): string {
		$reasons = self::reasons();
		$label   = $reasons[ $reason ] ?? $reasons['other'];

		return '' !== $note ? $label . '：' . $note : $label;
	}

	/**
	 * Name for one batch.
	 *
	 * The same string goes on every delete event, every sanction event and every
	 * outbox key, so one query can reconstruct "this decision removed these files
	 * and punished these people". Deliberately not `batch_id`: that column belongs
	 * to review-execution batches, and the two kinds of batch must not share an
	 * integer field.
	 *
	 * @param int $actor_id Acting user.
	 * @return string
	 */
	public static function operation_id( int $actor_id ): string {
		return 'takedown:' . gmdate( 'YmdHis' ) . '-' . max( 0, $actor_id );
	}

	/**
	 * Remove the attachments and record the decision.
	 *
	 * @param array<int, int>      $attachment_ids Attachment IDs.
	 * @param array<string, mixed> $args           See {@see self::normalise()}; an
	 *                                             `operation_id` may be supplied to
	 *                                             group several calls into one batch.
	 * @return array{done:int,partial:int,skipped:int,sanctioned:int,operation_id:string,denied:string,owners:array<int,int>}
	 */
	public function run( array $attachment_ids, array $args = array() ): array {
		$opts = self::normalise( $args );

		$operation_id = isset( $args['operation_id'] ) && is_scalar( $args['operation_id'] )
			? substr( trim( (string) $args['operation_id'] ), 0, 64 )
			: '';

		if ( '' === $operation_id ) {
			$operation_id = self::operation_id( $opts['actor_id'] );
		}

		$detail = self::detail( $opts['reason'], $opts['note'] );
		$ids    = $this->normalise_ids( $attachment_ids );

		$out = array(
			'done'         => 0,
			'partial'      => 0,
			'skipped'      => 0,
			'sanctioned'   => 0,
			'operation_id' => $operation_id,
			'denied'       => '',
			'owners'       => array(),
		);

		if ( ! $ids ) {
			return $out;
		}

		$owners = $this->owners_of( $ids );
		$targets = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
				$out['skipped']++;
				continue;
			}

			/* The kernel answers in_progress for a row another executor already
			 * owns; asking just to be told that is noise. */
			$lifecycle = (string) ( $owners[ $id ]['lifecycle'] ?? '' );

			if ( Files::LIFECYCLE_DELETING === $lifecycle ) {
				$out['skipped']++;
				continue;
			}

			$targets[] = $id;
		}

		if ( ! $targets ) {
			return $out;
		}

		$ctx = new Deletion_Context(
			Deletion_Context::MODE_TAKEDOWN,
			$opts['actor_id'],
			Deletion::REASON_MODERATION,
			false,
			true,
			Deletion::VIA_PLUGIN,
			false,
			$detail,
			$operation_id
		);

		$result  = $this->deletion->delete( $targets, $ctx );
		$by_owner = array();

		foreach ( $result->items() as $item ) {
			$status = (string) ( $item['status'] ?? '' );

			/* PARTIAL means the local file and the attachment row are gone but
			 * the bucket still holds the object: the kernel queued a retry, so the
			 * file is off the site already. Count it apart from DELETED so the
			 * result message can say the cloud copy is still pending. */
			if ( Deletion_Result::DELETED !== $status && Deletion_Result::PARTIAL !== $status ) {
				$out['skipped']++;
				continue;
			}

			$id   = (int) ( $item['attachment_id'] ?? 0 );
			$info = $owners[ $id ] ?? null;

			if ( Deletion_Result::PARTIAL === $status ) {
				$out['partial']++;
			} else {
				$out['done']++;
			}

			if ( null === $info ) {
				// No ledger row: the settlement observer writes nothing, so the
				// removal still needs its own audit line.
				$this->log( $id, $detail, $operation_id, $opts['actor_id'] );
				continue;
			}

			$owner_id = (int) $info['owner_id'];

			if ( $owner_id <= 0 ) {
				continue;
			}

			if ( ! isset( $by_owner[ $owner_id ] ) ) {
				$by_owner[ $owner_id ] = 0;
			}

			/* Only a completed removal counts as the incident: a file whose bucket
			 * copy is still pending was not taken down yet, and a skipped one was
			 * not touched at all. */
			if ( Deletion_Result::DELETED === $status ) {
				++$by_owner[ $owner_id ];
			}
		}

		$out['owners']     = $by_owner;
		$out['sanctioned'] = $this->settle( $by_owner, $detail, $opts, $operation_id );

		return $out;
	}

	/**
	 * Notify every affected owner once, then apply the opt-in verdict.
	 *
	 * One takedown batch can touch files owned by several people, so the outcome
	 * is collected per owner first and turned into one message (and at most one
	 * verdict) each. Enqueueing per attachment would put 200 rows in the outbox
	 * for one decision.
	 *
	 * @param array<int, int>                                              $by_owner     Owner id => completed removals.
	 * @param string                                                       $detail       Audit detail.
	 * @param array{reason:string,note:string,punish:bool,type:string,days:int,actor_id:int} $opts Normalised arguments.
	 * @param string                                                       $operation_id Batch name.
	 * @return int Number of verdicts applied.
	 */
	private function settle( array $by_owner, string $detail, array $opts, string $operation_id ): int {
		$applied = 0;

		foreach ( $by_owner as $owner_id => $count ) {
			$owner_id = (int) $owner_id;

			$this->notify( $owner_id, $count, $detail, $operation_id );

			/* Sanctioning is opt-in and never implied by a takedown: a copyright
			 * complaint can arrive before anyone knows who was right, and a
			 * deletion cannot be undone, so treating every takedown as a verdict
			 * would make the plugin the judge of the uploader's intent. */
			if ( ! $opts['punish'] || $count <= 0 || ! $this->sanctions ) {
				continue;
			}

			$result = $this->sanctions->apply(
				array(
					'owner_id'     => $owner_id,
					'actor_id'     => $opts['actor_id'],
					'type'         => $opts['type'],
					'scope'        => Sanctions::SCOPE_PLUGIN,
					'reason_code'  => $opts['reason'],
					'note'         => $opts['note'],
					'days'         => $opts['days'],
					'operation_id' => $operation_id,
					'source'       => 'manual',
				)
			);

			if ( ! is_wp_error( $result ) ) {
				++$applied;
			}
		}

		return $applied;
	}

	/**
	 * Queue one moderation message for an owner.
	 *
	 * @param int    $owner_id     Owner.
	 * @param int    $count        Completed removals.
	 * @param string $detail       Audit detail.
	 * @param string $operation_id Batch name.
	 * @return void
	 */
	private function notify( int $owner_id, int $count, string $detail, string $operation_id ): void {
		if ( ! $this->outbox || $owner_id <= 0 || $count <= 0 ) {
			return;
		}

		/* Keyed by the batch, not the attachment: re-running the same batch
		 * (double submit, back button) must not queue a second message. */
		$this->outbox->enqueue(
			$owner_id,
			0,
			'moderation',
			array(
				'count'  => $count,
				'reason' => $detail,
			),
			'takedown:' . get_current_blog_id() . ':' . $owner_id . ':' . substr( $operation_id, 9, 40 )
		);
	}

	/**
	 * Write an audit line for a removal the ledger could not settle.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $detail        Audit detail.
	 * @param string $operation_id  Batch name.
	 * @param int    $actor_id      Acting user.
	 * @return void
	 */
	private function log( int $attachment_id, string $detail, string $operation_id, int $actor_id ): void {
		if ( ! $this->events ) {
			return;
		}

		$this->events->log(
			array(
				'actor_id'     => $actor_id,
				'owner_id'     => (int) get_post_field( 'post_author', $attachment_id ),
				'subject_id'   => $attachment_id,
				'operation_id' => $operation_id,
				'action'       => self::ACTION,
				'result'       => 'purged',
				'safe_detail'  => Deletion::VIA_PLUGIN . ':' . Deletion::REASON_MODERATION . '|' . mb_substr( $detail, 0, 80 ),
			)
		);
	}

	/**
	 * Ledger owner and lifecycle for each attachment, in one query.
	 *
	 * Rows without a ledger entry are absent from the map, which is what tells
	 * {@see self::run()} to write its own audit line.
	 *
	 * @param array<int, int> $ids Attachment IDs.
	 * @return array<int, array{owner_id:int,lifecycle:string}>
	 */
	private function owners_of( array $ids ): array {
		global $wpdb;

		if ( ! $ids ) {
			return array();
		}

		$table        = Schema::table( 'files' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder list and arguments both derive from $ids.
		$sql = $wpdb->prepare( "SELECT attachment_id, owner_id, lifecycle FROM {$table} WHERE attachment_id IN ({$placeholders})", $ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$rows = $wpdb->get_results( $sql );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row->attachment_id ] = array(
				'owner_id'  => (int) $row->owner_id,
				'lifecycle' => (string) $row->lifecycle,
			);
		}

		return $out;
	}

	/**
	 * Normalise an ID list.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, int>
	 */
	private function normalise_ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = trim( $value );
			$value = '' === $value ? array() : explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $id ) {
			if ( is_array( $id ) || is_object( $id ) ) {
				continue;
			}

			$id = absint( $id );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}
}
