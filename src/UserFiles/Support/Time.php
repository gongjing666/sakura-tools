<?php
/**
 * Time helpers shared by the user file space repositories.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every stored timestamp is UTC `Y-m-d H:i:s`, so comparisons never depend on
 * the visitor's timezone setting.
 */
final class Time {

	/**
	 * Current UTC timestamp for a DATETIME column.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * UTC timestamp N seconds from now.
	 *
	 * @param int $seconds Offset in seconds.
	 * @return string
	 */
	public static function in( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $seconds );
	}

	/**
	 * The zero datetime used by the schema.
	 *
	 * @return string
	 */
	public static function zero(): string {
		return '0000-00-00 00:00:00';
	}

	/**
	 * Is a stored timestamp set (not the zero datetime)?
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function set( string $value ): bool {
		return '' !== $value && self::zero() !== $value;
	}
}
