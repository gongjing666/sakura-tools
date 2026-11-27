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
 * The screen is a React island: PHP prints the shell and one bootstrap
 * document built by Deletes_Payload, and the client polls the snapshot
 * endpoint for fresh rows.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Deletes_Payload;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Support\Hook_Loader;

/**
 * Renders the delete queue and handles its row actions.
 */
final class Delete_Queue_Panel {

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
	 * Load the shared admin chrome for this screen.
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
	}

	/**
	 * Handle retry / drop / export submissions.
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

		$data = ( new Deletes_Payload() )->build();

		require __DIR__ . '/../../includes/views/deletes-island.php';
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
