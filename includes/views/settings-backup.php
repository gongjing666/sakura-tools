<?php
/**
 * View partial. Included from a settings/media method; uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var string $backup_group */
/** @var string $backup_slug */
/** @var \Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface $backup */

/** @var \Sakura\ImageOptimizer\Image_Optimizer_Module $this */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

settings_errors();
$usage = $backup->get_usage();
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'image',
			'backup',
			/* translators: %s: number of backups */
			array( 'status' => sprintf( __( '备份 %s 个', 'sakura-imgopt' ), number_format_i18n( $usage['count'] ) ) )
		);
		?>

		<section class="hero">
			<div class="hero-copy">
				<p class="eyebrow">IMAGE RECOVERY / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
				<h1><?php esc_html_e( '备份管理', 'sakura-imgopt' ); ?></h1>
				<p class="lede"><?php esc_html_e( '管理优化前的原图快照与自动清理策略。清空备份后，已优化图片将无法一键还原。', 'sakura-imgopt' ); ?></p>
			</div>
			<aside class="hero-status">
				<div class="hero-status-top">
					<span class="hero-status-label"><span class="dot"></span> <?php esc_html_e( '原图备份', 'sakura-imgopt' ); ?></span>
				</div>
				<div class="hero-status-main"><strong id="sakura-backup-hero-count"><?php echo esc_html( number_format_i18n( $usage['count'] ) ); ?></strong><span>· <?php esc_html_e( '个文件', 'sakura-imgopt' ); ?></span></div>
				<div class="hero-status-sub"><?php echo esc_html( size_format( $usage['bytes'], 2 ) ); ?></div>
			</aside>
		</section>

		<div class="compact-cards" data-sakura-layout="backup" data-sakura-group="main">
			<section class="card" data-sakura-card="usage">
				<div class="card-head">
					<div><p class="section-kicker">Storage</p><h2><?php esc_html_e( '占用概览', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '备份用于「一键还原」，清空后已优化图片将无法还原为原图。', 'sakura-imgopt' ); ?></p></div>
					<div class="card-head-actions"><a href="#" id="sakura-refresh-backup" class="btn btn--small sakura-refresh-link" title="<?php esc_attr_e( '刷新占用数据', 'sakura-imgopt' ); ?>"><?php esc_html_e( '刷新', 'sakura-imgopt' ); ?></a></div>
				</div>
				<div class="card-body">
					<div id="sakura-backup-box">
						<div class="stat-strip">
							<div class="stat"><span><?php esc_html_e( '占用空间', 'sakura-imgopt' ); ?></span><strong id="sakura-backup-size"><?php echo esc_html( size_format( $usage['bytes'], 2 ) ); ?></strong></div>
							<div class="stat"><span><?php esc_html_e( '备份文件', 'sakura-imgopt' ); ?></span><strong id="sakura-backup-count"><?php echo esc_html( number_format_i18n( $usage['count'] ) ); ?></strong></div>
						</div>
						<div class="card-actions" style="padding-left:0;padding-right:0;padding-bottom:0;">
							<button type="button" id="sakura-clean-expired" class="btn btn--small"><?php esc_html_e( '立即清理过期备份', 'sakura-imgopt' ); ?></button>
							<button type="button" id="sakura-purge-backup" class="btn btn--small btn--danger"><?php esc_html_e( '清空全部备份', 'sakura-imgopt' ); ?></button>
							<span id="sakura-purge-msg" style="font-size:12.5px;"></span>
						</div>
					</div>
				</div>
			</section>

			<section class="card" data-sakura-card="policy">
				<div class="card-head">
					<div>
						<p class="section-kicker">Security</p>
						<h2><?php esc_html_e( '备份目录访问保护', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '如果别人能直接从网上把备份的原图下载走，优化就白做了。插件会自动加一层访问限制，但只对 Apache 服务器有效。', 'sakura-imgopt' ); ?></p>
					</div>
				</div>
				<div class="card-body">
					<?php
					$probe       = \Sakura\ImageOptimizer\Backup\Exposure_Probe::latest();
					$probe_time  = is_array( $probe ) && isset( $probe['time'] ) ? (int) $probe['time'] : 0;
					$probe_fresh = $probe_time > 0 && ( time() - $probe_time ) < DAY_IN_SECONDS;
					$probe_state = $probe_fresh && isset( $probe['state'] ) ? (string) $probe['state'] : '';
					$probe_color = 'exposed' === $probe_state ? '#b83f5c' : ( 'protected' === $probe_state ? '#2b8a64' : '#6b879b' );
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
					$probe_server  = \Sakura\ImageOptimizer\Backup\Exposure_Probe::server();
					$upload_info   = wp_upload_dir();
					$backup_path   = (string) wp_parse_url( trailingslashit( rtrim( (string) $upload_info['baseurl'], '/' ) . '/' . \Sakura\ImageOptimizer\Backup::DIR ), PHP_URL_PATH );
					?>
					<p class="stat-strip" style="margin-bottom:10px;">
						<span><?php esc_html_e( '当前服务器', 'sakura-imgopt' ); ?>：<strong><?php echo esc_html( (string) $probe_server['label'] ); ?></strong></span>
						<span><?php echo esc_html( $probe_server['reads_htaccess'] ? __( '会自动拦截', 'sakura-imgopt' ) : __( '不会自动拦截，需要在站点配置里加规则', 'sakura-imgopt' ) ); ?></span>
					</p>
					<p id="sakura-probe-state" style="color:<?php echo esc_attr( $probe_color ); ?>;margin:0 0 10px;"><?php echo esc_html( $probe_message ); ?></p>
					<p style="margin:0 0 10px;color:var(--muted);font-size:13px;">
						<?php esc_html_e( 'Nginx、OpenResty、IIS、Caddy 等服务器，请在站点配置里拒绝这个路径：', 'sakura-imgopt' ); ?>
						<code>location ^~ <?php echo esc_html( $backup_path ); ?>/ { deny all; }</code>
					</p>
					<div class="card-actions" style="padding-left:0;padding-right:0;padding-bottom:0;">
						<button type="button" id="sakura-probe-backup" class="btn btn--small"><?php esc_html_e( '检测是否可被公网访问', 'sakura-imgopt' ); ?></button>
						<span id="sakura-probe-msg" style="font-size:12.5px;"></span>
					</div>
				</div>
			</section>

			<section class="card" data-sakura-card="access">
				<div class="card-head">
					<div><p class="section-kicker">Policy</p><h2><?php esc_html_e( '自动清理策略', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '留空或填 0 表示不限制。', 'sakura-imgopt' ); ?></p></div>
				</div>
				<div class="card-body">
					<form id="sakura-backup-policy-form" action="options.php" method="post">
						<?php
						settings_fields( $backup_group );
						do_settings_sections( $backup_slug );
						?>
					</form>
				</div>
			</section>
		</div>

		<div class="page-savebar">
			<button class="btn btn--primary" type="submit" form="sakura-backup-policy-form"><?php esc_html_e( '保存清理策略', 'sakura-imgopt' ); ?></button>
		</div>

		<script>
		(function($) {
			function bindAjax(btnId, action, nonce, confirmMsg) {
				$(btnId).on('click', function() {
					if (confirmMsg && !window.confirm(confirmMsg)) { return; }
					var $btn = $(this);
					var isLink = $btn.hasClass('sakura-refresh-link');
					if (isLink) {
						$btn.addClass('is-busy');
					} else {
						$btn.prop('disabled', true);
						$('#sakura-purge-msg').css('color', '#6b879b').text(<?php echo wp_json_encode( __( '处理中...', 'sakura-imgopt' ) ); ?>);
					}
					$.post(ajaxurl, { action: action, _ajax_nonce: nonce }, function(res) {
						if (isLink) { $btn.removeClass('is-busy'); } else { $btn.prop('disabled', false); }
						if (res && res.success) {
							$('#sakura-backup-size').text(res.data.size);
							$('#sakura-backup-count, #sakura-backup-hero-count').text(res.data.count);
							$('#sakura-purge-msg').css('color', '#2b8a64').text(res.data.message);
						} else {
							$('#sakura-purge-msg').css('color', '#b83f5c').text((res && res.data) ? res.data : <?php echo wp_json_encode( __( '操作失败', 'sakura-imgopt' ) ); ?>);
						}
					}).fail(function() {
						$btn.prop('disabled', false);
						$('#sakura-purge-msg').css('color', '#b83f5c').text(<?php echo wp_json_encode( __( '请求失败，请重试', 'sakura-imgopt' ) ); ?>);
					});
				});
			}
			bindAjax('#sakura-refresh-backup', 'sakura_refresh_backup', '<?php echo esc_js( wp_create_nonce( 'sakura_refresh_backup' ) ); ?>', null);
			bindAjax('#sakura-clean-expired', 'sakura_clean_expired_backup', '<?php echo esc_js( wp_create_nonce( 'sakura_clean_expired_backup' ) ); ?>', null);
			bindAjax('#sakura-purge-backup', 'sakura_purge_backup', '<?php echo esc_js( wp_create_nonce( 'sakura_purge_backup' ) ); ?>', <?php echo wp_json_encode( __( '确定要清空全部原图备份吗？此操作不可恢复。', 'sakura-imgopt' ) ); ?>);

			$('#sakura-probe-backup').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true);
				$('#sakura-probe-msg').css('color', '#6b879b').text(<?php echo wp_json_encode( __( '检测中…', 'sakura-imgopt' ) ); ?>);
				$.post(ajaxurl, { action: 'sakura_probe_backup', _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'sakura_probe_backup' ) ); ?>' }, function(res) {
					$btn.prop('disabled', false);
					if (res && res.success) {
						$('#sakura-probe-state').css('color', res.data.state === 'exposed' ? '#b83f5c' : (res.data.state === 'protected' ? '#2b8a64' : '#6b879b')).text(res.data.message);
						$('#sakura-probe-msg').text('');
					} else {
						$('#sakura-probe-msg').css('color', '#b83f5c').text((res && res.data) ? res.data : <?php echo wp_json_encode( __( '检测失败', 'sakura-imgopt' ) ); ?>);
					}
				}).fail(function() {
					$btn.prop('disabled', false);
					$('#sakura-probe-msg').css('color', '#b83f5c').text(<?php echo wp_json_encode( __( '请求失败，请重试', 'sakura-imgopt' ) ); ?>);
				});
			});
		})(jQuery);
		</script>
	</div>
</div>
