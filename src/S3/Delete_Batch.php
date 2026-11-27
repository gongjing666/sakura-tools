<?php
/**
 * Batch dispatch settings for the delete queue.
 *
 * The queue used to fire the moment a row appeared. Operators with busy sites
 * want the opposite: wait until a batch is worth a run, or until the oldest row
 * has waited long enough. This class owns those numbers and nothing else.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Read, validate and persist the dispatch policy.
 */
final class Delete_Batch {

	/**
	 * Option name.
	 */
	public const OPTION = 'sakura_imgopt_s3_delete_batch';

	/**
	 * Defaults, in the units the card shows.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'     => true,
			'batch_size'  => 20,
			'max_wait'    => 300,
			'batch_time'  => 20,
			'batch_bytes' => 500,
		);
	}

	/**
	 * Current policy, clamped to the accepted ranges.
	 *
	 * @return array<string,mixed>
	 */
	public static function read(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist a policy from form input.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed> Stored policy.
	 */
	public static function save( $input ): array {
		$clean = self::sanitize( is_array( $input ) ? $input : array() );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $clean, false );
		}

		return $clean;
	}

	/**
	 * Clamp every field into its documented range.
	 *
	 * @param array<string,mixed> $input Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();

		return array(
			'enabled'     => ! isset( $input['enabled'] ) || ! in_array( (string) $input['enabled'], array( '0', 'false', '' ), true ),
			'batch_size'  => self::clamp( $input['batch_size'] ?? $defaults['batch_size'], 1, 200, (int) $defaults['batch_size'] ),
			'max_wait'    => self::clamp( $input['max_wait'] ?? $defaults['max_wait'], 0, 86400, (int) $defaults['max_wait'] ),
			'batch_time'  => self::clamp( $input['batch_time'] ?? $defaults['batch_time'], 10, 60, (int) $defaults['batch_time'] ),
			'batch_bytes' => self::clamp( $input['batch_bytes'] ?? $defaults['batch_bytes'], 0, 10240, (int) $defaults['batch_bytes'] ),
		);
	}

	/**
	 * Integer clamp with a fallback for unusable input.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value used when input is not numeric.
	 * @return int
	 */
	private static function clamp( $value, int $min, int $max, int $fallback ): int {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}
}
