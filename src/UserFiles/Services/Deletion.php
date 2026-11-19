<?php
/**
 * The single settlement point for deleted files.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Db_Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Turns "this attachment is gone" into exactly one ledger settlement.
 *
 * WordPress owns the deletion itself: core removes the attachment row, its
 * postmeta and its files on its own schedule, and third-party code relies on
 * its hooks. This class does not replace that; it watches the outcome and
 * settles the ledger from the single place that may move a charge.
 *
 * The mutex is a conditional UPDATE on the ledger row, not an in-process flag:
 * the media library, the approved-request executor, a script and WP-CLI running
 * at the same time all funnel through {@see settle()} and only the writer that
 * moves the row to `purged` releases the bytes.
 */
final class Deletion {

	/** The delete came from WordPress core. */
	public const VIA_WORDPRESS = 'wordpress';

	/** The delete came from one of the plugin's own entry points. */
	public const VIA_PLUGIN = 'plugin';

	/** The plugin's approved-request executor removed the file. */
	public const VIA_EXECUTOR = 'executor';

	/** A human at a shell removed the file. */
	public const VIA_CLI = 'cli';

	/** An administrator deleted the file on their own decision. */
	public const REASON_OPERATOR = 'operator';

	/** A user's approved delete request removed the file. */
	public const REASON_REQUEST = 'request';

	/** A moderation takedown removed the file. */
	public const REASON_MODERATION = 'moderation';

	/** Routine cleanup (orphans, leftovers after a bucket switch). */
	public const REASON_CLEANUP = 'cleanup';

	private Files $files;

	private ?Quota $quota;

	private ?Events $events;

	/**
	 * Constructor: assign only.
	 *
	 * @param Files       $files  Ledger repository.
	 * @param Quota|null  $quota  Quota service.
	 * @param Events|null $events Audit log.
	 */
	public function __construct( Files $files, ?Quota $quota = null, ?Events $events = null ) {
		$this->files  = $files;
		$this->quota  = $quota;
		$this->events = $events;
	}

	/**
	 * `deleted_post` observer: settle whatever the delete just removed.
	 *
	 * WordPress fires this after the attachment row is gone, so the ledger is
	 * the only remaining evidence that the file existed and what it charged.
	 * A row that is already `purged` means someone else won the race.
	 *
	 * @param int           $post_id Attachment id.
	 * @param \WP_Post|null $post    Deleted post object.
	 * @return void
	 */
	public function on_attachment_deleted( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return;
		}

		if ( $post instanceof \WP_Post && 'attachment' !== $post->post_type ) {
			return;
		}

		$file = $this->files->get_by_attachment( $post_id );

		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return;
		}

		$this->settle( (int) $file->id, self::VIA_WORDPRESS, (int) get_current_user_id(), self::REASON_OPERATOR );
	}

	/**
	 * Release a file's charge exactly once.
	 *
	 * Order matters: artifacts, the conditional lifecycle write and the quota
	 * movement are one transaction, so a failure leaves the row untouched and
	 * retryable instead of half-settled. The winner is decided by the
	 * conditional update, which is why the byte movement can never run twice.
	 *
	 * @param int    $file_id  Ledger row id.
	 * @param string $via      Origin, one of the VIA_* constants.
	 * @param int    $actor_id Acting user id (0 for cron or CLI).
	 * @param string $reason   operator|request|moderation|cleanup.
	 * @return bool True when this call was the one that settled the row.
	 */
	public function settle( int $file_id, string $via, int $actor_id = 0, string $reason = self::REASON_OPERATOR ): bool {
		global $wpdb;

		if ( $file_id <= 0 ) {
			return false;
		}

		$file = $this->files->get_by_id( $file_id );

		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return false;
		}

		$owner_id   = (int) $file->owner_id;
		$charged    = (int) $file->charged_bytes;
		$subject_id = (int) $file->attachment_id;

		Db_Transaction::begin();

		$wpdb->delete( Schema::table( 'artifacts' ), array( 'file_id' => $file_id ), array( '%d' ) );

		// The conditional update is the only mutex: losers get false here and
		// never reach the quota, so a double charge is impossible even when two
		// requests settle the same row concurrently.
		if ( ! $this->files->settle_purged( $file_id ) ) {
			Db_Transaction::rollback();

			return false;
		}

		$charged_ok = true;

		if ( $this->quota && $charged > 0 ) {
			$charged_ok = $this->quota->adjust_used( $owner_id, -1 * $charged );
		}

		if ( ! $charged_ok ) {
			Db_Transaction::rollback();

			$this->record( $subject_id, $owner_id, $actor_id, $via, $reason, 'failed' );

			return false;
		}

		Db_Transaction::commit();

		$this->record( $subject_id, $owner_id, $actor_id, $via, $reason, 'purged' );

		return true;
	}

	/**
	 *Write one audit row for a settlement.
	 *
	 * Paths and object keys stay out of the event: `safe_detail` carries the
	 * origin and the reason only, matching the repository's existing contract.
	 *
	 * @param int    $subject_id Attachment id.
	 * @param int    $owner_id   File owner.
	 * @param int    $actor_id   Acting user id.
	 * @param string $via        Origin.
	 * @param string $reason     Reason.
	 * @param string $result     purged|failed.
	 * @return void
	 */
	private function record( int $subject_id, int $owner_id, int $actor_id, string $via, string $reason, string $result ): void {
		if ( ! $this->events ) {
			return;
		}

		$this->events->log(
			array(
				'actor_id'    => $actor_id,
				'owner_id'    => $owner_id,
				'subject_id'  => $subject_id,
				'action'      => 'delete',
				'result'      => $result,
				'safe_detail' => $via . ':' . $reason,
			)
		);
	}
}
