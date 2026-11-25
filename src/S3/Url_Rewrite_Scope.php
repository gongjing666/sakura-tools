<?php
/**
 * Attachment URL scope for the database rewrite screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the path prefix that turns host matching into attachment matching.
 *
 * The active profile's public URL base (upload_url_path) is the first choice:
 * that is the path a browser actually sees in the database. When it has no
 * path component, the object-key prefix (upload_path) is used instead. A full
 * URL is accepted by both accessors, so callers never have to parse it twice.
 */
final class Url_Rewrite_Scope {

	/**
	 * Prefix came from the public upload URL path.
	 */
	public const SOURCE_URL = 'upload_url_path';

	/**
	 * Prefix came from the object-key upload path.
	 */
	public const SOURCE_PATH = 'upload_path';

	/**
	 * Normalized path prefix, without leading or trailing slashes.
	 *
	 * @var string
	 */
	private string $path_prefix;

	/**
	 * Which setting supplied the prefix.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Raw upload_url_path, for display only.
	 *
	 * @var string
	 */
	private string $url_base;

	/**
	 * Constructor.
	 *
	 * @param string $path_prefix Normalized prefix.
	 * @param string $source      SOURCE_URL or SOURCE_PATH.
	 * @param string $url_base    Raw public URL base.
	 */
	private function __construct( string $path_prefix, string $source, string $url_base ) {
		$this->path_prefix = Link_Rewriter::normalize_path_prefix( $path_prefix );
		$this->source      = self::SOURCE_URL === $source ? self::SOURCE_URL : self::SOURCE_PATH;
		$this->url_base    = $url_base;
	}

	/**
	 * Resolve the scope from the current storage options.
	 *
	 * @param Options|null $options Options snapshot, or null to read the active one.
	 */
	public static function current( ?Options $options = null ): self {
		$options = $options ?? new Options();
		$url     = (string) $options->get( 'upload_url_path', '' );

		if ( '' === $url ) {
			$active = $options->active_profile_id();

			if ( '' !== $active ) {
				foreach ( $options->saved_profiles() as $profile ) {
					if ( (string) ( $profile['id'] ?? '' ) === $active ) {
						$url = (string) ( $profile['upload_url_path'] ?? '' );
						break;
					}
				}
			}
		}

		return self::from_values( $url, $options->upload_path() );
	}

	/**
	 * Resolve from two raw settings values; kept public for focused tests.
	 *
	 * @param string $url_base    Public upload URL base.
	 * @param string $upload_path Object-key prefix.
	 */
	public static function from_values( string $url_base, string $upload_path ): self {
		$url_base = trim( $url_base );
		$path     = '';

		if ( '' !== $url_base ) {
			$parsed = parse_url( $url_base );
			$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? (string) $parsed['path'] : '';
		}

		if ( '' === trim( str_replace( '\\', '/', $path ), '/' ) ) {
			return new self( $upload_path, self::SOURCE_PATH, $url_base );
		}

		return new self( $path, self::SOURCE_URL, $url_base );
	}

	/**
	 * Normalized path prefix.
	 */
	public function path_prefix(): string {
		return $this->path_prefix;
	}

	/**
	 * Which setting supplied the prefix.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Raw public URL base, for display only.
	 */
	public function url_base(): string {
		return $this->url_base;
	}

	/**
	 * Human-readable path for the fallback view and the bootstrap payload.
	 */
	public function display_path(): string {
		return '' === $this->path_prefix ? '/' : '/' . $this->path_prefix . '/';
	}

	/**
	 * Data shape shared by the PHP view and the React payload.
	 *
	 * @return array{pathPrefix:string,displayPath:string,source:string,urlBase:string}
	 */
	public function payload(): array {
		return array(
			'pathPrefix'  => $this->path_prefix,
			'displayPath' => $this->display_path(),
			'source'      => $this->source,
			'urlBase'     => $this->url_base,
		);
	}
}
