<?php
/**
 * Theme upload limits: read the active theme's flood guard into the plugin and
 * write plugin edits back, so the theme never blocks an upload the plugin allows.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the theme's own upload frequency guard.
 *
 * Zibll stores its guard in `zibll_options['brush_limit_upload']` as
 * `minutes_10` / `day_1`. Another theme can plug in through the
 * `sufm_theme_upload_limits` filter and return the same shape.
 */
final class Theme_Limits {

	/**
	 * Zibll options option name.
	 */
	private const ZIBLL_OPTION = 'zibll_options';

	/**
	 * Zibll upload guard key.
	 */
	private const ZIBLL_KEY = 'brush_limit_upload';

	/**
	 * Detect the active theme guard.
	 *
	 * @return array{id:string,label:string,ten:int,day:int}|null
	 */
	public static function detect(): ?array {
		/**
		 * Let another theme adapter replace the built-in detection.
		 *
		 * Return `array( 'id', 'label', 'ten', 'day' )`, or null when the theme
		 * has no upload guard.
		 *
		 * @param array|null $detected Detected limits.
		 */
		$filtered = apply_filters( 'sufm_theme_upload_limits', null );

		// Single theme detection source: non Zibll themes never produce limits.
		if ( ! \Nboke\UserFiles\Services\Zibll_Bridge::instance()->is_zibll_theme() ) {
			return null;
		}

		if ( is_array( $filtered ) && isset( $filtered['id'], $filtered['ten'], $filtered['day'] ) ) {
			return array(
				'id'    => (string) $filtered['id'],
				'label' => (string) ( $filtered['label'] ?? $filtered['id'] ),
				'ten'   => max( 0, (int) $filtered['ten'] ),
				'day'   => max( 0, (int) $filtered['day'] ),
			);
		}

		$options = get_option( self::ZIBLL_OPTION );

		if ( ! is_array( $options ) || ! isset( $options[ self::ZIBLL_KEY ] ) || ! is_array( $options[ self::ZIBLL_KEY ] ) ) {
			return null;
		}

		$guard = $options[ self::ZIBLL_KEY ];

		return array(
			'id'    => 'zibll',
			'label' => __( '子比主题 · 上传限制', 'sakura-imgopt' ),
			'ten'   => max( 0, (int) ( $guard['minutes_10'] ?? 0 ) ),
			'day'   => max( 0, (int) ( $guard['day_1'] ?? 0 ) ),
		);
	}

	/**
	 * Write the limits back into the theme option.
	 *
	 * @param string $id  Adapter id.
	 * @param int    $ten Files allowed per 10 minutes.
	 * @param int    $day Files allowed per day.
	 * @return bool True when the theme option now holds the values.
	 */
	public static function write( string $id, int $ten, int $day ): bool {
		if ( 'zibll' !== $id ) {
			/**
			 * Write plugin limits into a third-party theme.
			 *
			 * @param bool  $handled False by default.
			 * @param int   $ten     Files per 10 minutes.
			 * @param int   $day     Files per day.
			 */
			return (bool) apply_filters( 'sufm_theme_upload_limits_write', false, $ten, $day );
		}

		$options = get_option( self::ZIBLL_OPTION );

		if ( ! is_array( $options ) ) {
			return false;
		}

		$options[ self::ZIBLL_KEY ] = array(
			'minutes_10' => (string) max( 0, $ten ),
			'day_1'      => (string) max( 0, $day ),
		);

		return (bool) update_option( self::ZIBLL_OPTION, $options, false );
	}
}
