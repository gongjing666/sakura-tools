<?php
/**
 * Exception carrying a WP_Error out of the media mover.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a move fails; the original WP_Error travels with it.
 */
final class Media_Move_Failure extends \RuntimeException {

	/** @var WP_Error */
	public WP_Error $error;

	/**
	 * @param WP_Error $error Failure detail.
	 */
	public function __construct( WP_Error $error ) {
		parent::__construct( $error->get_error_message() );
		$this->error = $error;
	}
}
