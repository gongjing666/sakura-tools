<?php
/**
 * Read-only picker feed for the front-end editor panel.
 *
 * Deliberately separate from Controller: this route must stay usable when the
 * user-centre entry switch is off, and it never writes anything. It lists the
 * current user's attachments (adopted or not) so old media stays reachable.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Rest;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Services\Quota;
use Nboke\UserFiles\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /sufm/v1/picker/items — the caller's own attachments, folder aware.
 */
final class Picker_Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sufm/v1';

	/**
	 * Page size for the picker grid.
	 */
	private const PER_PAGE = 40;

	/**
	 * Folder repository, used for the in-panel tree.
	 *
	 * @var Folders
	 */
	private Folders $folders;

	/**
	 * Quota ledger, used for the capacity line in the panel header.
	 *
	 * @var Quota
	 */
	private Quota $quota;

	/**
	 * Constructor.
	 *
	 * @param Folders $folders Folder repository.
	 * @param Quota   $quota   Quota ledger.
	 */
	public function __construct( Folders $folders, Quota $quota ) {
		$this->folders = $folders;
		$this->quota   = $quota;
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			'/picker/item',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'item' ),
				'permission_callback' => array( $this, 'can_pick' ),
				'args'                => array(
					'id'  => array( 'type' => 'integer', 'default' => 0 ),
					'ids' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/picker/items',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'items' ),
				'permission_callback' => array( $this, 'can_pick' ),
				'args'                => array(
					'folder_id' => array( 'type' => 'string', 'default' => '0' ),
					'search'    => array( 'type' => 'string', 'default' => '' ),
					'type'      => array( 'type' => 'string', 'default' => '' ),
					'cursor'    => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	/**
	 * Logged in, may manage own files, and the editor panel is switched on.
	 *
	 * Note: no entry_enabled() here — that switch governs the user centre and
	 * the upload endpoints, not a read-only picker.
	 */
	public function can_pick(): bool {
		return is_user_logged_in()
			&& current_user_can( Capabilities::MANAGE_OWN )
			&& Settings::editor_enabled();
	}

	/**
	 * List one page of the caller's attachments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$owner    = get_current_user_id();
		$raw      = (string) $request->get_param( 'folder_id' );
		$folder   = 'unassigned' === $raw ? 'unassigned' : max( 0, (int) $raw );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$cursor   = absint( $request->get_param( 'cursor' ) );
		$per_page = self::PER_PAGE;

		if ( is_int( $folder ) && $folder > 0 && ! $this->folders->breadcrumb( $owner, $folder ) ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$files  = Schema::table( 'files' );
		$where  = array( "p.post_type='attachment'", "p.post_status='inherit'", 'p.post_author=%d' );
		$params = array( $owner );

		// A search spans the whole space: the panel says "搜索我的文件", so
		// keeping the folder scope would silently hide matches.
		if ( '' !== $search ) {
			$folder = 0;
		}

		if ( 'unassigned' === $folder ) {
			$where[] = 'f.id IS NULL';
		} elseif ( $folder > 0 ) {
			$where[]  = 'f.folder_id=%d';
			$params[] = $folder;
		}

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(p.post_title LIKE %s OR f.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$mime_where = $this->mime_clause( $type );
		if ( '' !== $mime_where ) {
			$where[] = $mime_where;
		}

		if ( $cursor > 0 ) {
			$where[]  = 'p.ID < %d';
			$params[] = $cursor;
		}

		$params[] = $per_page + 1;

		$sql = "SELECT p.ID,p.post_title,p.post_mime_type,p.guid,p.post_date,f.id AS ledger_id,f.folder_id,f.display_name,f.charged_bytes
			FROM {$wpdb->posts} p
			LEFT JOIN {$files} f ON f.attachment_id=p.ID AND f.lifecycle='active'
			WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.ID DESC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = is_array( $rows ) ? $rows : array();

		$next = 0;
		if ( count( $rows ) > $per_page ) {
			$rows = array_slice( $rows, 0, $per_page );
			$last = end( $rows );
			$next = $last ? (int) $last->ID : 0;
		}

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->shape( $row );
		}

		$children = $this->folders->children_with_counts( $owner, is_int( $folder ) ? $folder : 0 );
		$folders  = array();
		foreach ( $children as $child ) {
			$folders[] = array(
				'id'    => (int) $child->id,
				'name'  => (string) $child->name,
				'count' => (int) ( $child->file_count ?? 0 ),
			);
		}

		$crumbs = is_int( $folder ) && $folder > 0 ? $this->folders->breadcrumb( $owner, $folder ) : array();
		$trail  = array( array( 'id' => 0, 'name' => __( '全部文件', 'sakura-imgopt' ) ) );
		foreach ( $crumbs as $crumb ) {
			$trail[] = array( 'id' => (int) $crumb->id, 'name' => (string) $crumb->name );
		}

		$snapshot = $this->quota->snapshot( $owner );

		return rest_ensure_response(
			array(
				'quota'      => array(
					'total'     => (int) $snapshot['quota'],
					'used'      => (int) $snapshot['used'],
					'available' => (int) $snapshot['available'],
				),
				'folder'     => $folder,
				'breadcrumb' => $trail,
				'folders'    => $folders,
				'items'      => $items,
				'next'       => $next,
			)
		);
	}

	/**
	 * One attachment, shaped for insertion into the editor.
	 *
	 * The manager script already holds the list; the panel needs the full-size
	 * URL and the real mime type of the single file being inserted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw = (string) $request->get_param( 'ids' );

		// Batch mode: the editor panel inserts a whole ticked selection at once,
		// so it asks for several attachments in one round trip.
		if ( '' !== $raw ) {
			$wanted = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
			$items  = array();

			foreach ( array_slice( $wanted, 0, 40 ) as $ledger_id ) {
				$shaped = $this->resolve( $ledger_id );

				if ( is_array( $shaped ) ) {
					$items[] = $shaped;
				}
			}

			if ( ! $items ) {
				return new WP_Error( 'sufm_file_missing', __( '文件不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
			}

			return rest_ensure_response( array( 'items' => $items ) );
		}

		$shaped = $this->resolve( absint( $request->get_param( 'id' ) ) );

		if ( is_wp_error( $shaped ) ) {
			return $shaped;
		}

		return rest_ensure_response( $shaped );
	}

	/**
	 * Resolve one ledger id (or raw attachment id) into the shaped item.
	 *
	 * The manager script addresses files by ledger id (sufm_files.id), which is
	 * what /sufm/v1/items returns. Passing that straight to get_post() picked
	 * whichever attachment happened to own the same numeric id — the panel
	 * inserted a different file than the one selected. Resolve the ledger row
	 * first; fall back to a raw attachment id for direct links.
	 *
	 * @param int $id Ledger id, or attachment id when no ledger row matches.
	 * @return array<string, mixed>|WP_Error
	 */
	private function resolve( int $id ): array|WP_Error {
		global $wpdb;

		$files = Schema::table( 'files' );

		$attachment_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT attachment_id FROM {$files} WHERE id=%d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! $attachment_id ) {
			$attachment_id = $id;
		}

		$post = $attachment_id ? get_post( $attachment_id ) : null;

		if ( ! $post || 'attachment' !== $post->post_type || (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error( 'sufm_file_missing', __( '文件不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id AS ledger_id,folder_id,display_name,charged_bytes FROM {$files} WHERE attachment_id=%d AND lifecycle='active'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id
			)
		);

		$data = (object) array(
			'ID'             => $attachment_id,
			'post_title'     => $post->post_title,
			'post_mime_type' => $post->post_mime_type,
			'guid'           => $post->guid,
			'post_date'      => $post->post_date,
			'ledger_id'      => $row ? $row->ledger_id : null,
			'folder_id'      => $row ? (int) $row->folder_id : 0,
			'display_name'   => $row ? (string) $row->display_name : '',
			'charged_bytes'  => $row ? (int) $row->charged_bytes : 0,
		);

		return $this->shape( $data );
	}

	/**
	 * Normalise one attachment row for the picker.
	 *
	 * @param object $row Database row.
	 * @return array<string, mixed>
	 */
	private function shape( object $row ): array {
		$id   = (int) $row->ID;
		$mime = (string) $row->post_mime_type;
		$meta = wp_get_attachment_metadata( $id );

		$url   = (string) $row->guid;
		$large = $url;
		$thumb = $url;
		$w     = 0;
		$h     = 0;

		if ( str_starts_with( $mime, 'image/' ) ) {
			$full = wp_get_attachment_image_src( $id, 'full' );
			if ( $full ) {
				$url = (string) $full[0];
				$w   = (int) $full[1];
				$h   = (int) $full[2];
			}

			$lg = wp_get_attachment_image_src( $id, 'large' );
			if ( $lg ) {
				$large = (string) $lg[0];
			}

			$md = wp_get_attachment_image_src( $id, 'medium' );
			if ( $md ) {
				$thumb = (string) $md[0];
			}
		}

		$name = trim( (string) $row->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $row->post_title );
		}
		if ( '' === $name ) {
			$name = wp_basename( (string) parse_url( $url, PHP_URL_PATH ) );
		}

		$bytes = (int) $row->charged_bytes;
		if ( $bytes <= 0 && is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
			$bytes = (int) $meta['filesize'];
		}

		return array(
			'id'         => $id,
			'name'       => $name,
			'mime'       => $mime,
			'bytes'      => $bytes,
			'url'        => $url,
			'large'      => $large,
			'thumb'      => $thumb,
			'width'      => $w,
			'height'     => $h,
			'folder_id'  => (int) $row->folder_id,
			'adopted'    => null !== $row->ledger_id,
			'created_at' => (string) $row->post_date,
		);
	}

	/**
	 * MIME filter for a picker tab.
	 *
	 * @param string $type Tab key.
	 * @return string SQL fragment (trusted, no user input).
	 */
	private function mime_clause( string $type ): string {
		switch ( $type ) {
			case 'image':
				return "p.post_mime_type LIKE 'image/%'";
			case 'video':
				return "p.post_mime_type LIKE 'video/%'";
			case 'audio':
				return "p.post_mime_type LIKE 'audio/%'";
			case 'document':
				return "p.post_mime_type IN ('application/pdf','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')";
			case 'archive':
				return "p.post_mime_type IN ('application/zip','application/x-zip-compressed','application/x-rar-compressed','application/gzip')";
			default:
				return '';
		}
	}
}
