<?php
/**
 * Read-only image data shared by the PHP renderer and the React island.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Image\Crop_Config_Scanner;
use Sakura\ImageOptimizer\Image\Size_Catalog;
use Sakura\ImageOptimizer\Thumbnail\Thumbnail_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the numbers and rows the image screens display.
 *
 * The rules screen used to compute these inside its Settings API callbacks;
 * the React island needs the same facts as data, so they live here and both
 * renderers read one implementation.
 */
final class Image_Report {

	/**
	 * Tone per scan status. Mirrors the classes the PHP table used.
	 *
	 * @var array<string,string>
	 */
	private const TONE_BY_STATUS = array(
		'enabled'           => 'ok',
		'disabled_by_plugin' => 'neutral',
		'removed'           => 'warn',
		'filtered'          => 'warn',
		'unregistered'      => 'bad',
		'unknown'           => 'bad',
	);

	/**
	 * Best available image engine, as the header pill shows it.
	 *
	 * @return string
	 */
	public static function engine_label(): string {
		static $cached = null;
		if ( is_string( $cached ) ) {
			return $cached;
		}

		if ( extension_loaded( 'imagick' ) ) {
			try {
				$imagick = new \Imagick();
				$formats = $imagick->queryFormats();
				if ( in_array( 'WEBP', $formats, true ) || in_array( 'AVIF', $formats, true ) ) {
					$cached = __( 'Imagick（推荐）', 'sakura-imgopt' );
					return $cached;
				}
			} catch ( \Exception $e ) {
				do_action( 'sakura_imgopt_imagick_probe_failed', $e );
			}
		}

		$cached = extension_loaded( 'gd' ) ? __( 'GD（备用）', 'sakura-imgopt' ) : __( '没有可用的图片组件', 'sakura-imgopt' );

		return $cached;
	}

	/**
	 * Image sizes WordPress knows about, before filtering.
	 *
	 * A disabled size has to stay visible or the user can never tick it back,
	 * so this deliberately avoids wp_get_registered_image_subsizes(), which
	 * runs through intermediate_image_sizes filters.
	 *
	 * @return array<string,array{width:int,height:int,crop:bool}>
	 */
	public static function sizes(): array {
		global $_wp_additional_image_sizes;

		$sizes = array();
		$core  = array(
			'thumbnail'    => array( (int) get_option( 'thumbnail_size_w', 150 ), (int) get_option( 'thumbnail_size_h', 150 ), (bool) get_option( 'thumbnail_crop', false ) ),
			'medium'       => array( (int) get_option( 'medium_size_w', 300 ), (int) get_option( 'medium_size_h', 300 ), false ),
			'medium_large' => array( (int) get_option( 'medium_large_size_w', 768 ), (int) get_option( 'medium_large_size_h', 0 ), false ),
			'large'        => array( (int) get_option( 'large_size_w', 1024 ), (int) get_option( 'large_size_h', 1024 ), false ),
			'1536x1536'    => array( 1536, 1536, false ),
			'2048x2048'    => array( 2048, 2048, false ),
		);

		foreach ( $core as $name => $data ) {
			if ( $data[0] > 0 || $data[1] > 0 ) {
				$sizes[ $name ] = array(
					'width'  => $data[0],
					'height' => $data[1],
					'crop'   => $data[2],
				);
			}
		}

		if ( is_array( $_wp_additional_image_sizes ) ) {
			foreach ( $_wp_additional_image_sizes as $name => $data ) {
				$sizes[ (string) $name ] = array(
					'width'  => isset( $data['width'] ) ? (int) $data['width'] : 0,
					'height' => isset( $data['height'] ) ? (int) $data['height'] : 0,
					'crop'   => ! empty( $data['crop'] ),
				);
			}
		}

		return $sizes;
	}

	/**
	 * Stored scan result, when one has run.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function scan(): ?array {
		$scan = Crop_Config_Scanner::stored();

		return is_array( $scan ) ? $scan : null;
	}

	/**
	 * Rows for the size table: runtime sizes, last scan, disabled flags.
	 *
	 * @param array<int,string>        $disabled Disabled size names.
	 * @param array<string,mixed>|null $scan     Stored scan result.
	 * @return array<int,array<string,mixed>>
	 */
	public static function size_rows( array $disabled, ?array $scan ): array {
		$available = self::sizes();
		$scan_rows = ( is_array( $scan ) && isset( $scan['sizes'] ) && is_array( $scan['sizes'] ) ) ? $scan['sizes'] : array();
		$names     = array_keys( $available );

		// Sizes removed from the theme or a plugin stay listed, otherwise their
		// disable flag could never be cleared.
		foreach ( array_merge( Crop_Config_Scanner::stored_names(), $disabled ) as $stored ) {
			$stored = Size_Catalog::sanitize_name( (string) $stored );
			if ( '' !== $stored && ! in_array( $stored, $names, true ) ) {
				$names[] = $stored;
			}
		}

		$rows = array();
		foreach ( $names as $name ) {
			if ( '_big_threshold' === $name ) {
				continue;
			}

			$info        = $available[ $name ] ?? null;
			$scan_row    = $scan_rows[ $name ] ?? null;
			$is_disabled = in_array( $name, $disabled, true );
			$width       = null !== $info ? $info['width'] : (int) ( $scan_row['width'] ?? 0 );
			$height      = null !== $info ? $info['height'] : (int) ( $scan_row['height'] ?? 0 );
			$crop        = null !== $info ? (bool) $info['crop'] : ! empty( $scan_row['crop'] );

			$status       = 'enabled';
			$status_label = __( '正常生成', 'sakura-imgopt' );
			$reason       = '';
			$sources      = array();

			if ( is_array( $scan_row ) ) {
				$reason  = (string) ( $scan_row['reason'] ?? '' );
				$sources = isset( $scan_row['sources'] ) && is_array( $scan_row['sources'] ) ? $scan_row['sources'] : array();
			}

			if ( $is_disabled ) {
				$status       = 'disabled_by_plugin';
				$status_label = __( '已禁止', 'sakura-imgopt' );
			} elseif ( is_array( $scan_row ) ) {
				$status       = (string) ( $scan_row['status'] ?? 'unknown' );
				$status_label = (string) ( $scan_row['status_label'] ?? $status_label );

				if ( 'disabled_by_plugin' === $status ) {
					// Same staleness as the big row: the snapshot remembered our
					// own checkbox, which has since been cleared.
					$status       = 'enabled';
					$status_label = __( '正常生成', 'sakura-imgopt' );
					$reason       = '';
				}
			}

			$rows[] = array(
				'name'         => $name,
				'kind'         => 'size',
				'width'        => $width,
				'height'       => $height,
				'crop'         => $crop,
				'disabled'     => $is_disabled,
				'status'       => $status,
				'tone'         => self::tone( $status ),
				'status_label' => $status_label,
				'reason'       => $reason,
				'sources'      => self::source_list( $sources, $is_disabled, $name, is_array( $scan_row ) && ! empty( $scan_row['registered'] ) ),
				'conflict'     => is_array( $scan_row ) && ! empty( $scan_row['conflict'] ),
			);
		}

		$big = array();
		if ( is_array( $scan ) && isset( $scan['big_threshold'] ) && is_array( $scan['big_threshold'] ) ) {
			$big = $scan['big_threshold'];
		}

		// The scan is a snapshot: its big_threshold.disabled was captured when the
		// operator last pressed 扫描裁剪配置, so using it here would make a fresh
		// save read back as its old value until the next scan. Our own switch is
		// the live option; the snapshot only tells us how WordPress itself behaves.
		$big_ours     = in_array( '_big_threshold', $disabled, true );
		$big_disabled = $big_ours;
		$big_status   = $big_ours ? 'disabled_by_plugin' : 'enabled';
		$big_label    = $big_ours
			? __( '已禁止', 'sakura-imgopt' )
			: ( 'enabled' === (string) ( $big['status'] ?? '' ) && ! empty( $big['status_label'] )
				? (string) $big['status_label']
				: __( '正常启用', 'sakura-imgopt' ) );

		$rows[] = array(
			'name'         => '_big_threshold',
			'kind'         => 'big',
			'width'        => 0,
			'height'       => 0,
			'crop'         => false,
			'disabled'     => $big_disabled,
			'status'       => $big_status,
			'tone'         => self::tone( $big_status ),
			'status_label' => $big_label,
			'reason'       => $big_ours ? '' : (string) ( $big['reason'] ?? '' ),
			'sources'      => array(
				array(
					'label'  => __( 'WordPress 自带规则', 'sakura-imgopt' ),
					'detail' => '',
				),
			),
			'conflict'     => false,
		);

		return $rows;
	}

	/**
	 * Human-readable scan summary shown next to the scan button.
	 *
	 * @param array<string,mixed>|null $scan Stored scan.
	 * @return string
	 */
	public static function scan_summary_text( ?array $scan ): string {
		if ( ! is_array( $scan ) ) {
			return __( '尚未扫描', 'sakura-imgopt' );
		}

		$summary = isset( $scan['summary'] ) && is_array( $scan['summary'] ) ? $scan['summary'] : array();
		$pending = (int) ( $summary['unregistered'] ?? 0 ) + (int) ( $summary['filtered'] ?? 0 ) + (int) ( $summary['unknown'] ?? 0 );

		$text = sprintf(
			/* translators: 1: scan time, 2: duration in ms, 3: total, 4: enabled, 5: disabled, 6: removed, 7: pending, 8: conflicts */
			__( '上次扫描：%1$s（%2$d ms）· 尺寸 %3$d：正常 %4$d / 已禁止 %5$d / 已移除 %6$d / 待核实 %7$d / 冲突 %8$d', 'sakura-imgopt' ),
			wp_date( 'Y-m-d H:i:s', (int) ( $scan['generated_at'] ?? time() ) ),
			(int) ( $scan['duration_ms'] ?? 0 ),
			(int) ( $summary['total'] ?? 0 ),
			(int) ( $summary['enabled'] ?? 0 ),
			(int) ( $summary['disabled'] ?? 0 ),
			(int) ( $summary['removed'] ?? 0 ),
			$pending,
			(int) ( $summary['conflicts'] ?? 0 )
		);

		$theme = isset( $scan['theme'] ) && is_array( $scan['theme'] ) ? $scan['theme'] : array();
		$text .= sprintf(
			/* translators: %d: files scanned in the active theme */
			__( ' · 主题 %d 个文件', 'sakura-imgopt' ),
			(int) ( $theme['files_scanned'] ?? 0 )
		);

		$plugins      = isset( $scan['plugins'] ) && is_array( $scan['plugins'] ) ? $scan['plugins'] : array();
		$plugin_files = 0;
		foreach ( $plugins as $plugin ) {
			if ( is_array( $plugin ) ) {
				$plugin_files += (int) ( $plugin['files_scanned'] ?? 0 );
			}
		}

		$text .= sprintf(
			/* translators: 1: number of plugins, 2: files scanned across plugins */
			__( ' · 插件 %1$d 个（%2$d 个文件）', 'sakura-imgopt' ),
			count( $plugins ),
			$plugin_files
		);

		return $text;
	}

	/**
	 * Compact wp-config.php note under the table.
	 *
	 * @param array<string,mixed>|null $scan Stored scan.
	 * @return string
	 */
	public static function wp_config_text( ?array $scan ): string {
		if ( ! is_array( $scan ) ) {
			return __( 'wp-config.php：尚未扫描。', 'sakura-imgopt' );
		}

		$config = isset( $scan['wp_config'] ) && is_array( $scan['wp_config'] ) ? $scan['wp_config'] : array();
		$path   = (string) ( $config['path'] ?? '' );
		$where  = ( '' === $path || 'wp-config.php' === $path ) ? 'wp-config.php' : sprintf( '%s（%s）', 'wp-config.php', $path );

		if ( empty( $config['readable'] ) ) {
			return sprintf(
				/* translators: %s: wp-config.php label/path. */
				__( '%s：未找到或不可读，已跳过常量扫描。', 'sakura-imgopt' ),
				$where
			);
		}

		$constants = isset( $config['constants'] ) && is_array( $config['constants'] ) ? $config['constants'] : array();
		$defined   = array();
		foreach ( $constants as $constant ) {
			if ( is_array( $constant ) && ! empty( $constant['defined'] ) ) {
				$defined[] = sprintf( '%s=%s', (string) ( $constant['name'] ?? '' ), (string) ( $constant['display'] ?? '' ) );
			}
		}

		return sprintf(
			/* translators: 1: wp-config label/path, 2: total constants, 3: defined count, 4: defined values or default note */
			__( '%1$s：相关常量 %2$d 个，已定义 %3$d 个%4$s', 'sakura-imgopt' ),
			$where,
			count( $constants ),
			count( $defined ),
			empty( $defined ) ? __( '（均使用默认值）', 'sakura-imgopt' ) : '（' . implode( '、', $defined ) . '）'
		);
	}

	/**
	 * Tone for a scan status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function tone( string $status ): string {
		return self::TONE_BY_STATUS[ $status ] ?? 'bad';
	}

	/**
	 * Normalised source labels plus the fallback sentence.
	 *
	 * @param array<int,array<string,mixed>> $sources  Scan sources.
	 * @param bool                           $disabled Whether the size is disabled.
	 * @param string                         $name     Size name.
	 * @param bool                           $registered Registered at runtime.
	 * @return array<int,array{label:string,detail:string}>
	 */
	private static function source_list( array $sources, bool $disabled, string $name, bool $registered ): array {
		$list  = array();
		$known = array();

		foreach ( $sources as $source ) {
			$label = (string) ( $source['label'] ?? '' );
			if ( '' === $label || isset( $known[ $label ] ) ) {
				continue;
			}
			$known[ $label ] = true;
			$list[]          = array(
				'label'  => $label,
				'detail' => (string) ( $source['detail'] ?? '' ),
			);
		}

		if ( array() !== $list ) {
			return $list;
		}

		if ( $registered ) {
			return array(
				array(
					'label'  => __( '运行中已注册，未定位静态来源', 'sakura-imgopt' ),
					'detail' => '',
				),
			);
		}

		if ( 'large' === $name ) {
			return array(
				array(
					'label'  => __( 'WordPress 核心（设置中已关闭）', 'sakura-imgopt' ),
					'detail' => '',
				),
			);
		}

		return array(
			array(
				'label'  => $disabled ? '—' : __( '未扫描', 'sakura-imgopt' ),
				'detail' => '',
			),
		);
	}
}
