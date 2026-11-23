<?php
/**
 * REST surface for the system settings island.
 *
 * Only two routes: the read the island refreshes with, and the write that
 * replaces options.php for this one card. Everything else on the screen is
 * read-only and still rendered by PHP.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\System_Layout_Settings;
use Sakura\ImageOptimizer\Admin\System_Payload;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `/system` and `/system/layout`.
 */
final class System_Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Registers both routes. Called from rest_api_init.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/system',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/system/layout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_layout' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => self::layout_args(),
			)
		);
	}

	/**
	 * Read the whole island payload.
	 *
	 * @return WP_REST_Response
	 */
	public static function read(): WP_REST_Response {
		return rest_ensure_response( System_Payload::build() );
	}

	/**
	 * Merge the requested layout values onto the stored ones, clamp, save.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_layout( WP_REST_Request $request ): WP_REST_Response {
		$input = array();

		foreach ( array_keys( self::layout_args() ) as $key ) {
			$value = $request->get_param( $key );

			if ( null !== $value && '' !== $value ) {
				$input[ $key ] = $value;
			}
		}

		$state = System_Layout_Settings::merge( $input, System_Layout_Settings::current() );
		$state = System_Layout_Settings::save( $state );

		return rest_ensure_response(
			array(
				'layout' => $state,
				'notice' => System_Payload::saved_notice(),
			)
		);
	}

	/**
	 * Only people who could edit the options may touch the route.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Arguments shared by the route schema and the merge loop.
	 *
	 * Numbers are sanitized to integers here; the range clamps live in
	 * System_Layout_Settings so the same rules apply to both transports.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function layout_args(): array {
		$args = array();

		foreach ( array( 'gapX', 'gapY', 'page_width', 'border_width', 'radius' ) as $key ) {
			$args[ $key ] = array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value );
				},
			);
		}

		$args['shadow'] = array(
			'type'              => 'string',
			'required'          => false,
			'enum'              => array( 'default', 'none', 'soft', 'strong' ),
			'sanitize_callback' => 'sanitize_key',
		);

		return $args;
	}
}
