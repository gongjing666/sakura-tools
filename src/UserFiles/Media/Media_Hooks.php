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
use Nboke\UserFiles\Repositories\Notifications;
use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Services\Deletion;
use Nboke\UserFiles\Services\Deletion_Context;
use Nboke\UserFiles\Services\Deletion_Result;
use Nboke\UserFiles\Services\Media_Adoption;
use Nboke\UserFiles\Services\Quota;
use Nboke\UserFiles\Services\Sync_Service;
use Nboke\UserFiles\Services\Uploader;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\S3\Bucket_Confirmation;
use Sakura\ImageOptimizer\S3\Delete_Jobs;
use Sakura\ImageOptimizer\S3\Sync_Status;
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
	private const COLUMNS = array( 'sufm_source', 'sufm_owner', 'sufm_folder', 'sufm_size', 'sufm_cloud' );

	/**
	 * Moderation reasons offered by the takedown action.
	 *
	 * A closed list keeps the audit row comparable: the key is stored, the label
	 * is rendered, and free text only ever lives in the note. A method rather
	 * than a constant because the labels are translated.
	 *
	 * @return array<string, string>
	 */
	private static function takedown_reasons(): array {
		return array(
			'copyright' => __( '侵犯版权', 'sakura-imgopt' ),
			'illegal'   => __( '违法违规内容', 'sakura-imgopt' ),
			'abuse'     => __( '辱骂或骚扰', 'sakura-imgopt' ),
			'privacy'   => __( '泄露他人隐私', 'sakura-imgopt' ),
			'policy'    => __( '违反站点规则', 'sakura-imgopt' ),
			'other'     => __( '其他', 'sakura-imgopt' ),
		);
	}

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

	private ?Events $events;

	/**
	 * Notification outbox (moderation takedowns tell the owner).
	 *
	 * @var Notifications|null
	 */
	private ?Notifications $outbox;

	/**
	 * Cloud state per attachment for the current list page.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cloud_states = array();

	/**
	 * Whether the current media list page already resolved cloud states.
	 */
	private bool $cloud_primed = false;

	/**
	 * Bucket confirmation service, built on first use.
	 *
	 * @var Bucket_Confirmation|null
	 */
	private ?Bucket_Confirmation $confirmation = null;

	/**
	 * Attachment sync state, built on first use.
	 *
	 * @var Sync_Status|null
	 */
	private ?Sync_Status $sync_status = null;

	/**
	 * Remote delete queue, built on first use.
	 *
	 * @var Delete_Jobs|null
	 */
	private ?Delete_Jobs $delete_jobs = null;

	public function __construct( Files $files, Folders $folders, Requests $requests, ?Sync_Service $sync = null, ?Quota $quota = null, ?Events $events = null, ?Notifications $outbox = null ) {
		$this->files    = $files;
		$this->folders  = $folders;
		$this->requests = $requests;
		$this->sync     = $sync;
		$this->quota    = $quota;
		$this->events   = $events;
		$this->outbox   = $outbox;
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
			'sufm_cloud'  => __( '云端状态', 'sakura-imgopt' ),
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

		/* The cloud state is real for every attachment: a file can be in the
		 * bucket without ever having a ledger row. Resolve it before the
		 * managed gate so an administrator can judge a takedown either way. */
		if ( 'sufm_cloud' === $column ) {
			$this->render_cloud_column( $post_id );
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

		// No ledger row means nothing was ever charged: there is no settlement
		// to lose, so WordPress may delete it without another gate.
		$file = $this->files->get_by_attachment( $post_id );

		if ( ! $file || 'purged' === (string) $file->lifecycle ) {
			return $delete;
		}

		/* A plugin purge already claimed this row. Letting an administrator
		 * finish it through the official entry keeps WordPress's own delete
		 * semantics intact; answering "no permission" for a job that is already
		 * approved would be a lie. */
		if ( Files::LIFECYCLE_DELETING === (string) $file->lifecycle && current_user_can( Capabilities::DELETE_FILES ) && current_user_can( 'delete_post', $post_id ) ) {
			return $delete;
		}

		/* Admission is decided by the actor's capability, never by where the
		 * file came from. The administrator may remove any file; everyone below
		 * them has to file a delete request and wait for execution. */
		if ( self::may_delete_directly( $file, current_user_can( Capabilities::DELETE_FILES ), current_user_can( 'delete_post', $post_id ) ) ) {
			return $delete;
		}

		/* Core returns whatever this filter returns, and the media list only
		 * checks truthiness — handing back a WP_Error made a blocked delete
		 * report success. false is the one answer that stops the delete and
		 * still reads as a failure to the caller. */
		return false;
	}

	/**
	 * `deleted_post` observer: hand every removed attachment to the settlement.
	 *
	 * WordPress fires this for every deletion it performs — media library, REST,
	 * WP-CLI, another plugin, and the approved-request executor — so there is no
	 * in-process flag to keep in sync. `Deletion::settle()` decides the winner
	 * with a conditional update, which makes the observer and a caller that
	 * settles by itself idempotent together.
	 *
	 * @param int          $post_id Attachment ID.
	 * @param WP_Post|null $post    Deleted post object.
	 * @return void
	 */
	public function finalize_deleted_attachment( $post_id, $post = null ): void {
		$this->deletion()->on_attachment_deleted( $post_id, $post );
	}

	/**
	 * Whether this actor may delete a managed file without the request flow.
	 *
	 * One decision, one place: the media-library gate and the REST gate both
	 * call this, so a capability change cannot leak through the other path.
	 *
	 * @param object $file            Ledger row.
	 * @param bool   $has_delete_cap  Holds `sufm_admin_delete_files`.
	 * @param bool   $can_delete_post Holds `delete_post` for this attachment.
	 * @return bool
	 */
	public static function may_delete_directly( object $file, bool $has_delete_cap, bool $can_delete_post ): bool {
		return 'active' === (string) $file->lifecycle && $has_delete_cap && $can_delete_post;
	}

	/**
	 * Settlement service, built from what this class already holds.
	 *
	 * @return Deletion
	 */
	private function deletion(): Deletion {
		return new Deletion( $this->files, $this->quota, $this->events );
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

			$file = $this->files->get_by_attachment( $attachment_id );

			if ( $file && Files::LIFECYCLE_DELETING === (string) $file->lifecycle ) {
				return current_user_can( Capabilities::DELETE_FILES ) ? $result : $this->deleting_error();
			}

			/* Gate by the ledger, not by the row's source: an adoption from the
			 * media library is charged to its author exactly like a user-space
			 * upload, so both answer with the request-flow message instead of
			 * letting core return its generic "不能删除这篇文章" 500. */
			if ( $file && Files::LIFECYCLE_PURGED !== (string) $file->lifecycle && ! current_user_can( Capabilities::DELETE_FILES ) ) {
				return $this->managed_error();
			}

			return $result;
		}

		if ( 'POST' === $method && preg_match( '#^/wp/v2/(?:media|attachments)/batch$#', $route ) ) {
			$ids = $this->batch_attachment_ids( $request );
			if ( $ids && $this->batch_contains_managed( $ids ) && ! current_user_can( Capabilities::DELETE_FILES ) ) {
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

		if ( ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}

		if ( current_user_can( Capabilities::REQUEST_DELETE ) ) {
			$actions['sufm_request_delete'] = __( '提交删除申请（用户文件空间）', 'sakura-imgopt' );
		}

		/* Moderation is offered to whoever may delete anything; the per-row
		 * `delete_post` check still runs in the handler, so an author cannot
		 * take down somebody else's file through this action. */
		if ( current_user_can( Capabilities::DELETE_FILES ) ) {
			$actions['sufm_takedown'] = __( '违规下架（删除本地与云端）', 'sakura-imgopt' );
		}

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
		$action = (string) $action;

		if ( 'sufm_takedown' === $action ) {
			return $this->handle_takedown( $redirect_to, $post_ids );
		}

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
	 * Take selected files down from the site and the bucket, with an audit trail.
	 *
	 * The removal itself goes through the deletion kernel, so the local file,
	 * the attachment row, the remote-delete queue and the ledger settlement run
	 * in the same order as every other plugin-initiated delete. What this
	 * action adds is the reason — the context carries the moderation reason, so
	 * the audit row reads moderation + the chosen reason instead of an anonymous
	 * operator delete — and the owner-facing notification.
	 *
	 * @param string $redirect_to Redirect URL from core.
	 * @param array  $post_ids    Selected attachment IDs.
	 * @return string
	 */
	public function handle_takedown( $redirect_to, $post_ids ) {
		$fallback = $redirect_to ? (string) $redirect_to : admin_url( 'upload.php' );

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( Capabilities::DELETE_FILES ) ) {
			return add_query_arg( 'sufm_takedown_denied', 1, $fallback );
		}

		/* The list table submits with GET — core reads the bulk action from
		 * $_REQUEST — so the reason arrives in the query string, not in $_POST. */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core verifies bulk-media before this filter runs.
		$reason = isset( $_REQUEST['sufm_takedown_reason'] ) && is_scalar( $_REQUEST['sufm_takedown_reason'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['sufm_takedown_reason'] ) ) : '';

		/* A takedown without a recorded reason is exactly the anonymous delete
		 * this action exists to replace, so refuse instead of guessing one. */
		$reasons = self::takedown_reasons();

		if ( ! isset( $reasons[ $reason ] ) ) {
			return add_query_arg( 'sufm_takedown_reason_missing', 1, $fallback );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core verifies bulk-media before this filter runs.
		$note   = isset( $_REQUEST['sufm_takedown_note'] ) && is_scalar( $_REQUEST['sufm_takedown_note'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['sufm_takedown_note'] ) ) : '';
		$note   = mb_substr( trim( $note ), 0, 120 );
		$label  = $reasons[ $reason ];
		$detail = '' !== $note ? $label . '：' . $note : $label;
		$actor  = get_current_user_id();
		$ids    = $this->normalize_ids( (array) $post_ids );

		if ( ! $ids ) {
			return add_query_arg(
				array(
					'sufm_takedown_done'    => 0,
					'sufm_takedown_partial' => 0,
					'sufm_takedown_skipped' => 0,
				),
				$fallback
			);
		}

		$this->prime_summaries( $ids );

		$done    = 0;
		$partial = 0;
		$skipped = 0;
		$targets = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
				$skipped++;
				continue;
			}

			$summary = $this->summaries[ $id ] ?? null;

			/* An executor already owns this row; the kernel would answer
			 * in_progress, so do not hand it over just to be told that. */
			if ( is_array( $summary ) && Files::LIFECYCLE_DELETING === (string) ( $summary['lifecycle'] ?? '' ) ) {
				$skipped++;
				continue;
			}

			$targets[] = $id;
		}

		if ( $targets ) {
			$ctx = new Deletion_Context(
				Deletion_Context::MODE_TAKEDOWN,
				$actor,
				Deletion::REASON_MODERATION,
				false,
				true,
				Deletion::VIA_PLUGIN,
				false,
				$detail
			);

			$result = $this->deletion()->delete( $targets, $ctx );

			foreach ( $result->items() as $item ) {
				$status = (string) ( $item['status'] ?? '' );

				/* PARTIAL means the local file and the attachment row are gone but
				 * the bucket still holds the object: the kernel queued a retry, so
				 * the file is off the site already. Count it apart from DELETED so
				 * the result message can say the cloud copy is still pending. */
				if ( Deletion_Result::DELETED !== $status && Deletion_Result::PARTIAL !== $status ) {
					$skipped++;
					continue;
				}

				$id = (int) ( $item['attachment_id'] ?? 0 );

				if ( Deletion_Result::PARTIAL === $status ) {
					$partial++;
				} else {
					$done++;
				}

				$summary = $this->summaries[ $id ] ?? null;

				if ( is_array( $summary ) ) {
					$this->notify_takedown( $id, $summary, $detail );
				} else {
					$this->log_takedown( $id, $detail );
				}
			}
		}

		return add_query_arg(
			array(
				'sufm_takedown_done'    => $done,
				'sufm_takedown_partial' => $partial,
				'sufm_takedown_skipped' => $skipped,
			),
			$fallback
		);
	}

	/**
	 * Tell the owner once per file that it was taken down.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $summary       Ledger summary.
	 * @param string               $detail        Reason label and note.
	 * @return void
	 */
	private function notify_takedown( int $attachment_id, array $summary, string $detail ): void {
		if ( ! $this->outbox ) {
			return;
		}

		$owner_id = (int) ( $summary['owner_id'] ?? 0 );

		if ( $owner_id <= 0 ) {
			return;
		}

		$this->outbox->enqueue(
			$owner_id,
			0,
			'moderation',
			array(
				'count'  => 1,
				'reason' => $detail,
			),
			'sufm-moderation:' . get_current_blog_id() . ':' . $attachment_id
		);
	}

	/**
	 * Audit a takedown of a file that never had a ledger row.
	 *
	 * Without a ledger the settlement observer writes nothing, but the action
	 * still removed a public file and must leave the same kind of record.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $detail        Reason label and note.
	 * @return void
	 */
	private function log_takedown( int $attachment_id, string $detail ): void {
		if ( ! $this->events ) {
			return;
		}

		$this->events->log(
			array(
				'actor_id'    => get_current_user_id(),
				'owner_id'    => (int) get_post_field( 'post_author', $attachment_id ),
				'subject_id'  => $attachment_id,
				'action'      => 'delete',
				'result'      => 'purged',
				'safe_detail' => Deletion::VIA_PLUGIN . ':' . Deletion::REASON_MODERATION . '|' . mb_substr( $detail, 0, 80 ),
			)
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
				// Rendered here because the media list's own admin notice sits under the fixed folder cabinet.
				'notice'  => $this->takedown_result_notice(),
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
					'takedownTitle' => __( '违规下架', 'sakura-imgopt' ),
					'takedownHint' => __( '这会同时删除本地文件与云端对象，并释放该用户占用的容量。理由与备注会写入审计记录并通知文件所有者。', 'sakura-imgopt' ),
					'takedownReason' => __( '下架理由', 'sakura-imgopt' ),
					'takedownNote' => __( '备注（可选，最多 120 字）', 'sakura-imgopt' ),
					'takedownConfirm' => __( '确认下架', 'sakura-imgopt' ),
					'takedownCancel' => __( '取消', 'sakura-imgopt' ),
					'takedownNeedReason' => __( '请选择下架理由。', 'sakura-imgopt' ),
					'needSelection' => __( '请先选择要处理的文件。', 'sakura-imgopt' ),
					'takedownReasons' => self::takedown_reasons(),
					/* translators: %d: number of selected files */
					'takedownSummary' => __( '即将下架选中的 %d 个文件', 'sakura-imgopt' ),
					/* translators: 1: deleted count, 2: failed count */
					'deletePartial' => __( '已删除 %d 个文件，%s 个未能删除（无删除权限，用户上传的文件请提交申请或由管理员删除）。', 'sakura-imgopt' ), // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText -- JS replaces %s/%d literally; ordering them would break the UI.
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

		$has_delete_cap = current_user_can( Capabilities::DELETE_FILES );

		$blocked = 0;
		foreach ( $ids as $id ) {
			// Media-library imports may be deleted from the library itself; only
			// files that belong to the user file space need the request flow.
			if ( ! $this->is_managed_attachment( $id ) ) {
				continue;
			}

			/* The gate would let this one through — an administrator holding
			 * both capabilities deletes it like any other attachment — so
			 * counting it here would bounce the whole selection to a notice
			 * that tells the operator to do what they are already doing. */
			if ( $has_delete_cap && current_user_can( 'delete_post', $id ) ) {
				continue;
			}

			$blocked++;
		}

		if ( $blocked < 1 ) {
			return;
		}

		$back = remove_query_arg( array( 'action', 'media', '_wpnonce', '_wp_http_referer', 'deleted', 'sufm_delete_blocked' ) );
		wp_safe_redirect( add_query_arg( 'sufm_delete_blocked', $blocked, $back ) );
		exit;
	}

	/**
	 * Bulk-action result carried back by the media list's GET submit.
	 *
	 * The list table submits with GET and the result has to survive the 302, so
	 * the flags live in the query string. `assets/media-folders.js` reads them
	 * and shows a toast: an admin notice here would be painted underneath the
	 * fixed folder cabinet and never be seen (verified on production).
	 *
	 * @return array{message:string,error:bool}|null
	 */
	private function takedown_result_notice(): ?array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag.
		$done = isset( $_GET['sufm_takedown_done'] ) && is_scalar( $_GET['sufm_takedown_done'] )
			? absint( wp_unslash( (string) $_GET['sufm_takedown_done'] ) )
			: null;

		if ( null !== $done ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag.
			$skipped = isset( $_GET['sufm_takedown_skipped'] ) && is_scalar( $_GET['sufm_takedown_skipped'] )
				? absint( wp_unslash( (string) $_GET['sufm_takedown_skipped'] ) )
				: 0;

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag.
			$partial = isset( $_GET['sufm_takedown_partial'] ) && is_scalar( $_GET['sufm_takedown_partial'] )
				? absint( wp_unslash( (string) $_GET['sufm_takedown_partial'] ) )
				: 0;

			$bits = array();

			if ( $done > 0 ) {
				$bits[] = sprintf(
					/* translators: %d: files removed locally and remotely. */
					__( '已下架 %d 个文件（本地与云端）', 'sakura-imgopt' ),
					$done
				);
			}

			if ( $partial > 0 ) {
				$bits[] = sprintf(
					/* translators: %d: files whose cloud object is still pending. */
					__( '%d 个已删除本地副本，云端对象已排入删除队列自动补删', 'sakura-imgopt' ),
					$partial
				);
			}

			if ( $skipped > 0 ) {
				$bits[] = sprintf(
					/* translators: %d: files skipped. */
					__( '跳过 %d 个', 'sakura-imgopt' ),
					$skipped
				);
			}

			if ( array() === $bits ) {
				$bits[] = __( '本次没有文件被下架', 'sakura-imgopt' );
			}

			$message = implode( '，', $bits ) . '。';

			if ( 0 === $done + $partial && $skipped > 0 ) {
				$message .= __( '（多为无权限、已在删除流程中或不是附件）', 'sakura-imgopt' );
			}

			return array( 'message' => $message, 'error' => $done + $partial < 1 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag.
		if ( isset( $_GET['sufm_takedown_reason_missing'] ) ) {
			return array( 'message' => __( '请先选择下架理由，本次未删除任何文件。', 'sakura-imgopt' ), 'error' => true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag.
		if ( isset( $_GET['sufm_takedown_denied'] ) ) {
			return array( 'message' => __( '你没有删除文件的权限，本次未下架任何文件。', 'sakura-imgopt' ), 'error' => true );
		}

		return null;
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
							__( '有 %d 个文件属于“用户文件空间”，不能从媒体库直接删除。请到“用户中心 → 文件空间”提交删除申请，管理员批准后执行。', 'sakura-imgopt' ),
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
	 * Resolve the cloud state for every attachment on the current list page.
	 */
	private function prime_cloud_states(): void {
		if ( $this->cloud_primed ) {
			return;
		}

		$this->cloud_primed = true;
		$ids                = $this->list_attachment_ids();

		if ( $ids ) {
			$this->resolve_cloud_states( $ids );
		}
	}

	/**
	 * Classify the cloud state of a batch of attachments.
	 *
	 * One meta cache prime, one batched confirmation and one grouped queue
	 * query per page: the column must not turn a 100-row list into 300 queries.
	 * Everything this needs comes from the sync index the sync center already
	 * reads, so the two screens cannot disagree about "synced".
	 *
	 * @param array<int, int> $attachment_ids Attachment IDs.
	 */
	private function resolve_cloud_states( array $attachment_ids ): void {
		$ids = $this->normalize_ids( $attachment_ids );
		if ( ! $ids ) {
			return;
		}

		$pending = array();
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->cloud_states ) ) {
				$pending[] = $id;
			}
		}

		if ( ! $pending ) {
			return;
		}

		if ( ! class_exists( Bucket_Confirmation::class ) ) {
			foreach ( $pending as $id ) {
				$this->cloud_states[ $id ] = Cloud_State::classify( array(), array(), null, false );
			}

			return;
		}

		update_meta_cache( 'post', $pending );

		$objects = array();
		$all     = array();

		foreach ( $pending as $id ) {
			$keys           = array_map( 'strval', $this->sync_status()->objects( $id ) );
			$keys           = array_values(
				array_filter(
					$keys,
					static function ( string $key ): bool {
						return '' !== $key;
					}
				)
			);
			$objects[ $id ] = $keys;

			foreach ( $keys as $key ) {
				$all[ $key ] = $key;
			}
		}

		$confirmation = $this->confirmation();
		$can_confirm  = $confirmation->available();
		$confirmed    = array();

		if ( $can_confirm && $all ) {
			$confirmed = $confirmation->confirms_many( array_values( $all ) );
		}

		$deletes = $this->delete_jobs()->states_for( $pending );

		foreach ( $pending as $id ) {
			$delete                     = isset( $deletes[ $id ] ) && is_array( $deletes[ $id ] ) ? $deletes[ $id ] : null;
			$this->cloud_states[ $id ] = Cloud_State::classify( $objects[ $id ], $confirmed, $delete, $can_confirm );
		}
	}

	/**
	 * Print the cloud state cell.
	 *
	 * @param int $post_id Attachment ID.
	 */
	private function render_cloud_column( int $post_id ): void {
		$this->prime_cloud_states();

		if ( ! array_key_exists( $post_id, $this->cloud_states ) ) {
			$this->resolve_cloud_states( array( $post_id ) );
		}

		$state = $this->cloud_states[ $post_id ] ?? Cloud_State::classify( array(), array(), null, false );
		$key   = (string) ( $state['state'] ?? Cloud_State::UNKNOWN );
		$label = self::cloud_label( $key, (int) ( $state['confirmed'] ?? 0 ), (int) ( $state['total'] ?? 0 ), (int) ( $state['count'] ?? 0 ) );
		$title = self::cloud_title( $key, $state );

		printf(
			'<span class="sufm-cloud sufm-cloud--%s" title="%s">%s</span>',
			esc_attr( $key ),
			esc_attr( $title ),
			esc_html( $label )
		);
	}

	/**
	 * Human label of one cloud state.
	 *
	 * @param string $state     One of the Cloud_State constants.
	 * @param int    $confirmed Confirmed object count.
	 * @param int    $total     Recorded object count.
	 * @param int    $count     Queued delete task count.
	 * @return string
	 */
	public static function cloud_label( string $state, int $confirmed = 0, int $total = 0, int $count = 0 ): string {
		switch ( $state ) {
			case Cloud_State::SYNCED:
				return __( '已同步', 'sakura-imgopt' );
			case Cloud_State::PARTIAL:
				return sprintf(
					/* translators: 1: confirmed object count, 2: recorded object count */
					__( '部分同步 %1$d/%2$d', 'sakura-imgopt' ),
					$confirmed,
					$total
				);
			case Cloud_State::PENDING:
				return __( '待上传', 'sakura-imgopt' );
			case Cloud_State::DELETING:
				return $count > 1
					? sprintf(
						/* translators: %d: queued delete task count */
						__( '删除中（%d 项）', 'sakura-imgopt' ),
						$count
					)
					: __( '删除中', 'sakura-imgopt' );
			case Cloud_State::FAILED:
				return __( '删除失败', 'sakura-imgopt' );
			case Cloud_State::LOCAL:
				return __( '仅本地', 'sakura-imgopt' );
			default:
				return __( '未知', 'sakura-imgopt' );
		}
	}

	/**
	 * Tooltip for one cloud state: counts and the last queue error only.
	 *
	 * @param string               $state Classified state.
	 * @param array<string, mixed> $row   Classifier result.
	 * @return string
	 */
	private static function cloud_title( string $state, array $row ): string {
		$error = trim( (string) ( $row['error'] ?? '' ) );

		if ( Cloud_State::FAILED === $state && '' !== $error ) {
			return mb_substr( $error, 0, 120 );
		}

		if ( Cloud_State::SYNCED === $state ) {
			return __( '云端与本地一致', 'sakura-imgopt' );
		}

		if ( Cloud_State::LOCAL === $state ) {
			return __( '没有云端对象记录，只存在于本站', 'sakura-imgopt' );
		}

		if ( Cloud_State::UNKNOWN === $state ) {
			return __( '同步索引未启用，无法确认云端状态', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: 1: confirmed object count, 2: recorded object count */
			__( '已确认 %1$d / %2$d 个云端对象', 'sakura-imgopt' ),
			(int) ( $row['confirmed'] ?? 0 ),
			(int) ( $row['total'] ?? 0 )
		);
	}

	/**
	 * Bucket confirmation service (first use builds it).
	 *
	 * @return Bucket_Confirmation
	 */
	private function confirmation(): Bucket_Confirmation {
		if ( null === $this->confirmation ) {
			$this->confirmation = new Bucket_Confirmation();
		}

		return $this->confirmation;
	}

	/**
	 * Attachment sync state (first use builds it).
	 *
	 * @return Sync_Status
	 */
	private function sync_status(): Sync_Status {
		if ( null === $this->sync_status ) {
			$this->sync_status = new Sync_Status();
		}

		return $this->sync_status;
	}

	/**
	 * Remote delete queue (first use builds it).
	 *
	 * @return Delete_Jobs
	 */
	private function delete_jobs(): Delete_Jobs {
		if ( null === $this->delete_jobs ) {
			$this->delete_jobs = new Delete_Jobs();
		}

		return $this->delete_jobs;
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
			// Same rule as the single-item gate: any charged ledger row counts,
			// whether it came from the user space or was adopted from the media
			// library, because both are settled by the plugin.
			if ( 'purged' !== (string) $row->lifecycle ) {
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
			__( '你没有直接删除该文件的权限。请提交删除申请，等待管理员批准执行；管理员可直接在媒体库删除。', 'sakura-imgopt' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Answer for a file whose deletion is already in flight.
	 *
	 * Distinct from {@see self::managed_error()}: the actor may well have the
	 * right to delete this file, it is simply being removed right now by an
	 * approved request. "No permission" here would send the operator to file a
	 * second request for a file that is already on its way out.
	 *
	 * @return WP_Error
	 */
	private function deleting_error(): WP_Error {
		return new WP_Error(
			'sufm_deleting',
			__( '该文件正在删除中，请稍后刷新查看结果。', 'sakura-imgopt' ),
			array( 'status' => 409 )
		);
	}
}
