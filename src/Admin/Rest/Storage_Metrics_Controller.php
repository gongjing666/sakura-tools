<?php
/**
 * REST endpoint behind the upload throughput card.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\Rest;

use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Storage_Metrics_Card;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the same array Storage_Metrics_Card::build() hands the first paint.
 *
 * The old panel refreshed through admin-ajax with its own action and nonce, and
 * answered with a second copy of the read model. The island asks this route
 * instead, and the body is the card itself, so a poll and a reload cannot drift
 * apart. Read-only on purpose: the old screen had no way to reset the counters
 * (Upload_Metrics::reset() is documented as a manual/test helper with no button),
 * so this route does not invent one.
 */
final class Storage_Metrics_Controller {

	/**
	 * REST namespace shared by every admin route of the plugin.
	 */
	public const NS = 'sakura-tools/v1';

	/**
	 * Route of the card.
	 */
	public const ROUTE = '/storage/metrics';

	/**
	 * Absolute URL of the route, for the bootstrap payload.
	 */
	public static function url(): string {
		return rest_url( self::NS . self::ROUTE );
	}

	/**
	 * Register the route.
	 */
	public static function register(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);
	}

	/**
	 * Capability gate: the card lives on a page only administrators can open.
	 */
	public static function can_manage(): bool {
		return App_Manifest::can_view( 'storage-connection' );
	}

	/**
	 * Answer with a freshly built card.
	 *
	 * @return WP_REST_Response
	 */
	public static function read(): WP_REST_Response {
		$section = Storage_Metrics_Card::build();
		$updated = is_array( $section['updated'] ?? null ) ? $section['updated'] : array();

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'section'    => $section,
				'updated_at' => (int) ( $updated['at'] ?? 0 ),
			),
			200,
			array(
				// The numbers move every upload; a cached copy would be a lie.
				'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
			)
		);
	}
}
