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
	 * Field holding how many units the cached verdict stays valid.
	 */
	public const DURATION_FIELD = 'duration';

	/**
	 * Field holding the lifetime unit.
	 */
	public const UNIT_FIELD = 'unit';

	/**
	 * Upper bound for the duration field.
	 */
	public const MAX_DURATION = 9999;

	/**
	 * Default unit: the pre-existing behaviour, a verdict that never lapses on
	 * its own and only follows the license expiry recorded by the theme.
	 */
	public const DEFAULT_UNIT = 'forever';

	/**
	 * Default option value.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'license_cache'      => 0,
			self::DURATION_FIELD => 1,
			self::UNIT_FIELD     => self::DEFAULT_UNIT,
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
	 * Lifetime unit choices, in the order the select shows them.
	 *
	 * @return array<string,string>
	 */
	public static function units(): array {
		return array(
			'hour'    => __( '小时', 'sakura-imgopt' ),
			'day'     => __( '天', 'sakura-imgopt' ),
			'week'    => __( '周', 'sakura-imgopt' ),
			'month'   => __( '月', 'sakura-imgopt' ),
			'year'    => __( '年', 'sakura-imgopt' ),
			'forever' => __( '永久', 'sakura-imgopt' ),
		);
	}

	/**
	 * Stored duration, clamped to a sane range.
	 *
	 * @return int
	 */
	public static function duration(): int {
		$value = self::get();

		return self::clamp_duration( $value[ self::DURATION_FIELD ] ?? 1 );
	}

	/**
	 * Stored unit, falling back to the default when unknown.
	 *
	 * @return string
	 */
	public static function unit(): string {
		$value = self::get();

		return self::normalize_unit( (string) ( $value[ self::UNIT_FIELD ] ?? self::DEFAULT_UNIT ) );
	}

	/**
	 * How long a verified verdict stays usable, in seconds.
	 *
	 * Zero means “no lifetime limit”: the verdict then only depends on the
	 * license expiry recorded by the theme.
	 *
	 * @return int
	 */
	public static function lifetime_seconds(): int {
		return self::lifetime_for( self::duration(), self::unit() );
	}

	/**
	 * Human label of the stored lifetime, e.g. “6 小时” or “永久”.
	 *
	 * @return string
	 */
	public static function lifetime_label(): string {
		return self::label_for( self::duration(), self::unit() );
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

		$duration = self::clamp_duration( $input[ self::DURATION_FIELD ] ?? $old[ self::DURATION_FIELD ] ?? 1 );
		$unit     = self::normalize_unit( (string) ( $input[ self::UNIT_FIELD ] ?? $old[ self::UNIT_FIELD ] ?? self::DEFAULT_UNIT ) );
		$base     = array(
			'license_cache'      => 0,
			self::DURATION_FIELD => $duration,
			self::UNIT_FIELD     => $unit,
		);

		if ( empty( $input['license_cache'] ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			if ( $was_on ) {
				self::error( 'success', __( '授权缓存已关闭。所有请求恢复由子比主题自行校验。', 'sakura-imgopt' ) );
			}

			return $base;
		}

		$version = License::theme_version();

		if ( ! License::is_supported( $version ) && ! $was_on && empty( $input[ self::ACK_FIELD ] ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			self::error(
				'warning',
				/* translators: 1: current theme version, 2: supported versions. */
				sprintf( __( '当前子比版本 %1$s 未经验证（已验证：%2$s）。继续启用需要再次提交并勾选确认。', 'sakura-imgopt' ), '' === $version ? __( '未知', 'sakura-imgopt' ) : $version, License::supported_label() )
			);

			return $base;
		}

		/*
		 * A cache that still matches this environment already proves the theme
		 * accepted the license, so probing again buys nothing: skip the 33–47 ms
		 * check (and the theme's exit path) on an unrelated save. This also
		 * absorbs the second sanitisation WordPress runs for a brand-new option
		 * (update_option() sanitises, then add_option() sanitises the same value
		 * again), which would otherwise probe twice and print the notice twice.
		 *
		 * Changing the lifetime retunes the existing verdict instead of throwing
		 * it away; only shortening it below the cache's age makes the verdict
		 * stale, and then the check runs again right here.
		 */
		if ( License_Cache::is_valid() && License_Cache::retune( self::lifetime_for( $duration, $unit ) ) ) {
			Revalidate::reschedule();

			$base['license_cache'] = 1;

			return $base;
		}

		$cache = License_Cache::warm( 'admin_enable' );

		if ( is_wp_error( $cache ) ) {
			License_Cache::clear();
			Revalidate::unschedule();

			self::error( 'error', $cache->get_error_message() );

			return $base;
		}

		Revalidate::reschedule();

		$message = __( '授权验证通过，缓存已预热。前台与后台页面命中缓存时将跳过子比的 zib_init_add_action。', 'sakura-imgopt' );

		if ( 0 === self::lifetime_for( $duration, $unit ) ) {
			$message .= ' ' . __( '缓存不按时间过期，只受授权到期约束。', 'sakura-imgopt' );
		} else {
			$message .= ' ' . sprintf(
				/* translators: %s: cache lifetime label, e.g. “6 小时”. */
				__( '缓存有效期 %s，到期后先回到主题校验，复验完成后重新生效。', 'sakura-imgopt' ),
				self::label_for( $duration, $unit )
			);
		}

		if ( ! License::is_supported( $version ) ) {
			$message .= ' ' . __( '当前版本未经验证，如遇异常请关闭本功能。', 'sakura-imgopt' );
		}

		self::error( 'success', $message );

		$base['license_cache'] = 1;

		return $base;
	}

	/**
	 * Human label for a duration/unit pair.
	 *
	 * @param int    $duration Number of units.
	 * @param string $unit     Unit key.
	 * @return string
	 */
	public static function label_for( int $duration, string $unit ): string {
		$units = self::units();

		if ( ! isset( $units[ $unit ] ) || 'forever' === $unit ) {
			return $units['forever'];
		}

		/* translators: 1: number of units, 2: unit label (小时/天/周/月/年). */
		return sprintf( __( '%1$d %2$s', 'sakura-imgopt' ), $duration, $units[ $unit ] );
	}

	/**
	 * Clamp the submitted duration into 1…MAX_DURATION.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	private static function clamp_duration( $value ): int {
		$duration = absint( $value );

		if ( $duration < 1 ) {
			$duration = 1;
		}

		if ( $duration > self::MAX_DURATION ) {
			$duration = self::MAX_DURATION;
		}

		return $duration;
	}

	/**
	 * Keep only known unit keys.
	 *
	 * @param string $value Submitted unit.
	 * @return string
	 */
	private static function normalize_unit( string $value ): string {
		$unit = sanitize_key( $value );

		return array_key_exists( $unit, self::units() ) ? $unit : self::DEFAULT_UNIT;
	}

	/**
	 * Seconds for a duration/unit pair; zero for “forever”.
	 *
	 * @param int    $duration Number of units.
	 * @param string $unit     Unit key.
	 * @return int
	 */
	private static function lifetime_for( int $duration, string $unit ): int {
		switch ( $unit ) {
			case 'hour':
				$seconds = HOUR_IN_SECONDS;
				break;
			case 'day':
				$seconds = DAY_IN_SECONDS;
				break;
			case 'week':
				$seconds = 7 * DAY_IN_SECONDS;
				break;
			case 'month':
				$seconds = 30 * DAY_IN_SECONDS;
				break;
			case 'year':
				$seconds = 365 * DAY_IN_SECONDS;
				break;
			default:
				return 0;
		}

		return $seconds * $duration;
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
