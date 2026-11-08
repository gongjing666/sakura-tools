<?php
/**
 * Immutable thumbnail rendition value object.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one target image rendition.
 *
 * The mode is provider-neutral: `fit` preserves the aspect ratio, `crop`
 * fills the requested box and `pad` letterboxes. Providers translate it to
 * their own URL syntax.
 */
final class Rendition {

	public const MODE_FIT  = 'fit';
	public const MODE_CROP = 'crop';
	public const MODE_PAD  = 'pad';

	/**
	 * Allowed modes.
	 *
	 * @var array<int,string>
	 */
	public const MODES = array( self::MODE_FIT, self::MODE_CROP, self::MODE_PAD );

	/**
	 * Allowed crop positions.
	 *
	 * @var array<int,string>
	 */
	public const POSITIONS = array( 'auto', 'center', 'top', 'bottom', 'left', 'right' );

	/**
	 * Allowed formats.
	 *
	 * @var array<int,string>
	 */
	public const FORMATS = array( 'auto', 'webp', 'avif', 'original' );

	/**
	 * Constructor.
	 *
	 * @param int    $width    Target width, 0 means unconstrained.
	 * @param int    $height   Target height, 0 means unconstrained.
	 * @param string $mode     fit|crop|pad.
	 * @param string $position Crop position.
	 * @param int    $quality  Quality 0-100, 0 means provider default.
	 * @param string $format   auto|webp|avif|original.
	 * @param string $style    Optional named style.
	 */
	public function __construct(
		public readonly int $width = 0,
		public readonly int $height = 0,
		public readonly string $mode = self::MODE_FIT,
		public readonly string $position = 'auto',
		public readonly int $quality = 0,
		public readonly string $format = 'auto',
		public readonly string $style = ''
	) {}

	/**
	 * Build a rendition from a normalized settings row.
	 *
	 * @param array<string,mixed> $data Raw row.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$mode = isset( $data['mode'] ) && is_string( $data['mode'] ) ? strtolower( $data['mode'] ) : self::MODE_FIT;
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = self::MODE_FIT;
		}

		$position = isset( $data['position'] ) && is_string( $data['position'] ) ? strtolower( $data['position'] ) : 'auto';
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			$position = 'auto';
		}

		$format = isset( $data['format'] ) && is_string( $data['format'] ) ? strtolower( $data['format'] ) : 'auto';
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			$format = 'auto';
		}

		$style = isset( $data['style'] ) && is_string( $data['style'] ) ? $data['style'] : '';
		$style = self::sanitize_style( $style );

		$quality = isset( $data['quality'] ) ? (int) $data['quality'] : 0;
		$quality = max( 0, min( 100, $quality ) );

		return new self(
			max( 0, (int) ( $data['width'] ?? 0 ) ),
			max( 0, (int) ( $data['height'] ?? 0 ) ),
			$mode,
			$position,
			$quality,
			$format,
			$style
		);
	}

	/**
	 * Validate a named style (Bitiful allows 1-36 alphanumeric characters).
	 *
	 * @param string $style Candidate.
	 * @return string Empty when invalid.
	 */
	public static function sanitize_style( string $style ): string {
		$style = trim( $style );
		if ( '' === $style || strlen( $style ) > 36 ) {
			return '';
		}

		return 1 === preg_match( '/^[A-Za-z0-9]+$/', $style ) ? $style : '';
	}

	/**
	 * Whether the rendition constrains at least one dimension.
	 */
	public function has_size(): bool {
		return $this->width > 0 || $this->height > 0;
	}

	/**
	 * Whether the rendition is usable at all.
	 */
	public function is_usable(): bool {
		return $this->has_size() || '' !== $this->style;
	}

	/**
	 * Return a copy with a named style.
	 *
	 * @param string $style Style name.
	 */
	public function with_style( string $style ): self {
		return new self(
			$this->width,
			$this->height,
			$this->mode,
			$this->position,
			$this->quality,
			$this->format,
			self::sanitize_style( $style )
		);
	}

	/**
	 * Stable key for request memoization.
	 */
	public function key(): string {
		return implode( ':', array( $this->width, $this->height, $this->mode, $this->position, $this->quality, $this->format, $this->style ) );
	}

	/**
	 * Serialize back to an array.
	 *
	 * @return array<string,int|string>
	 */
	public function to_array(): array {
		return array(
			'width'    => $this->width,
			'height'   => $this->height,
			'mode'     => $this->mode,
			'position' => $this->position,
			'quality'  => $this->quality,
			'format'   => $this->format,
			'style'    => $this->style,
		);
	}
}
