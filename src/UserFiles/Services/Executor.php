<?php
/**
 * Delete batch executor: the only code path that removes a user's files.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Support\Db_Transaction;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;
use Sakura\ImageOptimizer\Support\Text;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every irreversible step re-reads the approval and the stop flag first; a
 * revocation bumps `approval_revision`, so the fence in the job context stops
 * matching and remaining items never run.
 */
final class Executor {

	/**
	 * Seconds a worker lease stays valid.
	 */
	private const LEASE = 180;

	/**
	 * Files repository.
	 *
	 * @var Files
	 */
	private Files $files;

	/**
	 * Folders repository.
	 *
	 * @var Folders
	 */
	private Folders $folders;

	/**
	 * Requests repository.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Quota ledger.
	 *
	 * @var Quota
	 */
	private Quota $quota;

	/**
	 * Notifications.
	 *
	 * @var Notifier
	 */
	private Notifier $notifier;

	/**
	 * Wire dependencies.
	 *
	 * @param Files    $files    Files.
	 * @param Folders  $folders  Folders.
	 * @param Requests $requests Requests.
	 * @param Quota    $quota    Quota.
	 * @param Notifier $notifier Notifications.
	 */
	public function __construct( Files $files, Folders $folders, Requests $requests, Quota $quota, Notifier $notifier ) {
		$this->files    = $files;
		$this->folders  = $folders;
		$this->requests = $requests;
		$this->quota    = $quota;
		$this->notifier = $notifier;
	}

	/**
	 * Preview the exact scope an execution would remove.
	 *
	 * @param array<int, int> $request_ids Approved request ids.
	 * @return array<string, mixed>|WP_Error
	 */
	public function preview( array $request_ids ) {
		$request_ids = array_values( array_unique( array_filter( array_map( 'absint', $request_ids ) ) ) );
		sort( $request_ids );

		if ( ! $request_ids ) {
			return new WP_Error( 'sufm_empty', __( '请选择已批准的申请。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$parts    = array();
		$requests = array();
		$items    = 0;
		$bytes    = 0;

		foreach ( $request_ids as $id ) {
			$request = $this->requests->get( $id );

			if ( ! $request ) {
				return new WP_Error( 'sufm_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
			}

			if ( Requests::APPROVED !== (string) $request->status ) {
				return new WP_Error( 'sufm_state', __( '只有已批准且未执行的申请可以生成删除批次。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			if ( (int) $request->batch_id > 0 ) {
				return new WP_Error( 'sufm_batched', __( '部分申请已经生成过删除批次。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$count = count( $this->requests->items( $id ) );

			$parts[]    = $id . ':' . (int) $request->approval_revision;
			$requests[] = array(
				'id'     => $id,
				'owner'  => (int) $request->owner_id,
				'count'  => $count,
				'bytes'  => $this->requests->bytes( $id ),
			);
			$items     += $count;
			$bytes     += $this->requests->bytes( $id );
		}

		return array(
			'hash'     => hash( 'sha256', implode( '|', $parts ) ),
			'requests' => $requests,
			'items'    => $items,
			'bytes'    => $bytes,
		);
	}

	/**
	 * Confirm a batch. Requires an explicit phrase, a matching preview hash and
	 * the execution capability (checked by the REST layer).
	 *
	 * @param array<int, int> $request_ids Request ids.
	 * @param int             $admin_id    Executor.
	 * @param string          $phrase      Confirmation phrase.
	 * @param string          $hash        Hash shown in the preview.
	 * @return array<string, mixed>|WP_Error
	 */
	public function confirm( array $request_ids, int $admin_id, string $phrase, string $hash ) {
		global $wpdb;

		$confirm_word = __( '删除', 'sakura-imgopt' );

		if ( $confirm_word !== trim( $phrase ) ) {
			return new WP_Error( 'sufm_confirm', __( '请输入“删除”两个字以确认执行。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$preview = $this->preview( $request_ids );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( '' === $hash || ! hash_equals( (string) $preview['hash'], $hash ) ) {
			return new WP_Error( 'sufm_snapshot', __( '预览范围已变化，请重新勾选并确认。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$batch_table = Schema::table( 'delete_batches' );
		$now         = Time::now();

		$wpdb->insert(
			$batch_table,
			array(
				'owner_id'          => (int) ( $preview['requests'][0]['owner'] ?? 0 ),
				'request_id'        => 0,
				'approval_revision' => 0,
				'snapshot_hash'     => (string) $preview['hash'],
				'state'             => 'queued',
				'total_items'       => (int) $preview['items'],
				'done_items'        => 0,
				'failed_items'      => 0,
				'executor_id'       => $admin_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		$batch_id = (int) $wpdb->insert_id;

		if ( $batch_id <= 0 ) {
			return new WP_Error( 'sufm_batch', __( '创建删除批次失败，请重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$context = array( 'batch_id' => $batch_id, 'requests' => array() );

		foreach ( $preview['requests'] as $entry ) {
			$request = $this->requests->get( (int) $entry['id'] );

			if ( ! $request ) {
				continue;
			}

			$context['requests'][] = array(
				'id'       => (int) $request->id,
				'revision' => (int) $request->approval_revision,
			);
		}

		// The job row is the execution carrier: if it cannot be written, the
		// confirmation must fail before any request moves to queued, or the
		// batch looks confirmed and can never run.
		$job_table    = Schema::table( 'jobs' );
		$job_inserted = $wpdb->insert(
			$job_table,
			array(
				'type'        => 'delete_batch',
				'owner_id'    => (int) ( $preview['requests'][0]['owner'] ?? 0 ),
				'subject_id'  => $batch_id,
				'dedupe_key'  => 'batch:' . $batch_id . ':' . substr( (string) $preview['hash'], 0, 24 ),
				'context_json' => (string) wp_json_encode( $context ),
				'payload_json' => '{}',
				'state'       => 'queued',
				'fence'       => 0,
				'attempts'    => 0,
				'next_at'     => $now,
				'last_error'  => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		$job_id = (int) $wpdb->insert_id;

		if ( ! $job_inserted || $job_id <= 0 ) {
			$wpdb->update( $batch_table, array( 'state' => 'failed', 'updated_at' => Time::now() ), array( 'id' => $batch_id ) );

			return new WP_Error( 'sufm_job', __( '创建执行任务失败，请重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		foreach ( $context['requests'] as $entry ) {
			$ok = $this->requests->transition(
				(int) $entry['id'],
				Requests::APPROVED,
				Requests::QUEUED,
				array(
					'batch_id'     => $batch_id,
					'executor_id'  => $admin_id,
					'confirmed_at' => $now,
				)
			);

			if ( ! $ok ) {
				$wpdb->update( $batch_table, array( 'state' => 'failed', 'updated_at' => Time::now() ), array( 'id' => $batch_id ) );
				$wpdb->update( $job_table, array( 'state' => 'failed', 'last_error' => 'state_changed', 'updated_at' => Time::now() ), array( 'id' => $job_id ) );

				return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重新选择。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$this->requests->set_items_state( (int) $entry['id'], 'queued' );
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 1, 'sufm_delete_tick' );
		}

		return array(
			'batch_id' => $batch_id,
			'items'    => (int) $preview['items'],
			'requests' => $context['requests'],
		);
	}

	/**
	 * Stop a batch and bump every request's authority generation.
	 *
	 * @param int $batch_id Batch.
	 * @param int $actor    Actor.
	 * @return bool|WP_Error
	 */
	public function stop( int $batch_id, int $actor ) {
		global $wpdb;

		$batch = $this->batch( $batch_id );

		if ( ! $batch ) {
			return new WP_Error( 'sufm_batch_missing', __( '删除批次不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( in_array( (string) $batch->state, array( 'completed', 'failed', 'partial_failed', 'stopped' ), true ) ) {
			return new WP_Error( 'sufm_batch_done', __( '该批次已经结束。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$wpdb->update(
			Schema::table( 'delete_batches' ),
			array(
				'state'      => 'stopped',
				'stopped_at' => Time::now(),
				'updated_at' => Time::now(),
			),
			array( 'id' => $batch_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . Schema::table( 'jobs' ) . " SET state='stopped', updated_at=%s WHERE subject_id=%d AND type='delete_batch'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::now(),
				$batch_id
			)
		);

		$requests_table = Schema::table( 'delete_requests' );
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$requests_table} WHERE batch_id=%d AND status IN ('queued','executing')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch_id
			)
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$request = $this->requests->get( (int) $row->id );

			if ( $request ) {
				$this->requests->stop( $request, $actor );
			}
		}

		return true;
	}

	/**
	 * Read one batch row.
	 *
	 * @param int $batch_id Batch.
	 * @return object|null
	 */
	public function batch( int $batch_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'delete_batches' ) . ' WHERE id=%d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$batch_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * One worker tick: claim a job and process a bounded number of items.
	 *
	 * @param int $budget Maximum items to touch in this request.
	 * @return int Processed item count.
	 */
	public function tick( int $budget = 40 ): int {
		// Settle deletions interrupted mid-way before claiming new work: the
		// file may already be gone while the charge is still on the ledger.
		$this->recover_deleting();

		$job = $this->claim();

		if ( ! $job ) {
			return 0;
		}

		try {
			return $this->run( $job, max( 1, min( 200, $budget ) ) );
		} catch ( \Throwable $e ) {
			$this->fail_job( $job, 'exception: ' . Text::clip( $e->getMessage(), 180 ) );

			return 0;
		}
	}

	/**
	 * Claim one queued job with a compare-and-set.
	 *
	 * @return object|null
	 */
	private function claim(): ?object {
		global $wpdb;

		$jobs = Schema::table( 'jobs' );
		$now  = Time::now();

		// Watchdog: a worker that died leaves a stale lease behind.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$jobs} SET state='queued' WHERE state='running' AND lease_until < %s LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$jobs} WHERE state IN ('queued','retry_wait') AND next_at <= %s ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		if ( $id <= 0 ) {
			return null;
		}

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$jobs} SET state='running', fence=fence+1, attempts=attempts+1, lease_until=%s, updated_at=%s WHERE id=%d AND state IN ('queued','retry_wait')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::in( self::LEASE ),
				$now,
				$id
			)
		);

		if ( 1 !== $claimed ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$jobs} WHERE id=%d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $row ? $row : null;
	}

	/**
	 * Run claimed work.
	 *
	 * @param object $job    Job row.
	 * @param int    $budget Item budget.
	 * @return int Processed items.
	 */
	private function run( object $job, int $budget ): int {
		$context = json_decode( (string) $job->context_json, true );
		$context = is_array( $context ) ? $context : array();

		$batch_id = (int) ( $context['batch_id'] ?? $job->subject_id );
		$batch    = $this->batch( $batch_id );

		if ( ! $batch || in_array( (string) $batch->state, array( 'stopped', 'completed', 'failed', 'partial_failed' ), true ) ) {
			$this->finish_job( $job, 'succeeded' );

			return 0;
		}

		global $wpdb;

		$wpdb->update(
			Schema::table( 'delete_batches' ),
			array(
				'state'      => 'executing',
				'started_at' => '' === (string) $batch->started_at || '0000-00-00 00:00:00' === (string) $batch->started_at ? Time::now() : $batch->started_at,
				'updated_at' => Time::now(),
			),
			array( 'id' => $batch_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$processed = 0;
		$entries   = isset( $context['requests'] ) && is_array( $context['requests'] ) ? $context['requests'] : array();

		foreach ( $entries as $entry ) {
			$request_id = (int) ( $entry['id'] ?? 0 );
			$revision   = (int) ( $entry['revision'] ?? 0 );
			$request    = $this->requests->get( $request_id );

			if ( ! $request || (int) $request->batch_id !== $batch_id ) {
				continue;
			}

			if ( (int) $request->approval_revision !== $revision ) {
				$this->requests->set_items_state( $request_id, 'revoked' );
				continue;
			}

			if ( ! in_array( (string) $request->status, array( Requests::QUEUED, Requests::EXECUTING ), true ) ) {
				$this->requests->set_items_state( $request_id, 'stopped' );
				continue;
			}

			$this->requests->transition( $request_id, (string) $request->status, Requests::EXECUTING );

			$items = $this->requests->items( $request_id );

			usort(
				$items,
				static function ( $a, $b ) {
					$rank = static fn( $item ) => 'file' === (string) $item->object_type ? 0 : 1;

					return $rank( $a ) <=> $rank( $b ) ?: (int) $a->id <=> (int) $b->id;
				}
			);

			foreach ( $items as $item ) {
				if ( $processed >= $budget ) {
					break 2;
				}

				if ( ! in_array( (string) $item->state, array( 'queued', 'executing', 'deleting' ), true ) ) {
					continue;
				}

				// The lease/fence must still be ours before an irreversible step.
				if ( ! $this->authority( $job ) ) {
					$this->finish_job( $job, 'queued' );

					return $processed;
				}

				// Re-read authority for every irreversible step.
				$fresh = $this->requests->get( $request_id );

				if ( ! $fresh || (int) $fresh->approval_revision !== $revision || ! in_array( (string) $fresh->status, array( Requests::EXECUTING, Requests::QUEUED ), true ) ) {
					$this->update_item( (int) $item->id, 'stopped', 'authority_lost' );
					continue;
				}

				++$processed;

				if ( 'file' === (string) $item->object_type ) {
					$ok = $this->purge_file( $item, $fresh );
				} else {
					$ok = $this->purge_folder( $item, $fresh );
				}

				if ( ! $ok ) {
					$this->bump_batch( $batch_id, 'failed_items' );
				} else {
					$this->bump_batch( $batch_id, 'done_items' );
				}
			}

			$this->finalize_request( $request_id );
		}

		$batch = $this->batch( $batch_id );

		if ( $batch ) {
			$state = (int) $batch->failed_items > 0
				? ( 0 === (int) $batch->done_items ? 'failed' : 'partial_failed' )
				: 'completed';

			$remaining = (int) $batch->total_items - (int) $batch->done_items - (int) $batch->failed_items;

			if ( (string) $batch->state !== 'stopped' ) {
				$wpdb->update(
					Schema::table( 'delete_batches' ),
					array(
						'state'       => $remaining > 0 ? 'executing' : $state,
						'finished_at' => $remaining > 0 ? '0000-00-00 00:00:00' : Time::now(),
						'updated_at'  => Time::now(),
					),
					array( 'id' => $batch_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		$batch = $this->batch( $batch_id );
		$left  = $batch ? (int) $batch->total_items - (int) $batch->done_items - (int) $batch->failed_items : 0;

		if ( $left > 0 && $batch && 'stopped' !== (string) $batch->state ) {
			// Budget reached with work left: keep the progress and come back.
			$this->finish_job( $job, 'retry_wait', 60 );

			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + 60, 'sufm_delete_tick' );
			}

			return $processed;
		}

		$this->finish_job( $job, 'succeeded' );

		return $processed;
	}

	/**
	 * Delete one file's artifacts, prove they are gone, then charge down.
	 *
	 * @param object $item    Item row.
	 * @param object $request Request row.
	 * @return bool
	 */
	private function purge_file( object $item, object $request ): bool {
		global $wpdb;

		$file = $this->files->get( (int) $request->owner_id, (int) $item->object_id );

		if ( ! $file ) {
			$this->update_item( (int) $item->id, 'failed', 'missing' );

			return false;
		}

		if ( 'purged' === (string) $file->lifecycle ) {
			$this->update_item( (int) $item->id, 'done', 'already' );

			return true;
		}

		if ( (int) $file->pending_request_id !== (int) $request->id ) {
			$this->update_item( (int) $item->id, 'failed', 'ownership' );

			return false;
		}

		$is_media = Files::SOURCE_MEDIA === (string) $file->source;

		// Only the manifest frozen by the approval may be deleted. Media-library
		// adoptions have no artifact manifest, so they use the attachment itself
		// as the frozen scope (attachment id, version and owner were verified
		// above and are re-checked below).
		$snapshot = json_decode( (string) $item->manifest_snapshot, true );

		if ( ! $is_media && ( ! is_array( $snapshot ) || ! $snapshot ) ) {
			$this->update_item( (int) $item->id, 'failed', 'no_snapshot' );

			return false;
		}

		if ( (int) $file->version !== (int) $item->object_version || (int) $file->attachment_id !== (int) $item->attachment_id ) {
			$this->update_item( (int) $item->id, 'failed', 'snapshot_changed' );

			return false;
		}

		// Continuous authorization: the confirming admin must still be allowed
		// to delete this specific attachment, and the owner must not have moved.
		$executor = (int) $request->executor_id;

		if ( $executor <= 0 || ! user_can( $executor, Capabilities::DELETE_FILES ) || ! user_can( $executor, 'delete_post', (int) $file->attachment_id ) ) {
			$this->update_item( (int) $item->id, 'failed', 'authority' );

			return false;
		}

		// Record the intent before the first irreversible step. A worker killed
		// between the unlink and complete_purge() leaves the file gone and the
		// charge in place; tick()'s recovery pass finishes that ledger instead
		// of depending on this job surviving.
		if ( ! $this->claim_item_for_delete( (int) $item->id ) ) {
			return true;
		}

		$post = get_post( (int) $file->attachment_id );

		if ( $post && (int) $post->post_author !== (int) $request->owner_id ) {
			$this->update_item( (int) $item->id, 'failed', 'owner_changed' );

			return false;
		}

		if ( ! $post ) {
			// A previous attempt deleted the attachment and then died before the
			// ledger transaction. The files are gone, so finish the accounting
			// instead of failing forever on a missing post.
			return $this->complete_purge( $item, $request, $file );
		}

		if ( $is_media ) {
			$allow = static function () {
				return true;
			};

			add_filter( 'sufm_allow_attachment_delete', $allow, 99 );
			wp_delete_attachment( (int) $file->attachment_id, true );
			remove_filter( 'sufm_allow_attachment_delete', $allow, 99 );

			if ( get_post( (int) $file->attachment_id ) ) {
				$this->update_item( (int) $item->id, 'failed', 'attachment_kept' );

				return false;
			}

			return $this->complete_purge( $item, $request, $file );
		}

		$keys = array();

		foreach ( $snapshot as $entry ) {
			$keys[] = (string) ( $entry['key'] ?? '' );

			if ( '' !== (string) ( $entry['remote'] ?? '' ) ) {
				// A remote copy exists: settle only after the object store
				// confirms removal (adapter hook), never on a local unlink alone.
				$deleted = apply_filters( 'sufm_delete_remote_artifact', null, $entry, $file, $request );

				if ( true !== $deleted ) {
					$this->update_item( (int) $item->id, 'failed', 'remote_unverified' );

					return false;
				}
			}

			$path = (string) ( $entry['local'] ?? '' );

			if ( '' === $path ) {
				continue;
			}

			if ( is_link( $path ) || ! $this->inside_uploads( $path ) ) {
				$this->update_item( (int) $item->id, 'failed', 'unsafe_path' );

				return false;
			}

			if ( ! file_exists( $path ) ) {
				continue;
			}

			if ( ! wp_delete_file( $path ) ) {
				$this->update_item( (int) $item->id, 'failed', 'unlink' );

				return false;
			}

			clearstatcache( true, $path );

			if ( file_exists( $path ) ) {
				$this->update_item( (int) $item->id, 'failed', 'still_present' );

				return false;
			}
		}

		// A generation created after the approval is outside the granted scope.
		$artifacts_table = Schema::table( 'artifacts' );
		$extra           = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT artifact_key FROM {$artifacts_table} WHERE file_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $file->id
			)
		);

		foreach ( is_array( $extra ) ? $extra : array() as $key ) {
			if ( ! in_array( (string) $key, $keys, true ) ) {
				$this->update_item( (int) $item->id, 'failed', 'manifest_changed' );

				return false;
			}
		}

		$allow = static function () {
			return true;
		};

		add_filter( 'sufm_allow_attachment_delete', $allow, 99 );

		wp_delete_attachment( (int) $file->attachment_id, true );

		remove_filter( 'sufm_allow_attachment_delete', $allow, 99 );

		if ( get_post( (int) $file->attachment_id ) ) {
			$this->update_item( (int) $item->id, 'failed', 'attachment_kept' );

			return false;
		}

		return $this->complete_purge( $item, $request, $file );
	}

	/**
	 * Shared tail of a confirmed file purge: drop artifacts, release the charge
	 * and close the row only after the attachment is verified gone.
	 *
	 * @param object $item    Item row.
	 * @param object $request Request row.
	 * @param object $file    File row.
	 * @return bool
	 */
	private function complete_purge( object $item, object $request, object $file ): bool {
		global $wpdb;

		$items_table = Schema::table( 'delete_request_items' );

		Db_Transaction::begin();

		// The item row is the mutex. A worker whose lease expired and the
		// retry worker both get here; locking the row and re-reading its
		// state makes the loser a no-op instead of a second charge.
		$fresh = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT state FROM {$items_table} WHERE id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $item->id
			)
		);

		if ( ! $fresh || ! in_array( (string) $fresh->state, array( 'queued', 'executing', 'deleting' ), true ) ) {
			Db_Transaction::rollback();

			return true;
		}

		// Artifacts, ledger and row close as one unit: deleting the artifact
		// records before the transaction lost them even when the charge failed.
		// The lifecycle write is the plugin-wide mutex, so the charge moves only
		// when this call won it — the deleted_post observer settles the same row
		// earlier in this very request and would otherwise refund it twice.
		$artifacts_ok = $wpdb->delete( Schema::table( 'artifacts' ), array( 'file_id' => (int) $file->id ), array( '%d' ) );
		$lifecycle_ok = $this->files->settle_purged( (int) $file->id );
		$charged_ok   = true;

		if ( $lifecycle_ok ) {
			$charged_ok = $this->quota->adjust_used( (int) $request->owner_id, -1 * (int) $file->charged_bytes );
		}

		if ( false === $artifacts_ok || ! $charged_ok || ! $lifecycle_ok ) {
			$current = $this->files->get_by_id( (int) $file->id );

			// Already settled means the observer got here first: the charge is
			// gone and only the request item is left to close.
			if ( $current && 'purged' === (string) $current->lifecycle && false !== $artifacts_ok ) {
				$this->update_item( (int) $item->id, 'done', 'purged' );
				Db_Transaction::commit();

				return true;
			}

			Db_Transaction::rollback();

			$this->update_item( (int) $item->id, 'failed', 'ledger' );

			return false;
		}

		$this->update_item( (int) $item->id, 'done', 'purged' );

		Db_Transaction::commit();

		return true;
	}

	/**
	 * Remove an empty folder row; refuse when live files remain.
	 *
	 * @param object $item    Item row.
	 * @param object $request Request row.
	 * @return bool
	 */
	private function purge_folder( object $item, object $request ): bool {
		global $wpdb;

		$items_table   = Schema::table( 'delete_request_items' );
		$folders_table = Schema::table( 'folders' );

		Db_Transaction::begin();

		$fresh = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT state FROM {$items_table} WHERE id=%d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $item->id
			)
		);

		if ( ! $fresh || ! in_array( (string) $fresh->state, array( 'queued', 'executing', 'deleting' ), true ) ) {
			Db_Transaction::rollback();

			return true;
		}

		$folder = $this->folders->get( (int) $request->owner_id, (int) $item->object_id );

		if ( ! $folder ) {
			Db_Transaction::rollback();

			$this->update_item( (int) $item->id, 'done', 'missing' );

			return true;
		}

		// The request must still own the lock: another request may have
		// overwritten pending_request_id, and purging then removed a folder
		// that request was still responsible for.
		if ( (int) $folder->pending_request_id !== (int) $request->id ) {
			Db_Transaction::rollback();

			$this->update_item( (int) $item->id, 'failed', 'ownership' );

			return false;
		}

		$live = $this->files->recursive_files( (int) $request->owner_id, (int) $item->object_id, $this->folders );

		if ( $live ) {
			Db_Transaction::rollback();

			$this->update_item( (int) $item->id, 'failed', 'not_empty' );

			return false;
		}

		// Folders are purged deepest first so a parent never points at a live child.
		$descendants = $this->folders->descendants( (int) $request->owner_id, (int) $item->object_id );
		usort( $descendants, static fn( $a, $b ) => (int) $b->depth <=> (int) $a->depth );

		foreach ( $descendants as $child ) {
			$left = $this->files->recursive_files( (int) $request->owner_id, (int) $child->id, $this->folders );

			if ( $left ) {
				Db_Transaction::rollback();

				$this->update_item( (int) $item->id, 'failed', 'child_not_empty' );

				return false;
			}

			$updated = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$folders_table} SET lifecycle='deleted', active_slot=%d, pending_request_id=0, updated_at=%s WHERE id=%d AND owner_id=%d AND pending_request_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $child->id,
					Time::now(),
					(int) $child->id,
					(int) $request->owner_id,
					(int) $request->id
				)
			);

			if ( 1 !== $updated ) {
				Db_Transaction::rollback();

				$this->update_item( (int) $item->id, 'failed', 'ownership' );

				return false;
			}
		}

		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$folders_table} SET lifecycle='deleted', active_slot=%d, pending_request_id=0, version=%d, tree_version=%d, updated_at=%s WHERE id=%d AND owner_id=%d AND pending_request_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $folder->id,
				(int) $folder->version + 1,
				(int) $folder->tree_version + 1,
				Time::now(),
				(int) $folder->id,
				(int) $request->owner_id,
				(int) $request->id
			)
		);

		if ( 1 !== $updated ) {
			Db_Transaction::rollback();

			$this->update_item( (int) $item->id, 'failed', 'ownership' );

			return false;
		}

		$this->update_item( (int) $item->id, 'done', 'purged' );

		Db_Transaction::commit();

		return true;
	}

	/**
	 * Close out one request based on its item states.
	 *
	 * @param int $request_id Request.
	 * @return void
	 */
	private function finalize_request( int $request_id ): void {
		global $wpdb;

		$items = $this->requests->items( $request_id );
		$open  = 0;
		$fail  = 0;

		foreach ( $items as $item ) {
			if ( in_array( (string) $item->state, array( 'queued', 'executing', 'deleting' ), true ) ) {
				++$open;
			}

			if ( 'failed' === (string) $item->state ) {
				++$fail;
			}
		}

		if ( $open > 0 ) {
			return;
		}

		$request = $this->requests->get( $request_id );

		if ( ! $request ) {
			return;
		}

		$review = 0;

		foreach ( $items as $item ) {
			if ( in_array( (string) $item->result, array( 'snapshot_changed', 'manifest_changed', 'remote_unverified', 'owner_changed' ), true ) ) {
				++$review;
			}
		}

		if ( $review > 0 ) {
			$this->requests->transition( $request_id, (string) $request->status, Requests::NEEDS, array() );
			$this->notifier->after( $request, Requests::NEEDS, count( $items ) );

			return;
		}

		$status = $fail > 0 ? Requests::PARTIAL : Requests::COMPLETED;

		$this->requests->transition( $request_id, (string) $request->status, $status, array() );

		if ( Requests::COMPLETED === $status ) {
			$this->requests->unlock( $request_id );
		}

		$this->notifier->after( $request, $status, count( $items ) );
	}

	/**
	 * Update one item row.
	 *
	 * @param int    $item_id Item.
	 * @param string $state   State.
	 * @param string $result  Result code.
	 * @return bool True when the row was actually transitioned.
	 */
	/**
	 * Mark the item as "deletion started" before anything is unlinked.
	 *
	 * @param int $item_id Item id.
	 * @return bool True when this worker may proceed.
	 */
	private function claim_item_for_delete( int $item_id ): bool {
		global $wpdb;

		$table = Schema::table( 'delete_request_items' );

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, result=%s, updated_at=%s WHERE id=%d AND state IN ('queued','executing')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'deleting',
				'intent',
				Time::now(),
				$item_id
			)
		);

		if ( 1 === $claimed ) {
			return true;
		}

		// Already claimed by an earlier attempt: proceed only while the row
		// still says the deletion is in flight.
		$state = $wpdb->get_var(
			$wpdb->prepare( "SELECT state FROM {$table} WHERE id=%d", $item_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return 'deleting' === (string) $state;
	}

	/**
	 * Finish or roll back deletions abandoned by a dead worker.
	 *
	 * @return int Number of repaired items.
	 */
	private function recover_deleting(): int {
		global $wpdb;

		$items_table    = Schema::table( 'delete_request_items' );
		$requests_table = Schema::table( 'delete_requests' );

		$stale = gmdate( 'Y-m-d H:i:s', time() - 300 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, r.status AS request_status FROM {$items_table} i INNER JOIN {$requests_table} r ON r.id=i.request_id WHERE i.state=%s AND i.updated_at < %s ORDER BY i.id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'deleting',
				$stale
			)
		);

		$fixed = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$request = $this->requests->get( (int) $row->request_id );

			if ( ! $request ) {
				$this->update_item( (int) $row->id, 'failed', 'missing' );
				++$fixed;
				continue;
			}

			$file = $this->files->get( (int) $request->owner_id, (int) $row->object_id );

			if ( ! $file ) {
				$this->update_item( (int) $row->id, 'failed', 'missing' );
				++$fixed;
				continue;
			}

			if ( $this->still_present( $file ) ) {
				if ( ! in_array( (string) ( $row->request_status ?? '' ), array( 'queued', 'executing' ), true ) ) {
					// The request was stopped while the worker was mid-delete: the
					// artifacts are intact, so park the item instead of reviving it.
					$this->update_item( (int) $row->id, 'stopped', 'stopped_mid_delete' );
					++$fixed;
					continue;
				}

				// Nothing was removed yet: hand the item back to the queue.
				$reset = (int) $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$items_table} SET state=%s, result=%s, updated_at=%s WHERE id=%d AND state=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'queued',
						'',
						Time::now(),
						(int) $row->id,
						'deleting'
					)
				);

				$fixed += 1 === $reset ? 1 : 0;
				continue;
			}

			// The artifacts are already gone: the charge must come off whatever
			// the request state is; finalizing only applies to a live request.
			if ( $this->complete_purge( $row, $request, $file ) ) {
				$this->bump_batch( (int) $request->batch_id, 'done_items' );

				if ( in_array( (string) ( $row->request_status ?? '' ), array( 'queued', 'executing' ), true ) ) {
					$this->finalize_request( (int) $request->id );
				}

				++$fixed;
			}
		}

		return $fixed;
	}

	/**
	 * Whether the deleted artifact is still on disk (attachment or local file).
	 *
	 * @param object $file File row.
	 * @return bool
	 */
	private function still_present( object $file ): bool {
		if ( (int) $file->attachment_id > 0 && get_post( (int) $file->attachment_id ) ) {
			return true;
		}

		global $wpdb;

		$table = Schema::table( 'artifacts' );
		$paths = $wpdb->get_col(
			$wpdb->prepare( "SELECT local_path FROM {$table} WHERE file_id=%d", (int) $file->id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			if ( '' !== (string) $path && file_exists( (string) $path ) ) {
				return true;
			}
		}

		return false;
	}

	private function update_item( int $item_id, string $state, string $result ): bool {
		global $wpdb;

		$table = Schema::table( 'delete_request_items' );

		// Never rewrite an item that already reached a terminal state: two
		// workers processing the same item used to overwrite each other's
		// result and both bump the batch counters.
		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, result=%s, updated_at=%s WHERE id=%d AND state NOT IN ('done','failed','stopped','revoked')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state,
				substr( $result, 0, 32 ),
				Time::now(),
				$item_id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Increment a batch counter atomically.
	 *
	 * @param int    $batch_id Batch.
	 * @param string $column   done_items|failed_items.
	 * @return void
	 */
	private function bump_batch( int $batch_id, string $column ): void {
		global $wpdb;

		$allowed = array( 'done_items', 'failed_items' );

		if ( ! in_array( $column, $allowed, true ) ) {
			return;
		}

		$table = Schema::table( 'delete_batches' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET {$column}={$column}+1, updated_at=%s WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Time::now(),
				$batch_id
			)
		);
	}

	/**
	 * Is this an absolute path inside the uploads directory?
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private function inside_uploads( string $path ): bool {
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( (string) $uploads['basedir'] );
		$real    = realpath( $path );

		if ( false === $base ) {
			return false;
		}

		if ( false === $real ) {
			$real = $path;
		}

		return 0 === strpos( $real, $base . DIRECTORY_SEPARATOR );
	}

	/**
	 * Does this worker still hold a live lease for the job?
	 *
	 * @param object $job Job row as claimed.
	 * @return bool
	 */
	private function authority( object $job ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . Schema::table( 'jobs' ) . " WHERE id=%d AND state='running' AND fence=%d AND lease_until>=%s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $job->id,
				(int) $job->fence,
				Time::now()
			)
		);
	}

	/**
	 * Mark a job finished.
	 *
	 * @param object $job      Job.
	 * @param string $state    New state.
	 * @param int    $delay    Seconds until the next attempt when not final.
	 * @return void
	 */
	private function finish_job( object $job, string $state, int $delay = 0 ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'jobs' ),
			array(
				'state'       => $state,
				'lease_until' => '0000-00-00 00:00:00',
				'next_at'     => $delay > 0 ? Time::in( $delay ) : Time::now(),
				'updated_at'  => Time::now(),
			),
			array( 'id' => (int) $job->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retry or bury a job that threw.
	 *
	 * @param object $job    Job.
	 * @param string $reason Safe reason.
	 * @return void
	 */
	private function fail_job( object $job, string $reason ): void {
		global $wpdb;

		$attempts = (int) $job->attempts;
		$state    = $attempts >= 5 ? 'dead' : 'queued';

		$wpdb->update(
			Schema::table( 'jobs' ),
			array(
				'state'      => $state,
				'attempts'   => $attempts,
				'last_error' => Text::clip( $reason, 200 ),
				'next_at'    => Time::in( min( 3600, 60 * ( 2 ** max( 0, min( 6, $attempts ) ) ) ) ),
				'updated_at' => Time::now(),
			),
			array( 'id' => (int) $job->id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}
}
