<?php
/**
 * Per-card writes for the thumbnail tab.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Thumbnail\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Saves one thumbnail card over REST.
 *
 * The thumbnail tab keeps five cards in one option (`sakura_imgopt_oss_thumb`).
 * The card boundary lives inside `Settings::sanitize()`: it starts from the
 * stored value and only lets the card named by `_sakura_card` write its own
 * section. This class therefore has exactly one job — put those two markers
 * back, which the form path used to add as hidden fields, and then persist the
 * sanitized array. It never rewrites the option whole.
 */
final class Thumb_Settings {

	/**
	 * Cards that own part of the option.
	 *
	 * @var array<int,string>
	 */
	public const CARDS = array( 'switches', 'scopes', 'scenes', 'sizes', 'advanced' );

	/**
	 * Save one card.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted values.
	 * @return array{card:string,values:array<string,mixed>,notice:array<string,string>}
	 */
	public function save( string $card, array $fields ): array {
		if ( ! in_array( $card, self::CARDS, true ) ) {
			return array(
				'card'   => $card,
				'values' => $this->values( self::CARDS[0] ),
				'notice' => $this->failed(),
			);
		}

		$current = Settings::get();
		$input   = $fields;

		if ( 'sizes' === $card ) {
			$input['sizes'] = $this->merge_sizes( $current, $fields['sizes'] ?? array() );
		}

		// The two markers the form used to carry; without them sanitize() would
		// treat this as a whole-form submission and blank every other section.
		$input['_sakura_card']    = $card;
		$input['_sakura_partial'] = 1;
		$input['schema']          = Settings::SCHEMA;

		$clean = Settings::sanitize( $input );

		update_option( Settings::OPTION, $clean );
		Settings::reset();

		return array(
			'card'   => $card,
			'values' => $this->values( $card ),
			'notice' => $this->notice( $card, $clean, $input ),
		);
	}

	/**
	 * Values the front end needs after a save, scoped to one card.
	 *
	 * @param string $card Card id.
	 * @return array<string,mixed>
	 */
	public function values( string $card ): array {
		$option = Settings::get();

		switch ( $card ) {
			case 'switches':
				return array(
					'enabled'       => (int) ( $option['enabled'] ?? 0 ),
					'engine'        => (string) ( $option['engine'] ?? 'url' ),
					'provider_mode' => (string) ( $option['provider_mode'] ?? 'auto' ),
					'dpr'           => (float) ( $option['dpr'] ?? 1.5 ),
					'host'          => Settings::media_host(),
					'effective'     => Settings::effective_mode(),
				);

			case 'scopes':
				return array( 'scopes' => (array) ( $option['scopes'] ?? array() ) );

			case 'scenes':
				return array( 'scenes' => (array) ( $option['scenes'] ?? array() ) );

			case 'sizes':
				return array(
					'sizes'     => (array) ( $option['sizes'] ?? array() ),
					'style_map' => (array) ( $option['style_map'] ?? array() ),
				);

			case 'advanced':
				return array( 'advanced' => (array) ( $option['advanced'] ?? array() ) );
		}

		return array();
	}

	/**
	 * Merge submitted size rows onto the stored ones.
	 *
	 * The table does not edit `format`, and a row here replaces the stored row
	 * wholesale, so a submission without it would silently reset every size to
	 * `auto`. Width, height, mode, position, quality and style come from the
	 * form; everything else is carried over untouched.
	 *
	 * @param array<string,mixed> $current Stored option.
	 * @param mixed               $rows    Submitted rows.
	 * @return array<string,array<string,mixed>>
	 */
	private function merge_sizes( array $current, $rows ): array {
		$stored = isset( $current['sizes'] ) && is_array( $current['sizes'] ) ? $current['sizes'] : array();
		$styles = isset( $current['style_map'] ) && is_array( $current['style_map'] ) ? $current['style_map'] : array();
		$out    = array();

		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $name => $row ) {
			$name = (string) $name;

			if ( ! is_array( $row ) ) {
				continue;
			}

			$base = isset( $stored[ $name ] ) && is_array( $stored[ $name ] ) ? $stored[ $name ] : array();
			$row  = array_merge(
				$base,
				array_intersect_key(
					$row,
					array(
						'width'    => true,
						'height'   => true,
						'mode'     => true,
						'position' => true,
						'quality'  => true,
						'style'    => true,
					)
				)
			);

			$row['format'] = (string) ( $base['format'] ?? 'auto' );

			if ( ! isset( $row['style'] ) ) {
				$row['style'] = (string) ( $styles[ $name ] ?? '' );
			}

			$out[ $name ] = $row;
		}

		return $out;
	}

	/**
	 * Prompt for a successful save, or a warning when values were adjusted.
	 *
	 * @param string              $card      Card id.
	 * @param array<string,mixed> $stored    Stored option after the save.
	 * @param array<string,mixed> $requested Values this request asked for.
	 * @return array<string,string>
	 */
	private function notice( string $card, array $stored, array $requested ): array {
		$messages = array();

		if ( 'switches' === $card && ! empty( $stored['enabled'] ) && '' === Settings::media_host() ) {
			$messages[] = __( '已保存，但没有从对象存储配置里解析出图片域名，前台不会处理任何图片。', 'sakura-imgopt' );
		}

		if ( $messages ) {
			return array(
				'tone'    => 'warn',
				'kicker'  => 'SETTINGS / CHECK',
				'title'   => __( '已保存，但有需要注意的地方', 'sakura-imgopt' ),
				'message' => implode( ' ', $messages ),
			);
		}

		if ( 'sizes' === $card && ! $this->same_rows( $stored, $requested ) ) {
			return array(
				'tone'    => 'warn',
				'kicker'  => 'SETTINGS / CHECK',
				'title'   => __( '已保存，部分数值按规则调整', 'sakura-imgopt' ),
				'message' => __( '尺寸的宽高或质量超出允许范围，已按最近的合法值写入。', 'sakura-imgopt' ),
			);
		}

		return array(
			'tone'    => 'ok',
			'kicker'  => 'SETTINGS / SAVED',
			'title'   => __( '设置已保存', 'sakura-imgopt' ),
			'message' => __( '这一张卡的设置已经写入，页面其它卡片不受影响。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Whether two size maps describe the same editable numbers.
	 *
	 * The stored rows carry `format` (the table never edits it) and the style
	 * lives in `style_map`, so a raw comparison of the two arrays would flag
	 * every save. Compare only what the operator can actually type.
	 *
	 * @param array<string,mixed> $stored    Option after sanitize().
	 * @param array<string,mixed> $requested Values this request asked for.
	 * @return bool
	 */
	private function same_rows( array $stored, array $requested ): bool {
		return $this->editable( $stored ) === $this->editable( $requested );
	}

	/**
	 * The part of a size map the operator controls.
	 *
	 * @param array<string,mixed> $option Option-like array.
	 * @return array{0:array<string,array<string,mixed>>,1:array<string,string>}
	 */
	private function editable( array $option ): array {
		$sizes  = isset( $option['sizes'] ) && is_array( $option['sizes'] ) ? $option['sizes'] : array();
		$styles = isset( $option['style_map'] ) && is_array( $option['style_map'] ) ? $option['style_map'] : array();
		$nums   = array();
		$names  = array();

		foreach ( $sizes as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name          = (string) $name;
			$nums[ $name ] = array(
				'width'    => (int) ( $row['width'] ?? 0 ),
				'height'   => (int) ( $row['height'] ?? 0 ),
				'mode'     => (string) ( $row['mode'] ?? '' ),
				'position' => (string) ( $row['position'] ?? '' ),
				'quality'  => (int) ( $row['quality'] ?? 0 ),
			);
			$names[ $name ] = (string) ( $row['style'] ?? $styles[ $name ] ?? '' );
		}

		ksort( $nums );
		ksort( $names );

		return array( $nums, $names );
	}

	/**
	 * Notice for a request this class refuses.
	 *
	 * @return array<string,string>
	 */
	private function failed(): array {
		return array(
			'tone'    => 'bad',
			'kicker'  => 'SETTINGS / ERROR',
			'title'   => __( '设置未保存', 'sakura-imgopt' ),
			'message' => __( '这一张卡不能单独保存，请刷新页面后重试。', 'sakura-imgopt' ),
		);
	}
}
