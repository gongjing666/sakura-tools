<?php
/**
 * Delete requests: the user-facing intent record.
 *
 * A request freezes the scope (expanded to one item per affected object) and
 * locks those objects. Approval is a review verdict only: it never deletes,
 * never enqueues a job and never releases bytes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Repositories;

use Nboke\UserFiles\Support\Db_Transaction;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Request state machine and item freezing.
 */
final class Requests {

	public const PENDING   = 'pending_review';
	public const WITHDRAWN = 'withdrawn';
	public const REJECTED  = 'rejected';
	public const APPROVED  = 'approved';
	public const REVOKED   = 'revoked';
	public const NEEDS     = 'needs_review';
	public const QUEUED    = 'queued';
	public const EXECUTING = 'executing';
	public const COMPLETED = 'completed';
	public const PARTIAL   = 'partial_failed';
	public const FAILED    = 'failed';
	public const STOPPED   = 'stopped';

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
	 * Event log.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Wire dependencies.
	 *
	 * @param Files   $files   Files.
	 * @param Folders $folders Folders.
	 * @param Events  $events  Events.
	 */
	public function __construct( Files $files, Folders $folders, Events $events ) {
		$this->files   = $files;
		$this->folders = $folders;
		$this->events  = $events;
	}

	/**
	 * Read one request row.
	 *
	 * @param int $id Request id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * One request owned by the given user.
	 *
	 * @param int $owner_id   Owner.
	 * @param int $request_id Request.
	 * @return object|null
	 */
	public function for_owner( int $owner_id, int $request_id ): ?object {
		$row = $this->get( $request_id );

		if ( ! $row || (int) $row->owner_id !== $owner_id ) {
			return null;
		}

		return $row;
	}

	/**
	 * Items of one request, ordered for display and execution.
	 *
	 * @param int $request_id Request id.
	 * @return array<int, object>
	 */
	public function items( int $request_id ): array {
		global $wpdb;

		$table   = Schema::table( 'delete_request_items' );
		$files   = Schema::table( 'files' );
		$folders = Schema::table( 'folders' );
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.*, f.display_name AS file_name, d.name AS folder_name
				FROM {$table} i
				LEFT JOIN {$files} f ON i.object_type='file' AND f.id=i.object_id AND f.owner_id=i.owner_id
				LEFT JOIN {$folders} d ON i.object_type='folder' AND d.id=i.object_id AND d.owner_id=i.owner_id
				WHERE i.request_id=%d ORDER BY (i.object_type='file') DESC, i.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$request_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count requests in a state for one owner.
	 *
	 * @param int    $owner_id Owner.
	 * @param string $status   Status ('' = all).
	 * @return int
	 */
	public function count( int $owner_id, string $status = '' ): int {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );

		// owner_id = 0 means "every owner" so the admin queue can show totals.
		if ( $owner_id <= 0 ) {
			if ( '' === $status ) {
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status=%s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		if ( '' === $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id=%d", $owner_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND status=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$status
			)
		);
	}

	/**
	 * Count pending requests for many owners in one query (audit A08).
	 *
	 * @param array<int> $owner_ids Owner ids.
	 * @param string     $status    Status to count.
	 * @return array<int, int> Owner id => count.
	 */
	public function pending_counts_by_owner( array $owner_ids, string $status = self::PENDING ): array {
		global $wpdb;

		$owner_ids = array_values( array_unique( array_filter( array_map( 'intval', $owner_ids ) ) ) );

		if ( ! $owner_ids ) {
			return array();
		}

		$table = Schema::table( 'delete_requests' );
		$marks = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT owner_id, COUNT(*) AS total FROM {$table} WHERE status=%s AND owner_id IN ({$marks}) GROUP BY owner_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $status ), $owner_ids )
			)
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row->owner_id ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Create a request from user targets.
	 *
	 * @param int                  $owner_id   Owner.
	 * @param int                  $requester  Requesting user.
	 * @param array<int, array{type:string,id:int}> $targets Targets.
	 * @param string               $reason     Free text reason.
	 * @param string               $key        Idempotency key.
	 * @return object|WP_Error
	 */
	public function create( int $owner_id, int $requester, array $targets, string $reason, string $key, string $notice_version = '', bool $confirmed = false ) {
		global $wpdb;

		$settings = Settings::get();
		$key      = substr( sanitize_text_field( $key ), 0, 160 );
		$reason   = mb_substr( sanitize_textarea_field( $reason ), 0, 500 );

		// Validate before namespacing, or an empty key becomes "{owner}:"
		// and silently collides with every later request by the same owner.
		if ( '' === $key ) {
			return new WP_Error( 'sufm_key_missing', __( '缺少申请幂等键，请刷新页面重试。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$key = $owner_id . ':' . $key;

		$payload = array();
		foreach ( $targets as $target ) {
			$payload[] = (string) ( $target['type'] ?? '' ) . ':' . (int) ( $target['id'] ?? 0 );
		}
		sort( $payload );
		$payload_hash = hash( 'sha256', implode( ',', $payload ) . '|' . $reason . '|' . $notice_version );

		if ( ! $confirmed ) {
			return new WP_Error( 'sufm_reference_unconfirmed', __( '请先确认已了解删除后的引用影响。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( (string) $settings['reference_notice_version'] !== $notice_version ) {
			return new WP_Error( 'sufm_notice_version', __( '删除提示已更新，请刷新页面重新确认。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( $this->pending_count( $owner_id ) >= (int) $settings['max_pending_delete_requests'] ) {
			return new WP_Error( 'sufm_request_limit', __( '待处理申请过多，请等待管理员审核后再提交。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$existing = $this->find_by_key( $key );

		if ( $existing ) {
			if ( (int) $existing->owner_id !== $owner_id || (string) $existing->payload_hash !== $payload_hash ) {
				return new WP_Error( 'sufm_key_conflict', __( '幂等键已被其他申请占用，请刷新页面后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			return $existing;
		}

		$plan  = $this->plan( $owner_id, $targets, (int) $settings['max_delete_request_items'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$now   = Time::now();
		$table = Schema::table( 'delete_requests' );

		Db_Transaction::begin();

		try {
			$inserted = $wpdb->insert(
				$table,
				array(
					'blog_id'                   => get_current_blog_id(),
					'owner_id'                  => $owner_id,
					'requester_id'              => $requester,
					'idempotency_key'           => $key,
					'payload_hash'              => $payload_hash,
					'request_revision'          => 1,
					'status'                    => self::PENDING,
					'reason'                    => $reason,
					'reference_notice_version'  => substr( (string) $settings['reference_notice_version'], 0, 32 ),
					'reference_confirmed_at'    => $now,
					'snapshot_hash'             => $plan['snapshot_hash'],
					'created_at'                => $now,
					'updated_at'                => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( ! $inserted ) {
				throw new \RuntimeException( 'insert failed' );
			}

			$request_id = (int) $wpdb->insert_id;
			$items      = Schema::table( 'delete_request_items' );

			foreach ( $plan['items'] as $item ) {
				$ok = $wpdb->insert(
					$items,
					array(
						'request_id'        => $request_id,
						'blog_id'           => get_current_blog_id(),
						'owner_id'          => $owner_id,
						'object_type'       => $item['object_type'],
						'object_id'         => $item['object_id'],
						'parent_id'         => $item['parent_id'],
						'object_version'    => $item['object_version'],
						'tree_version'      => $item['tree_version'],
						'attachment_id'     => $item['attachment_id'],
						'manifest_version'  => $item['manifest_version'],
						'manifest_snapshot' => $item['manifest_snapshot'],
						'item_hash'         => $item['item_hash'],
						'charged_bytes'     => $item['charged_bytes'],
						'state'             => 'pending_review',
						'created_at'        => $now,
						'updated_at'        => $now,
					),
					array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
				);

				if ( ! $ok ) {
					throw new \RuntimeException( 'item insert failed' );
				}
			}

			// Lock every affected object against a second request. The
			// pending_request_id=0 predicate turns this into a compare-and-set:
			// an unconditional write silently stole another request's lock.
			$file_ids = $plan['file_ids'];
			if ( $file_ids ) {
				$files_table = Schema::table( 'files' );
				$in          = implode( ',', array_fill( 0, count( $file_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- fixed SET/WHERE placeholders plus an IN list sized from $file_ids; arguments are merged in the same order.
				$locked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$files_table} SET pending_request_id=%d WHERE owner_id=%d AND pending_request_id=0 AND id IN ({$in})", array_merge( array( $request_id, $owner_id ), $file_ids ) ) );

				if ( count( $file_ids ) !== $locked ) {
					throw new \RuntimeException( 'lock race' );
				}
			}

			$folder_ids = $plan['folder_ids'];
			if ( $folder_ids ) {
				$folders_table = Schema::table( 'folders' );
				$in            = implode( ',', array_fill( 0, count( $folder_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- fixed SET/WHERE placeholders plus an IN list sized from $folder_ids; arguments are merged in the same order.
				$locked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$folders_table} SET pending_request_id=%d WHERE owner_id=%d AND pending_request_id=0 AND id IN ({$in})", array_merge( array( $request_id, $owner_id ), $folder_ids ) ) );

				if ( count( $folder_ids ) !== $locked ) {
					throw new \RuntimeException( 'lock race' );
				}
			}

			Db_Transaction::commit();
		} catch ( \Throwable $e ) {
			Db_Transaction::rollback();

			if ( 'lock race' === $e->getMessage() ) {
				return new WP_Error( 'sufm_locked', __( '部分对象刚刚被另一份申请锁定，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			return new WP_Error( 'sufm_request_failed', __( '提交申请失败，请稍后重试。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$this->events->log(
			array(
				'actor_id'   => $requester,
				'owner_id'   => $owner_id,
				'request_id' => $request_id,
				'subject_id' => $request_id,
				'action'     => 'request_created',
				'result'     => 'ok',
				'safe_detail' => sprintf( 'items=%d files=%d folders=%d', count( $plan['items'] ), count( $plan['file_ids'] ), count( $plan['folder_ids'] ) ),
			)
		);

		$this->set_status( $request_id, self::PENDING, array( 'items' => 'pending_review' ) );

		return $this->get( $request_id );
	}

	/**
	 * Resolve targets into item rows, rejecting anything locked or foreign.
	 *
	 * @param int                                   $owner_id Owner.
	 * @param array<int, array{type:string,id:int}> $targets  Targets.
	 * @param int                                   $limit    Item cap.
	 * @return array{items:array<int,array<string,mixed>>,file_ids:int[],folder_ids:int[],snapshot_hash:string}|WP_Error
	 */
	private function plan( int $owner_id, array $targets, int $limit ) {
		$items      = array();
		$file_ids   = array();
		$folder_ids = array();
		$hashes     = array();
		$seen       = array();

		foreach ( $targets as $target ) {
			$type = (string) ( $target['type'] ?? '' );
			$id   = (int) ( $target['id'] ?? 0 );

			if ( $id <= 0 || ! in_array( $type, array( 'file', 'folder' ), true ) ) {
				continue;
			}

			$dedupe = $type . ':' . $id;
			if ( isset( $seen[ $dedupe ] ) ) {
				continue;
			}
			$seen[ $dedupe ] = true;

			if ( 'file' === $type ) {
				$file = $this->files->get( $owner_id, $id );

				if ( ! $file || (int) $file->owner_id !== $owner_id ) {
					return new WP_Error( 'sufm_not_found', __( '部分文件已不存在，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 404 ) );
				}

				if ( 'active' !== (string) $file->lifecycle || (int) $file->pending_request_id > 0 ) {
					return new WP_Error( 'sufm_locked', __( '部分文件已在删除申请中，不能重复申请。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}

				$items[]    = $this->file_item( $file );
				$file_ids[] = $id;
				$hashes[]   = end( $items )['item_hash'];
				continue;
			}

			$folder = $this->folders->get( $owner_id, $id );

			if ( ! $folder || (int) $folder->owner_id !== $owner_id ) {
				return new WP_Error( 'sufm_not_found', __( '部分文件夹已不存在，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 404 ) );
			}

			if ( 'active' !== (string) $folder->lifecycle || (int) $folder->pending_request_id > 0 ) {
				return new WP_Error( 'sufm_locked', __( '部分文件夹已在删除申请中，不能重复申请。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$descendant_folders = $this->descendant_folders( $owner_id, $id );
			$subtree_files      = $this->subtree_files( $owner_id, $id );

			// A descendant folder already held by another request means this
			// request would delete something that request still owns. Checking
			// only the clicked folder let a second request overwrite the lock.
			foreach ( $descendant_folders as $child ) {
				if ( (int) $child->pending_request_id > 0 ) {
					return new WP_Error( 'sufm_locked', __( '文件夹内有子目录已在删除申请中，请先处理该申请。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}
			}

			foreach ( $subtree_files as $file ) {
				if ( (int) $file->pending_request_id > 0 ) {
					return new WP_Error( 'sufm_locked', __( '文件夹内已有文件在删除申请中，请先处理该申请。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}
			}

			foreach ( $subtree_files as $file ) {
				$items[]    = $this->file_item( $file, $id );
				$file_ids[] = (int) $file->id;
				$hashes[]   = end( $items )['item_hash'];
			}

			$items[]        = $this->folder_item( $folder, array_map( static fn( $f ) => (int) $f->id, $subtree_files ) );
			$folder_ids[]   = $id;
			$folder_ids     = array_merge( $folder_ids, array_map( static fn( $f ) => (int) $f->id, $descendant_folders ) );
			$hashes[]       = end( $items )['item_hash'];
		}

		if ( ! $items ) {
			return new WP_Error( 'sufm_empty', __( '请选择要申请删除的文件或文件夹。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( count( $items ) > $limit ) {
			return new WP_Error(
				'sufm_too_many',
				sprintf(
					/* translators: %d: maximum item count */
					__( '本次申请涉及的对象超过 %d 个，请分批提交。', 'sakura-imgopt' ),
					$limit
				),
				array( 'status' => 409 )
			);
		}

		sort( $hashes );

		return array(
			'items'         => $items,
			'file_ids'      => array_values( array_unique( $file_ids ) ),
			'folder_ids'    => array_values( array_unique( $folder_ids ) ),
			'snapshot_hash' => hash( 'sha256', implode( ',', $hashes ) ),
		);
	}

	/**
	 * Build a file item row.
	 *
	 * @param object $file      File row.
	 * @param int    $parent_id Parent folder target (0 for direct files).
	 * @return array<string, mixed>
	 */
	private function file_item( object $file, int $parent_id = 0 ): array {
		global $wpdb;

		$artifacts = Schema::table( 'artifacts' );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT generation,artifact_key,kind,logical_bytes,local_path,remote_context,checksum,cleanup_state FROM {$artifacts} WHERE file_id=%d ORDER BY generation ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $file->id
			)
		);

		$manifest = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$manifest[] = array(
				'generation' => (int) $row->generation,
				'key'        => (string) $row->artifact_key,
				'kind'       => (string) $row->kind,
				'bytes'      => (int) $row->logical_bytes,
				'local'      => (string) $row->local_path,
				'remote'     => (string) $row->remote_context,
				'checksum'   => (string) $row->checksum,
			);
		}

		$json = (string) wp_json_encode( $manifest );

		return array(
			'object_type'       => 'file',
			'object_id'         => (int) $file->id,
			'parent_id'         => $parent_id,
			'object_version'    => (int) $file->version,
			'tree_version'      => 0,
			'attachment_id'     => (int) $file->attachment_id,
			'manifest_version'  => (int) $file->manifest_version,
			'manifest_snapshot' => $json,
			'item_hash'         => hash( 'sha256', implode( '|', array( 'file', (int) $file->id, (int) $file->version, (int) $file->manifest_version, $json ) ) ),
			'charged_bytes'     => (int) $file->charged_bytes,
		);
	}

	/**
	 * Build a folder item row.
	 *
	 * @param object    $folder   Folder row.
	 * @param array<int> $file_ids Descendant file ids at freeze time.
	 * @return array<string, mixed>
	 */
	private function folder_item( object $folder, array $file_ids ): array {
		$json = (string) wp_json_encode( array( 'files' => array_values( $file_ids ) ) );

		return array(
			'object_type'       => 'folder',
			'object_id'         => (int) $folder->id,
			'parent_id'         => (int) $folder->parent_id,
			'object_version'    => (int) $folder->version,
			'tree_version'      => (int) $folder->tree_version,
			'attachment_id'     => 0,
			'manifest_version'  => 0,
			'manifest_snapshot' => $json,
			'item_hash'         => hash( 'sha256', implode( '|', array( 'folder', (int) $folder->id, (int) $folder->version, (int) $folder->tree_version, $json ) ) ),
			'charged_bytes'     => 0,
		);
	}

	/**
	 * Descendant folders (breadth first).
	 *
	 * @param int $folder_id Root folder.
	 * @return array<int, object>
	 */
	private function descendant_folders( int $owner_id, int $folder_id ): array {
		$out   = array();
		$queue = array( $folder_id );

		while ( $queue ) {
			$current = array_shift( $queue );

			foreach ( $this->folders->children( $owner_id, (int) $current ) as $child ) {
				$out[]   = $child;
				$queue[] = (int) $child->id;
			}
		}

		return $out;
	}

	/**
	 * All files in a folder subtree.
	 *
	 * @param int $folder_id Root folder.
	 * @return array<int, object>
	 */
	private function subtree_files( int $owner_id, int $folder_id ): array {
		return $this->files->recursive_files( $owner_id, $folder_id, $this->folders );
	}

	/**
	 * Re-freeze the approved manifest at decision time. Execution may only
	 * remove exactly these artifacts; later generations turn the item into a
	 * review case instead of being deleted under an older approval.
	 *
	 * @param int $request_id Request.
	 * @return true|WP_Error
	 */
	private function freeze_items( int $request_id ) {
		global $wpdb;

		$items = $this->items( $request_id );
		$table = Schema::table( 'delete_request_items' );

		foreach ( $items as $item ) {
			if ( 'file' === (string) $item->object_type ) {
				$file = $this->files->get( (int) $item->owner_id, (int) $item->object_id );

				if ( ! $file || 'active' !== (string) $file->lifecycle ) {
					return new WP_Error( 'sufm_gone', __( '部分对象已变化，请驳回后让用户重新提交。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}

				$fresh = $this->file_item( $file, (int) $item->parent_id );
			} else {
				$folder = $this->folders->get( (int) $item->owner_id, (int) $item->object_id );

				if ( ! $folder ) {
					return new WP_Error( 'sufm_gone', __( '部分对象已变化，请驳回后让用户重新提交。', 'sakura-imgopt' ), array( 'status' => 409 ) );
				}

				$files = $this->subtree_files( (int) $item->owner_id, (int) $item->object_id );
				$fresh = $this->folder_item( $folder, array_map( static fn( $f ) => (int) $f->id, $files ) );
			}

			$wpdb->update(
				$table,
				array(
					'object_version'    => (int) $fresh['object_version'],
					'tree_version'      => (int) $fresh['tree_version'],
					'attachment_id'     => (int) $fresh['attachment_id'],
					'manifest_version'  => (int) $fresh['manifest_version'],
					'manifest_snapshot' => (string) $fresh['manifest_snapshot'],
					'item_hash'         => (string) $fresh['item_hash'],
					'updated_at'        => Time::now(),
				),
				array( 'id' => (int) $item->id ),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		return true;
	}

	/**
	 * Count non-terminal requests so history does not block new submissions.
	 *
	 * @param int $owner_id Owner.
	 * @return int
	 */
	public function pending_count( int $owner_id ): int {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$live  = array( self::PENDING, self::NEEDS, self::APPROVED, self::QUEUED, self::EXECUTING );

		$in = implode( ',', array_fill( 0, count( $live ), '%s' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $owner_id ), $live )
			)
		);
	}

	/**
	 * Idempotency lookup.
	 *
	 * @param string $key Key.
	 * @return object|null
	 */
	private function find_by_key( string $key ): ?object {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE idempotency_key=%s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Owner withdraws a pending request.
	 *
	 * @param object $request Request row.
	 * @param int    $actor   Actor id.
	 * @return bool|WP_Error
	 */
	public function withdraw( object $request, int $actor ) {
		if ( self::PENDING !== (string) $request->status ) {
			return new WP_Error( 'sufm_state', __( '只有待审核的申请可以撤回。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( ! $this->transition( (int) $request->id, self::PENDING, self::WITHDRAWN, array( 'items' => 'withdrawn' ) ) ) {
			return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$this->unlock( (int) $request->id, (int) $request->owner_id );

		$this->events->log(
			array(
				'actor_id'   => $actor,
				'owner_id'   => (int) $request->owner_id,
				'request_id' => (int) $request->id,
				'subject_id' => (int) $request->id,
				'action'     => 'request_withdrawn',
				'result'     => 'ok',
			)
		);

		return true;
	}

	/**
	 * Admin approves the frozen snapshot. This is a verdict, not an execution.
	 *
	 * @param object $request  Request.
	 * @param int    $reviewer Reviewer id.
	 * @return bool|WP_Error
	 */
	public function approve( object $request, int $reviewer ) {
		$status = (string) $request->status;

		if ( ! in_array( $status, array( self::PENDING, self::NEEDS ), true ) ) {
			return new WP_Error( 'sufm_state', __( '当前状态不能批准。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$frozen = $this->freeze_items( (int) $request->id );

		if ( is_wp_error( $frozen ) ) {
			return $frozen;
		}

		$revision = (int) $request->approval_revision + 1;

		$ok = $this->transition(
			(int) $request->id,
			$status,
			self::APPROVED,
			array(
				'items'             => 'approved',
				'approval_revision' => $revision,
				'reviewer_id'       => $reviewer,
				'reviewed_at'       => Time::now(),
				'approved_at'       => Time::now(),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$this->events->log(
			array(
				'actor_id'          => $reviewer,
				'owner_id'          => (int) $request->owner_id,
				'request_id'        => (int) $request->id,
				'approval_revision' => $revision,
				'subject_id'        => (int) $request->id,
				'action'            => 'request_approved',
				'result'            => 'ok',
			)
		);

		return true;
	}

	/**
	 * Admin rejects a request.
	 *
	 * @param object $request  Request.
	 * @param int    $reviewer Reviewer id.
	 * @param string $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function reject( object $request, int $reviewer, string $reason = '' ) {
		$status = (string) $request->status;

		if ( ! in_array( $status, array( self::PENDING, self::NEEDS ), true ) ) {
			return new WP_Error( 'sufm_state', __( '当前状态不能驳回。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$ok = $this->transition(
			(int) $request->id,
			$status,
			self::REJECTED,
			array(
				'items'             => 'rejected',
				'reviewer_id'       => $reviewer,
				'reviewed_at'       => Time::now(),
				'revocation_reason' => mb_substr( sanitize_textarea_field( $reason ), 0, 500 ),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$this->unlock( (int) $request->id, (int) $request->owner_id );

		$this->events->log(
			array(
				'actor_id'   => $reviewer,
				'owner_id'   => (int) $request->owner_id,
				'request_id' => (int) $request->id,
				'subject_id' => (int) $request->id,
				'action'     => 'request_rejected',
				'result'     => 'ok',
			)
		);

		return true;
	}

	/**
	 * Revoke an approval (authority generation changes, queued work dies).
	 *
	 * @param object $request  Request.
	 * @param int    $reviewer Actor id.
	 * @param string $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function revoke( object $request, int $reviewer, string $reason = '' ) {
		if ( self::APPROVED !== (string) $request->status ) {
			return new WP_Error( 'sufm_state', __( '只有已批准的申请可以撤销。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$revision = (int) $request->approval_revision + 1;

		$ok = $this->transition(
			(int) $request->id,
			self::APPROVED,
			self::REVOKED,
			array(
				'items'             => 'revoked',
				'approval_revision' => $revision,
				'revoked_at'        => Time::now(),
				'revocation_reason' => mb_substr( sanitize_textarea_field( $reason ), 0, 500 ),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$this->unlock( (int) $request->id, (int) $request->owner_id );

		$this->events->log(
			array(
				'actor_id'          => $reviewer,
				'owner_id'          => (int) $request->owner_id,
				'request_id'        => (int) $request->id,
				'approval_revision' => $revision,
				'subject_id'        => (int) $request->id,
				'action'            => 'request_revoked',
				'result'            => 'ok',
			)
		);

		return true;
	}

	/**
	 * Cancel a queued/executing batch's request.
	 *
	 * @param object $request Request.
	 * @param int    $actor   Actor id.
	 * @return bool|WP_Error
	 */
	public function stop( object $request, int $actor ) {
		$status = (string) $request->status;

		if ( ! in_array( $status, array( self::QUEUED, self::EXECUTING, self::APPROVED ), true ) ) {
			return new WP_Error( 'sufm_state', __( '当前状态不能停止。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$revision = (int) $request->approval_revision + 1;

		$ok = $this->transition(
			(int) $request->id,
			$status,
			self::STOPPED,
			array(
				'approval_revision' => $revision,
				'stopped_at'        => Time::now(),
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_state', __( '申请状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		// A stopped request must not keep the object locks alive. Without this the
		// folder/file stays "pending deletion" forever and every later rename,
		// move or delete is refused with sufm_folder_locked (audit A07).
		$this->stop_items( (int) $request->id );
		$this->unlock( (int) $request->id, (int) $request->owner_id );

		$this->events->log(
			array(
				'actor_id'          => $actor,
				'owner_id'          => (int) $request->owner_id,
				'request_id'        => (int) $request->id,
				'approval_revision' => $revision,
				'subject_id'        => (int) $request->id,
				'action'            => 'request_stopped',
				'result'            => 'ok',
			)
		);

		return true;
	}

	/**
	 * Stop every item that has not finished yet.
	 *
	 * Terminal items (done / failed / stopped / revoked) are left untouched so a
	 * stop never rewrites history.
	 *
	 * @param int $request_id Request.
	 * @return int Rows changed.
	 */
	public function stop_items( int $request_id ): int {
		global $wpdb;

		$table = Schema::table( 'delete_request_items' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, updated_at=%s WHERE request_id=%d AND state IN (%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STOPPED,
				Time::now(),
				$request_id,
				self::QUEUED,
				self::EXECUTING
			)
		);
	}

	/**
	 * Compare-and-set one status transition.
	 *
	 * @param int                  $request_id Request.
	 * @param string               $from       Expected status.
	 * @param string               $to         New status.
	 * @param array<string, mixed> $extra      Extra request columns.
	 * @return bool
	 */
	public function transition( int $request_id, string $from, string $to, array $extra = array() ): bool {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$data  = array_merge(
			$extra,
			array(
				'status'     => $to,
				'updated_at' => Time::now(),
			)
		);

		$allowed = array(
			'reviewer_id',
			'reviewed_at',
			'approval_revision',
			'approved_at',
			'revoked_at',
			'revocation_reason',
			'executor_id',
			'confirmed_at',
			'batch_id',
		);

		$set    = array();
		$values = array();

		foreach ( $extra as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}

			$set[]    = "{$column}=" . ( is_int( $value ) ? '%d' : '%s' );
			$values[] = $value;
		}

		$set[]    = 'status=%s';
		$values[] = $to;
		$set[]    = 'updated_at=%s';
		$values[] = Time::now();

		$values[] = $request_id;
		$values[] = $from;

		$sql = "UPDATE {$table} SET " . implode( ',', $set ) . ' WHERE id=%d AND status=%s';

		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return 1 === (int) $result;
	}

	/**
	 * Move all items of a request to a new state.
	 *
	 * @param int    $request_id Request.
	 * @param string $state      Item state.
	 * @return void
	 */
	public function set_items_state( int $request_id, string $state ): void {
		global $wpdb;

		$table = Schema::table( 'delete_request_items' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state=%s, updated_at=%s WHERE request_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state,
				Time::now(),
				$request_id
			)
		);
	}

	/**
	 * Convenience wrapper used by create().
	 *
	 * @param int                  $request_id Request.
	 * @param string               $status     Status.
	 * @param array<string, mixed> $extra      Extra columns.
	 * @return void
	 */
	private function set_status( int $request_id, string $status, array $extra = array() ): void {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$item  = (string) ( $extra['items'] ?? '' );
		unset( $extra['items'] );

		$wpdb->update(
			$table,
			array_merge( $extra, array( 'status' => $status, 'updated_at' => Time::now() ) ),
			array( 'id' => $request_id ),
			null,
			array( '%d' )
		);

		if ( '' !== $item ) {
			$this->set_items_state( $request_id, $item );
		}
	}

	/**
	 * Release object locks held by a request.
	 *
	 * @param int      $request_id Request.
	 * @param int|null $owner_id   Owner of the request; when omitted it is read
	 *                             from the request row so the release stays
	 *                             inside that account's objects.
	 * @return void
	 */
	public function unlock( int $request_id, ?int $owner_id = null ): void {
		global $wpdb;

		$files   = Schema::table( 'files' );
		$folders = Schema::table( 'folders' );

		if ( null === $owner_id || $owner_id <= 0 ) {
			$owner_id = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT owner_id FROM ' . Schema::table( 'delete_requests' ) . ' WHERE id=%d', $request_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		$scoped = $owner_id > 0;

		$files_sql = $scoped
			? $wpdb->prepare( "UPDATE {$files} SET pending_request_id=0 WHERE owner_id=%d AND pending_request_id=%d", $owner_id, $request_id )
			: $wpdb->prepare( "UPDATE {$files} SET pending_request_id=0 WHERE pending_request_id=%d", $request_id );

		$folders_sql = $scoped
			? $wpdb->prepare( "UPDATE {$folders} SET pending_request_id=0 WHERE owner_id=%d AND pending_request_id=%d", $owner_id, $request_id )
			: $wpdb->prepare( "UPDATE {$folders} SET pending_request_id=0 WHERE pending_request_id=%d", $request_id );

		$wpdb->query( $files_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $folders_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Paginate the owner's requests.
	 *
	 * @param int    $owner_id Owner.
	 * @param string $status   Status filter ('' = all).
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public function list_for_owner( int $owner_id, string $status = '', int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table  = Schema::table( 'delete_requests' );
		$where  = 'owner_id=%d';
		$params = array( $owner_id );

		if ( '' !== $status ) {
			$where   .= ' AND status=%s';
			$params[] = $status;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where is assembled from fixed fragments; $params gains one value per fragment.
		$rows  = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where contributes a variable number of placeholders; LIMIT/OFFSET add two fixed ones.
				"SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, array( $per_page, max( 0, ( $page - 1 ) * $per_page ) ) )
			)
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Admin queue with filters.
	 *
	 * @param string $status Status filter.
	 * @param string $search User/login search.
	 * @param int    $page   Page.
	 * @param int    $per    Page size.
	 * @return array{rows:array<int,object>,total:int}
	 */
	public function admin_queue( string $status = '', string $search = '', int $page = 1, int $per = 20 ): array {
		global $wpdb;

		$table  = Schema::table( 'delete_requests' );
		$where  = '1=1';
		$params = array();

		if ( '' !== $status ) {
			$where   .= ' AND r.status=%s';
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$from = "{$table} r LEFT JOIN {$wpdb->users} u ON u.ID=r.owner_id";

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where is assembled from fixed fragments; $params gains one value per fragment.

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where contributes a variable number of placeholders; LIMIT/OFFSET add two fixed ones.
				"SELECT r.* , u.display_name AS owner_name, u.user_login AS owner_login FROM {$from} WHERE {$where} ORDER BY (r.status='pending_review') DESC, r.id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, array( $per, max( 0, ( $page - 1 ) * $per ) ) )
			)
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Sum charged bytes across a request's file items.
	 *
	 * @param int $request_id Request.
	 * @return int
	 */
	public function bytes( int $request_id ): int {
		global $wpdb;

		$table = Schema::table( 'delete_request_items' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(charged_bytes),0) FROM {$table} WHERE request_id=%d AND object_type='file'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$request_id
			)
		);
	}
}
