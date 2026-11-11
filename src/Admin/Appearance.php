<?php
/**
 * Admin appearance settings.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Sakura\ImageOptimizer\Admin;

/**
 * Stores the shared page and card appearance used by every plugin screen.
 */
final class Appearance {

	public const OPTION = 'sakura_imgopt_ui_style';

	public const DEFAULT_WIDTH = 1480;
	public const MIN_WIDTH     = 960;
	public const MAX_WIDTH     = 1920;
	public const MAX_BORDER    = 6;
	public const MAX_RADIUS    = 40;

	public const DEFAULTS = array(
		'page_width'   => self::DEFAULT_WIDTH,
		'page_bg'      => '',
		'card_bg'      => '#ffffff',
		'border_width' => 1,
		'radius'       => 18,
		'shadow'       => 'default',
	);

	/**
	 * Current values, always normalized.
	 *
	 * @return array<string, int|string>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, self::DEFAULTS );

		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Sanitize the appearance option.
	 *
	 * @param mixed $value Raw option value.
	 * @return array<string, int|string>
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();

		$page_bg = ! empty( $value['page_bg_default'] )
			? ''
			: self::color( $value['page_bg'] ?? '', '' );

		return array(
			'page_width'   => self::clamp_int( $value['page_width'] ?? self::DEFAULT_WIDTH, self::MIN_WIDTH, self::MAX_WIDTH, self::DEFAULT_WIDTH ),
			'page_bg'      => $page_bg,
			'card_bg'      => self::color( $value['card_bg'] ?? self::DEFAULTS['card_bg'], self::DEFAULTS['card_bg'] ),
			'border_width' => self::clamp_int( $value['border_width'] ?? 1, 0, self::MAX_BORDER, 1 ),
			'radius'       => self::clamp_int( $value['radius'] ?? 18, 0, self::MAX_RADIUS, 18 ),
			'shadow'       => in_array( $value['shadow'] ?? '', array( 'none', 'soft', 'strong' ), true ) ? (string) $value['shadow'] : 'default',
		);
	}

	/**
	 * Inline CSS variables for the page root. Defaults are omitted so the
	 * stylesheet tokens stay in charge until the user changes something.
	 */
	public static function inline_style(): string {
		$values = self::get();
		$style  = array();

		if ( self::DEFAULT_WIDTH !== $values['page_width'] ) {
			$style[] = '--g-page-max:' . $values['page_width'] . 'px';
		}

		if ( self::DEFAULTS['card_bg'] !== $values['card_bg'] ) {
			$style[] = '--g-card-surface:' . $values['card_bg'];
		}

		if ( 1 !== $values['border_width'] ) {
			$style[] = '--g-card-border-width:' . $values['border_width'] . 'px';
		}

		if ( 18 !== $values['radius'] ) {
			$style[] = '--g-radius-card:' . $values['radius'] . 'px';
		}

		switch ( $values['shadow'] ) {
			case 'none':
				$style[] = '--g-shadow-card:none';
				break;
			case 'soft':
				$style[] = '--g-shadow-card:var(--g-shadow-soft)';
				break;
			case 'strong':
				$style[] = '--g-shadow-card:var(--g-shadow-pop)';
				break;
		}

		if ( '' !== $values['page_bg'] ) {
			$style[] = 'background:' . $values['page_bg'];
		}

		return implode( ';', $style );
	}

	/**
	 * @param mixed $value Raw number.
	 */
	private static function clamp_int( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Accept #RGB or #RRGGBB, normalize to lowercase; anything else falls back.
	 */
	private static function color( $value, string $fallback ): string {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}

		$value = strtolower( trim( $value ) );

		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $value ) ) {
			return $value;
		}

		return $fallback;
	}
}
