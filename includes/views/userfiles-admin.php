<?php
/**
 * Admin screen view.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var array<string, mixed>     $settings Current settings.
 */

defined( 'ABSPATH' ) || exit;

$sufm_theme_limits = \Nboke\UserFiles\Services\Theme_Limits::detect();

$sufm_mimes = array(
	'image/jpeg'      => 'JPG',
	'image/png'       => 'PNG',
	'image/webp'      => 'WebP',
	'image/gif'       => 'GIF',
	'application/pdf' => 'PDF',
	'text/plain'      => 'TXT',
	'application/zip' => 'ZIP',
	'video/mp4'       => 'MP4',
	'audio/mpeg'      => 'MP3',
);

$sufm_groups = array(
	array(
		'title'  => __( '容量与数量', 'sakura-imgopt' ),
		'hint'   => __( '决定每个用户能存多少、能建多少。', 'sakura-imgopt' ),
		'fields' => array(
			'quota_default_bytes' => array( __( '默认单人容量（MB）', 'sakura-imgopt' ), 'number', __( '默认 512，用户可在下方单独调整。', 'sakura-imgopt' ) ),
			'max_file_bytes'      => array( __( '单文件上限（MB）', 'sakura-imgopt' ), 'number', __( '默认 50。', 'sakura-imgopt' ) ),
			'max_user_files'      => array( __( '每人文件数', 'sakura-imgopt' ), 'number', __( '超过后不能再上传。', 'sakura-imgopt' ) ),
			'max_user_folders'    => array( __( '每人文件夹数', 'sakura-imgopt' ), 'number', '', ),
			'max_folder_depth'    => array( __( '目录层级', 'sakura-imgopt' ), 'number', __( '根目录为第 1 层。', 'sakura-imgopt' ) ),
		),
	),
	array(
		'title'  => __( '上传频率与全站预算', 'sakura-imgopt' ),
		'hint'   => __( '防止集中占用带宽与服务端资源。', 'sakura-imgopt' ),
		'fields' => array(
			'daily_upload_files'           => array( __( '每日上传文件数', 'sakura-imgopt' ), 'number', '', ),
			'daily_upload_bytes'           => array( __( '每日上传量（MB）', 'sakura-imgopt' ), 'number', __( '按 UTC 自然日累计。', 'sakura-imgopt' ) ),
			'max_concurrent_uploads'       => array( __( '并发上传数', 'sakura-imgopt' ), 'number', __( '前端同时上传的通道数。', 'sakura-imgopt' ) ),
			'upload_requests_per_minute'   => array( __( '每分钟上传请求', 'sakura-imgopt' ), 'number', '', ),
			'site_storage_budget_bytes'    => array( __( '全站容量预算（MB）', 'sakura-imgopt' ), 'number', __( '0 表示不启用全站预算。', 'sakura-imgopt' ) ),
			'site_storage_warning_percent' => array( __( '预算告警阈值', 'sakura-imgopt' ), 'number', __( '百分比，达到后提示管理员。', 'sakura-imgopt' ) ),
		),
	),
	array(
		'title'  => __( '删除申请', 'sakura-imgopt' ),
		'hint'   => __( '限制用户提交的审核单数量与体量。', 'sakura-imgopt' ),
		'fields' => array(
			'max_pending_delete_requests' => array( __( '待处理申请上限', 'sakura-imgopt' ), 'number', __( '每人最多同时存在的待审核申请。', 'sakura-imgopt' ) ),
			'max_delete_request_items'    => array( __( '单个申请对象上限', 'sakura-imgopt' ), 'number', __( '一个申请最多包含的文件和文件夹数量。', 'sakura-imgopt' ) ),
		),
	),
);

// The file space is split into four screens; every card below is gated by the
// active tab so a growing queue never pushes the settings off the page.
list( , $sufm_tab ) = \Sakura\ImageOptimizer\Admin\Registry::current();
$sufm_tab = (string) $sufm_tab;

$sufm_ledes = array(
	'queue'    => __( '用户提交删除申请后，管理员在这里审核；批准不等于删除，需要在下方勾选范围并确认后才会真正执行。', 'sakura-imgopt' ),
	'users'    => __( '逐个用户查看空间占用、文件数与单独容量；留空表示使用默认容量。', 'sakura-imgopt' ),
	'settings' => __( '文件夹树的外观与上传配额策略；前台入口开关在「子比专属」页，媒体库功能始终可用。', 'sakura-imgopt' ),
	'log'      => __( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ),
);
$sufm_lede  = isset( $sufm_ledes[ $sufm_tab ] ) ? $sufm_ledes[ $sufm_tab ] : $sufm_ledes['queue'];

$sufm_status_pills = array(
	'settings' => array(
		'label' => $enabled ? __( '前台入口已开启', 'sakura-imgopt' ) : __( '前台入口未开启', 'sakura-imgopt' ),
		'tone'  => $enabled ? 'ok' : 'off',
	),
	'users'    => array(
		'label' => __( '按用户配额', 'sakura-imgopt' ),
		'tone'  => '',
	),
	'queue'    => array(
		'label' => __( '删除申请审核', 'sakura-imgopt' ),
		'tone'  => '',
	),
	'log'      => array(
		'label' => __( '操作记录', 'sakura-imgopt' ),
		'tone'  => '',
	),
);
$sufm_status_pill  = isset( $sufm_status_pills[ $sufm_tab ] ) ? $sufm_status_pills[ $sufm_tab ] : $sufm_status_pills['queue'];
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
			<?php
			\Sakura\ImageOptimizer\Admin\Shell::header(
				'files',
				$sufm_tab,
				array(
					'status'      => esc_html( $sufm_status_pill['label'] ),
					'status_tone' => $sufm_status_pill['tone'],
					'status_id'   => 'sufm-link-state',
				)
			);
			?>

			<section class="hero">
				<div class="hero-copy">
					<p class="eyebrow"><?php esc_html_e( 'USER FILE SPACE', 'sakura-imgopt' ); ?></p>
					<h1><?php esc_html_e( '文件管理', 'sakura-imgopt' ); ?></h1>
					<p class="lede">
						<?php echo esc_html( $sufm_lede ); ?>
					</p>
					<div class="hero-actions">
						<a class="btn btn--small" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( '打开媒体库', 'sakura-imgopt' ); ?></a>
						<a class="btn btn--small" href="<?php echo esc_url( admin_url( 'admin.php?page=sakura-tools' ) ); ?>"><?php esc_html_e( '返回插件首页', 'sakura-imgopt' ); ?></a>
					</div>
				</div>
				<aside class="hero-status">
					<?php if ( $enabled && 'queue' === $sufm_tab ) : ?>
						<div class="hero-status-main">
							<span class="section-kicker"><?php esc_html_e( '审核队列', 'sakura-imgopt' ); ?></span>
							<strong id="sufm-hero-pending">—</strong>
							<span><?php esc_html_e( '份待审核申请', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="hero-status-sub">
							<span><?php esc_html_e( '已批准待执行', 'sakura-imgopt' ); ?> <b id="sufm-hero-approved">—</b></span>
							<span><?php esc_html_e( '执行中', 'sakura-imgopt' ); ?> <b id="sufm-hero-queued">—</b></span>
						</div>
					<?php elseif ( $enabled ) : ?>
						<div class="hero-status-main">
							<span class="section-kicker"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></span>
							<strong><?php esc_html_e( '前台入口已开启', 'sakura-imgopt' ); ?></strong>
						</div>
						<div class="hero-status-sub">
							<span><?php /* translators: %s: entry point label */ echo esc_html( sprintf( __( '入口：%s', 'sakura-imgopt' ), $entry_status['label'] ) ); ?></span>
						</div>
					<?php else : ?>
						<div class="hero-status-main">
							<span class="section-kicker"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></span>
							<strong><?php esc_html_e( '前台入口未开启', 'sakura-imgopt' ); ?></strong>
							<span><?php esc_html_e( '媒体库的多用户文件夹仍照常工作', 'sakura-imgopt' ); ?></span>
						</div>
						<div class="hero-status-sub">
							<span><?php echo esc_html( $entry_status['label'] ); ?></span>
						</div>
					<?php endif; ?>
				</aside>
			</section>

				<?php if ( ! $enabled && 'settings' === $sufm_tab ) : ?>
					<section class="card sufm-gate-card">
						<div class="card-head">
							<div>
								<p class="section-kicker"><?php esc_html_e( 'GET STARTED', 'sakura-imgopt' ); ?></p>
								<h2><?php esc_html_e( '启用前台入口', 'sakura-imgopt' ); ?></h2>
								<p><?php esc_html_e( '只有前台入口受开关控制：它决定用户中心里是否出现「文件管理」。媒体库的多用户文件夹、整理与归属转移是后台能力，始终可用。', 'sakura-imgopt' ); ?></p>
							</div>
							<div class="card-head-actions">
								<a class="btn btn--small" href="<?php echo esc_url( \Sakura\ImageOptimizer\Admin\Registry::url( 'files', 'zibll' ) ); ?>"><?php esc_html_e( '前往前台入口', 'sakura-imgopt' ); ?></a>
							</div>
						</div>
						<div class="card-body">
							<ul class="sufm-gate-list">
								<li>
									<span><?php esc_html_e( '当前主题', 'sakura-imgopt' ); ?></span>
									<strong><?php echo esc_html( $entry_status['theme'] ? $entry_status['theme'] : $entry_status['stylesheet'] ); ?></strong>
								</li>
								<li>
									<span><?php esc_html_e( '检测到的入口', 'sakura-imgopt' ); ?></span>
									<strong><?php echo esc_html( $entry_status['label'] ); ?></strong>
								</li>
								<li>
									<span><?php esc_html_e( '前台位置', 'sakura-imgopt' ); ?></span>
									<strong>
										<?php if ( $entry_status['generic'] ) : ?>
											<?php esc_html_e( '新建一个页面并插入短代码 [sufm_files]', 'sakura-imgopt' ); ?>
										<?php else : ?>
											<?php esc_html_e( '用户中心 → 文件管理', 'sakura-imgopt' ); ?>
										<?php endif; ?>
									</strong>
								</li>
							</ul>
							<p class="sufm-hint"><?php esc_html_e( '在「子比专属 → 前台入口」勾选“启用前台入口”并保存后，用户中心的入口与前台上传接口才会开启；媒体库的多用户文件夹、整理与归属转移不受此开关影响。', 'sakura-imgopt' ); ?></p>
						</div>
					</section>
				<?php endif; ?>


			<div class="compact-cards" data-sakura-layout="files-<?php echo esc_attr( $sufm_tab ); ?>" data-sakura-group="main" data-sakura-collect>

				<?php if ( in_array( $sufm_tab, array( 'queue', 'users' ), true ) ) : ?>
				<?php if ( 'queue' === $sufm_tab ) : ?>

				<section class="card" data-sakura-card="queue" data-sakura-span="12">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'REVIEW', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '删除申请队列', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '批准只会改变申请状态；驳回或撤销会立即解除对象锁。', 'sakura-imgopt' ); ?></p>
						</div>
						<div class="card-head-actions">
							<select id="sufm-status" aria-label="<?php esc_attr_e( '按状态筛选', 'sakura-imgopt' ); ?>">
								<option value=""><?php esc_html_e( '全部状态', 'sakura-imgopt' ); ?></option>
								<option value="pending_review"><?php esc_html_e( '待审核', 'sakura-imgopt' ); ?></option>
								<option value="approved"><?php esc_html_e( '已批准', 'sakura-imgopt' ); ?></option>
								<option value="needs_review"><?php esc_html_e( '需进一步核实', 'sakura-imgopt' ); ?></option>
								<option value="completed"><?php esc_html_e( '已完成', 'sakura-imgopt' ); ?></option>
								<option value="rejected"><?php esc_html_e( '已驳回', 'sakura-imgopt' ); ?></option>
							</select>
							<input type="search" id="sufm-search" placeholder="<?php esc_attr_e( '搜索用户', 'sakura-imgopt' ); ?>" aria-label="<?php esc_attr_e( '搜索用户', 'sakura-imgopt' ); ?>" />
							<button type="button" class="btn btn--small" id="sufm-refresh"><?php esc_html_e( '刷新', 'sakura-imgopt' ); ?></button>
						</div>
					</div>
					<div class="card-body">
						<div class="sufm-table-wrap">
							<table class="sufm-table sufm-table--queue">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( '编号', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '用户', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '对象', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '提交时间', 'sakura-imgopt' ); ?></th>
										<th scope="col" class="sufm-col-actions"><?php esc_html_e( '操作', 'sakura-imgopt' ); ?></th>

									</tr>
								</thead>
								<tbody id="sufm-queue-body">
									<tr><td colspan="6" class="sufm-empty"><?php esc_html_e( '正在读取…', 'sakura-imgopt' ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<div class="sufm-pager" id="sufm-queue-pager"></div>
					</div>
				</section>

				<section class="card" data-sakura-card="execute" data-sakura-span="5">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'EXECUTE', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '执行删除', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '只能选择已批准的申请；确认前会再次核对范围哈希。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body sufm-exec">
						<div class="sufm-step" id="sufm-step-pick">
							<div class="sufm-step-head">
								<span class="sufm-step-no" aria-hidden="true">1</span>
								<div>
									<h3><?php esc_html_e( '选择已批准的申请', 'sakura-imgopt' ); ?></h3>
									<p><?php esc_html_e( '批准只代表可以进入执行；勾选后才算进入下一步。', 'sakura-imgopt' ); ?></p>
								</div>
								<span class="sufm-step-meta" id="sufm-pick-count"><?php esc_html_e( '未选择', 'sakura-imgopt' ); ?></span>
							</div>
							<div id="sufm-approved-list" class="sufm-checklist"></div>
						</div>

						<div class="sufm-step" id="sufm-step-preview">
							<div class="sufm-step-head">
								<span class="sufm-step-no" aria-hidden="true">2</span>
								<div>
									<h3><?php esc_html_e( '核对删除范围', 'sakura-imgopt' ); ?></h3>
									<p><?php esc_html_e( '预览会生成范围哈希；下一步确认时会再比对一次。', 'sakura-imgopt' ); ?></p>
								</div>
								<button type="button" class="btn btn--small" id="sufm-preview"><?php esc_html_e( '预览删除范围', 'sakura-imgopt' ); ?></button>
							</div>
							<p class="sufm-muted sufm-preview-hint" id="sufm-preview-hint"><?php esc_html_e( '先在上一步勾选已批准的申请，再核对范围。', 'sakura-imgopt' ); ?></p>
							<div id="sufm-preview-box" class="sufm-preview" hidden></div>
							<p class="sufm-muted sufm-preview-hash" id="sufm-preview-hash"></p>
						</div>

						<div class="sufm-step sufm-step--confirm is-locked" id="sufm-step-confirm">
							<div class="sufm-step-head">
								<span class="sufm-step-no" aria-hidden="true">3</span>
								<div>
									<h3><?php esc_html_e( '确认执行', 'sakura-imgopt' ); ?></h3>
									<p><?php esc_html_e( '输入「删除」两个字，批次建立后由后台任务逐项清理。', 'sakura-imgopt' ); ?></p>
								</div>
							</div>
							<div class="sufm-confirm-row">
								<label class="sufm-phrase">
									<span class="sufm-sr-only"><?php esc_html_e( '输入“删除”以确认', 'sakura-imgopt' ); ?></span>
									<input type="text" id="sufm-phrase" value="" autocomplete="off" placeholder="<?php esc_attr_e( '输入“删除”', 'sakura-imgopt' ); ?>" />
								</label>
								<button type="button" class="btn btn--danger" id="sufm-confirm" disabled><?php esc_html_e( '建立删除批次', 'sakura-imgopt' ); ?></button>
							</div>
							<p class="sufm-hint"><?php esc_html_e( '执行中可以停止，但已经开始的对象无法撤回。', 'sakura-imgopt' ); ?></p>
							<div id="sufm-batch-state" class="sufm-batch" hidden></div>
						</div>
					</div>
								</section>

				<?php else : ?>

				<section class="card" data-sakura-card="users" data-sakura-span="12">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'QUOTA', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '用户容量', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '留空表示使用默认容量；已用容量包含文件空间上传与已纳入账本的媒体库文件。', 'sakura-imgopt' ); ?></p>
						</div>
						<div class="card-head-actions">
							<button type="button" class="btn btn--small" id="sufm-reset-quota"><?php esc_html_e( '重置容量', 'sakura-imgopt' ); ?></button>
							<button type="button" class="btn btn--small" id="sufm-sync-quota"><?php esc_html_e( '同步容量', 'sakura-imgopt' ); ?></button>
							<input type="search" id="sufm-user-search" placeholder="<?php esc_attr_e( '搜索用户', 'sakura-imgopt' ); ?>" aria-label="<?php esc_attr_e( '搜索用户', 'sakura-imgopt' ); ?>" />
							<button type="button" class="btn btn--small" id="sufm-users-refresh"><?php esc_html_e( '刷新', 'sakura-imgopt' ); ?></button>
						</div>
					</div>
					<div class="card-body">
						<div class="sufm-table-wrap">
							<table class="sufm-table sufm-table--users">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( '用户', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '容量使用', 'sakura-imgopt' ); ?></th>
										<th scope="col" class="sufm-col-files"><?php esc_html_e( '文件', 'sakura-imgopt' ); ?></th>
										<th scope="col" class="sufm-col-quota"><?php esc_html_e( '单独容量（MB）', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody id="sufm-users-body">
									<tr><td colspan="4" class="sufm-empty"><?php esc_html_e( '正在读取…', 'sakura-imgopt' ); ?></td></tr>
								</tbody>
							</table>
						</div>
						<div class="sufm-pager" id="sufm-users-pager"></div>
						<p class="sufm-muted" id="sufm-reconcile-out" aria-live="polite"></p>
					</div>
				</section>

				<?php endif; ?>
				<?php endif; ?>

				<?php if ( 'settings' === $sufm_tab ) : ?>

				<section class="card" data-sakura-card="appearance" data-sakura-span="12" id="sufm-appearance">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'APPEARANCE', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '文件夹样式', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '决定媒体库左侧文件夹树的观感，保存后立即生效。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
							<?php settings_fields( 'sufm_settings_group' ); ?>
							<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[_partial]' ); ?>" value="1" />

							<?php
							$sufm_styles = array(
								\Nboke\UserFiles\Settings::STYLE_WINDOWS => array(
									'label' => __( 'Windows 11', 'sakura-imgopt' ),
									'note'  => __( '圆角实心文件夹、深浅两级配色，接近资源管理器的观感。', 'sakura-imgopt' ),
								),
								\Nboke\UserFiles\Settings::STYLE_MAC => array(
									'label' => __( 'macOS Finder', 'sakura-imgopt' ),
									'note'  => __( '描边文件夹、更轻的层次和字重，接近访达的观感。', 'sakura-imgopt' ),
								),
							);
							$sufm_style_now = (string) ( $settings['tree_style'] ?? \Nboke\UserFiles\Settings::STYLE_WINDOWS );
							?>

							<fieldset class="sufm-styles">
								<legend><?php esc_html_e( '选择样式', 'sakura-imgopt' ); ?></legend>
								<div class="sufm-style-grid">
									<?php foreach ( $sufm_styles as $sufm_style_key => $sufm_style ) : ?>
										<label class="sufm-style" data-style="<?php echo esc_attr( $sufm_style_key ); ?>">
											<input
												type="radio"
												name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[tree_style]' ); ?>"
												value="<?php echo esc_attr( $sufm_style_key ); ?>"
												<?php checked( $sufm_style_now, $sufm_style_key ); ?>
											/>
											<span class="sufm-style-preview" aria-hidden="true">
												<span class="sufm-style-demo">
													<?php
													$sufm_demo_rows = array( true, false, false );
													foreach ( $sufm_demo_rows as $sufm_demo_active ) :
														?>
														<span class="sufm-style-demo-row<?php echo $sufm_demo_active ? ' is-open' : ''; ?>">
															<i>
																<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7V5a2 2 0 0 1 2-2h5l3 4h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
															</i>
															<b></b><em></em>
														</span>
													<?php endforeach; ?>
												</span>
											</span>
											<span class="sufm-style-copy">
												<strong><?php echo esc_html( $sufm_style['label'] ); ?></strong>
												<em><?php echo esc_html( $sufm_style['note'] ); ?></em>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="sufm-hint"><?php esc_html_e( '同时作用于媒体库左侧文件夹树和前台用户中心的文件管理，两侧图形与配色保持一致。', 'sakura-imgopt' ); ?></p>
							</fieldset>

							<fieldset class="sufm-accents">
								<legend><?php esc_html_e( '文件夹图标颜色', 'sakura-imgopt' ); ?></legend>
								<div class="sufm-swatches">
									<?php foreach ( \Nboke\UserFiles\Settings::accent_choices() as $sufm_accent_key => $sufm_accent_label ) : ?>
										<label class="sufm-swatch" data-accent="<?php echo esc_attr( $sufm_accent_key ? $sufm_accent_key : 'auto' ); ?>">
											<input
												type="radio"
												name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[folder_accent]' ); ?>"
												value="<?php echo esc_attr( $sufm_accent_key ); ?>"
												<?php checked( (string) ( $settings['folder_accent'] ?? '' ), $sufm_accent_key ); ?>
											/>
											<span class="sufm-swatch-dot" aria-hidden="true"></span>
											<span class="sufm-swatch-name"><?php echo esc_html( $sufm_accent_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="sufm-hint"><?php esc_html_e( '只给文件夹图标上色，标题文字保持中性灰，避免整棵树的花色压过内容。', 'sakura-imgopt' ); ?></p>
							</fieldset>

							<div class="card-savebar">
								<?php submit_button( __( '保存样式', 'sakura-imgopt' ), 'btn btn--primary', 'submit', false ); ?>
							</div>
						</form>
					</div>
				</section>

				<section class="card" data-sakura-card="settings" data-sakura-span="12" id="sufm-settings">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'POLICY', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '上传与配额策略', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '这里是唯一默认值来源；保存后立即对新请求生效。前台入口开关不在本页。', 'sakura-imgopt' ); ?></p>
						</div>
						<div class="card-head-actions">
							<a class="btn btn--small" href="<?php echo esc_url( \Sakura\ImageOptimizer\Admin\Registry::url( 'files', 'zibll' ) ); ?>"><?php esc_html_e( '前往子比专属', 'sakura-imgopt' ); ?></a>
						</div>
					</div>
					<div class="card-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
							<?php settings_fields( 'sufm_settings_group' ); ?>
							<?php // The master switch now lives on the Zibll tab; keep the stored value as-is. ?>
							<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[enabled]' ); ?>" value="<?php echo $enabled ? '1' : '0'; ?>" />

							<p class="sufm-hint sufm-hint--moved">
								<?php
								echo esc_html(
									$enabled
										/* translators: %s: entry point label */
										? sprintf( __( '「前台入口」开关已移至「子比专属」页；当前已开启，入口：%s。', 'sakura-imgopt' ), $entry_status['label'] )
										: __( '「前台入口」开关已移至「子比专属」页；当前未开启，前台不出现入口（媒体库功能不受影响）。', 'sakura-imgopt' )
								);
								?>
							</p>

							<?php $sufm_mb_keys = array( 'quota_default_bytes', 'max_file_bytes', 'daily_upload_bytes', 'site_storage_budget_bytes' ); ?>
							<?php foreach ( $sufm_groups as $sufm_group ) : ?>
								<div class="sufm-group">
									<div class="sufm-group-head">
										<h3><?php echo esc_html( $sufm_group['title'] ); ?></h3>
										<p><?php echo esc_html( $sufm_group['hint'] ); ?></p>
									</div>
									<div class="sufm-form-grid">
										<?php foreach ( $sufm_group['fields'] as $sufm_key => $sufm_field ) : ?>
											<?php
											$sufm_is_mb = in_array( $sufm_key, $sufm_mb_keys, true );
											$sufm_raw   = (int) ( $settings[ $sufm_key ] ?? 0 );
											$sufm_value = $sufm_raw;

											if ( $sufm_is_mb ) {
												$sufm_value = $sufm_raw / 1048576;
												$sufm_value = ( (float) (int) $sufm_value === $sufm_value ) ? (int) $sufm_value : round( $sufm_value, 2 );
											}

											$sufm_name = $sufm_is_mb ? $sufm_key . '_mb' : $sufm_key;
											$sufm_min  = $sufm_is_mb && 'site_storage_budget_bytes' !== $sufm_key ? '1' : '0';
											?>
											<label class="sufm-field">
												<span><?php echo esc_html( $sufm_field[0] ); ?></span>
												<input
													type="<?php echo esc_attr( $sufm_field[1] ); ?>"
													name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[' . $sufm_name . ']' ); ?>"
													value="<?php echo esc_attr( (string) $sufm_value ); ?>"
													min="<?php echo esc_attr( $sufm_min ); ?>"
													step="1"
												/>
												<?php if ( '' !== $sufm_field[2] ) : ?>
													<em><?php echo esc_html( $sufm_field[2] ); ?></em>
												<?php endif; ?>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>

							<?php if ( null !== $sufm_theme_limits ) : ?>
								<fieldset class="sufm-theme-limits">
									<legend>
										<?php esc_html_e( '主题上传限制', 'sakura-imgopt' ); ?>
										<span class="sufm-badge"><?php esc_html_e( '同步写入主题', 'sakura-imgopt' ); ?></span>
									</legend>
									<p class="sufm-hint">
										<?php
										printf(
											/* translators: %s: theme label */
											esc_html__( '读取自 %s。保存本页时会把下面两个值写回主题设置，避免主题先于插件拦截上传。', 'sakura-imgopt' ),
											esc_html( $sufm_theme_limits['label'] )
										);
										?>
										<?php if ( '' !== (string) $settings['theme_upload_limit_synced_at'] ) : ?>
											<span class="sufm-muted">
												<?php
												printf(
													/* translators: %s: UTC timestamp */
													esc_html__( '最近同步：%s', 'sakura-imgopt' ),
													esc_html( (string) $settings['theme_upload_limit_synced_at'] )
												);
												?>
											</span>
										<?php endif; ?>
									</p>
									<div class="sufm-form-grid">
										<label class="sufm-field">
											<span><?php esc_html_e( '每 10 分钟上传文件数', 'sakura-imgopt' ); ?></span>
											<input
												type="number"
												name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[theme_upload_limit_ten_minutes]' ); ?>"
												value="<?php echo esc_attr( (string) (int) $settings['theme_upload_limit_ten_minutes'] ); ?>"
												min="0"
												step="1"
											/>
											<em><?php esc_html_e( '与主题「10 分钟」上限一致；0 表示不限制。', 'sakura-imgopt' ); ?></em>
										</label>
										<label class="sufm-field">
											<span><?php esc_html_e( '每天上传文件数', 'sakura-imgopt' ); ?></span>
											<input
												type="number"
												name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[theme_upload_limit_day]' ); ?>"
												value="<?php echo esc_attr( (string) (int) $settings['theme_upload_limit_day'] ); ?>"
												min="0"
												step="1"
											/>
											<em><?php esc_html_e( '与主题「每天」上限一致；0 表示不限制。', 'sakura-imgopt' ); ?></em>
										</label>
									</div>
									<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[theme_upload_limit_source]' ); ?>" value="<?php echo esc_attr( (string) $settings['theme_upload_limit_source'] ); ?>" />
									<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[theme_upload_limit_synced_at]' ); ?>" value="<?php echo esc_attr( (string) $settings['theme_upload_limit_synced_at'] ); ?>" />
								</fieldset>
							<?php endif; ?>

							<fieldset class="sufm-types">
								<legend><?php esc_html_e( '允许的文件类型', 'sakura-imgopt' ); ?></legend>
								<?php foreach ( $sufm_mimes as $sufm_mime => $sufm_label ) : ?>
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[allowed_types][]' ); ?>"
											value="<?php echo esc_attr( $sufm_mime ); ?>"
											<?php checked( in_array( $sufm_mime, (array) $settings['allowed_types'], true ) ); ?>
										/>
										<span><?php echo esc_html( $sufm_label ); ?></span>
									</label>
								<?php endforeach; ?>
								<p class="sufm-hint"><?php esc_html_e( 'SVG、HTML、脚本和可执行文件始终不会被接受，即使在这里勾选也不会生效。', 'sakura-imgopt' ); ?></p>
							</fieldset>

							<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[reference_notice_version]' ); ?>" value="<?php echo esc_attr( (string) $settings['reference_notice_version'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[tree_style]' ); ?>" value="<?php echo esc_attr( (string) ( $settings['tree_style'] ?? \Nboke\UserFiles\Settings::STYLE_WINDOWS ) ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( \Nboke\UserFiles\Settings::OPTION . '[folder_accent]' ); ?>" value="<?php echo esc_attr( (string) ( $settings['folder_accent'] ?? '' ) ); ?>" />

							<div class="card-savebar">
								<?php submit_button( __( '保存策略', 'sakura-imgopt' ), 'btn btn--primary', 'submit', false ); ?>
							</div>
						</form>
					</div>
				</section>

				<?php endif; ?>

				<?php if ( 'log' === $sufm_tab ) : ?>

				<section class="card" data-sakura-card="log" data-sakura-span="12">
					<div class="card-head">
						<div>
							<p class="section-kicker"><?php esc_html_e( 'AUDIT', 'sakura-imgopt' ); ?></p>
							<h2><?php esc_html_e( '最近操作', 'sakura-imgopt' ); ?></h2>
							<p><?php esc_html_e( '只记录动作与结果，不记录文件路径、对象键或凭证。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
					<div class="card-body">
						<ul class="sufm-events" id="sufm-events">
							<li class="sufm-empty"><?php esc_html_e( '正在读取…', 'sakura-imgopt' ); ?></li>
						</ul>
						<div class="sufm-pager" id="sufm-events-pager"></div>
					</div>
				</section>
				<?php endif; ?>

			</div>
		</div>
	</div>

