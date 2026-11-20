<?php
/**
 * The "sakura front" rail: local media → cloud objects → index, one line.
 *
 * Three stations sit on a single drawn line with a scattering of petals, so a
 * reader sees at a glance whether the three sides agree. The artwork is a
 * scaled copy of the design draft; the numbers live in HTML instead of the
 * drawing, because they grow (1,240 files, 1.23 GB) and SVG text would clip at
 * the first station's anchor.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin\UI;

defined( 'ABSPATH' ) || exit;

/**
 * One decorative rail with three value/label pairs.
 */
final class Frontline {

	/**
	 * Render the rail.
	 *
	 * @param array<int,array<string,string>> $stations Three entries: value, label, sub, tone.
	 * @param string                          $caption  Left-hand caption.
	 * @param string                          $note     Right-hand caption (e.g. last check time).
	 * @return void
	 */
	public static function render( array $stations, string $caption = '', string $note = '' ): void {
		$stations = array_slice( array_values( $stations ), 0, 3 );

		if ( 3 !== count( $stations ) ) {
			return;
		}

		$tones = array( 'sky', 'petal', 'sun' );

		/*
		 * Station anchors as percentages of the 900×150 view box, so the number
		 * sits on its dot instead of in a row underneath: 86/900, 112/150 etc.
		 * The draft puts the value above the node and the caption beside or
		 * below it; reproducing that needs the labels positioned, not flowed.
		 */
		$anchors = array(
			array( 'x' => '9.556%', 'y' => '74.667%' ),
			array( 'x' => '50.222%', 'y' => '37.333%' ),
			array( 'x' => '90.889%', 'y' => '64.000%' ),
		);

		echo '<div class="frontline">';
		echo '<div class="frontline__plot">';

		self::arc();

		echo '<ul class="frontline__legend">';

		foreach ( $stations as $index => $station ) {
			$tone  = isset( $station['tone'] ) && '' !== $station['tone'] ? $station['tone'] : $tones[ $index ];
			$style = '--frontline-x:' . $anchors[ $index ]['x'] . ';--frontline-y:' . $anchors[ $index ]['y'];

			echo '<li class="frontline__station frontline__station--' . esc_attr( $tone ) . '" style="' . esc_attr( $style ) . '">';
			echo '<span class="frontline__value">' . esc_html( $station['value'] ) . '</span>';
			echo '<span class="frontline__meta">';
			echo '<span class="frontline__label">' . esc_html( $station['label'] ) . '</span>';

			if ( ! empty( $station['sub'] ) ) {
				echo '<span class="frontline__sub">' . esc_html( $station['sub'] ) . '</span>';
			}

			echo '</span>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';

		if ( '' !== $caption || '' !== $note ) {
			echo '<p class="frontline__caption">';
			if ( '' !== $caption ) {
				echo '<span>' . esc_html( $caption ) . '</span>';
			}
			if ( '' !== $note ) {
				echo '<span>' . esc_html( $note ) . '</span>';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * The drawing itself: one arc, a soft glow at its end, two petals, three dots.
	 *
	 * Dots keep their positions from the draft (86 / 452 / 818 of 900) so the
	 * three legend cells line up under them.
	 *
	 * @return void
	 */
	private static function arc(): void {
		echo '<svg class="frontline__arc" viewBox="0 0 900 150" aria-hidden="true" focusable="false">';

		echo '<defs>';
		echo '<linearGradient id="sakura-front-arc" x1="0" y1="0" x2="1" y2="0">';
		echo '<stop offset="0" stop-color="#7eb6f0"/><stop offset=".42" stop-color="#c99cd8"/>';
		echo '<stop offset=".66" stop-color="#ff9cc0"/><stop offset="1" stop-color="#ffc85c"/>';
		echo '</linearGradient>';
		echo '<radialGradient id="sakura-front-glow" cx=".5" cy=".5" r=".5">';
		echo '<stop offset="0" stop-color="#fff4d6" stop-opacity=".95"/>';
		echo '<stop offset="1" stop-color="#fff4d6" stop-opacity="0"/>';
		echo '</radialGradient>';
		echo '</defs>';

		echo '<circle cx="820" cy="74" r="64" fill="url(#sakura-front-glow)"/>';
		echo '<path d="M86 112C210 22 330 20 452 56c120 36 244 40 366 40" fill="none" stroke="url(#sakura-front-arc)" stroke-width="3.5" stroke-linecap="round" opacity=".55"/>';
		echo '<path d="M86 112C210 22 330 20 452 56c120 36 244 40 366 40" fill="none" stroke="#ffb0d0" stroke-width="10" stroke-linecap="round" stroke-dasharray="2 46" opacity=".9"/>';

		echo '<g class="frontline__petals">';
		echo '<path transform="translate(268 44) rotate(18)" d="M0 0c3.4 2 6 5.4 6 9.1C6 12.7 3.4 17 0 18.3-3.4 17-6 12.7-6 9.1-6 5.4-3.4 2 0 0z" fill="#ffb3d1"/>';
		echo '<path transform="translate(636 84) rotate(-24)" d="M0 0c2.6 1.5 4.5 4.1 4.5 7 0 2.7-1.9 6-4.5 7-2.6-1-4.5-4.3-4.5-7 0-2.9 1.9-5.5 4.5-7z" fill="#ffc6db" opacity=".9"/>';
		echo '</g>';

		echo '<circle class="frontline__dot frontline__dot--sky" cx="86" cy="112" r="9"/>';
		echo '<circle class="frontline__dot frontline__dot--petal" cx="452" cy="56" r="9"/>';
		echo '<circle class="frontline__dot frontline__dot--sun" cx="818" cy="96" r="9"/>';

		echo '</svg>';
	}
}
