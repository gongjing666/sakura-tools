<?php
/**
 * One health snapshot for the file-space settings screen.
 *
 * The settings page and the Zibll tab read the same bridge/capability state;
 * this helper keeps that snapshot in one place instead of rebuilding a second
 * status source inside the page payload.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Frontend\Entry;
use Nboke\UserFiles\Services\Zibll_Bridge;
use Nboke\UserFiles\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge and front-end entry health.
 */
final class Health {

	/**
	 * Per-request memo.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Full snapshot used by the page head and the settings payload.
	 *
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$bridge      = Zibll_Bridge::instance();
		$available   = $bridge->available();
		$capabilities = $bridge->capabilities();
		$total       = count( $capabilities );
		$ready       = count( array_filter( $capabilities ) );
		$theme       = $bridge->theme();
		$entry       = ( new Entry() )->status();
		$enabled     = Settings::entry_enabled();

		$theme_name = isset( $theme['name'] ) && '' !== (string) $theme['name']
			? (string) $theme['name']
			: __( '未识别主题', 'sakura-imgopt' );
		$entry_name = isset( $entry['label'] ) && '' !== (string) $entry['label']
			? (string) $entry['label']
			: __( '未检测到', 'sakura-imgopt' );

		$tone = $ready === $total ? 'ok' : ( $ready >= 5 ? 'warn' : 'bad' );
		if ( ! $available ) {
			$tone = 'bad';
		}

		$entry_text = $enabled
			? sprintf(
				/* translators: %s: detected front-end entry label. */
				__( '前台入口已开启（%s）', 'sakura-imgopt' ),
				$entry_name
			)
			: __( '前台入口未开启', 'sakura-imgopt' );

		self::$cache = array(
			'available'   => $available,
			'ready'       => $ready,
			'total'       => $total,
			'tone'        => $tone,
			'theme'       => $theme_name,
			'stylesheet'  => isset( $theme['stylesheet'] ) ? (string) $theme['stylesheet'] : '',
			'value'       => $available ? $ready . ' / ' . $total : __( '不可用', 'sakura-imgopt' ),
			'unit'        => $available ? __( '项能力', 'sakura-imgopt' ) : '',
			'sub'         => $theme_name . ' · ' . $entry_text,
			'entryEnabled' => $enabled,
			'entryLabel'  => $entry_name,
			'entryGeneric' => ! empty( $entry['generic'] ),
			'entryUrl'    => isset( $entry['url'] ) ? (string) $entry['url'] : '',
		);

		return self::$cache;
	}

	/**
	 * The compact shape UI::page() expects for its status card.
	 *
	 * @return array{label:string,value:string,unit:string,sub:string,tone:string}
	 */
	public static function status(): array {
		$snapshot = self::snapshot();

		return array(
			'label' => __( '子比桥接', 'sakura-imgopt' ),
			'value' => (string) $snapshot['value'],
			'unit'  => (string) $snapshot['unit'],
			'sub'   => (string) $snapshot['sub'],
			'tone'  => (string) $snapshot['tone'],
		);
	}
}
