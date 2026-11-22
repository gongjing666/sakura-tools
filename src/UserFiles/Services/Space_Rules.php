<?php
/**
 * Derived space quota rules.
 *
 * Pure derivation: base quota + level bonus + VIP bonus + active grants.
 * Statics only, because the single consumption point
 * ({@see \Nboke\UserFiles\Settings::quota_for()}) is a static method.
 *
 * Time base: every sufm_* table stores UTC, therefore now() returns gmdate() and
 * all comparisons are parameterised with that same value (never NOW()).
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles\Services;

use Nboke\UserFiles\Space_Settings;

/**
 * Quota formula, grants aggregation and per-request memoisation.
 */
final class Space_Rules {

	/**
	 * Mirrors \Nboke\UserFiles\Settings::QUOTA_META (kept as a literal because that
	 * constant is not part of the public settings contract).
	 */
	private const QUOTA_META = 'sufm_quota_bytes';

	/**
	 * Highest level key we look up (matches Space_Settings::MAX_LEVEL_KEY).
	 */
	private const MAX_LEVEL_KEY = 20;

	/**
	 * Per-request memo of breakdown() results.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $memo = array();

	/**
	 * Current UTC timestamp in MySQL format.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Effective quota for a user as consumed by {@see \Nboke\UserFiles\Settings::quota_for()}.
	 *
	 * Returns the computed limit before the per-user meta override is applied: the
	 * cap / replace decision lives in quota_for() so that a disabled expansion
	 * stays byte-for-byte identical to the previous plugin version.
	 *
	 * @param int $user_id User id.
	 * @return int Bytes, never negative.
	 */
	public static function limit_for( int $user_id ): int {
		$breakdown = self::breakdown( $user_id );

		return max( 0, (int) ( $breakdown['computed'] ?? 0 ) );
	}

	/**
	 * Full derivation breakdown (front-end quota card + admin diagnostics card).
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	public static function breakdown( int $user_id ): array {
		$user_id = max( 0, $user_id );

		if ( isset( self::$memo[ $user_id ] ) ) {
			return self::$memo[ $user_id ];
		}

		$apply = Space_Settings::apply_bonuses();

		$base = 0;
		if ( class_exists( '\\Nboke\\UserFiles\\Settings' ) ) {
			$file_settings = \Nboke\UserFiles\Settings::get();
			if ( isset( $file_settings['quota_default_bytes'] ) ) {
				$base = max( 0, (int) $file_settings['quota_default_bytes'] );
			}
		}

		$level = self::user_level( $user_id );
		$vip   = self::user_vip( $user_id );

		$level_bytes = self::level_bonus( $level );
		$vip_bytes   = self::vip_bonus( $vip );
		$granted     = $apply ? self::granted_bytes( $user_id ) : 0;

		$raw      = $base + $level_bytes + $vip_bytes + $granted;
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'sufm_space_limit', $raw, $user_id ) : $raw;
		$computed = max( 0, (int) $filtered );

		$override = self::quota_override( $user_id );
		$mode     = Space_Settings::mode();
		$final    = $computed;
		$capped   = false;

		if ( null !== $override ) {
			if ( 'replace' === $mode ) {
				$final = $override;
			} elseif ( $override < $computed ) {
				$final  = $override;
				$capped = true;
			}
		}

		$breakdown = array(
			'base'          => $base,
			'level'         => $level,
			'level_bytes'   => $level_bytes,
			'vip'           => $vip,
			'vip_bytes'     => $vip_bytes,
			'granted_bytes' => $granted,
			'raw'           => $raw,
			'computed'      => $computed,
			'override'      => $override,
			'mode'          => $mode,
			'final'         => $final,
			'capped'        => $capped,
			'apply_bonuses' => $apply,
		);

		self::$memo[ $user_id ] = $breakdown;

		return $breakdown;
	}

	/**
	 * Sum of active, non-expired grants for a user.
	 *
	 * Returns 0 when the table is missing: the schema upgrade only runs on
	 * admin_init, so a front-end request may hit a database without the table.
	 *
	 * @param int $user_id User id.
	 * @return int Bytes.
	 */
	public static function granted_bytes( int $user_id ): int {
		if ( 0 >= $user_id || ! self::has_grants_table() ) {
			return 0;
		}

		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'sufm_space_grants';
		$sql   = $wpdb->prepare(
			"SELECT COALESCE(SUM(bytes),0) FROM {$table} WHERE owner_id = %d AND state = 'active' AND ( expires_at IS NULL OR expires_at > %s )",
			$user_id,
			self::now()
		);

		return max( 0, (int) $wpdb->get_var( $sql ) );
	}

	/**
	 * Bytes granted for a level key.
	 *
	 * With the rule enabled the value is derived from the theme's ladder:
	 * every `points_per_step` upgrade points buy `bytes_per_step` bytes, so a
	 * level the theme makes harder automatically grants more. Unknown levels
	 * and a zero threshold both yield 0.
	 *
	 * @param int $level Level.
	 * @return int Bytes.
	 */
	public static function level_bonus( int $level ): int {
		$rule = Space_Settings::level_rule();

		if ( $rule['enabled'] ) {
			$threshold = \Nboke\UserFiles\Support\Theme_Levels::threshold( max( 0, $level ) );
			$steps     = intdiv( max( 0, $threshold ), max( 1, $rule['points_per_step'] ) );

			return max( 0, $steps * $rule['bytes_per_step'] );
		}

		$settings = Space_Settings::get();
		$map      = isset( $settings['level_bonus'] ) && is_array( $settings['level_bonus'] ) ? $settings['level_bonus'] : array();

		if ( ! isset( $map[ $level ] ) ) {
			return 0;
		}

		return max( 0, (int) $map[ $level ] );
	}

	/**
	 * Bytes granted for a VIP level (0 / 1 / 2); unknown keys yield 0.
	 *
	 * @param int $vip Vip level.
	 * @return int Bytes.
	 */
	public static function vip_bonus( int $vip ): int {
		$settings = Space_Settings::get();
		$map      = isset( $settings['vip_bonus'] ) && is_array( $settings['vip_bonus'] ) ? $settings['vip_bonus'] : array();

		if ( ! isset( $map[ $vip ] ) ) {
			return 0;
		}

		return max( 0, (int) $map[ $vip ] );
	}

	/**
	 * Runtime VIP labels: filter first, then theme option, then a fallback label.
	 *
	 * @return array<int,string> 0 => regular user, 1 => VIP, 2 => SVIP.
	 */
	public static function vip_labels(): array {
		$theme_names = self::theme_vip_names();
		$labels      = array();

		foreach ( array( 0, 1, 2 ) as $vip_level ) {
			if ( 0 === $vip_level ) {
				$labels[0] = __( '普通用户', 'sakura-imgopt' );
				continue;
			}

			$label = null;

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'sufm_vip_label', null, $vip_level );
				if ( is_string( $filtered ) && '' !== $filtered ) {
					$label = $filtered;
				}
			}

			if ( null === $label && isset( $theme_names[ $vip_level ] ) && '' !== $theme_names[ $vip_level ] ) {
				$label = $theme_names[ $vip_level ];
			}

			if ( null === $label ) {
				$label = 1 === $vip_level
					? __( 'VIP（黄金会员）', 'sakura-imgopt' )
					: __( 'SVIP（钻石会员）', 'sakura-imgopt' );
			}

			$labels[ $vip_level ] = $label;
		}

		return $labels;
	}

	/**
	 * Drops the memo for one user, or for every user when null.
	 *
	 * @param int|null $user_id User id or null.
	 * @return void
	 */
	public static function flush( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			self::$memo = array();
			return;
		}

		unset( self::$memo[ $user_id ] );
	}

	/**
	 * Reads the theme level, guarded and clamped.
	 *
	 * @param int $user_id User id.
	 * @return int 0 when unavailable.
	 */
	private static function user_level( int $user_id ): int {
		if ( 0 >= $user_id || ! function_exists( 'zib_get_user_level' ) ) {
			return 0;
		}

		try {
			$level = (int) zib_get_user_level( $user_id );
		} catch ( \Throwable $e ) {
			return 0;
		}

		if ( 0 > $level ) {
			return 0;
		}

		return min( self::MAX_LEVEL_KEY, $level );
	}

	/**
	 * Reads the theme VIP level, guarded and clamped.
	 *
	 * @param int $user_id User id.
	 * @return int 0 when unavailable or expired.
	 */
	private static function user_vip( int $user_id ): int {
		if ( 0 >= $user_id || ! function_exists( 'zib_get_user_vip_level' ) ) {
			return 0;
		}

		try {
			$vip = (int) zib_get_user_vip_level( $user_id );
		} catch ( \Throwable $e ) {
			return 0;
		}

		if ( 0 > $vip ) {
			return 0;
		}

		return min( 2, $vip );
	}

	/**
	 * Per-user quota override meta, or null when absent.
	 *
	 * @param int $user_id User id.
	 * @return int|null
	 */
	private static function quota_override( int $user_id ): ?int {
		if ( 0 >= $user_id || ! function_exists( 'metadata_exists' ) ) {
			return null;
		}

		if ( ! metadata_exists( 'user', $user_id, self::QUOTA_META ) ) {
			return null;
		}

		return max( 0, (int) get_user_meta( $user_id, self::QUOTA_META, true ) );
	}

	/**
	 * Memoised existence check for the grants table.
	 *
	 * Delegates to the store: the space panel asks the same question through
	 * `Space_Store` while the quota derivation runs, and two independent memos
	 * answered it with two `SHOW TABLES` round trips.
	 *
	 * @return bool
	 */
	private static function has_grants_table(): bool {
		return Space_Store::grants_table_exists();
	}

	/**
	 * Best-effort VIP names from the theme options.
	 *
	 * Several candidate keys are probed because the theme does not document them;
	 * a miss simply falls back to the built-in labels.
	 *
	 * @return array<int,string>
	 */
	private static function theme_vip_names(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$options = get_option( 'zibll_options' );

		if ( ! is_array( $options ) ) {
			return array();
		}

		$candidates = array(
			1 => array( 'pay_user_vip_1_name', 'vip_name_1', 'user_vip_1_name' ),
			2 => array( 'pay_user_vip_2_name', 'vip_name_2', 'user_vip_2_name' ),
		);

		$names = array();

		foreach ( $candidates as $vip_level => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $options[ $key ] ) || ! is_string( $options[ $key ] ) ) {
					continue;
				}

				$value = function_exists( 'sanitize_text_field' )
					? sanitize_text_field( $options[ $key ] )
					: trim( $options[ $key ] );

				if ( '' === $value ) {
					continue;
				}

				$names[ $vip_level ] = $value;
				break;
			}
		}

		return $names;
	}
}
