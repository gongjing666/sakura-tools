<?php
/** Atomic virtual-folder moves, transfers and first adoption. */
declare( strict_types=1 );
namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Support\Db_Transaction;
use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use WP_Error;
defined( 'ABSPATH' ) || exit;

final class Media_Mover {
	public const MAX_BATCH = 100;
	private bool $locking = false;
	/** Strict parsing: never truncate or silently discard an invalid target. */
	public static function ids( $raw ) {
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > self::MAX_BATCH ) {
			return new WP_Error( 'sufm_move_limit', __( '每次请选择1至100个附件。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}
		$ids = array();
		foreach ( $raw as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! ctype_digit( (string) $id ) || (int) $id <= 0 ) {
				return new WP_Error( 'sufm_move_ids', __( '附件编号无效。', 'sakura-imgopt' ), array( 'status' => 400 ) );
			}
			$ids[] = (int) $id;
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	public function preview( $raw, int $owner, int $folder ) {
		$this->locking = false;
		$ids = self::ids( $raw );
		if ( is_wp_error( $ids ) ) { return $ids; }
		if ( ! current_user_can( Capabilities::MANAGE_MEDIA ) ) { return $this->error( 'sufm_forbidden', __( '没有整理媒体的权限。', 'sakura-imgopt' ), 403 ); }
		try {
			$plan = $this->plan( $ids, $owner, $folder );
			return $this->public_plan( $plan );
		} catch ( Media_Move_Failure $e ) { return $e->error; }
	}

	/** Same-owner endpoint; uses the identical transaction and ancestor checks. */
	public function move( $raw, int $owner, int $folder ) {
		return $this->execute( $raw, $owner, $folder, '', '', false, true );
	}

	public function execute( $raw, int $owner, int $folder, string $hash, string $key, bool $confirmed, bool $same_only = false ) {
		global $wpdb;
		$this->locking = false;
		$ids = self::ids( $raw );
		if ( is_wp_error( $ids ) ) { return $ids; }
		if ( ! current_user_can( Capabilities::MANAGE_MEDIA ) ) { return $this->error( 'sufm_forbidden', __( '没有整理媒体的权限。', 'sakura-imgopt' ), 403 ); }
		if ( ! $same_only && ( ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/D', $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) ) {
			return $this->error( 'sufm_move_key', __( '请先预览并提供有效的操作号。', 'sakura-imgopt' ), 400 );
		}
		$actor = get_current_user_id();
		$payload = hash( 'sha256', wp_json_encode( array( $ids, $owner, $folder, $hash, $confirmed ) ) );
		$ops = Schema::table( 'media_operations' );
		$in_transaction = false;
		try {
			$this->transactional_tables();
			Db_Transaction::begin();
			$in_transaction = true;
			if ( ! $same_only ) {
				// The unique key also serializes concurrent retries of this operation.
				$this->sql( $wpdb->prepare( "INSERT IGNORE INTO {$ops} (actor_id,idempotency_key,payload_hash,result_json,notification_status,next_at,last_error,created_at) VALUES (%d,%s,%s,'','pending',%s,'',%s)", $actor, $key, $payload, Time::now(), Time::now() ) );
				$op = $this->row( $wpdb->prepare( "SELECT * FROM {$ops} WHERE actor_id=%d AND idempotency_key=%s FOR UPDATE", $actor, $key ) );
				if ( ! $op || ! hash_equals( (string) $op->payload_hash, $payload ) ) { $this->fail( 'sufm_idempotency_conflict', __( '此操作号已用于另一批文件，请重新预览。', 'sakura-imgopt' ) ); }
				if ( '' !== (string) $op->result_json ) {
					$result = json_decode( (string) $op->result_json, true );
					// A replay still requires current per-attachment authorization.
					foreach ( $ids as $id ) { if ( ! current_user_can( 'edit_post', $id ) ) { $this->fail( 'sufm_forbidden', __( '没有修改该附件的权限。', 'sakura-imgopt' ), 403 ); } }
					if ( ! empty( $result['confirmation_required'] ) && ! current_user_can( Capabilities::TRANSFER ) ) { $this->fail( 'sufm_forbidden', __( '没有转交媒体的权限。', 'sakura-imgopt' ), 403 ); }
					Db_Transaction::commit();
					$result['notification_status'] = (string) $op->notification_status;
					return $result;
				}
			}
			// Collect owner IDs before locking, then lock ledgers in stable order.
			// The second plan is built from current SQL rows after all object locks.
			$first = $this->plan( $ids, $owner, $folder );
			$owners = array( $owner );
			foreach ( $first['items'] as $item ) { if ( $item['source_owner_id'] > 0 && ! $item['adopted'] ) { $owners[] = $item['source_owner_id']; } }
			$owners = array_values( array_unique( $owners ) ); sort( $owners, SORT_NUMERIC );
			$usage = Schema::table( 'usage' );
			foreach ( $owners as $uid ) {
				// Exclusive lock from the first statement (see Folders::write): INSERT
				// IGNORE leaves a shared lock that deadlocks as soon as two sessions
				// upgrade to FOR UPDATE on the same mutex row.
				$this->sql( $wpdb->prepare( "INSERT INTO {$usage} (owner_id,used_bytes,reserved_bytes,revision,updated_at) VALUES (%d,0,0,0,%s) ON DUPLICATE KEY UPDATE owner_id = owner_id", $uid, Time::now() ) );
				$this->row( $wpdb->prepare( "SELECT * FROM {$usage} WHERE owner_id=%d FOR UPDATE", $uid ) );
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$this->rows( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) ORDER BY ID FOR UPDATE", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.
			$this->rows( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders}) ORDER BY post_id,meta_id FOR UPDATE", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.
			$files = Schema::table( 'files' );
			$this->rows( $wpdb->prepare( "SELECT id FROM {$files} WHERE attachment_id IN ({$placeholders}) ORDER BY attachment_id FOR UPDATE", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $ids.
			// Folder locks protect every source/target ancestor from concurrent changes.
			$folders = Schema::table( 'folders' );
			$owner_marks = implode( ',', array_fill( 0, count( $owners ), '%d' ) );
			$this->rows( $wpdb->prepare( "SELECT id FROM {$folders} WHERE owner_id IN ({$owner_marks}) ORDER BY owner_id,id FOR UPDATE", $owners ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $owners.
			$valid_users = $this->rows( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID IN ({$owner_marks}) ORDER BY ID FOR UPDATE", $owners ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $owners.
			if ( ! in_array( $owner, array_map( static fn( $u ) => (int) $u->ID, $valid_users ), true ) ) { $this->fail( 'sufm_owner', __( '目标用户已不存在，请重新预览。', 'sakura-imgopt' ) ); }
			$membership = $this->row( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s FOR UPDATE", $owner, $wpdb->prefix . 'capabilities' ) );
			if ( ! $membership ) { $this->fail( 'sufm_owner', __( '目标用户已退出本站，请重新预览。', 'sakura-imgopt' ) ); }
			$this->locking = true;
			$plan = $this->plan( $ids, $owner, $folder );
			foreach ( $plan['items'] as $item ) { if ( ! $item['adopted'] && ! in_array( $item['source_owner_id'], $owners, true ) ) { $this->fail( 'sufm_move_stale', __( '文件归属已变化，请重新预览。', 'sakura-imgopt' ) ); } }
			if ( $same_only && $plan['confirmation_required'] ) { $this->fail( 'sufm_transfer_required', __( '所选附件需要转交或首次纳管，请预览并确认。', 'sakura-imgopt' ) ); }
			if ( ! $same_only && ! hash_equals( $plan['snapshot_hash'], $hash ) ) { $this->fail( 'sufm_move_stale', __( '文件或目标目录已变化，请重新预览。', 'sakura-imgopt' ) ); }
			if ( $plan['confirmation_required'] && ( ! $confirmed || ! current_user_can( Capabilities::TRANSFER ) ) ) { $this->fail( 'sufm_transfer_confirm', __( '转交或首次纳管需要独立权限及确认。', 'sakura-imgopt' ), 403 ); }
			$result = array( 'operation_id' => $same_only ? 0 : (int) $op->id, 'moved' => array(), 'unchanged' => array(), 'skipped' => array(), 'transferred' => array(), 'adopted' => array(), 'promoted' => array(), 'confirmation_required' => $plan['confirmation_required'], 'owner_id' => $owner, 'folder_id' => $folder, 'outgoing' => $plan['outgoing'], 'incoming_bytes' => $plan['incoming_bytes'], 'actor_id' => $actor, 'notification_owners' => array_values( array_unique( array_merge( array( $owner ), array_map( static fn( $i ) => (int) $i['source_owner_id'], $plan['items'] ) ) ) ), 'created_at' => Time::now(), 'notification_status' => $same_only || ! $plan['confirmation_required'] ? 'not_required' : 'pending' );
			$incoming = (int) $plan['incoming_bytes'];
			foreach ( $plan['outgoing'] as $out ) {
				$affected = $this->sql( $wpdb->prepare( "UPDATE {$usage} SET used_bytes=used_bytes-%d,revision=revision+1,updated_at=%s WHERE owner_id=%d AND used_bytes>=%d", $out['bytes'], Time::now(), $out['owner_id'], $out['bytes'] ) );
				if ( 1 !== $affected ) { $this->fail( 'sufm_ledger_mismatch', __( '来源空间账本与文件不一致，请先核对空间。', 'sakura-imgopt' ) ); }
			}
			if ( $incoming > 0 ) {
				$affected = $this->sql( $wpdb->prepare( "UPDATE {$usage} SET used_bytes=used_bytes+%d,revision=revision+1,updated_at=%s WHERE owner_id=%d AND used_bytes+reserved_bytes+%d<=%d", $incoming, Time::now(), $owner, $incoming, Settings::quota_for( $owner ) ) );
				if ( 1 !== $affected ) { $this->fail( 'sufm_quota', __( '目标用户的剩余空间不足。', 'sakura-imgopt' ) ); }
			}
			foreach ( $plan['items'] as $item ) {
				$id = $item['attachment_id'];
				if ( ! $item['adopted'] && $item['source_owner_id'] === $owner && $item['source_folder_id'] === $folder ) { $result['unchanged'][] = $id; continue; }
				if ( $item['adopted'] ) {
					$this->insert( $files, array( 'attachment_id' => $id, 'owner_id' => $owner, 'folder_id' => $folder, 'display_name' => $item['name'], 'source' => 'media', 'lifecycle' => 'active', 'storage_state' => 'local', 'charged_bytes' => $item['charged_bytes'], 'manifest_version' => 1, 'version' => 1, 'created_at' => Time::now(), 'updated_at' => Time::now() ) );
					$file_id = (int) $wpdb->insert_id;
					foreach ( $item['_artifacts'] as $artifact ) {
						$this->insert( Schema::table( 'artifacts' ), array_merge( $artifact, array( 'file_id' => $file_id, 'generation' => 1, 'artifact_key' => 'media:' . $id . ':' . hash( 'sha256', $artifact['local_path'] ), 'cleanup_state' => 'present', 'created_at' => Time::now(), 'updated_at' => Time::now() ) ) );
					}
					$result['adopted'][] = $id;
				} else {
					$affected = $this->sql( $wpdb->prepare( "UPDATE {$files} SET owner_id=%d,folder_id=%d,manifest_version=GREATEST(manifest_version,%d),version=version+1,updated_at=%s WHERE attachment_id=%d AND owner_id=%d AND version=%d AND pending_request_id=0 AND lifecycle='active'", $owner, $folder, $item['_promote'] ? 1 : 0, Time::now(), $id, $item['source_owner_id'], $item['version'] ) );
					if ( 1 !== $affected ) { $this->fail( 'sufm_move_stale', __( '文件状态已变化，请重新预览。', 'sakura-imgopt' ) ); }
					if ( $item['_promote'] ) {
						// Persist the inventory this transfer just proved from disk so the
						// next transfer reads rows instead of re-hashing every size again.
						$file_id = (int) $this->value( $wpdb->prepare( "SELECT id FROM {$files} WHERE attachment_id=%d", $id ) );
						foreach ( $item['_artifacts'] as $artifact ) {
							$this->insert( Schema::table( 'artifacts' ), array_merge( $artifact, array( 'file_id' => $file_id, 'generation' => 1, 'artifact_key' => 'media:' . $id . ':' . hash( 'sha256', $artifact['local_path'] ), 'cleanup_state' => 'present', 'created_at' => Time::now(), 'updated_at' => Time::now() ) ) );
						}
						$result['promoted'][] = $id;
					}
				}
				if ( $item['adopted'] || $item['source_owner_id'] !== $owner ) {
					// SQL participates in this transaction; WP post-save hooks do not.
					$this->sql( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author=%d WHERE ID=%d AND post_type='attachment'", $owner, $id ) );
					$result['transferred'][] = $id;
				}
				$this->insert( Schema::table( 'events' ), array( 'actor_id' => $actor, 'owner_id' => $owner, 'operation_id' => 'media:' . $result['operation_id'], 'subject_id' => $id, 'action' => $item['adopted'] ? 'media_adopt' : 'media_move', 'result' => 'succeeded', 'safe_detail' => wp_json_encode( array( 'previous_author' => $item['post_author'], 'from_owner' => $item['source_owner_id'], 'from_folder' => $item['source_folder_id'], 'to_owner' => $owner, 'to_folder' => $folder, 'bytes' => $item['charged_bytes'], 'version' => $item['version'] ) ), 'created_at' => Time::now() ) );
				$result['moved'][] = $id;
			}
			if ( ! $same_only ) { $this->sql( $wpdb->prepare( "UPDATE {$ops} SET result_json=%s,notification_status=%s WHERE id=%d", wp_json_encode( $result ), $result['notification_status'], (int) $op->id ) ); }
			Db_Transaction::commit(); $in_transaction = false;
			foreach ( $ids as $id ) { clean_post_cache( $id ); }
			return $result;
		} catch ( Media_Move_Failure $e ) {
			if ( $in_transaction ) { Db_Transaction::rollback(); }
			return $e->error;
		} catch ( \Throwable $e ) {
			if ( $in_transaction ) { Db_Transaction::rollback(); }
			return $this->error( 'sufm_move_failed', __( '整理失败，未提交本次批量变更，请重试。', 'sakura-imgopt' ), 500 );
		}
	}

	/** Re-read every fact; do not trust values sent by the browser. */
	private function plan( array $ids, int $owner, int $folder ): array {
		global $wpdb;
		if ( $owner < 1 || $folder < 0 || ! get_userdata( $owner ) || ! is_user_member_of_blog( $owner ) ) { $this->fail( 'sufm_owner', __( '目标必须是本站有效用户。', 'sakura-imgopt' ), 400 ); }
		$target = $this->chain( $owner, $folder );
		$items = array(); $outgoing = array(); $incoming = 0; $additional = 0; $required = false;
		$files = Schema::table( 'files' );
		foreach ( $ids as $id ) {
			$post = $this->row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d AND post_type='attachment'", $id ) );
			if ( ! current_user_can( 'edit_post', $id ) ) { $this->fail( 'sufm_forbidden', __( '没有修改所选附件的权限。', 'sakura-imgopt' ), 403 ); }
			$cap_post = get_post( $id );
			if ( $post && $cap_post && ( (int) $post->post_author !== (int) $cap_post->post_author || $post->post_status !== $cap_post->post_status || $post->post_type !== $cap_post->post_type ) ) { clean_post_cache( $id ); $this->fail( 'sufm_move_stale', __( '附件权限上下文已变化，请重新预览。', 'sakura-imgopt' ) ); }
			if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) { $this->fail( 'sufm_attachment_missing', __( '所选附件不存在或尚未完成上传。', 'sakura-imgopt' ) ); }
			$file = $this->row( $wpdb->prepare( "SELECT * FROM {$files} WHERE attachment_id=%d", $id ) );
			$artifacts = array(); $source_chain = array(); $promote = false;
			if ( $file ) {
				if ( 'active' !== $file->lifecycle || (int) $file->pending_request_id > 0 || in_array( $file->storage_state, array( 'uploading', 'staging', 'pending', 'failed' ), true ) ) { $this->fail( 'sufm_file_locked', __( '所选文件尚未完成上传或正在删除申请中。', 'sakura-imgopt' ) ); }
				if ( (int) $post->post_author !== (int) $file->owner_id ) { $this->fail( 'sufm_owner_mismatch', __( '附件作者与文件归属不一致，请先核对。', 'sakura-imgopt' ) ); }
				$source_chain = $this->chain( (int) $file->owner_id, (int) $file->folder_id );
				$bytes = (int) $file->charged_bytes;
			} else {
				$artifacts = ( new Adoption_Manifest() )->build( $id );
				if ( is_wp_error( $artifacts ) ) { throw new Media_Move_Failure( $artifacts ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception payload, never printed.
				$bytes = array_sum( array_column( $artifacts, 'logical_bytes' ) );
			}
			$source = $file ? (int) $file->owner_id : (int) $post->post_author;
			$transfer = ! $file || $source !== $owner;
			if ( $transfer && $file ) {
				// Transfers require a recorded complete charge, never guessed zero bytes.
				$recorded = $this->rows( $wpdb->prepare( 'SELECT artifact_key,generation,logical_bytes,local_path,remote_context,checksum,cleanup_state FROM ' . Schema::table( 'artifacts' ) . ' WHERE file_id=%d ORDER BY artifact_key', (int) $file->id ) );
				$proven = (int) $file->manifest_version >= 1 && $recorded && $bytes > 0 && array_sum( array_map( static fn( $a ) => (int) $a->logical_bytes, $recorded ) ) === $bytes;
				if ( $proven ) {
					foreach ( $recorded as $artifact ) {
						if ( 'present' !== $artifact->cleanup_state ) { $this->fail( 'sufm_manifest_unproven', __( '该文件制品状态不完整，请先核对再转交。', 'sakura-imgopt' ) ); }
					}
					$artifacts = array_map( static fn( $a ) => (array) $a, $recorded );
				} elseif ( $recorded ) {
					// Artifact rows exist but do not add up to the charge: the ledger
					// and the artifacts disagree, so refuse instead of overwriting.
					$this->fail( 'sufm_manifest_unproven', __( '该文件尚无完整空间与制品清单，请先核对文件空间再转交。', 'sakura-imgopt' ) );
				} else {
					// Ledger rows written before the manifest existed (uploads, sync
					// adoption, the 1.18.42 backlog fill) carry a charge but no
					// artifact proof at all, so they could never be handed to another
					// user. Re-derive the inventory from disk once and require it to
					// reproduce the recorded charge exactly; a mismatch stays fatal
					// because it means the ledger and the disk disagree.
					$rebuilt = ( new Adoption_Manifest() )->build( $id );
					if ( is_wp_error( $rebuilt ) ) { throw new Media_Move_Failure( $rebuilt ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception payload, never printed.
					if ( array_sum( array_column( $rebuilt, 'logical_bytes' ) ) !== $bytes ) { $this->fail( 'sufm_manifest_unproven', __( '该文件尚无完整空间与制品清单，请先核对文件空间再转交。', 'sakura-imgopt' ) ); }
					$artifacts = $rebuilt;
					$promote  = true;
				}
			}
			if ( $transfer ) {
				$required = true; $incoming += $bytes; $additional++;
				if ( $file ) { $outgoing[ $source ] = ( $outgoing[ $source ] ?? 0 ) + $bytes; }
			}
			$items[] = array( 'attachment_id' => $id, 'name' => $file ? (string) $file->display_name : (string) $post->post_title, 'source_owner_id' => $source, 'source_folder_id' => $file ? (int) $file->folder_id : 0, 'version' => $file ? (int) $file->version : 0, 'manifest_version' => $file ? (int) $file->manifest_version : 0, 'charged_bytes' => $bytes, 'adopted' => ! $file, 'post_author' => (int) $post->post_author, '_artifacts' => $artifacts, '_promote' => $promote, '_chain' => $source_chain, '_post_modified' => (string) $post->post_modified_gmt );
		}
		$usage = Schema::table( 'usage' );
		$balance = $this->row( $wpdb->prepare( "SELECT * FROM {$usage} WHERE owner_id=%d", $owner ) );
		$used = $balance ? (int) $balance->used_bytes : 0;
		$reserved = $balance ? (int) $balance->reserved_bytes : 0;
		$quota = Settings::quota_for( $owner );
		if ( $incoming > 0 && $used + $reserved + $incoming > $quota ) { $this->fail( 'sufm_quota', __( '目标用户的剩余空间不足。', 'sakura-imgopt' ) ); }
		$count = (int) $this->value( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE owner_id=%d AND lifecycle<>'purged'", $owner ) );
		$reservations = Schema::table( 'reservations' );
		$in_flight = (int) $this->value( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations} WHERE owner_id=%d AND state='reserved'", $owner ) );
		if ( $additional && $count + $in_flight + $additional > (int) Settings::get()['max_user_files'] ) { $this->fail( 'sufm_file_limit', __( '目标用户的文件数量（含在途上传）超过上限。', 'sakura-imgopt' ) ); }
		$outs = array(); ksort( $outgoing, SORT_NUMERIC );
		foreach ( $outgoing as $uid => $bytes ) { $outs[] = array( 'owner_id' => (int) $uid, 'bytes' => $bytes ); }
		$hash = hash( 'sha256', wp_json_encode( array( 'blog' => get_current_blog_id(), 'actor' => get_current_user_id(), 'owner' => $owner, 'folder' => $folder, 'target' => $target, 'items' => $items ) ) );
		return array( 'snapshot_hash' => $hash, 'confirmation_required' => $required, 'items' => $items, 'incoming_bytes' => $incoming, 'target_used_bytes' => $used, 'target_reserved_bytes' => $reserved, 'target_quota_bytes' => $quota, 'outgoing' => $outs, 'owner_id' => $owner, 'folder_id' => $folder );
	}

	/** Include all ancestor revisions in the preview digest and validate freezes. */
	private function chain( int $owner, int $folder ): array {
		global $wpdb;
		$chain = array(); $seen = array(); $table = Schema::table( 'folders' );
		while ( $folder > 0 ) {
			if ( isset( $seen[ $folder ] ) || count( $chain ) > 64 ) { $this->fail( 'sufm_folder_cycle', __( '目录层级无效。', 'sakura-imgopt' ) ); }
			$seen[ $folder ] = true;
			$row = $this->row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d AND owner_id=%d", $folder, $owner ) );
			if ( ! $row || 'active' !== $row->lifecycle || (int) $row->pending_request_id > 0 ) { $this->fail( 'sufm_folder_locked', __( '来源或目标目录不存在或正在删除申请中。', 'sakura-imgopt' ) ); }
			$chain[] = array( 'id' => (int) $row->id, 'parent_id' => (int) $row->parent_id, 'version' => (int) $row->version, 'tree_version' => (int) $row->tree_version );
			$folder = (int) $row->parent_id;
		}
		return $chain;
	}
	private function public_plan( array $plan ): array {
		foreach ( $plan['items'] as &$item ) { foreach ( array_keys( $item ) as $key ) { if ( str_starts_with( $key, '_' ) ) { unset( $item[ $key ] ); } } } unset( $item );
		return $plan;
	}
	/** Fail closed on nontransactional legacy tables instead of pretending rollback works. */
	private function transactional_tables(): void {
		global $wpdb;
		$tables = array( $wpdb->posts, Schema::table( 'files' ), Schema::table( 'folders' ), Schema::table( 'usage' ), Schema::table( 'artifacts' ), Schema::table( 'events' ), Schema::table( 'media_operations' ), $wpdb->postmeta, Schema::table( 'reservations' ), $wpdb->users, $wpdb->usermeta );
		foreach ( $tables as $table ) {
			$engine = $this->value( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( 'INNODB' !== strtoupper( (string) $engine ) ) { $this->fail( 'sufm_transaction_required', __( '媒体整理需要InnoDB事务表，请先完成数据库升级。', 'sakura-imgopt' ), 503 ); }
		}
	}
	private function insert( string $table, array $data ): void { global $wpdb; if ( false === $wpdb->insert( $table, $data ) ) { $this->fail( 'sufm_move_sql', __( '写入失败，未提交本次批量变更。', 'sakura-imgopt' ), 500 ); } }
	private function sql( string $sql ): int { global $wpdb; $result = $wpdb->query( $sql ); if ( false === $result ) { $this->fail( 'sufm_move_sql', __( '数据库操作失败，未提交本次批量变更。', 'sakura-imgopt' ), 500 ); } return (int) $result; }
	private function row( string $sql ) { global $wpdb; $result = $wpdb->get_row( $this->locking ? $sql . ' FOR UPDATE' : $sql ); if ( $wpdb->last_error ) { $this->fail( 'sufm_move_sql', __( '读取文件状态失败。', 'sakura-imgopt' ), 500 ); } return $result; }
	private function rows( string $sql ): array { global $wpdb; $result = $wpdb->get_results( $this->locking && ! str_ends_with( $sql, 'FOR UPDATE' ) ? $sql . ' FOR UPDATE' : $sql ); if ( $wpdb->last_error ) { $this->fail( 'sufm_move_sql', __( '读取文件状态失败。', 'sakura-imgopt' ), 500 ); } return (array) $result; }
	private function value( string $sql ) { global $wpdb; $result = $wpdb->get_var( $this->locking && ! str_contains( $sql, 'information_schema' ) ? $sql . ' FOR UPDATE' : $sql ); if ( $wpdb->last_error ) { $this->fail( 'sufm_move_sql', __( '读取文件状态失败。', 'sakura-imgopt' ), 500 ); } return $result; }
	private function error( string $code, string $message, int $status = 409 ): WP_Error { return new WP_Error( $code, $message, array( 'status' => $status ) ); }
	private function fail( string $code, string $message, int $status = 409 ): void { throw new Media_Move_Failure( $this->error( $code, $message, $status ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception payload, never printed.
}
