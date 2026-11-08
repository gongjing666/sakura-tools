<?php
/**
 * wp_get_attachment_image_src filter.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

use Sakura\ImageOptimizer\Oss\Url_Rewriter;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites an attachment image URL according to the context + size policy.
 *
 * Runs late (priority 20) so other plugins that offload media to the bucket
 * have already produced the final URL. Returns the input untouched on any
 * uncertainty.
 */
final class Decorator {

	/**
	 * Shared instance so the Stats shutdown hook is only registered once.
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
	 * Provider.
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Stats collector.
	 *
	 * @var Stats
	 */
	private Stats $stats;

	/**
	 * Per-request URL memo.
	 *
	 * @var array<string,string>
	 */
	private array $memo = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $options  Options override (tests).
	 * @param Provider|null            $provider Provider override.
	 * @param Policy|null              $policy   Policy override.
	 * @param Stats|null               $stats    Stats override.
	 * @param string|null              $host     Host override.
	 */
	public function __construct( ?array $options = null, ?Provider $provider = null, ?Policy $policy = null, ?Stats $stats = null, ?string $host = null ) {
		$this->options  = null !== $options ? Settings::normalize( $options ) : Settings::get();
		$this->host     = null !== $host ? strtolower( $host ) : Settings::media_host();
		$requested      = (string) ( $this->options['provider_mode'] ?? 'auto' );
		$this->mode     = in_array( $requested, array( 'params', 'style', 'native', 'none' ), true ) ? $requested : Settings::effective_mode();
		$this->provider = $provider ?? Settings::make_provider();
		$this->policy   = $policy ?? new Policy( $this->options );
		$this->stats    = $stats ?? new Stats();
	}

	/**
	 * WordPress filter callback.
	 *
	 * @param mixed             $image         Image data array.
	 * @param int               $attachment_id Attachment ID.
	 * @param string|int[]      $size          Requested size.
	 * @param bool              $icon          Icon flag.
	 * @return mixed
	 */
	public static function filter( $image, $attachment_id = 0, $size = 'thumbnail', $icon = false ) {
		if ( ! is_array( $image ) || ! isset( $image[0] ) || ! is_string( $image[0] ) ) {
			return $image;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance->apply( $image, (int) $attachment_id, $size );
	}

	/**
	 * Reset the shared instance (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * `wp_content_img_tag` filter.
	 *
	 * @param mixed  $tag           Image tag HTML.
	 * @param mixed  $context       Content context name.
	 * @param mixed  $attachment_id Attachment ID.
	 * @return mixed
	 */
	public static function filter_content_image( $tag, $context = '', $attachment_id = 0 ) {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return $tag;
		}

		return self::shared()->apply_content_image( $tag, (int) $attachment_id );
	}

	/**
	 * Rewrite one content image tag.
	 *
	 * @param string $tag           Image tag.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function apply_content_image( string $tag, int $attachment_id ): string {
		if ( ! $this->active() || empty( $this->options['advanced']['content_images'] ) ) {
			return $tag;
		}

		if ( $this->s3_url_unavailable( $attachment_id ) ) {
			return $tag;
		}

		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $tag;
		}

		$processor = new \WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			return $tag;
		}

		$source_attr = 'src';
		$url         = (string) $processor->get_attribute( 'src' );
		$url         = $this->media_url( $url );
		if ( '' === $url ) {
			$data_src = (string) $processor->get_attribute( 'data-src' );
			$url      = $this->media_url( $data_src );
			if ( '' !== $url ) {
				$source_attr = 'data-src';
			}
		}
		if ( '' === $url ) {
			return $tag;
		}

		if ( $attachment_id <= 0 && function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = (int) attachment_url_to_postid( $url );
		}

		$dims      = $this->original_dimensions( $attachment_id, array( $url ) );
		$width     = (int) $processor->get_attribute( 'width' );
		$requested = $width > 0 ? $width : ( $dims[0] > 0 ? $dims[0] : 0 );
		$size      = $requested >= 600 || 0 === $requested ? 'large' : 'medium';
		$context   = Context::current();
		$rendition = $this->policy->resolve( $context, $size, $dims[0], $dims[1], $requested, $this->mode, true, Policy::SCENE_CONTENT );
		if ( null === $rendition ) {
			// A scene target that cannot shrink this image (or has no style) must
			// not disable optimisation: fall back to the regular size policy.
			$rendition = $this->policy->resolve( $context, $size, $dims[0], $dims[1], $requested, $this->mode, true, Policy::SCENE_NONE );
		}
		if ( null === $rendition ) {
			return $tag;
		}

		$decorated = $this->provider->decorate( $url, $rendition );
		if ( '' === $decorated || $decorated === $url ) {
			return $tag;
		}

		$processor->set_attribute( $source_attr, $decorated );
		if ( 'style' === $this->mode ) {
			// The named style is a fixed size, so a native srcset would make the
			// browser fetch an unoptimised candidate instead.
			$processor->remove_attribute( 'srcset' );
		}

		$this->record( 'rewrite', $context, 'content' );

		return $processor->get_updated_html();
	}

	/**
	 * `zib_get_spare_thumb` theme bridge filter.
	 *
	 * @param mixed $url Spare thumbnail URL.
	 * @return mixed
	 */
	public static function filter_spare_thumb( $url, bool $record = true, bool $lookup = true ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		return self::shared()->apply_spare_thumb( $url, $record, $lookup );
	}

	/**
	 * Rewrite a theme spare thumbnail URL.
	 *
	 * @param string $url Spare URL.
	 */
	public function apply_spare_thumb( string $url, bool $record = true, bool $lookup = true ): string {
		if ( ! $this->active() || empty( $this->options['advanced']['theme_bridge'] ) || ! $this->is_eligible_url( $url ) ) {
			return $url;
		}

		$attachment_id = 0;
		$dims          = array( 0, 0 );
		if ( $lookup ) {
			$attachment_id = function_exists( 'attachment_url_to_postid' ) ? (int) attachment_url_to_postid( $url ) : 0;
			$dims          = $this->original_dimensions( $attachment_id, array( $url ) );
		}

		$context   = Context::current();
		$rendition = $this->policy->resolve( $context, 'medium', $dims[0], $dims[1], 250, $this->mode, true );
		if ( null === $rendition ) {
			return $url;
		}

		$decorated = $this->provider->decorate( $url, $rendition );
		if ( '' === $decorated || $decorated === $url ) {
			return $url;
		}

		if ( $record ) {
			$this->record( 'rewrite', $context, 'spare' );
		}

		return $decorated;
	}

	/**
	 * Apply the policy to one image.
	 *
	 * @param array<int,mixed> $image Image data.
	 * @param int              $attachment_id Attachment ID.
	 * @param mixed            $size Requested size.
	 * @return array<int,mixed>
	 */
	public function apply( array $image, int $attachment_id, $size ): array {
		if ( ! $this->active() ) {
			return $image;
		}

		// An attachment whose cloud copy failed still renders from local disk.
		// Swapping its host for the media host would point at a missing object.
		if ( $this->s3_url_unavailable( $attachment_id ) ) {
			return $image;
		}

		$url = $image[0];
		if ( ! $this->is_eligible_url( $url ) ) {
			return $image;
		}

		$size_name = '';
		$requested = isset( $image[1] ) ? (int) $image[1] : 0;
		if ( is_string( $size ) ) {
			$size_name = $size;
		} elseif ( is_array( $size ) ) {
			$requested = isset( $size[0] ) ? (int) $size[0] : $requested;
		}

		$context = Context::current();
		$dims    = $this->original_dimensions( $attachment_id, $image );
		$rendition = $this->policy->resolve( $context, $size_name, $dims[0], $dims[1], $requested, $this->mode );

		if ( null === $rendition ) {
			$this->record( ! empty( $this->options['advanced']['native_fallback'] ) && $attachment_id > 0 ? 'fallback' : 'skip', $context, '' !== $size_name ? $size_name : 'unknown' );
			return $image;
		}

		$decorated = $this->provider->decorate( $url, $rendition );
		if ( $decorated === $url || '' === $decorated ) {
			$this->record( ! empty( $this->options['advanced']['native_fallback'] ) && $attachment_id > 0 ? 'fallback' : 'skip', $context, '' !== $size_name ? $size_name : 'unknown' );
			return $image;
		}

		$memo_key = $url . '|' . $rendition->key();
		if ( isset( $this->memo[ $memo_key ] ) ) {
			$image[0] = $this->memo[ $memo_key ];
			return $image;
		}

		$this->memo[ $memo_key ] = $decorated;
		$image[0]                = $decorated;
		$this->record( 'rewrite', $context, '' !== $size_name ? $size_name : 'unknown' );

		return $image;
	}

	/**
	 * Whether the URL engine can run for this request.
	 */
	private function active(): bool {
		if ( empty( $this->options['enabled'] ) || 'url' !== ( $this->options['engine'] ?? 'off' ) ) {
			return false;
		}

		if ( 'style' !== $this->mode && 'params' !== $this->mode ) {
			return false;
		}

		if ( '' === $this->host ) {
			return false;
		}

		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && empty( $this->options['advanced']['rest'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Shared instance for static WordPress filters.
	 */
	private static function shared(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Record a statistic when enabled.
	 *
	 * @param string $kind    rewrite|skip|fallback.
	 * @param string $context Context.
	 * @param string $size    Size.
	 */
	private function record( string $kind, string $context, string $size ): void {
		if ( empty( $this->options['advanced']['stats'] ) ) {
			return;
		}
		$this->stats->record( $kind, $context, $size );
	}

	/**
	 * Whether S3 sync failed for an attachment that still has a local file.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function s3_url_unavailable( int $attachment_id ): bool {
		if ( $attachment_id <= 0 || ! class_exists( '\Sakura\ImageOptimizer\S3\Sync_Status' ) ) {
			return false;
		}
		$status = ( new \Sakura\ImageOptimizer\S3\Sync_Status() )->status( $attachment_id );
		if ( ! in_array( $status, array( 'pending', 'failed', 'dead' ), true ) ) {
			return false;
		}
		$file = get_attached_file( $attachment_id );
		return is_string( $file ) && '' !== $file && is_file( $file );
	}

	/**
	 * Map an uploads URL to the media host and strip optimizer syntax.
	 *
	 * WordPress builds srcset URLs from the local uploads base URL, so an
	 * offloaded site needs the host swapped before the provider can decorate.
	 *
	 * @param string $url Candidate URL.
	 */
	private function media_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		if ( $this->is_eligible_url( $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( false === strpos( $path, '/wp-content/uploads/' ) && false === strpos( $path, '/uploads/' ) ) {
			return '';
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( $host !== $this->host ) {
			$site_host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
			if ( '' === $site_host || strtolower( rtrim( $site_host, '.' ) ) !== $host ) {
				return '';
			}

			$url = (string) preg_replace( '#^https?://' . preg_quote( $parts['host'], '#' ) . '#i', 'https://' . $this->host, $url );
		}

		$url = (string) preg_replace( '/!style=[^?]*/', '', $url );
		$url = (string) preg_replace( '/[?&](?:w|h|q|mode|pos|format)=[^&]*/', '', $url );
		$url = rtrim( str_replace( array( '?&', '&&' ), '&', $url ), '?&' );

		return $this->is_eligible_url( $url ) ? $url : '';
	}

	/**
	 * Basic URL eligibility checks.
	 *
	 * @param string $url Candidate.
	 */
	private function is_eligible_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, 'blob:' ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || strtolower( rtrim( $host, '.' ) ) !== $this->host ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/\.svg$/i', $path ) ) {
			return false;
		}
		if ( preg_match( '/\.gif$/i', $path ) && empty( $this->options['advanced']['animated'] ) ) {
			return false;
		}
		if ( preg_match( '/\.(jpe?g|png|webp|avif|bmp)$/i', $path ) === 0 && ! $this->looks_like_image_path( $path ) ) {
			return false;
		}

		if ( $this->already_optimized( $url ) || $this->is_signed( $url ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the URL already carries optimizer syntax.
	 *
	 * @param string $url URL.
	 */
	private function already_optimized( string $url ): bool {
		if ( false !== strpos( $url, '!style=' ) ) {
			return true;
		}
		if ( preg_match( '/[?&](?:w|h|q|mode|pos|format)=/', $url ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the URL carries a signed query.
	 *
	 * @param string $url URL.
	 */
	private function is_signed( string $url ): bool {
		if ( class_exists( Url_Rewriter::class ) ) {
			return Url_Rewriter::is_signed_query( $url );
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return false;
		}
		parse_str( $query, $args );

		foreach ( array( 'signature', 'sign', 'sig', 'token', 'expires', 'x-amz-signature', 'x-amz-credential', 'x-oss-signature' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Allow extension-less URLs that still look like uploads.
	 *
	 * @param string $path URL path.
	 */
	private function looks_like_image_path( string $path ): bool {
		return false !== strpos( $path, '/wp-content/uploads/' ) || false !== strpos( $path, '/uploads/' );
	}

	/**
	 * Original attachment dimensions.
	 *
	 * @param int              $attachment_id Attachment ID.
	 * @param array<int,mixed> $image         Resolved image.
	 * @return array{0:int,1:int}
	 */
	private function original_dimensions( int $attachment_id, array $image ): array {
		if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_metadata' ) ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				return array( (int) $meta['width'], (int) $meta['height'] );
			}
		}

		return array( 0, 0 );
	}
}
