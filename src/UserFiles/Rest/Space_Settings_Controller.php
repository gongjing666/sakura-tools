<?php
/**
 * REST surface for the Zibll-only file space cards.
 *
 * One endpoint writes every editable card on files-zibll. The card id travels
 * in the body and Space_Cards keeps only that card's fields, then returns the
 * sections the page replaces plus the prompt copy.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Rest;

use Nboke\UserFiles\Admin\Space_Cards;
use Nboke\UserFiles\Admin\Space_Payload;
use Sakura\ImageOptimizer\Admin\App_Manifest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /sufm/v1/admin/space/settings
 */
final class Space_Settings_Controller {

	/**
	 * Route namespace.
	 */
	public const NS = 'sufm/v1';

	/**
	 * Route path.
	 */
	public const PATH = '/admin/space/settings';

	/**
	 * Register the route. Called from rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NS,
			self::PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => array(
					'card' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Same gate as the page: allowlist + zibll tab environment + capability.
	 *
	 * @return bool
	 */
	public static function can_edit(): bool {
		return App_Manifest::can_view( Space_Payload::PAGE );
	}

	/**
	 * Save one card and return its fresh sections.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$card   = sanitize_key( (string) $request->get_param( 'card' ) );
		$fields = $request->get_param( 'fields' );
		$fields = is_array( $fields ) ? $fields : array();

		$result = Space_Cards::save( $card, $fields );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
