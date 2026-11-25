<?php
/**
 * Attachment URL rewrite screen.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string,mixed> $state */
/** @var array<int,string> $hosts */
/** @var string $current_host */
/** @var \Sakura\ImageOptimizer\S3\Url_Rewrite_Scope $scope */

use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;

$tasks        = isset( $state['tasks'] ) && is_array( $state['tasks'] ) ? $state['tasks'] : array();
$runs         = isset( $state['runs'] ) && is_array( $state['runs'] ) ? $state['runs'] : array();
$hosts        = isset( $hosts ) && is_array( $hosts ) ? $hosts : array();
$current_host = isset( $current_host ) ? (string) $current_host : '';
$scope          = isset( $scope ) && $scope instanceof \Sakura\ImageOptimizer\S3\Url_Rewrite_Scope
	? $scope
	: \Sakura\ImageOptimizer\S3\Url_Rewrite_Scope::current();
$scope_source   = \Sakura\ImageOptimizer\S3\Url_Rewrite_Scope::SOURCE_URL === $scope->source()
	? __( '图片地址前缀（upload_url_path）', 'sakura-imgopt' )
	: __( '云端路径前缀（upload_path）', 'sakura-imgopt' );
$rewrite_status = isset( $state['status'] ) ? (string) $state['status'] : 'idle';
$group_labels = array(
	'content' => __( '内容', 'sakura-imgopt' ),
	'theme'   => __( '主题数据', 'sakura-imgopt' ),
	'users'   => __( '用户数据', 'sakura-imgopt' ),
	'plugin'  => __( '插件配置', 'sakura-imgopt' ),
	'other'   => __( '其他', 'sakura-imgopt' ),
);
$status_map = array(
	'idle'     => array( __( '未扫描', 'sakura-imgopt' ), 'neutral' ),
	'scanning' => array( __( '扫描中', 'sakura-imgopt' ), 'info' ),
	'ready'    => array( __( '待执行', 'sakura-imgopt' ), 'warn' ),
	'running'  => array( __( '改写中', 'sakura-imgopt' ), 'info' ),
	'done'     => array( __( '已完成', 'sakura-imgopt' ), 'ok' ),
);
$status_label = $status_map[ $rewrite_status ][0] ?? __( '未扫描', 'sakura-imgopt' );
$status_tone  = $status_map[ $rewrite_status ][1] ?? 'neutral';
$progress     = min( 100, max( 0, (int) ( $state['progress'] ?? 0 ) ) );
$scanning     = 'scanning' === $rewrite_status;
$running      = 'running' === $rewrite_status;
$can_preview  = in_array( $rewrite_status, array( 'ready', 'running', 'done' ), true );
$selected     = count(
	array_filter(
		$tasks,
		static function ( $task ) {
			return is_array( $task ) && ! empty( $task['enabled'] );
		}
	)
);
$all_selected = array() !== $tasks && $selected === count( $tasks );

ob_start();
?>
<div class="rewrite-target">
	<?php
	UI::field(
		array(
			'id'          => 'rewrite-from',
			'label'       => __( '旧域名', 'sakura-imgopt' ),
			'type'        => 'text',
			'placeholder' => 'bf.nboke.com',
			'input_class' => 'regular-text code',
			'hint'        => __( '填域名即可，例如 bf.nboke.com；也可以粘贴完整 URL。', 'sakura-imgopt' ),
			'attrs'       => array( 'list' => 'rewrite-host-options', 'autocomplete' => 'off', 'spellcheck' => 'false' ),
		)
	);
	?>
	<span class="rewrite-target__arrow" aria-hidden="true">→</span>
	<?php
	UI::field(
		array(
			'id'          => 'rewrite-to',
			'label'       => __( '新域名', 'sakura-imgopt' ),
			'type'        => 'text',
			'placeholder' => 'doge.nboke.com',
			'value'       => $current_host,
			'input_class' => 'regular-text code',
			'hint'        => __( '当前接管媒体 URL 的域名已预填；换行请确认新域名已经在对象存储里可用。', 'sakura-imgopt' ),
			'attrs'       => array( 'list' => 'rewrite-host-options', 'autocomplete' => 'off', 'spellcheck' => 'false' ),
		)
	);
	?>
</div>
<datalist id="rewrite-host-options">
	<?php foreach ( $hosts as $host ) : ?>
		<option value="<?php echo esc_attr( $host ); ?>"></option>
	<?php endforeach; ?>
</datalist>
<?php
UI::alert(
	sprintf(
		/* translators: 1: attachment path, 2: setting label. */
		__( '只改写「旧域名 + 附件路径 %1$s」的 URL；同域名的其它路径与非附件链接不会改写。路径来自 %2$s。仍会修正 PHP 序列化数据的长度前缀；反序列化后是对象的值会跳过，不会尝试重建。', 'sakura-imgopt' ),
		$scope->display_path(),
		$scope_source
	),
	'info'
);
UI::alert(
	__( '改动直接写进数据库。每一行写入前都会先记入回滚账本；但仍然建议先确认数据库备份可用，再执行。', 'sakura-imgopt' ),
	'warn',
	__( '先备份，再执行', 'sakura-imgopt' )
);
$target_body = ob_get_clean();

ob_start();
?>
<p class="rewrite-scan-note">
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: status, 2: number of scanned locations, 3: attachment path. */
			__( '当前状态：%1$s，已发现 %2$s 个包含 %3$s 的位置。', 'sakura-imgopt' ),
			$status_label,
			number_format_i18n( count( $tasks ) ),
			$scope->display_path()
		)
	);
	?>
</p>
<div class="rewrite-actions">
	<button type="button" class="btn btn--primary" id="rewrite-scan" <?php echo $scanning ? 'disabled' : ''; ?>><?php esc_html_e( '扫描数据库', 'sakura-imgopt' ); ?></button>
	<button type="button" class="btn btn--ghost" id="rewrite-reset" <?php echo 'idle' === $rewrite_status ? 'disabled' : ''; ?>><?php esc_html_e( '重置当前扫描', 'sakura-imgopt' ); ?></button>
	<span class="rewrite-scan-status" id="rewrite-scan-status" role="status" aria-live="polite">
		<?php UI::badge( $status_label, $status_tone ); ?>
		<span class="rewrite-scan-status__text"><?php echo esc_html( $scanning ? __( '扫描未完成，打开页面会自动续跑。', 'sakura-imgopt' ) : '' ); ?></span>
	</span>
</div>
<div class="progress rewrite-progress" id="rewrite-scan-progress" <?php echo $scanning ? '' : 'hidden'; ?>>
	<span class="progress__bar" data-rewrite-scan-bar style="width: <?php echo esc_attr( (string) $progress ); ?>%"></span>
</div>

<div class="rewrite-toolbar">
	<label class="check"><input type="checkbox" id="rewrite-select-all" <?php echo $all_selected ? 'checked' : ''; ?> /><span class="check__box" aria-hidden="true"></span><span class="check__text"><?php esc_html_e( '全选', 'sakura-imgopt' ); ?></span></label>
	<div class="rewrite-presets" role="group" aria-label="<?php esc_attr_e( '快捷选择', 'sakura-imgopt' ); ?>">
		<span class="rewrite-presets__label"><?php esc_html_e( '快捷选择', 'sakura-imgopt' ); ?></span>
		<button type="button" class="btn btn--ghost btn--small rewrite-preset" id="rewrite-preset-default"><?php esc_html_e( '内容 + 主题', 'sakura-imgopt' ); ?></button>
		<button type="button" class="btn btn--ghost btn--small rewrite-preset" id="rewrite-preset-all"><?php esc_html_e( '全选所有位置', 'sakura-imgopt' ); ?></button>
	</div>
	<span class="rewrite-selection" id="rewrite-selection"><?php echo esc_html( sprintf( /* translators: %d: selected count. */ __( '已选 %d 项', 'sakura-imgopt' ), $selected ) ); ?></span>
</div>

<div class="table-scroll">
	<table class="table table--compact table--responsive rewrite-tasks">
		<thead>
			<tr>
				<th scope="col" class="rewrite-tasks__pick"><span class="screen-reader-text"><?php esc_html_e( '选择', 'sakura-imgopt' ); ?></span></th>
				<th scope="col"><?php esc_html_e( '位置', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '分组', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '命中行数', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '样例', 'sakura-imgopt' ); ?></th>
			</tr>
		</thead>
		<tbody id="rewrite-tasks" data-can-preview="<?php echo $can_preview ? '1' : '0'; ?>">
		<?php if ( $tasks ) : ?>
			<?php foreach ( $tasks as $task ) : ?>
				<?php
				$index = (int) $task['index'];
				$group = (string) ( $task['group'] ?? 'other' );
				?>
				<tr data-index="<?php echo esc_attr( (string) $index ); ?>">
					<td data-label="<?php esc_attr_e( '选择', 'sakura-imgopt' ); ?>" class="rewrite-tasks__pick">
						<input type="checkbox" class="rewrite-task__check" value="<?php echo esc_attr( (string) $index ); ?>" <?php echo ! empty( $task['enabled'] ) ? 'checked' : ''; ?> />
					</td>
					<td data-label="<?php esc_attr_e( '位置', 'sakura-imgopt' ); ?>">
						<code class="rewrite-task__label"><?php echo esc_html( (string) $task['label'] ); ?></code>
						<span class="rewrite-task__meta"><?php echo esc_html( (string) $task['table'] . '.' . (string) $task['column'] ); ?></span>
					</td>
					<td data-label="<?php esc_attr_e( '分组', 'sakura-imgopt' ); ?>"><?php UI::badge( $group_labels[ $group ] ?? $group, 'content' === $group ? 'ok' : 'neutral' ); ?></td>
					<td data-label="<?php esc_attr_e( '命中行数', 'sakura-imgopt' ); ?>"><?php echo esc_html( number_format_i18n( (int) $task['rows'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '样例', 'sakura-imgopt' ); ?>">
						<button type="button" class="btn btn--quiet btn--small" data-rewrite-preview="<?php echo esc_attr( (string) $index ); ?>" <?php echo $can_preview ? '' : 'disabled'; ?>><?php esc_html_e( '查看', 'sakura-imgopt' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr class="rewrite-tasks__empty">
				<td colspan="5" data-label="" class="rewrite-empty-cell">
					<?php
					UI::empty_state(
						__( '还没有扫描结果', 'sakura-imgopt' ),
						__( '填写新旧域名后点“扫描数据库”。只统计包含当前附件路径的位置；扫描只读，不会改动任何数据；发现位置后再勾选并执行。', 'sakura-imgopt' )
					);
					?>
				</td>
			</tr>
		<?php endif; ?>
		</tbody>
	</table>
</div>

<div class="rewrite-preview" id="rewrite-preview" hidden>
	<div class="rewrite-preview__head">
		<strong id="rewrite-preview-title"><?php esc_html_e( '样例', 'sakura-imgopt' ); ?></strong>
		<button type="button" class="btn btn--quiet btn--small" id="rewrite-preview-close"><?php esc_html_e( '收起', 'sakura-imgopt' ); ?></button>
	</div>
	<div class="rewrite-preview__body" id="rewrite-preview-body"></div>
</div>
<?php
$scan_body = ob_get_clean();

ob_start();
?>
<div class="rewrite-run">
	<div class="rewrite-run__stats">
		<div class="rewrite-stat"><span class="rewrite-stat__label"><?php esc_html_e( '待处理', 'sakura-imgopt' ); ?></span><strong class="rewrite-stat__value" data-rewrite-stat="total"><?php echo esc_html( number_format_i18n( (int) ( $state['total'] ?? 0 ) ) ); ?></strong></div>
		<div class="rewrite-stat"><span class="rewrite-stat__label"><?php esc_html_e( '已检查', 'sakura-imgopt' ); ?></span><strong class="rewrite-stat__value" data-rewrite-stat="done"><?php echo esc_html( number_format_i18n( (int) ( $state['done'] ?? 0 ) ) ); ?></strong></div>
		<div class="rewrite-stat"><span class="rewrite-stat__label"><?php esc_html_e( '已改写', 'sakura-imgopt' ); ?></span><strong class="rewrite-stat__value rewrite-stat__value--ok" data-rewrite-stat="changed"><?php echo esc_html( number_format_i18n( (int) ( $state['changed'] ?? 0 ) ) ); ?></strong></div>
		<div class="rewrite-stat"><span class="rewrite-stat__label"><?php esc_html_e( '已跳过', 'sakura-imgopt' ); ?></span><strong class="rewrite-stat__value" data-rewrite-stat="skipped"><?php echo esc_html( number_format_i18n( (int) ( $state['skipped'] ?? 0 ) ) ); ?></strong></div>
		<div class="rewrite-stat"><span class="rewrite-stat__label"><?php esc_html_e( '失败', 'sakura-imgopt' ); ?></span><strong class="rewrite-stat__value rewrite-stat__value--bad" data-rewrite-stat="failed"><?php echo esc_html( number_format_i18n( (int) ( $state['failed'] ?? 0 ) ) ); ?></strong></div>
	</div>
	<div class="progress rewrite-progress" id="rewrite-run-progress" <?php echo 'running' === $rewrite_status ? '' : 'hidden'; ?>>
		<span class="progress__bar" data-rewrite-run-bar style="width: <?php echo esc_attr( (string) $progress ); ?>%"></span>
	</div>
	<p class="rewrite-run__note" id="rewrite-run-note" role="status" aria-live="polite">
		<?php
		if ( 'done' === $rewrite_status ) {
			esc_html_e( '改写已完成。需要撤销时用右侧回滚账本，按记录逐行改回原值。', 'sakura-imgopt' );
		} elseif ( 'running' === $rewrite_status ) {
			esc_html_e( '改写进行中：每批先写账本再更新数据，关闭页面后可从进度处继续。', 'sakura-imgopt' );
		} else {
			esc_html_e( '勾选上方的位置后执行；每行会先写入回滚账本，再更新原值。', 'sakura-imgopt' );
		}
		?>
	</p>
	<div class="rewrite-actions">
		<button type="button" class="btn btn--primary" id="rewrite-run" <?php echo 'ready' === $rewrite_status || 'running' === $rewrite_status ? '' : 'disabled'; ?>><?php echo 'running' === $rewrite_status ? esc_html__( '继续改写', 'sakura-imgopt' ) : esc_html__( '执行改写', 'sakura-imgopt' ); ?></button>
	</div>
	<ul class="notes-list">
		<li><strong><?php esc_html_e( '已跳过：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '值里是对象（unserialize 后为 object）、嵌套过深，或者替换前后完全一样。', 'sakura-imgopt' ); ?></li>
		<li><strong><?php esc_html_e( '失败：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '账本写入或数据库更新失败；该行原值保持不变，可以重试。', 'sakura-imgopt' ); ?></li>
		<li><strong><?php esc_html_e( '插件配置：', 'sakura-imgopt' ); ?></strong><?php esc_html_e( '默认不勾选 sakura-imgopt_* 与插件自建表；需要一起改时手动勾选。', 'sakura-imgopt' ); ?></li>
	</ul>
</div>
<?php
$run_body = ob_get_clean();

ob_start();
?>
<div class="table-scroll">
	<table class="table table--compact table--responsive rewrite-runs">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( '时间', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '改写', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '账本行数', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '待回滚', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '冲突', 'sakura-imgopt' ); ?></th>
				<th scope="col"><?php esc_html_e( '操作', 'sakura-imgopt' ); ?></th>
			</tr>
		</thead>
		<tbody id="rewrite-runs">
		<?php if ( $runs ) : ?>
			<?php foreach ( $runs as $run ) : ?>
				<tr data-run="<?php echo esc_attr( (string) $run['runId'] ); ?>">
					<td data-label="<?php esc_attr_e( '时间', 'sakura-imgopt' ); ?>"><?php echo esc_html( (string) $run['createdAt'] ); ?></td>
					<td data-label="<?php esc_attr_e( '改写', 'sakura-imgopt' ); ?>"><code><?php echo esc_html( (string) $run['from'] . ' → ' . (string) $run['to'] ); ?></code></td>
					<td data-label="<?php esc_attr_e( '账本行数', 'sakura-imgopt' ); ?>"><?php echo esc_html( number_format_i18n( (int) $run['total'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '待回滚', 'sakura-imgopt' ); ?>"><?php echo esc_html( number_format_i18n( (int) $run['pending'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '冲突', 'sakura-imgopt' ); ?>"><?php echo esc_html( number_format_i18n( (int) $run['conflicts'] ) ); ?></td>
					<td data-label="<?php esc_attr_e( '操作', 'sakura-imgopt' ); ?>">
						<button type="button" class="btn btn--ghost btn--small" data-rewrite-revert="<?php echo esc_attr( (string) $run['runId'] ); ?>" <?php echo (int) $run['pending'] > 0 ? '' : 'disabled'; ?>><?php esc_html_e( '回滚', 'sakura-imgopt' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr class="rewrite-runs__empty">
				<td colspan="6" data-label="" class="rewrite-empty-cell">
					<?php
					UI::empty_state(
						__( '还没有改写记录', 'sakura-imgopt' ),
						__( '执行一次改写后，这里按批次列出账本；回滚会按记录把值改回原样。', 'sakura-imgopt' )
					);
					?>
				</td>
			</tr>
		<?php endif; ?>
		</tbody>
	</table>
</div>
<p class="rewrite-runs__note"><?php esc_html_e( '“冲突”表示这一行在改写之后又被别的操作改过，回滚不会覆盖它；需要时请手工处理。', 'sakura-imgopt' ); ?></p>
<?php
$history_body = ob_get_clean();

$cards = static function () use ( $target_body, $scan_body, $run_body, $history_body ): void {
	UI::card(
		array(
			'id'          => 'target',
			'kicker'      => __( 'Step 1', 'sakura-imgopt' ),
			'title'       => __( '目标域名', 'sakura-imgopt' ),
			'description' => __( '只改旧域名到新域名，并且只处理当前附件路径下的 URL；同域名的其它路径保持原样。', 'sakura-imgopt' ),
		),
		static function () use ( $target_body ): void {
			echo $target_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
		}
	);

	UI::card(
		array(
			'id'          => 'scan',
			'kicker'      => __( 'Step 2', 'sakura-imgopt' ),
			'title'       => __( '扫描与选择', 'sakura-imgopt' ),
			'description' => __( '扫描当前站点前缀下的文本列，只统计旧域名 + 附件路径命中的位置；文章内容与主题数据默认勾选。', 'sakura-imgopt' ),
		),
		static function () use ( $scan_body ): void {
			echo $scan_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
		}
	);

	UI::card(
		array(
			'id'          => 'run',
			'kicker'      => __( 'Step 3', 'sakura-imgopt' ),
			'title'       => __( '执行改写', 'sakura-imgopt' ),
			'description' => __( '分批执行，每批最多 50 行；先写账本再更新，失败的行保持原值。', 'sakura-imgopt' ),
		),
		static function () use ( $run_body ): void {
			echo $run_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
		}
	);

	UI::card(
		array(
			'id'          => 'history',
			'kicker'      => __( 'Rollback', 'sakura-imgopt' ),
			'title'       => __( '回滚账本', 'sakura-imgopt' ),
			'description' => __( '每次改写一行账本；回滚只把值改回，不删除账本记录（状态标为已回滚）。', 'sakura-imgopt' ),
		),
		static function () use ( $history_body ): void {
			echo $history_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
		}
	);
};

UI::page(
	array(
		'id'      => 'storage-rewrite',
		'module'  => 'storage',
		'tab'     => 'rewrite',
		'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
		'title'   => __( '附件链接改写', 'sakura-imgopt' ),
		'lede'    => __( '换了对象存储之后，把文章、主题和插件配置里仍然指向旧域名的附件链接统一改成新域名。', 'sakura-imgopt' ),
		'status'  => array(
			'label' => __( '当前媒体域名', 'sakura-imgopt' ),
			'value' => '' !== $current_host ? $current_host : __( '未接管', 'sakura-imgopt' ),
			'sub'   => __( '改写先记账本，可回滚', 'sakura-imgopt' ),
			'tone'  => '' !== $current_host ? 'ok' : 'warn',
		),
		'actions' => array(
			array(
				'label'   => __( '备份还原', 'sakura-imgopt' ),
				'url'     => Registry::url( 'image', 'backup' ),
				'variant' => 'quiet',
			),
			array(
				'label'   => __( '连接配置', 'sakura-imgopt' ),
				'url'     => Registry::url( 'storage', 'connection' ),
				'variant' => 'quiet',
			),
		),
	),
	$cards
);
