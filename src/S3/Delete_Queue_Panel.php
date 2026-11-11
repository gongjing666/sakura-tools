<?php
/**
 * Admin panel for the remote delete queue.
 *
 * Audit A03 follow-up: when the bucket/endpoint of a profile changes, jobs that
 * were enqueued for the previous target can no longer be resolved from the
 * current configuration. The worker prefers the archived target saved by the
 * settings saver, but operators still need a place to see what is stuck, retry
 * it, export the keys for manual removal, or drop rows that will never finish.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Registry;

/**
 * Renders `对象存储 → 删除队列` and handles its row actions.
 */
final class Delete_Queue_Panel {

	/**
	 * Per-page row count.
	 */
	private const PER_PAGE = 50;

	/**
	 * Wire dependencies.
	 */
	public function __construct() {
	}

	/**
	 * Register admin-post handlers.
	 *
	 * @param \Sakura\ImageOptimizer\Support\Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( \Sakura\ImageOptimizer\Support\Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_post_sakura_s3_delete_queue', array( $this, 'handle_action' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the shared admin chrome for this screen.
	 *
	 * The panel renders the platform shell around its card, so it needs the same
	 * stylesheet stack as the other screens; without it the card and the table
	 * fall back to bare WordPress markup.
	 *
	 * @param string $hook_suffix Current admin page hook (unused).
	 * @return void
	 */
	public function enqueue( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! class_exists( Registry::class ) || ! Registry::on( 'storage', 'deletes' ) ) {
			return;
		}

		if ( class_exists( '\Sakura\ImageOptimizer\Admin\Page_Assets' ) ) {
			\Sakura\ImageOptimizer\Admin\Page_Assets::set_data(
				'storage-deletes',
				array(
					'strings' => array(
						/* translators: %d: selected rows. */
						'selected'     => __( '已选 %d 项', 'sakura-imgopt' ),
						'dropTitle'    => __( '丢弃队列记录', 'sakura-imgopt' ),
						'dropDesc'     => __( '只会删除队列记录，不会删除云端对象；确定丢弃选中的记录吗？', 'sakura-imgopt' ),
						'dropConfirm'  => __( '丢弃记录', 'sakura-imgopt' ),
						'noneSelected' => __( '请先勾选要处理的队列记录。', 'sakura-imgopt' ),
					),
				)
			);
		}

		if ( class_exists( '\Sakura\ImageOptimizer\Admin\Assets' ) ) {
			\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();
		}
	}

	/**
	 * Handle retry / drop / export submissions.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '没有权限。', 'sakura-imgopt' ) );
		}

		check_admin_referer( 'sakura_s3_delete_queue' );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( (string) $_POST['task'] ) ) : '';
		$raw  = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids  = array_values( array_filter( array_map( 'absint', $raw ) ) );

		global $wpdb;
		$table = Delete_Jobs::table_name();

		if ( 'export' === $task ) {
			$this->stream_csv( $ids );
			return;
		}

		if ( array() === $ids ) {
			$this->redirect( array( 'sufm_deletes' => 'none' ) );
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'retry' === $task ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET state = 'queued', attempts = 0, next_at = %s, lease_until = NULL, claim_token = '', last_error = '' WHERE id IN ({$in})",
				array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $ids )
			);
			$affected = false === $sql ? 0 : (int) $wpdb->query( $sql );
			$this->redirect( array( 'sufm_deletes' => 'retried', 'count' => $affected ) );
		}

		if ( 'drop' === $task ) {
			$sql      = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $ids );
			$affected = false === $sql ? 0 : (int) $wpdb->query( $sql );
			$this->redirect( array( 'sufm_deletes' => 'dropped', 'count' => $affected ) );
		}

		$this->redirect( array( 'sufm_deletes' => 'none' ) );
	}

	/**
	 * Render the queue tab.
	 */
	public function render(): void {
		global $wpdb;

		$ui = '\Sakura\ImageOptimizer\Admin\UI';

		$table = Delete_Jobs::table_name();
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( (string) $_GET['state'] ) ) : '';
		$state = in_array( $state, array( 'queued', 'running', 'failed', 'dead' ), true ) ? $state : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$where = '' === $state ? '' : $wpdb->prepare( 'WHERE state = %s', $state );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above or empty.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, profile_id, state, attempts, object_key, last_error, updated_at
				FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above or empty.
				self::PER_PAGE,
				( $paged - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);

		$counts = $this->counts();
		$notice = isset( $_GET['sufm_deletes'] ) ? sanitize_key( wp_unslash( (string) $_GET['sufm_deletes'] ) ) : '';
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0;
		$queued = (int) $counts['queued'];
		$pending = $queued + (int) $counts['running'];
		$latest  = ! empty( $rows ) ? (string) $rows[0]['updated_at'] : '';
		$states  = $this->state_choices();
		$tones   = array(
			'queued'  => 'info',
			'running' => 'warn',
			'failed'  => 'bad',
			'dead'    => 'neutral',
		);

		$filters = array();
		foreach ( $states as $sufm_value => $sufm_choice ) {
			$filters[] = $sufm_choice . ' ' . number_format_i18n( (int) $counts[ $sufm_value ] );
		}

		ob_start();
		?>
		<?php if ( 'retried' === $notice ) : ?>
			<?php $ui::alert( sprintf( /* translators: %d: number of rows. */ __( '已重排 %d 个任务。', 'sakura-imgopt' ), $count ), 'ok' ); ?>
		<?php elseif ( 'dropped' === $notice ) : ?>
			<?php $ui::alert( sprintf( /* translators: %d: number of rows. */ __( '已删除 %d 行队列记录。', 'sakura-imgopt' ), $count ), 'ok' ); ?>
		<?php elseif ( 'none' === $notice ) : ?>
			<?php $ui::alert( __( '没有选中的记录。', 'sakura-imgopt' ), 'warn' ); ?>
		<?php endif; ?>

		<section class="kpis" aria-label="<?php esc_attr_e( '队列状态', 'sakura-imgopt' ); ?>">
			<?php foreach ( $states as $sufm_value => $sufm_choice ) : ?>
				<?php
				$sufm_active = $state === $sufm_value;
				$sufm_url    = Registry::url( 'storage', 'deletes', $sufm_active ? array() : array( 'state' => $sufm_value ) );
				?>
				<a class="kpi kpi--link<?php echo $sufm_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $sufm_url ); ?>" <?php echo $sufm_active ? 'aria-current="page"' : ''; ?>>
					<span class="kpi__label"><?php echo esc_html( $sufm_choice ); ?></span>
					<span class="kpi__value"><?php echo esc_html( number_format_i18n( (int) $counts[ $sufm_value ] ) ); ?><small><?php esc_html_e( '条', 'sakura-imgopt' ); ?></small></span>
					<span class="kpi__sub"><?php echo esc_html( $sufm_active ? __( '正在查看此状态', 'sakura-imgopt' ) : __( '点击筛选此状态', 'sakura-imgopt' ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</section>

		<?php
		$ui::card(
			array(
				'id'          => 'queue',
				'class'       => 'card--wide',
				'kicker'      => __( 'QUEUE DETAIL', 'sakura-imgopt' ),
				'title'       => __( '队列明细', 'sakura-imgopt' ),
				'description' => __( '删除任务会先排进这里，由 WP-Cron 逐条执行。勾选记录后可批量重试或丢弃；丢弃只删队列记录，不会删除云端对象。', 'sakura-imgopt' ),
			),
			function () use ( $ui, $rows, $state, $states, $tones, $counts, $total, $paged, $latest, $pending, $count ): void {
				?>
				<div class="queue-toolbar">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="queue-filter" data-sakura-queue-filter>
						<input type="hidden" name="page" value="<?php echo esc_attr( (string) ( \Sakura\ImageOptimizer\Admin\Registry::modules()['storage']['slug'] ?? '' ) ); ?>" />
						<input type="hidden" name="t" value="deletes" />
						<label class="screen-reader-text" for="sufm-delete-state"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></label>
						<select id="sufm-delete-state" name="state">
							<option value=""><?php esc_html_e( '全部状态', 'sakura-imgopt' ); ?></option>
							<?php foreach ( $states as $sufm_value => $sufm_choice ) : ?>
								<option value="<?php echo esc_attr( $sufm_value ); ?>"<?php selected( $state, $sufm_value ); ?>><?php echo esc_html( $sufm_choice ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="btn btn--small"><?php esc_html_e( '筛选', 'sakura-imgopt' ); ?></button>
					</form>
					<div class="queue-toolbar__meta">
						<span class="small-text"><?php echo esc_html( sprintf( /* translators: 1: pending, 2: total. */ __( '待执行 %1$s 条 · 当前筛选 %2$s 条', 'sakura-imgopt' ), number_format_i18n( $pending ), number_format_i18n( $total ) ) ); ?></span>
						<span class="small-text"><?php echo '' !== $latest ? esc_html( sprintf( /* translators: %s: last update time. */ __( '最近更新 %s', 'sakura-imgopt' ), mysql2date( 'Y-m-d H:i', $latest ) ) ) : esc_html__( '暂无记录', 'sakura-imgopt' ); ?></span>
					</div>
				</div>

				<?php if ( empty( $rows ) ) : ?>
					<?php
					$ui::empty_state(
						'' === $state ? __( '队列为空', 'sakura-imgopt' ) : __( '当前筛选下没有记录', 'sakura-imgopt' ),
						'' === $state
							? __( '删除对象时任务会自动排进这里。目前没有待执行或失败的记录。', 'sakura-imgopt' )
							: __( '换个状态看，或把筛选改回「全部状态」。', 'sakura-imgopt' ),
						'' === $state ? array() : array(
							'label' => __( '查看全部状态', 'sakura-imgopt' ),
							'url'   => \Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'deletes' ),
						)
					);
					?>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="queue-form" data-sakura-queue-form>
						<input type="hidden" name="action" value="sakura_s3_delete_queue" />
						<?php wp_nonce_field( 'sakura_s3_delete_queue' ); ?>

						<div class="queue-selection small-text" id="sufm-delete-selected" hidden></div>

						<label class="queue-select-mobile">
							<input type="checkbox" data-sakura-role="select-all-mobile" />
							<span><?php esc_html_e( '全选当前页', 'sakura-imgopt' ); ?></span>
						</label>

						<div class="table-scroll">
							<table class="table table--responsive" aria-label="<?php esc_attr_e( '删除队列', 'sakura-imgopt' ); ?>">
								<thead>
									<tr>
										<th scope="col" class="cell-select"><input type="checkbox" id="sufm-delete-all" data-sakura-role="select-all" aria-label="<?php esc_attr_e( '全选', 'sakura-imgopt' ); ?>" /></th>
										<th scope="col"><?php esc_html_e( 'ID', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '对象', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
										<th scope="col" class="is-num"><?php esc_html_e( '尝试', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '最后错误', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '更新时间', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rows as $row ) : ?>
										<?php
										$sufm_state_slug = (string) $row['state'];
										$sufm_key        = (string) $row['object_key'];
										$sufm_error      = (string) $row['last_error'];
										?>
										<tr data-state="<?php echo esc_attr( $sufm_state_slug ); ?>">
											<td class="cell-select" data-label="<?php esc_attr_e( '选择', 'sakura-imgopt' ); ?>"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: object key. */ __( '选择 %s', 'sakura-imgopt' ), $sufm_key ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'ID', 'sakura-imgopt' ); ?>"><span class="queue-id">#<?php echo esc_html( (string) $row['id'] ); ?></span></td>
											<td data-label="<?php esc_attr_e( '对象', 'sakura-imgopt' ); ?>"><code class="queue-key" title="<?php echo esc_attr( $sufm_key ); ?>"><?php echo esc_html( $sufm_key ); ?></code></td>
											<td data-label="<?php esc_attr_e( '状态', 'sakura-imgopt' ); ?>"><?php $ui::badge( $states[ $sufm_state_slug ] ?? $sufm_state_slug, $tones[ $sufm_state_slug ] ?? 'neutral' ); ?></td>
											<td data-label="<?php esc_attr_e( '尝试', 'sakura-imgopt' ); ?>" class="is-num"><?php echo esc_html( (string) (int) $row['attempts'] ); ?></td>
											<td data-label="<?php esc_attr_e( '最后错误', 'sakura-imgopt' ); ?>"><span class="queue-error<?php echo '' !== $sufm_error ? '' : ' is-empty'; ?>" title="<?php echo esc_attr( $sufm_error ); ?>"><?php echo '' !== $sufm_error ? esc_html( $sufm_error ) : '—'; ?></span></td>
											<td data-label="<?php esc_attr_e( '更新时间', 'sakura-imgopt' ); ?>"><span class="queue-time"><?php echo esc_html( (string) $row['updated_at'] ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<div class="queue-foot">
							<p class="queue-hint small-text"><?php esc_html_e( '导出 CSV 包含对象 key 与所属桶，可用于在旧存储里手工删除。', 'sakura-imgopt' ); ?></p>
							<div class="button-row queue-actions">
								<button type="submit" name="task" value="retry" class="btn btn--primary btn--small"><?php esc_html_e( '重试选中', 'sakura-imgopt' ); ?></button>
								<button type="submit" name="task" value="drop" class="btn btn--danger btn--small" data-sakura-confirm-task="drop"><?php esc_html_e( '丢弃选中', 'sakura-imgopt' ); ?></button>
								<button type="submit" name="task" value="export" class="btn btn--ghost btn--small"><?php esc_html_e( '导出 CSV', 'sakura-imgopt' ); ?></button>
							</div>
						</div>
					</form>

					<?php $this->render_pagination( $total, $paged, $state ); ?>
				<?php endif; ?>
				<?php
			}
		);

		$deletes_body = ob_get_clean();

		$ui::page(
			array(
				'id'      => 'storage-deletes',
				'module'  => 'storage',
				'tab'     => 'deletes',
				'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( '删除队列', 'sakura-imgopt' ),
				'lede'    => __( '云端对象被删除后，删除任务会先排进这里，由 WP-Cron 逐条执行。失败的记录按退避时间重试，直到放弃。', 'sakura-imgopt' ),
				'status'  => array(
					'label' => __( '队列状态', 'sakura-imgopt' ),
					'value' => $queued > 0 ? sprintf( /* translators: %s: queued rows. */ __( '排队 %s', 'sakura-imgopt' ), number_format_i18n( $queued ) ) : __( '队列空闲', 'sakura-imgopt' ),
					'sub'   => ( $latest ? sprintf( /* translators: %s: last update time. */ __( '最近更新 %s', 'sakura-imgopt' ), mysql2date( 'm-d H:i', $latest ) ) : __( '暂无记录', 'sakura-imgopt' ) ) . ' · ' . implode( ' · ', $filters ),
					'tone'  => $queued > 0 ? 'warn' : 'ok',
				),
				'actions' => array(
					array(
						'label'   => __( '打开同步中心', 'sakura-imgopt' ),
						'url'     => Registry::url( 'storage', 'sync' ),
						'variant' => 'primary',
					),
					array(
						'label'   => __( '连接配置', 'sakura-imgopt' ),
						'url'     => Registry::url( 'storage', 'connection' ),
						'variant' => 'ghost',
					),
				),
			),
			static function () use ( $deletes_body ): void {
				echo $deletes_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
			}
		);
	}

	/**
	 * Render numbered pagination links for the queue table.
	 *
	 * @param int    $total Row count.
	 * @param int    $paged Current page.
	 * @param string $state State filter.
	 */
	private function render_pagination( int $total, int $paged, string $state ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}

		$base = array( 'page' => $this->menu_slug(), 't' => 'deletes' );
		if ( '' !== $state ) {
			$base['state'] = $state;
		}
		$link = static function ( int $number ) use ( $base ): string {
			return add_query_arg( array_merge( $base, array( 'paged' => $number ) ), admin_url( 'admin.php' ) );
		};
		$from = max( 1, $paged - 2 );
		$to   = min( $pages, $paged + 2 );
		?>
		<nav class="pagination" aria-label="<?php esc_attr_e( '队列分页', 'sakura-imgopt' ); ?>">
			<span class="pagination__summary"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages, 3: total rows. */ __( '第 %1$s / %2$s 页 · 共 %3$s 条', 'sakura-imgopt' ), number_format_i18n( $paged ), number_format_i18n( $pages ), number_format_i18n( $total ) ) ); ?></span>
			<div class="pagination__pages">
				<?php if ( $paged > 1 ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $paged - 1 ) ); ?>" aria-label="<?php esc_attr_e( '上一页', 'sakura-imgopt' ); ?>">‹</a>
				<?php else : ?>
					<button type="button" class="pagination__link" disabled>‹</button>
				<?php endif; ?>
				<?php if ( $from > 1 ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( 1 ) ); ?>">1</a>
					<?php if ( $from > 2 ) : ?><span class="pagination__ellipsis">…</span><?php endif; ?>
				<?php endif; ?>
				<?php for ( $number = $from; $number <= $to; $number++ ) : ?>
					<?php if ( $number === $paged ) : ?>
						<span class="pagination__link is-current" aria-current="page"><?php echo esc_html( number_format_i18n( $number ) ); ?></span>
					<?php else : ?>
						<a class="pagination__link" href="<?php echo esc_url( $link( $number ) ); ?>"><?php echo esc_html( number_format_i18n( $number ) ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
				<?php if ( $to < $pages ) : ?>
					<?php if ( $to < $pages - 1 ) : ?><span class="pagination__ellipsis">…</span><?php endif; ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $pages ) ); ?>"><?php echo esc_html( number_format_i18n( $pages ) ); ?></a>
				<?php endif; ?>
				<?php if ( $paged < $pages ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $paged + 1 ) ); ?>" aria-label="<?php esc_attr_e( '下一页', 'sakura-imgopt' ); ?>">›</a>
				<?php else : ?>
					<button type="button" class="pagination__link" disabled>›</button>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Per-state row counts.
	 *
	 * @return array<string,int>
	 */
	private function counts(): array {
		global $wpdb;
		$table = Delete_Jobs::table_name();
		$rows  = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A );
		$out   = array( 'queued' => 0, 'running' => 0, 'failed' => 0, 'dead' => 0 );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) $row['state'];
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * State slug => label, in display order.
	 *
	 * @return array<string,string>
	 */
	private function state_choices(): array {
		return array(
			'queued'  => __( '排队', 'sakura-imgopt' ),
			'running' => __( '执行中', 'sakura-imgopt' ),
			'failed'  => __( '失败', 'sakura-imgopt' ),
			'dead'    => __( '已放弃', 'sakura-imgopt' ),
		);
	}

	/**
	 * Human label for a queue state.
	 *
	 * @param string $state Raw state.
	 * @return string
	 */
	private function state_label( string $state ): string {
		switch ( $state ) {
			case 'queued':
				return __( '排队', 'sakura-imgopt' );
			case 'running':
				return __( '执行中', 'sakura-imgopt' );
			case 'failed':
				return __( '失败', 'sakura-imgopt' );
			case 'dead':
				return __( '已放弃', 'sakura-imgopt' );
		}

		return $state;
	}

	/**
	 * Stream the selected rows as CSV.
	 *
	 * @param array<int,int> $ids Selected row ids.
	 */
	private function stream_csv( array $ids ): void {
		global $wpdb;

		$table = Delete_Jobs::table_name();
		$sql   = "SELECT id, attachment_id, profile_id, state, attempts, object_key, last_error, updated_at FROM {$table}";

		if ( array() !== $ids ) {
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = $wpdb->prepare( "{$sql} WHERE id IN ({$in})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list built from absint values.
		}

		$rows = false === $sql ? array() : $wpdb->get_results( $sql . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above when needed.

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sakura-delete-queue-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array( 'id', 'attachment_id', 'profile_id', 'state', 'attempts', 'object_key', 'last_error', 'updated_at' ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			fputcsv( $out, array_values( $row ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Redirect back to the panel with a status flag.
	 *
	 * @param array<string,mixed> $args Query args.
	 */
	private function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, Registry::url( 'storage', 'deletes' ) ) );
		exit;
	}

	/**
	 * WordPress page slug that hosts this tab.
	 *
	 * @return string
	 */
	private function menu_slug(): string {
		$modules = Registry::modules();

		return isset( $modules['storage']['slug'] ) ? (string) $modules['storage']['slug'] : '';
	}
}
