<?php
/**
 * REST surface for the sync centre's scan lifecycle.
 *
 * The handlers live on Sync_Center_Module so the admin-ajax adapters and these
 * routes share one implementation: no controller copies the reconcile gate, the
 * scan state machine or the queue rules.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\S3\Sync_Center_Module;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the sync-centre routes.
 */
final class Sync_Controller {

	/**
	 * Namespace shared with the other storage routes.
	 */
	private const NS = 'sakura-tools/v1';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		$routes = array(
			'/storage/sync/scan'        => 'scan',
			'/storage/sync/scan/step'   => 'scan_step',
			'/storage/sync/scan/cancel' => 'scan_cancel',
			'/storage/sync/scan/resume' => 'scan_resume',
			'/storage/sync/refresh'     => 'refresh',
			'/storage/sync/page'        => 'page',
			'/storage/sync/preview'     => 'preview',
			'/storage/sync/latency'     => 'latency',
			'/storage/sync/toggle'      => 'toggle',
			'/storage/sync/notice'      => 'notice',
			'/storage/sync/test'        => 'test',
			'/storage/sync/ignored'     => 'ignored',
			'/storage/sync/action'      => 'action',
			'/storage/sync/retry'       => 'retry',
		);

		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'can_manage' ),
				)
			);
		}

		/*
		 * The two file responses are GET links, so they take the browser's cookie
		 * session plus a _wpnonce query argument; they stream themselves below
		 * instead of travelling through the JSON encoder.
		 */
		foreach ( array( '/storage/sync/export' => 'export', '/storage/sync/media' => 'media' ) as $route => $callback ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Serve a file response through the escape hatch.
	 *
	 * WP sends Content-Type: application/json before dispatch and JSON-encodes
	 * the body afterwards; rest_pre_serve_request is the supported way out.
	 *
	 * @param string   $route Route path, as WP_REST_Request::get_route() reports it.
	 * @param callable $serve Callback that sets headers and streams the body.
	 * @return WP_REST_Response
	 */
	private static function stream( string $route, callable $serve ): WP_REST_Response {
		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $request ) use ( $route, $serve ) {
				if ( $served || ! $request instanceof WP_REST_Request || $route !== $request->get_route() ) {
					return $served;
				}

				$serve();

				return true;
			},
			10,
			3
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * CSV export.
	 *
	 * @return WP_REST_Response
	 */
	public static function export(): WP_REST_Response {
		return self::stream(
			'/sakura-tools/v1/storage/sync/export',
			static function (): void {
				self::module()->serve_export();
			}
		);
	}

	/**
	 * Download one object into the media library.
	 *
	 * @return WP_REST_Response
	 */
	public static function media(): WP_REST_Response {
		return self::stream(
			'/sakura-tools/v1/storage/sync/media',
			static function (): void {
				self::module()->serve_media();
			}
		);
	}

	/**
	 * Permission callback: the screen is manage_options only.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * One module instance per request; its services are stateless enough to share.
	 *
	 * @return Sync_Center_Module
	 */
	private static function module(): Sync_Center_Module {
		static $module = null;

		if ( null === $module ) {
			$module = new Sync_Center_Module();
		}

		return $module;
	}

	/**
	 * Turn a handler result into a REST reply.
	 *
	 * @param array<string,mixed>|\WP_Error $result Handler result.
	 * @return WP_REST_Response|\WP_Error
	 */
	private static function reply( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( (array) $result );
	}

	/**
	 * Start a fresh inventory scan.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function scan() {
		return self::reply( self::module()->handle_scan() );
	}

	/**
	 * Advance one scan step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function scan_step( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_scan_step( (int) $request->get_param( 'scan_id' ) ) );
	}

	/**
	 * Request cancellation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function scan_cancel( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_cancel( (int) $request->get_param( 'scan_id' ) ) );
	}

	/**
	 * Resume the interrupted scan.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function scan_resume() {
		return self::reply( self::module()->handle_resume() );
	}

	/**
	 * Recompute the diff from the index.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function refresh() {
		return self::reply( self::module()->handle_refresh() );
	}

	/**
	 * Keyset page for the reconciliation table.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function page( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_page( (array) ( $request->get_json_params() ?: $request->get_params() ) ) );
	}

	/**
	 * Preview payload for one object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_preview( (string) $request->get_param( 'object_key' ) ) );
	}

	/**
	 * Bucket round-trip time.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function latency() {
		return self::reply( self::module()->handle_latency() );
	}

	/**
	 * Enable or disable the persistent index.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function toggle( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_toggle_index( '1' === (string) $request->get_param( 'enabled' ) ) );
	}

	/**
	 * Mute (or un-mute) the configuration dialog.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function notice( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_dismiss_config_notice( '1' === (string) $request->get_param( 'dismiss' ) ) );
	}

	/**
	 * Measure bucket connectivity.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function test() {
		return self::reply( self::module()->handle_test() );
	}

	/**
	 * Persist the ignore rules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function ignored( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_ignored_save( (string) $request->get_param( 'raw' ) ) );
	}

	/**
	 * Upload, download, delete or adopt objects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function action( WP_REST_Request $request ) {
		return self::reply( self::module()->handle_action( (array) ( $request->get_json_params() ?: array() ) ) );
	}

	/**
	 * Requeue failed uploads and dead deletes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function retry( WP_REST_Request $request ) {
		$module = new \Sakura\ImageOptimizer\S3\Storage_Module();

		return self::reply( $module->handle_retry( (int) $request->get_param( 'attachment_id' ) ) );
	}
}
