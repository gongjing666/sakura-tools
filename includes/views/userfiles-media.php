<?php
/**
 * Media manager: owner tree on the left, file browser on the right.
 *
 * The screen is painted by assets/ui/js/pages/files-media.js from REST payloads.
 * This view only provides the shell: two panes, a toolbar and the dialog. Every
 * piece of data, including the reason list, comes from the page payload so the
 * markup here stays static and the page keeps working when the copy changes.
 *
 * @package Sakura_Image_Optimizer
 *
 * @var bool $can_takedown Whether the current user may remove files.
 */

use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$can_takedown = isset( $can_takedown ) ? (bool) $can_takedown : false;

UI::page(
	array(
		'id'      => 'files-media',
		'module'  => 'files',
		'tab'     => 'media',
		'eyebrow' => 'MEDIA MANAGER / ' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '' ),
		'title'   => __( '媒体管理', 'sakura-imgopt' ),
		'lede'    => __( '按账户浏览文件空间里的文件，列表与卡片共用一套选择方式；下架会同时删除本地文件与云端对象。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '浏览范围', 'sakura-imgopt' ),
			'value' => __( '全部账户', 'sakura-imgopt' ),
			'sub'   => __( '含已退出本站的账户遗留文件', 'sakura-imgopt' ),
			'tone'  => 'neutral',
		),
	),
	static function () use ( $can_takedown ) {
		?>
		<div class="sufm-mm" id="sufm-mm">
			<aside class="sufm-mm__side" aria-label="<?php echo esc_attr__( '账户', 'sakura-imgopt' ); ?>">
				<div class="sufm-mm__side-head">
					<label class="screen-reader-text" for="sufm-mm-owner-search"><?php echo esc_html__( '搜索账户', 'sakura-imgopt' ); ?></label>
					<input type="search" id="sufm-mm-owner-search" class="sufm-mm__search" autocomplete="off" placeholder="<?php echo esc_attr__( '搜索账户…', 'sakura-imgopt' ); ?>" />
				</div>
				<div class="sufm-mm__tree" id="sufm-mm-tree" role="tree" aria-label="<?php echo esc_attr__( '账户', 'sakura-imgopt' ); ?>">
					<div class="sufm-mm__tree-empty" role="status"><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></div>
				</div>
				<div class="sufm-mm__side-foot">
					<button type="button" class="button sufm-mm__more" id="sufm-mm-more-owners" hidden><?php echo esc_html__( '加载更多', 'sakura-imgopt' ); ?></button>
				</div>
			</aside>

			<section class="sufm-mm__main" aria-label="<?php echo esc_attr__( '文件', 'sakura-imgopt' ); ?>">
				<div class="sufm-mm__toolbar">
					<div class="sufm-mm__crumbs" id="sufm-mm-crumbs"></div>
					<div class="sufm-mm__spacer"></div>
					<label class="screen-reader-text" for="sufm-mm-file-search"><?php echo esc_html__( '搜索文件名', 'sakura-imgopt' ); ?></label>
					<input type="search" id="sufm-mm-file-search" class="sufm-mm__search sufm-mm__search--files" autocomplete="off" placeholder="<?php echo esc_attr__( '搜索文件名…', 'sakura-imgopt' ); ?>" />
					<div class="sufm-mm__views" role="group" aria-label="<?php echo esc_attr__( '视图', 'sakura-imgopt' ); ?>">
						<button type="button" class="sufm-mm__view" id="sufm-mm-view-list" aria-pressed="true"><?php echo esc_html__( '列表视图', 'sakura-imgopt' ); ?></button>
						<button type="button" class="sufm-mm__view" id="sufm-mm-view-grid" aria-pressed="false"><?php echo esc_html__( '卡片视图', 'sakura-imgopt' ); ?></button>
					</div>
					<button type="button" class="button" id="sufm-mm-refresh"><?php echo esc_html__( '刷新', 'sakura-imgopt' ); ?></button>
				</div>

				<div class="sufm-mm__selection" id="sufm-mm-selection" hidden>
					<span class="sufm-mm__count" id="sufm-mm-count" aria-live="polite"></span>
					<?php if ( $can_takedown ) : ?>
						<button type="button" class="button button-primary sufm-mm__takedown" id="sufm-mm-takedown"><?php echo esc_html__( '违规下架', 'sakura-imgopt' ); ?></button>
					<?php endif; ?>
					<button type="button" class="button-link sufm-mm__clear" id="sufm-mm-clear"><?php echo esc_html__( '取消选择', 'sakura-imgopt' ); ?></button>
				</div>

				<div class="sufm-mm__files" id="sufm-mm-files" data-view="list" aria-live="polite">
					<div class="sufm-mm__placeholder"><?php echo esc_html__( '正在读取…', 'sakura-imgopt' ); ?></div>
				</div>

				<div class="sufm-mm__foot">
					<button type="button" class="button sufm-mm__more" id="sufm-mm-more-files" hidden><?php echo esc_html__( '加载更多', 'sakura-imgopt' ); ?></button>
				</div>
			</section>
		</div>

		<dialog class="sakura-dialog sufm-mm-dialog" id="sufm-mm-takedown-dialog" aria-labelledby="sufm-mm-takedown-title">
			<form method="dialog" class="sakura-dialog__form">
				<div class="dialog__body">
					<h2 class="dialog__title" id="sufm-mm-takedown-title"><?php echo esc_html__( '违规下架', 'sakura-imgopt' ); ?></h2>
					<p class="sufm-mm-dialog__lead" id="sufm-mm-takedown-lead"></p>

					<div class="sufm-mm-dialog__field">
						<label for="sufm-mm-reason"><?php echo esc_html__( '下架理由', 'sakura-imgopt' ); ?></label>
						<select id="sufm-mm-reason"></select>
					</div>

					<div class="sufm-mm-dialog__field">
						<label for="sufm-mm-note"><?php echo esc_html__( '备注（可选，最多 120 字）', 'sakura-imgopt' ); ?></label>
						<textarea id="sufm-mm-note" rows="2" maxlength="120"></textarea>
					</div>

					<div class="sufm-mm-dialog__sanction">
						<label class="sufm-mm-dialog__check">
							<input type="checkbox" id="sufm-mm-punish" />
							<span><?php echo esc_html__( '同时处罚上传者（可选）', 'sakura-imgopt' ); ?></span>
						</label>
						<p class="sufm-mm-dialog__hint"><?php echo esc_html__( '批量下架时，每个受影响的上传者各收到一条通知与一项处罚。', 'sakura-imgopt' ); ?></p>
						<div class="sufm-mm-dialog__sanction-fields" id="sufm-mm-sanction-fields" hidden>
							<div class="sufm-mm-dialog__field">
								<label for="sufm-mm-sanction-type"><?php echo esc_html__( '处罚方式', 'sakura-imgopt' ); ?></label>
								<select id="sufm-mm-sanction-type">
									<option value="warn"><?php echo esc_html__( '仅警告（只通知，不限制上传）', 'sakura-imgopt' ); ?></option>
									<option value="upload_ban"><?php echo esc_html__( '禁止上传（仍可登录、仍可删除自己文件）', 'sakura-imgopt' ); ?></option>
								</select>
							</div>
							<div class="sufm-mm-dialog__field">
								<label for="sufm-mm-sanction-days"><?php echo esc_html__( '处罚期限', 'sakura-imgopt' ); ?></label>
								<select id="sufm-mm-sanction-days">
									<option value="7"><?php echo esc_html__( '7 天', 'sakura-imgopt' ); ?></option>
									<option value="30"><?php echo esc_html__( '30 天', 'sakura-imgopt' ); ?></option>
								</select>
							</div>
							<p class="sufm-mm-dialog__hint"><?php echo esc_html__( '永久处罚只能在该账户的「处罚」操作里设置，需要输入登录名确认。', 'sakura-imgopt' ); ?></p>
						</div>
					</div>
				</div>
				<div class="dialog__actions">
					<button type="button" class="button" id="sufm-mm-takedown-cancel"><?php echo esc_html__( '取消', 'sakura-imgopt' ); ?></button>
					<button type="button" class="button button-primary" id="sufm-mm-takedown-confirm"><?php echo esc_html__( '确认下架', 'sakura-imgopt' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}
);
