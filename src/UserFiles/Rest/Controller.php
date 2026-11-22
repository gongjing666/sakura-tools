<?php
/**
 * REST surface for the user file space.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Rest;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Nboke\UserFiles\Repositories\Files;
use Nboke\UserFiles\Repositories\Folders;
use Nboke\UserFiles\Repositories\Requests;
use Nboke\UserFiles\Repositories\Sanctions;
use Nboke\UserFiles\Schema;
use Nboke\UserFiles\Services\Executor;
use Nboke\UserFiles\Services\Notifier;
use Nboke\UserFiles\Services\Sync_Service;
use Nboke\UserFiles\Services\Quota;
use Nboke\UserFiles\Services\Sanction_Service;
use Nboke\UserFiles\Services\Uploader;
use Nboke\UserFiles\Settings;
use Nboke\UserFiles\Support\Time;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Every owner id comes from the session; a request body can never name one.
 */
final class Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sufm/v1';

	/**
	 * Registers the space expansion routes (P1).
	 *
	 * Called from rest_api_init by Nboke\UserFiles\Space_Panel::register().
	 *
	 * @return void
	 */
	public static function register_space_routes(): void {
		register_rest_route(
			self::NS,
			'/space/plans',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'space_plans' ),
				'permission_callback' => array( __CLASS__, 'space_can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/space/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'space_order' ),
				'permission_callback' => array( __CLASS__, 'space_can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/space/orders/(?P<num>[A-Za-z0-9]+)/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'space_order_status' ),
				'permission_callback' => array( __CLASS__, 'space_can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/space/grants',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'space_grants' ),
				'permission_callback' => array( __CLASS__, 'space_can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/space/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'space_redeem' ),
				'permission_callback' => array( __CLASS__, 'space_can_use' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/space/grants',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_space_grants' ),
				'permission_callback' => array( __CLASS__, 'space_can_review' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/space/reconcile',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_space_reconcile' ),
				'permission_callback' => array( __CLASS__, 'space_can_review' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/space/grants',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_space_grant' ),
				'permission_callback' => array( __CLASS__, 'space_can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/space/grants/(?P<id>\d+)/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_space_revoke' ),
				'permission_callback' => array( __CLASS__, 'space_can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/admin/space/grants/(?P<id>\d+)/extend',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_space_extend' ),
				'permission_callback' => array( __CLASS__, 'space_can_manage' ),
			)
		);
	}

	/**
	 * Permission callback for the user facing space routes.
	 *
	 * @return bool
	 */
	public static function space_can_use(): bool {
		// The expansion panel lives inside the front-end entry, so it follows the
		// same switch; the ledger routes for admins do not.
		return is_user_logged_in() && Settings::entry_enabled();
	}

	/**
	 * Permission callback for the space ledger.
	 *
	 * @return bool
	 */
	public static function space_can_review(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( '\\Nboke\\UserFiles\\Capabilities' ) && is_callable( array( '\\Nboke\\UserFiles\\Capabilities', 'can_review' ) ) ) {
			return (bool) \Nboke\UserFiles\Capabilities::can_review();
		}

		return false;
	}

	/**
	 * Permission callback for money moving actions (grant / revoke / extend).
	 *
	 * Deliberately stricter than space_can_review(): reviewers may read the
	 * ledger, only administrators may change it.
	 *
	 * @return bool
	 */
	public static function space_can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /space/plans
	 *
	 * @return \WP_REST_Response
	 */
	public static function space_plans() {
		$store = \Nboke\UserFiles\Services\Space_Store::instance();
		$uid   = (int) get_current_user_id();

		$breakdown = \Nboke\UserFiles\Services\Space_Rules::breakdown( $uid );

		$breakdown['final_human'] = \Nboke\UserFiles\Services\Space_Store::bytes_human( (int) ( $breakdown['final'] ?? 0 ) );

		return new \WP_REST_Response(
			array(
				'available' => \Nboke\UserFiles\Services\Zibll_Bridge::instance()->available(),
				'enabled'   => \Nboke\UserFiles\Space_Settings::enabled(),
				'plans'     => $store->plans(),
				'methods'   => $store->payment_methods(),
				'breakdown' => $breakdown,
			),
			200
		);
	}

	/**
	 * POST /space/orders
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function space_order( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = (array) $request->get_params();
		}

		$plan_id = isset( $params['plan_id'] ) ? sanitize_key( (string) $params['plan_id'] ) : '';
		$method  = isset( $params['method'] ) ? sanitize_key( (string) $params['method'] ) : '';

		$result = \Nboke\UserFiles\Services\Space_Store::instance()->purchase(
			(int) get_current_user_id(),
			$plan_id,
			$method
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /space/grants
	 *
	 * @return \WP_REST_Response
	 */
	public static function space_grants(): \WP_REST_Response {
		$uid = (int) get_current_user_id();

		return new \WP_REST_Response(
			array(
				'items' => \Nboke\UserFiles\Services\Space_Store::instance()->my_grants( $uid, 20 ),
			),
			200
		);
	}

	/**
	 * POST /space/redeem
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function space_redeem( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = (array) $request->get_params();
		}

		$gb         = isset( $params['gb'] ) ? (float) $params['gb'] : 0.0;
		$request_id = isset( $params['request_id'] ) ? (string) $params['request_id'] : '';
		$days       = isset( $params['days'] ) ? (int) $params['days'] : 365;
		$package_id = isset( $params['package_id'] ) ? (string) $params['package_id'] : '';

		if ( '' !== $package_id ) {
			$packed = \Nboke\UserFiles\Services\Space_Store::instance()->redeem_package(
				(int) get_current_user_id(),
				$package_id,
				$request_id
			);

			if ( is_wp_error( $packed ) ) {
				return $packed;
			}

			return new \WP_REST_Response( $packed, 200 );
		}

		if ( 0 > $days || 3650 < $days ) {
			return new \WP_Error( 'sufm_points_days', __( '有效期参数不合法。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$result = \Nboke\UserFiles\Services\Space_Store::instance()->redeem_points(
			(int) get_current_user_id(),
			$gb,
			$request_id,
			$days
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /space/orders/{num}/status
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function space_order_status( $request ) {
		$num = isset( $request['num'] ) ? (string) $request['num'] : '';

		$result = \Nboke\UserFiles\Services\Space_Store::instance()->order_status(
			(int) get_current_user_id(),
			$num
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/space/grants
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_space_grants( $request ): \WP_REST_Response {
		$args = array(
			'page'    => (int) $request->get_param( 'page' ),
			'per'     => (int) $request->get_param( 'per' ),
			'state'   => (string) $request->get_param( 'state' ),
			'user_id' => (int) $request->get_param( 'user_id' ),
		);

		return new \WP_REST_Response( \Nboke\UserFiles\Services\Space_Store::instance()->admin_grants( $args ), 200 );
	}

	/**
	 * POST /admin/space/grants
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function admin_space_grant( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = (array) $request->get_params();
		}

		$user_id = isset( $params['user_id'] ) ? (int) $params['user_id'] : 0;
		$gb      = isset( $params['gb'] ) ? (float) $params['gb'] : 0.0;
		$days    = isset( $params['days'] ) ? (int) $params['days'] : 0;
		$note    = isset( $params['note'] ) ? (string) $params['note'] : '';

		if ( 0 >= $user_id ) {
			return new \WP_Error( 'sufm_grant_user', __( '请指定有效的用户。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		if ( 0 >= $gb || 1024 < $gb ) {
			return new \WP_Error( 'sufm_grant_size', __( '容量必须在 0 到 1024 GB 之间。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$bytes = (int) round( $gb * 1024 * 1024 * 1024 );

		$result = \Nboke\UserFiles\Services\Space_Store::instance()->admin_grant( $user_id, $bytes, $days, $note );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /admin/space/grants/{id}/revoke
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function admin_space_revoke( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = (array) $request->get_params();
		}

		$grant_id = isset( $request['id'] ) ? (int) $request['id'] : 0;
		$note     = isset( $params['note'] ) ? (string) $params['note'] : '';

		if ( 0 >= $grant_id ) {
			return new \WP_Error( 'sufm_grant_missing', __( '授权记录不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$done = \Nboke\UserFiles\Services\Space_Store::instance()->revoke( $grant_id, $note );

		if ( ! $done ) {
			return new \WP_Error( 'sufm_grant_state', __( '撤销失败：该授权当前不可撤销。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response( array( 'revoked' => true, 'grant_id' => $grant_id ), 200 );
	}

	/**
	 * POST /admin/space/grants/{id}/extend
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function admin_space_extend( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = (array) $request->get_params();
		}

		$grant_id = isset( $request['id'] ) ? (int) $request['id'] : 0;
		$days     = isset( $params['days'] ) ? (int) $params['days'] : 0;

		if ( 1 > $days || 3650 < $days ) {
			return new \WP_Error( 'sufm_grant_days', __( '延长天数必须在 1 到 3650 之间。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$result = \Nboke\UserFiles\Services\Space_Store::instance()->admin_extend( $grant_id, $days );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /admin/space/reconcile
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_space_reconcile( $request ): \WP_REST_Response {
		// Dry run by default: only an explicit falsy dry_run writes grants.
		$apply = in_array( $request->get_param( 'dry_run' ), array( '0', 0, false, 'false' ), true );

		return new \WP_REST_Response(
			\Nboke\UserFiles\Services\Space_Store::instance()->reconcile( $apply ),
			200
		);
	}

	/**
	 * Files.
	 *
	 * @var Files
	 */
	private Files $files;

	/**
	 * Folders.
	 *
	 * @var Folders
	 */
	private Folders $folders;

	/**
	 * Requests.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Quota.
	 *
	 * @var Quota
	 */
	private Quota $quota;

	/**
	 * Uploads.
	 *
	 * @var Uploader
	 */
	private Uploader $uploader;

	/**
	 * Executor.
	 *
	 * @var Executor
	 */
	private Executor $executor;

	/**
	 * Events.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Owner notifications.
	 *
	 * @var Notifier|null
	 */
	private ?Notifier $notifier;

	/**
	 * Media-library scan.
	 *
	 * @var Sync_Service|null
	 */
	private ?Sync_Service $sync;

	/**
	 * Sanction decisions.
	 *
	 * @var Sanction_Service|null
	 */
	private ?Sanction_Service $sanctions;

	/**
	 * Wire dependencies.
	 *
	 * @param Files                 $files     Files.
	 * @param Folders               $folders   Folders.
	 * @param Requests              $requests  Requests.
	 * @param Quota                 $quota     Quota.
	 * @param Uploader              $uploader  Uploader.
	 * @param Executor              $executor  Executor.
	 * @param Events                $events    Events.
	 * @param Notifier|null         $notifier  Notifier.
	 * @param Sync_Service|null     $sync      Scan.
	 * @param Sanction_Service|null $sanctions Sanction decisions.
	 */
	public function __construct( Files $files, Folders $folders, Requests $requests, Quota $quota, Uploader $uploader, Executor $executor, Events $events, ?Notifier $notifier = null, ?Sync_Service $sync = null, ?Sanction_Service $sanctions = null ) {
		$this->files     = $files;
		$this->folders   = $folders;
		$this->requests  = $requests;
		$this->quota     = $quota;
		$this->uploader  = $uploader;
		$this->executor  = $executor;
		$this->events    = $events;
		$this->notifier  = $notifier;
		$this->sync      = $sync;
		$this->sanctions = $sanctions;
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register(): void {
		$user  = array( $this, 'can_use_files' );
		$review = array( $this, 'can_review' );
		$execute = array( $this, 'can_execute' );

		$routes = array(
			array( '/summary', 'GET', 'summary', $user ),
			array( '/capacity', 'GET', 'capacity', $user ),
			array( '/items', 'GET', 'items', $user ),
			array( '/folders', 'GET', 'own_folders', $user ),
			array( '/upload-policy', 'GET', 'upload_policy', $user ),
			array( '/uploads', 'POST', 'uploads', $user ),
			array( '/folders', 'POST', 'create_folder', $user ),
			array( '/files/(?P<id>\d+)', 'PATCH', 'patch_file', $user ),
			array( '/folders/(?P<id>\d+)', 'PATCH', 'patch_folder', $user ),
			array( '/delete-requests', 'GET', 'list_requests', $user ),
			array( '/delete-requests', 'POST', 'create_request', $user ),
			array( '/delete-requests/(?P<id>\d+)', 'GET', 'get_request', $user ),
			array( '/delete-requests/(?P<id>\d+)/withdraw', 'POST', 'withdraw_request', $user ),
			array( '/batch', 'POST', 'batch', $user ),
			array( '/operations/(?P<id>\d+)', 'GET', 'operation', $user ),
			array( '/admin/delete-requests', 'GET', 'admin_queue', $review ),
			array( '/admin/delete-requests/(?P<id>\d+)/items', 'GET', 'admin_request_items', $review ),
			array( '/admin/delete-requests/(?P<id>\d+)/review', 'POST', 'admin_review', $review ),
			array( '/admin/delete-requests/(?P<id>\d+)/revoke', 'POST', 'admin_revoke', $review ),
			array( '/admin/delete-batches/preview', 'POST', 'admin_batch_preview', $execute ),
			array( '/admin/delete-batches', 'POST', 'admin_batch', $execute ),
			array( '/admin/delete-batches/(?P<id>\d+)', 'GET', 'admin_batch_get', $execute ),
			array( '/admin/delete-batches/(?P<id>\d+)/stop', 'POST', 'admin_batch_stop', $execute ),
			array( '/admin/users', 'GET', 'admin_users', $execute ),
			array( '/admin/events', 'GET', 'admin_events', $execute ),
			array( '/admin/users/(?P<id>\d+)/quota', 'PATCH', 'admin_quota', $execute ),
			array( '/admin/users/(?P<id>\d+)/sanction', 'GET', 'admin_sanction_get', array( $this, 'can_sanction_users' ) ),
			array( '/admin/users/(?P<id>\d+)/sanction', 'POST', 'admin_sanction_create', array( $this, 'can_sanction_users' ) ),
			array( '/admin/sanctions/(?P<id>\d+)/revoke', 'POST', 'admin_sanction_revoke', array( $this, 'can_sanction_users' ) ),
			array( '/admin/reconcile', 'POST', 'admin_reconcile', $execute ),
			array( '/admin/reset-quotas', 'POST', 'admin_reset_quotas', $execute ),
			array( '/admin/sync', 'POST', 'admin_sync_start', $execute ),
			array( '/admin/sync', 'GET', 'admin_sync_status', $execute ),
			array( '/admin/folders', 'GET', 'admin_folders', array( $this, 'can_manage_media' ) ),
			array( '/admin/folders', 'POST', 'admin_folder_create', array( $this, 'can_manage_media' ) ),
			array( '/admin/folders/(?P<id>\d+)', 'PATCH', 'admin_folder_update', array( $this, 'can_manage_media' ) ),
			array( '/admin/transfers/preview', 'POST', 'admin_transfer_preview', array( $this, 'can_manage_media' ) ),
			array( '/admin/transfers/(?P<id>\\d+)', 'GET', 'admin_transfer_status', array( $this, 'can_manage_media' ) ),
			array( '/admin/transfers', 'POST', 'admin_transfer', array( $this, 'can_manage_media' ) ),
			array( '/admin/folders/move', 'POST', 'admin_folder_move', array( $this, 'can_manage_media' ) ),
			array( '/admin/folders/reorder', 'POST', 'admin_folder_reorder', array( $this, 'can_manage_media' ) ),
			array( '/admin/folders/(?P<id>\d+)', 'DELETE', 'admin_folder_delete', array( $this, 'can_manage_media' ) ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $route[3],
				)
			);
		}
	}

	/**
	 * Capability: use one's own space.
	 *
	 * @return bool
	 */
	public function can_use_files(): bool {
		// Self-service uploads only exist while a front-end entry is offered.
		if ( ! Settings::entry_enabled() ) {
			return false;
		}

		return Capabilities::can_manage_own( get_current_user_id() );
	}

	/**
	 * Capability: review deletion requests.
	 *
	 * @return bool
	 */
	public function can_review(): bool {
		return Capabilities::can_review();
	}

	/**
	 * Capability: execute deletions.
	 *
	 * @return bool
	 */
	public function can_execute(): bool {
		return Capabilities::can_execute();
	}

	/**
	 * Capability: decide a sanction against an uploader.
	 *
	 * Deliberately separate from `can_execute`: deleting a file and banning a
	 * person carry different risks, so an operator can hold one without the
	 * other. The per-target rules (self, administrators) live in
	 * `Sanction_Service::can_sanction()`, which this cannot answer on its own.
	 *
	 * @return bool
	 */
	public function can_sanction_users(): bool {
		return current_user_can( Capabilities::SANCTION_USERS );
	}

	/**
	 * Every counter the overview needs, in one round trip.
	 *
	 * The four values come from three different tables but none of them depends
	 * on another, so they are four scalar subqueries instead of four queries.
	 *
	 * @param int $owner Owner.
	 * @return array{files:int,folders:int,pending:int,approved:int}
	 */
	private function overview_counts( int $owner ): array {
		global $wpdb;

		$files   = Schema::table( 'files' );
		$folders = Schema::table( 'folders' );
		$request = Schema::table( 'delete_requests' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					(SELECT COUNT(*) FROM {$files} WHERE owner_id=%d AND lifecycle='active') AS files_total,
					(SELECT COUNT(*) FROM {$folders} WHERE owner_id=%d AND lifecycle='active') AS folders_total,
					(SELECT COUNT(*) FROM {$request} WHERE owner_id=%d AND status=%s) AS pending_total,
					(SELECT COUNT(*) FROM {$request} WHERE owner_id=%d AND status=%s) AS approved_total", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner,
				$owner,
				$owner,
				Requests::PENDING,
				$owner,
				Requests::APPROVED
			)
		);

		return array(
			'files'    => $row ? (int) $row->files_total : 0,
			'folders'  => $row ? (int) $row->folders_total : 0,
			'pending'  => $row ? (int) $row->pending_total : 0,
			'approved' => $row ? (int) $row->approved_total : 0,
		);
	}

	/**
	 * Space overview.
	 *
	 * @return WP_REST_Response
	 */
	public function summary(): WP_REST_Response {
		$owner    = get_current_user_id();
		$snapshot = $this->quota->snapshot( $owner );
		$settings = Settings::get();
		$counts   = $this->overview_counts( $owner );

		return rest_ensure_response(
			array(
				'quota'  => array(
					'total'     => (int) $snapshot['quota'],
					'used'      => (int) $snapshot['used'],
					'reserved'  => (int) $snapshot['reserved'],
					'available' => (int) $snapshot['available'],
					'over'      => (int) $snapshot['available'] <= 0,
				),
				'counts' => $counts,
				'policy' => array(
					'max_file_bytes'    => (int) $settings['max_file_bytes'],
					'max_user_files'    => (int) $settings['max_user_files'],
					'max_folder_depth'  => (int) $settings['max_folder_depth'],
					'allowed_types'     => $settings['allowed_types'],
					'extensions'        => array_map(
						static function ( $mime ) {
							$map = array(
								'image/jpeg'      => 'jpg',
								'image/png'       => 'png',
								'image/webp'      => 'webp',
								'image/gif'       => 'gif',
								'application/pdf' => 'pdf',
								'text/plain'      => 'txt',
								'application/zip' => 'zip',
								'video/mp4'       => 'mp4',
								'audio/mpeg'      => 'mp3',
							);

							return $map[ $mime ] ?? '';
						},
						$settings['allowed_types']
					),
					'reference_notice'  => $this->notice(),
					'notice_version'    => (string) $settings['reference_notice_version'],
				),
			)
		);
	}

	/**
	 * Capacity detail dialog.
	 *
	 * One payload for the 「容量详情」 modal: the total, the usage, and every
	 * source that builds the quota. Time limited grants also carry their expiry
	 * date and remaining days, so the dialog never has to guess.
	 *
	 * @return WP_REST_Response
	 */
	public function capacity(): WP_REST_Response {
		$owner     = (int) get_current_user_id();
		$snapshot  = $this->quota->snapshot( $owner );
		$rules     = \Nboke\UserFiles\Services\Space_Rules::breakdown( $owner );
		$store     = \Nboke\UserFiles\Services\Space_Store::instance();
		$vip_names = \Nboke\UserFiles\Services\Space_Rules::vip_labels();

		$total  = max( 0, (int) $snapshot['quota'] );
		$used   = max( 0, (int) $snapshot['used'] );
		$free   = max( 0, $total - $used );
		$counts = $this->overview_counts( $owner );

		$percent       = $total > 0 ? min( 100, ( $used / $total ) * 100 ) : ( 0 < $used ? 100.0 : 0.0 );
		$percent_label = $used > 0 && $percent < 0.1
			? '<0.1%'
			: number_format_i18n( $percent, 1 ) . '%';

		// The dialog is a detail view: two decimals on GB keep 总容量 and 可用 apart.
		// size_format() rounds to whole units and would print 38 GB twice.
		$human = static function ( int $bytes ): string {
			$bytes     = abs( $bytes );
			$gigabytes = $bytes / 1073741824;
			$megabytes = $bytes / 1048576;

			if ( 1 <= $gigabytes ) {
				$value = round( $gigabytes, 2 );
				$unit  = 'GB';
			} elseif ( 1 <= $megabytes ) {
				$value = round( $megabytes, 1 );
				$unit  = 'MB';
			} else {
				return number_format_i18n( round( $bytes / 1024, 0 ) ) . ' KB';
			}

			$decimals = ( (float) $value === floor( (float) $value ) ) ? 0 : ( 'GB' === $unit ? 2 : 1 );

			return number_format_i18n( $value, $decimals ) . ' ' . $unit;
		};

		// One row shape for every source: the dialog only formats, never derives.
		$row = static function ( string $key, string $label, string $tag, string $note, int $bytes, bool $permanent = true, string $date = '', ?int $days = null ): array {
			return array(
				'key'        => $key,
				'label'      => $label,
				'tag'        => $tag,
				'note'       => $note,
				'bytes'      => $bytes,
				'permanent'  => $permanent,
				'date'       => $date,
				'days'       => $days,
				'expiry'     => $permanent
					? __( '永久有效', 'sakura-imgopt' )
					/* translators: %s: expiry date, e.g. 2027-11-04. */
					: sprintf( __( '%s 到期', 'sakura-imgopt' ), $date ),
				'days_label' => null === $days
					? ''
					/* translators: %d: remaining days. */
					: ( $days > 0 ? sprintf( __( '剩 %d 天', 'sakura-imgopt' ), $days ) : __( '今天到期', 'sakura-imgopt' ) ),
				'soon'       => null !== $days && $days <= 30,
			);
		};

		$sources   = array();
		$sources[] = $row( 'base', __( '基础容量', 'sakura-imgopt' ), '', __( '账号默认配额', 'sakura-imgopt' ), (int) ( $rules['base'] ?? 0 ) );

		$level_bytes = (int) ( $rules['level_bytes'] ?? 0 );

		if ( 0 < $level_bytes ) {
			$sources[] = $row(
				'level',
				/* translators: %s: theme level number. */
				sprintf( __( '等级 LV%s 加成', 'sakura-imgopt' ), (string) ( $rules['level'] ?? 0 ) ),
				'',
				__( '按等级自动发放', 'sakura-imgopt' ),
				$level_bytes
			);
		}

		$vip_bytes = (int) ( $rules['vip_bytes'] ?? 0 );

		if ( 0 < $vip_bytes ) {
			$vip_level = (int) ( $rules['vip'] ?? 0 );

			$sources[] = $row(
				'vip',
				/* translators: %s: vip level name, e.g. 黄金会员. */
				sprintf( __( '%s加成', 'sakura-imgopt' ), $vip_names[ $vip_level ] ?? __( '会员', 'sakura-imgopt' ) ),
				'',
				__( '会员权益', 'sakura-imgopt' ),
				$vip_bytes
			);
		}

		$expiring = array(
			'count' => 0,
			'bytes' => 0,
			'days'  => null,
			'date'  => '',
		);

		// Expired and pending rows never count towards granted_bytes, so listing
		// them here would break the sum the user just read on the quota card.
		if ( ! empty( $rules['apply_bonuses'] ) ) {
			$grants = array();

			foreach ( $store->my_grants( $owner, 100 ) as $grant ) {
				$bytes = (int) ( $grant['bytes'] ?? 0 );

				if ( 'active' !== (string) ( $grant['state'] ?? '' ) || ! empty( $grant['expired'] ) || 0 >= $bytes ) {
					continue;
				}

				$days    = isset( $grant['days_left'] ) ? (int) $grant['days_left'] : null;
				$date    = '' === (string) ( $grant['expires_at'] ?? '' ) ? '' : substr( (string) $grant['expires_at'], 0, 10 );
				$source  = (string) ( $grant['source'] ?? '' );

				if ( 'points' === $source ) {
					$key = 'points';
					$tag = __( '积分', 'sakura-imgopt' );
				} elseif ( 'purchase' === $source ) {
					$key = 'purchase';
					$tag = __( '已购', 'sakura-imgopt' );
				} else {
					$key = 'gift';
					$tag = __( '赠送', 'sakura-imgopt' );
				}

				$label = trim( (string) ( $grant['label'] ?? '' ) );

				if ( '' === $label ) {
					$label = __( '扩容包', 'sakura-imgopt' );
				}

				// "积分兑换 1.00 GB" + a 积分 badge is noise: drop the duplicate.
				if ( '' !== $tag && false !== strpos( $label, $tag ) ) {
					$tag = '';
				}

				$grants[] = $row( $key, $label, $tag, '', $bytes, null === $days, $date, $days );

				if ( null !== $days && 30 >= $days ) {
					++$expiring['count'];
					$expiring['bytes'] += $bytes;

					if ( null === $expiring['days'] || $days < $expiring['days'] ) {
						$expiring['days'] = $days;
						$expiring['date'] = $date;
					}
				}
			}

			// Soonest expiry first, permanent grants last.
			usort(
				$grants,
				static function ( array $a, array $b ): int {
					return ( $a['days'] ?? PHP_INT_MAX ) <=> ( $b['days'] ?? PHP_INT_MAX );
				}
			);

			$sources = array_merge( $sources, $grants );
		}

		$raw   = (int) ( $rules['raw'] ?? 0 );
		$final = (int) ( $rules['final'] ?? 0 );

		// A per user override (or a site filter) changes the total without adding
		// a grant; surface the difference instead of letting the rows not add up.
		if ( $final !== $raw ) {
			$sources[] = $row( 'adjust', __( '配额调整', 'sakura-imgopt' ), '', __( '按站点设置覆盖', 'sakura-imgopt' ), $final - $raw );
		}

		foreach ( $sources as $index => $source ) {
			$bytes = (int) $source['bytes'];
			$size  = $human( $bytes );

			if ( 'adjust' === $source['key'] ) {
				$size = ( 0 > $bytes ? '-' : '+' ) . $size;
			}

			$sources[ $index ]['human'] = $size;
			$sources[ $index ]['share'] = ( $total > 0 && 0 < $bytes ) ? round( $bytes / $total * 100, 1 ) : 0.0;
		}

		return rest_ensure_response(
			array(
				'total'       => array(
					'bytes' => $total,
					'human' => $human( $total ),
				),
				'used'        => array(
					'bytes'         => $used,
					'human'         => $human( $used ),
					'percent'       => round( $percent, 2 ),
					'percent_label' => $percent_label,
				),
				'free'        => array(
					'bytes' => $free,
					'human' => $human( $free ),
				),
				/* translators: %s: number of files. */
				'files_label' => sprintf( __( '共 %s 个文件', 'sakura-imgopt' ), number_format_i18n( (int) $counts['files'] ) ),
				'sources'     => array_values( $sources ),
				'expiring'    => array(
					'count' => (int) $expiring['count'],
					'bytes' => (int) $expiring['bytes'],
					'human' => $human( (int) $expiring['bytes'] ),
					'date'  => (string) $expiring['date'],
					'days'  => $expiring['days'],
					'text'  => 0 < $expiring['count']
						? sprintf(
							/* translators: 1: number of grants, 2: total size, 3: first expiry date. */
							__( '%1$d 项容量共 %2$s 将于 %3$s 起到期，届时总容量将减少。', 'sakura-imgopt' ),
							(int) $expiring['count'],
							$human( (int) $expiring['bytes'] ),
							(string) $expiring['date']
						)
						: '',
				),
				'expand'      => \Nboke\UserFiles\Space_Panel::has_panel(),
			)
		);
	}

	/**
	 * Folder + file listing for one virtual directory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner    = get_current_user_id();
		$folder   = (int) $request->get_param( 'folder_id' );
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$cursor   = absint( $request->get_param( 'cursor' ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ?: 40 ) ) );

		// The breadcrumb walk already loads the folder itself, so validating the
		// requested id through it costs nothing extra: an empty chain means the
		// folder is missing (the root is always valid and never walks).
		$crumbs = $folder > 0 ? $this->folders->breadcrumb( $owner, $folder ) : array();

		if ( $folder > 0 && ! $crumbs ) {
			return rest_ensure_response( new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		// The root view is the root directory, not the whole cabinet: a file filed
		// into a folder disappears from "all files" and only comes back when that
		// folder is opened, searched for, or filtered by a category tab. Listing
		// filed files at the root made filing look like it did nothing.
		$scope    = ( '' !== $search || '' !== $type ) ? 'all' : 'folder';
		$children = ( '' === $search && '' === $type ) ? $this->folders->children_with_counts( $owner, $folder ) : array();

		$rows = $this->files->query(
			$owner,
			array(
				'folder_id'   => $folder,
				'all_folders' => 'all' === $scope,
				'type'        => $type,
				'search'      => $search,
				'cursor'      => $cursor,
				'per_page'    => $per_page,
			)
		);

		$files = array();

		foreach ( $rows as $row ) {
			$files[] = array(
				'id'         => (int) $row->id,
				'name'       => (string) $row->display_name,
				'bytes'      => (int) $row->charged_bytes,
				'mime'       => (string) ( $row->post_mime_type ?? '' ),
				'url'        => (string) ( $row->guid ?? '' ),
				'folder_id'  => (int) $row->folder_id,
				'folder'     => (string) ( $row->folder_name ?? '' ),
				'created_at' => (string) $row->created_at,
				'locked'     => (int) $row->pending_request_id > 0,
			);
		}

		return rest_ensure_response(
			array(
				'folder'     => $folder,
				'scope'      => $scope,
				'breadcrumb' => 'all' === $scope ? array() : array_map(
					static function ( $row ) {
						return array(
							'id'   => (int) $row->id,
							'name' => (string) $row->name,
						);
					},
					$crumbs
				),
				'folders'    => array_map(
					static function ( $row ) {
						return array(
							'id'         => (int) $row->id,
							'name'       => (string) $row->name,
							'locked'     => (int) $row->pending_request_id > 0,
							'count'      => (int) ( $row->file_count ?? 0 ),
							'updated_at' => (string) $row->updated_at,
						);
					},
					$children
				),
				'files'      => $files,
				// Only the first page of the root view needs the size of the set it
				// is paging through; later pages and scoped/filtered listings would
				// pay for a COUNT nobody reads.
				'total'      => ( 0 === $cursor && 0 === $folder && '' === $search && '' === $type )
					? $this->files->count( $owner, 0 )
					: 0,
				'next'       => count( $files ) >= $per_page ? (int) end( $rows )->id : 0,
				'quota'      => $this->quota->snapshot( $owner ),
			)
		);
	}

	/**
	 * Folder tree for the current user, used by the media upload pickers.
	 *
	 * Media-new and the editor uploader only need the caller's own cabinet and
	 * must stay available to authors without the admin media capability, so this
	 * mirrors `admin_tree()` for one owner instead of exposing the admin route.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function own_folders(): WP_REST_Response|WP_Error {
		$owner = get_current_user_id();

		if ( ! Capabilities::can_manage_own( $owner ) ) {
			return new WP_Error( 'sufm_forbidden', __( '你没有管理文件的权限。', 'sakura-imgopt' ), array( 'status' => 403 ) );
		}

		$owners = $this->folders->admin_tree( array( $owner ), true );
		$me     = $owners ? $owners[0] : array( 'tree' => array() );

		return rest_ensure_response(
			array(
				'owner'  => $owner,
				'tree'   => $me['tree'],
				'files'  => (int) ( $me['files'] ?? 0 ),
				'rooted' => (int) ( $me['unassigned'] ?? 0 ),
			)
		);
	}

	/**
	 * Upload policy for the picker.
	 *
	 * @return WP_REST_Response
	 */
	public function upload_policy(): WP_REST_Response {
		$settings = Settings::get();

		return rest_ensure_response(
			array(
				'max_bytes'   => (int) $settings['max_file_bytes'],
				'max_files'   => (int) $settings['max_user_files'],
				'extensions'  => $this->extensions( $settings['allowed_types'] ),
				'notice'      => $this->notice(),
			)
		);
	}

	/**
	 * Accept one upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function uploads( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner  = get_current_user_id();
		$folder = absint( $request->get_param( 'folder_id' ) );
		$key    = (string) $request->get_param( 'idempotency_key' );
		$files  = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return rest_ensure_response( new WP_Error( 'sufm_upload_missing', __( '没有收到上传文件。', 'sakura-imgopt' ), array( 'status' => 400 ) ) );
		}

		$result = $this->uploader->handle( $owner, $folder, $files['file'], $key );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( array( 'file' => $result ) );
	}

	/**
	 * Create a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_folder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner  = get_current_user_id();
		$parent = absint( $request->get_param( 'parent_id' ) );
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );

		$folder = $this->folders->create( $owner, $parent, $name );

		if ( is_wp_error( $folder ) ) {
			return rest_ensure_response( $folder );
		}

		return rest_ensure_response(
			array(
				'id'   => (int) $folder->id,
				'name' => (string) $folder->name,
			)
		);
	}

	/**
	 * Rename or move a file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function patch_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner = get_current_user_id();
		$id    = (int) $request['id'];
		$name  = $request->get_param( 'name' );
		$move  = $request->get_param( 'folder_id' );

		if ( null !== $name ) {
			$done = $this->files->rename( $owner, $id, sanitize_text_field( (string) $name ) );
			if ( is_wp_error( $done ) ) {
				return rest_ensure_response( $done );
			}
		}

		if ( null !== $move ) {
			$done = $this->files->move( $owner, $id, absint( $move ), $this->folders );
			if ( is_wp_error( $done ) ) {
				return rest_ensure_response( $done );
			}
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Rename a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function patch_folder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner = get_current_user_id();
		$id    = (int) $request['id'];
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$parent = $request->get_param( 'parent_id' );

		if ( '' !== $name ) {
			$done = $this->folders->rename( $owner, $id, $name );

			if ( is_wp_error( $done ) ) {
				return rest_ensure_response( $done );
			}
		}

		if ( null !== $parent ) {
			$done = $this->folders->move( $owner, $id, absint( $parent ) );

			if ( is_wp_error( $done ) ) {
				return rest_ensure_response( $done );
			}
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Owner's deletion requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_requests( WP_REST_Request $request ): WP_REST_Response {
		$owner    = get_current_user_id();
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 10 ) ) );

		$result = $this->requests->list_for_owner( $owner, $status, $page, $per_page );
		$rows   = array();

		foreach ( $result['rows'] as $row ) {
			$rows[] = $this->describe_request( $row );
		}

		return rest_ensure_response(
			array(
				'rows'  => $rows,
				'total' => (int) $result['total'],
				'page'  => $page,
			)
		);
	}

	/**
	 * Submit a deletion request. Approval is a separate admin decision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( Capabilities::REQUEST_DELETE ) ) {
			return rest_ensure_response( new WP_Error( 'sufm_forbidden', __( '当前账号没有提交删除申请的权限。', 'sakura-imgopt' ), array( 'status' => 403 ) ) );
		}

		$owner    = get_current_user_id();
		$targets  = $this->targets( $request->get_param( 'items' ) );
		$reason   = (string) $request->get_param( 'reason' );
		$key      = (string) $request->get_param( 'idempotency_key' );
		$version  = (string) $request->get_param( 'notice_version' );
		$confirmed = (bool) $request->get_param( 'confirmed' );

		if ( is_wp_error( $targets ) ) {
			return rest_ensure_response( $targets );
		}

		$result = $this->requests->create( $owner, $owner, $targets, $reason, $key, $version, $confirmed );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( array( 'request' => $this->describe_request( $result ) ) );
	}

	/**
	 * One request plus its items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner   = get_current_user_id();
		$row     = $this->requests->for_owner( $owner, (int) $request['id'] );

		if ( ! $row ) {
			return rest_ensure_response( new WP_Error( 'sufm_request_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		return rest_ensure_response( $this->describe_request( $row ) );
	}

	/**
	 * Withdraw a pending request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function withdraw_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner = get_current_user_id();
		$row   = $this->requests->for_owner( $owner, (int) $request['id'] );

		if ( ! $row ) {
			return rest_ensure_response( new WP_Error( 'sufm_request_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		$result = $this->requests->withdraw( $row, $owner );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Whitelisted batch actions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner  = get_current_user_id();
		$raw    = $request->get_param( 'items' );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$allowed = array( 'rename', 'move', 'request_delete' );

		if ( ! in_array( $action, $allowed, true ) ) {
			return rest_ensure_response( new WP_Error( 'sufm_action', __( '不支持的批量操作。', 'sakura-imgopt' ), array( 'status' => 400 ) ) );
		}

		if ( 'request_delete' === $action && ! current_user_can( Capabilities::REQUEST_DELETE ) ) {
			return rest_ensure_response( new WP_Error( 'sufm_forbidden', __( '当前账号没有提交删除申请的权限。', 'sakura-imgopt' ), array( 'status' => 403 ) ) );
		}

		if ( 'request_delete' === $action ) {
			$targets = $this->targets( $raw );

			if ( is_wp_error( $targets ) ) {
				return rest_ensure_response( $targets );
			}

			$result = $this->requests->create(
				$owner,
				$owner,
				$targets,
				(string) $request->get_param( 'reason' ),
				(string) $request->get_param( 'idempotency_key' ),
				(string) $request->get_param( 'notice_version' ),
				(bool) $request->get_param( 'confirmed' )
			);

			if ( is_wp_error( $result ) ) {
				return rest_ensure_response( $result );
			}

			return rest_ensure_response( array( 'request' => $this->describe_request( $result ) ) );
		}

		if ( ! is_array( $raw ) || ! $raw ) {
			return rest_ensure_response( new WP_Error( 'sufm_empty', __( '请选择要操作的文件。', 'sakura-imgopt' ), array( 'status' => 400 ) ) );
		}

		$done    = array();
		$skipped = array();
		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$folder  = absint( $request->get_param( 'folder_id' ) );

		foreach ( array_slice( $raw, 0, 100 ) as $entry ) {
			$id = absint( is_array( $entry ) ? ( $entry['id'] ?? 0 ) : $entry );

			if ( $id <= 0 ) {
				continue;
			}

			$result = 'rename' === $action
				? $this->files->rename( $owner, $id, $name )
				: $this->files->move( $owner, $id, $folder, $this->folders );

			if ( is_wp_error( $result ) ) {
				$skipped[] = array(
					'id'    => $id,
					'error' => $result->get_error_code(),
				);
				continue;
			}

			$done[] = $id;
		}

		return rest_ensure_response(
			array(
				'done'    => $done,
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Operation status for a batch or job id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function operation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$id  = (int) $request['id'];
		$job = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'jobs' ) . ' WHERE id=%d', $id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $job ) {
			return rest_ensure_response( new WP_Error( 'sufm_operation', __( '操作不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		if ( (int) $job->owner_id !== get_current_user_id() && ! current_user_can( Capabilities::REVIEW ) ) {
			return rest_ensure_response( new WP_Error( 'sufm_operation', __( '操作不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		return rest_ensure_response(
			array(
				'id'       => (int) $job->id,
				'state'    => (string) $job->state,
				'attempts' => (int) $job->attempts,
				'error'    => (string) $job->last_error,
			)
		);
	}

	/**
	 * Whole cabinet: owners, their folder trees and file counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_folders( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( $this->folders->admin_page( max( 1, (int) $request->get_param( 'page' ) ), (int) ( $request->get_param( 'per_page' ) ?: 50 ), sanitize_text_field( (string) $request->get_param( 'search' ) ), absint( $request->get_param( 'owner_id' ) ) ) );
	}

	/**
	 * Delete a folder and lift its contents to a parent folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_folder_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request['id'] );
		$owner  = absint( $request->get_param( 'owner_id' ) );
		$parent = null !== $request->get_param( 'target_parent' ) ? absint( $request->get_param( 'target_parent' ) ) : null;

		$result = $this->folders->delete( $owner, $id, $parent );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	/**
	 * Reorder or reparent one folder inside an owner's cabinet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_folder_reorder( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner    = absint( $request->get_param( 'owner_id' ) );
		$folder   = absint( $request->get_param( 'folder_id' ) );
		$parent   = absint( $request->get_param( 'parent_id' ) );
		$position = (int) $request->get_param( 'position' );

		$result = $this->folders->reorder( $owner, $folder, $parent, $position );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'ok' => true, 'folder_id' => $folder, 'parent_id' => $parent, 'position' => $position ) );
	}

	/**
	 * Create a folder inside an owner's cabinet.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_folder_create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$owner  = absint( $request->get_param( 'owner_id' ) );
		$parent = absint( $request->get_param( 'parent_id' ) );
		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( $owner <= 0 || ! get_userdata( $owner ) || ! is_user_member_of_blog( $owner ) ) {
			return new WP_Error( 'sufm_owner', __( '请选择文件夹所属用户。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$folder = $this->folders->create( $owner, $parent, $name );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		return rest_ensure_response(
			array(
				'id'        => (int) $folder->id,
				'name'      => (string) $folder->name,
				'parent_id' => (int) $folder->parent_id,
				'owner_id'  => $owner,
			)
		);
	}

	/**
	 * Rename and/or reparent a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_folder_update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request['id'] );
		$owner  = absint( $request->get_param( 'owner_id' ) );
		$folder = $this->folders->get( $owner, $id );

		if ( ! $folder ) {
			return new WP_Error( 'sufm_folder_missing', __( '文件夹不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$name = $request->get_param( 'name' );
		if ( is_string( $name ) && '' !== trim( $name ) ) {
			$renamed = $this->folders->rename( $owner, $id, sanitize_text_field( $name ) );
			if ( is_wp_error( $renamed ) ) {
				return $renamed;
			}
		}

		$parent = $request->get_param( 'parent_id' );
		if ( null !== $parent ) {
			$moved = $this->folders->move( $owner, $id, absint( $parent ) );
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}

		$fresh = $this->folders->get( $owner, $id );

		return rest_ensure_response(
			array(
				'id'        => (int) $fresh->id,
				'name'      => (string) $fresh->name,
				'parent_id' => (int) $fresh->parent_id,
				'owner_id'  => $owner,
			)
		);
	}

	/**
	 * Move managed attachments into one folder of the same owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_folder_move( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->media_target( $request );
		if ( is_wp_error( $target ) ) { return $target; }
		$result = ( new \Nboke\UserFiles\Services\Media_Mover() )->move( $request->get_param( 'attachment_ids' ), $target[0], $target[1] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function can_manage_media(): bool { return current_user_can( Capabilities::MANAGE_MEDIA ); }

	public function admin_transfer_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->media_target( $request );
		if ( is_wp_error( $target ) ) { return $target; }
		$result = ( new \Nboke\UserFiles\Services\Media_Mover() )->preview( $request->get_param( 'attachment_ids' ), $target[0], $target[1] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function admin_transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$target = $this->media_target( $request );
		if ( is_wp_error( $target ) ) { return $target; }
		$result = ( new \Nboke\UserFiles\Services\Media_Mover() )->execute( $request->get_param( 'attachment_ids' ), $target[0], $target[1], (string) $request->get_param( 'snapshot_hash' ), (string) $request->get_param( 'idempotency_key' ), true === $request->get_param( 'confirmed' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function admin_transfer_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$table = Schema::table( 'media_operations' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT actor_id,result_json,notification_status,last_error FROM {$table} WHERE id=%d", absint( $request['id'] ) ) );
		if ( ! $row || ( (int) $row->actor_id !== get_current_user_id() && ! current_user_can( Capabilities::TRANSFER ) ) ) { return new WP_Error( 'sufm_operation', __( '操作不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ); }
		$result = json_decode( (string) $row->result_json, true );
		if ( ! is_array( $result ) ) { return new WP_Error( 'sufm_operation', __( '操作尚未完成。', 'sakura-imgopt' ), array( 'status' => 409 ) ); }
		unset( $result['notification_receipts'] );
		$result['notification_status'] = (string) $row->notification_status;
		$result['notification_error'] = (string) $row->last_error;
		return rest_ensure_response( $result );
	}

	private function media_target( WP_REST_Request $request ): array|WP_Error {
		$owner = $request->get_param( 'owner_id' ); $folder = $request->get_param( 'folder_id' );
		if ( ( ! is_int( $owner ) && ! is_string( $owner ) ) || ! ctype_digit( (string) $owner ) || (int) $owner < 1 || ( ! is_int( $folder ) && ! is_string( $folder ) ) || ! ctype_digit( (string) $folder ) ) {
			return new WP_Error( 'sufm_move_target', __( '请选择有效用户与目录。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}
		return array( (int) $owner, (int) $folder );
	}

	/**
	 * Admin review queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_queue( WP_REST_Request $request ): WP_REST_Response {
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

		$result = $this->requests->admin_queue( $status, $search, $page, $per_page );
		$rows   = array();

		foreach ( $result['rows'] as $row ) {
			$rows[] = $this->describe_request( $row );
		}

		return rest_ensure_response(
			array(
				'rows'   => $rows,
				'total'  => (int) $result['total'],
				'counts' => array(
					'pending_review' => $this->requests->count( 0, Requests::PENDING ),
					'approved'       => $this->requests->count( 0, Requests::APPROVED ),
					'needs_review'   => $this->requests->count( 0, Requests::NEEDS ),
					'queued'         => $this->requests->count( 0, Requests::QUEUED ),
				),
			)
		);
	}

	/**
	 * Files in one request, shaped for the admin media gallery.
	 *
	 * The queue response intentionally stays small: this endpoint is loaded only
	 * when an administrator opens a request, and it never exposes a filesystem
	 * path. A folder request is expanded to its frozen snapshot so the reviewer
	 * sees the actual files that would be deleted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_request_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->requests->get( (int) $request['id'] );

		if ( ! $row ) {
			return rest_ensure_response( new WP_Error( 'sufm_request_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		$owner = get_userdata( (int) $row->owner_id );

		return rest_ensure_response(
			array(
				'request' => array(
					'id'         => (int) $row->id,
					'owner_id'   => (int) $row->owner_id,
					'owner_name' => $owner ? (string) $owner->display_name : '',
					'status'     => (string) $row->status,
				),
				'items'   => $this->request_gallery( $row ),
			)
		);
	}

	/**
	 * Expand a request into previewable file entries.
	 *
	 * @param object $row Request row.
	 * @return array<int,array<string,mixed>>
	 */
	private function request_gallery( object $row ): array {
		$labels = $this->request_item_state_labels();
		$items  = $this->requests->items( (int) $row->id );
		$out    = array();

		foreach ( $items as $item ) {
			$state = (string) $item->state;

			if ( 'folder' === (string) $item->object_type ) {
				$snapshot = json_decode( (string) $item->manifest_snapshot, true );
				$ids      = array_values( array_filter( array_map( 'absint', (array) ( $snapshot['files'] ?? array() ) ) ) );
				$children = $this->request_child_files( (int) $row->owner_id, $ids );

				if ( ! $children ) {
					$out[] = $this->folder_preview( $item, (int) $row->id, $labels );
					continue;
				}

				foreach ( $children as $child ) {
					$out[] = $this->file_preview( $child, (string) ( $item->folder_name ?? '' ), (int) $row->id, $state, $labels );
				}
				continue;
			}

			$out[] = $this->file_preview( $item, '', (int) $row->id, $state, $labels );
		}

		return $out;
	}

	/**
	 * Read the frozen files of a folder request in one query.
	 *
	 * @param int        $owner_id Owner id.
	 * @param array<int> $ids      File ids from the folder snapshot.
	 * @return array<int,object>
	 */
	private function request_child_files( int $owner_id, array $ids ): array {
		global $wpdb;

		if ( ! $ids ) {
			return array();
		}

		$table        = Schema::table( 'files' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT id,attachment_id,display_name,charged_bytes,storage_state FROM {$table} WHERE owner_id=%d AND id IN ({$placeholders}) ORDER BY id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $owner_id ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Preview payload for one request file.
	 *
	 * @param object               $file     File row or request item.
	 * @param string               $group    Folder name, when expanded from a folder.
	 * @param int                  $request_id Request id.
	 * @param string               $state    Item state.
	 * @param array<string,string> $labels   State labels.
	 * @return array<string,mixed>
	 */
	private function file_preview( object $file, string $group, int $request_id, string $state, array $labels ): array {
		$attachment = (int) ( $file->attachment_id ?? 0 );
		$name       = (string) ( $file->file_name ?? $file->display_name ?? '' );
		$size       = (int) ( $file->charged_bytes ?? 0 );
		$path       = $attachment > 0 ? (string) get_attached_file( $attachment ) : '';
		$mime       = $attachment > 0 ? (string) get_post_mime_type( $attachment ) : '';
		$filetype   = wp_check_filetype( '' !== $path ? $path : $name );

		if ( '' === $mime && ! empty( $filetype['type'] ) ) {
			$mime = (string) $filetype['type'];
		}

		$ext  = strtoupper( (string) pathinfo( '' !== $path ? $path : $name, PATHINFO_EXTENSION ) );
		$kind = $this->preview_kind( $mime, $path, $name );
		$url  = $attachment > 0 ? (string) wp_get_attachment_url( $attachment ) : '';
		$view = in_array( $kind, array( 'image', 'video' ), true ) ? $url : '';

		$download = '';
		if ( $attachment > 0 ) {
			$download = add_query_arg(
				array(
					'action'     => 'sakura_sufm_download',
					'attachment' => $attachment,
					'_wpnonce'   => wp_create_nonce( 'sakura_sufm_download' ),
				),
				admin_url( 'admin-post.php' )
			);
		}

		$kind_labels = array(
			'image'   => __( '图片', 'sakura-imgopt' ),
			'video'   => __( '视频', 'sakura-imgopt' ),
			'archive' => __( '压缩包', 'sakura-imgopt' ),
			'folder'  => __( '文件夹', 'sakura-imgopt' ),
			'other'   => __( '文件', 'sakura-imgopt' ),
		);

		return array(
			'id'          => (int) ( $file->id ?? 0 ),
			'name'        => $name,
			'path'        => '' !== $group ? $group . ' / ' . $name : sprintf( /* translators: %d: request id. */ __( '申请 #%d', 'sakura-imgopt' ), $request_id ),
			'kind'        => $kind,
			'kind_label'  => $kind_labels[ $kind ] ?? $kind_labels['other'],
			'mime'        => $mime,
			'ext'         => $ext,
			'size'        => $size,
			'size_label'  => size_format( $size ),
			'status'      => $state,
			'status_label' => $labels[ $state ] ?? $state,
			'place_label' => '' !== $group ? __( '文件夹内文件', 'sakura-imgopt' ) : __( '申请文件', 'sakura-imgopt' ),
			'url'         => $view,
			'download'    => $download,
		);
	}

	/**
	 * Placeholder for a folder whose snapshot has no readable files.
	 *
	 * @param object               $item    Request item.
	 * @param int                  $request_id Request id.
	 * @param array<string,string> $labels  State labels.
	 * @return array<string,mixed>
	 */
	private function folder_preview( object $item, int $request_id, array $labels ): array {
		$snapshot = json_decode( (string) $item->manifest_snapshot, true );
		$count    = is_array( $snapshot ) ? count( (array) ( $snapshot['files'] ?? array() ) ) : 0;
		$state    = (string) $item->state;

		return array(
			'id'          => (int) $item->id,
			'name'        => (string) ( $item->folder_name ?? __( '文件夹', 'sakura-imgopt' ) ),
			'path'        => sprintf( /* translators: %d: request id. */ __( '申请 #%d · 文件夹', 'sakura-imgopt' ), $request_id ),
			'kind'        => 'folder',
			'kind_label'  => __( '文件夹', 'sakura-imgopt' ),
			'size'        => 0,
			'size_label'  => '',
			'status'      => $state,
			'status_label' => $labels[ $state ] ?? $state,
			'place_label' => __( '申请文件', 'sakura-imgopt' ),
			'url'         => '',
			'download'    => '',
			'message'     => sprintf( /* translators: %d: file count. */ __( '该文件夹申请包含 %d 个文件，但快照中没有可读取的附件。', 'sakura-imgopt' ), $count ),
		);
	}

	/**
	 * Classify a file from its MIME type and extension.
	 *
	 * @param string $mime MIME type.
	 * @param string $path Path.
	 * @param string $name Display name.
	 * @return string
	 */
	private function preview_kind( string $mime, string $path, string $name ): string {
		$ext = strtolower( (string) pathinfo( '' !== $path ? $path : $name, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, array( 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz' ), true ) ) {
			return 'archive';
		}
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}

		return 'other';
	}

	/**
	 * Human labels for item states.
	 *
	 * @return array<string,string>
	 */
	private function request_item_state_labels(): array {
		return array(
			'pending'        => __( '待处理', 'sakura-imgopt' ),
			'pending_review' => __( '待审核', 'sakura-imgopt' ),
			'approved'       => __( '已批准', 'sakura-imgopt' ),
			'rejected'       => __( '已驳回', 'sakura-imgopt' ),
			'revoked'        => __( '已撤销', 'sakura-imgopt' ),
			'withdrawn'      => __( '已撤回', 'sakura-imgopt' ),
			'queued'         => __( '待执行', 'sakura-imgopt' ),
			'executing'      => __( '执行中', 'sakura-imgopt' ),
			'done'           => __( '已删除', 'sakura-imgopt' ),
			'failed'         => __( '失败', 'sakura-imgopt' ),
			'stopped'        => __( '已停止', 'sakura-imgopt' ),
		);
	}

	/**
	 * Approve, reject or flag a request. Approval never deletes anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row      = $this->requests->get( (int) $request['id'] );
		$actor    = get_current_user_id();
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );
		$reason   = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );

		if ( ! $row ) {
			return rest_ensure_response( new WP_Error( 'sufm_request_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		if ( 'approve' === $decision ) {
			$result = $this->requests->approve( $row, $actor );
		} elseif ( 'reject' === $decision ) {
			$result = $this->requests->reject( $row, $actor, $reason );
		} elseif ( 'needs_review' === $decision ) {
			$result = $this->requests->transition( (int) $row->id, (string) $row->status, Requests::NEEDS, array( 'reviewer_id' => $actor, 'reviewed_at' => Time::now() ) );
		} else {
			return rest_ensure_response( new WP_Error( 'sufm_decision', __( '未知的审核操作。', 'sakura-imgopt' ), array( 'status' => 400 ) ) );
		}

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		if ( ! $result ) {
			return rest_ensure_response( new WP_Error( 'sufm_state', __( '申请状态已经变化，请刷新后重试。', 'sakura-imgopt' ), array( 'status' => 409 ) ) );
		}

		if ( $this->notifier ) {
			$fresh = $this->requests->get( (int) $row->id );

			if ( $fresh ) {
				$status = 'approve' === $decision ? Requests::APPROVED : ( 'reject' === $decision ? Requests::REJECTED : Requests::NEEDS );
				$this->notifier->review( $fresh, $status, $reason );
			}
		}

		$this->events->log(
			array(
				'actor_id'   => $actor,
				'owner_id'   => (int) $row->owner_id,
				'request_id' => (int) $row->id,
				'subject_id' => (int) $row->id,
				'action'     => 'review_' . $decision,
				'result'     => 'ok',
				'safe_detail' => mb_substr( $reason, 0, 120 ),
			)
		);

		return rest_ensure_response( array( 'ok' => true, 'status' => (string) ( $this->requests->get( (int) $row->id )->status ) ) );
	}

	/**
	 * Revoke an approval.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row   = $this->requests->get( (int) $request['id'] );
		$actor = get_current_user_id();

		if ( ! $row ) {
			return rest_ensure_response( new WP_Error( 'sufm_request_missing', __( '申请不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		$reason = sanitize_textarea_field( (string) $request->get_param( 'reason' ) );
		$result = $this->requests->revoke( $row, $actor, $reason );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		if ( $this->notifier ) {
			$fresh = $this->requests->get( (int) $row->id );

			if ( $fresh ) {
				$this->notifier->review( $fresh, Requests::REVOKED, $reason );
			}
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Preview hash and scope for a set of approved requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_batch_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids    = array_map( 'absint', (array) $request->get_param( 'requests' ) );
		$result = $this->executor->preview( $ids );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create and queue a deletion batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = array_map( 'absint', (array) $request->get_param( 'requests' ) );

		$result = $this->executor->confirm(
			$ids,
			get_current_user_id(),
			(string) $request->get_param( 'phrase' ),
			(string) $request->get_param( 'hash' )
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Batch state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_batch_get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$batch = $this->executor->batch( (int) $request['id'] );

		if ( ! $batch ) {
			return rest_ensure_response( new WP_Error( 'sufm_batch_missing', __( '删除批次不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		$requests_table = Schema::table( 'delete_requests' );
		$rows           = $wpdb->get_results(
			$wpdb->prepare( "SELECT id,status,owner_id FROM {$requests_table} WHERE batch_id=%d", (int) $batch->id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return rest_ensure_response(
			array(
				'batch'    => $batch,
				'requests' => is_array( $rows ) ? $rows : array(),
				'job'      => $wpdb->get_row(
					$wpdb->prepare( 'SELECT id,state,attempts,last_error FROM ' . Schema::table( 'jobs' ) . ' WHERE subject_id=%d AND type=%s LIMIT 1', (int) $batch->id, 'delete_batch' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				),
			)
		);
	}

	/**
	 * Stop a running batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_batch_stop( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->executor->stop( (int) $request['id'], get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Users with a file space and their usage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_users( WP_REST_Request $request ): WP_REST_Response {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$page   = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per    = min( 100, max( 5, absint( $request->get_param( 'per_page' ) ?: 25 ) ) );

		$query = new \WP_User_Query(
			array(
				'search'         => '' === $search ? '' : '*' . esc_attr( $search ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => $per,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$users = $query->get_results();
		$ids   = array();

		foreach ( $users as $user ) {
			$ids[] = (int) $user->ID;
		}

		// Four aggregates for the whole page instead of four queries per row
		// (audit A08): 40 queries at per_page=10, 100+ at per_page=25.
		$usage    = $this->quota->snapshots( $ids );
		$files    = $this->files->counts_by_owner( $ids );
		$folders  = $this->folders->counts_by_owner( $ids );
		$pending  = $this->requests->pending_counts_by_owner( $ids );
		$active   = $this->sanctions ? $this->sanctions->repo()->active_map( $ids ) : array();

		$rows = array();

		foreach ( $users as $user ) {
			$id       = (int) $user->ID;
			$snapshot = isset( $usage[ $id ] ) ? $usage[ $id ] : array(
				'quota'    => 0,
				'used'     => 0,
				'reserved' => 0,
			);

			$sanction = isset( $active[ $id ] ) ? $active[ $id ] : null;

			$rows[] = array(
				'id'       => $id,
				'name'     => (string) $user->display_name,
				'login'    => (string) $user->user_login,
				'email'    => (string) $user->user_email,
				'quota'    => (int) $snapshot['quota'],
				'used'     => (int) $snapshot['used'],
				'reserved' => (int) $snapshot['reserved'],
				'files'    => isset( $files[ $id ] ) ? (int) $files[ $id ] : 0,
				'folders'  => isset( $folders[ $id ] ) ? (int) $folders[ $id ] : 0,
				'pending'  => isset( $pending[ $id ] ) ? (int) $pending[ $id ] : 0,
				'sanction'  => null === $sanction ? null : array(
					'id'    => (int) $sanction->id,
					'type'  => (string) $sanction->type,
					'badge' => Sanction_Service::badge( $sanction ),
					'ends'  => (string) ( $sanction->ends_at ?? '' ),
				),
				// Server-side decision so the UI never offers a button the
				// endpoint would reject anyway.
				'can_sanction' => $this->sanctions ? $this->sanctions->can_sanction( $id ) : false,
			);
		}

		return rest_ensure_response(
			array(
				'rows'     => $rows,
				'total'    => (int) $query->get_total(),
				'page'     => $page,
				'per_page' => $per,
			)
		);
	}

	/**
	 * Start (or resume) the media-library scan.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_sync_start(): WP_REST_Response|WP_Error {
		if ( ! $this->sync ) {
			return new WP_Error( 'sufm_no_sync', __( '同步服务未初始化。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$state = $this->sync->start();
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$this->sync->tick( 1200 );

		return rest_ensure_response( $this->sync->state() );
	}

	/**
	 * Scan progress.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_sync_status(): WP_REST_Response|WP_Error {
		if ( ! $this->sync ) {
			return new WP_Error( 'sufm_no_sync', __( '同步服务未初始化。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$state = $this->sync->state();
		if ( 'running' === (string) $state['state'] ) {
			$state = $this->sync->tick( 1200 );
		}

		return rest_ensure_response( $state );
	}

	/**
	 * Clear every per-user quota override so the default applies again.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_reset_quotas(): WP_REST_Response {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s LIMIT 5000",
				\Nboke\UserFiles\Settings::QUOTA_META
			)
		);

		$reset = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $user_id ) {
			if ( delete_user_meta( (int) $user_id, \Nboke\UserFiles\Settings::QUOTA_META ) ) {
				$reset++;
			}
		}

		$this->events->log(
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => 'quota_reset',
				'result'      => 'ok',
				'safe_detail' => 'users=' . $reset,
			)
		);

		return rest_ensure_response( array( 'reset' => $reset ) );
	}

	/**
	 * Paged audit log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function admin_events( WP_REST_Request $request ): WP_REST_Response {
		$page = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$per  = min( 100, max( 5, absint( $request->get_param( 'per_page' ) ?: 25 ) ) );

		$data = $this->events->paged( $page, $per );

		return rest_ensure_response(
			array(
				'rows'     => $data['rows'],
				'total'    => (int) $data['total'],
				'page'     => $page,
				'per_page' => $per,
			)
		);
	}

	/**
	 * Override one user's quota.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_quota( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = (int) $request['id'];
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return rest_ensure_response( new WP_Error( 'sufm_user_missing', __( '用户不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) ) );
		}

		$bytes = (int) $request->get_param( 'quota_bytes' );

		if ( $bytes < 0 ) {
			// Negative clears the override: fall back to the site default.
			delete_user_meta( $user_id, Settings::QUOTA_META );
			$bytes = 0;
		} elseif ( 0 === $bytes ) {
			// An explicit 0 blocks uploads for this account.
			update_user_meta( $user_id, Settings::QUOTA_META, 0 );
		} else {
			$bytes = max( MB_IN_BYTES, min( 1024 * 1024 * 1024 * 1024, $bytes ) );
			update_user_meta( $user_id, Settings::QUOTA_META, $bytes );
		}

		$this->events->log(
			array(
				'actor_id'   => get_current_user_id(),
				'owner_id'   => $user_id,
				'subject_id' => $user_id,
				'action'     => 'quota_set',
				'result'     => 'ok',
				'safe_detail' => 'bytes=' . (string) $bytes,
			)
		);

		return rest_ensure_response( array( 'quota' => Settings::quota_for( $user_id ) ) );
	}

	/**
	 * Current sanction for one user, plus what the ladder would suggest.
	 *
	 * The dialog needs both: the active row (to offer "revoke") and the
	 * suggestion (to pre-select type and duration) — and the suggestion must be
	 * computed server-side so the client never has to know the ladder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_sanction_get( WP_REST_Request $request ) {
		if ( ! $this->sanctions ) {
			return new WP_Error( 'sufm_no_sanctions', __( '处罚服务未初始化。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$user_id = (int) $request['id'];
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'sufm_user_missing', __( '用户不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$repo   = $this->sanctions->repo();
		$active = $repo->active_for( $user_id, 1 );
		$active = $active ? $active[0] : null;

		return rest_ensure_response(
			array(
				'user'         => array(
					'id'    => $user_id,
					'name'  => (string) $user->display_name,
					'login' => (string) $user->user_login,
				),
				'active'       => null === $active ? null : $this->sanction_row( $active ),
				'suggestion'   => $this->sanctions->suggest( $user_id, '' ),
				'can_sanction' => $this->sanctions->can_sanction( $user_id ),
				'types'        => array(
					array( 'value' => Sanctions::TYPE_WARN, 'label' => __( '仅警告（只通知，不限制上传）', 'sakura-imgopt' ) ),
					array( 'value' => Sanctions::TYPE_UPLOAD_BAN, 'label' => __( '禁止上传（仍可登录、仍可删除自己文件）', 'sakura-imgopt' ) ),
				),
				'durations'    => array(
					array( 'value' => 7, 'label' => __( '7 天', 'sakura-imgopt' ) ),
					array( 'value' => 30, 'label' => __( '30 天', 'sakura-imgopt' ) ),
					array( 'value' => 0, 'label' => __( '永久（需输入用户名确认）', 'sakura-imgopt' ) ),
				),
				'reasons'      => $this->sanction_reasons(),
			)
		);
	}

	/**
	 * The closed reason list, shared with the takedown action.
	 *
	 * Same six keys the media library offers, because both paths write the same
	 * `reason_code` and the audit table must stay comparable across them.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	private function sanction_reasons(): array {
		return array(
			array( 'value' => 'copyright', 'label' => __( '侵犯版权', 'sakura-imgopt' ) ),
			array( 'value' => 'illegal', 'label' => __( '违法违规内容', 'sakura-imgopt' ) ),
			array( 'value' => 'abuse', 'label' => __( '辱骂或骚扰', 'sakura-imgopt' ) ),
			array( 'value' => 'privacy', 'label' => __( '泄露他人隐私', 'sakura-imgopt' ) ),
			array( 'value' => 'policy', 'label' => __( '违反站点规则', 'sakura-imgopt' ) ),
			array( 'value' => 'other', 'label' => __( '其他', 'sakura-imgopt' ) ),
		);
	}

	/**
	 * Apply a sanction to one uploader.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_sanction_create( WP_REST_Request $request ) {
		if ( ! $this->sanctions ) {
			return new WP_Error( 'sufm_no_sanctions', __( '处罚服务未初始化。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$user_id = (int) $request['id'];
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'sufm_user_missing', __( '用户不存在。', 'sakura-imgopt' ), array( 'status' => 404 ) );
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$days = (int) $request->get_param( 'days' );

		if ( ! Sanctions::is_type( $type ) ) {
			return new WP_Error( 'sufm_sanction_type', __( '处罚类型不合法。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		/* Permanent means "no end date", so it is the one decision that cannot
		 * be walked back by time. Requiring the login name typed back makes an
		 * accidental permanent ban impossible from a mis-click. */
		if ( 0 === $days && Sanctions::TYPE_WARN !== $type ) {
			$typed = sanitize_user( (string) $request->get_param( 'confirm' ), true );

			if ( $typed !== (string) $user->user_login ) {
				return new WP_Error(
					'sufm_sanction_confirm',
					__( '永久处罚需要输入该用户的登录名确认。', 'sakura-imgopt' ),
					array( 'status' => 400 )
				);
			}
		}

		$result = $this->sanctions->apply(
			array(
				'owner_id'    => $user_id,
				'actor_id'    => get_current_user_id(),
				'type'        => $type,
				'scope'       => Sanctions::TYPE_UPLOAD_BAN === $type ? Sanctions::SCOPE_PLUGIN : Sanctions::SCOPE_SITE,
				'reason_code' => sanitize_key( (string) $request->get_param( 'reason_code' ) ),
				'note'        => sanitize_text_field( (string) $request->get_param( 'note' ) ),
				'days'        => $days,
				'source'      => 'manual',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $this->sanctions->repo()->get( $result );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'sanction' => null === $row ? null : $this->sanction_row( $row ),
			)
		);
	}

	/**
	 * Lift a sanction early.
	 *
	 * The files it was about are already gone — deleting is not undone by
	 * clearing the decision — so the flag travels with the response and the
	 * client is expected to keep saying so.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_sanction_revoke( WP_REST_Request $request ) {
		if ( ! $this->sanctions ) {
			return new WP_Error( 'sufm_no_sanctions', __( '处罚服务未初始化。', 'sakura-imgopt' ), array( 'status' => 500 ) );
		}

		$id     = (int) $request['id'];
		$result = $this->sanctions->revoke( $id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'              => true,
				'content_restored' => false,
			)
		);
	}

	/**
	 * Wire shape of one sanction row.
	 *
	 * @param object $row Row.
	 * @return array<string, mixed>
	 */
	private function sanction_row( object $row ): array {
		return array(
			'id'          => (int) $row->id,
			'owner_id'    => (int) $row->owner_id,
			'type'        => (string) $row->type,
			'scope'       => (string) $row->scope,
			'reason_code' => (string) $row->reason_code,
			'note'        => (string) $row->note,
			'starts_at'   => (string) $row->starts_at,
			'ends_at'     => (string) ( $row->ends_at ?? '' ),
			'permanent'   => null === $row->ends_at || '' === (string) $row->ends_at,
			'state'       => (string) $row->state,
			'label'       => Sanction_Service::describe( $row ),
			'badge'       => Sanction_Service::badge( $row ),
		);
	}

	/**
	 * Recompute used bytes from the file rows and report the delta.
	 *
	 * @return WP_REST_Response
	 */
	public function admin_reconcile(): WP_REST_Response {
		global $wpdb;

		$usage = Schema::table( 'usage' );
		$files = Schema::table( 'files' );

		$owners = $wpdb->get_col( "SELECT owner_id FROM {$usage}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$report = array();

		foreach ( is_array( $owners ) ? array_slice( $owners, 0, 200 ) : array() as $owner ) {
			$owner = (int) $owner;

			$sum = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(charged_bytes),0) FROM {$files} WHERE owner_id=%d AND lifecycle<>'purged'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$owner
				)
			);

			$current = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT used_bytes FROM {$usage} WHERE owner_id=%d", $owner ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			if ( $sum === $current ) {
				continue;
			}

			$this->quota->adjust_used( $owner, $sum - $current );

			$report[] = array(
				'owner' => $owner,
				'from'  => $current,
				'to'    => $sum,
			);
		}

		return rest_ensure_response(
			array(
				'fixed' => count( $report ),
				'rows'  => $report,
			)
		);
	}

	/**
	 * Normalise the client's target list.
	 *
	 * @param mixed $raw Raw items.
	 * @return array<int, array{type:string,id:int}>|WP_Error
	 */
	private function targets( $raw ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return new WP_Error( 'sufm_empty', __( '请选择要申请删除的内容。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$out = array();

		foreach ( array_slice( $raw, 0, 500 ) as $entry ) {
			$type = sanitize_key( (string) ( is_array( $entry ) ? ( $entry['type'] ?? '' ) : '' ) );
			$id   = absint( is_array( $entry ) ? ( $entry['id'] ?? 0 ) : $entry );

			if ( $id <= 0 || ! in_array( $type, array( 'file', 'folder' ), true ) ) {
				continue;
			}

			$out[] = array(
				'type' => $type,
				'id'   => $id,
			);
		}

		if ( ! $out ) {
			return new WP_Error( 'sufm_empty', __( '请选择要申请删除的内容。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		return $out;
	}

	/**
	 * Path-free request payload.
	 *
	 * @param object $row Request row.
	 * @return array<string, mixed>
	 */
	private function describe_request( object $row ): array {
		$items = $this->requests->items( (int) $row->id );

		return array(
			'id'                  => (int) $row->id,
			'owner_id'            => (int) $row->owner_id,
			'status'              => (string) $row->status,
			'reason'              => (string) $row->reason,
			'items'               => array_map(
				static function ( $item ) {
					return array(
						'id'          => (int) $item->id,
						'type'        => (string) $item->object_type,
						'object_id'   => (int) $item->object_id,
						'name'        => (string) ( $item->file_name ?? $item->folder_name ?? '' ),
						'state'       => (string) $item->state,
						'result'      => (string) $item->result,
						'bytes'       => (int) $item->charged_bytes,
					);
				},
				$items
			),
			'item_count'          => count( $items ),
			'bytes'               => $this->requests->bytes( (int) $row->id ),
			'created_at'          => (string) $row->created_at,
			'reviewed_at'         => (string) $row->reviewed_at,
			'approval_revision'   => (int) $row->approval_revision,
			'batch_id'            => (int) $row->batch_id,
			'owner_name'          => (string) ( $row->owner_name ?? '' ),
		);
	}

	/**
	 * Fixed reference notice text shown before a deletion request.
	 *
	 * @return string
	 */
	private function notice(): string {
		return __( '删除后文章、商品、头像等位置的链接可能失效，请自行确认这些文件是否仍被使用。通过审核只代表申请被批准，管理员确认执行后才会真正删除。', 'sakura-imgopt' );
	}

	/**
	 * Map allowed MIME types to the extensions shown in the file picker.
	 *
	 * @param array<int, string> $mimes Allowed MIME list.
	 * @return array<int, string>
	 */
	private function extensions( array $mimes ): array {
		$map = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/webp'      => 'webp',
			'image/gif'       => 'gif',
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
			'application/zip' => 'zip',
			'video/mp4'       => 'mp4',
			'audio/mpeg'      => 'mp3',
		);

		$out = array();

		foreach ( $mimes as $mime ) {
			if ( isset( $map[ $mime ] ) ) {
				$out[] = $map[ $mime ];
			}
		}

		return $out;
	}
}
