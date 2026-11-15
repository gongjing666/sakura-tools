<?php
/**
 * Which storage carrier owns which URL syntax.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

use Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful;
use Sakura\ImageOptimizer\Thumbnail\Providers\Doge;
use Sakura\ImageOptimizer\Thumbnail\Providers\Generic;

defined( 'ABSPATH' ) || exit;

/**
 * Maps an S3 provider id to the class that knows its image syntax.
 *
 * Keeping the table here means adding a carrier is one entry plus one
 * provider class, instead of editing every `'bitiful' === $provider`
 * comparison that used to be scattered across the probe, the mode resolver
 * and the decorator.
 */
final class Provider_Registry {

	/**
	 * Providers with a built-in URL syntax.
	 *
	 * The value lists the modes the class can decorate: `style` (named rule),
	 * `params` (resize parameters) or both.
	 *
	 * @var array<string,array{class:class-string<Provider>,modes:array<int,string>}>
	 */
	private const NATIVE = array(
		'bitiful'   => array(
			'class' => Bitiful::class,
			'modes' => array( 'style', 'params' ),
		),
		'dogecloud' => array(
			'class' => Doge::class,
			'modes' => array( 'style', 'params' ),
		),
	);

	/**
	 * Whether a provider has its own syntax for a mode.
	 *
	 * @param string $provider S3 provider id.
	 * @param string $mode     style|params.
	 */
	public static function supports( string $provider, string $mode ): bool {
		$entry = self::NATIVE[ $provider ] ?? null;

		return null !== $entry && in_array( $mode, $entry['modes'], true );
	}

	/**
	 * Build the provider for a supported carrier.
	 *
	 * @param string $provider S3 provider id.
	 * @param string $mode     Effective mode.
	 * @return Provider|null Null when the carrier needs the generic template.
	 */
	public static function make( string $provider, string $mode ): ?Provider {
		if ( ! self::supports( $provider, 'style' ) && ! self::supports( $provider, 'params' ) ) {
			return null;
		}

		$class = self::NATIVE[ $provider ]['class'];

		return new $class( $mode );
	}

	/**
	 * Provider ids that can rewrite URLs without a custom template.
	 *
	 * @return array<int,string>
	 */
	public static function names(): array {
		return array_keys( self::NATIVE );
	}
}
