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
 * The screen is a React island when assets/ui/dist/deletes.js is on disk; the
 * classic PHP renderer below stays as the fallback for a checkout without a
 * built bundle. Both read the same Deletes_Payload snapshot.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Deletes_Payload;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Support\Hook_Loader;

/**
 * Renders the delete queue and handles its row actions.
 */
final class Delete_Queue_Panel {

	/**
	 * Bundle that takes over the page body.
	 */
	private const BUNDLE = 'assets/ui/dist/deletes.js';

	/**
	 * Wire dependencies.
	 */
	public function __construct() {
	}

	/**
	 * Register admin-post handlers.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_post_sakura_s3_delete_queue', array( $this, 'handle_action' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the shared admin chrome and the island bundle for this screen.
	 *
	 * @param string $hook_suffix Current admin page hook (unused).
	 * @return void
	 */
	public function enqueue( string $hook_suffix = '' ): void {
		unset( $hook_suffix );

		if ( ! class_exists( Registry::class ) || ! Registry::on( 'storage', 'deletes' ) ) {
			return;
		}

		if ( class_exists( Page_Assets::class ) ) {
			Page_Assets::set_data(
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

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			wp_enqueue_script(
				'sakura-admin-deletes',
				SAKURA_TOOLS_URL . self::BUNDLE,
				array( 'sakura-admin-core' ),
				Page_Assets::asset_version( self::BUNDLE ),
				true
			);
		}
	}

	/**
	 * Handle retry / drop / export submissions from the fallback renderer.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '没有权限。', 'sakura-imgopt' ) );
		}

		check_admin_referer( 'sakura_s3_delete_queue' );

		$task = '';
		if ( isset( $_POST['task'] ) ) {
			$task = sanitize_key( wp_unslash( (string) $_POST['task'] ) );
		} elseif ( isset( $_GET['task'] ) ) {
			$task = sanitize_key( wp_unslash( (string) $_GET['task'] ) );
		}

		// Only the read-only CSV export is allowed over GET; retry and drop
		// change rows and must stay on a POST.
		if ( ! isset( $_POST['task'] ) && 'export' !== $task ) {
			wp_die( esc_html__( '请求方式不正确。', 'sakura-imgopt' ) );
		}

		$raw = array();
		if ( isset( $_POST['ids'] ) ) {
			$raw = (array) wp_unslash( $_POST['ids'] );
		} elseif ( isset( $_GET['ids'] ) ) {
			$raw = wp_unslash( $_GET['ids'] );
			$raw = is_string( $raw ) ? explode( ',', $raw ) : (array) $raw;
		}

		$ids = array();
		foreach ( (array) $raw as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		$ids = array_values( $ids );

		if ( 'export' === $task ) {
			$this->stream_csv( $ids );
			return;
		}

		if ( array() === $ids ) {
			$this->redirect( array( 'sufm_deletes' => 'none' ) );
		}

		$jobs = new Delete_Jobs();

		if ( 'retry' === $task ) {
			$affected = $jobs->retry_ids( $ids );
			$this->redirect(
				array(
					'sufm_deletes' => 'retried',
					'count'        => $affected,
				)
			);
		}

		if ( 'drop' === $task ) {
			$affected = $jobs->drop_ids( $ids );
			$this->redirect(
				array(
					'sufm_deletes' => 'dropped',
					'count'        => $affected,
				)
			);
		}

		$this->redirect( array( 'sufm_deletes' => 'none' ) );
	}

	/**
	 * Render the queue tab.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$payload = new Deletes_Payload();

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			$data = $payload->build();

			require __DIR__ . '/../../includes/views/deletes-island.php';
			return;
		}

		$state   = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( (string) $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		$state   = in_array( $state, array( 'queued', 'running', 'done', 'dead', 'failed' ), true ) ? $state : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state.
		$snapshot = $payload->snapshot( '' === $state ? 'all' : $state, $paged, Deletes_Payload::PER_PAGE );
		$page_args = $payload->page_args( $snapshot );

		// The fallback prints its own body; without these a fresh checkout would
		// get the island skeleton instead of the working table.
		unset( $page_args['island'], $page_args['skeleton'] );

		$rows   = (array) $snapshot['rows'];
		$counts = (array) $snapshot['counts'];
		$pagination = (array) $snapshot['pagination'];
		$notice = isset( $_GET['sufm_deletes'] ) ? sanitize_key( wp_unslash( (string) $_GET['sufm_deletes'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
		$count  = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.

		ob_start();

		$result = '';
		if ( 'retried' === $notice ) {
			$result = $count > 0
				? sprintf( /* translators: %d: number of rows. */ __( '已重排 %d 个任务。', 'sakura-imgopt' ), $count )
				: __( '选中的记录都不能重排：只有排队中、失败或已放弃的记录可以重试。', 'sakura-imgopt' );
		} elseif ( 'dropped' === $notice ) {
			$result = $count > 0
				? sprintf( /* translators: %d: number of rows. */ __( '已删除 %d 行队列记录。', 'sakura-imgopt' ), $count )
				: __( '没有可删除的记录。', 'sakura-imgopt' );
		} elseif ( 'none' === $notice ) {
			$result = __( '没有选中的记录。', 'sakura-imgopt' );
		}

		if ( '' !== $result ) {
			UI::modal(
				array(
					'id'        => 'sakura-deletes-result-modal',
					'return_to' => '.queue-toolbar .btn, .card__actions .btn',
					'tone'      => 'none' === $notice ? 'warn' : 'ok',
					'size'      => 'compact',
					'kicker'    => 'DELETE QUEUE / RESULT',
					'title'     => 'none' === $notice ? __( '没有执行', 'sakura-imgopt' ) : __( '操作已完成', 'sakura-imgopt' ),
					'message'   => $result,
				)
			);
		}
		?>
		<div class="card-grid" data-sakura-layout="deletes" data-sakura-group="main">

		<?php
		UI::card(
			array(
				'id'          => 'queue',
				'class'       => 'card--wide',
				'kicker'      => __( 'QUEUE DETAIL', 'sakura-imgopt' ),
				'title'       => __( '队列明细', 'sakura-imgopt' ),
				'description' => __( '删除任务先排进这里，由 WP-Cron 逐条执行。排队、执行中、已完成与已放弃都会保留；完成的记录保留 7 天后自动清理。', 'sakura-imgopt' ),
			),
			function () use ( $counts, $rows, $state, $pagination ): void {
				$choices = array(
					''        => __( '全部', 'sakura-imgopt' ),
					'queued'  => __( '排队', 'sakura-imgopt' ),
					'running' => __( '执行中', 'sakura-imgopt' ),
					'done'    => __( '已完成', 'sakura-imgopt' ),
					'dead'    => __( '已放弃', 'sakura-imgopt' ),
				);
				if ( (int) $counts['failed'] > 0 ) {
					$choices['failed'] = __( '失败', 'sakura-imgopt' );
				}
				?>
				<nav class="queue-stats" aria-label="<?php esc_attr_e( '队列状态筛选', 'sakura-imgopt' ); ?>">
					<?php foreach ( $choices as $value => $label ) : ?>
						<?php
						$key    = '' === $value ? 'all' : $value;
						$active = $state === $value;
						$url    = Registry::url( 'storage', 'deletes', $active ? array() : ( '' === $value ? array() : array( 'state' => $value ) ) );
						?>
						<a class="queue-stat<?php echo $active ? ' is-active' : ''; ?><?php echo 0 === (int) $counts[ $key ] ? ' is-zero' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $active ? 'aria-current="true"' : ''; ?>>
							<span class="queue-stat__label"><?php echo esc_html( $label ); ?></span>
							<strong class="queue-stat__value"><?php echo esc_html( number_format_i18n( (int) $counts[ $key ] ) ); ?></strong>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php if ( array() === $rows ) : ?>
					<?php
					UI::empty_state(
						__( '队列为空', 'sakura-imgopt' ),
						__( '删除对象时任务会自动排进这里；当前没有排队、执行中或已完成的记录。', 'sakura-imgopt' )
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
										<th scope="col" class="cell-select"><input type="checkbox" data-sakura-role="select-all" aria-label="<?php esc_attr_e( '全选', 'sakura-imgopt' ); ?>" /></th>
										<th scope="col"><?php esc_html_e( 'ID', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '对象', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '状态', 'sakura-imgopt' ); ?></th>
										<th scope="col" class="is-num"><?php esc_html_e( '尝试', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '进度 / 说明', 'sakura-imgopt' ); ?></th>
										<th scope="col"><?php esc_html_e( '更新时间', 'sakura-imgopt' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rows as $row ) : ?>
										<?php
										$key   = (string) $row['key'];
										$error = (string) $row['error'];
										?>
										<tr data-state="<?php echo esc_attr( (string) $row['state'] ); ?>">
											<td class="cell-select" data-label="<?php esc_attr_e( '选择', 'sakura-imgopt' ); ?>"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: object key. */ __( '选择 %s', 'sakura-imgopt' ), $key ) ); ?>" /></td>
											<td data-label="<?php esc_attr_e( 'ID', 'sakura-imgopt' ); ?>"><span class="queue-id">#<?php echo esc_html( (string) $row['id'] ); ?></span></td>
											<td data-label="<?php esc_attr_e( '对象', 'sakura-imgopt' ); ?>"><code class="queue-key" title="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></code></td>
											<td data-label="<?php esc_attr_e( '状态', 'sakura-imgopt' ); ?>"><?php UI::badge( (string) $row['badge']['label'], (string) $row['badge']['tone'] ); ?></td>
											<td data-label="<?php esc_attr_e( '尝试', 'sakura-imgopt' ); ?>" class="is-num"><?php echo esc_html( (string) $row['attempt_label'] ); ?></td>
											<td data-label="<?php esc_attr_e( '进度 / 说明', 'sakura-imgopt' ); ?>">
												<span class="queue-progress-text"><?php echo esc_html( (string) $row['progress']['label'] ); ?></span>
												<?php if ( '' !== $error ) : ?>
													<span class="queue-error" title="<?php echo esc_attr( $error ); ?>"><?php echo esc_html( $error ); ?></span>
												<?php endif; ?>
											</td>
											<td data-label="<?php esc_attr_e( '更新时间', 'sakura-imgopt' ); ?>"><span class="queue-time"><?php echo esc_html( (string) $row['updated_label'] ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<div class="queue-foot">
							<p class="queue-hint small-text"><?php esc_html_e( '重试只重排排队中、失败或已放弃的记录；丢弃只删队列记录，不会删除云端对象。导出 CSV 包含对象 key、所属桶与完成结果。', 'sakura-imgopt' ); ?></p>
							<div class="button-row queue-actions">
								<button type="submit" name="task" value="retry" class="btn btn--primary btn--small"><?php esc_html_e( '重试选中', 'sakura-imgopt' ); ?></button>
								<button type="submit" name="task" value="drop" class="btn btn--danger btn--small" data-sakura-confirm-task="drop"><?php esc_html_e( '丢弃选中', 'sakura-imgopt' ); ?></button>
								<button type="submit" name="task" value="export" class="btn btn--ghost btn--small"><?php esc_html_e( '导出 CSV', 'sakura-imgopt' ); ?></button>
							</div>
						</div>
					</form>

					<?php $this->render_pagination( $pagination, $state ); ?>
				<?php endif; ?>
				<?php
			}
		);
		?>
		</div>
		<?php
		$body = (string) ob_get_clean();

		UI::page(
			$page_args,
			static function () use ( $body ): void {
				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while buffering.
			}
		);
	}

	/**
	 * Numbered pagination for the fallback table.
	 *
	 * @param array<string,mixed> $pagination Pagination data.
	 * @param string              $state      Current filter.
	 */
	private function render_pagination( array $pagination, string $state ): void {
		$pages = (int) ( $pagination['pages'] ?? 1 );
		if ( $pages <= 1 ) {
			return;
		}

		$current = (int) ( $pagination['page'] ?? 1 );
		$total   = (int) ( $pagination['total'] ?? 0 );
		$link    = static function ( int $number ) use ( $state ): string {
			$args = array( 'paged' => $number );
			if ( '' !== $state ) {
				$args['state'] = $state;
			}

			return Registry::url( 'storage', 'deletes', $args );
		};
		$from = max( 1, $current - 2 );
		$to   = min( $pages, $current + 2 );
		?>
		<nav class="pagination" aria-label="<?php esc_attr_e( '队列分页', 'sakura-imgopt' ); ?>">
			<span class="pagination__summary"><?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages, 3: total rows. */ __( '第 %1$s / %2$s 页 · 共 %3$s 条', 'sakura-imgopt' ), number_format_i18n( $current ), number_format_i18n( $pages ), number_format_i18n( $total ) ) ); ?></span>
			<div class="pagination__pages">
				<?php if ( $current > 1 ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $current - 1 ) ); ?>" aria-label="<?php esc_attr_e( '上一页', 'sakura-imgopt' ); ?>">‹</a>
				<?php else : ?>
					<button type="button" class="pagination__link" disabled>‹</button>
				<?php endif; ?>
				<?php if ( $from > 1 ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( 1 ) ); ?>">1</a>
					<?php if ( $from > 2 ) : ?><span class="pagination__ellipsis">…</span><?php endif; ?>
				<?php endif; ?>
				<?php for ( $number = $from; $number <= $to; $number++ ) : ?>
					<?php if ( $number === $current ) : ?>
						<span class="pagination__link is-current" aria-current="page"><?php echo esc_html( number_format_i18n( $number ) ); ?></span>
					<?php else : ?>
						<a class="pagination__link" href="<?php echo esc_url( $link( $number ) ); ?>"><?php echo esc_html( number_format_i18n( $number ) ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
				<?php if ( $to < $pages ) : ?>
					<?php if ( $to < $pages - 1 ) : ?><span class="pagination__ellipsis">…</span><?php endif; ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $pages ) ); ?>"><?php echo esc_html( number_format_i18n( $pages ) ); ?></a>
				<?php endif; ?>
				<?php if ( $current < $pages ) : ?>
					<a class="pagination__link" href="<?php echo esc_url( $link( $current + 1 ) ); ?>" aria-label="<?php esc_attr_e( '下一页', 'sakura-imgopt' ); ?>">›</a>
				<?php else : ?>
					<button type="button" class="pagination__link" disabled>›</button>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Stream the selected rows as CSV.
	 *
	 * @param array<int,int> $ids Selected row ids; empty exports everything.
	 */
	private function stream_csv( array $ids ): void {
		$rows = ( new Delete_Jobs() )->export_rows( $ids );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sakura-delete-queue-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'wb' );
		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array( 'id', 'attachment_id', 'profile_id', 'state', 'attempts', 'object_key', 'last_error', 'created_at', 'updated_at', 'completed_at', 'resolution' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_values( $row ), ',', '"', '' );
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
}
