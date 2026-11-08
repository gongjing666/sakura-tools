<?php
/**
 * Repairs stale zibll post-thumbnail cache entries without editing the theme.
 *
 * The theme caches the final thumbnail URL per post ID + size, before the
 * plugin's image filter runs. When a thumbnail was first requested in a
 * disabled context, that cache can contain a bare original URL and later be
 * reused by an enabled sidebar/archive render. This guard upgrades only those
 * stale entries in place, before the theme reads them.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Guards the theme's post_thumbnail_url_* cache.
 */
final class Theme_Cache {

	/**
	 * Repair stale bare URLs for the post about to be rendered.
	 *
	 * Hooked to `the_post` at priority 1 so it runs before the theme asks for
	 * `zib_post_thumbnail()`.
	 *
	 * @param mixed $post Post object or ID.
	 */
	public static function guard( $post ): void {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		if ( $post_id <= 0 ) {
			return;
		}

		$options = Settings::get();
		if ( 'url' !== ( $options['engine'] ?? '' ) ) {
			return;
		}

		$mode = Settings::effective_mode();
		if ( ! in_array( $mode, array( 'style', 'params' ), true ) ) {
			return;
		}

		$context = Context::current();
		if ( ! Context::enabled( (array) ( $options['scopes'] ?? array() ), $context ) ) {
			return;
		}

		$host = Settings::media_host();
		if ( '' === $host ) {
			return;
		}

		$policy = new Policy( $options );

		foreach ( array_keys( (array) ( $options['sizes'] ?? array() ) ) as $size ) {
			$size  = (string) $size;
			$group = 'post_thumbnail_url_' . $size;
			$url   = wp_cache_get( $post_id, $group );
			if ( ! is_string( $url ) || '' === $url || 'no' === $url ) {
				continue;
			}

			// Expect the rendition for the current context and scene, not just
			// "any optimizer syntax": a cached large URL must be replaced when
			// the sidebar scene points at a smaller size.
			$rendition = $policy->resolve( $context, $size, 0, 0, 0, $mode );
			if ( null === $rendition || self::is_current( $url, $rendition, $mode, $host ) ) {
				continue;
			}

			$upgraded = self::upgrade( $url, $size, $context, $mode, $options, $policy );
			if ( '' !== $upgraded && $upgraded !== $url ) {
				wp_cache_set( $post_id, $upgraded, $group );
			}
		}
	}

	/**
	 * Build the current optimized URL for a cached bare URL.
	 *
	 * @param string              $url     Cached URL.
	 * @param string              $size    Size name.
	 * @param string              $context Current scope.
	 * @param string              $mode    Current provider mode.
	 * @param array<string,mixed> $options Plugin options.
	 * @param Policy              $policy  Policy instance.
	 */
	private static function upgrade( string $url, string $size, string $context, string $mode, array $options, Policy $policy ): string {
		$bare          = self::strip_optimizer( $url );
		$attachment_id = function_exists( 'attachment_url_to_postid' ) && '' !== $bare ? (int) attachment_url_to_postid( $bare ) : 0;
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		$width    = is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$height   = is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
		if ( $width <= 0 || $height <= 0 ) {
			return '';
		}

		$rendition = $policy->resolve( $context, $size, $width, $height, 0, $mode );
		if ( null === $rendition ) {
			return '';
		}

		$provider = Settings::make_provider();
		$upgraded = $provider->decorate( $bare, $rendition );

		return $upgraded !== $url ? $upgraded : '';
	}

	/**
	 * Whether a cached URL already matches the expected rendition.
	 *
	 * A bare URL, a URL carrying another style, or a URL with different size
	 * parameters is not current. The comparison is exact so that changing a
	 * scene target replaces the cached entry instead of keeping the old one.
	 *
	 * @param string    $url       Cached URL.
	 * @param Rendition $rendition Expected rendition.
	 * @param string    $mode      Current provider mode.
	 * @param string    $host      Media host.
	 */
	private static function is_current( string $url, Rendition $rendition, string $mode, string $host ): bool {
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $url_host ) || strtolower( $url_host ) !== strtolower( $host ) ) {
			return true; // Not ours: never churn external/other-host cache entries.
		}

		if ( 'style' === $mode ) {
			if ( '' === $rendition->style ) {
				return true; // No style for this size: leave the theme's URL alone.
			}
			if ( false === strpos( $url, '!style=' . rawurlencode( $rendition->style ) ) ) {
				return false;
			}
			// A leftover width/quality query from a previous params run would be
			// applied on top of the style, so the upgrade has to strip it.
			return ! self::has_optimizer_query( $url );
		}

		if ( 'pad' === $rendition->mode ) {
			return true; // The provider leaves padded URLs untouched.
		}
		if ( false !== strpos( $url, '!style=' ) ) {
			return false; // A leftover style would be applied on top of the params.
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}
		parse_str( $query, $args );

		if ( $rendition->width > 0 && (int) ( $args['w'] ?? 0 ) !== $rendition->width ) {
			return false;
		}
		if ( $rendition->height > 0 && (int) ( $args['h'] ?? 0 ) !== $rendition->height ) {
			return false;
		}
		if ( $rendition->width > 0 && $rendition->height > 0 && isset( $args['mode'] ) && (string) $args['mode'] !== $rendition->mode ) {
			return false;
		}
		if ( $rendition->quality > 0 && (int) ( $args['q'] ?? 0 ) !== $rendition->quality ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a URL carries size parameters left over from the params mode.
	 *
	 * @param string $url Cached URL.
	 */
	private static function has_optimizer_query( string $url ): bool {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}
		parse_str( $query, $args );
		foreach ( array( 'w', 'h', 'mode', 'pos', 'q', 'format' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove this plugin's own URL syntax before resolving the attachment.
	 *
	 * Cache entries can already carry `!style=` or `?w=`; attachment_url_to_postid()
	 * does not understand them, and appending a second style to one URL would
	 * leave two style segments in the path.
	 *
	 * @param string $url Cached URL.
	 */
	private static function strip_optimizer( string $url ): string {
		$url = (string) preg_replace( '/!style=[A-Za-z0-9%._~-]*/', '', $url );

		$mark = strpos( $url, '?' );
		if ( false === $mark ) {
			return $url;
		}

		$base = substr( $url, 0, $mark );
		$args = array();
		parse_str( substr( $url, $mark + 1 ), $args );
		foreach ( array( 'w', 'h', 'mode', 'pos', 'q', 'format' ) as $key ) {
			unset( $args[ $key ] );
		}

		return array() === $args ? $base : $base . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}
}
