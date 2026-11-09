<?php
/**
 * Virtual folder storage.
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
 * Folders are virtual: they never move a file on disk and never change its URL.
 *
 * `active_slot` follows the blueprint: 0 for the live row and the row id for a
 * historical one, so the UNIQUE (owner,parent,name_key,active_slot) key allows a
 * deleted name to be reused while the old row stays as evidence.
 */
final class Folders {

	/**
	 * Maximum accepted display name length.
	 */
	private const MAX_NAME = 100;

	/**
	 * One folder owned by the user, or null.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Folder id.
	 * @return object|null
	 */
	public function get( int $owner_id, int $folder_id ): ?object {
		global $wpdb;

		if ( $folder_id <= 0 ) {
			return null;
		}

		$table = Schema::table( 'folders' );
		$row   = $this->db_get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id=%d AND owner_id=%d AND lifecycle='active' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$folder_id,
				$owner_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Direct children of a folder, ordered by name.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $parent_id Parent id (0 = root).
	 * @return array<int, object>
	 */
	public function children( int $owner_id, int $parent_id ): array {
		global $wpdb;

		$table = Schema::table( 'folders' );
		$rows  = $this->db_get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id=%d AND parent_id=%d AND lifecycle='active' ORDER BY name_key ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$parent_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Direct children of a folder together with their live file counts.
	 *
	 * Folds the count pass into the child listing so a cabinet view costs one
	 * query instead of two. The count subquery is restricted to the same owner
	 * and lifecycle, so it can ride the (owner_id,folder_id,lifecycle,id) index.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $parent_id Parent id (0 = root).
	 * @return array<int, object> Folder rows with a `file_count` property.
	 */
	public function children_with_counts( int $owner_id, int $parent_id ): array {
		global $wpdb;

		$folders = Schema::table( 'folders' );
		$files   = Schema::table( 'files' );
		$rows    = $this->db_get_results(
			$wpdb->prepare(
				"SELECT d.*, COALESCE( c.total, 0 ) AS file_count
				FROM {$folders} d
				LEFT JOIN (
					SELECT f.folder_id, COUNT(*) AS total
					FROM {$files} f
					WHERE f.owner_id=%d AND f.lifecycle='active'
					GROUP BY f.folder_id
				) c ON c.folder_id = d.id
				WHERE d.owner_id=%d AND d.parent_id=%d AND d.lifecycle='active'
				ORDER BY d.name_key ASC, d.id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$owner_id,
				$parent_id
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ancestors from the root down to the folder itself.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Folder id.
	 * @return array<int, object>
	 */
	public function breadcrumb( int $owner_id, int $folder_id ): array {
		$chain = array();
		$seen  = array();
		$id    = $folder_id;

		while ( $id > 0 ) {
			if ( count( $chain ) >= 64 || isset( $seen[ $id ] ) ) {
				break;
			}
			$seen[ $id ] = true;

			$row = $this->get( $owner_id, $id );
			if ( ! $row ) {
				break;
			}

			array_unshift( $chain, $row );
			$id = (int) $row->parent_id;
		}

		return $chain;
	}

	/**
	 * Ancestor depth of a parent folder (root = 0).
	 *
	 * @param int $owner_id Owner.
	 * @param int $parent_id Parent id.
	 * @return int
	 */
	public function depth( int $owner_id, int $parent_id ): int {
		return count( $this->breadcrumb( $owner_id, $parent_id ) );
	}

	/**
	 * Create a folder.
	 *
	 * @param int    $owner_id  Owner.
	 * @param int    $parent_id Parent id.
	 * @param string $name      Display name.
	 * @return object|WP_Error Created row.
	 */
	private function create_locked( int $owner_id, int $parent_id, string $name ) {
		global $wpdb;
		$this->lock_folders( $owner_id, array( $parent_id ) );

		$guard = $this->writable_chain( $owner_id, $parent_id );
		if ( is_wp_error( $guard ) ) { return $guard; }
		$name = $this->clean_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$settings = Settings::get();

		$parent = $parent_id > 0 ? $this->get( $owner_id, $parent_id ) : null;

		if ( $parent_id > 0 && ! $parent ) {
			return new WP_Error( 'sufm_folder_missing', __( '上级文件夹不存在或已删除。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( $parent && (int) $parent->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_folder_locked', __( '上级文件夹正在删除申请中。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( $this->depth( $owner_id, $parent_id ) >= (int) $settings['max_folder_depth'] ) {
			return new WP_Error( 'sufm_folder_depth', __( '已达到允许的最大文件夹层级。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( $this->count( $owner_id ) >= (int) $settings['max_user_folders'] ) {
			return new WP_Error( 'sufm_folder_limit', __( '文件夹数量已达到上限。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$key = $this->name_key( $name );
		if ( $this->find_by_key( $owner_id, $parent_id, $key ) ) {
			return new WP_Error( 'sufm_folder_exists', __( '同级目录下已有同名文件夹。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$table = Schema::table( 'folders' );
		$now   = Time::now();
		$order = (int) $this->db_get_var(
			$wpdb->prepare(
				"SELECT MAX(sort_order) FROM {$table} WHERE owner_id=%d AND parent_id=%d AND lifecycle='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$parent_id
			)
		) + 10;
		$ok    = $this->db_insert(
			$table,
			array(
				'owner_id'    => $owner_id,
				'parent_id'   => $parent_id,
				'name'        => $name,
				'name_key'    => $key,
				'active_slot' => 0,
				'lifecycle'   => 'active',
				'tree_version' => 1,
				'version'     => 1,
				'sort_order'  => $order,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'sufm_folder_conflict', __( '创建文件夹失败，请重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		return $this->get( $owner_id, (int) $wpdb->insert_id );
	}

	/**
	 * Rename a folder the user still owns and that is not locked by a request.
	 *
	 * @param int    $owner_id  Owner.
	 * @param int    $folder_id Folder id.
	 * @param string $name      New name.
	 * @return object|WP_Error
	 */
	private function rename_locked( int $owner_id, int $folder_id, string $name ) {
		global $wpdb;
		$this->lock_folders( $owner_id, array( $folder_id ) );

		$row = $this->get( $owner_id, $folder_id );
		if ( ! $row ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( 'active' !== (string) $row->lifecycle || (int) $row->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_folder_locked', __( '该文件夹正在删除申请中，暂不能改名。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$guard = $this->writable_chain( $owner_id, $folder_id );
		if ( is_wp_error( $guard ) ) { return $guard; }
		$name = $this->clean_name( $name );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$key = $this->name_key( $name );
		$dupe = $this->find_by_key( $owner_id, (int) $row->parent_id, $key );
		if ( $dupe && (int) $dupe->id !== $folder_id ) {
			return new WP_Error( 'sufm_folder_exists', __( '同级目录下已有同名文件夹。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$table = Schema::table( 'folders' );
		$this->db_update(
			$table,
			array(
				'name'       => $name,
				'name_key'   => $key,
				'version'    => (int) $row->version + 1,
				'updated_at' => Time::now(),
			),
			array( 'id' => $folder_id, 'owner_id' => $owner_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		return $this->get( $owner_id, $folder_id );
	}

	/**
	 * Whole-cabinet view for the media library: owners, folder trees and direct
	 * file counts in one pass.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/** Paged user roots. The root is a projection, never a folder row. */
	public function admin_page( int $page = 1, int $per_page = 50, string $search = '', int $owner_id = 0 ): array {
		global $wpdb;
		$page = max( 1, $page ); $per_page = min( 50, max( 1, $per_page ) );
		$files = Schema::table( 'files' ); $folders = Schema::table( 'folders' );
		$cap_key = $wpdb->prefix . 'capabilities';
		// Include site members without files and retained mappings for removed members.
		$base = $wpdb->prepare( "SELECT u.ID AS id FROM {$wpdb->users} u WHERE EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key=%s) UNION SELECT owner_id AS id FROM {$files} WHERE lifecycle<>'purged' UNION SELECT owner_id AS id FROM {$folders} WHERE lifecycle='active'", $cap_key );
		$where = 'roots.id>0'; $params = array();
		if ( $owner_id > 0 ) { $where .= ' AND roots.id=%d'; $params[] = $owner_id; }
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (u.display_name LIKE %s OR u.user_login LIKE %s OR CAST(roots.id AS CHAR)=%s OR EXISTS (SELECT 1 FROM ' . $folders . " sf WHERE sf.owner_id=roots.id AND sf.lifecycle='active' AND sf.name LIKE %s))";
			array_push( $params, $like, $like, $search, $like );
		}
		$from = " FROM ({$base}) roots LEFT JOIN {$wpdb->users} u ON u.ID=roots.id WHERE {$where}";
		$total_sql = 'SELECT COUNT(*)' . $from;
		$total = (int) $this->db_get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );
		$select = 'SELECT roots.id' . $from . ' ORDER BY roots.id ASC LIMIT %d OFFSET %d';
		$args = array_merge( $params, array( $per_page + 1, ( $page - 1 ) * $per_page ) );
		$ids = array_map( 'intval', (array) $this->db_get_col( $wpdb->prepare( $select, $args ) ) );
		$more = count( $ids ) > $per_page; $ids = array_slice( $ids, 0, $per_page );
		$owners = $this->admin_tree( $ids, $owner_id > 0 );
		$badges = $this->db_get_row( "SELECT COUNT(*) AS total,COALESCE(SUM(NOT EXISTS (SELECT 1 FROM {$files} f WHERE f.attachment_id=p.ID AND f.lifecycle<>'purged')),0) AS unassigned_count FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft')" );
		return array( 'owners' => $owners, 'page' => $page, 'per_page' => $per_page, 'total_users' => $total, 'has_more' => $more, 'next_page' => $more ? $page + 1 : null, 'total' => $badges ? (int) $badges->total : 0, 'unassigned_count' => $badges ? (int) $badges->unassigned_count : 0 );
	}

	public function admin_tree( ?array $owner_ids = null, bool $load_tree = true ): array {
		global $wpdb;
		if ( null === $owner_ids ) { return $this->admin_page()['owners']; }
		if ( ! $owner_ids ) { return array(); }
		$folders = Schema::table( 'folders' ); $files = Schema::table( 'files' );
		$marks = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$rows = $load_tree ? (array) $this->db_get_results( $wpdb->prepare( "SELECT * FROM {$folders} WHERE lifecycle='active' AND owner_id IN ({$marks}) ORDER BY sort_order,id", $owner_ids ) ) : array();
		$counts = (array) $this->db_get_results( $wpdb->prepare( "SELECT f.owner_id,f.folder_id,COUNT(*) AS c FROM {$files} f INNER JOIN {$wpdb->posts} p ON p.ID=f.attachment_id AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') WHERE f.lifecycle<>'purged' AND f.owner_id IN ({$marks}) GROUP BY f.owner_id,f.folder_id", $owner_ids ) );
		$by_owner = array(); $owned = array(); $unassigned = array();
		foreach ( $counts as $count ) {
			$uid = (int) $count->owner_id; $owned[ $uid ] = ( $owned[ $uid ] ?? 0 ) + (int) $count->c;
			if ( 0 === (int) $count->folder_id ) { $unassigned[ $uid ] = (int) $count->c; }
		}
		foreach ( $rows as $row ) {
			$direct = 0; foreach ( $counts as $count ) { if ( (int) $count->owner_id === (int) $row->owner_id && (int) $count->folder_id === (int) $row->id ) { $direct = (int) $count->c; break; } }
			$by_owner[ (int) $row->owner_id ][ (int) $row->id ] = array( 'id' => (int) $row->id, 'owner_id' => (int) $row->owner_id, 'folder_id' => (int) $row->id, 'kind' => 'folder', 'name' => (string) $row->name, 'parent_id' => (int) $row->parent_id, 'sort_order' => (int) $row->sort_order, 'version' => (int) $row->version, 'locked' => (int) $row->pending_request_id > 0, 'count' => $direct, 'total' => $direct, 'depth' => 0, 'children' => array() );
		}
		$owners = array();
		foreach ( $owner_ids as $uid ) {
			$user = get_userdata( $uid ); $valid = $user && is_user_member_of_blog( $uid );
			$nodes = $by_owner[ $uid ] ?? array();
			$build = function ( int $parent, int $depth, bool $locked, array $seen ) use ( &$build, $nodes, $valid ): array {
				$result = array();
				foreach ( $nodes as $node ) {
					if ( $node['parent_id'] !== $parent || isset( $seen[ $node['id'] ] ) || $depth > 64 ) { continue; }
					$next = $seen; $next[ $node['id'] ] = true;
					$node['depth'] = $depth; $node['locked'] = $locked || $node['locked'];
					$node['can_drop'] = $valid && ! $node['locked'];
					$node['children'] = $build( $node['id'], $depth + 1, $node['locked'], $next );
					$node['total'] += array_sum( array_column( $node['children'], 'total' ) );
					$result[] = $node;
				}
				return $result;
			};
			/* translators: %d: user ID */
			$owners[] = array( 'id' => $uid, 'owner_id' => $uid, 'folder_id' => 0, 'kind' => 'owner', 'name' => $valid ? (string) ( $user->display_name ?: $user->user_login ) : sprintf( __( '已删除或退出本站用户 #%d', 'sakura-imgopt' ), $uid ), 'login' => $valid ? (string) $user->user_login : '', 'deleted' => ! $valid, 'locked' => false, 'can_drop' => (bool) $valid, 'version' => 0, 'files' => $owned[ $uid ] ?? 0, 'unassigned' => $unassigned[ $uid ] ?? 0, 'tree' => $load_tree ? $build( 0, 0, false, array() ) : array(), 'tree_loaded' => $load_tree );
		}
		return $owners;
	}

	/**
	 * Number of live folders owned by the user.
	 *
	 * @param int $owner_id Owner.
	 * @return int
	 */
	public function count( int $owner_id ): int {
		global $wpdb;

		$table = Schema::table( 'folders' );

		return (int) $this->db_get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE owner_id=%d AND lifecycle='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id
			)
		);
	}

	/**
	 * Count live folders for many owners in one query (audit A08).
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

		$table = Schema::table( 'folders' );
		$marks = implode( ',', array_fill( 0, count( $owner_ids ), '%d' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT owner_id, COUNT(*) AS total FROM {$table} WHERE lifecycle='active' AND owner_id IN ({$marks}) GROUP BY owner_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * Normalized comparison key for a folder name.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	public function name_key( string $name ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	/**
	 * Validate and normalize a folder name.
	 *
	 * @param string $name Raw name.
	 * @return string|WP_Error
	 */
	private function clean_name( string $name ) {
		$name = trim( wp_strip_all_tags( $name ) );
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );

		if ( '' === $name || '.' === $name || '..' === $name ) {
			return new WP_Error( 'sufm_folder_name', __( '请输入文件夹名称。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( preg_match( '#[\\\\/:*?"<>|]#', $name ) ) {
			return new WP_Error( 'sufm_folder_chars', __( '文件夹名称不能包含 \\ / : * ? " < > | 等字符。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) > self::MAX_NAME : strlen( $name ) > self::MAX_NAME ) {
			return new WP_Error( 'sufm_folder_long', __( '文件夹名称过长（最多 100 个字符）。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		return $name;
	}

	/**
	 * Existing live row with the same key under the same parent.
	 *
	 * @param int    $owner_id  Owner.
	 * @param int    $parent_id Parent id.
	 * @param string $key       Normalized key.
	 * @return object|null
	 */
	private function find_by_key( int $owner_id, int $parent_id, string $key ): ?object {
		global $wpdb;

		$table = Schema::table( 'folders' );
		$row   = $this->db_get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_id=%d AND parent_id=%d AND name_key=%s AND active_slot=0 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$parent_id,
				$key
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Move one folder under another parent (0 = root).
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Folder.
	 * @param int $parent_id New parent.
	 * @return object|WP_Error
	 */
	private function move_locked( int $owner_id, int $folder_id, int $parent_id ) {
		global $wpdb;
		$this->lock_folders( $owner_id, array( $folder_id, $parent_id ) );

		$folder = $this->get( $owner_id, $folder_id );

		if ( ! $folder ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( $folder_id === $parent_id ) {
			return new WP_Error( 'sufm_folder_cycle', __( '不能把文件夹移动到自身。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		if ( (int) $folder->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_folder_locked', __( '该文件夹正在删除申请中，不能移动。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$target = $parent_id > 0 ? $this->get( $owner_id, $parent_id ) : null;

		if ( $parent_id > 0 && ! $target ) {
			return new WP_Error( 'sufm_folder_missing', __( '目标文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		foreach ( array( $folder_id, $parent_id ) as $check ) { $guard = $this->writable_chain( $owner_id, $check ); if ( is_wp_error( $guard ) ) { return $guard; } }
		$guard = $this->writable_subtree( $owner_id, $folder_id ); if ( is_wp_error( $guard ) ) { return $guard; }
		if ( (int) $folder->parent_id === $parent_id ) { return $folder; }

		// Walk up from the target: hitting this folder would create a cycle.
		$cursor = $parent_id;
		$guard  = 0;

		while ( $cursor > 0 && $guard++ < 64 ) {
			if ( $cursor === $folder_id ) {
				return new WP_Error( 'sufm_folder_cycle', __( '不能把文件夹移动到它自己的子目录。', 'sakura-imgopt' ), array( 'status' => 409 ) );
			}

			$row    = $this->get( $owner_id, $cursor );
			$cursor = $row ? (int) $row->parent_id : 0;
		}

		$settings = Settings::get();

		if ( $this->depth( $owner_id, $parent_id ) + 1 + $this->subtree_depth( $owner_id, $folder_id ) > (int) $settings['max_folder_depth'] ) {
			return new WP_Error( 'sufm_folder_depth', __( '移动后超过允许的最大文件夹层级。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$key = $this->name_key( (string) $folder->name );
		if ( $this->find_by_key( $owner_id, $parent_id, $key ) ) {
			return new WP_Error( 'sufm_folder_exists', __( '目标目录下已有同名文件夹。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$ok = $this->db_update(
			Schema::table( 'folders' ),
			array(
				'parent_id'    => $parent_id,
				'version'      => (int) $folder->version + 1,
				'tree_version' => (int) $folder->tree_version + 1,
				'updated_at'   => Time::now(),
			),
			array( 'id' => $folder_id, 'owner_id' => $owner_id ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'sufm_folder_conflict', __( '移动文件夹失败，请重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		return $this->get( $owner_id, $folder_id );
	}

	/**
	 * Remove a folder, lifting its contents to a parent (default: its own).
	 *
	 * The row is soft-deleted (active_slot = id) so the unique key keeps the
	 * historical name reserved for audit rows.
	 *
	 * @param int      $owner_id      Owner.
	 * @param int      $folder_id     Folder.
	 * @param int|null $target_parent Destination parent; null keeps the current parent.
	 * @return true|WP_Error
	 */
	private function delete_locked( int $owner_id, int $folder_id, ?int $target_parent = null ) {
		global $wpdb;
		$this->lock_folders( $owner_id, array( $folder_id, (int) $target_parent ) );

		$folder = $this->get( $owner_id, $folder_id );
		if ( ! $folder ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		if ( (int) $folder->pending_request_id > 0 ) {
			return new WP_Error( 'sufm_folder_locked', __( '该文件夹正在删除申请中，不能删除。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		$parent = null === $target_parent ? (int) $folder->parent_id : max( 0, $target_parent );
		if ( $parent > 0 && ! $this->get( $owner_id, $parent ) ) {
			return new WP_Error( 'sufm_folder_missing', __( '目标文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		foreach ( array( $folder_id, $parent ) as $check ) { $guard = $this->writable_chain( $owner_id, $check ); if ( is_wp_error( $guard ) ) { return $guard; } }
		$guard = $this->writable_subtree( $owner_id, $folder_id ); if ( is_wp_error( $guard ) ) { return $guard; }
		foreach ( $this->breadcrumb( $owner_id, $parent ) as $ancestor ) { if ( (int) $ancestor->id === $folder_id ) { return new WP_Error( 'sufm_folder_cycle', __( '不能将内容提升到被移除的目录或它的子目录。', 'sakura-imgopt' ), array( 'status' => 409 ) ); } }

		$now     = Time::now();
		$folders = Schema::table( 'folders' );
		$files   = Schema::table( 'files' );

		foreach ( $this->children( $owner_id, $folder_id ) as $child ) {
			$this->db_update(
				$folders,
				array(
					'parent_id'    => $parent,
					'sort_order'   => (int) $child->sort_order + 10,
					'version'      => (int) $child->version + 1,
					'tree_version' => (int) $child->tree_version + 1,
					'updated_at'   => $now,
				),
				array( 'id' => (int) $child->id ),
				array( '%d', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
		}

		$this->db_query(
			$wpdb->prepare(
				"UPDATE {$files} SET folder_id=%d, version=version+1, updated_at=%s WHERE owner_id=%d AND folder_id=%d AND lifecycle='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$parent,
				$now,
				$owner_id,
				$folder_id
			)
		);

		$ok = $this->db_update(
			$folders,
			array(
				'lifecycle'     => 'deleted',
				'active_slot'   => $folder_id,
				'sort_order'    => 0,
				'version'       => (int) $folder->version + 1,
				'tree_version'  => (int) $folder->tree_version + 1,
				'updated_at'    => $now,
			),
			array( 'id' => $folder_id, 'owner_id' => $owner_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'sufm_folder_conflict', __( '删除文件夹失败，请重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Move/reorder a folder among its siblings.
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Folder.
	 * @param int $parent_id Destination parent.
	 * @param int $position  0-based slot among siblings; -1 appends.
	 * @return true|WP_Error
	 */
	private function reorder_locked( int $owner_id, int $folder_id, int $parent_id, int $position ) {
		global $wpdb;
		$this->lock_folders( $owner_id, array( $folder_id, $parent_id ) );

		$folder = $this->get( $owner_id, $folder_id );
		if ( ! $folder ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		foreach ( array( $folder_id, $parent_id ) as $check ) { $guard = $this->writable_chain( $owner_id, $check ); if ( is_wp_error( $guard ) ) { return $guard; } }
		foreach ( $this->children( $owner_id, $parent_id ) as $sibling ) { if ( (int) $sibling->pending_request_id > 0 ) { return new WP_Error( 'sufm_folder_locked', __( '同级目录正在删除申请中，暂不能排序。', 'sakura-imgopt' ), array( 'status' => 409 ) ); } }

		if ( (int) $folder->parent_id !== $parent_id ) {
			$moved = $this->move( $owner_id, $folder_id, $parent_id );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}

		$folders = Schema::table( 'folders' );
		$ids     = $this->db_get_col(
			$wpdb->prepare(
				"SELECT id FROM {$folders} WHERE owner_id=%d AND parent_id=%d AND lifecycle='active' ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$parent_id
			)
		);
		$ids     = array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => $id !== $folder_id ) );

		if ( $position < 0 || $position > count( $ids ) ) {
			$position = count( $ids );
		}

		array_splice( $ids, $position, 0, array( $folder_id ) );

		foreach ( $ids as $index => $id ) {
			$this->db_update(
				$folders,
				array( 'sort_order' => ( $index + 1 ) * 10, 'version' => (int) $this->get( $owner_id, $id )->version + 1, 'updated_at' => Time::now() ),
				array( 'id' => $id, 'owner_id' => $owner_id ),
				array( '%d', '%d', '%s' ),
				array( '%d', '%d' )
			);
		}

		return true;
	}

	/**
	 * Deepest descendant depth under one folder (0 = no children).
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Folder.
	 * @return int
	 */
	private function subtree_depth( int $owner_id, int $folder_id ): int {
		$max = 0;

		foreach ( $this->children( $owner_id, $folder_id ) as $child ) {
			$max = max( $max, 1 + $this->subtree_depth( $owner_id, (int) $child->id ) );
		}

		return $max;
	}

	/**
	 * Every descendant folder with a `depth` (1 = direct child).
	 *
	 * @param int $owner_id  Owner.
	 * @param int $folder_id Root.
	 * @return object[]
	 */
	public function descendants( int $owner_id, int $folder_id ): array {
		$out   = array();
		$queue = array( array( $folder_id, 0 ) );

		while ( $queue ) {
			list( $current, $depth ) = array_shift( $queue );

			foreach ( $this->children( $owner_id, (int) $current ) as $child ) {
				$child->depth = $depth + 1;
				$out[]        = $child;
				$queue[]      = array( (int) $child->id, $depth + 1 );
			}
		}

		return $out;
	}
	private bool $write_lock = false;
	private ?int $write_owner = null;
	public function create( int $owner_id, int $parent_id, string $name ) { return $this->write( $owner_id, fn() => $this->create_locked( $owner_id, $parent_id, $name ) ); }
	public function rename( int $owner_id, int $folder_id, string $name ) { return $this->write( $owner_id, fn() => $this->rename_locked( $owner_id, $folder_id, $name ) ); }
	public function move( int $owner_id, int $folder_id, int $parent_id ) { return $this->write( $owner_id, fn() => $this->move_locked( $owner_id, $folder_id, $parent_id ) ); }
	public function delete( int $owner_id, int $folder_id, ?int $target_parent = null ) { return $this->write( $owner_id, fn() => $this->delete_locked( $owner_id, $folder_id, $target_parent ) ); }
	public function reorder( int $owner_id, int $folder_id, int $parent_id, int $position ) { return $this->write( $owner_id, fn() => $this->reorder_locked( $owner_id, $folder_id, $parent_id, $position ) ); }
	/** Lock specific folder rows before a cross-repository re-read (internal). */
	public function lock_rows( int $owner_id, array $ids ): void {
		$this->lock_folders( $owner_id, $ids );
	}

	/** Internal repository protocol; authorization remains with the calling handler. */
	public function with_owner_write( int $owner_id, callable $work ) {
		if ( $this->write_lock ) { return new WP_Error( 'sufm_transaction_busy', __( '目录整理不能嵌套到另一项数据库事务。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
		return $this->write( $owner_id, $work );
	}

	private function write( int $owner_id, callable $work ) {
		global $wpdb;
		if ( $this->write_lock ) { return $owner_id === $this->write_owner ? $work() : new WP_Error( 'sufm_transaction_busy', __( '目录整理不能嵌套到另一项数据库事务。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
		$started = false;

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		try {
			foreach ( array( 'folders', 'files', 'usage' ) as $name ) {
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', Schema::table( $name ) ) );
				if ( 'INNODB' !== strtoupper( (string) $engine ) ) { return new WP_Error( 'sufm_transaction_required', __( '目录整理需要InnoDB事务表。', 'sakura-imgopt' ), array( 'status' => 503 ) ); }
			}
			// Portable nesting guard: `SELECT @@in_transaction` is MariaDB-only and
			// silently returns null on MySQL 8, which used to disable this check.
			if ( Db_Transaction::depth() > 0 ) { return new WP_Error( 'sufm_transaction_busy', __( '目录整理不能嵌套到另一项数据库事务。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			$this->write_lock = true; $this->write_owner = $owner_id;
			Db_Transaction::begin(); $started = true;
			$usage = Schema::table( 'usage' ); $files = Schema::table( 'files' ); $folders = Schema::table( 'folders' );
			// `INSERT IGNORE` on an existing mutex row takes a shared lock, and two
			// sessions that then upgrade to FOR UPDATE deadlock instantly (that is
			// what made the second parallel batch of moves fail while the rows were
			// already committed by the other requests). ON DUPLICATE KEY UPDATE takes
			// the exclusive lock in the same statement, so concurrent tenants queue.
			$this->db_query( $wpdb->prepare( "INSERT INTO {$usage} (owner_id,used_bytes,reserved_bytes,revision,updated_at) VALUES (%d,0,0,0,%s) ON DUPLICATE KEY UPDATE owner_id = owner_id", $owner_id, Time::now() ) );
			$this->db_get_row( $wpdb->prepare( "SELECT owner_id FROM {$usage} WHERE owner_id=%d FOR UPDATE", $owner_id ) );
			// The usage row above is the per-tenant mutex. Locking every file and
			// folder row of the tenant as well only widened the lock window: one
			// move blocked unrelated uploads of the same user for its duration.
			$result = $work();
			is_wp_error( $result ) ? Db_Transaction::rollback() : Db_Transaction::commit();
			return $result;
		} catch ( \Throwable $e ) {
			if ( $started ) { Db_Transaction::rollback(); }

			$message = (string) $e->getMessage();
			$retryable = (bool) preg_match( '/deadlock|try restarting transaction|lock wait timeout/i', $message );

			if ( $retryable && $attempt < 3 ) {
				usleep( 15000 * $attempt );
				continue;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( 'sufm folder write failed: ' . $message ); }

			return new WP_Error( 'sufm_folder_conflict', __( '目录状态已变化或写入失败，本次整理未提交，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
		} finally {
			$this->write_lock = false;
			$this->write_owner = null;
		}
		}

		return new WP_Error( 'sufm_folder_conflict', __( '目录状态已变化或写入失败，本次整理未提交，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) );
	}
	/**
	 * Lock the folder rows an operation is about to re-read.
	 *
	 * The usage row is the per-tenant mutex; this narrow lock keeps a concurrent
	 * approve/delete from slipping between our read and our write without
	 * materialising every row of the tenant (the old tenant-wide FOR UPDATE did
	 * that and blocked unrelated uploads for the duration of one move).
	 *
	 * @param int        $owner_id Owner.
	 * @param array<int> $ids      Folder ids; the virtual root (0) is skipped.
	 * @return void
	 */
	private function lock_folders( int $owner_id, array $ids ): void {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );

		if ( ! $ids ) {
			return;
		}

		$folders = Schema::table( 'folders' );
		$marks   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$this->db_get_results( $wpdb->prepare( "SELECT id FROM {$folders} WHERE owner_id=%d AND id IN ({$marks}) FOR UPDATE", array_merge( array( $owner_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function writable_chain( int $owner_id, int $folder_id ) {
		$seen = array();
		while ( $folder_id > 0 ) {
			if ( isset( $seen[ $folder_id ] ) || count( $seen ) >= 64 ) { return new WP_Error( 'sufm_folder_cycle', __( '目录层级无效。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			$seen[ $folder_id ] = true;
			$folder = $this->get( $owner_id, $folder_id );
			if ( ! $folder || (int) $folder->pending_request_id > 0 ) { return new WP_Error( 'sufm_folder_locked', __( '目录或上级目录不存在或正在删除申请中。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			$folder_id = (int) $folder->parent_id;
		}
		return true;
	}
	private function writable_subtree( int $owner_id, int $folder_id ) {
		global $wpdb;
		$queue = array( $folder_id ); $ids = array();
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $ids[ $id ] ) || count( $ids ) > 10000 ) { return new WP_Error( 'sufm_folder_cycle', __( '目录层级无效。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
			$ids[ $id ] = $id;
			$guard = $this->writable_chain( $owner_id, $id ); if ( is_wp_error( $guard ) ) { return $guard; }
			foreach ( $this->children( $owner_id, $id ) as $child ) { $queue[] = (int) $child->id; }
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) ); $files = Schema::table( 'files' );
		$args = array_merge( array( $owner_id ), array_values( $ids ) );
		$locked = $this->db_get_var( $wpdb->prepare( "SELECT id FROM {$files} WHERE owner_id=%d AND folder_id IN ({$marks}) AND lifecycle<>'purged' AND (pending_request_id>0 OR lifecycle<>'active') LIMIT 1 FOR UPDATE", $args ) );
		return $locked ? new WP_Error( 'sufm_folder_locked', __( '目录内有正在删除申请中的文件，暂不能整理。', 'sakura-imgopt' ), array( 'status' => 409 ) ) : true;
	}
	private function check_db( $result ) { global $wpdb; if ( $this->write_lock && ( false === $result || $wpdb->last_error ) ) { throw new \RuntimeException( 'folder_sql_failed: ' . (string) $wpdb->last_error ); } return $result; } // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught in write(), only logged, never printed.
	private function db_get_row( ...$args ) { global $wpdb; return $this->check_db( $wpdb->get_row( ...$args ) ); }
	private function db_get_results( ...$args ) { global $wpdb; return $this->check_db( $wpdb->get_results( ...$args ) ); }
	private function db_get_var( ...$args ) { global $wpdb; return $this->check_db( $wpdb->get_var( ...$args ) ); }
	private function db_get_col( ...$args ) { global $wpdb; return $this->check_db( $wpdb->get_col( ...$args ) ); }
	private function db_query( ...$args ) { global $wpdb; return $this->check_db( $wpdb->query( ...$args ) ); }
	private function db_insert( ...$args ) { global $wpdb; return $this->check_db( $wpdb->insert( ...$args ) ); }
	private function db_update( ...$args ) { global $wpdb; return $this->check_db( $wpdb->update( ...$args ) ); }

}
