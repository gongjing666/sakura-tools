<?php
/**
 * Managed file rows (one per attachment uploaded through this module).
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Repositories;

use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A row is the plugin's record of a managed file: either uploaded through the
 * module (`source = 'userfiles'`) or adopted from the media library on demand
 * (`source = 'media'`). Both are managed; rows for any other attachment never
 * exist.
 */
final class Files {

	/**
	 * Upload source marker; nothing else is managed.
	 */
	public const SOURCE = 'userfiles';

	/**
	 * Source marker for an attachment adopted from the WordPress media library.
	 */
	public const SOURCE_MEDIA = 'media';

	/**
	 * MIME type groups used by the type tabs.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function type_groups(): array {
		return array(
			'image'    => array( 'image/' ),
			'document' => array( 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			'archive'  => array( 'application/zip', 'application/x-zip-compressed' ),
			'video'    => array( 'video/' ),
			'audio'    => array( 'audio/' ),
		);
	}

	/**
	 * Map a MIME type onto the type-tab group stored on the row.
	 *
	 * Storing the tab on the row keeps the category filter an index range: the
	 * wp_posts join stays only for the URL of the rows a page renders.
	 *
	 * @param string $mime Attachment MIME type.
	 * @return string One of the type_groups() keys, or 'other'.
	 */
	public static function mime_group_for( string $mime ): string {
		$mime = strtolower( trim( $mime ) );

		if ( '' === $mime ) {
			return 'other';
		}

		foreach ( self::type_groups() as $group => $needles ) {
			foreach ( $needles as $needle ) {
				if ( '/' === substr( $needle, -1 ) ) {
					if ( str_starts_with( $mime, $needle ) ) {
						return $group;
					}
					continue;
				}

				if ( $mime === $needle ) {
					return $group;
				}
			}
		}

		return 'other';
	}

	/**
	 * Fill mime_group for rows written before the column existed.
	 *
	 * One bounded batch per call, oldest id first, so the caller can loop from
	 * install or the minute tick without ever holding a long table scan.
	 *
	 * @param int $limit Maximum rows to fill in this call.
	 * @return int Rows updated.
	 */
	public static function backfill_mime_group( int $limit = 500 ): int {
		global $wpdb;

		$table = Schema::table( 'files' );
		$limit = max( 1, min( 5000, $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT f.id, p.post_mime_type FROM {$table} f INNER JOIN {$wpdb->posts} p ON p.ID = f.attachment_id WHERE f.mime_group = '' ORDER BY f.id ASC LIMIT %d",
				$limit
			)
		);

		if ( ! is_array( $rows ) || ! $rows ) {
			return 0;
		}

		$buckets = array();
		foreach ( $rows as $row ) {
			$buckets[ self::mime_group_for( (string) $row->post_mime_type ) ][] = (int) $row->id;
		}

		$updated = 0;
		foreach ( $buckets as $group => $ids ) {
			$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$updated += (int) $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table} SET mime_group = %s WHERE mime_group = '' AND id IN ({$marks})",
					array_merge( array( $group ), $ids )
				)
			);
		}

		return $updated;
	}

	/**
	 * Row by id, scoped to the owner.
	 *
	 * @param int $owner_id Owner.
	 * @param int $file_id  Row id.
	 * @return object|null
	 */
	public function get( int $owner_id, int $file_id ): ?object {
		global $wpdb;

		if ( $file_id <= 0 ) {
			return null;
		}

		$table = Schema::table( 'files' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id=%d AND owner_id=%d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$file_id,
				$owner_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Row by attachment id.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return object|null
	 */
	public function get_by_attachment( int $attachment_id ): ?object {
		global $wpdb;

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$table = Schema::table( 'files' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE attachment_id=%d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Character bigrams of a display name, the units of the search index.
	 *
	 * Every adjacent character pair of the name becomes one token. A search
	 * term then requires all of its own bigrams, which turns a substring test
	 * into an index lookup instead of a scan over every name.
	 *
	 * @param string $name Display name.
	 * @return array<int, string> Unique bigrams, first-seen order.
	 */
	public static function tokens_for( string $name ): array {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		$name = trim( $name );

		if ( '' === $name ) {
			return array();
		}

		$chars = preg_split( '//u', $name, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $chars ) || count( $chars ) < 2 ) {
			return array();
		}

		$tokens = array();
		$last   = count( $chars ) - 1;

		for ( $i = 0; $i < $last; $i++ ) {
			$tokens[] = $chars[ $i ] . $chars[ $i + 1 ];
		}

		// array_unique keeps the first-seen order and the string type: a
		// numeric bigram such as "21" must stay a string, not become an int key.
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Replace the token rows of one file.
	 *
	 * @param int    $file_id Row id.
	 * @param string $name    Display name.
	 * @return void
	 */
	private static function sync_tokens( int $file_id, string $name ): void {
		global $wpdb;

		$table = Schema::table( 'file_tokens' );

		$wpdb->delete( $table, array( 'file_id' => $file_id ), array( '%d' ) );

		$tokens = self::tokens_for( $name );

		if ( ! $tokens ) {
			return;
		}

		$values = array();

		foreach ( $tokens as $token ) {
			$values[] = $wpdb->prepare( '(%d,%s)', $file_id, $token );
		}

		$wpdb->query( "INSERT IGNORE INTO {$table} (file_id,token) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Is the token index complete enough to trust as a prefilter?
	 *
	 * Two conditions: the schema that owns the table is installed, and the
	 * backfill watermark has caught up with the newest row. A row written by a
	 * path that does not tokenise immediately (bulk media import) therefore
	 * keeps search exact: the caller falls back to the plain LIKE scan until
	 * the next sweep indexes it.
	 *
	 * @return bool
	 */
	private static function token_index_ready(): bool {
		global $wpdb;

		if ( Schema::VERSION !== (string) get_option( Schema::VERSION_OPTION, '' ) ) {
			return false;
		}

		$files = Schema::table( 'files' );
		$after = (int) get_option( 'sufm_tokens_after', 0 );
		$max   = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$files}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $max > 0 && $max <= $after;
	}

	/**
	 * Pick a bigram whose postings are few enough to start a join from.
	 *
	 * The probe is bounded: the count stops at the limit, so a common bigram
	 * costs a short index dive instead of a full posting count. When nothing
	 * is selective the caller keeps the plain LIKE scan, which costs exactly
	 * what it did before the token index existed.
	 *
	 * @param array<int, string> $tokens Search bigrams.
	 * @return string A rare token, or '' when none is.
	 */
	private static function rare_token( array $tokens ): string {
		global $wpdb;

		$table = Schema::table( 'file_tokens' );
		$limit = 1500;
		$probe = 0;

		foreach ( $tokens as $token ) {
			if ( ++$probe > 4 ) {
				break;
			}

			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE token = %s LIMIT %d) probe", $token, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $found < $limit ) {
				return (string) $token;
			}
		}

		return '';
	}

	/**
	 * Fill the token index for rows written before it existed.
	 *
	 * Watermark based: each call takes the next batch of ids and advances
	 * `sufm_tokens_after`, so install or the minute tick can loop without a
	 * long scan and without reprocessing rows.
	 *
	 * @param int $limit Maximum rows per call.
	 * @return int Rows processed.
	 */
	public static function backfill_tokens( int $limit = 500 ): int {
		global $wpdb;

		$table = Schema::table( 'files' );
		$limit = max( 1, min( 5000, $limit ) );
		$after = (int) get_option( 'sufm_tokens_after', 0 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,display_name FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$after,
				$limit
			)
		);

		if ( ! $rows ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			self::sync_tokens( (int) $row->id, (string) $row->display_name );
		}

		update_option( 'sufm_tokens_after', (int) $rows[ count( $rows ) - 1 ]->id, false );

		return count( $rows );
	}

	/**
	 * Insert one managed file row.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return object|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$table = Schema::table( 'files' );
		$now   = Time::now();

		$ok = $wpdb->insert(
			$table,
			array(
				'attachment_id'  => (int) ( $data['attachment_id'] ?? 0 ),
				'owner_id'       => (int) ( $data['owner_id'] ?? 0 ),
				'folder_id'      => (int) ( $data['folder_id'] ?? 0 ),
				'display_name'   => (string) ( $data['display_name'] ?? '' ),
				'mime_group'     => self::mime_group_for( (string) ( $data['mime'] ?? get_post_mime_type( (int) ( $data['attachment_id'] ?? 0 ) ) ) ),
				'source'         => (string) ( $data['source'] ?? self::SOURCE ),
				'lifecycle'      => 'active',
				'storage_state'  => (string) ( $data['storage_state'] ?? 'local' ),
				'charged_bytes'  => (int) ( $data['charged_bytes'] ?? 0 ),
				'manifest_version' => (int) ( $data['manifest_version'] ?? 1 ),
				'version'        => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_file_conflict', __( '记录上传文件失败，请重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$row = $this->get_by_attachment( (int) ( $data['attachment_id'] ?? 0 ) );

		if ( $row ) {
			self::sync_tokens( (int) $row->id, (string) $row->display_name );
		}

		return $row;
	}

	/**
	 * Rename a managed file the owner may still change.
	 *
	 * @param int    $owner_id Owner.
	 * @param int    $file_id  Row id.
	 * @param string $name     Display name.
	 * @return object|WP_Error
	 */
	public function rename( int $owner_id, int $file_id, string $name ) {
		global $wpdb;

		$row = $this->get( $owner_id, $file_id );
		if ( ! $row ) {
			return new WP_Error( 'sufm_file_missing', __( '文件不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( 'active' !== (string) $row->lifecycle || (int) $row->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_file_locked', __( '该文件正在删除申请中，暂不能修改。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$name = trim( wp_strip_all_tags( $name ) );
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		if ( '' === $name ) {
			return new WP_Error( 'sufm_file_name', __( '请输入文件显示名。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120, 'UTF-8' ) : substr( $name, 0, 120 );

		$table = Schema::table( 'files' );
		$updated = $wpdb->update(
			$table,
			array(
				'display_name' => $name,
				'version'      => (int) $row->version + 1,
				'updated_at'   => Time::now(),
			),
			array( 'id' => $file_id, 'owner_id' => $owner_id, 'version' => (int) $row->version, 'pending_request_id' => 0, 'lifecycle' => 'active' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		if ( 1 !== $updated ) { return new WP_Error( 'sufm_file_stale', __( '文件状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }

		self::sync_tokens( $file_id, $name );

		return $this->get( $owner_id, $file_id );
	}

	/**
	 * Move a file into another folder the owner owns.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $file_id   Row id.
	 * @param int $folder_id Target folder (0 = root).
	 * @return object|WP_Error
	 */
	public function move( int $owner_id, int $file_id, int $folder_id, Folders $folders ) {
		return $folders->with_owner_write( $owner_id, fn() => $this->move_locked( $owner_id, $file_id, $folder_id, $folders ) );
	}

	/** Source and target are re-read only after the shared owner/files/folders locks. */
	private function move_locked( int $owner_id, int $file_id, int $folder_id, Folders $folders ) {
		global $wpdb;

		// Lock the target folder before reading anything, then the source folder
		// once the row tells us where it lives: the re-read below must not race a
		// concurrent approve/delete, and we do not need the whole tenant locked.
		$folders->lock_rows( $owner_id, array( $folder_id ) );

		$row = $this->get( $owner_id, $file_id );
		if ( ! $row ) {
			return new WP_Error( 'sufm_file_missing', __( '文件不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$folders->lock_rows( $owner_id, array( (int) $row->folder_id ) );

		if ( 'active' !== (string) $row->lifecycle || (int) $row->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_file_locked', __( '该文件正在删除申请中，暂不能移动。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( $folder_id > 0 && ! $folders->get( $owner_id, $folder_id ) ) {
			return new WP_Error( 'sufm_folder_missing', __( '目标文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		foreach ( array( (int) $row->folder_id, $folder_id ) as $check ) {
			if ( $check < 0 ) { return new WP_Error( 'sufm_folder_missing', __( '目标文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ); }
			$chain = $folders->breadcrumb( $owner_id, $check );
			if ( $check > 0 && ( ! $chain || (int) $chain[0]->parent_id !== 0 ) ) { return new WP_Error( 'sufm_folder_locked', __( '目录层级已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			foreach ( $chain as $ancestor ) {
				if ( (int) $ancestor->pending_request_id > 0 || 'active' !== $ancestor->lifecycle ) { return new WP_Error( 'sufm_folder_locked', __( '来源或目标目录正在删除申请中，暂不能移动。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			}
		}

		$table = Schema::table( 'files' );
		$updated = $wpdb->update(
			$table,
			array(
				'folder_id'  => $folder_id,
				'version'    => (int) $row->version + 1,
				'updated_at' => Time::now(),
			),
			array( 'id' => $file_id, 'owner_id' => $owner_id, 'version' => (int) $row->version, 'pending_request_id' => 0, 'lifecycle' => 'active' ),
			array( '%d', '%d', '%s' ),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		if ( 1 !== $updated ) { return new WP_Error( 'sufm_file_stale', __( '文件状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
		$result = $this->get( $owner_id, $file_id );
		if ( ! $result || $wpdb->last_error ) { return new WP_Error( 'sufm_file_stale', __( '文件状态已变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
		return $result;
	}

	/**
	 * List managed files with keyset pagination.
	 *
	 * @param int                  $owner_id Owner.
	 * @param array<string, mixed> $args     folder_id, all_folders, type, search, cursor, per_page.
	 * @return array<int, object>
	 */
	public function query( int $owner_id, array $args ): array {
		global $wpdb;

		$table    = Schema::table( 'files' );
		$folder   = (int) ( $args['folder_id'] ?? 0 );
		$type     = (string) ( $args['type'] ?? '' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$cursor   = (int) ( $args['cursor'] ?? 0 );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 30 ) ) );

		$where  = array( 'f.owner_id = %d', "f.lifecycle = 'active'" );
		$params = array( $owner_id );

		// Search and category tabs span the whole cabinet: a file lives in the
		// folder the user filed it in, so finding it must not depend on which
		// folder happens to be open.
		if ( empty( $args['all_folders'] ) ) {
			$where[]  = 'f.folder_id = %d';
			$params[] = $folder;
		}

		$search_from   = '';
		$search_params = array();

		if ( '' !== $search ) {
			$rare = self::token_index_ready() ? self::rare_token( self::tokens_for( $search ) ) : '';

			if ( '' !== $rare ) {
				// One bigram with few postings is enough to start the scan from:
				// the LIKE below is still the exact check, so the join only has
				// to make the candidate set small. Starting from the token keeps
				// the work proportional to the matches, not to the cabinet.
				$tokens_table  = Schema::table( 'file_tokens' );
				$search_from   = "(SELECT file_id FROM {$tokens_table} WHERE token = %s) tm STRAIGHT_JOIN {$table} f ON f.id = tm.file_id";
				$search_params = array( $rare );
			}

			$where[]  = 'f.display_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$type_clause = $this->type_clause( $type, $params );
		if ( '' !== $type_clause ) {
			$where[] = $type_clause;
		}

		if ( $cursor > 0 ) {
			$where[]  = 'f.id < %d';
			$params[] = $cursor;
		}

		// Only the columns the listing actually renders. `f.*` would drag the
		// manifest/version bookkeeping through every row for no reader.
		// `folder_name` rides along so a cross-folder listing can name the
		// folder a file came from without a second round trip per row.
		$folders = Schema::table( 'folders' );
		$columns = 'f.id,f.folder_id,f.display_name,f.charged_bytes,f.pending_request_id,f.created_at,fd.name AS folder_name';
		$from    = '' !== $search_from ? $search_from : "{$table} f";
		$sql     = "SELECT {$columns}, p.post_mime_type, p.guid FROM {$from} INNER JOIN {$wpdb->posts} p ON p.ID = f.attachment_id LEFT JOIN {$folders} fd ON fd.id = f.folder_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY f.id DESC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $search_params, $params, array( $per_page ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count live files for a user.
	 *
	 * @param int $owner_id Owner.
	 * @return int
	 */
	public function count( int $owner_id, ?int $folder_id = null ): int {
		global $wpdb;

		$table = Schema::table( 'files' );

		if ( null === $folder_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND lifecycle='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$owner_id
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND lifecycle='active' AND folder_id=%d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$folder_id
			)
		);
	}

	/**
	 * Count live files for many owners in one query (audit A08).
	 *
	 * @param array<int> $owner_ids Owner ids.
	 * @return array<int, int> Owner id => count.
	 */
	public function counts_by_owner( array $owner_ids ): array {
		global $wpdb;

		$owner_ids = array_values( array_unique( array_filter( array_map( 'intval', $owner_ids ) ) ) );

		if ( ! $owner_ids ) {
			return array();
		}

		$table = Schema::table( 'files' );
		$marks = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT owner_id, COUNT(*) AS total FROM {$table} WHERE lifecycle='active' AND owner_id IN ({$marks}) GROUP BY owner_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN list and arguments both derive from $owner_ids.
				$owner_ids
			)
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row->owner_id ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Update lifecycle / storage state.
	 *
	 * @param int    $file_id   Row id.
	 * @param string $lifecycle New lifecycle.
	 * @param string $state     Optional storage state.
	 * @return bool False when the write failed.
	 */
	public function set_lifecycle( int $file_id, string $lifecycle, string $state = '' ): bool {
		global $wpdb;

		$table = Schema::table( 'files' );
		$set   = array(
			'lifecycle'  => $lifecycle,
			'updated_at' => Time::now(),
		);
		$fmt   = array( '%s', '%s' );

		if ( '' !== $state ) {
			$set['storage_state'] = $state;
			$fmt[]                = '%s';
		}

		$ok = false !== $wpdb->update( $table, $set, array( 'id' => $file_id ), $fmt, array( '%d' ) );

		if ( $ok && 'purged' === $lifecycle ) {
			$wpdb->delete( Schema::table( 'file_tokens' ), array( 'file_id' => $file_id ), array( '%d' ) );
		}

		return $ok;
	}

	/**
	 * Every live file under a folder, including subfolders (for a snapshot).
	 *
	 * @param int     $owner_id  Owner.
	 * @param int     $folder_id Root folder.
	 * @param Folders $folders   Folder repository.
	 * @return array<int, object>
	 */
	public function recursive_files( int $owner_id, int $folder_id, Folders $folders ): array {
		$ids   = array( $folder_id );
		$queue = array( $folder_id );
		$seen  = array( $folder_id => true );

		while ( $queue ) {
			$current = (int) array_shift( $queue );
			foreach ( $folders->children( $owner_id, $current ) as $child ) {
				$id = (int) $child->id;
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$ids[]       = $id;
				$queue[]     = $id;
			}
		}

		global $wpdb;

		$table        = Schema::table( 'files' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $owner_id ), $ids );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id=%d AND lifecycle='active' AND folder_id IN ({$placeholders}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * SQL fragment selecting one MIME group; empty for the "all" tab.
	 *
	 * The group is a column on the row (1.5.0), so this is an equality test the
	 * owner/group index can serve. The placeholder is appended to the caller's
	 * parameter list.
	 *
	 * @param string            $type   Group key.
	 * @param array<int, mixed> $params Parameter list, by reference.
	 * @return string
	 */
	private function type_clause( string $type, array &$params ): string {
		if ( ! isset( self::type_groups()[ $type ] ) ) {
			return '';
		}

		$params[] = $type;

		return 'f.mime_group = %s';
	}
}
