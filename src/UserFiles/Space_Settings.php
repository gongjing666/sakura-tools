<?php
/**
 * Space expansion settings (option: sufm_space_settings).
 *
 * Single source of defaults + sanitisation for the Zibll-only quota expansion
 * feature. Kept separate from {@see \Nboke\UserFiles\Settings} on purpose: the
 * file-space master switch (sufm_settings.enabled) and the expansion switch
 * (sufm_space_settings.enabled) are two independent switches.
 *
 * @package Sakura\ImageOptimizer
 */

declare(strict_types=1);

namespace Nboke\UserFiles;

/**
 * Defaults, getters and sanitiser for the expansion option.
 */
final class Space_Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'sufm_space_settings';

	/**
	 * One megabyte in bytes.
	 */
	private const MB = 1048576;

	/**
	 * One gigabyte in bytes.
	 */
	private const GB = 1073741824;

	/**
	 * Hard upper bound for a single bonus / package size (1 TB).
	 */
	private const MAX_BYTES = 1099511627776;

	/**
	 * Highest key accepted by the manual fallback table.
	 *
	 * The theme supplies level prices and labels (Theme_Levels); this
	 * constant only bounds the legacy hand-filled map, kept so an install that
	 * still relies on it does not lose rows on save.
	 */
	private const MAX_LEVEL_KEY = 20;

	/**
	 * Highest number of days a package may last.
	 */
	private const MAX_DAYS = 3650;

	/**
	 * Request scoped cache of the sanitised option.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $memo = null;

	/**
	 * Shipped defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => false,
			'allowed_methods'     => array( 'balance' ),
			'apply_bonuses'       => true,
			'quota_override_mode' => 'cap',
			'order_icon'          => '',
			'level_rule'          => array(
				'enabled'         => true,
				'points_per_step' => 1000,
				'bytes_per_step'  => 100 * self::MB,
			),
			'level_bonus'         => array(
				1  => 0,
				2  => 128 * self::MB,
				3  => 256 * self::MB,
				4  => 512 * self::MB,
				5  => self::GB,
				6  => 2 * self::GB,
				7  => 3 * self::GB,
				8  => 4 * self::GB,
				9  => 6 * self::GB,
				10 => 8 * self::GB,
			),
			'vip_bonus'           => array(
				0 => 0,
				1 => 5 * self::GB,
				2 => 20 * self::GB,
			),
			'packages'            => array(
				array(
					'id'     => 'a1',
					'label'  => __( '10GB 扩容包', 'sakura-imgopt' ),
					'bytes'  => 10 * self::GB,
					'price'  => 30.0,
					'days'   => 365,
					'active' => true,
				),
				array(
					'id'     => 'a2',
					'label'  => __( '30GB 扩容包', 'sakura-imgopt' ),
					'bytes'  => 30 * self::GB,
					'price'  => 69.0,
					'days'   => 365,
					'active' => true,
				),
				array(
					'id'     => 'a3',
					'label'  => __( '100GB 永久扩容包', 'sakura-imgopt' ),
					'bytes'  => 100 * self::GB,
					'price'  => 168.0,
					'days'   => 0,
					'active' => true,
				),
			),
			'points'              => array(
				'enabled'          => true,
				'points_per_gb'    => 90,
				'max_gb_per_order' => 10,
				'daily_cap_gb'     => 20,
			),
			'points_packages'     => array(
				array(
					'id'     => 'p1',
					'gb'     => 1.0,
					'points' => 90,
					'days'   => 365,
					'active' => true,
				),
				array(
					'id'     => 'p2',
					'gb'     => 5.0,
					'points' => 450,
					'days'   => 365,
					'active' => true,
				),
				array(
					'id'     => 'p3',
					'gb'     => 10.0,
					'points' => 900,
					'days'   => 365,
					'active' => true,
				),
			),
		);
	}

	/**
	 * Sanitised option value (defaults merged with what is stored).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$stored = self::stored();
		$merged = array_merge( self::defaults(), $stored );

		// Numeric lists are appended by array_merge(); the stored allow-list must
		// replace the shipped one instead of extending it.
		if ( array_key_exists( 'allowed_methods', $stored ) ) {
			$merged['allowed_methods'] = $stored['allowed_methods'];
		}

		self::$memo = self::sanitize( $merged );

		return self::$memo;
	}

	/**
	 * Whether the expansion sale channel is switched on (L1, default off).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$settings = self::get();

		return true === (bool) ( $settings['enabled'] ?? false );
	}

	/**
	 * Whether grants / level / VIP bonuses are applied to the derived quota (L1b).
	 *
	 * @return bool
	 */
	public static function apply_bonuses(): bool {
		$settings = self::get();

		return true === (bool) ( $settings['apply_bonuses'] ?? true );
	}

	/**
	 * How an explicit per-user quota meta interacts with the derived limit.
	 *
	 * @return string Either cap or replace.
	 */
	public static function mode(): string {
		$settings = self::get();
		$mode     = (string) ( $settings['quota_override_mode'] ?? 'cap' );

		return in_array( $mode, array( 'cap', 'replace' ), true ) ? $mode : 'cap';
	}

	/**
	 * Level derived capacity rule.
	 *
	 * The theme owns both the ladder size and the points needed per rung, so
	 * the plugin stores only the conversion (points -> bytes) instead of a
	 * hand-maintained per-level table that drifts whenever the theme changes.
	 *
	 * @return array{enabled:bool,points_per_step:int,bytes_per_step:int}
	 */
	public static function level_rule(): array {
		$settings = self::get();
		$rule     = isset( $settings['level_rule'] ) && is_array( $settings['level_rule'] ) ? $settings['level_rule'] : array();

		return array(
			'enabled'         => true === (bool) ( $rule['enabled'] ?? false ),
			'points_per_step' => max( 1, (int) ( $rule['points_per_step'] ?? 1000 ) ),
			'bytes_per_step'  => max( 0, (int) ( $rule['bytes_per_step'] ?? ( 100 * self::MB ) ) ),
		);
	}

	/**
	 * Active + inactive package definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function packages(): array {
		$settings = self::get();
		$packages = $settings['packages'] ?? array();

		return is_array( $packages ) ? array_values( $packages ) : array();
	}

	/**
	 * Points exchange configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function points(): array {
		$settings = self::get();
		$points   = $settings['points'] ?? array();

		return is_array( $points ) ? $points : array();
	}

	/**
	 * Theme payment channels the operator has explicitly enabled.
	 *
	 * Empty means the cash checkout is closed. The value is a whitelist: the
	 * sufm_space_allowed_payment_methods filter can only narrow it further.
	 *
	 * @return string[]
	 */
	public static function allowed_methods(): array {
		$settings = self::get();
		$methods  = $settings['allowed_methods'] ?? array();

		return self::sanitize_methods( $methods );
	}

	/**
	 * Whether the points exchange product is switched on.
	 *
	 * Kept separate from points_per_gb: a site can keep its price table and
	 * close the entrance without losing the configured numbers.
	 *
	 * @return bool
	 */
	public static function points_enabled(): bool {
		$points = self::points();

		return true === (bool) ( $points['enabled'] ?? true );
	}

	/**
	 * Active points packages (points are their own product line).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function points_packages(): array {
		$settings = self::get();
		$rows     = $settings['points_packages'] ?? array();

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( self::class, 'with_points_label' ), array_values( $rows ) );
	}

	/**
	 * Order icon shared by every package (cash and points alike).
	 *
	 * @return string
	 */
	public static function order_icon(): string {
		$settings = self::get();

		return self::sanitize_icon_url( self::scalar_string( $settings['order_icon'] ?? '' ) );
	}

	/**
	 * Adds a stable label to a points package row.
	 *
	 * @param mixed $row Stored row.
	 * @return array<string,mixed>
	 */
	public static function with_points_label( $row ): array {
		$row = is_array( $row ) ? $row : array();

		$label = self::scalar_string( $row['label'] ?? '' );

		if ( '' === $label ) {
			$label = self::points_label( self::scalar_float( $row['gb'] ?? 0 ), self::scalar_int( $row['days'] ?? 0 ) );
		}

		$row['label'] = $label;

		return $row;
	}

	/**
	 * Human readable points package label.
	 *
	 * @param float $gb   Gigabytes.
	 * @param int   $days Days, 0 for permanent.
	 * @return string
	 */
	public static function points_label( float $gb, int $days ): string {
		unset( $days );

		$size = rtrim( rtrim( number_format( $gb, 2, '.', '' ), '0' ), '.' );

		/* translators: %s: size in GB */
		return sprintf( __( '%sGB 扩容包', 'sakura-imgopt' ), $size );
	}

	/**
	 * Sanitises an icon URL (http / https only).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function sanitize_icon_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * Drops the request scoped cache (called after an option write).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Sanitiser used both as the settings API callback and internally.
	 *
	 * Supports partial submissions: when the payload carries a truthy `_partial`
	 * key the missing keys are inherited from the stored value instead of the
	 * defaults, so a card that only edits switches cannot wipe the bonus table.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $value ): array {
		$defaults = self::defaults();
		$stored   = self::stored();
		$value    = is_array( $value ) ? $value : array();

		$partial = false;
		if ( isset( $value['_partial'] ) ) {
			$partial = (bool) $value['_partial'];
			unset( $value['_partial'] );
		}

		// A checkbox group sends nothing when every box is cleared, so the form
		// also posts allowed_methods_present=1 to distinguish "all off" from a
		// card that does not edit this field at all.
		$methods_present = array_key_exists( 'allowed_methods', $value ) || isset( $value['allowed_methods_present'] );
		unset( $value['allowed_methods_present'] );

		$merged = $partial
			? array_merge( $defaults, $stored, $value )
			: array_merge( $defaults, $value );

		// The payment card posts only points[enabled]; a shallow array_merge()
		// would replace the whole points block and reset its price fields.
		if ( $partial && isset( $value['points'] ) && is_array( $value['points'] ) ) {
			$stored_points  = isset( $stored['points'] ) && is_array( $stored['points'] ) ? $stored['points'] : array();
			$merged['points'] = array_merge( $defaults['points'], $stored_points, $value['points'] );
		}

		$out = array();

		if ( $methods_present ) {
			$methods = $value['allowed_methods'] ?? array();
		} elseif ( $partial ) {
			$methods = $stored['allowed_methods'] ?? $defaults['allowed_methods'];
		} else {
			$methods = $defaults['allowed_methods'];
		}

		$out['allowed_methods'] = self::sanitize_methods( $methods );

		$out['enabled']             = true === (bool) ( $merged['enabled'] ?? false );
		$out['apply_bonuses']       = true === (bool) ( $merged['apply_bonuses'] ?? true );
		$out['quota_override_mode'] = self::sanitize_mode( $merged['quota_override_mode'] ?? 'cap' );
		$out['order_icon']          = self::sanitize_icon_url( self::scalar_string( $merged['order_icon'] ?? '' ) );
		$out['level_bonus']         = self::sanitize_level_bonus( $value, $merged, $defaults );
		$out['level_rule']          = self::sanitize_level_rule( $value, $merged, $defaults );
		$out['vip_bonus']           = self::sanitize_vip_bonus( $value, $merged, $defaults );
		$out['packages']            = self::sanitize_packages( $value, $merged, $defaults );
		$out['points']              = self::sanitize_points( $value, $merged, $defaults );
		$out['points_packages']     = self::sanitize_points_packages( $value, $merged, $defaults );

		return $out;
	}

	/**
	 * Raw stored option value, always an array.
	 *
	 * @return array<string,mixed>
	 */
	private static function stored(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Normalises the cap / replace selector.
	 *
	 * @param mixed $mode Raw value.
	 * @return string
	 */
	private static function sanitize_mode( $mode ): string {
		$mode = is_string( $mode ) ? $mode : '';

		return in_array( $mode, array( 'cap', 'replace' ), true ) ? $mode : 'cap';
	}

	/**
	 * Level bonus table. Form posts megabytes via level_bonus_mb[<level>].
	 *
	 * @param array<string,mixed> $value    Submitted payload (already stripped of _partial).
	 * @param array<string,mixed> $merged   Merged payload used as fallback.
	 * @param array<string,mixed> $defaults Shipped defaults.
	 * @return array<int,int> Level => bytes.
	 */
	private static function sanitize_level_bonus( array $value, array $merged, array $defaults ): array {
		if ( isset( $value['level_bonus_mb'] ) && is_array( $value['level_bonus_mb'] ) ) {
			return self::map_from_megabytes( $value['level_bonus_mb'], 1, self::MAX_LEVEL_KEY );
		}

		if ( isset( $value['level_bonus'] ) && is_array( $value['level_bonus'] ) ) {
			return self::map_from_bytes( $value['level_bonus'], 1, self::MAX_LEVEL_KEY );
		}

		$fallback = isset( $merged['level_bonus'] ) && is_array( $merged['level_bonus'] )
			? $merged['level_bonus']
			: $defaults['level_bonus'];

		return self::map_from_bytes( $fallback, 1, self::MAX_LEVEL_KEY );
	}

	/**
	 * Level capacity rule. The form posts megabytes via level_rule[bytes_per_step_mb].
	 *
	 * @param array<string,mixed> $value    Submitted payload (already stripped of _partial).
	 * @param array<string,mixed> $merged   Merged payload used as fallback.
	 * @param array<string,mixed> $defaults Shipped defaults.
	 * @return array{enabled:bool,points_per_step:int,bytes_per_step:int}
	 */
	private static function sanitize_level_rule( array $value, array $merged, array $defaults ): array {
		$shipped = isset( $defaults['level_rule'] ) && is_array( $defaults['level_rule'] ) ? $defaults['level_rule'] : array();
		$posted  = isset( $value['level_rule'] ) && is_array( $value['level_rule'] ) ? $value['level_rule'] : null;
		$source  = null !== $posted ? $posted : ( isset( $merged['level_rule'] ) && is_array( $merged['level_rule'] ) ? $merged['level_rule'] : $shipped );

		$points = self::scalar_int( $source['points_per_step'] ?? 1000, 1000 );
		$points = max( 1, min( 10000000, $points ) );

		if ( isset( $source['bytes_per_step_mb'] ) && is_scalar( $source['bytes_per_step_mb'] ) ) {
			$bytes = (int) round( max( 0.0, (float) $source['bytes_per_step_mb'] ) * self::MB );
		} elseif ( isset( $source['bytes_per_step'] ) && is_scalar( $source['bytes_per_step'] ) ) {
			$bytes = (int) $source['bytes_per_step'];
		} else {
			$bytes = self::scalar_int( $shipped['bytes_per_step'] ?? ( 100 * self::MB ), 100 * self::MB );
		}

		return array(
			'enabled'         => ! empty( self::scalar_int( $source['enabled'] ?? 0 ) ),
			'points_per_step' => $points,
			'bytes_per_step'  => max( 0, min( self::MAX_BYTES, $bytes ) ),
		);
	}

	/**
	 * VIP bonus table. Form posts megabytes via vip_bonus_mb[<vip level>].
	 *
	 * @param array<string,mixed> $value    Submitted payload.
	 * @param array<string,mixed> $merged   Merged payload used as fallback.
	 * @param array<string,mixed> $defaults Shipped defaults.
	 * @return array<int,int> Vip level (0..2) => bytes.
	 */
	private static function sanitize_vip_bonus( array $value, array $merged, array $defaults ): array {
		$out = array();

		if ( isset( $value['vip_bonus_mb'] ) && is_array( $value['vip_bonus_mb'] ) ) {
			$out = self::map_from_megabytes( $value['vip_bonus_mb'], 0, 2 );
		} elseif ( isset( $value['vip_bonus'] ) && is_array( $value['vip_bonus'] ) ) {
			$out = self::map_from_bytes( $value['vip_bonus'], 0, 2 );
		} else {
			$fallback = isset( $merged['vip_bonus'] ) && is_array( $merged['vip_bonus'] )
				? $merged['vip_bonus']
				: $defaults['vip_bonus'];

			$out = self::map_from_bytes( $fallback, 0, 2 );
		}

		// The theme hardcodes 0..2 (zibpay-vip.php), so the key set is always complete.
		foreach ( array( 0, 1, 2 ) as $vip_level ) {
			if ( ! isset( $out[ $vip_level ] ) ) {
				$out[ $vip_level ] = 0;
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Package definitions: id / label / bytes / price / days / active.
	 *
	 * @param array<string,mixed> $value    Submitted payload.
	 * @param array<string,mixed> $merged   Merged payload used as fallback.
	 * @param array<string,mixed> $defaults Shipped defaults.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_packages( array $value, array $merged, array $defaults ): array {
		if ( isset( $value['packages'] ) && is_array( $value['packages'] ) ) {
			$source = $value['packages'];
		} elseif ( isset( $merged['packages'] ) && is_array( $merged['packages'] ) ) {
			$source = $merged['packages'];
		} else {
			$source = $defaults['packages'];
		}

		$packages = array();
		$seen     = array();

		foreach ( $source as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			// Rows marked for deletion never reach the option. A posted array is
			// not a delete flag: only a scalar truthy value removes the row.
			if ( isset( $package['remove'] ) && is_scalar( $package['remove'] ) && ! empty( $package['remove'] ) ) {
				continue;
			}

			if ( isset( $package['bytes_gb'] ) ) {
				$bytes = self::gb_to_bytes( self::scalar_float( $package['bytes_gb'] ) );
			} elseif ( isset( $package['bytes_mb'] ) ) {
				$bytes = self::mb_to_bytes( self::scalar_float( $package['bytes_mb'] ) );
			} elseif ( isset( $package['bytes'] ) ) {
				$bytes = self::clamp_bytes( self::scalar_int( $package['bytes'] ) );
			} else {
				$bytes = 0;
			}

			$price = round( self::scalar_float( $package['price'] ?? 0.0 ), 2 );
			if ( 0.0 > $price ) {
				$price = 0.0;
			}

			$label = sanitize_text_field( self::scalar_string( $package['label'] ?? '' ) );

			// A freshly added row the admin never filled in is not a package.
			if ( 0 >= $bytes && 0.0 >= $price && '' === $label ) {
				continue;
			}

			$days = self::scalar_int( $package['days'] ?? 0 );
			if ( 0 > $days ) {
				$days = 0;
			}
			if ( self::MAX_DAYS < $days ) {
				$days = self::MAX_DAYS;
			}

			if ( '' === $label ) {
				$label = '';
			}

			$id = self::plan_id( self::scalar_string( $package['id'] ?? '' ), 'a', $seen );

			$seen[ $id ] = true;

			$image = esc_url_raw( trim( self::scalar_string( $package['image'] ?? '' ) ) );

			if ( '' !== $image && ! in_array( (string) wp_parse_url( $image, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				$image = '';
			}

			$packages[] = array(
				'id'     => $id,
				'label'  => '' !== $label ? $label : $id,
				'bytes'  => $bytes,
				'price'  => $price,
				'days'   => $days,
				'image'  => $image,
				'active' => ! empty( self::scalar_int( $package['active'] ?? 0 ) ),
			);

			if ( count( $packages ) >= 100 ) {
				break;
			}
		}

		return $packages;
	}

	/**
	 * Points exchange configuration, including the per-order <= per-day rule.
	 *
	 * @param array<string,mixed> $value    Submitted payload.
	 * @param array<string,mixed> $merged   Merged payload used as fallback.
	 * @param array<string,mixed> $defaults Shipped defaults.
	 * @return array<string,mixed>
	 */
	/**
	 * Points packages are their own product line: gb + points + days.
	 *
	 * @param array $value    Raw input.
	 * @param array $merged   Merged settings.
	 * @param array $defaults Defaults.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_points_packages( array $value, array $merged, array $defaults ): array {
		$raw = $value['points_packages'] ?? null;

		if ( null === $raw ) {
			$raw = $merged['points_packages'] ?? ( $defaults['points_packages'] ?? array() );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// Rows marked for deletion never reach the option. A posted array is
			// not a delete flag: only a scalar truthy value removes the row.
			if ( isset( $row['remove'] ) && is_scalar( $row['remove'] ) && ! empty( $row['remove'] ) ) {
				continue;
			}

			$gb     = self::scalar_float( $row['gb'] ?? 0.0 );
			$points = self::scalar_int( $row['points'] ?? 0 );

			if ( $gb <= 0 || $points <= 0 ) {
				continue;
			}

			$id = self::plan_id( self::scalar_string( $row['id'] ?? '' ), 'p', $seen );

			$seen[ $id ] = true;

			$days  = max( 0, min( 3650, self::scalar_int( $row['days'] ?? 365, 365 ) ) );
			$label = sanitize_text_field( self::scalar_string( $row['label'] ?? '' ) );

			if ( '' === $label ) {
				$label = self::points_label( $gb, $days );
			}

			$out[] = array(
				'id'     => $id,
				'label'  => $label,
				'gb'     => round( max( 0.5, min( 1024, $gb ) ), 2 ),
				'points' => max( 1, min( 1000000, $points ) ),
				'days'   => $days,
				'active' => ! empty( self::scalar_int( $row['active'] ?? 0 ) ),
			);

			if ( count( $out ) >= 100 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Keeps the plan id inside its own namespace (a* for cash, p* for points).
	 *
	 * An id that already matches the pattern and is unused is preserved; anything
	 * else gets the next free number, so a new row never reuses a deleted id and
	 * ids never collide across the two product lines.
	 *
	 * @param string             $raw    Submitted id ('' for a new row).
	 * @param string             $prefix Either a or p.
	 * @param array<string,bool> $used   Ids already claimed in this payload.
	 * @return string
	 */
	private static function plan_id( string $raw, string $prefix, array $used ): string {
		$raw = strtolower( trim( $raw ) );

		if ( preg_match( '/^' . $prefix . '(\d+)$/', $raw, $matches ) ) {
			$number = (int) $matches[1];

			if ( 0 < $number && ! isset( $used[ $prefix . $number ] ) ) {
				return $prefix . $number;
			}
		}

		$max = 0;

		foreach ( array_keys( $used ) as $key ) {
			if ( preg_match( '/^' . $prefix . '(\d+)$/', (string) $key, $matches ) ) {
				$max = max( $max, (int) $matches[1] );
			}
		}

		$next = $max + 1;

		while ( isset( $used[ $prefix . $next ] ) ) {
			$next++;
		}

		return $prefix . $next;
	}

	/**
	 * Normalises a payment channel allow-list.
	 *
	 * @param mixed $methods Raw method ids.
	 * @return string[]
	 */
	private static function sanitize_methods( $methods ): array {
		if ( ! is_array( $methods ) ) {
			return array();
		}

		$out = array();

		foreach ( $methods as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			$out[ $key ] = true;

			if ( 20 <= count( $out ) ) {
				break;
			}
		}

		return array_keys( $out );
	}

	private static function sanitize_points( array $value, array $merged, array $defaults ): array {
		$base = isset( $merged['points'] ) && is_array( $merged['points'] ) ? $merged['points'] : $defaults['points'];

		if ( isset( $value['points'] ) && is_array( $value['points'] ) ) {
			$base = array_merge( $base, $value['points'] );
		}

		$points = array(
			'enabled'          => true === (bool) self::scalar_int( $base['enabled'] ?? 1, 1 ),
			'points_per_gb'    => self::clamp_int( self::scalar_int( $base['points_per_gb'] ?? 90, 90 ), 1, 100000 ),
			'max_gb_per_order' => self::clamp_int( self::scalar_int( $base['max_gb_per_order'] ?? 10, 10 ), 1, 1000 ),
			'daily_cap_gb'     => self::clamp_int( self::scalar_int( $base['daily_cap_gb'] ?? 20, 20 ), 1, 1000 ),
		);

		// Hard rule: a single order can never exceed the daily allowance.
		if ( $points['max_gb_per_order'] > $points['daily_cap_gb'] ) {
			$points['max_gb_per_order'] = $points['daily_cap_gb'];
		}

		return $points;
	}

	/**
	 * Converts a [key => megabytes] map into a sorted [key => bytes] map.
	 *
	 * @param array<mixed> $map      Raw map.
	 * @param int          $min_key  Lowest accepted key.
	 * @param int          $max_key  Highest accepted key.
	 * @return array<int,int>
	 */
	private static function map_from_megabytes( array $map, int $min_key, int $max_key ): array {
		$out = array();

		foreach ( $map as $key => $megabytes ) {
			$index = (int) $key;

			if ( $index < $min_key || $index > $max_key || ! is_scalar( $megabytes ) ) {
				continue;
			}

			$out[ $index ] = self::mb_to_bytes( $megabytes );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Normalises a [key => bytes] map, clamping keys and values.
	 *
	 * @param array<mixed> $map     Raw map.
	 * @param int          $min_key Lowest accepted key.
	 * @param int          $max_key Highest accepted key.
	 * @return array<int,int>
	 */
	private static function map_from_bytes( array $map, int $min_key, int $max_key ): array {
		$out = array();

		foreach ( $map as $key => $bytes ) {
			$index = (int) $key;

			if ( $index < $min_key || $index > $max_key || ! is_scalar( $bytes ) ) {
				continue;
			}

			$out[ $index ] = self::clamp_bytes( $bytes );
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Gigabytes to bytes, clamped to [0, 1TB].
	 *
	 * @param mixed $gigabytes Raw gigabytes value.
	 * @return int
	 */
	private static function gb_to_bytes( $gigabytes ): int {
		if ( ! is_scalar( $gigabytes ) ) {
			return 0;
		}

		return self::clamp_bytes( round( max( 0.0, (float) $gigabytes ) * self::GB ) );
	}

	/**
	 * Megabytes to bytes, clamped to [0, 1TB].
	 *
	 * @param mixed $megabytes Raw megabytes value.
	 * @return int
	 */
	private static function mb_to_bytes( $megabytes ): int {
		if ( ! is_scalar( $megabytes ) ) {
			return 0;
		}

		$bytes = (int) round( (float) $megabytes * (float) self::MB );

		return self::clamp_bytes( $bytes );
	}

	/**
	 * Clamps a byte value to [0, 1TB]; negative input becomes 0.
	 *
	 * @param mixed $bytes Raw byte value.
	 * @return int
	 */
	private static function clamp_bytes( $bytes ): int {
		if ( ! is_scalar( $bytes ) ) {
			return 0;
		}

		$bytes = (int) round( (float) $bytes );

		if ( 0 > $bytes ) {
			$bytes = 0;
		}

		if ( self::MAX_BYTES < $bytes ) {
			$bytes = self::MAX_BYTES;
		}

		return $bytes;
	}

	/**
	 * A scalar as a string, or the fallback when the value is not scalar.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function scalar_string( $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * A scalar as a float, or the fallback when the value is not scalar.
	 *
	 * @param mixed $value    Raw value.
	 * @param float $fallback Fallback.
	 * @return float
	 */
	private static function scalar_float( $value, float $fallback = 0.0 ): float {
		return is_scalar( $value ) ? (float) $value : $fallback;
	}

	/**
	 * A scalar as an int, or the fallback when the value is not scalar.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private static function scalar_int( $value, int $fallback = 0 ): int {
		return is_scalar( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Clamps an integer to an inclusive range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Lower bound.
	 * @param int   $max   Upper bound.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max ): int {
		if ( ! is_scalar( $value ) ) {
			return $min;
		}

		$value = (int) $value;

		if ( $value < $min ) {
			$value = $min;
		}

		if ( $value > $max ) {
			$value = $max;
		}

		return $value;
	}
}
