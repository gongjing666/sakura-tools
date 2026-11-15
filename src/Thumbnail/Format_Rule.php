<?php
/**
 * Which attachment formats the image pipeline may rewrite.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Format gate shared by the front-end decorator and the media library.
 *
 * Both rewrite paths have to agree on this rule. An SVG has no raster
 * pipeline behind it (DogeCloud answers 400 on a styled SVG path), and
 * turning a GIF into a WebP freezes the animation. Keeping one copy of the
 * rule is what stops the front end and wp-admin from drifting apart.
 */
final class Format_Rule {

	/**
	 * Whether a URL path may carry the cloud rendition syntax.
	 *
	 * @param string              $path    URL path or file path.
	 * @param array<string,mixed> $options Resolved thumbnail options.
	 */
	public static function allows( string $path, array $options ): bool {
		if ( preg_match( '/\.svg$/i', $path ) ) {
			return false;
		}

		if ( preg_match( '/\.gif$/i', $path ) && empty( $options['advanced']['animated'] ) ) {
			return false;
		}

		return true;
	}
}
