<?php
/**
 * REST endpoints for the attachment URL rewrite island.
 *
 * The scan/run/revert buttons used to post to admin-ajax; they speak JSON now,
 * so the export screen owns one write channel. The service still owns the
 * cursor and the time box, so a dropped request can resume on the next click.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Repository;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Scope;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the rewrite routes.
 */
final class Rewrite_Controller {

	/**
	 * Route namespace shared by every island.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Route id of the screen these actions belong to.
	 */
	public const PAGE = 'storage-rewrite';

	/**
	 * Base path every action hangs off.
	 */
	private const BASE = '/storage/rewrite';

	/**
	 * Register every route this controller owns.
	 */
	public static function register(): void {
		self::add(
			'scan',
			'scan',
			array(
				'from' => array(
					'type'    => 'string',
					'default' => '',
				),
				'to'   => array(
					'type'    => 'string',
					'default' => '',
				),
			)
		);

		self::add( 'scan-step', 'scan_step' );

		self::add(
			'run',
			'run',
			array(
				'tasks' => array(
					'type'    => 'array',
					'default' => array(),
				),
				'batch' => array(
					'type'    => 'integer',
					'default' => 0,
				),
			)
		);

		self::add(
			'preview',
			'preview',
			array(
				'index' => array(
					'type'    => 'integer',
					'default' => 0,
				),
			)
		);

		self::add(
			'revert',
			'revert',
			array(
				'runId' => array(
					'type'    => 'string',
					'default' => '',
				),
				'after' => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'batch' => array(
					'type'    => 'integer',
					'default' => 0,
				),
			)
		);

		self::add( 'reset', 'reset' );
	}

	/**
	 * Permission callback: the same tab gate as the page.
	 *
	 * @return true|WP_Error
	 */
	public static function can_manage() {
		if ( ! App_Manifest::can_view( self::PAGE ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( '权限不足。', 'sakura-imgopt' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Start a fresh scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function scan( WP_REST_Request $request ) {
		Url_Rewrite_Repository::maybe_install();

		$service = new Url_Rewrite_Service();

		return self::respond(
			$service->start(
				self::text( $request, 'from' ),
				self::text( $request, 'to' ),
				Url_Rewrite_Scope::current()->path_prefix()
			)
		);
	}

	/**
	 * Continue the scan for one time box.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function scan_step() {
		$service = new Url_Rewrite_Service();

		return self::respond( $service->scan_step() );
	}

	/**
	 * Run one rewrite batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run( WP_REST_Request $request ) {
		Url_Rewrite_Repository::maybe_install();

		$service = new Url_Rewrite_Service();

		return self::respond(
			$service->run_step(
				self::indexes( $request->get_param( 'tasks' ) ),
				(int) $request->get_param( 'batch' )
			)
		);
	}

	/**
	 * Sample rows for one task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$service = new Url_Rewrite_Service();

		return self::respond( $service->preview( (int) $request->get_param( 'index' ) ) );
	}

	/**
	 * Roll back one ledger batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revert( WP_REST_Request $request ) {
		Url_Rewrite_Repository::maybe_install();

		$service = new Url_Rewrite_Service();

		return self::respond(
			$service->revert_step(
				sanitize_text_field( (string) $request->get_param( 'runId' ) ),
				(int) $request->get_param( 'after' ),
				(int) $request->get_param( 'batch' )
			)
		);
	}

	/**
	 * Drop the scan state; the ledger stays for rollback.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reset() {
		Url_Rewrite_Repository::maybe_install();

		$service = new Url_Rewrite_Service();

		return self::respond( $service->reset() );
	}

	/**
	 * Register one POST action below the rewrite base.
	 *
	 * @param string                            $path     Relative path.
	 * @param string                            $callback Callback method.
	 * @param array<string,array<string,mixed>> $args     Request arguments.
	 */
	private static function add( string $path, string $callback, array $args = array() ): void {
		register_rest_route(
			self::NS,
			self::BASE . '/' . $path,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, $callback ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Send a service result or turn its WP_Error into a REST error.
	 *
	 * @param array<string,mixed>|WP_Error $result Result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				(string) $result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Scalar string parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Field.
	 * @return string
	 */
	private static function text( WP_REST_Request $request, string $key ): string {
		return sanitize_text_field( (string) $request->get_param( $key ) );
	}

	/**
	 * Task indexes from an array or a comma separated string.
	 *
	 * @param mixed $raw Raw parameter.
	 * @return array<int,int>
	 */
	private static function indexes( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		$out = array();

		foreach ( (array) $raw as $index ) {
			$out[] = (int) $index;
		}

		return $out;
	}
}
