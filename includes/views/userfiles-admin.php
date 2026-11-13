<?php
/**
 * File space admin: style/policy, quotas, review queue and audit log.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var bool                 $enabled      Front-end entry enabled.
 * @var array<string,mixed>  $settings     Resolved settings.
 * @var array<string,mixed>  $entry_status Entry provider status.
 */

use Nboke\UserFiles\Services\Theme_Limits;
use Nboke\UserFiles\Settings;
use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

list( , $sufm_tab ) = \Sakura\ImageOptimizer\Admin\Registry::current();
$sufm_tab           = in_array( $sufm_tab, array( 'settings', 'users', 'queue', 'log' ), true ) ? $sufm_tab : 'settings';
$sufm_page          = 'files-' . $sufm_tab;
$enabled            = ! empty( $enabled );
$settings           = isset( $settings ) && is_array( $settings ) ? $settings : Settings::get();
$entry_status       = isset( $entry_status ) && is_array( $entry_status ) ? $entry_status : array();
$sufm_theme_limits  = Theme_Limits::detect();
$sufm_entry_label   = (string) ( $entry_status['label'] ?? '' );
$sufm_entry_theme   = (string) ( $entry_status['theme'] ?? '' );
$sufm_default_mb    = (int) round( ( (int) ( $settings['quota_default_bytes'] ?? 0 ) ) / 1048576 );
$sufm_type_labels   = Settings::type_labels();
$sufm_accent_labels = Settings::accent_choices();
$sufm_style_labels  = array(
	'windows' => __( 'Windows 11', 'sakura-imgopt' ),
	'mac'     => __( 'macOS Finder', 'sakura-imgopt' ),
);
$sufm_ledes         = array(
	'settings' => __( '文件夹树的外观与上传配额策略；前台入口开关在「子比专属」页，媒体库功能始终可用。', 'sakura-imgopt' ),
	'users'    => __( '逐个用户查看空间占用、文件数与单独容量；留空表示使用默认容量。', 'sakura-imgopt' ),
	'queue'    => __( '用户提交删除申请后，管理员在这里审核；批准不等于删除，需要在下方勾选范围并确认后才会真正执行。', 'sakura-imgopt' ),
	'log'      => __( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ),
);
$sufm_status        = array(
	'settings' => array(
		'label' => __( '前台入口', 'sakura-imgopt' ),
		'value' => $enabled ? __( '已开启', 'sakura-imgopt' ) : __( '未开启', 'sakura-imgopt' ),
		'sub'   => $enabled
			/* translators: %s: detected front-end entry label */
			? sprintf( __( '入口：%s', 'sakura-imgopt' ), '' !== $sufm_entry_label ? $sufm_entry_label : __( '未检测到', 'sakura-imgopt' ) )
			: __( '媒体库功能不受影响', 'sakura-imgopt' ),
		'tone'  => $enabled ? 'ok' : 'neutral',
	),
	'users'    => array(
		'label' => __( '容量策略', 'sakura-imgopt' ),
		'value' => __( '按用户配额', 'sakura-imgopt' ),
		'sub'   => sprintf(
			/* translators: %d: default quota in MB */
			__( '默认 %d MB', 'sakura-imgopt' ),
			$sufm_default_mb
		),
		'tone'  => 'neutral',
	),
	'queue'    => array(
		'label' => __( '审核队列', 'sakura-imgopt' ),
		'value' => '—',
		'unit'  => __( '份待审核申请', 'sakura-imgopt' ),
		'sub'   => __( '已批准待执行 — · 执行中 —', 'sakura-imgopt' ),
		'tone'  => 'neutral',
		'id'    => 'sufm-hero-pending',
		'sub_id'=> 'sufm-hero-sub',
	),
	'log'      => array(
		'label' => __( '审计', 'sakura-imgopt' ),
		'value' => __( '操作记录', 'sakura-imgopt' ),
		'sub'   => __( '只记录动作与结果', 'sakura-imgopt' ),
		'tone'  => 'neutral',
	),
);
$sufm_actions       = array(
	array(
		'label'   => __( '打开媒体库', 'sakura-imgopt' ),
		'url'     => admin_url( 'upload.php' ),
		'variant' => 'ghost',
	),
	array(
		'label'   => __( '返回插件首页', 'sakura-imgopt' ),
		'url'     => admin_url( 'admin.php?page=sakura-tools' ),
		'variant' => 'quiet',
	),
);

$sufm_checkbox = static function ( string $name, string $value, string $text, bool $checked ): void {
	echo '<label class="check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . ' />';
	echo '<span class="check__box" aria-hidden="true"></span><span class="check__text">' . esc_html( $text ) . '</span></label>';
};

$sufm_pager = static function ( string $id, array $sizes ): void {
	?>
	<div class="pagination" id="<?php echo esc_attr( $id ); ?>" hidden>
		<div class="pagination__sizes" role="group" aria-label="<?php echo esc_attr__( '每页条数', 'sakura-imgopt' ); ?>">
			<span><?php echo esc_html__( '每页', 'sakura-imgopt' ); ?></span>
			<?php foreach ( $sizes as $size ) : ?>
				<button type="button" class="pagination__link" data-size="<?php echo esc_attr( (string) $size ); ?>" aria-pressed="false"><?php echo esc_html( (string) $size ); ?></button>
			<?php endforeach; ?>
		</div>
		<span class="pagination__summary" data-summary></span>
		<div class="pagination__pages">
			<button type="button" class="pagination__link" data-page-prev aria-label="<?php echo esc_attr__( '上一页', 'sakura-imgopt' ); ?>">‹</button>
			<span class="pagination__now" data-now></span>
			<button type="button" class="pagination__link" data-page-next aria-label="<?php echo esc_attr__( '下一页', 'sakura-imgopt' ); ?>">›</button>
		</div>
	</div>
	<?php
};

$sufm_appearance_form = array(
	'id'           => 'sufm-appearance-form',
	'option_group' => 'sufm_settings_group',
	'markers'      => 'option_fields',
	'option'       => Settings::OPTION,
	'marker_card'  => 'appearance',
	'hidden'       => array(
		'sufm_settings[_partial]' => '1',
	),
);

$sufm_policy_form = array(
	'id'           => 'sufm-settings-form',
	'option_group' => 'sufm_settings_group',
	'markers'      => 'option_fields',
	'option'       => Settings::OPTION,
	'marker_card'  => 'settings',
	'hidden'       => array(
		'sufm_settings[_partial]'              => '1',
		'sufm_settings[reference_notice_version]' => (string) ( $settings['reference_notice_version'] ?? '' ),
		'sufm_settings[tree_style]'            => (string) ( $settings['tree_style'] ?? 'windows' ),
		'sufm_settings[folder_accent]'         => (string) ( $settings['folder_accent'] ?? '' ),
	),
);

$sufm_policy_groups = array(
	array(
		'title'  => __( '容量与数量', 'sakura-imgopt' ),
		'hint'   => __( '决定每个用户能存多少、能建多少。', 'sakura-imgopt' ),
		'fields' => array(
			array(
				'key'   => 'quota_default_bytes',
				'label' => __( '默认单人容量（MB）', 'sakura-imgopt' ),
				'mb'    => true,
				'min'   => 1,
				'hint'  => __( '默认 512，用户可在下方单独调整。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'max_file_bytes',
				'label' => __( '单文件上限（MB）', 'sakura-imgopt' ),
				'mb'    => true,
				'min'   => 1,
				'hint'  => __( '默认 50。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'max_user_files',
				'label' => __( '每人文件数', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '超过后不能再上传。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'max_user_folders',
				'label' => __( '每人文件夹数', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => '',
			),
			array(
				'key'   => 'max_folder_depth',
				'label' => __( '目录层级', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '根目录为第 1 层。', 'sakura-imgopt' ),
			),
		),
	),
	array(
		'title'  => __( '上传频率与全站预算', 'sakura-imgopt' ),
		'hint'   => __( '防止集中占用带宽与服务端资源。', 'sakura-imgopt' ),
		'fields' => array(
			array(
				'key'   => 'daily_upload_files',
				'label' => __( '每日上传文件数', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => '',
			),
			array(
				'key'   => 'daily_upload_bytes',
				'label' => __( '每日上传量（MB）', 'sakura-imgopt' ),
				'mb'    => true,
				'min'   => 1,
				'hint'  => __( '按 UTC 自然日累计。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'max_concurrent_uploads',
				'label' => __( '并发上传数', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '前端同时上传的通道数。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'upload_requests_per_minute',
				'label' => __( '每分钟上传请求', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => '',
			),
			array(
				'key'   => 'site_storage_budget_bytes',
				'label' => __( '全站容量预算（MB）', 'sakura-imgopt' ),
				'mb'    => true,
				'min'   => 0,
				'hint'  => __( '0 表示不启用全站预算。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'site_storage_warning_percent',
				'label' => __( '预算告警阈值', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '百分比，达到后提示管理员。', 'sakura-imgopt' ),
			),
		),
	),
	array(
		'title'  => __( '删除申请', 'sakura-imgopt' ),
		'hint'   => __( '限制用户提交的审核单数量与体量。', 'sakura-imgopt' ),
		'fields' => array(
			array(
				'key'   => 'max_pending_delete_requests',
				'label' => __( '待处理申请上限', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '每人最多同时存在的待审核申请。', 'sakura-imgopt' ),
			),
			array(
				'key'   => 'max_delete_request_items',
				'label' => __( '单个申请对象上限', 'sakura-imgopt' ),
				'min'   => 0,
				'hint'  => __( '一个申请最多包含的文件和文件夹数量。', 'sakura-imgopt' ),
			),
		),
	),
);

UI::page(
	array(
		'id'      => $sufm_page,
		'module'  => 'files',
		'tab'     => $sufm_tab,
		'eyebrow' => 'USER FILE SPACE',
		'title'   => __( '文件管理', 'sakura-imgopt' ),
		'lede'    => $sufm_ledes[ $sufm_tab ],
		'status'  => $sufm_status[ $sufm_tab ],
		'actions' => $sufm_actions,
	),
	static function () use ( $sufm_tab, $settings, $enabled, $entry_status, $sufm_entry_label, $sufm_entry_theme, $sufm_theme_limits, $sufm_type_labels, $sufm_accent_labels, $sufm_style_labels, $sufm_appearance_form, $sufm_policy_form, $sufm_policy_groups, $sufm_checkbox, $sufm_pager ) {
		if ( 'settings' === $sufm_tab ) {

			$sufm_moved = $enabled
				/* translators: %s: detected entry label */
				? sprintf( __( '「前台入口」开关已移至「子比专属」页；当前已开启，入口：%s。', 'sakura-imgopt' ), '' !== $sufm_entry_label ? $sufm_entry_label : __( '未检测到', 'sakura-imgopt' ) )
				: __( '「前台入口」开关已移至「子比专属」页；当前未开启，前台不出现入口（媒体库功能不受影响）。', 'sakura-imgopt' );
			?>
			<div class="card-grid" data-sakura-layout="files-settings" data-sakura-group="main">
				<?php
				if ( ! $enabled ) {
					UI::card(
						array(
							'id'          => 'entry-gate',
							'wide'        => true,
							'kicker'      => 'GET STARTED',
							'title'       => __( '启用前台入口', 'sakura-imgopt' ),
							'description' => __( '只有前台入口受开关控制：它决定用户中心里是否出现「文件管理」。媒体库的多用户文件夹、整理与归属转移是后台能力，始终可用。', 'sakura-imgopt' ),
							'actions'     => array(
								array(
									'label'   => __( '前往前台入口', 'sakura-imgopt' ),
									'url'     => \Sakura\ImageOptimizer\Admin\Registry::url( 'files', 'zibll' ),
									'variant' => 'ghost',
								),
							),
						),
						static function () use ( $entry_status, $sufm_entry_label, $sufm_entry_theme ) {
							$sufm_position = ! empty( $entry_status['generic'] )
								? __( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' )
								: __( '用户中心 → 文件管理', 'sakura-imgopt' );
							?>
							<ul class="list gate-list">
								<li><span><?php echo esc_html__( '当前主题', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( '' !== $sufm_entry_theme ? $sufm_entry_theme : get_stylesheet() ); ?></strong></li>
								<li><span><?php echo esc_html__( '检测到的入口', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( '' !== $sufm_entry_label ? $sufm_entry_label : __( '未检测到', 'sakura-imgopt' ) ); ?></strong></li>
								<li><span><?php echo esc_html__( '前台位置', 'sakura-imgopt' ); ?></span><strong><?php echo esc_html( $sufm_position ); ?></strong></li>
							</ul>
							<p class="field__hint"><?php echo esc_html__( '在「子比专属 → 前台入口」勾选“启用前台入口”并保存后，用户中心的入口与前台上传接口才会开启；媒体库的多用户文件夹、整理与归属转移不受此开关影响。', 'sakura-imgopt' ); ?></p>
							<?php
						}
					);
				}

				UI::card(
					array(
						'id'          => 'appearance',
						'anchor'      => 'sufm-appearance',
						'kicker'      => 'APPEARANCE',
						'title'       => __( '文件夹样式', 'sakura-imgopt' ),
						'description' => __( '决定媒体库左侧文件夹树的观感，保存后立即生效。', 'sakura-imgopt' ),
						'form'        => $sufm_appearance_form,
						'save'        => __( '保存样式', 'sakura-imgopt' ),
					),
					static function () use ( $settings, $sufm_accent_labels, $sufm_style_labels ) {
						$current_style  = (string) ( $settings['tree_style'] ?? 'windows' );
						$current_accent = (string) ( $settings['folder_accent'] ?? '' );
						?>
						<div class="appearance-grid">
							<div class="tree-preview" data-tree-preview data-style="<?php echo esc_attr( $current_style ); ?>" data-accent="<?php echo esc_attr( $current_accent ); ?>">
								<div class="tree-preview__bar" aria-hidden="true"><span></span><span></span><span></span></div>
								<div class="tree-preview__row is-active"><span class="tree-folder" aria-hidden="true"></span><span class="tree-preview__name"><?php echo esc_html__( '图片素材', 'sakura-imgopt' ); ?></span><span class="tree-preview__count">128</span></div>
								<div class="tree-preview__row is-nested"><span class="tree-folder" aria-hidden="true"></span><span class="tree-preview__name"><?php echo esc_html__( '项目归档', 'sakura-imgopt' ); ?></span><span class="tree-preview__count">36</span></div>
								<div class="tree-preview__row is-nested"><span class="tree-folder" aria-hidden="true"></span><span class="tree-preview__name"><?php echo esc_html__( '待整理', 'sakura-imgopt' ); ?></span><span class="tree-preview__count">12</span></div>
							</div>
							<div class="appearance-fields">
								<fieldset class="choice-field">
									<legend><?php echo esc_html__( '选择样式', 'sakura-imgopt' ); ?></legend>
									<div class="style-choices">
										<?php foreach ( $sufm_style_labels as $style => $label ) : ?>
											<label class="style-choice" data-label="<?php echo esc_attr( $label ); ?>">
												<input type="radio" name="sufm_settings[tree_style]" value="<?php echo esc_attr( $style ); ?>" <?php checked( $current_style, $style ); ?> />
												<span class="style-choice__visual style-choice__visual--<?php echo esc_attr( $style ); ?>" aria-hidden="true"><span></span><span></span><span></span></span>
												<span class="style-choice__copy">
													<strong><?php echo esc_html( $label ); ?></strong>
													<span><?php echo esc_html( 'windows' === $style ? __( '圆角实心文件夹、深浅两级配色，接近资源管理器的观感。', 'sakura-imgopt' ) : __( '描边文件夹、更轻的层次和字重，接近访达的观感。', 'sakura-imgopt' ) ); ?></span>
												</span>
											</label>
										<?php endforeach; ?>
									</div>
									<p class="field__hint"><?php echo esc_html__( '同时作用于媒体库左侧文件夹树和前台用户中心的文件管理，两侧图形与配色保持一致。', 'sakura-imgopt' ); ?></p>
								</fieldset>
								<fieldset class="choice-field">
									<legend><?php echo esc_html__( '文件夹图标颜色', 'sakura-imgopt' ); ?></legend>
									<div class="accent-choices">
										<?php foreach ( $sufm_accent_labels as $accent => $label ) : ?>
											<label class="accent-choice" data-accent="<?php echo esc_attr( '' === $accent ? 'auto' : $accent ); ?>" data-label="<?php echo esc_attr( $label ); ?>">
												<input type="radio" name="sufm_settings[folder_accent]" value="<?php echo esc_attr( $accent ); ?>" <?php checked( $current_accent, $accent ); ?> />
												<span class="accent-choice__dot accent-choice__dot--<?php echo esc_attr( '' === $accent ? 'auto' : $accent ); ?>" aria-hidden="true"></span>
												<span><?php echo esc_html( $label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<p class="field__hint"><?php echo esc_html__( '只给文件夹图标上色，标题文字保持中性灰，避免整棵树的花色压过内容。', 'sakura-imgopt' ); ?></p>
								</fieldset>
							</div>
						</div>
						<p class="field__hint style-summary" data-style-summary aria-live="polite">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: tree style label, 2: accent label */
									__( '当前样式：%1$s；图标颜色：%2$s。', 'sakura-imgopt' ),
									$sufm_style_labels[ $current_style ] ?? $current_style,
									$sufm_accent_labels[ $current_accent ] ?? $sufm_accent_labels['']
								)
							);
							?>
						</p>
						<?php
					}
				);

				UI::card(
					array(
						'id'          => 'settings',
						'anchor'      => 'sufm-settings',
						'kicker'      => 'POLICY',
						'title'       => __( '上传与配额策略', 'sakura-imgopt' ),
						'description' => __( '这里是唯一默认值来源；保存后立即对新请求生效。前台入口开关不在本页。', 'sakura-imgopt' ),
						'actions'     => array(
							array(
								'label'   => __( '前往子比专属', 'sakura-imgopt' ),
								'url'     => \Sakura\ImageOptimizer\Admin\Registry::url( 'files', 'zibll' ),
								'variant' => 'ghost',
							),
						),
						'form'        => $sufm_policy_form,
						'save'        => __( '保存策略', 'sakura-imgopt' ),
					),
					static function () use ( $settings, $sufm_moved, $sufm_policy_groups, $sufm_theme_limits, $sufm_type_labels, $sufm_checkbox ) {
						?>
						<p class="field__hint moved-hint"><?php echo esc_html( $sufm_moved ); ?></p>
						<?php foreach ( $sufm_policy_groups as $group ) : ?>
							<section class="policy-group">
								<div class="policy-group__head">
									<h3><?php echo esc_html( $group['title'] ); ?></h3>
									<p><?php echo esc_html( $group['hint'] ); ?></p>
								</div>
								<div class="field-list">
									<?php
									foreach ( $group['fields'] as $field ) {
										$key   = (string) $field['key'];
										$value = (int) ( $settings[ $key ] ?? 0 );
										$is_mb = ! empty( $field['mb'] );
										if ( $is_mb ) {
											$value = round( $value / 1048576, 2 );
										}
										UI::field(
											array(
												'type'  => 'number',
												'id'    => 'sufm-' . str_replace( '_', '-', $key ),
												'name'  => 'sufm_settings[' . ( $is_mb ? $key . '_mb' : $key ) . ']',
												'label' => $field['label'],
												'value' => (string) $value,
												'min'   => (int) $field['min'],
												'step'  => 1,
												'hint'  => $field['hint'],
											)
										);
									}
									?>
								</div>
							</section>
						<?php endforeach; ?>

						<?php if ( is_array( $sufm_theme_limits ) ) : ?>
							<fieldset class="theme-limits">
								<legend>
									<?php echo esc_html__( '主题上传限制', 'sakura-imgopt' ); ?>
									<?php UI::badge( __( '同步写入主题', 'sakura-imgopt' ), 'info' ); ?>
								</legend>
								<p class="field__hint">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: theme limits source label */
											__( '读取自 %s。保存本页时会把下面两个值写回主题设置，避免主题先于插件拦截上传。', 'sakura-imgopt' ),
											(string) ( $sufm_theme_limits['label'] ?? '' )
										)
									);
									if ( ! empty( $settings['theme_upload_limit_synced_at'] ) ) {
										echo ' <span class="text-muted">' . esc_html( sprintf( /* translators: %s: last sync time */ __( '最近同步：%s', 'sakura-imgopt' ), (string) $settings['theme_upload_limit_synced_at'] ) ) . '</span>';
									}
									?>
								</p>
								<div class="field-list">
									<?php
									UI::field(
										array(
											'type'  => 'number',
											'id'    => 'sufm-theme-limit-ten',
											'name'  => 'sufm_settings[theme_upload_limit_ten_minutes]',
											'label' => __( '每 10 分钟上传文件数', 'sakura-imgopt' ),
											'value' => (string) (int) ( $settings['theme_upload_limit_ten_minutes'] ?? 0 ),
											'min'   => 0,
											'step'  => 1,
											'hint'  => __( '与主题「10 分钟」上限一致；0 表示不限制。', 'sakura-imgopt' ),
										)
									);
									UI::field(
										array(
											'type'  => 'number',
											'id'    => 'sufm-theme-limit-day',
											'name'  => 'sufm_settings[theme_upload_limit_day]',
											'label' => __( '每天上传文件数', 'sakura-imgopt' ),
											'value' => (string) (int) ( $settings['theme_upload_limit_day'] ?? 0 ),
											'min'   => 0,
											'step'  => 1,
											'hint'  => __( '与主题「每天」上限一致；0 表示不限制。', 'sakura-imgopt' ),
										)
									);
									?>
								</div>
								<input type="hidden" name="sufm_settings[theme_upload_limit_source]" value="<?php echo esc_attr( (string) ( $settings['theme_upload_limit_source'] ?? '' ) ); ?>" />
								<input type="hidden" name="sufm_settings[theme_upload_limit_synced_at]" value="<?php echo esc_attr( (string) ( $settings['theme_upload_limit_synced_at'] ?? '' ) ); ?>" />
							</fieldset>
						<?php endif; ?>

						<fieldset class="type-field">
							<legend><?php echo esc_html__( '允许的文件类型', 'sakura-imgopt' ); ?></legend>
							<div class="check-grid">
								<?php
								$allowed = is_array( $settings['allowed_types'] ?? null ) ? $settings['allowed_types'] : array();
								foreach ( $sufm_type_labels as $mime => $label ) {
									$sufm_checkbox( 'sufm_settings[allowed_types][]', (string) $mime, (string) $label, in_array( (string) $mime, $allowed, true ) );
								}
								?>
							</div>
							<p class="field__hint"><?php echo esc_html__( 'SVG、HTML、脚本和可执行文件始终不会被接受，即使在这里勾选也不会生效。', 'sakura-imgopt' ); ?></p>
						</fieldset>
						<?php
					}
				);
				?>
			</div>
			<?php
		}

		if ( 'queue' === $sufm_tab ) {
			?>
			<div class="card-grid" data-sakura-layout="files-queue" data-sakura-group="main">
				<?php
				UI::card(
					array(
						'id'          => 'queue',
						'wide'        => true,
						'kicker'      => 'REVIEW',
						'title'       => __( '删除申请队列', 'sakura-imgopt' ),
						'description' => __( '批准只会改变申请状态；驳回或撤销会立即解除对象锁。', 'sakura-imgopt' ),
					),
					static function () use ( $sufm_pager ) {
						?>
						<div class="toolbar" role="search">
							<label class="toolbar__field">
								<span class="screen-reader-text"><?php echo esc_html__( '筛选状态', 'sakura-imgopt' ); ?></span>
								<select id="sufm-status">
									<option value=""><?php echo esc_html__( '全部状态', 'sakura-imgopt' ); ?></option>
									<option value="pending_review"><?php echo esc_html__( '待审核', 'sakura-imgopt' ); ?></option>
									<option value="approved"><?php echo esc_html__( '已批准', 'sakura-imgopt' ); ?></option>
									<option value="needs_review"><?php echo esc_html__( '需进一步核实', 'sakura-imgopt' ); ?></option>
									<option value="completed"><?php echo esc_html__( '已完成', 'sakura-imgopt' ); ?></option>
									<option value="rejected"><?php echo esc_html__( '已驳回', 'sakura-imgopt' ); ?></option>
								</select>
							</label>
							<label class="toolbar__field toolbar__field--grow">
								<span class="screen-reader-text"><?php echo esc_html__( '搜索用户', 'sakura-imgopt' ); ?></span>
								<input type="search" id="sufm-search" placeholder="<?php echo esc_attr__( '搜索用户', 'sakura-imgopt' ); ?>" />
							</label>
							<button type="button" class="btn btn--small btn--ghost" id="sufm-refresh"><?php echo esc_html__( '刷新', 'sakura-imgopt' ); ?></button>
						</div>
						<div class="table-scroll">
							<table class="table table--responsive sakura-files-table">
								<thead>
									<tr>
										<th scope="col"><?php echo esc_html__( '编号', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '用户', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '对象', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '状态', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '提交时间', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '操作', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody id="sufm-queue-body">
									<tr class="is-loading"><td colspan="6" data-label=""><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<div class="page-empty" data-empty="queue" hidden>
							<?php UI::empty_state( __( '暂无删除申请', 'sakura-imgopt' ), __( '当前筛选条件下没有申请。', 'sakura-imgopt' ) ); ?>
						</div>
						<?php $sufm_pager( 'sufm-queue-pager', array( 20, 30, 50 ) ); ?>
						<?php
					}
				);

				UI::card(
					array(
						'id'          => 'execute',
						'wide'        => true,
						'kicker'      => 'EXECUTE',
						'title'       => __( '执行删除', 'sakura-imgopt' ),
						'description' => __( '只能选择已批准的申请；确认前会再次核对范围哈希。', 'sakura-imgopt' ),
					),
					static function () {
						?>
						<div class="exec-steps">
							<section class="exec-step" id="sufm-step-pick">
								<header class="exec-step__head">
									<span class="exec-step__no" aria-hidden="true">1</span>
									<div class="exec-step__copy">
										<h3><?php echo esc_html__( '选择已批准的申请', 'sakura-imgopt' ); ?></h3>
										<p><?php echo esc_html__( '批准只代表可以进入执行；勾选后才算进入下一步。', 'sakura-imgopt' ); ?></p>
									</div>
									<span class="exec-step__meta" id="sufm-pick-count"><?php echo esc_html__( '未选择', 'sakura-imgopt' ); ?></span>
								</header>
								<div class="approved-list" id="sufm-approved-list">
									<p class="text-muted"><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></p>
								</div>
							</section>

							<section class="exec-step" id="sufm-step-preview">
								<header class="exec-step__head">
									<span class="exec-step__no" aria-hidden="true">2</span>
									<div class="exec-step__copy">
										<h3><?php echo esc_html__( '核对删除范围', 'sakura-imgopt' ); ?></h3>
										<p><?php echo esc_html__( '预览会生成范围哈希；下一步确认时会再比对一次。', 'sakura-imgopt' ); ?></p>
									</div>
									<button type="button" class="btn btn--small btn--ghost" id="sufm-preview" disabled><?php echo esc_html__( '预览删除范围', 'sakura-imgopt' ); ?></button>
								</header>
								<p class="field__hint" id="sufm-preview-hint"><?php echo esc_html__( '先在上一步勾选已批准的申请，再核对范围。', 'sakura-imgopt' ); ?></p>
								<div class="preview-box" id="sufm-preview-box" hidden>
									<div class="preview-box__head">
										<strong id="sufm-preview-count"></strong>
										<span class="text-muted" id="sufm-preview-total"></span>
									</div>
									<div id="sufm-preview-requests"></div>
									<p class="field__hint" id="sufm-preview-hash"></p>
								</div>
							</section>

							<section class="exec-step exec-step--confirm is-locked" id="sufm-step-confirm">
								<header class="exec-step__head">
									<span class="exec-step__no" aria-hidden="true">3</span>
									<div class="exec-step__copy">
										<h3><?php echo esc_html__( '确认执行', 'sakura-imgopt' ); ?></h3>
										<p><?php echo esc_html__( '输入「删除」两个字，批次建立后由后台任务逐项清理。', 'sakura-imgopt' ); ?></p>
									</div>
								</header>
								<div class="exec-confirm">
									<label class="screen-reader-text" for="sufm-phrase"><?php echo esc_html__( '确认短语', 'sakura-imgopt' ); ?></label>
									<input type="text" id="sufm-phrase" placeholder="<?php echo esc_attr__( '输入“删除”', 'sakura-imgopt' ); ?>" autocomplete="off" />
									<button type="button" class="btn btn--danger" id="sufm-confirm" disabled><?php echo esc_html__( '建立删除批次', 'sakura-imgopt' ); ?></button>
								</div>
								<p class="field__hint"><?php echo esc_html__( '执行中可以停止，但已经开始的对象无法撤回。', 'sakura-imgopt' ); ?></p>
								<div class="batch-state" id="sufm-batch-state" hidden></div>
							</section>
						</div>
						<?php
					}
				);
				?>
			</div>
			<?php
		}

		if ( 'users' === $sufm_tab ) {
			?>
			<div class="card-grid" data-sakura-layout="files-users" data-sakura-group="main">
				<?php
				UI::card(
					array(
						'id'          => 'users',
						'wide'        => true,
						'kicker'      => 'QUOTA',
						'title'       => __( '用户容量', 'sakura-imgopt' ),
						'description' => __( '留空表示使用默认容量；已用容量包含文件空间上传与已纳入账本的媒体库文件。', 'sakura-imgopt' ),
						'actions'     => array(
							array(
								'label'   => __( '重置容量', 'sakura-imgopt' ),
								'button'  => true,
								'id'      => 'sufm-reset-quota',
								'variant' => 'ghost',
							),
							array(
								'label'   => __( '同步容量', 'sakura-imgopt' ),
								'button'  => true,
								'id'      => 'sufm-sync-quota',
								'variant' => 'ghost',
							),
							array(
								'label'   => __( '刷新', 'sakura-imgopt' ),
								'button'  => true,
								'id'      => 'sufm-users-refresh',
								'variant' => 'ghost',
							),
						),
					),
					static function () use ( $sufm_pager ) {
						?>
						<div class="toolbar toolbar--filter" role="search">
							<label class="toolbar__field toolbar__field--search">
								<span class="screen-reader-text"><?php echo esc_html__( '搜索用户', 'sakura-imgopt' ); ?></span>
								<input type="search" id="sufm-user-search" placeholder="<?php echo esc_attr__( '搜索用户', 'sakura-imgopt' ); ?>" />
							</label>
						</div>
						<div class="table-scroll">
							<table class="table table--responsive sakura-files-table">
								<thead>
									<tr>
										<th scope="col"><?php echo esc_html__( '用户', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '容量使用', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '文件', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php echo esc_html__( '单独容量（MB）', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody id="sufm-users-body">
									<tr class="is-loading"><td colspan="4" data-label=""><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<p class="live-note" id="sufm-reconcile-out" aria-live="polite"></p>
						<div class="page-empty" data-empty="users" hidden>
							<?php UI::empty_state( __( '没有匹配的用户', 'sakura-imgopt' ), __( '换一个关键词，或清空搜索后重试。', 'sakura-imgopt' ) ); ?>
						</div>
						<?php $sufm_pager( 'sufm-users-pager', array( 25, 50, 100 ) ); ?>
						<?php
					}
				);
				?>
			</div>
			<?php
		}

		if ( 'log' === $sufm_tab ) {
			?>
			<div class="card-grid" data-sakura-layout="files-log" data-sakura-group="main">
				<?php
				UI::card(
					array(
						'id'          => 'log',
						'wide'        => true,
						'kicker'      => 'AUDIT',
						'title'       => __( '最近操作', 'sakura-imgopt' ),
						'description' => __( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ),
					),
					static function () use ( $sufm_pager ) {
						?>
						<div class="toolbar" role="search">
							<label class="toolbar__field toolbar__field--grow">
								<span class="screen-reader-text"><?php echo esc_html__( '筛选当前页', 'sakura-imgopt' ); ?></span>
								<input type="search" id="sufm-log-search" placeholder="<?php echo esc_attr__( '筛选当前页（动作、结果或说明）', 'sakura-imgopt' ); ?>" />
							</label>
							<label class="toolbar__field">
								<span class="screen-reader-text"><?php echo esc_html__( '结果', 'sakura-imgopt' ); ?></span>
								<select id="sufm-log-result">
									<option value=""><?php echo esc_html__( '全部结果', 'sakura-imgopt' ); ?></option>
								</select>
							</label>
							<button type="button" class="btn btn--small btn--ghost" id="sufm-log-refresh"><?php echo esc_html__( '刷新', 'sakura-imgopt' ); ?></button>
							<span class="toolbar__meta" id="sufm-log-count" aria-live="polite"></span>
						</div>
						<ul class="event-list" id="sufm-events">
							<li class="event-list__empty"><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></li>
						</ul>
						<p class="filter-empty" id="sufm-log-filter-empty" hidden><?php echo esc_html__( '没有匹配的记录。', 'sakura-imgopt' ); ?></p>
						<div class="page-empty" data-empty="log" hidden>
							<?php UI::empty_state( __( '暂无操作记录', 'sakura-imgopt' ), __( '审核、执行和容量变更会记录在这里。', 'sakura-imgopt' ) ); ?>
						</div>
						<?php $sufm_pager( 'sufm-events-pager', array( 25, 50, 100 ) ); ?>
						<?php
					}
				);
				?>
			</div>
			<?php
		}
	}
);
