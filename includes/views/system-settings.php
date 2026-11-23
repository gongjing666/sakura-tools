<?php
/**
 * System settings view: mount point for the React island.
 *
 * Included from System_Settings_Module::render_system_settings(). The page
 * shell (title, status badge, actions) stays server-rendered so the screen is
 * never blank and the menu highlight has something to attach to; everything
 * below the header is drawn by assets/ui/dist/system.js from the bootstrap
 * JSON below.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed> $payload System_Payload::build() output.
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$system_bootstrap = wp_json_encode(
	$payload,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

$optimize_url = admin_url( 'admin.php?page=sakura-tools-optimize' );
$refresh_url  = admin_url( 'admin.php?page=sakura-tools' );

UI::page(
	array(
		'id'      => 'system-settings',
		'module'  => 'system',
		'tab'     => 'settings',
		'eyebrow' => 'SYSTEM READOUT / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '系统设置', 'sakura-imgopt' ),
		'lede'    => __( '服务器运行参数、PHP 扩展与图像引擎能力在这里核对；卡片间隔、页面宽度与卡片样式也在这里统一设置。', 'sakura-imgopt' ),
		'status'  => $payload['status'],
		'actions' => array(
			array(
				'label'   => __( '进入图像优化', 'sakura-imgopt' ),
				'url'     => $optimize_url,
				'variant' => 'primary',
			),
			array(
				'label'   => __( '重新检测', 'sakura-imgopt' ),
				'url'     => $refresh_url,
				'variant' => 'ghost',
			),
		),
	),
	static function () use ( $system_bootstrap ) {
		?>
		<div id="sakura-system-root">
			<p class="system-note"><?php echo esc_html__( '正在读取环境信息…', 'sakura-imgopt' ); ?></p>
		</div>
		<script type="application/json" id="sakura-system-bootstrap"><?php echo $system_bootstrap; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_TAG. ?></script>
		<?php
	}
);
