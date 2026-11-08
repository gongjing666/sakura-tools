<?php
/**
 * Generic template provider.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail\Providers;

use Sakura\ImageOptimizer\Thumbnail\Provider;
use Sakura\ImageOptimizer\Thumbnail\Rendition;

defined( 'ABSPATH' ) || exit;

/**
 * Expands a user supplied suffix template.
 *
 * Supported placeholders: {url}, {w}, {h}, {mode}, {pos}, {q}, {format},
 * {style}. When the template contains `{url}` it is treated as a complete
 * URL template; otherwise the expanded text is appended to the original URL.
 */
final class Generic implements Provider {

	/**
	 * Suffix / URL template.
	 *
	 * @var string
	 */
	private string $template;

	/**
	 * Constructor.
	 *
	 * @param string $template Template.
	 */
	public function __construct( string $template = '' ) {
		$this->template = trim( $template );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return '' === $this->template ? 'none' : 'generic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		$on = '' !== $this->template;

		return array(
			'resize'  => $on,
			'crop'    => $on,
			'format'  => $on,
			'quality' => $on,
			'style'   => $on,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $capability Capability name.
	 */
	public function supports( string $capability ): bool {
		$all = $this->capabilities();

		return ! empty( $all[ $capability ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string    $url       Original URL.
	 * @param Rendition $rendition Target rendition.
	 */
	public function decorate( string $url, Rendition $rendition ): string {
		if ( '' === $this->template ) {
			return $url;
		}

		if ( false !== strpos( $this->template, '{w}' ) && $rendition->width <= 0 ) {
			return $url;
		}
		if ( false !== strpos( $this->template, '{h}' ) && $rendition->height <= 0 ) {
			return $url;
		}
		if ( false !== strpos( $this->template, '{style}' ) && '' === $rendition->style ) {
			return $url;
		}

		$replacements = array(
			'{url}'    => $url,
			'{w}'      => (string) $rendition->width,
			'{h}'      => (string) $rendition->height,
			'{mode}'   => $rendition->mode,
			'{pos}'    => $rendition->position,
			'{q}'      => (string) $rendition->quality,
			'{format}' => $rendition->format,
			'{style}'  => $rendition->style,
		);

		$expanded = strtr( $this->template, $replacements );
		if ( false !== strpos( $this->template, '{url}' ) ) {
			return esc_url_raw( $expanded );
		}

		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		if ( str_starts_with( $expanded, '?' ) || str_starts_with( $expanded, '&' ) ) {
			if ( false !== strpos( $url, '?' ) ) {
				$expanded = ltrim( $expanded, '?&' );
				$url     .= '&' . $expanded;
			} else {
				$url .= '?' . ltrim( $expanded, '?&' );
			}
		} else {
			$url .= $expanded;
		}

		return esc_url_raw( $url . $fragment );
	}
}
