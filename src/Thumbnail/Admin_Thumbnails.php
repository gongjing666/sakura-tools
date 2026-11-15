<?php
/**
 * Media library thumbnails served from the cloud image pipeline.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites media-library thumbnails to the configured cloud rendition.
 *
 * WordPress renders the media library through two unrelated paths: the grid
 * asks wp_prepare_attachment_for_js() for every size, and the list table asks
 * wp_get_attachment_image() for a 60x60 box. Both end up in image_downsize(),
 * which is why this filter covers the two views at once.
 *
 * Falling back is deliberate: when the size has no cloud rendition (no named
 * style configured, provider without built-in syntax, or a size that would
 * upscale), returning the untouched value leaves WordPress' own behaviour in
 * place — the original file. Nothing here touches local sub-sizes, because a
 * site that offloads to a bucket does not keep them.
 */
final class Admin_Thumbnails {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Resolved options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Media host.
	 *
	 * @var string
	 */
	private string $host;

	/**
	 * Effective provider mode.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Provider override.
	 *
	 * @var Provider|null
	 */
	private ?Provider $provider;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $options  Options override (tests).
	 * @param Provider|null            $provider Provider override.
	 * @param Policy|null              $policy   Policy override.
	 * @param string|null              $host     Host override.
	 */
	public function __construct( ?array $options = null, ?Provider $provider = null, ?Policy $policy = null, ?string $host = null ) {
		$this->options  = null !== $options ? Settings::normalize( $options ) : Settings::get();
		$this->host     = null !== $host ? strtolower( $host ) : Settings::media_host();
		$this->policy   = $policy ?? new Policy( $this->options );
		$this->provider = $provider;

		$requested  = (string) ( $this->options['provider_mode'] ?? 'auto' );
		$this->mode = in_array( $requested, array( 'params', 'style' ), true ) ? $requested : Settings::effective_mode();
	}

	/**
	 * image_downsize filter.
	 *
	 * @param mixed        $out           Short-circuit value from earlier filters.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested size.
	 * @return mixed
	 */
	public static function filter( $out, $attachment_id = 0, $size = 'thumbnail' ) {
		if ( false !== $out ) {
			// Another plugin already answered; its bytes are not ours to relabel.
			return $out;
		}

		if ( ! self::in_admin() ) {
			return $out;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance->apply( (int) $attachment_id, $size, $out );
	}

	/**
	 * Reset the shared instance (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Whether this request is a wp-admin request.
	 *
	 * admin-ajax.php defines WP_ADMIN, so both the grid (queried over AJAX) and
	 * the list table (rendered server-side) report true here.
	 */
	public static function in_admin(): bool {
		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * Build the rewritten image data.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested size.
	 * @param mixed        $out           Current filter value.
	 * @return mixed
	 */
	public function apply( int $attachment_id, $size, $out ) {
		if ( $attachment_id <= 0 || ! $this->active() ) {
			return $out;
		}

		if ( ! function_exists( 'wp_attachment_is_image' ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return $out;
		}

		$url = function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $attachment_id ) : '';
		if ( '' === $url || ! $this->on_media_host( $url ) ) {
			// A local URL means the object is not in the bucket yet (S3 sync
			// pending). Decorating it would point the style at a missing object.
			return $out;
		}

		// SVGs have no raster pipeline behind them (the bucket answers 400 on a
		// styled SVG path) and a GIF rendition would freeze its animation. The
		// front-end decorator enforces the same rule; see Format_Rule.
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! Format_Rule::allows( $path, $this->options ) ) {
			return $out;
		}

		$dims      = $this->original_dimensions( $attachment_id );
		$size_name = is_string( $size ) ? $size : $this->box_size_name();
		$requested = is_array( $size ) && isset( $size[0] ) ? (int) $size[0] : 0;

		// The media library is neither an archive nor a singular view, so the
		// scene mapping must not apply and the scope switches are bypassed:
		// those switches describe front-end rendering, not the admin screen.
		$rendition = $this->policy->resolve( Context::OTHER, $size_name, $dims[0], $dims[1], $requested, $this->mode, true, Policy::SCENE_NONE );
		if ( null === $rendition ) {
			return $out;
		}

		$provider  = $this->provider ?? Settings::make_provider();
		$decorated = $provider->decorate( $url, $rendition );
		if ( '' === $decorated || $decorated === $url ) {
			return $out;
		}

		$box = $this->display_size( $rendition, $size, $dims );

		// The fourth element must be truthy: wp_prepare_attachment_for_js()
		// drops any size whose intermediate flag is empty, which would leave
		// the grid without a candidate and fall back to the full file.
		return array( $decorated, $box[0], $box[1], true );
	}

	/**
	 * Width and height to report for the rewritten URL.
	 *
	 * A pixel size (the list table asks for a 60x60 box) keeps its requested
	 * box so the markup keeps its layout; the browser scales the rendition
	 * down to fit.
	 *
	 * @param Rendition          $rendition Resolved rendition.
	 * @param string|int[]       $size      Requested size.
	 * @param array{0:int,1:int} $dims      Original dimensions.
	 * @return array{0:int,1:int}
	 */
	private function display_size( Rendition $rendition, $size, array $dims ): array {
		if ( is_array( $size ) && isset( $size[0], $size[1] ) && (int) $size[0] > 0 ) {
			$w = (int) $size[0];
			$h = (int) $size[1];
			if ( $h <= 0 ) {
				$h = $dims[0] > 0 && $dims[1] > 0 ? (int) round( $w * $dims[1] / $dims[0] ) : $w;
			}
			return array( $w, max( 1, $h ) );
		}

		$w = $rendition->width > 0 ? $rendition->width : $dims[0];
		$h = $rendition->height;
		if ( $h <= 0 ) {
			$h = ( $rendition->width > 0 && $dims[0] > 0 && $dims[1] > 0 ) ? (int) round( $rendition->width * $dims[1] / $dims[0] ) : $dims[1];
		}

		return array( max( 1, $w ), max( 1, $h ) );
	}

	/**
	 * Size name to resolve for a pixel box.
	 *
	 * The list table asks for a bare 60x60 box, which carries no size name, and
	 * a named style is only reachable through a size name. Mapping the box onto
	 * the configured thumbnail keeps the style lookup working; the reported
	 * dimensions stay the requested box, so the markup does not change.
	 */
	private function box_size_name(): string {
		$sizes = isset( $this->options['sizes'] ) && is_array( $this->options['sizes'] ) ? $this->options['sizes'] : array();

		foreach ( array( 'thumbnail', 'medium', 'medium_large', 'large' ) as $candidate ) {
			if ( isset( $sizes[ $candidate ] ) && is_array( $sizes[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether the URL already points at the media host.
	 *
	 * @param string $url Attachment URL.
	 */
	private function on_media_host( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $this->host && strtolower( rtrim( $host, '.' ) ) === $this->host;
	}

	/**
	 * Whether the engine can run for this configuration.
	 */
	private function active(): bool {
		if ( empty( $this->options['enabled'] ) || 'url' !== ( $this->options['engine'] ?? 'off' ) ) {
			return false;
		}

		if ( 'style' !== $this->mode && 'params' !== $this->mode ) {
			return false;
		}

		return '' !== $this->host;
	}

	/**
	 * Original attachment dimensions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{0:int,1:int}
	 */
	private function original_dimensions( int $attachment_id ): array {
		if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
			return array( 0, 0 );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array( (int) $meta['width'], (int) $meta['height'] );
		}

		return array( 0, 0 );
	}
}
