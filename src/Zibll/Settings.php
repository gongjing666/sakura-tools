<?php
/**
 * Zibll module settings.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\Zibll;

use Sakura\ImageOptimizer\Admin\Card_Form;

defined( 'ABSPATH' ) || exit;

/**
 * The single toggle, and the save path that verifies the license for real.
 *
 * Enabling is not persisted as a preference and then acted upon elsewhere: the
 * sanitize callback runs the theme's own check (one 33–47 ms call inside the
 * save request) and either writes the cache or refuses to turn the switch on.
 * A vendor toggle backed by a cached lie is worse than the 33 ms it saves, so
 * the failure path always leaves the feature off.
 */
final class Settings {

	/**
	 * Option holding the toggle.
	 */
	public const OPTION = 'sakura_imgopt_zibll';

	/**
	 * Settings group used by the options form.
	 */
	public const GROUP = 'sakura_imgopt_zibll';

	/**
	 * Card that owns the toggle, used by Card_Form.
	 */
	public const CARD = 'license-cache';

	/**
	 * Checkbox submitted only when the operator acknowledges an untested version.
	 */
	public const ACK_FIELD = 'unsupported_ack';

	/**
	 * Default option value.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'license_cache' => 0,
		);
	}

	/**
	 * Stored option merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );

		if ( ! is_array( $value ) ) {
			$value = array();
		}

		return wp_parse_args( $value, self::defaults() );
	}

	/**
	 * Whether the front-end bypass is enabled.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$value = self::get();

		return ! empty( $value['license_cache'] );
	}

	/**
	 * Sanitize the submitted toggle, verifying the license before enabling.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		// A partial form from another card must not touch this option.
		if ( ! self::owns_card( $input ) ) {
			return self::get();
		}

		$old    = self::get();
		$was_on = ! empty( $old['license_cache'] );

		if ( empty( $input['license_cache'] ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			if ( $was_on ) {
				self::error( 'success', __( '授权缓存已关闭。所有请求恢复由子比主题自行校验。', 'sakura-imgopt' ) );
			}

			return array( 'license_cache' => 0 );
		}

		$version = License::theme_version();

		/*
		 * A cache that still matches this environment already proves the theme
		 * accepted the license, so probing again buys nothing: skip the 33–47 ms
		 * check (and the theme's exit path) on an unrelated save. This also
		 * absorbs the second sanitisation WordPress runs for a brand-new option
		 * (update_option() sanitises, then add_option() sanitises the same value
		 * again), which would otherwise probe twice and print the notice twice.
		 */
		if ( License_Cache::is_valid() ) {
			Revalidate::schedule();

			return array( 'license_cache' => 1 );
		}

		if ( ! License::is_supported( $version ) && ! $was_on && empty( $input[ self::ACK_FIELD ] ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			self::error(
				'warning',
				/* translators: 1: current theme version, 2: supported versions. */
				sprintf( __( '当前子比版本 %1$s 未经验证（已验证：%2$s）。继续启用需要再次提交并勾选确认。', 'sakura-imgopt' ), '' === $version ? __( '未知', 'sakura-imgopt' ) : $version, License::supported_label() )
			);

			return array( 'license_cache' => 0 );
		}

		$cache = License_Cache::warm( 'admin_enable' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			self::error( 'error', $cache->get_error_message() );

			return array( 'license_cache' => 0 );
		}

		Revalidate::schedule();

		$message = __( '授权验证通过，缓存已预热。前台与后台页面命中缓存时将跳过子比的 zib_init_add_action。', 'sakura-imgopt' );

		if ( ! License::is_supported( $version ) ) {
			$message .= ' ' . __( '当前版本未经验证，如遇异常请关闭本功能。', 'sakura-imgopt' );
		}

		self::error( 'success', $message );

		return array( 'license_cache' => 1 );
	}

	/**
	 * Whether this submission came from the card that owns the toggle.
	 *
	 * @param array<string,mixed> $input Submitted option value.
	 * @return bool
	 */
	private static function owns_card( array $input ): bool {
		// Card_Form::option_fields() nests the partial marker inside the option
		// array, because options.php only hands the sanitize callback that array.
		if ( empty( $input[ Card_Form::PARTIAL_FIELD ] ) ) {
			return true;
		}

		$card = isset( $input[ Card_Form::CARD_FIELD ] ) ? sanitize_key( (string) $input[ Card_Form::CARD_FIELD ] ) : '';

		return self::CARD === $card;
	}

	/**
	 * Queue a settings message shown after the redirect.
	 *
	 * @param string $type    message, success, warning or error.
	 * @param string $message Message.
	 * @return void
	 */
	private static function error( string $type, string $message ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		add_settings_error( self::OPTION, 'sakura_zibll_license_cache', $message, $type );
	}
}
