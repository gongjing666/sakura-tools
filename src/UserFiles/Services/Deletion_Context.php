<?php
/**
 * Value object describing one call into the deletion kernel.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What the caller of {@see Deletion::delete()} wants done and who asked.
 *
 * The context is immutable by convention: every property is public and set
 * once in the constructor, and the kernel never writes to it. Keeping the
 * decision inputs in one value object is what lets the media library, the
 * approved-request executor, the sync centre, the takedown action and WP-CLI
 * share exactly one implementation without a growing parameter list.
 */
final class Deletion_Context {

	/** Remove the attachment, its files, the ledger row and (optionally) the cloud objects. */
	public const MODE_FULL = 'full';

	/** Remove cloud objects only; the attachment and its ledger row stay. */
	public const MODE_REMOTE_ONLY = 'remote_only';

	/** A moderation takedown: full deletion plus an owner notification. */
	public const MODE_TAKEDOWN = 'takedown';

	/**
	 * One of the MODE_* constants.
	 *
	 * @var string
	 */
	public string $mode;

	/**
	 * Acting user id; 0 means a system path (cron, queue worker, WP-CLI).
	 *
	 * @var int
	 */
	public int $actor_id;

	/**
	 * One of the Deletion::REASON_* constants.
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Whether the kernel removes the cloud objects itself, synchronously.
	 *
	 * Media-library and takedown deletes leave this false: the `delete_attachment`
	 * observer already queues the remote removal, which is asynchronous on
	 * purpose. The sync centre sets it true because the operator is watching.
	 *
	 * @var bool
	 */
	public bool $sync_remote;

	/**
	 * Whether the file owner must be told the file is gone.
	 *
	 * @var bool
	 */
	public bool $notify_owner;

	/**
	 * Audit origin; empty means "derive it from the mode and the actor".
	 *
	 * @var string
	 */
	public string $via;

	/**
	 * Whether in-flight request items must be left alone.
	 *
	 * The approved-request executor already owns the item rows it is working
	 * on: voiding them from inside the kernel would turn a successful purge
	 * into a `failed / attachment_gone` item. Every other caller leaves this
	 * false so a delete from the media library or the sync centre voids the
	 * request the user is waiting on.
	 *
	 * @var bool
	 */
	public bool $keep_request;

	/**
	 * Free-text note stored in the audit event (`via:reason|note`).
	 *
	 * @var string
	 */
	public string $note;

	/**
	 * Constructor.
	 *
	 * @param string $mode         One of the MODE_* constants.
	 * @param int    $actor_id     Acting user id (0 for system paths).
	 * @param string $reason       One of the Deletion::REASON_* constants.
	 * @param bool   $sync_remote  Remove cloud objects synchronously.
	 * @param bool   $notify_owner Notify the owner after the delete.
	 * @param string $via          Audit origin, empty to derive.
	 * @param bool   $keep_request Keep the caller's request items untouched.
	 * @param string $note         Audit note.
	 */
	public function __construct( string $mode = self::MODE_FULL, int $actor_id = 0, string $reason = Deletion::REASON_OPERATOR, bool $sync_remote = false, bool $notify_owner = false, string $via = '', bool $keep_request = false, string $note = '' ) {
		$this->mode         = in_array( $mode, array( self::MODE_FULL, self::MODE_REMOTE_ONLY, self::MODE_TAKEDOWN ), true ) ? $mode : self::MODE_FULL;
		$this->actor_id     = max( 0, $actor_id );
		$this->reason       = in_array( $reason, array( Deletion::REASON_OPERATOR, Deletion::REASON_REQUEST, Deletion::REASON_MODERATION, Deletion::REASON_CLEANUP ), true ) ? $reason : Deletion::REASON_OPERATOR;
		$this->sync_remote  = $sync_remote;
		$this->notify_owner = $notify_owner || self::MODE_TAKEDOWN === $this->mode;
		$this->via          = in_array( $via, array( Deletion::VIA_WORDPRESS, Deletion::VIA_PLUGIN, Deletion::VIA_EXECUTOR, Deletion::VIA_CLI ), true ) ? $via : '';
		$this->keep_request = $keep_request;
		$this->note         = $note;
	}

	/**
	 * Whether this context removes the attachment row and the local files.
	 *
	 * @return bool
	 */
	public function removes_attachment(): bool {
		return self::MODE_REMOTE_ONLY !== $this->mode;
	}

	/**
	 * Audit origin for this context.
	 *
	 * A system path is only `cli` when the caller explicitly said so; the queue
	 * worker and cron run as `executor`/`plugin` because they act on behalf of
	 * an approved request or a scheduled job, not on behalf of a shell.
	 *
	 * @return string
	 */
	public function via(): string {
		if ( '' !== $this->via ) {
			return $this->via;
		}

		if ( self::MODE_TAKEDOWN === $this->mode ) {
			return Deletion::VIA_PLUGIN;
		}

		return $this->actor_id > 0 ? Deletion::VIA_PLUGIN : Deletion::VIA_EXECUTOR;
	}
}
