<?php
/**
 * REST route that feeds the persistent shell on navigation.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /pages/{page}`.
 *
 * One route for every allowlisted screen: the reply is the page's own
 * bootstrap payload, built by the same class the first paint uses.
 */
final class Page_Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Registers the route. Called from rest_api_init.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/pages/(?P<page>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'args'                => array(
					'page' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Return one page payload.
	 *
	 * A page id that is not on the allowlist is a 404 from here, not a blank
	 * screen: the browser falls back to a full navigation, which WordPress
	 * answers with the page it actually owns.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$page    = sanitize_key( (string) $request->get_param( 'page' ) );
		$payload = App_Manifest::payload( $page );

		if ( null === $payload ) {
			return new WP_Error(
				'sakura_unknown_page',
				__( '这个页面不在后台外壳的路由表里。', 'sakura-imgopt' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Allowlist + tab gate + capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_read( WP_REST_Request $request ): bool {
		return App_Manifest::can_view( sanitize_key( (string) $request->get_param( 'page' ) ) );
	}
}
