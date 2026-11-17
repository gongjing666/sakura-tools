<?php
/**
 * WordPress media library integration for the user file space.
 *
 * Read-only media columns/filters, attachment summaries, and delete guards.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Media;

use Nboke\UserFiles\Support\Db_Transaction;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Services\Media_Adoption;
use Nboke\UserFiles\Services\Quota;
use Nboke\UserFiles\Services\Sync_Service;
use Nboke\UserFiles\Services\Uploader;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Couples the user file space to the WordPress media library.
 */
final class Media_Hooks {

	/**
	 * Maximum number of attachment IDs inspected in one REST batch request.
	 */
	private const MAX_BATCH_IDS = 1000;

	/**
	 * Media list table columns owned by this integration.
	 */
	private const COLUMNS = array( 'sufm_source', 'sufm_owner', 'sufm_folder', 'sufm_size' );

	private Files $files;

	private Folders $folders;

	private Requests $requests;

	/**
	 * Optional ledger sync (media uploads can be adopted on the fly).
	 *
	 * @var Sync_Service|null
	 */
	private ?Sync_Service $sync;

	/**
	 * Optional quota ledger.
	 *
	 * @var Quota|null
	 */
	private ?Quota $quota;

	/**
	 * Attachment summaries for the current list page.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $summaries = array();

	/**
	 * Whether the current media list page has already been primed.
	 */
	private bool $list_primed = false;

	/**
	 * Display names, keyed by user ID.
	 *
	 * @var array<int, string>
	 */
	private array $user_names = array();

	/**
	 * Folder rows, keyed by folder ID. A null value means "not found".
	 *
	 * @var array<int, object|null>
	 */
	private array $folder_rows = array();

	/**
	 * Latest delete request per attachment, keyed by attachment ID.
	 *
	 * @var array<int, object|null>
	 */
	private array $request_rows = array();

	/**
	 * Attachments this request already cleared for a direct media-library
	 * delete. Only these are reconciled on `deleted_post`; deletions driven by
	 * the approved-request executor settle their own ledger row.
	 *
	 * @var array<int, bool>
	 */
	private array $direct_deletes = array();

	private ?Events $events;

	public function __construct( Files $files, Folders $folders, Requests $requests, ?Sync_Service $sync = null, ?Quota $quota = null, ?Events $events = null ) {
		$this->files    = $files;
		$this->folders  = $folders;
		$this->requests = $requests;
		$this->sync     = $sync;
		$this->quota    = $quota;
		$this->events   = $events;
	}

	/**
	 * Register the media library hooks.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_filter( 'wp_handle_upload_prefilter', array( $this, 'guard_media_upload' ), 50 );
		$hooks->add_action( 'add_attachment', array( $this, 'assign_uploaded' ), 20 );
		$hooks->add_filter( 'manage_media_columns', array( $this, 'add_columns' ), 999 );
		$hooks->add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		$hooks->add_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 10, 2 );
		$hooks->add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
		$hooks->add_filter( 'ajax_query_attachments_args', array( $this, 'filter_grid_query' ) );
		$hooks->add_filter( 'posts_where', array( $this, 'filter_media_where' ), 10, 2 );
		$hooks->add_filter( 'attachment_fields_to_edit', array( $this, 'add_summary_field' ), 10, 2 );
		$hooks->add_filter( 'attachment_fields_to_save', array( $this, 'save_summary_field' ), 10, 2 );
		$hooks->add_filter( 'pre_delete_attachment', array( $this, 'guard_delete' ), 10, 3 );
		$hooks->add_action( 'deleted_post', array( $this, 'finalize_deleted_attachment' ), 10, 2 );
		$hooks->add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest_delete' ), 10, 3 );
		$hooks->add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		$hooks->add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		$hooks->add_action( 'admin_notices', array( $this, 'render_bulk_notice' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		$hooks->add_action( 'load-upload.php', array( $this, 'preflight_bulk_delete' ) );
		$hooks->add_action( 'admin_notices', array( $this, 'render_folder_panel' ) );
		$hooks->add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_folder_panel' ) );
	}

	/**
	 * Add the read-only media columns.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	/**
	 * Keep the media list readable after four columns were added to it.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		$path = SAKURA_TOOLS_PATH . 'assets/ui/wp-media/media-library.css';
		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_style(
			'sufm-media-library',
			SAKURA_TOOLS_URL . 'assets/ui/wp-media/media-library.css',
			array(),
			\Nboke\UserFiles\Support\Assets::version( 'assets/ui/wp-media/media-library.css' )
		);
	}

	public function add_columns( $columns ) {
		if ( ! is_array( $columns ) || ! current_user_can( 'upload_files' ) ) {
			return $columns;
		}

		/* Keep our four next to the file name: appended at the end they sit
		 * behind FileBird and S3 columns, off-screen on a normal laptop. */
		$ours = array(
			'sufm_source' => __( '文件来源', 'sakura-imgopt' ),
			'sufm_owner'  => __( '所有者', 'sakura-imgopt' ),
			'sufm_folder' => __( '虚拟目录', 'sakura-imgopt' ),
			'sufm_size'   => __( '容量', 'sakura-imgopt' ),
		);

		if ( isset( $columns['sufm_source'] ) ) {
			return $columns;
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out += $ours;
			}
		}

		return array_key_exists( 'sufm_source', $out ) ? $out : array_merge( $out, $ours );
	}

	/**
	 * Render one of the user file columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Attachment ID.
	 */
	public function render_column( $column, $post_id ): void {
		if ( ! in_array( (string) $column, self::COLUMNS, true ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$this->prime_list_summaries();

		$post_id = (int) $post_id;
		if ( ! array_key_exists( $post_id, $this->summaries ) ) {
			$this->prime_summaries( array( $post_id ) );
		}

		$summary = $this->summaries[ $post_id ] ?? null;
		$managed = is_array( $summary ) && ! empty( $summary['managed'] );

		if ( 'sufm_source' === $column ) {
			$source = (string) ( $summary['source'] ?? '' );
			if ( ! $managed ) {
				$label = __( 'WordPress 原库', 'sakura-imgopt' );
			} elseif ( Files::SOURCE_MEDIA === $source ) {
				$label = __( '媒体库导入', 'sakura-imgopt' );
			} else {
				$label = __( '用户文件空间', 'sakura-imgopt' );
			}
			echo esc_html( $label );
			return;
		}

		if ( ! $managed ) {
			echo esc_html( '—' );
			return;
		}

		switch ( $column ) {
			case 'sufm_owner':
				$owner_id = (int) ( $summary['owner_id'] ?? 0 );
				if ( $owner_id <= 0 ) {
					echo esc_html( '—' );
					return;
				}

				$owner_name = (string) ( $summary['owner_name'] ?? '' );
				if ( '' === $owner_name ) {
					$owner_name = '#' . $owner_id;
				}

				$url = add_query_arg(
					array( 'sufm_owner' => $owner_id ),
					admin_url( 'upload.php' )
				);

				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $owner_name ) . '</a>';
				return;

			case 'sufm_folder':
				echo esc_html( (string) ( $summary['folder_path'] ?? '—' ) );
				return;

			case 'sufm_size':
				echo esc_html( (string) size_format( (int) ( $summary['charged_bytes'] ?? 0 ), 2 ) );
				return;
		}
	}

	/**
	 * Pick one request and list exactly the attachments frozen into it.
	 *
	 * @param int $current Current request id.
	 */
	private function render_request_picker( int $current ): void {
		global $wpdb;

		$table = Schema::table( 'delete_requests' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,owner_id,status FROM {$table} WHERE status IN (%s,%s,%s) ORDER BY id DESC LIMIT 40", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Requests::PENDING,
				Requests::APPROVED,
				Requests::NEEDS
			)
		);

		if ( ! $rows ) {
			return;
		}

		echo '<label class="screen-reader-text" for="sufm-request-id">'
			. esc_html__( '按删除申请筛选', 'sakura-imgopt' )
			. '</label>';
		echo '<select name="sufm_request" id="sufm-request-id" class="postform">';
		printf(
			'<option value="0"%1$s>%2$s</option>',
			selected( $current, 0, false ),
			esc_html__( '所有申请', 'sakura-imgopt' )
		);

		foreach ( $rows as $row ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $row->id,
				selected( $current, (int) $row->id, false ),
				esc_html( sprintf( /* translators: 1: request id, 2: owner id, 3: status */ __( '申请 #%1$d · 用户 %2$d · %3$s', 'sakura-imgopt' ), (int) $row->id, (int) $row->owner_id, (string) $row->status ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Render the media library filters.
	 *
	 * @param string $post_type Post type being listed.
	 * @param string $which     Location of the extra table nav.
	 */
	public function render_filters( $post_type, $which = '' ): void {
		if ( 'attachment' !== $post_type || 'bottom' === $which ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$owner   = $this->param_int( 'sufm_owner', array() );
		$source  = $this->param_key( 'sufm_source', array() );
		$status  = $this->param_key( 'sufm_request_status', array() );
		$request = $this->param_int( 'sufm_request', array() );

		if ( ! in_array( $source, array( '', 'userfiles', 'media', 'wordpress' ), true ) ) {
			$source = '';
		}

		$request_statuses = array( '', 'none', Requests::PENDING, Requests::APPROVED, Requests::REVOKED );
		if ( ! in_array( $status, $request_statuses, true ) ) {
			$status = '';
		}

		if ( current_user_can( 'manage_options' ) ) {
			$this->render_owner_filter( $owner );
		}

		$this->render_source_filter( $source );
		$this->render_request_filter( $status );
		$this->render_request_picker( $request );
	}

	/**
	 * Apply the selected filters to the upload.php list query.
	 *
	 * @param mixed $query Query object.
	 */
	public function filter_list_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( is_array( $post_type ) ) {
			if ( ! in_array( 'attachment', $post_type, true ) ) {
				return;
			}
		} elseif ( is_string( $post_type ) && '' !== $post_type && 'attachment' !== $post_type ) {
			// upload.php sets the post type after pre_get_posts on some versions.
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$scope = $this->library_scope();
		if ( null !== $scope ) {
			$query->set( '_sufm_library_scope', $scope );
		}
	}

	/**
	 * Apply the selected filters to the media grid AJAX query.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function filter_grid_query( $args ) {
		if ( ! is_array( $args ) || ! current_user_can( 'upload_files' ) ) {
			return $args;
		}

		// Core strips custom query keys before this hook. Read only our scalar keys.
		$overrides = $args;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Authenticated, read-only media query.
		$raw_query = isset( $_POST['query'] ) && is_array( $_POST['query'] ) ? $_POST['query'] : array();
		foreach ( array( 'sufm_owner', 'sufm_source', 'sufm_request_status', 'sufm_request', 'sufm_folder', 'sufm_folder_mode', 'sufm_unassigned' ) as $key ) {
			if ( array_key_exists( $key, $raw_query ) ) {
				$overrides[ $key ] = is_scalar( $raw_query[ $key ] ) ? wp_unslash( (string) $raw_query[ $key ] ) : '';
			}
		}
		$scope = $this->library_scope( $overrides );
		if ( null !== $scope ) {
			$args['_sufm_library_scope'] = $scope;
		}
		return $args;
	}

	/**
	 * Preserve native search/date/MIME/ID restrictions and native pagination.
	 *
	 * @param string   $where Native WHERE.
	 * @param WP_Query $query Query.
	 */
	public function filter_media_where( $where, $query ): string {
		if ( ! $query instanceof WP_Query || ! current_user_can( 'upload_files' ) ) {
			return (string) $where;
		}
		$scope = $query->get( '_sufm_library_scope' );
		return is_array( $scope ) ? (string) $where . Library_Filter::where( $scope ) : (string) $where;
	}

	/**
	 * Build server-owned query context, rather than a capped post__in list.
	 *
	 * @param array<string,mixed> $overrides Grid query variables.
	 * @return array<string,mixed>|null
	 */
	private function library_scope( array $overrides = array() ): ?array {
		if ( ! ( $this->can_review() || current_user_can( 'sufm_manage_media_folders' ) ) ) {
			return null;
		}
		$owner   = $this->param_int( 'sufm_owner', $overrides );
		$source  = $this->param_key( 'sufm_source', $overrides );
		$status  = $this->param_key( 'sufm_request_status', $overrides );
		$request = $this->param_int( 'sufm_request', $overrides );
		$folder  = $this->param_key( 'sufm_folder', $overrides );
		$mode    = $this->param_key( 'sufm_folder_mode', $overrides );
		$unassigned = $this->param_int( 'sufm_unassigned', $overrides ) > 0;
		$has_folder = '' !== $folder && 'all' !== $mode;
		$source = in_array( $source, array( '', 'userfiles', 'media', 'wordpress' ), true ) ? $source : '';
		$status = in_array( $status, array( '', 'none', Requests::PENDING, Requests::APPROVED, Requests::REVOKED ), true ) ? $status : '';
		if ( $owner <= 0 && '' === $source && '' === $status && $request <= 0 && ! $has_folder && ! $unassigned ) {
			return null;
		}
		$scope = compact( 'owner', 'source', 'status', 'request', 'unassigned' );
		if ( $has_folder ) {
			if ( ! ctype_digit( $folder ) ) {
				$scope['invalid'] = true;
				return $scope;
			}
			$folder_id = (int) $folder;
			$folder_owner = $folder_id > 0 ? $this->folder_owner( $folder_id ) : $owner;
			if ( $folder_owner <= 0 || ( $owner > 0 && $owner !== $folder_owner ) ) {
				$scope['invalid'] = true;
				return $scope;
			}
			$scope['owner'] = $folder_owner;
			$scope['folder_ids'] = array( $folder_id );
			if ( $folder_id > 0 && 'subtree' === $mode ) {
				global $wpdb;
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,parent_id FROM ' . Schema::table( 'folders' ) . ' WHERE owner_id=%d AND lifecycle=%s', $folder_owner, 'active' ) );
				$children = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$children[ (int) $row->parent_id ][] = (int) $row->id;
				}
				$seen = array( $folder_id => true );
				$queue = array( $folder_id );
				while ( $queue ) {
					$current = array_shift( $queue );
					foreach ( $children[ $current ] ?? array() as $child ) {
						if ( ! isset( $seen[ $child ] ) ) {
							$seen[ $child ] = true;
							$queue[] = $child;
							$scope['folder_ids'][] = $child;
						}
					}
				}
			}
		}
		return $scope;
	}

	/**
	 * Add the read-only summary to the attachment details fields.
	 *
	 * @param array<string, mixed> $form_fields Attachment form fields.
	 * @param mixed                $post        Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_summary_field( $form_fields, $post ) {
		if ( ! is_array( $form_fields ) || ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return $form_fields;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return $form_fields;
		}

		// The summary names the owning account and the virtual folder path.
		// Editors with `upload_files` may open any attachment in the media
		// library, so only reveal another account's ledger to an operator who
		// is explicitly trusted with the file space.
		if ( ! current_user_can( Capabilities::MANAGE_MEDIA ) && (int) $post->post_author !== get_current_user_id() ) {
			return $form_fields;
		}

		if ( isset( $form_fields['sufm_summary'] ) ) {
			return $form_fields;
		}

		$summary = $this->summary_for( (int) $post->ID );

		$form_fields['sufm_summary'] = array(
			'label' => __( '用户文件空间', 'sakura-imgopt' ),
			'input' => 'html',
			'html'  => $this->summary_html( $summary ),
			'helps' => __( '只读摘要：删除必须由用户提交申请并经管理员批准。', 'sakura-imgopt' ),
		);

		return $form_fields;
	}

	/**
	 * The summary is read-only; nothing is saved.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 * @return array<string, mixed>
	 */
	public function save_summary_field( $post, $attachment ) {
		return $post;
	}

	/**
	 * Last line of defense against direct attachment deletion.
	 *
	 * @param mixed $delete       Short-circuit value from earlier filters.
	 * @param mixed $post         Attachment post.
	 * @param bool  $force_delete Whether to bypass the trash.
	 * @return mixed
	 */
	public function guard_delete( $delete, $post, $force_delete ) {
		if ( $delete instanceof WP_Error ) {
			return $delete;
		}

		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return $delete;
		}

		$post_id = (int) $post->ID;

		/**
		 * Allow the approved deletion executor to delete managed attachments.
		 *
		 * The third argument is reserved for the executor request ID.
		 */
		$allowed = (bool) apply_filters( 'sufm_allow_attachment_delete', false, $post_id, 0 );
		if ( $allowed ) {
			return $delete;
		}

		// An attachment without a ledger row must not slip past this guard just
		// because nobody adopted it yet: adopt on sight, then decide normally.
		$file = $this->adopt_unmanaged( $post_id );

		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return $delete;
		}

		/* Media-library imports belong to the site's own media library: an
		 * administrator who may delete posts can remove them directly. Files
		 * uploaded through the user file space stay behind the request flow. */
		if (
			Files::SOURCE_MEDIA === (string) $file->source
			&& 'active' === (string) $file->lifecycle
			&& (int) $file->pending_request_id <= 0
			&& current_user_can( Capabilities::DELETE_FILES )
			&& current_user_can( 'delete_post', $post_id )
		) {
			$this->direct_deletes[ $post_id ] = true;

			return $delete;
		}

		/* Core returns whatever this filter returns, and the media list only
		 * checks truthiness — handing back a WP_Error made a blocked delete
		 * report success. false is the one answer that stops the delete and
		 * still reads as a failure to the caller. */
		return false;
	}

	/**
	 * Settle the ledger after an administrator removed a media-library import.
	 *
	 * `deleted_post` fires once the attachment row is gone. Deletions run by
	 * the approved-request executor are skipped here because that executor
	 * already charges the quota back in its own purge step.
	 *
	 * @param int          $post_id Attachment ID.
	 * @param WP_Post|null $post    Deleted post object.
	 * @return void
	 */
	public function finalize_deleted_attachment( $post_id, $post = null ): void {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( empty( $this->direct_deletes[ $post_id ] ) ) {
			return;
		}
		unset( $this->direct_deletes[ $post_id ] );

		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return;
		}

		$file = $this->files->get_by_attachment( $post_id );
		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return;
		}

		$wpdb->delete( Schema::table( 'artifacts' ), array( 'file_id' => (int) $file->id ), array( '%d' ) );

		Db_Transaction::begin();
		if ( $this->quota && (int) $file->charged_bytes > 0 ) {
			$this->quota->adjust_used( (int) $file->owner_id, -1 * (int) $file->charged_bytes );
		}
		$this->files->set_lifecycle( (int) $file->id, 'purged', 'deleted' );
		Db_Transaction::commit();

		if ( $this->events ) {
			$this->events->log(
				array(
					'actor_id'    => get_current_user_id(),
					'owner_id'    => (int) $file->owner_id,
					'subject_id'  => $post_id,
					'action'      => 'media_delete',
					'result'      => 'purged',
					'safe_detail' => 'direct',
				)
			);
		}
	}

	/**
	 * Reject core REST media deletes for managed attachments before dispatch.
	 *
	 * @param mixed $result  Pre-dispatch result.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function guard_rest_delete( $result, $server, $request ) {
		if ( $result instanceof WP_Error || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$method = strtoupper( (string) $request->get_method() );
		if ( ! in_array( $method, array( 'DELETE', 'POST' ), true ) ) {
			return $result;
		}

		$route = rtrim( (string) $request->get_route(), '/' );
		if ( 0 !== strpos( $route, '/wp/v2/' ) ) {
			return $result;
		}

		if ( 'DELETE' === $method && preg_match( '#^/wp/v2/(?:media|attachments)/(\d+)$#', $route, $matches ) ) {
			$attachment_id = (int) $matches[1];

			if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
				return $result;
			}

			if ( $this->is_managed_attachment( $attachment_id ) ) {
				return $this->managed_error();
			}

			return $result;
		}

		if ( 'POST' === $method && preg_match( '#^/wp/v2/(?:media|attachments)/batch$#', $route ) ) {
			$ids = $this->batch_attachment_ids( $request );
			if ( $ids && $this->batch_contains_managed( $ids ) ) {
				return $this->managed_error();
			}
		}

		return $result;
	}

	/**
	 * Add the optional bulk action that leads to the front-end user center.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( Capabilities::REQUEST_DELETE ) ) {
			return $actions;
		}

		$actions['sufm_request_delete'] = __( '提交删除申请（用户文件空间）', 'sakura-imgopt' );

		return $actions;
	}

	/**
	 * Redirect the bulk action to the user center without changing any data.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action.
	 * @param array  $post_ids    Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'sufm_request_delete' !== $action ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( Capabilities::REQUEST_DELETE ) ) {
			return $redirect_to;
		}

		$fallback = $redirect_to ? $redirect_to : admin_url( 'upload.php' );
		$ids      = $this->normalize_ids( (array) $post_ids );

		if ( ! $ids ) {
			return add_query_arg( 'sufm_bulk_blocked', 1, $fallback );
		}

		$this->prime_summaries( $ids );

		$user_id = get_current_user_id();
		$own     = array();

		foreach ( $ids as $id ) {
			$summary = $this->summaries[ $id ] ?? null;
			if ( ! is_array( $summary ) || empty( $summary['managed'] ) ) {
				continue;
			}

			if ( (int) $summary['owner_id'] !== $user_id ) {
				continue;
			}

			if ( 'active' !== (string) $summary['lifecycle'] || (int) $summary['pending_request_id'] > 0 ) {
				continue;
			}

			if ( 'trash' === get_post_status( $id ) ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$own[] = $id;
		}

		if ( ! $own ) {
			return add_query_arg( 'sufm_bulk_blocked', 1, $fallback );
		}

		$target = function_exists( 'zib_get_user_center_url' )
			? zib_get_user_center_url( 'files' )
			: home_url( '/user/' );

		return add_query_arg(
			array( 'sufm_request_ids' => implode( ',', $own ) ),
			$target
		);
	}

	/**
	 * Explain why the bulk action did not redirect to the user center.
	 */

	/**
	 * Mark the media screen so the cabinet can take over folder chrome.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ): string {
		global $pagenow;

		if ( 'upload.php' !== $pagenow || ! current_user_can( 'upload_files' ) ) {
			return (string) $classes;
		}

		/**
		 * Whether the cabinet replaces third-party folder sidebars on the media screen.
		 *
		 * @param bool $takeover Take over.
		 */
		if ( apply_filters( 'sufm_media_folders_takeover', true ) ) {
			$classes .= ' sufm-tree-active ';
		}

		// The look is a setting, so it rides on the body class instead of being
		// baked into the stylesheet: switching style repaints without a rebuild.
		$style = Settings::tree_style();
		$classes .= ' sufm-tree-style-' . ( Settings::STYLE_MAC === $style ? 'mac' : 'windows' ) . ' ';

		$accent = Settings::folder_accent();
		if ( '' !== $accent ) {
			$classes .= ' sufm-accent-' . sanitize_html_class( $accent ) . ' ';
		}

		return (string) $classes;
	}

	/**
	 * Enforce the file-space policy on media-library uploads.
	 *
	 * The media library (plupload, `async-upload.php`, REST `/wp/v2/media`) runs
	 * `wp_handle_upload()`, which the plugin's own pipeline never touches. Rather
	 * than forking that pipeline we hook the documented prefilter and apply the
	 * same three rules the pipeline applies up front: type allow list, per-file
	 * size ceiling and remaining space. Files that already passed the REST
	 * pipeline are skipped so a single upload is never judged twice.
	 *
	 * Rejecting here is the only honest option: `add_attachment` fires after the
	 * file is on disk and the attachment row exists, so a post-hoc check could
	 * only delete something the editor already saw succeed.
	 *
	 * @param array<string, mixed> $file Upload array.
	 * @return array<string, mixed>
	 */
	public function guard_media_upload( $file ) {
		if ( ! is_array( $file ) ) {
			return $file;
		}

		if ( Uploader::in_pipeline() ) {
			return $file;
		}

		// AJAX and REST uploads carry a logged-in user; anything else (imports,
		// cron, CLI) is left to WordPress' own checks.
		$owner = get_current_user_id();
		if ( ! $owner || ! current_user_can( 'upload_files' ) || ! current_user_can( Capabilities::MANAGE_OWN ) ) {
			return $file;
		}

		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		$settings = Settings::get();
		$name     = (string) ( $file['name'] ?? '' );
		$tmp      = (string) ( $file['tmp_name'] ?? '' );
		$size     = (int) ( $file['size'] ?? 0 );

		$check = wp_check_filetype_and_ext( $tmp, $name );
		$mime  = (string) ( $check['type'] ?? '' );

		if ( '' === $mime || ! Settings::is_allowed_type( $mime ) ) {
			$file['error'] = __( '这个文件类型不在允许列表里，请换一种格式上传。', 'sakura-imgopt' );

			return $file;
		}

		$limit = (int) $settings['max_file_bytes'];

		if ( $limit > 0 && $size > $limit ) {
			$file['error'] = sprintf(
				/* translators: %s: human readable size */
				__( '单个文件不能超过 %s。', 'sakura-imgopt' ),
				size_format( $limit )
			);

			return $file;
		}

		if ( $this->quota ) {
			// An attachment uploaded earlier through a path that skipped adoption
			// is still using space; settle that backlog before judging this file.
			$this->adopt_owner_backlog( $owner );

			$snapshot = $this->quota->snapshot( $owner );

			if ( (int) $snapshot['available'] <= 0 ) {
				$file['error'] = __( '文件空间已用满，请先删除部分文件或联系管理员扩容。', 'sakura-imgopt' );

				return $file;
			}

			if ( $size > (int) $snapshot['available'] ) {
				$file['error'] = sprintf(
					/* translators: %s: human readable size */
					__( '剩余空间不足，这个文件还需要 %s。', 'sakura-imgopt' ),
					size_format( $size - (int) $snapshot['available'] )
				);

				return $file;
			}
		}

		return $file;
	}

	/**
	 * Put a fresh media upload into the folder picked in the uploader.
	 *
	 * The picker sends `sufm_folder` / `sufm_owner` with the multipart request;
	 * the attachment is adopted into the ledger on first sight so the folder
	 * count, quota and front-end tree stay in step.
	 *
	 * @param int $attachment_id New attachment.
	 * @return void
	 */
	public function assign_uploaded( int $attachment_id ): void {
		// The REST pipeline writes its own ledger row after the attachment
		// exists; adopting here would race it into a UNIQUE violation.
		if ( Uploader::in_pipeline() ) {
			return;
		}

		/**
		 * Filters whether a new attachment should stay out of the file-space ledger.
		 *
		 * Bulk restores and historical adoptions create attachment rows for files
		 * that were never uploaded through the file space; charging them to the
		 * author's quota would be wrong.
		 *
		 * @param bool $skip   Whether to skip adoption.
		 * @param int  $attachment_id Attachment id.
		 */
		if ( apply_filters( 'sufm_skip_attachment_adoption', false, $attachment_id ) ) {
			return;
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		// The author is the only owner we ever charge. `sufm_owner` used to be
		// read from the request, which meant a stale or forged value could point
		// adoption at the wrong account; the post is the authority.
		$owner = (int) $post->post_author;
		if ( $owner <= 0 ) {
			return;
		}

		$folder_id = 0;
		$user_id   = get_current_user_id();

		// Only the uploading user may pick a destination folder.
		if ( $user_id && $user_id === $owner && isset( $_POST['sufm_folder'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Upload request carries its own nonce.
			$wanted = absint( wp_unslash( $_POST['sufm_folder'] ) );
			if ( $wanted > 0 && $this->folders->get( $owner, $wanted ) ) {
				$folder_id = $wanted;
			}
		}

		$file = $this->adopt_unmanaged( $attachment_id );

		if ( $file && $folder_id > 0 && (int) $file->folder_id !== $folder_id ) {
			$this->files->move( $owner, (int) $file->id, $folder_id, $this->folders );
		}
	}

	/**
	 * Adopt an attachment that has no ledger row yet.
	 *
	 * Media-library uploads reach WordPress through paths the plugin's own
	 * pipeline never touches (REST, `media_handle_upload()`, imports). Adoption
	 * used to wait for the background sync, so an attachment could be uploaded,
	 * served and deleted without ever being counted — which let it bypass both
	 * the quota and the delete guard. Adopting on first sight closes that hole.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null Ledger row, or null when it cannot be adopted.
	 */
	private function adopt_unmanaged( int $attachment_id ) {
		$file = $this->files->get_by_attachment( $attachment_id );

		if ( $file ) {
			return $file;
		}

		if ( ! $this->sync ) {
			return null;
		}

		// One implementation of "create the row, then charge the recorded
		// bytes". The bucket-download path (Sync_Actions::download()) runs the
		// same service, so the two entry points cannot drift apart.
		( new Media_Adoption( $this->files, $this->quota, $this->sync ) )->adopt( $attachment_id );

		$file = $this->files->get_by_attachment( $attachment_id );

		return $file ?: null;
	}

	/**
	 * Adopt an owner's unmanaged attachments, newest first.
	 *
	 * Called before a quota decision so that a user cannot keep uploading past
	 * their limit by sending files through a path that skipped adoption.
	 *
	 * @param int $owner_id Owner.
	 * @param int $limit    How many rows to adopt in one pass.
	 * @return int Number adopted.
	 */
	public function adopt_owner_backlog( int $owner_id, int $limit = 50 ): int {
		global $wpdb;

		if ( $owner_id <= 0 || ! $this->sync || $limit < 1 ) {
			return 0;
		}

		$files = Schema::table( 'files' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$files} f ON f.attachment_id = p.ID WHERE p.post_type='attachment' AND p.post_author=%d AND f.id IS NULL ORDER BY p.ID DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_id,
				$limit
			)
		);

		$adopted = 0;

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( $this->adopt_unmanaged( (int) $id ) ) {
				$adopted++;
			}
		}

		return $adopted;
	}

	/**
	 * Styles and behaviour for the media-library folder cabinet.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function enqueue_folder_panel( $hook ): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		if ( 'upload.php' !== $hook && 'media-new.php' !== $hook ) {
			$modal_css = SAKURA_TOOLS_PATH . 'assets/ui/wp-media/media-modal-folders.css';

			if ( file_exists( $modal_css ) ) {
				wp_enqueue_style( 'sufm-media-modal', SAKURA_TOOLS_URL . 'assets/ui/wp-media/media-modal-folders.css', array(), \Nboke\UserFiles\Support\Assets::version( 'assets/ui/wp-media/media-modal-folders.css' ) );
			}

			$modal = SAKURA_TOOLS_PATH . 'assets/media-modal-folders.js';
			if ( file_exists( $modal ) ) {
				wp_enqueue_script( 'sufm-media-modal', SAKURA_TOOLS_URL . 'assets/media-modal-folders.js', array(), \Nboke\UserFiles\Support\Assets::version( 'assets/media-modal-folders.js' ), true );
				wp_localize_script(
					'sufm-media-modal',
					'sufmFolders',
					array(
						'restUrl' => esc_url_raw( rest_url( 'sufm/v1/' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'userId'  => get_current_user_id(),
						'strings' => array(
							'uploadTo'      => __( '上传到文件夹', 'sakura-imgopt' ),
							'uncategorized' => __( '我的文件（根目录）', 'sakura-imgopt' ),
							'uploadRoot' => __( '我的文件（根目录）', 'sakura-imgopt' ),
							'requestFailed' => __( '请求失败，请重试。', 'sakura-imgopt' ),
						),
					)
				);
			}
			return;
		}

		if ( 'media-new.php' === $hook ) {
			$css = SAKURA_TOOLS_PATH . 'assets/ui/wp-media/media-new-folders.css';
			$js  = SAKURA_TOOLS_PATH . 'assets/media-new-folders.js';
			if ( file_exists( $css ) ) {
				wp_enqueue_style( 'sufm-media-new', SAKURA_TOOLS_URL . 'assets/ui/wp-media/media-new-folders.css', array(), \Nboke\UserFiles\Support\Assets::version( 'assets/ui/wp-media/media-new-folders.css' ) );
			}
			if ( file_exists( $js ) ) {
				wp_enqueue_script( 'sufm-media-new', SAKURA_TOOLS_URL . 'assets/media-new-folders.js', array(), \Nboke\UserFiles\Support\Assets::version( 'assets/media-new-folders.js' ), true );
				wp_localize_script(
					'sufm-media-new',
					'sufmFolders',
					array(
						'restUrl' => esc_url_raw( rest_url( 'sufm/v1/' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'userId'  => get_current_user_id(),
						'strings' => array(
							'uploadTo'    => __( '上传到文件夹', 'sakura-imgopt' ),
							'uncategorized' => __( '我的文件（根目录）', 'sakura-imgopt' ),
							'uploadRoot' => __( '我的文件（根目录）', 'sakura-imgopt' ),
							'noFolders'   => __( '还没有文件夹，上传后可在前台文件管理中新建。', 'sakura-imgopt' ),
							'requestFailed' => __( '请求失败，请重试。', 'sakura-imgopt' ),
							/* translators: %d: number of files being uploaded */
							'uploading'   => __( '正在上传 %d 个文件…', 'sakura-imgopt' ),
							/* translators: %d: number of uploaded files */
							'uploaded'    => __( '%d 个文件已上传完成。', 'sakura-imgopt' ),
							/* translators: %s: error message */
							'uploadFailed' => __( '上传失败：%s', 'sakura-imgopt' ),
							'uploadingTitle' => __( '正在上传', 'sakura-imgopt' ),
							'uploadedTitle' => __( '上传完成', 'sakura-imgopt' ),
							'uploadFailedTitle' => __( '上传失败', 'sakura-imgopt' ),
						),
					)
				);
			}
			return;
		}

		$css = SAKURA_TOOLS_PATH . 'assets/media-folders.css';
		$js  = SAKURA_TOOLS_PATH . 'assets/media-folders.js';
		if ( ! file_exists( $css ) || ! file_exists( $js ) ) {
			return;
		}

		wp_enqueue_style( 'sufm-media-folders', SAKURA_TOOLS_URL . 'assets/media-folders.css', array(), \Nboke\UserFiles\Support\Assets::version( 'assets/media-folders.css' ) );
		wp_enqueue_media();
		wp_enqueue_script( 'sufm-media-folders', SAKURA_TOOLS_URL . 'assets/media-folders.js', array( 'media-views', 'media-grid' ), \Nboke\UserFiles\Support\Assets::version( 'assets/media-folders.js' ), true );
		wp_localize_script(
			'sufm-media-folders',
			'sufmFolders',
			array(
				'restUrl' => esc_url_raw( rest_url( 'sufm/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'userId'  => get_current_user_id(),
				'strings' => array(
					'requestFailed' => __( '请求失败，请重试。', 'sakura-imgopt' ),
					'refreshFailed' => __( '列表刷新失败，请刷新页面查看最新结果。', 'sakura-imgopt' ),
					/* translators: %d: selected item count */
					'selected' => __( '已选 %d 项', 'sakura-imgopt' ),
					'moveTo' => __( '移动到', 'sakura-imgopt' ),
					'cancelSelection' => __( '取消选择', 'sakura-imgopt' ),
					'assignOwner' => __( '指定归属', 'sakura-imgopt' ),
					'assignHint' => __( '勾选下方媒体，再点「指定归属」把它们分给某个账号', 'sakura-imgopt' ),
					'unassignedNote' => __( '这里列出还没有归属账号的媒体。勾选要分配的文件，点上方「指定归属」，选择目标账号即可。', 'sakura-imgopt' ),
					'uncategorized' => __( '未归类', 'sakura-imgopt' ),
					'uploadRoot' => __( '我的文件（根目录）', 'sakura-imgopt' ),
					'allMedia' => __( '全部媒体', 'sakura-imgopt' ),
					'unassignedMedia' => __( '未分配媒体', 'sakura-imgopt' ),
					'collapse' => __( '收起', 'sakura-imgopt' ),
					'expand' => __( '展开', 'sakura-imgopt' ),
					'moreActions' => __( '更多操作', 'sakura-imgopt' ),
					'folderSameOwner' => __( '整个文件夹只能在同一用户下整理。', 'sakura-imgopt' ),
					'newFolder' => __( '新建文件夹', 'sakura-imgopt' ),
					'onlyUncategorized' => __( '只看未归类', 'sakura-imgopt' ),
					'loading' => __( '正在加载…', 'sakura-imgopt' ),
					'noUsers' => __( '没有匹配的用户。', 'sakura-imgopt' ),
					'loadMore' => __( '加载更多用户', 'sakura-imgopt' ),
					'includingChildren' => __( '含子文件夹', 'sakura-imgopt' ),
					'open' => __( '打开', 'sakura-imgopt' ),
					'newSubfolder' => __( '新建子文件夹', 'sakura-imgopt' ),
					'rename' => __( '重命名', 'sakura-imgopt' ),
					'moveRoot' => __( '移到用户根', 'sakura-imgopt' ),
					'deleteFolder' => __( '删除文件夹', 'sakura-imgopt' ),
					/* translators: %s: folder name */
					'deleteFolderConfirm' => __( '删除「%s」？文件与子目录移到上一层，不删除媒体。', 'sakura-imgopt' ),
					'folderUpdated' => __( '文件夹已更新。', 'sakura-imgopt' ),
					'newFolderName' => __( '新建文件夹名称', 'sakura-imgopt' ),
					'cancel' => __( '取消', 'sakura-imgopt' ),
					'continue' => __( '继续', 'sakura-imgopt' ),
					'searchUsers' => __( '搜索用户昵称、登录名或 ID', 'sakura-imgopt' ),
					'expandFolders' => __( '展开该用户文件夹', 'sakura-imgopt' ),
					'transferTitle' => __( '转交或纳入用户文件空间', 'sakura-imgopt' ),
					/* translators: %d: number of media items */
					'transferDescription' => __( '将 %d 项媒体归属到以下用户及目录，请确认容量结算。', 'sakura-imgopt' ),
					/* translators: %d: source user ID */
					'sourceRelease' => __( '来源用户 #%d 释放', 'sakura-imgopt' ),
					'incomingBytes' => __( '本次计入', 'sakura-imgopt' ),
					'usedBytes' => __( '目标已用', 'sakura-imgopt' ),
					'reservedBytes' => __( '上传预留', 'sakura-imgopt' ),
					'quotaBytes' => __( '目标额度', 'sakura-imgopt' ),
					'afterTransfer' => __( '转交后已用', 'sakura-imgopt' ),
					'transferNote' => __( '改变文件空间归属与附件作者，保留原上传者记录；文件链接及存储对象保持不变。确认时重新校验冻结状态、版本与额度。', 'sakura-imgopt' ),
					'confirmTransfer' => __( '确认转交', 'sakura-imgopt' ),
					/* translators: %m: moved count, %u: already-in-target count, %s: skipped count (replaced by the browser, not by PHP) */
					'moveResult' => __( '已移动 %m 项；已在目标 %u 项；跳过 %s 项。', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
					/* translators: %d: number of items */
					'dragCount' => __( '移动 %d 项', 'sakura-imgopt' ),
					'chooseUserFirst' => __( '请先打开一个用户或文件夹，再新建目录。', 'sakura-imgopt' ),
					'uploadTarget' => __( '上传目标文件夹', 'sakura-imgopt' ),
					/* translators: %d: number of files */
					'uploading' => __( '正在上传 %d 个文件…', 'sakura-imgopt' ),
					/* translators: %d: number of files */
					'uploaded' => __( '%d 个文件已上传完成。', 'sakura-imgopt' ),
					/* translators: %s: error message */
					'uploadFailed' => __( '上传失败：%s', 'sakura-imgopt' ),
					'uploadingTitle' => __( '正在上传', 'sakura-imgopt' ),
					'uploadedTitle' => __( '上传完成', 'sakura-imgopt' ),
					'uploadFailedTitle' => __( '上传失败', 'sakura-imgopt' ),
					'uploadRefresh' => __( '正在刷新媒体库…', 'sakura-imgopt' ),
					/* translators: %d: number of deleted files */
					'deleteDone' => __( '已删除 %d 个文件。', 'sakura-imgopt' ),
					/* translators: 1: deleted count, 2: failed count */
					'deletePartial' => __( '已删除 %d 个文件，%s 个未能删除（可能是用户中心上传的文件）。', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
				)
			)
		);
	}

	/**
	 * Folder cabinet beside the media list, shared with the front-end tree.
	 *
	 * @return void
	 */
	public function render_folder_panel(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$folder = isset( $_GET['sufm_folder'] ) && is_scalar( $_GET['sufm_folder'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sufm_folder'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$owner = isset( $_GET['sufm_owner'] ) && is_scalar( $_GET['sufm_owner'] ) ? absint( wp_unslash( (string) $_GET['sufm_owner'] ) ) : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		$unassigned = isset( $_GET['sufm_unassigned'] ) && is_scalar( $_GET['sufm_unassigned'] ) && '1' === (string) $_GET['sufm_unassigned'] ? '1' : '';
		echo '<button type="button" class="button" id="sufm-tree-toggle" aria-expanded="false" aria-controls="sufm-tree">' . esc_html__( '文件夹', 'sakura-imgopt' ) . '</button>';
		echo '<button type="button" id="sufm-tree-shade" tabindex="-1" aria-label="' . esc_attr__( '关闭文件夹', 'sakura-imgopt' ) . '"></button>';
		echo '<aside class="sufm-tree" id="sufm-tree" aria-label="' . esc_attr__( '用户文件夹', 'sakura-imgopt' ) . '" data-folder="' . esc_attr( $folder ) . '" data-owner="' . esc_attr( (string) $owner ) . '" data-unassigned="' . esc_attr( $unassigned ) . '">';
		echo '<div class="sufm-tree-head"><span class="sufm-tree-title">' . esc_html__( '用户文件夹', 'sakura-imgopt' ) . '</span><span class="sufm-tree-tools">';
		echo '<button type="button" class="button button-primary" id="sufm-tree-new" title="' . esc_attr__( '新建文件夹', 'sakura-imgopt' ) . '" aria-label="' . esc_attr__( '新建文件夹', 'sakura-imgopt' ) . '">' . esc_html__( '新文件夹', 'sakura-imgopt' ) . '</button>';
		echo '<button type="button" class="button button-small" id="sufm-tree-close" aria-label="' . esc_attr__( '关闭文件夹', 'sakura-imgopt' ) . '">×</button></span></div>';
		echo '<div class="sufm-tree-actions">';
		echo '<button type="button" class="button" id="sufm-tree-rename" disabled>' . esc_html__( '重命名', 'sakura-imgopt' ) . '</button>';
		echo '<button type="button" class="button" id="sufm-tree-delete" disabled>' . esc_html__( '删除', 'sakura-imgopt' ) . '</button>';
		echo '<button type="button" class="button" id="sufm-tree-subfolder" disabled>' . esc_html__( '子文件夹', 'sakura-imgopt' ) . '</button></div>';
		echo '<label class="sufm-tree-search"><span class="screen-reader-text">' . esc_html__( '搜索用户', 'sakura-imgopt' ) . '</span><input type="search" id="sufm-tree-search" placeholder="' . esc_attr__( '搜索用户昵称、登录名或 ID', 'sakura-imgopt' ) . '"></label>';
		echo '<div class="sufm-tree-path" id="sufm-tree-path"></div>';
		echo '<ul class="sufm-tree-list" id="sufm-tree-list" role="tree" aria-label="' . esc_attr__( '用户与文件夹', 'sakura-imgopt' ) . '"></ul>';
		echo '</aside>';
		echo '<button type="button" class="sufm-tree-handle" id="sufm-tree-handle" aria-controls="sufm-tree" aria-expanded="true" title="' . esc_attr__( '收起 / 展开文件夹', 'sakura-imgopt' ) . '"><span class="sufm-tree-handle-ico" aria-hidden="true"></span></button>';
	}

	/**
	 * Step in before WordPress' own media-list delete runs.
	 *
	 * The generic guard can only refuse one attachment at a time, and the list
	 * screen turns that refusal into a wp_die page. Checking the POST up front
	 * lets a managed selection bounce back to the list with a message that says
	 * where the approved path lives.
	 *
	 * @return void
	 */
	public function preflight_bulk_delete(): void {
		/* The media list submits its own filter form, so the bulk action can
		 * arrive over GET as well. Verify the same nonce core will check, and
		 * leave anything unsigned to core so a crafted link cannot make us
		 * redirect on someone else's behalf. */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified immediately below.
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( 'delete' !== $action ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only to verify.
		$nonce = isset( $_REQUEST['_wpnonce'] ) && is_scalar( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-media' ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		$ids = isset( $_REQUEST['media'] ) ? (array) wp_unslash( $_REQUEST['media'] ) : array();
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		$blocked = 0;
		foreach ( $ids as $id ) {
			// Media-library imports may be deleted from the library itself; only
			// files that belong to the user file space need the request flow.
			if ( $this->is_managed_attachment( $id ) ) {
				$blocked++;
			}
		}

		if ( $blocked < 1 ) {
			return;
		}

		$back = remove_query_arg( array( 'action', 'media', '_wpnonce', '_wp_http_referer', 'deleted', 'sufm_delete_blocked' ) );
		wp_safe_redirect( add_query_arg( 'sufm_delete_blocked', $blocked, $back ) );
		exit;
	}

	public function render_bulk_notice(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		$blocked = isset( $_GET['sufm_bulk_blocked'] ) && is_scalar( $_GET['sufm_bulk_blocked'] )
			? absint( wp_unslash( (string) $_GET['sufm_bulk_blocked'] ) )
			: 0;

		$delete_blocked = isset( $_GET['sufm_delete_blocked'] ) && is_scalar( $_GET['sufm_delete_blocked'] )
			? absint( wp_unslash( (string) $_GET['sufm_delete_blocked'] ) )
			: 0;

			if ( $delete_blocked > 0 ) {
				$html = \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html(
					esc_html(
						sprintf(
							/* translators: %d: number of managed attachments. */
							__( '有 %d 个文件属于“用户文件空间”，不能从媒体库直接删除。请让文件所有者到用户中心提交删除申请，管理员在“樱花工具箱 → 文件管理”批准并执行。', 'sakura-imgopt' ),
							$delete_blocked
						)
					),
					'warn'
				);
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
				return;
			}

			if ( $blocked < 1 ) {
				return;
			}

			echo \Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
				esc_html__( '未跳转：只能选择你本人名下、未在删除申请中的“用户文件空间”文件。本次操作未修改任何数据。', 'sakura-imgopt' ),
				'warn'
			);
		}

	/**
	 * Render the owner filter. Administrators only.
	 *
	 * @param int $current Currently selected owner ID.
	 */
	private function render_owner_filter( int $current ): void {
		$users = get_users(
			array(
				'number'  => 200,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		echo '<label class="screen-reader-text" for="sufm-owner-filter">'
			. esc_html__( '按所有者筛选', 'sakura-imgopt' )
			. '</label>';
		echo '<select name="sufm_owner" id="sufm-owner-filter" class="postform">';
		echo '<option value="">' . esc_html__( '所有所有者', 'sakura-imgopt' ) . '</option>';

		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $user->ID,
				selected( $current, (int) $user->ID, false ),
				esc_html( (string) $user->display_name )
			);
		}

		echo '</select>';
	}

	/**
	 * Render the source filter.
	 *
	 * @param string $current Currently selected source.
	 */
	private function render_source_filter( string $current ): void {
		$options = array(
			''          => __( '所有来源', 'sakura-imgopt' ),
			'userfiles' => __( '用户文件空间', 'sakura-imgopt' ),
			'media'     => __( '媒体库导入', 'sakura-imgopt' ),
			'wordpress' => __( 'WordPress 原库', 'sakura-imgopt' ),
		);

		echo '<label class="screen-reader-text" for="sufm-source-filter">'
			. esc_html__( '按来源筛选', 'sakura-imgopt' )
			. '</label>';
		echo '<select name="sufm_source" id="sufm-source-filter" class="postform">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render the delete request status filter.
	 *
	 * @param string $current Currently selected status.
	 */
	private function render_request_filter( string $current ): void {
		$options = array(
			''                  => __( '所有申请状态', 'sakura-imgopt' ),
			'none'              => __( '无申请', 'sakura-imgopt' ),
			Requests::PENDING   => __( '待审核', 'sakura-imgopt' ),
			Requests::APPROVED  => __( '已批准', 'sakura-imgopt' ),
			Requests::REVOKED   => __( '已撤销', 'sakura-imgopt' ),
		);

		echo '<label class="screen-reader-text" for="sufm-request-filter">'
			. esc_html__( '按删除申请状态筛选', 'sakura-imgopt' )
			. '</label>';
		echo '<select name="sufm_request_status" id="sufm-request-filter" class="postform">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Normalize a list of IDs, preserving the zero sentinel.
	 *
	 * @param mixed $value Raw ID value.
	 * @return array<int, int>
	 */
	private function normalize_ids( $value ): array {
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

			$id = (int) $id;
			if ( $id >= 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Read a positive integer filter value.
	 *
	 * @param string               $key       Query key.
	 * @param array<string, mixed> $overrides Optional overrides.
	 */
	private function param_int( string $key, array $overrides ): int {
		if ( array_key_exists( $key, $overrides ) ) {
			return is_scalar( $overrides[ $key ] ) ? absint( $overrides[ $key ] ) : 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			return absint( wp_unslash( (string) $_GET[ $key ] ) );
		}

		return 0;
	}

	/**
	 * Read a sanitized key filter value.
	 *
	 * @param string               $key       Query key.
	 * @param array<string, mixed> $overrides Optional overrides.
	 */
	private function param_key( string $key, array $overrides ): string {
		if ( array_key_exists( $key, $overrides ) ) {
			return is_scalar( $overrides[ $key ] ) ? sanitize_key( (string) $overrides[ $key ] ) : '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			return sanitize_key( wp_unslash( (string) $_GET[ $key ] ) );
		}

		return '';
	}

	/**
	 * Owner of a folder row, or 0 when it is gone.
	 *
	 * @param int $folder_id Folder id.
	 * @return int
	 */
	private function folder_owner( int $folder_id ): int {
		$folder = $this->folder_rows[ $folder_id ] ?? null;

		if ( ! $folder ) {
			global $wpdb;
			$folder = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'folders' ) . ' WHERE id=%d AND lifecycle=%s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$folder_id,
					'active'
				)
			);
			$this->folder_rows[ $folder_id ] = $folder ?: null;
		}

		return $folder ? (int) $folder->owner_id : 0;
	}

	/**
	 * Prime summaries for all attachments on the current media list page.
	 */
	private function prime_list_summaries(): void {
		if ( $this->list_primed ) {
			return;
		}

		$this->list_primed = true;
		$ids = $this->list_attachment_ids();

		if ( $ids ) {
			$this->prime_summaries( $ids );
		}
	}

	/**
	 * Return attachment IDs from the current list table page.
	 *
	 * @return array<int, int>
	 */
	private function list_attachment_ids(): array {
		$ids = array();

		$table = isset( $GLOBALS['wp_list_table'] ) ? $GLOBALS['wp_list_table'] : null;
		if ( is_object( $table ) && isset( $table->items ) && is_array( $table->items ) ) {
			foreach ( $table->items as $item ) {
				if ( is_object( $item ) && isset( $item->ID ) ) {
					$ids[] = (int) $item->ID;
				} elseif ( is_array( $item ) && isset( $item['ID'] ) ) {
					$ids[] = (int) $item['ID'];
				}
			}
		}

		if ( ! $ids ) {
			global $wp_query;
			if ( $wp_query instanceof WP_Query && is_array( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					if ( $post instanceof WP_Post ) {
						$ids[] = (int) $post->ID;
					}
				}
			}
		}

		return $this->normalize_ids( $ids );
	}

	/**
	 * Return a summary for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	private function summary_for( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $attachment_id, $this->summaries ) ) {
			$this->prime_summaries( array( $attachment_id ) );
		}

		return $this->summaries[ $attachment_id ] ?? null;
	}

	/**
	 * Prime summaries, users, folders, and latest requests in batch.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 */
	private function prime_summaries( array $attachment_ids ): void {
		global $wpdb;

		$ids = $this->normalize_ids( $attachment_ids );
		if ( ! $ids ) {
			return;
		}

		$missing = array();
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->summaries ) ) {
				$missing[] = $id;
			}
		}

		if ( ! $missing ) {
			return;
		}

		$table        = Schema::table( 'files' );
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$sql          = "SELECT attachment_id, owner_id, folder_id, display_name, source, lifecycle, charged_bytes, pending_request_id FROM {$table} WHERE attachment_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $missing ) );
		$by_id = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_id[ (int) $row->attachment_id ] = $row;
		}

		$owner_ids  = array();
		$folder_ids = array();

		foreach ( $missing as $id ) {
			$managed = isset( $by_id[ $id ] ) && 'purged' !== (string) $by_id[ $id ]->lifecycle;

			$this->summaries[ $id ] = array(
				'attachment_id'      => $id,
				'managed'            => $managed,
				'owner_id'           => 0,
				'folder_id'          => 0,
				'display_name'       => '',
				'source'             => '',
				'lifecycle'          => '',
				'charged_bytes'      => 0,
				'pending_request_id' => 0,
				'owner_name'         => '',
				'folder_path'        => '—',
				'request'            => null,
			);

			if ( ! $managed ) {
				continue;
			}

			$row     = $by_id[ $id ];
			$owner   = (int) $row->owner_id;
			$folder  = (int) $row->folder_id;

			$this->summaries[ $id ]['owner_id']           = $owner;
			$this->summaries[ $id ]['folder_id']          = $folder;
			$this->summaries[ $id ]['display_name']       = (string) $row->display_name;
			$this->summaries[ $id ]['source']             = (string) $row->source;
			$this->summaries[ $id ]['lifecycle']          = (string) $row->lifecycle;
			$this->summaries[ $id ]['charged_bytes']      = (int) $row->charged_bytes;
			$this->summaries[ $id ]['pending_request_id'] = (int) $row->pending_request_id;

			if ( $owner > 0 ) {
				$owner_ids[ $owner ] = $owner;
			}

			if ( $folder > 0 ) {
				$folder_ids[ $folder ] = $folder;
			}
		}

		$this->prime_users( array_values( $owner_ids ) );
		$this->prime_folders( array_values( $folder_ids ) );
		$this->prime_requests( $missing );

		foreach ( $missing as $id ) {
			if ( empty( $this->summaries[ $id ]['managed'] ) ) {
				continue;
			}

			$owner = (int) $this->summaries[ $id ]['owner_id'];

			$this->summaries[ $id ]['owner_name']  = $owner > 0 ? ( $this->user_names[ $owner ] ?? '' ) : '';
			$this->summaries[ $id ]['folder_path'] = $this->folder_path( $owner, (int) $this->summaries[ $id ]['folder_id'] );
			$this->summaries[ $id ]['request']     = $this->request_rows[ $id ] ?? null;

			if ( null === $this->summaries[ $id ]['request'] && (int) $this->summaries[ $id ]['pending_request_id'] > 0 ) {
				$request = $this->requests->get( (int) $this->summaries[ $id ]['pending_request_id'] );
				if ( $request ) {
					$this->summaries[ $id ]['request'] = (object) array(
						'request_id' => (int) $request->id,
						'status'     => (string) $request->status,
						'updated_at' => (string) $request->updated_at,
					);
				}
			}
		}
	}

	/**
	 * Prime user display names with the WordPress user cache.
	 *
	 * @param array<int, int> $owner_ids User IDs.
	 */
	private function prime_users( array $owner_ids ): void {
		$ids = $this->normalize_ids( $owner_ids );
		$missing = array();

		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->user_names ) ) {
				$missing[] = $id;
			}
		}

		if ( ! $missing ) {
			return;
		}

		if ( function_exists( 'cache_users' ) ) {
			cache_users( $missing );
		}

		foreach ( $missing as $id ) {
			$user = get_userdata( $id );
			$this->user_names[ $id ] = $user ? (string) $user->display_name : '';
		}
	}

	/**
	 * Prime folder rows by walking parent batches.
	 *
	 * @param array<int, int> $folder_ids Folder IDs.
	 */
	private function prime_folders( array $folder_ids ): void {
		global $wpdb;

		$pending = $this->normalize_ids( $folder_ids );
		$pending = array_values(
			array_filter(
				$pending,
				function ( int $id ): bool {
					return ! array_key_exists( $id, $this->folder_rows );
				}
			)
		);

		$depth = 0;
		while ( $pending && $depth < 16 ) {
			++$depth;

			$placeholders = implode( ',', array_fill( 0, count( $pending ), '%d' ) );
			$table        = Schema::table( 'folders' );
			$sql          = "SELECT id, owner_id, parent_id, name, lifecycle FROM {$table} WHERE id IN ({$placeholders}) AND lifecycle = 'active'";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $pending ) );

			foreach ( $pending as $id ) {
				$this->folder_rows[ $id ] = null;
			}

			$next = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$id = (int) $row->id;
				$this->folder_rows[ $id ] = $row;

				$parent = (int) $row->parent_id;
				if ( $parent > 0 && ! array_key_exists( $parent, $this->folder_rows ) ) {
					$next[ $parent ] = $parent;
				}
			}

			$pending = array_values( $next );
		}
	}

	/**
	 * Build a folder breadcrumb from the primed folder rows.
	 *
	 * @param int $owner_id  Owner ID.
	 * @param int $folder_id Folder ID.
	 */
	private function folder_path( int $owner_id, int $folder_id ): string {
		if ( $folder_id <= 0 ) {
			return __( '根目录', 'sakura-imgopt' );
		}

		if ( ! array_key_exists( $folder_id, $this->folder_rows ) ) {
			$chain = $this->folders->breadcrumb( $owner_id, $folder_id );
			$names = array();

			foreach ( $chain as $row ) {
				if ( (int) $row->owner_id === $owner_id ) {
					$names[] = (string) $row->name;
				}
			}

			return $names ? implode( ' / ', $names ) : '—';
		}

		$names  = array();
		$cursor = $folder_id;
		$guard  = 0;

		while ( $cursor > 0 && $guard < 64 ) {
			++$guard;

			$row = $this->folder_rows[ $cursor ] ?? null;
			if ( ! $row || (int) $row->owner_id !== $owner_id ) {
				break;
			}

			array_unshift( $names, (string) $row->name );
			$cursor = (int) $row->parent_id;
		}

		return $names ? implode( ' / ', $names ) : '—';
	}

	/**
	 * Prime the latest delete request per attachment.
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 */
	private function prime_requests( array $attachment_ids ): void {
		global $wpdb;

		$ids     = $this->normalize_ids( $attachment_ids );
		$missing = array();

		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->request_rows ) ) {
				$missing[] = $id;
			}
		}

		if ( ! $missing ) {
			return;
		}

		foreach ( $missing as $id ) {
			$this->request_rows[ $id ] = null;
		}

		$items        = Schema::table( 'delete_request_items' );
		$requests     = Schema::table( 'delete_requests' );
		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$sql          = "SELECT i.attachment_id, r.id AS request_id, r.status, r.updated_at
			FROM {$items} i
			INNER JOIN {$requests} r ON r.id = i.request_id
			INNER JOIN (
				SELECT i2.attachment_id, MAX(r2.id) AS max_request_id
				FROM {$items} i2
				INNER JOIN {$requests} r2 ON r2.id = i2.request_id
				WHERE i2.object_type = 'file' AND i2.attachment_id IN ({$placeholders})
				GROUP BY i2.attachment_id
			) latest ON latest.attachment_id = i.attachment_id AND latest.max_request_id = r.id
			WHERE i.object_type = 'file'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $missing ) );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) $row->attachment_id;

			$this->request_rows[ $id ] = (object) array(
				'request_id' => (int) $row->request_id,
				'status'     => (string) $row->status,
				'updated_at' => (string) $row->updated_at,
			);
		}
	}

	/**
	 * Build the read-only attachment summary HTML.
	 *
	 * @param array<string, mixed>|null $summary Summary.
	 */
	private function summary_html( ?array $summary ): string {
		if ( ! is_array( $summary ) ) {
			return '<p>' . esc_html__( '暂无摘要。', 'sakura-imgopt' ) . '</p>';
		}

		$managed = ! empty( $summary['managed'] );
		$source  = $managed ? __( '用户文件空间', 'sakura-imgopt' ) : __( 'WordPress 原库', 'sakura-imgopt' );

		$owner = '—';
		if ( $managed ) {
			$owner_id = (int) ( $summary['owner_id'] ?? 0 );
			$name     = (string) ( $summary['owner_name'] ?? '' );
			$owner    = $owner_id > 0 ? ( '' !== $name ? $name : '#' . $owner_id ) : '—';
		}

		$folder = $managed ? (string) ( $summary['folder_path'] ?? '—' ) : '—';
		$size   = $managed ? (string) size_format( (int) ( $summary['charged_bytes'] ?? 0 ), 2 ) : '—';

		$html  = '<div class="sufm-attachment-summary">';
		$html .= $this->summary_row( __( '来源', 'sakura-imgopt' ), esc_html( $source ) );
		$html .= $this->summary_row( __( '所有者', 'sakura-imgopt' ), esc_html( $owner ) );
		$html .= $this->summary_row( __( '虚拟目录', 'sakura-imgopt' ), esc_html( $folder ) );
		$html .= $this->summary_row( __( '容量', 'sakura-imgopt' ), esc_html( $size ) );
		$html .= $this->summary_row( __( '最近删除申请', 'sakura-imgopt' ), $this->request_html( $summary['request'] ?? null ) );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render one summary row.
	 *
	 * @param string $label      Label text.
	 * @param string $value_html Already escaped value HTML.
	 */
	private function summary_row( string $label, string $value_html ): string {
		return '<p><strong>' . esc_html( $label ) . '：</strong> ' . $value_html . '</p>';
	}

	/**
	 * Render the latest delete request status and review link.
	 *
	 * @param mixed $request Request row.
	 */
	private function request_html( $request ): string {
		if ( ! is_object( $request ) || ! isset( $request->request_id ) ) {
			return esc_html( '—' );
		}

		$request_id = (int) $request->request_id;
		$label      = $this->status_label( (string) ( $request->status ?? '' ) );

		if ( $this->can_review() ) {
			$url = add_query_arg(
				array(
					'page'       => 'sakura-tools-userfiles',
					'request_id' => $request_id,
				),
				admin_url( 'admin.php' )
			);

			return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
				. ' <span class="description">#' . esc_html( (string) $request_id ) . '</span>';
		}

		return esc_html( $label . ' #' . $request_id );
	}

	/**
	 * Map a delete request status to a translated label.
	 *
	 * @param string $status Status key.
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case Requests::PENDING:
				return __( '待审核', 'sakura-imgopt' );
			case Requests::WITHDRAWN:
				return __( '已撤回', 'sakura-imgopt' );
			case Requests::REJECTED:
				return __( '已驳回', 'sakura-imgopt' );
			case Requests::APPROVED:
				return __( '已批准（待执行）', 'sakura-imgopt' );
			case Requests::REVOKED:
				return __( '已撤销', 'sakura-imgopt' );
			case Requests::NEEDS:
				return __( '需重新审核', 'sakura-imgopt' );
			case Requests::QUEUED:
				return __( '排队中', 'sakura-imgopt' );
			case Requests::EXECUTING:
				return __( '执行中', 'sakura-imgopt' );
			case Requests::COMPLETED:
				return __( '已完成', 'sakura-imgopt' );
			case Requests::PARTIAL:
				return __( '部分失败', 'sakura-imgopt' );
			case Requests::FAILED:
				return __( '失败', 'sakura-imgopt' );
			case Requests::STOPPED:
				return __( '已停止', 'sakura-imgopt' );
			default:
				return $status;
		}
	}

	/**
	 * Whether the current user may open the deletion review screen.
	 */
	private function can_review(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( Capabilities::REVIEW );
	}

	/**
	 * Whether an attachment is managed and not yet purged.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function is_managed_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$row = $this->files->get_by_attachment( $attachment_id );

		return $row
			&& Files::SOURCE === (string) $row->source
			&& 'purged' !== (string) $row->lifecycle;
	}

	/**
	 * Extract likely attachment IDs from a media batch REST request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<int, int>
	 */
	private function batch_attachment_ids( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! is_array( $params ) ) {
			return array();
		}

		$ids = array();

		foreach ( array( 'ids', 'media_ids', 'attachment_ids' ) as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$this->collect_batch_values( $params[ $key ], $ids );
			}
		}

		if ( isset( $params['requests'] ) && is_array( $params['requests'] ) ) {
			foreach ( $params['requests'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				foreach ( array( 'id', 'attachment_id', 'media_id' ) as $key ) {
					if ( isset( $entry[ $key ] ) ) {
						$this->collect_batch_values( $entry[ $key ], $ids );
					}
				}

				if ( isset( $entry['path'] ) && is_string( $entry['path'] ) && preg_match( '#/media/(\d+)#', $entry['path'], $matches ) ) {
					$id = (int) $matches[1];
					if ( $id > 0 ) {
						$ids[ $id ] = $id;
					}
				}
			}
		}

		return array_slice( array_values( $ids ), 0, self::MAX_BATCH_IDS );
	}

	/**
	 * Collect numeric IDs from a scalar or nested batch value.
	 *
	 * @param mixed             $value Raw value.
	 * @param array<int, int>   $ids   Collected IDs.
	 * @param int               $depth Recursion depth.
	 */
	private function collect_batch_values( $value, array &$ids, int $depth = 0 ): void {
		if ( $depth > 3 ) {
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->collect_batch_values( $item, $ids, $depth + 1 );
			}
			return;
		}

		if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
			foreach ( explode( ',', $value ) as $part ) {
				$this->collect_batch_values( $part, $ids, $depth + 1 );
			}
			return;
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
			return;
		}

		if ( is_string( $value ) && preg_match( '#/media/(\d+)#', $value, $matches ) ) {
			$id = (int) $matches[1];
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
	}

	/**
	 * Whether a REST batch contains a managed, not-yet-purged attachment.
	 *
	 * @param array<int, int> $ids Attachment IDs.
	 */
	private function batch_contains_managed( array $ids ): bool {
		global $wpdb;

		$ids = $this->normalize_ids( $ids );
		if ( ! $ids ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$post_sql     = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
		$attachment_ids = $wpdb->get_col( $wpdb->prepare( $post_sql, array_merge( array( 'attachment' ), $ids ) ) );
		$attachment_ids = array_map( 'intval', is_array( $attachment_ids ) ? $attachment_ids : array() );

		$check = array();
		foreach ( $attachment_ids as $id ) {
			if ( $id > 0 && current_user_can( 'delete_post', $id ) ) {
				$check[] = $id;
			}
		}

		if ( ! $check ) {
			return false;
		}

		$table        = Schema::table( 'files' );
		$placeholders = implode( ',', array_fill( 0, count( $check ), '%d' ) );
		$file_sql     = "SELECT attachment_id, source, lifecycle FROM {$table} WHERE attachment_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $file_sql, $check ) );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( Files::SOURCE === (string) $row->source && 'purged' !== (string) $row->lifecycle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The shared managed-attachment error.
	 */
	private function managed_error(): WP_Error {
		return new WP_Error(
			'sufm_managed',
			__( '该文件由“用户文件空间”管理，需由用户提交删除申请并经管理员批准执行。', 'sakura-imgopt' ),
			array( 'status' => 409 )
		);
	}
}
