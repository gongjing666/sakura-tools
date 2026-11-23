<?php
/**
 * Payload for the system settings island.
 *
 * One builder feeds both the inline bootstrap printed by the view and the REST
 * route, so the first paint and a later fetch can never disagree about a value
 * or a translated string. React owns the markup; every sentence stays here,
 * where the translation catalogue already lives.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI\Modal;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the environment report and the layout options into one array.
 */
final class System_Payload {

	/**
	 * Build the payload.
	 *
	 * @param string $page Page id that owns the island.
	 * @return array<string, mixed>
	 */
	public static function build( string $page = 'system-settings' ): array {
		$report   = Environment_Report::collect();
		$php      = $report['php'];
		$wordpress = $report['wordpress'];
		$engine   = $report['engine'];
		$checks   = $report['checks'];
		$summary  = $report['summary'];

		$check_map = array();
		foreach ( $checks as $check ) {
			$check_map[ (string) $check['key'] ] = (string) $check['state'];
		}

		$labels = array(
			'ok'   => __( '通过', 'sakura-imgopt' ),
			'warn' => __( '建议', 'sakura-imgopt' ),
			'fail' => __( '需要处理', 'sakura-imgopt' ),
		);

		$tones = array(
			'ok'   => 'ok',
			'warn' => 'warn',
			'fail' => 'bad',
		);

		$tone_for = static function ( string $key ) use ( $check_map, $tones ): string {
			$state = $check_map[ $key ] ?? 'warn';

			return $tones[ $state ] ?? 'warn';
		};

		$label_for = static function ( string $key ) use ( $check_map, $labels ): string {
			$state = $check_map[ $key ] ?? 'warn';

			return $labels[ $state ] ?? $state;
		};

		$total = count( $checks );
		$score = (int) round(
			( ( (int) $summary['pass'] + (int) $summary['warn'] * 0.5 ) / max( 1, $total ) ) * 100
		);

		$memory_bytes = (int) $php['memory_limit_bytes'];
		$upload_bytes = (int) ( $php['upload_limit_bytes'] ?? 0 );
		$memory_warn  = $memory_bytes >= 0 && $memory_bytes < 256 * 1024 * 1024;
		$upload_warn  = $upload_bytes >= 0 && $upload_bytes < 32 * 1024 * 1024;

		$layout = self::layout_section( $page );

		$status_text = (int) $summary['fail'] > 0
			? __( '需要处理', 'sakura-imgopt' )
			: ( (int) $summary['warn'] > 0 ? __( '有建议', 'sakura-imgopt' ) : __( '环境可用', 'sakura-imgopt' ) );

		return array(
			'version' => defined( 'SAKURA_TOOLS_VERSION' ) ? (string) SAKURA_TOOLS_VERSION : '',
			'status'  => array(
				'label' => __( '环境自检', 'sakura-imgopt' ),
				'value' => (string) $summary['pass'] . '/' . (string) $total,
				'unit'  => __( '项通过', 'sakura-imgopt' ),
				'sub'   => sprintf(
					/* translators: 1: environment status, 2: PHP version */
					__( '%1$s · PHP %2$s', 'sakura-imgopt' ),
					$status_text,
					(string) $php['version']
				),
				'tone'  => (int) $summary['fail'] > 0 ? 'bad' : ( (int) $summary['warn'] > 0 ? 'warn' : 'ok' ),
			),
			'score'   => array(
				'label' => __( '环境评分', 'sakura-imgopt' ),
				'value' => $score,
				'unit'  => __( '分', 'sakura-imgopt' ),
				'tone'  => $score >= 80 ? 'ok' : ( $score >= 60 ? 'warn' : 'bad' ),
				'note'  => __( '通过项计满分，建议项计半分', 'sakura-imgopt' ),
				'aria'  => __( '环境评分', 'sakura-imgopt' ),
			),
			'kpis'    => array(
				array(
					'label' => __( '通过', 'sakura-imgopt' ),
					'value' => (string) $summary['pass'],
					'unit'  => __( '项', 'sakura-imgopt' ),
					'sub'   => sprintf(
						/* translators: %d: number of checks. */
						__( '共检测 %d 项', 'sakura-imgopt' ),
						$total
					),
				),
				array(
					'label' => __( '建议', 'sakura-imgopt' ),
					'value' => (string) $summary['warn'],
					'unit'  => __( '项', 'sakura-imgopt' ),
					'sub'   => __( '可优化但不阻断', 'sakura-imgopt' ),
				),
				array(
					'label' => __( '需要处理', 'sakura-imgopt' ),
					'value' => (string) $summary['fail'],
					'unit'  => __( '项', 'sakura-imgopt' ),
					'sub'   => __( '影响核心能力', 'sakura-imgopt' ),
				),
			),
			'cards'   => self::ordered(
				array(
					'runtime'    => array(
						'id'          => 'runtime',
						'kicker'      => 'Runtime',
						'title'       => __( '服务器运行环境', 'sakura-imgopt' ),
						'description' => __( 'PHP、Web 服务与 WordPress 的当前读数。', 'sakura-imgopt' ),
						'placement'   => self::placement( $page, 'runtime' ),
						'columns'     => array( __( '项目', 'sakura-imgopt' ), __( '当前值', 'sakura-imgopt' ) ),
						'rows'        => self::runtime_rows( $php, $wordpress, $tone_for, $memory_warn, $upload_warn ),
						'note'        => '',
					),
					'engine'     => array(
						'id'          => 'engine',
						'kicker'      => 'Image engine',
						'title'       => __( '图像引擎', 'sakura-imgopt' ),
						'description' => __( 'WordPress 当前可用的图像处理能力；Imagick 优先，GD 作为回退。', 'sakura-imgopt' ),
						'placement'   => self::placement( $page, 'engine' ),
						'columns'     => array( __( '项目', 'sakura-imgopt' ), __( '状态', 'sakura-imgopt' ) ),
						'rows'        => self::engine_rows( $report, $engine, $label_for, $tone_for ),
						'note'        => __( '支持格式来自当前图像编辑器；缺失 WebP / AVIF 时仍可处理常见格式，但无法输出对应格式。', 'sakura-imgopt' ),
					),
					'checks'     => array(
						'id'          => 'checks',
						'kicker'      => 'Checks',
						'title'       => __( '环境检查', 'sakura-imgopt' ),
						'description' => sprintf(
							/* translators: 1: passed checks, 2: total checks. */
							__( '按插件的实际依赖逐项核对：%1$d / %2$d 项通过。', 'sakura-imgopt' ),
							(int) $summary['pass'],
							$total
						),
						'placement'   => self::placement( $page, 'checks' ),
						'columns'     => array(
							__( '检查项', 'sakura-imgopt' ),
							__( '状态', 'sakura-imgopt' ),
							__( '当前值', 'sakura-imgopt' ),
							__( '说明', 'sakura-imgopt' ),
						),
						'rows'        => self::check_rows( $checks, $labels, $tones ),
						'note'        => '',
					),
					'extensions' => array(
						'id'          => 'extensions',
						'kicker'      => 'Extensions',
						'title'       => __( 'PHP 扩展', 'sakura-imgopt' ),
						'description' => __( '按加载状态标记；S3 上传依赖 cURL 与 OpenSSL。', 'sakura-imgopt' ),
						'placement'   => self::placement( $page, 'extensions' ),
						'items'       => array_map(
							static function ( array $extension ): array {
								return array(
									'name'   => (string) $extension['name'],
									'loaded' => (bool) $extension['loaded'],
								);
							},
							(array) $report['extensions']
						),
						'note'        => __( '图片处理依赖 Imagick 或 GD；缺失的扩展会在环境检查中单独标记。', 'sakura-imgopt' ),
					),
					'layout'     => $layout,
				),
				self::card_ids()
			),
			'rest'    => array(
				'url'   => esc_url_raw( rest_url( Rest\System_Controller::NS . '/system/layout' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			),
			'strings' => array(
				'close'        => __( '关闭', 'sakura-imgopt' ),
				'dismiss'      => __( '知道了', 'sakura-imgopt' ),
				'saving'       => __( '保存中…', 'sakura-imgopt' ),
				'closeIcon'    => Modal::close_icon(),
				'mascot'       => Modal::mascot(),
				'savedNotice'  => self::saved_notice(),
				'failedNotice' => array(
					'tone'    => 'bad',
					'kicker'  => 'SETTINGS / ERROR',
					'title'   => __( '设置未保存', 'sakura-imgopt' ),
					'message' => __( '保存失败，请稍后重试。', 'sakura-imgopt' ),
				),
			),
		);
	}

	/**
	 * The sentence shown after a successful save.
	 *
	 * Shared by the bootstrap payload and the REST response so the island never
	 * has to guess whether a save succeeded.
	 *
	 * @return array{tone:string,kicker:string,title:string,message:string}
	 */
	public static function saved_notice(): array {
		return array(
			'tone'    => 'ok',
			'kicker'  => 'SETTINGS / SAVED',
			'title'   => __( '设置已保存', 'sakura-imgopt' ),
			'message' => __( '页面宽度、间隔与卡片样式已应用到所有插件页面。', 'sakura-imgopt' ),
		);
	}

	/**
	 * The cards the island renders, in reading order.
	 *
	 * Kept as one list so LayoutTest can assert it against Layout::PAGES without
	 * rendering anything, and so the payload can never reorder the grid.
	 *
	 * @return array<int, string>
	 */
	public static function card_ids(): array {
		return array( 'runtime', 'engine', 'checks', 'extensions', 'layout' );
	}

	/**
	 * Sort an id-keyed map into the declared order.
	 *
	 * @param array<string, mixed> $cards Card definitions keyed by id.
	 * @param array<int, string>   $ids   Ids in reading order.
	 * @return array<string, mixed>
	 */
	private static function ordered( array $cards, array $ids ): array {
		$ordered = array();

		foreach ( $ids as $id ) {
			if ( isset( $cards[ $id ] ) ) {
				$ordered[ $id ] = $cards[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * The writable layout card plus the values behind it.
	 *
	 * @param string $page Page id.
	 * @return array<string, mixed>
	 */
	private static function layout_section( string $page ): array {
		$gaps       = Layout::gaps();
		$appearance = Appearance::get();
		$max_gap    = Layout::MAX_GAP;

		return array(
			'id'          => 'layout',
			'kicker'      => 'Layout & style',
			'title'       => __( '布局设置', 'sakura-imgopt' ),
			'description' => __( '所有插件页面共用页面宽度与卡片样式；背景由插件统一控制，保存后立即生效。', 'sakura-imgopt' ),
			'placement'   => self::placement( $page, 'layout' ),
			'note'        => __( '页面宽度、背景和卡片样式在所有插件页面生效；间隔只影响桌面多列网格，不会清理缓存。', 'sakura-imgopt' ),
			'save'        => __( '保存布局设置', 'sakura-imgopt' ),
			'fields'      => array(
				array(
					'group' => __( '卡片间隔', 'sakura-imgopt' ),
					'type'  => 'number',
					'id'    => 'sakura-gap-x',
					'key'   => 'gapX',
					'label' => __( '左右间隔', 'sakura-imgopt' ),
					'hint'  => sprintf(
						/* translators: %d: maximum gap in pixels. */
						__( '列与列之间 · 0–%d px', 'sakura-imgopt' ),
						$max_gap
					),
					'value'     => $gaps['gapX'],
					'min'       => 0,
					'max'       => $max_gap,
					'step'      => 1,
					'inputmode' => 'numeric',
				),
				array(
					'type'  => 'number',
					'id'    => 'sakura-gap-y',
					'key'   => 'gapY',
					'label' => __( '上下间隔', 'sakura-imgopt' ),
					'hint'  => sprintf(
						/* translators: %d: maximum gap in pixels. */
						__( '行与行之间 · 0–%d px', 'sakura-imgopt' ),
						$max_gap
					),
					'value'     => $gaps['gapY'],
					'min'       => 0,
					'max'       => $max_gap,
					'step'      => 1,
					'inputmode' => 'numeric',
				),
				array(
					'group' => __( '页面与卡片样式', 'sakura-imgopt' ),
					'type'  => 'number',
					'id'    => 'sakura-page-width',
					'key'   => 'page_width',
					'label' => __( '页面宽度', 'sakura-imgopt' ),
					'hint'  => sprintf(
						/* translators: 1: minimum width, 2: maximum width, 3: default width. */
						__( '内容区最大宽度 · %1$d–%2$d px（默认 %3$d）', 'sakura-imgopt' ),
						Appearance::MIN_WIDTH,
						Appearance::MAX_WIDTH,
						(int) Appearance::DEFAULTS['page_width']
					),
					'value'     => $appearance['page_width'],
					'min'       => Appearance::MIN_WIDTH,
					'max'       => Appearance::MAX_WIDTH,
					'step'      => 10,
					'inputmode' => 'numeric',
				),
				array(
					'type'  => 'number',
					'id'    => 'sakura-card-border',
					'key'   => 'border_width',
					'label' => __( '边框粗细', 'sakura-imgopt' ),
					'hint'  => sprintf(
						/* translators: %d: maximum border width in pixels. */
						__( '0–%d px，0 表示无边框。', 'sakura-imgopt' ),
						Appearance::MAX_BORDER
					),
					'value'     => $appearance['border_width'],
					'min'       => 0,
					'max'       => Appearance::MAX_BORDER,
					'step'      => 1,
					'inputmode' => 'numeric',
				),
				array(
					'type'  => 'number',
					'id'    => 'sakura-card-radius',
					'key'   => 'radius',
					'label' => __( '圆角大小', 'sakura-imgopt' ),
					'hint'  => sprintf(
						/* translators: %d: maximum card radius in pixels. */
						__( '0–%d px。', 'sakura-imgopt' ),
						Appearance::MAX_RADIUS
					),
					'value'     => $appearance['radius'],
					'min'       => 0,
					'max'       => Appearance::MAX_RADIUS,
					'step'      => 1,
					'inputmode' => 'numeric',
				),
				array(
					'type'    => 'select',
					'id'      => 'sakura-card-shadow',
					'key'     => 'shadow',
					'label'   => __( '边框阴影', 'sakura-imgopt' ),
					'hint'    => __( '只表达层级，不改变卡片边框。', 'sakura-imgopt' ),
					'value'   => $appearance['shadow'],
					'options' => array(
						array( 'value' => 'default', 'label' => __( '默认', 'sakura-imgopt' ) ),
						array( 'value' => 'none', 'label' => __( '无', 'sakura-imgopt' ) ),
						array( 'value' => 'soft', 'label' => __( '轻', 'sakura-imgopt' ) ),
						array( 'value' => 'strong', 'label' => __( '强', 'sakura-imgopt' ) ),
					),
				),
			),
		);
	}

	/**
	 * Server-resolved placement, so a card never moves after hydration.
	 *
	 * @param string $page Page id.
	 * @param string $card Card id.
	 * @return array<int, int>|null Row, column, span.
	 */
	private static function placement( string $page, string $card ): ?array {
		return Layout::card( Layout::layout_for_page( $page ), $card );
	}

	/**
	 * Runtime rows.
	 *
	 * @param array<string, mixed> $php         PHP report.
	 * @param array<string, mixed> $wordpress   WordPress report.
	 * @param callable             $tone_for    State tone resolver.
	 * @param bool                 $memory_warn Whether memory deserves a hint.
	 * @param bool                 $upload_warn Whether the upload limit deserves a hint.
	 * @return array<int, array<string, mixed>>
	 */
	private static function runtime_rows( array $php, array $wordpress, callable $tone_for, bool $memory_warn, bool $upload_warn ): array {
		$rows = array(
			array(
				'label'  => __( 'PHP 版本', 'sakura-imgopt' ),
				'value'  => (string) $php['version'],
				'muted'  => '',
				'badges' => array(
					array(
						version_compare( (string) $php['version'], '8.2', '>=' ) ? __( '满足 8.2+', 'sakura-imgopt' ) : __( '低于 8.2', 'sakura-imgopt' ),
						$tone_for( 'php' ),
					),
				),
			),
			array(
				'label'  => __( '运行方式', 'sakura-imgopt' ),
				'value'  => (string) $php['sapi_label'],
				'muted'  => (string) $php['sapi'],
				'badges' => array(),
			),
			array(
				'label'  => __( 'Web 服务器', 'sakura-imgopt' ),
				'value'  => '' !== (string) $php['server_software'] ? (string) $php['server_software'] : __( '未知', 'sakura-imgopt' ),
				'muted'  => '',
				'badges' => array(),
			),
			array(
				'label'  => __( '内存上限', 'sakura-imgopt' ),
				'value'  => self::limit( (string) $php['memory_limit'] ),
				'muted'  => '',
				'badges' => $memory_warn ? array( array( __( '建议 256M+', 'sakura-imgopt' ), 'warn' ) ) : array(),
			),
			array(
				'label'  => __( '最大执行时间', 'sakura-imgopt' ),
				'value'  => self::seconds( (string) $php['max_execution_time'] ),
				'muted'  => '',
				'badges' => array(),
			),
			array(
				'label'  => __( '上传文件上限', 'sakura-imgopt' ),
				'value'  => self::limit( (string) $php['upload_limit'] ),
				'muted'  => '',
				'badges' => $upload_warn ? array( array( __( '建议 32M+', 'sakura-imgopt' ), 'warn' ) ) : array(),
			),
			array(
				'label'  => __( 'POST 上限', 'sakura-imgopt' ),
				'value'  => self::limit( (string) $php['post_limit'] ),
				'muted'  => '',
				'badges' => array(),
			),
			array(
				'label'  => __( 'OPcache', 'sakura-imgopt' ),
				'value'  => '',
				'muted'  => '',
				'badges' => array( array( (string) $php['opcache']['label'], $tone_for( 'opcache' ) ) ),
			),
			array(
				'label'  => __( 'WordPress', 'sakura-imgopt' ),
				'value'  => (string) $wordpress['version'],
				'muted'  => (string) $wordpress['environment'],
				'badges' => array(),
			),
			array(
				'label'  => __( '数据库', 'sakura-imgopt' ),
				'value'  => '' !== (string) $wordpress['db_version'] ? (string) $wordpress['db_version'] : __( '未知', 'sakura-imgopt' ),
				'muted'  => __( 'MySQL / MariaDB', 'sakura-imgopt' ),
				'badges' => array(),
			),
			array(
				'label'  => __( '时区', 'sakura-imgopt' ),
				'value'  => '' !== (string) $wordpress['timezone'] ? (string) $wordpress['timezone'] : __( '未知', 'sakura-imgopt' ),
				'muted'  => '',
				'badges' => array(),
			),
		);

		return $rows;
	}

	/**
	 * Engine rows.
	 *
	 * @param array<string, mixed> $report   Full report.
	 * @param array<string, mixed> $engine   Engine report.
	 * @param callable             $label_for State label resolver.
	 * @param callable             $tone_for  State tone resolver.
	 * @return array<int, array<string, mixed>>
	 */
	private static function engine_rows( array $report, array $engine, callable $label_for, callable $tone_for ): array {
		$formats = array();
		foreach ( (array) $engine['formats'] as $format => $supported ) {
			$formats[] = array( (string) $format, $supported ? 'ok' : 'warn' );
		}

		return array(
			array(
				'label'  => __( '首选编辑器', 'sakura-imgopt' ),
				'value'  => (string) $engine['primary'],
				'muted'  => '',
				'badges' => array( array( $label_for( 'engine' ), $tone_for( 'engine' ) ) ),
			),
			array(
				'label'  => 'Imagick',
				'value'  => '' !== (string) $engine['imagick'] ? (string) $engine['imagick'] : __( '未安装', 'sakura-imgopt' ),
				'muted'  => '',
				'badges' => array(
					array(
						'' !== (string) $engine['imagick'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ),
						'' !== (string) $engine['imagick'] ? 'ok' : 'warn',
					),
				),
			),
			array(
				'label'  => 'GD',
				'value'  => '' !== (string) $engine['gd'] ? (string) $engine['gd'] : __( '未安装', 'sakura-imgopt' ),
				'muted'  => '',
				'badges' => array(
					array(
						'' !== (string) $engine['gd'] ? __( '可用', 'sakura-imgopt' ) : __( '不可用', 'sakura-imgopt' ),
						'' !== (string) $engine['gd'] ? 'ok' : 'warn',
					),
				),
			),
			array(
				'label'  => 'WebP',
				'value'  => '',
				'muted'  => '',
				'badges' => array( array( ! empty( $report['formats']['webp'] ) ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), $tone_for( 'webp' ) ) ),
			),
			array(
				'label'  => 'AVIF',
				'value'  => '',
				'muted'  => '',
				'badges' => array( array( ! empty( $report['formats']['avif'] ) ? __( '支持', 'sakura-imgopt' ) : __( '不支持', 'sakura-imgopt' ), $tone_for( 'avif' ) ) ),
			),
			array(
				'label'  => __( '支持格式', 'sakura-imgopt' ),
				'value'  => '',
				'muted'  => '',
				'badges' => $formats,
			),
		);
	}

	/**
	 * Check rows.
	 *
	 * @param array<int, array<string, mixed>> $checks    Checks.
	 * @param array<string, string>            $labels    State labels.
	 * @param array<string, string>            $tones     State tones.
	 * @return array<int, array<string, mixed>>
	 */
	private static function check_rows( array $checks, array $labels, array $tones ): array {
		$rows = array();

		foreach ( $checks as $check ) {
			$state = (string) $check['state'];

			$rows[] = array(
				'label'      => (string) $check['label'],
				'stateLabel' => $labels[ $state ] ?? $state,
				'tone'       => $tones[ $state ] ?? 'warn',
				'value'      => (string) $check['value'],
				'note'       => (string) $check['note'],
			);
		}

		return $rows;
	}

	/**
	 * Format a byte-limit string the way the old view did.
	 *
	 * @param string $value Raw ini value.
	 * @return string
	 */
	private static function limit( string $value ): string {
		if ( '' === $value ) {
			return __( '未知', 'sakura-imgopt' );
		}

		if ( '-1' === $value ) {
			return __( '不限制', 'sakura-imgopt' );
		}

		return $value;
	}

	/**
	 * Format a seconds value.
	 *
	 * @param string $value Raw ini value.
	 * @return string
	 */
	private static function seconds( string $value ): string {
		if ( '' === $value ) {
			return __( '未知', 'sakura-imgopt' );
		}

		if ( '0' === $value ) {
			return __( '不限制', 'sakura-imgopt' );
		}

		return sprintf( /* translators: %s: seconds. */ __( '%s 秒', 'sakura-imgopt' ), $value );
	}
}
