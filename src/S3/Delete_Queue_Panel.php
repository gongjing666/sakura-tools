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
		?>
		<div class="sakura-sync-app">
			<div class="sky-layer" aria-hidden="true"></div>
			<div class="sakura-layer" aria-hidden="true"></div>
			<div class="app">
				<?php
				\Sakura\ImageOptimizer\Admin\Shell::header(
					'storage',
					'deletes',
					array(
						'status'      => (int) $counts['queued'] > 0
							/* translators: %d: queued rows. */
							? sprintf( __( '排队 %d', 'sakura-imgopt' ), (int) $counts['queued'] )
							: __( '队列空闲', 'sakura-imgopt' ),
						'status_tone' => (int) $counts['queued'] > 0 ? 'warn' : 'ok',
					)
				);

				$sufm_pending = (int) $counts['queued'] + (int) $counts['running'];
				$sufm_latest  = ! empty( $rows ) ? (string) $rows[0]['updated_at'] : '';
				$sufm_parts   = array();

				foreach ( $this->state_choices() as $sufm_value => $sufm_choice ) {
					$sufm_parts[] = $sufm_choice . ' ' . number_format_i18n( (int) $counts[ $sufm_value ] );
				}
				?>

				<section class="hero" aria-labelledby="sufm-queue-title">
					<div class="hero-copy">
						<p class="eyebrow"><?php esc_html_e( 'Object Storage / Delete Queue', 'sakura-imgopt' ); ?></p>
						<h1 id="sufm-queue-title"><?php esc_html_e( '删除队列', 'sakura-imgopt' ); ?></h1>
						<p class="lede"><?php esc_html_e( '云端对象被删除后，删除任务会先排进这里，由 WP-Cron 逐条执行。失败的记录按退避时间重试，直到放弃。', 'sakura-imgopt' ); ?></p>
						<div class="hero-actions">
							<a class="btn" href="<?php echo esc_url( Registry::url( 'storage', 'sync' ) ); ?>"><?php esc_html_e( '打开同步中心', 'sakura-imgopt' ); ?></a>
							<a class="btn btn--ghost" href="<?php echo esc_url( Registry::url( 'storage', 'connection' ) ); ?>"><?php esc_html_e( '连接配置', 'sakura-imgopt' ); ?></a>
							<span class="hero-note"><svg class="icon" style="width:14px;height:14px" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.3 19 6v5.3c0 4.4-2.9 7.6-7 9.4-4.1-1.8-7-5-7-9.4V6z" /><path d="m9 12 2 2 4-4" /></svg> <?php esc_html_e( '丢弃只删队列记录，不会删除云端对象', 'sakura-imgopt' ); ?></span>
						</div>
					</div>
					<aside class="hero-status" aria-label="<?php esc_attr_e( '队列摘要', 'sakura-imgopt' ); ?>">
						<div class="hero-status-top">
							<span class="hero-status-label"><span class="dot"></span> <?php echo (int) $counts['queued'] > 0 ? esc_html( sprintf( /* translators: %s: queued rows. */ __( '排队 %s 条', 'sakura-imgopt' ), number_format_i18n( (int) $counts['queued'] ) ) ) : esc_html__( '队列空闲', 'sakura-imgopt' ); ?></span>
							<small><?php echo '' !== $sufm_latest ? esc_html( sprintf( /* translators: %s: last update time. */ __( '最近更新 %s', 'sakura-imgopt' ), mysql2date( 'Y-m-d H:i', $sufm_latest ) ) ) : esc_html__( '暂无记录', 'sakura-imgopt' ); ?></small>
						</div>
						<div class="hero-status-main"><strong><?php echo esc_html( number_format_i18n( $sufm_pending ) ); ?></strong><span>· <?php esc_html_e( '待执行任务', 'sakura-imgopt' ); ?></span></div>
						<div class="hero-status-sub"><?php echo esc_html( implode( ' · ', $sufm_parts ) ); ?></div>
					</aside>
				</section>

				<div class="compact-cards">
					<section class="card">
						<div class="card-head">
							<div>
								<p class="section-kicker"><?php esc_html_e( 'QUEUE DETAIL', 'sakura-imgopt' ); ?></p>
								<h2><?php esc_html_e( '队列明细', 'sakura-imgopt' ); ?></h2>
								<p><?php esc_html_e( '点下方的状态磁贴或右上角的下拉筛选；勾选记录后可批量重试或丢弃。', 'sakura-imgopt' ); ?></p>
							</div>
							<div class="card-head-actions">
								<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sakura-queue-filter">
									<input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug() ); ?>" />
									<input type="hidden" name="t" value="deletes" />
									<label class="screen-reader-text" for="sufm-delete-state"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></label>
									<select id="sufm-delete-state" name="state" class="select">
										<option value=""><?php esc_html_e( '全部状态', 'sakura-imgopt' ); ?></option>
										<?php foreach ( $this->state_choices() as $sufm_value => $sufm_choice ) : ?>
											<option value="<?php echo esc_attr( $sufm_value ); ?>"<?php selected( $state, $sufm_value ); ?>><?php echo esc_html( $sufm_choice ); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
							</div>
						</div>
						<div class="card-body">
							<?php if ( 'retried' === $notice ) : ?>
								<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( /* translators: %d: number of rows. */ __( '已重排 %d 个任务。', 'sakura-imgopt' ), $count ) ); ?></p></div>
							<?php elseif ( 'dropped' === $notice ) : ?>
								<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( /* translators: %d: number of rows. */ __( '已删除 %d 行队列记录。', 'sakura-imgopt' ), $count ) ); ?></p></div>
							<?php elseif ( 'none' === $notice ) : ?>
								<div class="notice notice-warning inline"><p><?php esc_html_e( '没有选中的记录。', 'sakura-imgopt' ); ?></p></div>
							<?php endif; ?>

							<div class="sakura-queue-stats">
								<?php foreach ( $this->state_choices() as $sufm_value => $sufm_choice ) : ?>
									<?php $sufm_active = $state === $sufm_value; ?>
									<a class="sakura-queue-stat is-<?php echo esc_attr( $sufm_value ); ?><?php echo $sufm_active ? ' is-active' : ''; ?><?php echo 0 === (int) $counts[ $sufm_value ] ? ' is-zero' : ''; ?>"
										href="<?php echo esc_url( Registry::url( 'storage', 'deletes', array( 'state' => $sufm_value ) ) ); ?>"
										<?php echo $sufm_active ? 'aria-current="true"' : ''; ?>>
										<span class="sakura-queue-stat__label"><?php echo esc_html( $sufm_choice ); ?></span>
										<strong class="sakura-queue-stat__value"><?php echo esc_html( (string) (int) $counts[ $sufm_value ] ); ?></strong>
									</a>
								<?php endforeach; ?>
							</div>

							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sakura-queue-form">
								<input type="hidden" name="action" value="sakura_s3_delete_queue" />
								<?php wp_nonce_field( 'sakura_s3_delete_queue' ); ?>

								<div class="sakura-queue-table" role="table" aria-label="<?php esc_attr_e( '删除队列', 'sakura-imgopt' ); ?>">
									<div class="sakura-queue-row is-head" role="row">
										<span class="sakura-queue-check" role="columnheader">
											<input type="checkbox" id="sufm-delete-all" aria-label="<?php esc_attr_e( '全选', 'sakura-imgopt' ); ?>" />
										</span>
										<span role="columnheader"><?php esc_html_e( 'ID', 'sakura-imgopt' ); ?></span>
										<span role="columnheader"><?php esc_html_e( '对象', 'sakura-imgopt' ); ?></span>
										<span role="columnheader"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></span>
										<span class="is-num" role="columnheader"><?php esc_html_e( '尝试', 'sakura-imgopt' ); ?></span>
										<span role="columnheader"><?php esc_html_e( '最后错误', 'sakura-imgopt' ); ?></span>
										<span role="columnheader"><?php esc_html_e( '更新时间', 'sakura-imgopt' ); ?></span>
									</div>

									<?php if ( empty( $rows ) ) : ?>
										<div class="sakura-queue-empty">
											<svg class="sakura-queue-empty__mark" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
												<circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="4 5" />
												<path d="M15 24.5l6 6 12-13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
											</svg>
											<p class="sakura-queue-empty__title"><?php echo '' === $state ? esc_html__( '队列为空', 'sakura-imgopt' ) : esc_html__( '当前筛选下没有记录', 'sakura-imgopt' ); ?></p>
											<p class="sakura-queue-empty__hint">
												<?php
												echo '' === $state
													? esc_html__( '删除对象时任务会自动排进这里。目前没有待执行或失败的记录。', 'sakura-imgopt' )
													: esc_html__( '换个状态看，或把右上角的筛选改回「全部状态」。', 'sakura-imgopt' );
												?>
											</p>
										</div>
									<?php else : ?>
										<div role="rowgroup">
										<?php foreach ( $rows as $row ) : ?>
											<?php
											$sufm_state_slug = (string) $row['state'];
											$sufm_key        = (string) $row['object_key'];
											$sufm_error      = (string) $row['last_error'];
											?>
											<div class="sakura-queue-row" role="row">
												<span class="sakura-queue-check" role="cell">
													<input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: object key. */ __( '选择 %s', 'sakura-imgopt' ), $sufm_key ) ); ?>" />
												</span>
												<span class="sakura-queue-id" role="cell">#<?php echo esc_html( (string) $row['id'] ); ?></span>
												<span class="sakura-queue-key" role="cell" title="<?php echo esc_attr( $sufm_key ); ?>"><code><?php echo esc_html( $sufm_key ); ?></code></span>
												<span role="cell"><span class="sakura-queue-pill is-<?php echo esc_attr( $sufm_state_slug ); ?>"><?php echo esc_html( $this->state_label( $sufm_state_slug ) ); ?></span></span>
												<span class="is-num" role="cell"><?php echo esc_html( (string) (int) $row['attempts'] ); ?></span>
												<span class="sakura-queue-error<?php echo '' !== $sufm_error ? '' : ' is-empty'; ?>" role="cell" title="<?php echo esc_attr( $sufm_error ); ?>"><?php echo '' !== $sufm_error ? esc_html( $sufm_error ) : '—'; ?></span>
												<span class="sakura-queue-time" role="cell"><?php echo esc_html( (string) $row['updated_at'] ); ?></span>
											</div>
										<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>

								<div class="sakura-queue-foot">
									<p class="sakura-queue-hint"><?php esc_html_e( '导出 CSV 包含对象 key 与所属桶，可用于在旧存储里手工删除。', 'sakura-imgopt' ); ?></p>
									<div class="sakura-queue-actions">
										<button type="submit" name="task" value="retry" class="btn btn--small btn--primary"<?php disabled( empty( $rows ) ); ?>><?php esc_html_e( '重试选中', 'sakura-imgopt' ); ?></button>
										<button type="submit" name="task" value="drop" class="btn btn--small"<?php disabled( empty( $rows ) ); ?> onclick="return confirm('<?php echo esc_js( __( '从队列里删除这些记录？对象本身不会被删除。', 'sakura-imgopt' ) ); ?>');"><?php esc_html_e( '丢弃选中', 'sakura-imgopt' ); ?></button>
										<button type="submit" name="task" value="export" class="btn btn--small"<?php disabled( empty( $rows ) ); ?>><?php esc_html_e( '导出 CSV', 'sakura-imgopt' ); ?></button>
									</div>
								</div>
							</form>

							<?php if ( $total > self::PER_PAGE ) : ?>
								<?php
								$pages = (int) ceil( $total / self::PER_PAGE );
								?>
								<nav class="sakura-queue-pager" aria-label="<?php esc_attr_e( '队列分页', 'sakura-imgopt' ); ?>">
									<?php
									echo wp_kses_post(
										paginate_links(
											array(
												'base'      => add_query_arg( 'paged', '%#%' ),
												'format'    => '',
												'current'   => $paged,
												'total'     => $pages,
												'prev_text' => '‹',
												'next_text' => '›',
											)
										)
									);
									?>
								</nav>
							<?php endif; ?>
						</div>
					</section>
				</div>
			</div>
		</div>
		<script>
		( function () {
			var all = document.getElementById( 'sufm-delete-all' );
			if ( ! all ) { return; }
			var boxes = Array.prototype.slice.call( document.querySelectorAll( 'input[name="ids[]"]' ) );
			var filter = document.getElementById( 'sufm-delete-state' );
			if ( filter && filter.form ) {
				filter.addEventListener( 'change', function () { filter.form.submit(); } );
			}
			all.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) { box.checked = all.checked; } );
			} );
			boxes.forEach( function ( box ) {
				box.addEventListener( 'change', function () {
					all.checked = boxes.every( function ( b ) { return b.checked; } );
				} );
			} );
		}() );
		</script>
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
