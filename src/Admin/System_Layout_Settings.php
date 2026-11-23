<?php
/**
 * Read/write the layout options behind the system settings island.
 *
 * The REST route only passes values through this class, so clamps, defaults and
 * the shape of the response can never drift from what the PHP view used to do.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Layout option gateway for the system settings screen.
 */
final class System_Layout_Settings {

	/**
	 * Current stored values.
	 *
	 * @return array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>}
	 */
	public static function current(): array {
		return array(
			'gaps'       => Layout::gaps(),
			'appearance' => Appearance::get(),
		);
	}

	/**
	 * Merge a partial request onto the stored state and clamp the result.
	 *
	 * Every field is optional: a request that only carries `radius` keeps the
	 * stored width, gaps and shadow. Values are clamped exactly like the old
	 * options.php sanitizers, so a tampered payload cannot widen the canvas.
	 *
	 * @param array<string, mixed>                                    $input   Raw request fields.
	 * @param array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>} $current Stored state.
	 * @return array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>}
	 */
	public static function merge( array $input, array $current ): array {
		$gaps = array(
			'gapX' => self::pick_int( $input, 'gapX', (int) $current['gaps']['gapX'] ),
			'gapY' => self::pick_int( $input, 'gapY', (int) $current['gaps']['gapY'] ),
		);

		$appearance = array(
			'page_width'   => self::pick_int( $input, 'page_width', (int) $current['appearance']['page_width'] ),
			'border_width' => self::pick_int( $input, 'border_width', (int) $current['appearance']['border_width'] ),
			'radius'       => self::pick_int( $input, 'radius', (int) $current['appearance']['radius'] ),
			'shadow'       => is_string( $input['shadow'] ?? null ) && '' !== $input['shadow']
				? (string) $input['shadow']
				: (string) $current['appearance']['shadow'],
		);

		return array(
			'gaps'       => Layout::sanitize_gaps( $gaps ),
			'appearance' => Appearance::sanitize( $appearance ),
		);
	}

	/**
	 * Persist a merged state and return what is now stored.
	 *
	 * @param array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>} $state Merged state.
	 * @return array{gaps:array{gapX:int,gapY:int},appearance:array<string, int|string>}
	 */
	public static function save( array $state ): array {
		update_option( Layout::GAPS_OPTION, $state['gaps'] );
		update_option( Appearance::OPTION, $state['appearance'] );

		return self::current();
	}

	/**
	 * Read one integer field, falling back when it is missing or not numeric.
	 *
	 * @param array<string, mixed> $input    Request fields.
	 * @param string               $key      Field name.
	 * @param int                  $fallback Value to keep.
	 * @return int
	 */
	private static function pick_int( array $input, string $key, int $fallback ): int {
		if ( ! isset( $input[ $key ] ) ) {
			return $fallback;
		}

		$value = $input[ $key ];

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( is_float( $value ) ) {
			return (int) $value;
		}

		return $fallback;
	}
}
