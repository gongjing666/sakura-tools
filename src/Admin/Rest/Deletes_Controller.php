<?php
/**
 * REST endpoints for the delete-queue island.
 *
 * The screen polls the snapshot route and posts row actions back as JSON, so
 * the page never reloads. Export stays on its admin-post handler: the REST
 * server encodes every response as JSON, which would wrap a CSV in quotes.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Deletes_Payload;
use Sakura\ImageOptimizer\S3\Delete_Jobs;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the delete-queue routes.
 */
final class Deletes_Controller {

	/**
	 * Route namespace shared by every island.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Permission callback: the same tab gate as the page.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return App_Manifest::can_view( 'storage-deletes' );
	}

	/**
	 * Register every route this controller owns.
	 */
	public static function register(): void {
		$args = self::query_args();

		register_rest_route(
			self::NS,
			'/storage/deletes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			self::NS,
			'/storage/deletes/dispatch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'dispatch' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/storage/deletes/(?P<action>retry|drop)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'mutate' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array_merge(
					$args,
					array(
						'action' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'retry', 'drop' ),
						),
					)
				),
			)
		);
	}

	/**
	 * One live snapshot for the poller.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ): WP_REST_Response {
		$payload = new Deletes_Payload();

		return rest_ensure_response( $payload->snapshot( self::state( $request ), self::page( $request ), self::per_page( $request ) ) );
	}

	/**
	 * Retry or drop the selected rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Save the batch dispatch policy and return the refreshed slice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$input = $request->get_json_params();

		if ( ! is_array( $input ) || array() === $input ) {
			$input = (array) $request->get_params();
		}

		$policy  = \Sakura\ImageOptimizer\S3\Delete_Batch::save( $input );
		$payload = new Deletes_Payload();
		$snap    = $payload->snapshot( 'all', 1, \Sakura\ImageOptimizer\S3\Delete_Batch::read()['batch_size'] );

		return rest_ensure_response(
			array(
				'policy' => $policy,
				'queued' => $snap['dispatch']['queued'] ?? array(),
			)
		);
	}

	public static function mutate( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = self::ids( $request );

		if ( array() === $ids ) {
			return new WP_Error(
				'sakura_deletes_none',
				__( '请先勾选要处理的队列记录。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$jobs     = new Delete_Jobs();
		$affected = 'retry' === $action ? $jobs->retry_ids( $ids ) : $jobs->drop_ids( $ids );
		$payload  = new Deletes_Payload( $jobs );
		$snapshot = $payload->snapshot( self::state( $request ), self::page( $request ), self::per_page( $request ) );

		if ( 'retry' === $action ) {
			$message = $affected > 0
				? sprintf( /* translators: %s: number of rows. */ __( '已重排 %s 个任务。', 'sakura-imgopt' ), number_format_i18n( $affected ) )
				: __( '选中的记录都不能重排：只有排队中、失败或已放弃的记录可以重试。', 'sakura-imgopt' );
		} else {
			$message = $affected > 0
				? sprintf( /* translators: %s: number of rows. */ __( '已删除 %s 行队列记录。', 'sakura-imgopt' ), number_format_i18n( $affected ) )
				: __( '没有可删除的记录。', 'sakura-imgopt' );
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'tone'     => $affected > 0 ? 'ok' : 'warn',
				'affected' => $affected,
				'message'  => $message,
				'snapshot' => $snapshot,
			)
		);
	}

	/**
	 * Query args accepted by both routes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function query_args(): array {
		return array(
			'state'    => array(
				'type'    => 'string',
				'default' => 'all',
				'enum'    => array( 'all', 'queued', 'running', 'done', 'dead', 'failed' ),
			),
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => Deletes_Payload::PER_PAGE,
				'enum'    => array( 25, 50, 100 ),
			),
		);
	}

	/**
	 * State filter from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function state( WP_REST_Request $request ): string {
		$state = sanitize_key( (string) $request->get_param( 'state' ) );

		return in_array( $state, array( 'queued', 'running', 'done', 'dead', 'failed' ), true ) ? $state : 'all';
	}

	/**
	 * Page number from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function page( WP_REST_Request $request ): int {
		return max( 1, (int) $request->get_param( 'page' ) );
	}

	/**
	 * Rows per page from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function per_page( WP_REST_Request $request ): int {
		$per_page = (int) $request->get_param( 'per_page' );

		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : Deletes_Payload::PER_PAGE;
	}

	/**
	 * Positive, de-duplicated ids from a JSON array or a comma-separated string.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int,int>
	 */
	private static function ids( WP_REST_Request $request ): array {
		$raw = $request->get_param( 'ids' );

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $id ) {
			$id = absint( $id );

			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}

			if ( count( $out ) >= 200 ) {
				break;
			}
		}

		return array_values( $out );
	}
}
