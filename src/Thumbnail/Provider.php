<?php
/**
 * Thumbnail provider contract.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a rendition into a provider-specific image URL.
 *
 * Capability probing is intentionally a separate service (`Probe`) because
 * providers share the same HTTP evidence collection and the result is cached
 * globally, not per provider instance.
 */
interface Provider {

	/**
	 * Stable provider identifier.
	 */
	public function id(): string;

	/**
	 * Declared capabilities.
	 *
	 * @return array<string,bool>
	 */
	public function capabilities(): array;

	/**
	 * Whether a capability is supported.
	 *
	 * @param string $capability resize|crop|format|quality|style.
	 */
	public function supports( string $capability ): bool;

	/**
	 * Decorate a URL. Returns the input unchanged when it cannot be handled.
	 *
	 * @param string    $url       Original URL.
	 * @param Rendition $rendition Target rendition.
	 */
	public function decorate( string $url, Rendition $rendition ): string;
}
