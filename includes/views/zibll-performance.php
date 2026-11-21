<?php
/**
 * Zibll integration screen: license cache.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Zibll\Admin_Module;
use Sakura\ImageOptimizer\Zibll\License;
use Sakura\ImageOptimizer\Zibll\License_Cache;
use Sakura\ImageOptimizer\Zibll\Settings;

$zibll_state   = Admin_Module::cache_payload();
$zibll_enabled = ! empty( $zibll_state['enabled'] );
$zibll_valid   = ! empty( $zibll_state['valid'] );
$zibll_cache   = License_Cache::get();
$zibll_version = (string) $zibll_state['theme'];
$zibll_legacy  = ! empty( $zibll_state['legacy'] );
$zibll_sources = array(
	'admin_enable' => __( '保存时启用验证', 'sakura-imgopt' ),
	'ajax_manual'  => __( '手动复验', 'sakura-imgopt' ),
	'cron'         => __( '每日复验', 'sakura-imgopt' ),
	'manual'       => __( '手动验证', 'sakura-imgopt' ),
);

$zibll_time = static function ( int $timestamp ): string {
	if ( $timestamp <= 0 ) {
		return '—';
	}

	if ( function_exists( 'wp_date' ) ) {
		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}

	if ( function_exists( 'date_i18n' ) ) {
		return (string) date_i18n( 'Y-m-d H:i', $timestamp );
	}

	return '—';
};

$zibll_created = (int) ( $zibll_cache['created_at'] ?? 0 );
$zibll_source  = (string) ( $zibll_cache['source'] ?? '' );
$zibll_source  = isset( $zibll_sources[ $zibll_source ] ) ? $zibll_sources[ $zibll_source ] : $zibll_source;

if ( $zibll_enabled && $zibll_valid ) {
	$zibll_status = array(
		'label' => __( '授权缓存', 'sakura-imgopt' ),
		'value' => __( '已启用', 'sakura-imgopt' ),
		'sub'   => __( '前台命中缓存时跳过授权校验', 'sakura-imgopt' ),
		'tone'  => 'ok',
	);
} elseif ( $zibll_enabled ) {
	$zibll_status = array(
		'label' => __( '授权缓存', 'sakura-imgopt' ),
		'value' => __( '已失效', 'sakura-imgopt' ),
		'sub'   => __( '前台仍由子比主题校验', 'sakura-imgopt' ),
		'tone'  => 'warn',
	);
} else {
	$zibll_status = array(
		'label' => __( '授权缓存', 'sakura-imgopt' ),
		'value' => __( '未启用', 'sakura-imgopt' ),
		'sub'   => __( '每个前台请求都会真实验证', 'sakura-imgopt' ),
		'tone'  => 'neutral',
	);
}

ob_start();
?>
<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[license_cache]" value="0" />
<?php
UI::field(
	array(
		'id'      => 'zibll-license-cache',
		'type'    => 'toggle',
		'name'    => Settings::OPTION . '[license_cache]',
		'value'   => '1',
		'checked' => $zibll_enabled,
		'label'   => __( '启用 Zibll 授权缓存', 'sakura-imgopt' ),
		'hint'    => __( '保存时真实调用一次子比的授权校验；通过后缓存永久有效，前台命中缓存时跳过 zib_init_add_action。关闭后缓存立即失效，前台恢复由主题自行校验。', 'sakura-imgopt' ),
	)
);

if ( $zibll_legacy ) {
	UI::notice(
		sprintf(
			/* translators: %s: path of the legacy mu-plugin. */
			__( '检测到旧的 mu-plugin：%s。它会在本插件之前无条件跳过授权校验，请先重命名或删除它，否则本功能无法启用。', 'sakura-imgopt' ),
			License::legacy_muplugin()
		),
		'bad'
	);
} elseif ( ! $zibll_state['supported'] ) {
	UI::notice(
		sprintf(
			/* translators: 1: current theme version, 2: supported versions. */
			__( '当前子比版本 %1$s 未经验证（已验证：%2$s）。继续启用需要勾选下面的确认项。', 'sakura-imgopt' ),
			'' === $zibll_version ? __( '未知', 'sakura-imgopt' ) : $zibll_version,
			License::supported_label()
		),
		'warn'
	);

	UI::field(
		array(
			'id'      => 'zibll-unsupported-ack',
			'type'    => 'checkbox',
			'name'    => Settings::OPTION . '[' . Settings::ACK_FIELD . ']',
			'value'   => '1',
			'checked' => false,
			'label'   => __( '我了解风险，仍要在这个版本上启用', 'sakura-imgopt' ),
			'hint'    => __( '确认只在本次保存时生效，不会被记住；下次保存仍需要重新勾选。', 'sakura-imgopt' ),
		)
	);
}

if ( $zibll_enabled && ! $zibll_valid ) {
	UI::notice( __( '开关是开着的，但缓存与当前主题版本、站点地址或授权内容对不上，因此没有生效。请重新保存或点下面的按钮复验。', 'sakura-imgopt' ), 'warn' );
}
?>
<div class="zibll-actions">
	<button type="button" class="btn btn--quiet btn--small" id="zibll-warm"<?php if ( ! $zibll_enabled ) : ?> disabled<?php endif; ?>><?php esc_html_e( '重新验证并预热缓存', 'sakura-imgopt' ); ?></button>
	<span class="zibll-status" id="zibll-warm-status" role="status" aria-live="polite"></span>
</div>
<?php
$zibll_form_body = ob_get_clean();

ob_start();
?>
<div class="table-scroll">
	<table class="table table--compact table--responsive">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( '主题版本', 'sakura-imgopt' ); ?></th>
				<td data-label="<?php esc_attr_e( '主题版本', 'sakura-imgopt' ); ?>">
					<?php echo esc_html( '' === $zibll_version ? __( '未知', 'sakura-imgopt' ) : $zibll_version ); ?>
					<?php
					UI::badge(
						$zibll_state['supported'] ? __( '已验证版本', 'sakura-imgopt' ) : __( '未验证版本', 'sakura-imgopt' ),
						$zibll_state['supported'] ? 'ok' : 'warn'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '授权缓存', 'sakura-imgopt' ); ?></th>
				<td data-label="<?php esc_attr_e( '授权缓存', 'sakura-imgopt' ); ?>">
					<?php if ( $zibll_valid ) : ?>
						<?php esc_html_e( '有效', 'sakura-imgopt' ); ?>
						<?php if ( $zibll_created > 0 ) : ?>
							<span class="zibll-meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: verification time, 2: verification source. */
										__( '（%1$s 验证，来源：%2$s）', 'sakura-imgopt' ),
										$zibll_time( $zibll_created ),
										$zibll_source
									)
								);
								?>
							</span>
						<?php endif; ?>
					<?php elseif ( ! empty( $zibll_cache['ok'] ) ) : ?>
						<?php esc_html_e( '已写入，但与当前环境或授权对不上，未生效', 'sakura-imgopt' ); ?>
					<?php else : ?>
						<?php esc_html_e( '尚未写入', 'sakura-imgopt' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '授权到期', 'sakura-imgopt' ); ?></th>
				<td data-label="<?php esc_attr_e( '授权到期', 'sakura-imgopt' ); ?>">
					<?php echo esc_html( $zibll_time( License::expires_at() ) ); ?>
					<?php if ( License::expires_at() <= 0 ) : ?>
						<span class="zibll-meta"><?php esc_html_e( '主题没有记录到期时间，缓存不会因到期自动失效', 'sakura-imgopt' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '下次复验', 'sakura-imgopt' ); ?></th>
				<td data-label="<?php esc_attr_e( '下次复验', 'sakura-imgopt' ); ?>">
					<?php
					echo esc_html(
						(int) $zibll_state['next_run'] > 0
							? $zibll_time( (int) $zibll_state['next_run'] )
							: __( '未排程', 'sakura-imgopt' )
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '旧 mu-plugin', 'sakura-imgopt' ); ?></th>
				<td data-label="<?php esc_attr_e( '旧 mu-plugin', 'sakura-imgopt' ); ?>">
					<?php if ( $zibll_legacy ) : ?>
						<?php UI::badge( __( '存在', 'sakura-imgopt' ), 'bad' ); ?>
						<span class="zibll-meta"><?php echo esc_html( License::legacy_muplugin() ); ?></span>
					<?php else : ?>
						<?php esc_html_e( '未安装', 'sakura-imgopt' ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
<p class="zibll-note">
	<?php esc_html_e( '缓存与主题版本、站点地址、授权内容绑定；换域名、改授权或换主题都会让它自动失效。每日复验负责在授权续期后重新预热。', 'sakura-imgopt' ); ?>
</p>
<?php
$zibll_status_body = ob_get_clean();

$zibll_cards = static function () use ( $zibll_form_body, $zibll_status_body ): void {
	UI::card(
		array(
			'id'          => 'license-cache',
			'kicker'      => __( 'License', 'sakura-imgopt' ),
			'title'       => __( '启用 Zibll 授权缓存', 'sakura-imgopt' ),
			'description' => __( '子比 8.8 起每个前台请求都会跑一次授权校验：9.2 约 33 ms，其中大半是 OPcache 存不下的 eval。这里只缓存“已经真实通过”的结论。', 'sakura-imgopt' ),
			'form'        => array(
				'id'           => 'sakura-zibll-license-cache',
				'option_group' => Settings::GROUP,
				'markers'      => 'option_fields',
				'option'       => Settings::OPTION,
				'marker_card'  => Settings::CARD,
			),
			'save'        => __( '保存并验证', 'sakura-imgopt' ),
		),
		static function () use ( $zibll_form_body ): void {
			echo $zibll_form_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup.
		}
	);

	UI::card(
		array(
			'id'          => 'cache-status',
			'kicker'      => __( 'Status', 'sakura-imgopt' ),
			'title'       => __( '授权缓存状态', 'sakura-imgopt' ),
			'description' => __( '当前请求与缓存的实际对比结果；任何一项对不上，前台都会回到主题自己的校验。', 'sakura-imgopt' ),
		),
		static function () use ( $zibll_status_body ): void {
			echo $zibll_status_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped markup.
		}
	);
};

UI::page(
	array(
		'id'      => 'zibll-performance',
		'module'  => 'zibll',
		'tab'     => 'performance',
		'eyebrow' => 'ZIBLL / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '子比增强 · 性能优化', 'sakura-imgopt' ),
		'lede'    => __( '把主题每页都要跑的授权校验换成一次真实校验后的缓存。这里不伪造授权：只有主题自己校验通过的结论才会被写进缓存，失效条件全部在本页可见。', 'sakura-imgopt' ),
		'status'  => $zibll_status,
	),
	$zibll_cards
);
