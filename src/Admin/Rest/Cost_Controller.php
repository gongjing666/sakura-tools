<?php
/**
 * REST endpoint for the cloud cost estimator.
 *
 * The calculator is stateless: every request carries the whole demand and the
 * response is the same estimate the bootstrap payload prints.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\S3\Cost_Estimator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and answers the cost route.
 */
final class Cost_Controller {

	/**
	 * Route namespace shared by every island.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Route id of the screen this action belongs to.
	 */
	public const PAGE = 'storage-cost';

	/**
	 * Register every route this controller owns.
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			'/storage/cost/estimate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'estimate' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission callback: manage_options plus the same tab gate as the page.
	 *
	 * @return true|WP_Error
	 */
	public static function can_manage() {
		if ( ! current_user_can( 'manage_options' ) || ! App_Manifest::can_view( self::PAGE ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( '权限不足。', 'sakura-imgopt' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Recalculate the estimate for a submitted demand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function estimate( WP_REST_Request $request ) {
		$input = $request->get_json_params();

		if ( ! is_array( $input ) || array() === $input ) {
			$input = (array) $request->get_params();
		}

		return rest_ensure_response(
			Cost_Estimator::estimate(
				array(
					'storage_gb' => self::float( $input, 'storage_gb' ),
					'traffic_gb' => self::float( $input, 'traffic_gb' ),
					'read_wan'   => self::float( $input, 'read_wan' ),
					'write_wan'  => self::float( $input, 'write_wan' ),
					'image_gb'   => self::float( $input, 'image_gb' ),
					'hit_rate'   => self::float( $input, 'hit_rate' ),
					'busy_rate'  => self::float( $input, 'busy_rate' ),
					'https_rate' => self::float( $input, 'https_rate' ),
					'mode'       => sanitize_text_field( (string) ( $input['mode'] ?? '' ) ),
					'fx'         => self::float( $input, 'fx' ),
				)
			)
		);
	}

	/**
	 * Read one float, zero when it is missing or not numeric.
	 *
	 * @param array<string,mixed> $input Request body.
	 * @param string              $key   Field.
	 * @return float
	 */
	private static function float( array $input, string $key ): float {
		return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (float) $input[ $key ] : 0.0;
	}
}
