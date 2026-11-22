<?php
/**
 * The single settlement point for deleted files.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Db_Transaction;
use Nboke\UserFiles\Support\Time;
use WP_Error;

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

	/**
	 * Longest operator note kept in the audit row.
	 *
	 * `safe_detail` is 500 characters and also carries the origin and the
	 * reason; a note is a short human label, not free text storage.
	 */
	private const NOTE_LIMIT = 80;

	/**
	 * Per-attachment moderation context for the next delete.
	 *
	 * @var array<int, array{reason: string, note: string, actor_id: int, via: string, operation_id: string}>
	 */
	private static array $flagged = array();

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

		$flag   = self::take_flag( $post_id );
		$file   = $this->files->get_by_attachment( $post_id );
		$reason = is_array( $flag ) ? $flag['reason'] : self::REASON_OPERATOR;
		$note   = is_array( $flag ) ? $flag['note'] : '';
		$actor  = is_array( $flag ) ? $flag['actor_id'] : (int) get_current_user_id();
		$via    = is_array( $flag ) && '' !== $flag['via'] ? $flag['via'] : self::VIA_WORDPRESS;
		$op_id  = is_array( $flag ) ? (string) ( $flag['operation_id'] ?? '' ) : '';

		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return;
		}

		$this->settle( (int) $file->id, $via, $actor, $reason, $note, $op_id );
	}

	/**
	 * Remove one or more targets through the plugin's single deletion kernel.
	 *
	 * Every plugin-initiated delete funnels through here: the approved-request
	 * executor, the sync centre, the takedown action and WP-CLI. WordPress still
	 * performs the attachment removal itself; the kernel owns the ordering —
	 * claim, attachment, remote objects, settlement, audit — so those callers
	 * cannot drift apart again.
	 *
	 * @param array<int,int|string|array<string,mixed>> $targets Attachment ids, ledger row ids or object keys.
	 * @param Deletion_Context|null                     $ctx     Intent; defaults to a full operator delete.
	 * @return Deletion_Result Per-target outcome.
	 */
	public function delete( array $targets, ?Deletion_Context $ctx = null ): Deletion_Result {
		$ctx    = $ctx ?? new Deletion_Context();
		$result = new Deletion_Result();

		foreach ( $targets as $target ) {
			$this->delete_one( $target, $ctx, $result );
		}

		return $result;
	}

	/**
	 * Apply the kernel to one target and record the outcome.
	 *
	 * @param int|string|array<string,mixed> $target Target.
	 * @param Deletion_Context               $ctx    Intent.
	 * @param Deletion_Result                $result Result collector.
	 * @return void
	 */
	private function delete_one( $target, Deletion_Context $ctx, Deletion_Result $result ): void {
		$resolved   = $this->resolve_target( $target );
		$file       = $resolved['file'];
		$attachment = (int) $resolved['attachment_id'];
		$key        = (string) $resolved['key'];
		$label      = (string) $resolved['label'];
		$file_id    = $file ? (int) $file->id : 0;

		if ( $file && Files::LIFECYCLE_PURGED === (string) $file->lifecycle ) {
			$this->audit( $file, $ctx, 'missing' );
			$result->add( $label, Deletion_Result::MISSING, $file_id, $attachment );

			return;
		}

		if ( ! $file && 0 === $attachment && '' === $key ) {
			$result->add( $label, Deletion_Result::MISSING, 0, 0, 'unresolved' );

			return;
		}

		if ( ! $ctx->removes_attachment() ) {
			$this->delete_remote_only( $label, $file, $attachment, $key, $result );

			return;
		}

		if ( ! $this->may_delete( $ctx, $attachment ) ) {
			$result->add( $label, Deletion_Result::DENIED, $file_id, $attachment );

			return;
		}

		if ( $file && ! $this->claim( $file, $ctx, $label, $attachment, $result ) ) {
			return;
		}

		// The observer clears the recorded keys while deleting the attachment,
		// so the remote scope has to be frozen before the attachment step.
		$keys = $this->collect_keys( $attachment, $key );

		if ( $attachment > 0 && get_post( $attachment ) && ! $this->remove_attachment( $attachment, $ctx ) ) {
			$this->audit( $file, $ctx, 'partial' );
			$result->add( $label, Deletion_Result::PARTIAL, $file_id, $attachment, 'attachment_kept' );

			return;
		}

		$remote_error = '';

		if ( $ctx->sync_remote && $keys ) {
			$outcome = $this->delete_remote( $keys );

			if ( $outcome instanceof WP_Error ) {
				$remote_error = (string) $outcome->get_error_code();
				$this->enqueue_remote_retry( $attachment, $keys );
			}
		}

		if ( $file ) {
			$this->settle( $file_id, $ctx->via(), $ctx->actor_id, $ctx->reason, '', $ctx->operation_id );

			$fresh = $this->files->get_by_id( $file_id );

			if ( $fresh && Files::LIFECYCLE_PURGED !== (string) $fresh->lifecycle ) {
				$this->audit( $file, $ctx, 'partial' );
				$result->add( $label, Deletion_Result::PARTIAL, $file_id, $attachment, 'ledger' );

				return;
			}
		}

		if ( '' !== $remote_error ) {
			$this->audit( $file, $ctx, 'partial' );
			$result->add( $label, Deletion_Result::PARTIAL, $file_id, $attachment, $remote_error );

			return;
		}

		$result->add( $label, Deletion_Result::DELETED, $file_id, $attachment );
	}

	/**
	 * Remote-only branch: remove cloud objects, leave the attachment alone.
	 *
	 * Used by the sync centre for rows that have no ledger entry (or whose
	 * ledger row is already settled) but still occupy the bucket.
	 *
	 * @param string      $label      Target label for the result row.
	 * @param object|null $file       Ledger row, when one exists.
	 * @param int         $attachment Attachment id.
	 * @param string      $key        Object key from the target, when given.
	 * @param Deletion_Result $result Result collector.
	 * @return void
	 */
	private function delete_remote_only( string $label, ?object $file, int $attachment, string $key, Deletion_Result $result ): void {
		$keys    = $this->collect_keys( $attachment, $key );
		$file_id = $file ? (int) $file->id : 0;

		if ( ! $keys ) {
			$result->add( $label, Deletion_Result::MISSING, $file_id, $attachment );

			return;
		}

		$outcome = $this->delete_remote( $keys );

		if ( $outcome instanceof WP_Error ) {
			$result->add_error( $label, $file_id, $attachment, $outcome );

			return;
		}

		$result->add( $label, Deletion_Result::DELETED, $file_id, $attachment );
	}

	/**
	 * Claim the ledger row before the first irreversible step.
	 *
	 * @param object          $file       Ledger row.
	 * @param Deletion_Context $ctx       Intent.
	 * @param string          $label      Target label.
	 * @param int             $attachment Attachment id.
	 * @param Deletion_Result $result     Result collector.
	 * @return bool True when the caller may proceed.
	 */
	private function claim( object $file, Deletion_Context $ctx, string $label, int $attachment, Deletion_Result $result ): bool {
		$file_id = (int) $file->id;

		if ( Files::LIFECYCLE_DELETING === (string) $file->lifecycle ) {
			$result->add( $label, Deletion_Result::IN_PROGRESS, $file_id, $attachment );

			return false;
		}

		if ( $this->files->claim_deleting( $file_id ) ) {
			if ( ! $ctx->keep_request ) {
				$this->invalidate_pending( $file );
			}

			return true;
		}

		$fresh = $this->files->get_by_id( $file_id );

		if ( ! $fresh || Files::LIFECYCLE_PURGED === (string) $fresh->lifecycle ) {
			$result->add( $label, Deletion_Result::MISSING, $file_id, $attachment );
		} elseif ( Files::LIFECYCLE_DELETING === (string) $fresh->lifecycle ) {
			$result->add( $label, Deletion_Result::IN_PROGRESS, $file_id, $attachment );
		} else {
			$result->add( $label, Deletion_Result::DENIED, $file_id, $attachment, 'claim' );
		}

		return false;
	}

	/**
	 * Whether the acting user may remove this attachment outright.
	 *
	 * A system path (cron, queue worker, WP-CLI) has no logged-in user and is
	 * trusted: every such caller is plugin code that already passed its own
	 * authorization before queueing the work.
	 *
	 * @param Deletion_Context $ctx           Intent.
	 * @param int              $attachment_id Attachment id.
	 * @return bool
	 */
	private function may_delete( Deletion_Context $ctx, int $attachment_id ): bool {
		if ( $ctx->actor_id <= 0 ) {
			return true;
		}

		if ( ! user_can( $ctx->actor_id, Capabilities::DELETE_FILES ) ) {
			return false;
		}

		return $attachment_id <= 0 || user_can( $ctx->actor_id, 'delete_post', $attachment_id );
	}

	/**
	 * Call `wp_delete_attachment()` on the kernel's behalf.
	 *
	 * The gate must be told this particular delete is authorized, otherwise it
	 * refuses anything without a logged-in administrator (cron, WP-CLI, a queue
	 * worker) and the row sticks in `deleting` forever.
	 *
	 * @param int              $attachment_id Attachment id.
	 * @param Deletion_Context $ctx           Intent.
	 * @return bool True when the attachment is gone.
	 */
	private function remove_attachment( int $attachment_id, Deletion_Context $ctx ): bool {
		self::flag( array( $attachment_id ), $ctx->reason, $ctx->note, $ctx->actor_id, $ctx->via(), $ctx->operation_id );

		$allow = static function () {
			return true;
		};

		/* The synchronous modes delete the cloud objects themselves and verify
		 * the result, so the storage observer must not queue the same keys. */
		$skip_queue = static function () {
			return true;
		};

		add_filter( 'sufm_allow_attachment_delete', $allow, 99 );

		if ( $ctx->sync_remote ) {
			add_filter( 'sufm_skip_remote_enqueue', $skip_queue, 99 );
		}

		wp_delete_attachment( $attachment_id, true );

		remove_filter( 'sufm_allow_attachment_delete', $allow, 99 );

		if ( $ctx->sync_remote ) {
			remove_filter( 'sufm_skip_remote_enqueue', $skip_queue, 99 );
		}

		if ( get_post( $attachment_id ) ) {
			self::forget( $attachment_id );

			return false;
		}

		return true;
	}

	/**
	 * Void the pending request item that points at a row deleted from outside.
	 *
	 * The request itself is left to the executor: it owns the lease, the stop
	 * flag and the batch accounting. Marking the item as `attachment_gone` is
	 * what removes the "幽灵条目" a user would otherwise keep seeing.
	 *
	 * @param object $file Ledger row.
	 * @return void
	 */
	private function invalidate_pending( object $file ): void {
		global $wpdb;

		$request_id = (int) ( $file->pending_request_id ?? 0 );
		$file_id    = (int) $file->id;

		if ( $request_id <= 0 || $file_id <= 0 ) {
			return;
		}

		$table = Schema::table( 'delete_request_items' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, result=%s, updated_at=%s WHERE request_id=%d AND object_type='file' AND object_id=%d AND state IN ('pending','queued','executing','deleting')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'failed',
				'attachment_gone',
				Time::now(),
				$request_id,
				$file_id
			)
		);
	}

	/**
	 * Freeze the object keys a delete has to remove.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $key           Extra key from the target, when given.
	 * @return array<int,string>
	 */
	private function collect_keys( int $attachment_id, string $key ): array {
		$keys = array();

		if ( '' !== $key ) {
			$keys[] = $key;
		}

		if ( $attachment_id > 0 ) {
			$stored = get_post_meta( $attachment_id, '_sakura_imgopt_s3_objects', true );

			foreach ( is_array( $stored ) ? $stored : array() as $stored_key ) {
				if ( is_string( $stored_key ) && '' !== $stored_key ) {
					$keys[] = $stored_key;
				}
			}
		}

		$keys = array_values( array_unique( $keys ) );

		if ( $keys || $attachment_id <= 0 ) {
			return $keys;
		}

		$adapter = $this->adapter();

		if ( ! $adapter ) {
			return $keys;
		}

		foreach ( $adapter->attachment_files( $attachment_id ) as $path ) {
			$derived = $adapter->key_from_path( (string) $path );

			if ( is_string( $derived ) && '' !== $derived ) {
				$keys[] = $derived;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Delete cloud objects and prove they are gone.
	 *
	 * @param array<int,string> $keys Object keys.
	 * @return true|\WP_Error
	 */
	private function delete_remote( array $keys ) {
		$adapter = $this->adapter();

		if ( ! $adapter ) {
			return new WP_Error( 'sufm_remote_unavailable', __( '云端删除能力不可用。', 'sakura-imgopt' ) );
		}

		$deleted = $adapter->delete_keys( $keys );

		if ( true !== $deleted ) {
			return is_wp_error( $deleted ) ? $deleted : new WP_Error( 'sufm_remote_unverified', __( '云端对象删除失败。', 'sakura-imgopt' ) );
		}

		$remaining = $adapter->objects_exist( $keys );

		if ( is_wp_error( $remaining ) ) {
			return $remaining;
		}

		if ( $remaining ) {
			return new WP_Error( 'sufm_remote_unverified', __( '云端仍报告对象存在，已交给后台队列重试。', 'sakura-imgopt' ) );
		}

		return true;
	}

	/**
	 * Hand a failed remote delete to the S3 retry queue.
	 *
	 * @param int               $attachment_id Attachment id.
	 * @param array<int,string> $keys          Object keys.
	 * @return void
	 */
	private function enqueue_remote_retry( int $attachment_id, array $keys ): void {
		if ( ! $keys || ! class_exists( \Sakura\ImageOptimizer\S3\Delete_Jobs::class ) || ! class_exists( \Sakura\ImageOptimizer\S3\Options::class ) ) {
			return;
		}

		$options = new \Sakura\ImageOptimizer\S3\Options();

		if ( ! $options->is_enabled() ) {
			return;
		}

		$profile_id  = (string) $options->active_profile_id();
		$target_hash = '' === $profile_id ? '' : \Sakura\ImageOptimizer\S3\Delete_Jobs::target_hash( (array) $options->profile_options( $profile_id ) );

		( new \Sakura\ImageOptimizer\S3\Delete_Jobs() )->enqueue( max( 0, $attachment_id ), $keys, 0, $profile_id, $target_hash );
	}

	/**
	 * Storage adapter, when the S3 module is present.
	 *
	 * The user file space works on a site without S3; the dependency stays
	 * one-way and optional, exactly like the sync actions' adoption hook.
	 *
	 * @return object|null
	 */
	private function adapter(): ?object {
		if ( ! class_exists( \Sakura\ImageOptimizer\S3\Storage_Adapter::class ) ) {
			return null;
		}

		return new \Sakura\ImageOptimizer\S3\Storage_Adapter();
	}

	/**
	 * Map an object key back to its attachment when possible.
	 *
	 * @param string $key Object key.
	 * @return int
	 */
	private function attachment_id_for_key( string $key ): int {
		$adapter = $this->adapter();

		return $adapter ? (int) $adapter->attachment_id_for_key( $key ) : 0;
	}

	/**
	 * Turn a caller-supplied target into a ledger row, an attachment and a key.
	 *
	 * Numbers are tried as attachment ids first and as ledger row ids second;
	 * strings are object keys. An explicit array makes the intent unambiguous.
	 *
	 * @param int|string|array<string,mixed> $target Target.
	 * @return array{file: object|null, attachment_id: int, key: string, label: string}
	 */
	private function resolve_target( $target ): array {
		$file       = null;
		$attachment = 0;
		$key        = '';
		$label      = '';

		if ( is_array( $target ) ) {
			$attachment = (int) ( $target['attachment_id'] ?? 0 );
			$file_id    = (int) ( $target['file_id'] ?? 0 );
			$key        = trim( (string) ( $target['key'] ?? '' ) );

			if ( $file_id > 0 ) {
				$file = $this->files->get_by_id( $file_id );
			}

			$label = '' !== $key ? $key : (string) ( $attachment > 0 ? $attachment : $file_id );
		} elseif ( is_int( $target ) || ( is_string( $target ) && '' !== $target && ctype_digit( $target ) ) ) {
			$value = (int) $target;
			$label = (string) $value;
			$file  = $this->files->get_by_attachment( $value );

			if ( ! $file ) {
				$file = $this->files->get_by_id( $value );
			}

			$attachment = $file ? (int) $file->attachment_id : $value;
		} else {
			$key = trim( (string) $target );
			if ( '' !== $key ) {
				$label = $key;
			}
		}

		if ( ! $file && $attachment > 0 ) {
			$file = $this->files->get_by_attachment( $attachment );
		}

		if ( $file ) {
			$attachment = (int) $file->attachment_id;
		} elseif ( 0 === $attachment && '' !== $key ) {
			$attachment = $this->attachment_id_for_key( $key );

			if ( $attachment > 0 ) {
				$file = $this->files->get_by_attachment( $attachment );
			}
		}

		return array(
			'file'          => $file,
			'attachment_id' => max( 0, $attachment ),
			'key'           => $key,
			'label'         => $label,
		);
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
	 * @param string $note     Short operator/moderator note for the audit row.
	 * @param string $operation_id Batch reference copied onto the audit row.
	 * @return bool True when this call was the one that settled the row.
	 */
	public function settle( int $file_id, string $via, int $actor_id = 0, string $reason = self::REASON_OPERATOR, string $note = '', string $operation_id = '' ): bool {
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

			$this->record( $subject_id, $owner_id, $actor_id, $via, $reason, 'failed', $note, $operation_id );

			return false;
		}

		Db_Transaction::commit();

		$this->record( $subject_id, $owner_id, $actor_id, $via, $reason, 'purged', $note, $operation_id );

		return true;
	}

	/**
	 * Record an outcome the settlement step cannot see.
	 *
	 * `settle()` writes `purged` / `failed` because it owns the ledger write;
	 * the kernel's other endings (already gone, kept, partly done) still owe the
	 * audit trail a row so an operator can tell them apart from "nothing called".
	 *
	 * @param object|null       $file   Ledger row, when one exists.
	 * @param Deletion_Context  $ctx    Intent.
	 * @param string            $result `missing` or `partial`.
	 * @return void
	 */
	private function audit( ?object $file, Deletion_Context $ctx, string $result ): void {
		if ( ! $file ) {
			return;
		}

		$this->record(
			(int) $file->attachment_id,
			(int) $file->owner_id,
			$ctx->actor_id,
			$ctx->via(),
			$ctx->reason,
			$result,
			$ctx->note,
			$ctx->operation_id
		);
	}

	/**
	 * Remember why the next `wp_delete_attachment()` call removes this file.
	 *
	 * WordPress fires `deleted_post` without saying who asked or why, so an
	 * entry point that already knows the answer (a moderation takedown, a
	 * compliance removal) registers it here first. The observer consumes and
	 * clears the entry, which keeps the default `operator` reason for every
	 * other delete path and makes one flagged id affect exactly one delete.
	 *
	 * @param array<int, int> $attachment_ids Attachment ids about to be deleted.
	 * @param string          $reason         One of the REASON_* constants.
	 * @param string          $note           Short human note (already-sanitized label).
	 * @param int             $actor_id       Acting user id.
	 * @param string          $via            Audit origin; empty means the WordPress observer path.
	 * @param string          $operation_id   Batch reference for the audit row.
	 * @return void
	 */
	public static function flag( array $attachment_ids, string $reason, string $note = '', int $actor_id = 0, string $via = '', string $operation_id = '' ): void {
		$reason = sanitize_key( $reason );
		$note   = trim( sanitize_text_field( $note ) );

		if ( ! in_array( $reason, array( self::REASON_OPERATOR, self::REASON_REQUEST, self::REASON_MODERATION, self::REASON_CLEANUP ), true ) ) {
			$reason = self::REASON_MODERATION;
		}

		$via = in_array( $via, array( self::VIA_WORDPRESS, self::VIA_PLUGIN, self::VIA_EXECUTOR, self::VIA_CLI ), true ) ? $via : '';

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( $attachment_id <= 0 ) {
				continue;
			}

			self::$flagged[ $attachment_id ] = array(
				'reason'       => $reason,
				'note'         => $note,
				'actor_id'     => max( 0, $actor_id ),
				'via'          => $via,
				'operation_id' => substr( trim( $operation_id ), 0, 64 ),
			);
		}
	}

	/**
	 * Drop a flag that will not be used (the delete was refused).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return void
	 */
	public static function forget( int $attachment_id ): void {
		unset( self::$flagged[ (int) $attachment_id ] );
	}

	/**
	 * Read and clear the flag for one attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{reason: string, note: string, actor_id: int, via: string, operation_id: string}|null
	 */
	private static function take_flag( int $attachment_id ): ?array {
		$attachment_id = (int) $attachment_id;

		if ( ! isset( self::$flagged[ $attachment_id ] ) ) {
			return null;
		}

		$flag = self::$flagged[ $attachment_id ];
		unset( self::$flagged[ $attachment_id ] );

		return $flag;
	}

	/**
	 * Write one audit row for a settlement.
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
	 * @param string $note       Short human note, already sanitized.
	 * @param string $operation_id Batch reference, empty when this call is not part of one.
	 * @return void
	 */
	private function record( int $subject_id, int $owner_id, int $actor_id, string $via, string $reason, string $result, string $note = '', string $operation_id = '' ): void {
		if ( ! $this->events ) {
			return;
		}

		$this->events->log(
			array(
				'actor_id'    => $actor_id,
				'owner_id'    => $owner_id,
				'subject_id'   => $subject_id,
				'operation_id' => $operation_id,
				'action'       => 'delete',
				'result'       => $result,
				'safe_detail'  => $via . ':' . $reason . ( '' !== $note ? '|' . mb_substr( $note, 0, self::NOTE_LIMIT ) : '' ),
			)
		);
	}
}
