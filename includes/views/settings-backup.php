<?php
/**
 * Backup management page.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var string $backup_group Backup option group.
 * @var string $backup_slug Backup settings page slug.
 * @var \Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface $backup Backup repository.
 */

use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$usage = $backup->get_usage();

$probe       = \Sakura\ImageOptimizer\Backup\Exposure_Probe::latest();
$probe_time  = is_array( $probe ) && isset( $probe['time'] ) ? (int) $probe['time'] : 0;
$probe_fresh = $probe_time > 0 && ( time() - $probe_time ) < DAY_IN_SECONDS;
$probe_state = $probe_fresh && isset( $probe['state'] ) ? (string) $probe['state'] : '';
$probe_tone  = 'exposed' === $probe_state ? 'bad' : ( 'protected' === $probe_state ? 'ok' : 'neutral' );
if ( $probe_fresh && isset( $probe['message'] ) ) {
	$probe_message = sprintf(
		/* translators: 1: detection time, 2: detection result. */
		__( '上次检测（%1$s）：%2$s', 'sakura-imgopt' ),
		wp_date( 'Y-m-d H:i', $probe_time ),
		(string) $probe['message']
	);
} else {
	$probe_message = __( '尚未检测。点击下面的按钮，服务器会实际请求备份目录里的一个文件来判断。', 'sakura-imgopt' );
}
$probe_server = \Sakura\ImageOptimizer\Backup\Exposure_Probe::server();
$upload_info  = wp_upload_dir();
$backup_path  = (string) wp_parse_url( trailingslashit( rtrim( (string) $upload_info['baseurl'], '/' ) . '/' . \Sakura\ImageOptimizer\Backup::DIR ), PHP_URL_PATH );

$retention_days = (int) get_option( 'sakura_imgopt_backup_retention_days', 0 );
$max_mb         = (int) get_option( 'sakura_imgopt_backup_max_mb', 0 );
$delete_data    = (int) get_option( 'sakura_imgopt_delete_data_on_uninstall', 0 );

$checkbox_control = static function ( string $name, string $text, bool $checked ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
	echo '<label class="check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ' />';
	echo '<span class="check__box" aria-hidden="true"></span><span class="check__text">' . esc_html( $text ) . '</span></label>';
};

UI::page(
	array(
		'id'      => 'image-backup',
		'module'  => 'image',
		'tab'     => 'backup',
		'eyebrow' => 'IMAGE RECOVERY / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '备份管理', 'sakura-imgopt' ),
		'lede'    => __( '管理优化前的原图快照与自动清理策略。清空备份后，已优化图片将无法一键还原。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '原图备份', 'sakura-imgopt' ),
			'value'  => number_format_i18n( $usage['count'] ),
			'unit'   => __( '个文件', 'sakura-imgopt' ),
			'sub'    => size_format( $usage['bytes'], 2 ),
			'id'     => 'sakura-backup-hero-count',
			'sub_id' => 'sakura-backup-hero-size',
			'tone'  => 'neutral',
		),
	),
	static function () use ( $usage, $backup_path, $probe_server, $probe_message, $probe_tone, $retention_days, $max_mb, $delete_data, $checkbox_control, $backup_group ) {
		?>
		<div class="card-grid" data-sakura-layout="backup" data-sakura-group="main">
			<?php
			UI::card(
				array(
					'id'          => 'usage',
					'kicker'      => 'Storage',
					'title'       => __( '占用概览', 'sakura-imgopt' ),
					'description' => __( '备份用于「一键还原」，清空后已优化图片将无法还原为原图。', 'sakura-imgopt' ),
					'actions'     => array(
						array(
							'label'   => __( '刷新', 'sakura-imgopt' ),
							'url'     => '#',
							'variant' => 'ghost',
							'id'      => 'sakura-refresh-backup',
							'attrs'   => array(
								'data-sakura-backup-action' => 'sakura_refresh_backup',
								'data-sakura-nonce'         => wp_create_nonce( 'sakura_refresh_backup' ),
								'title'                     => __( '刷新占用数据', 'sakura-imgopt' ),
							),
						),
					),
				),
				static function () use ( $usage ) {
					?>
					<div class="kpis">
						<div class="kpi">
							<span class="kpi__label"><?php echo esc_html__( '占用空间', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value" id="sakura-backup-size"><?php echo esc_html( size_format( $usage['bytes'], 2 ) ); ?></strong>
						</div>
						<div class="kpi">
							<span class="kpi__label"><?php echo esc_html__( '备份文件', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value" id="sakura-backup-count"><?php echo esc_html( number_format_i18n( $usage['count'] ) ); ?></strong>
						</div>
					</div>
					<div class="button-row">
						<button type="button" class="btn btn--small" id="sakura-clean-expired" data-sakura-backup-action="sakura_clean_expired_backup" data-sakura-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_clean_expired_backup' ) ); ?>"><?php echo esc_html__( '立即清理过期备份', 'sakura-imgopt' ); ?></button>
						<button type="button" class="btn btn--small btn--danger" id="sakura-purge-backup" data-sakura-backup-action="sakura_purge_backup" data-sakura-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_purge_backup' ) ); ?>"><?php echo esc_html__( '清空全部备份', 'sakura-imgopt' ); ?></button>
						<span class="sakura-backup-msg" id="sakura-purge-msg" role="status" aria-live="polite"></span>
					</div>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'policy',
					'kicker'      => 'Security',
					'title'       => __( '备份目录访问保护', 'sakura-imgopt' ),
					'description' => __( '如果别人能直接从网上把备份的原图下载走，优化就白做了。插件会自动加一层访问限制，但只对 Apache 服务器有效。', 'sakura-imgopt' ),
				),
				static function () use ( $probe_server, $probe_message, $probe_tone, $backup_path ) {
					?>
					<div class="kpis">
						<div class="kpi">
							<span class="kpi__label"><?php echo esc_html__( '当前服务器', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value"><?php echo esc_html( (string) $probe_server['label'] ); ?></strong>
							<span class="kpi__sub"><?php echo esc_html( $probe_server['reads_htaccess'] ? __( '会自动拦截', 'sakura-imgopt' ) : __( '不会自动拦截，需要在站点配置里加规则', 'sakura-imgopt' ) ); ?></span>
						</div>
						<div class="kpi">
							<span class="kpi__label"><?php echo esc_html__( '目录保护', 'sakura-imgopt' ); ?></span>
							<strong class="kpi__value"><?php echo 'bad' === $probe_tone ? esc_html__( '可被公网访问', 'sakura-imgopt' ) : ( 'ok' === $probe_tone ? esc_html__( '已拦截', 'sakura-imgopt' ) : esc_html__( '待检测', 'sakura-imgopt' ) ); ?></strong>
							<span class="kpi__sub"><?php echo esc_html__( '建议每月检测一次', 'sakura-imgopt' ); ?></span>
						</div>
					</div>
					<p class="sakura-backup-probe is-<?php echo esc_attr( $probe_tone ); ?>" id="sakura-probe-state"><?php echo esc_html( $probe_message ); ?></p>
					<p class="field__hint"><?php echo esc_html__( 'Nginx、OpenResty、IIS、Caddy 等服务器，请在站点配置里拒绝这个路径：', 'sakura-imgopt' ); ?></p>
					<pre class="code-block">location ^~ <?php echo esc_html( untrailingslashit( $backup_path ) ); ?>/ { deny all; }</pre>
					<div class="button-row">
						<button type="button" class="btn btn--small" id="sakura-probe-backup" data-sakura-backup-action="sakura_probe_backup" data-sakura-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_probe_backup' ) ); ?>"><?php echo esc_html__( '检测是否可被公网访问', 'sakura-imgopt' ); ?></button>
						<span class="sakura-backup-msg" id="sakura-probe-msg" role="status" aria-live="polite"></span>
					</div>
					<?php
				}
			);

			UI::card(
				array(
					'id'          => 'access',
					'kicker'      => 'Policy',
					'title'       => __( '自动清理策略', 'sakura-imgopt' ),
					'description' => __( '留空或填 0 表示不限制。', 'sakura-imgopt' ),
					'form'        => array(
						'id'           => 'sakura-backup-policy-form',
						'option_group' => $backup_group,
						'markers'      => 'fields',
						'marker_card'  => 'backup_policy',
					),
					'save'        => __( '保存清理策略', 'sakura-imgopt' ),
				),
				static function () use ( $retention_days, $max_mb, $delete_data, $checkbox_control ) {
					?>
					<div class="field-list">
						<?php
						UI::field(
							array(
								'type'        => 'number',
								'name'        => 'sakura_imgopt_backup_retention_days',
								'id'          => 'sakura_imgopt_backup_retention_days',
								'label'       => __( '保留天数', 'sakura-imgopt' ),
								'value'       => (string) $retention_days,
								'min'         => 0,
								'step'        => 1,
								'input_class' => 'small-text',
								'hint'        => __( '超过这个天数的备份会被每天自动删除。填 0 表示永久保留。', 'sakura-imgopt' ),
							)
						);
						UI::field(
							array(
								'type'        => 'number',
								'name'        => 'sakura_imgopt_backup_max_mb',
								'id'          => 'sakura_imgopt_backup_max_mb',
								'label'       => __( '容量上限', 'sakura-imgopt' ),
								'value'       => (string) $max_mb,
								'min'         => 0,
								'step'        => 1,
								'input_class' => 'small-text',
								'hint'        => __( '备份占用超过这个容量时，会从最旧的备份开始自动删除。填 0 表示不限制。', 'sakura-imgopt' ),
							)
						);
						UI::field(
							array(
								'type'    => 'custom',
								'label'   => __( '卸载时删除数据', 'sakura-imgopt' ),
								'full'    => true,
								'control' => static function () use ( $delete_data, $checkbox_control ) {
									$checkbox_control( 'sakura_imgopt_delete_data_on_uninstall', __( '卸载插件时删除本插件产生的数据', 'sakura-imgopt' ), 1 === $delete_data );
								},
								'hint'    => __( '默认关闭：卸载插件后仍保留设置、云存储密钥、统计数据与备份文件，重新安装可以接着用。开启后卸载会删除这些数据，并把本插件改过的图片地址和上传目录恢复原样。', 'sakura-imgopt' ),
							)
						);
						?>
					</div>
					<?php
				}
			);
			?>
		</div>
		<?php
	}
);
