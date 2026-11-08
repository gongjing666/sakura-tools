<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Connection_Tester;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Manager;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Settings_Saver;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\S3\Delete_Jobs;
use Sakura\ImageOptimizer\S3\Upload_Metrics;
use Sakura\ImageOptimizer\S3\Delete_Worker;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** S3 存储后台设置：菜单、钩子与四个职责服务的装配。 */
class S3_Storage_Module implements Module {
	const MENU_SLUG = 'sakura-tools-storage';

	/** @var Options|null */
	private ?Options $options = null;
	/** @var Storage_Adapter|null */
	private ?Storage_Adapter $adapter = null;
	/** @var Settings_Saver|null */
	private ?Settings_Saver $saver = null;
	/** @var Connection_Tester|null */
	private ?Connection_Tester $tester = null;
	/** @var S3_Storage_Page|null */
	private ?S3_Storage_Page $page = null;
	/** @var Sync_Status|null */
	private ?Sync_Status $status = null;

	private function options(): Options {
		return $this->options ??= new Options();
	}

	private function adapter(): Storage_Adapter {
		return $this->adapter ??= new Storage_Adapter( $this->options() );
	}

	private function saver(): Settings_Saver {
		return $this->saver ??= new Settings_Saver( $this->options() );
	}

	private function tester(): Connection_Tester {
		return $this->tester ??= new Connection_Tester( $this->options() );
	}

	private function status(): Sync_Status {
		return $this->status ??= new Sync_Status();
	}

	private function page(): S3_Storage_Page {
		return $this->page ??= new S3_Storage_Page( $this->options(), $this->status() );
	}

	/** @param Hook_Loader $hooks Hook loader. */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_post_sakura_s3_save', array( $this, 'save' ) );
		$hooks->add_action( 'admin_post_sakura_s3_switch', array( $this, 'switch_profile' ) );
		$hooks->add_action( 'admin_post_sakura_s3_delete', array( $this, 'delete_profile' ) );
		$hooks->add_action( 'admin_post_sakura_s3_test', array( $this, 'test_connection' ) );
		$hooks->add_action( 'admin_post_sakura_s3_alerts_save', array( $this, 'save_alerts' ) );
		$hooks->add_action( 'admin_post_sakura_s3_alerts_test', array( $this, 'test_alert' ) );
		$hooks->add_action( 'admin_post_sakura_s3_prometheus_rotate', array( $this, 'rotate_prometheus' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_retry', array( $this, 'retry_failed' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_upload_metrics', array( $this, 'upload_metrics' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'storage', 'connection' ) ) {
			return;
		}

		\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();
	}

	public function save(): void {
		$this->saver()->save();
	}

	/** Persist monitoring alert settings. */
	public function save_alerts(): void {
		\Sakura\ImageOptimizer\S3\Monitoring_Panel::handle_save();
	}

	/** Send a test webhook alert. */
	public function test_alert(): void {
		\Sakura\ImageOptimizer\S3\Monitoring_Panel::handle_test();
	}

	/** Rotate the Prometheus scrape token. */
	public function rotate_prometheus(): void {
		\Sakura\ImageOptimizer\S3\Monitoring_Panel::handle_rotate();
	}

	/** Activate a saved provider profile. */
	public function switch_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_switch' );

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$manager    = new Profile_Manager();
		$result     = $manager->switch_profile( $profile_id );

		if ( empty( $result['success'] ) ) {
			$error = isset( $result['error'] ) && 'path_conflict' === $result['error'] ? 'switch_path' : 'switch';
			$this->redirect( array( 'saved' => '0', 'error' => $error ) );
		}

		$this->redirect(
			array(
				'saved'    => '1',
				'switched' => '1',
			)
		);
	}

	/** Delete a saved profile. */
	public function delete_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		check_admin_referer( 'sakura_s3_delete_' . $profile_id );

		$manager = new Profile_Manager();
		$result  = $manager->delete_profile( $profile_id );

		if ( empty( $result['success'] ) ) {
			$error = 'active' === ( $result['error'] ?? '' ) ? 'delete_active' : 'delete';
			$this->redirect( array( 'saved' => '0', 'error' => $error ) );
		}

		$this->redirect(
			array(
				'saved'   => '1',
				'deleted' => '1',
			)
		);
	}

	public function test_connection(): void {
		$this->tester()->test();
	}

	/**
	 * Return upload queue metrics for the auto-refreshing card.
	 */
	public function upload_metrics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_upload_metrics' );

		wp_send_json_success( ( new Upload_Metrics() )->snapshot() );
	}

	public function render(): void {
		$this->page()->render();
	}

	public function retry_failed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}
		check_ajax_referer( 'sakura_s3_retry' );

		if ( ! $this->options()->is_enabled() || ! $this->options()->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'S3 未启用或配置不完整，已拒绝重试。', 'sakura-imgopt' ) ), 400 );
		}

		$id            = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		$deletes       = new Delete_Jobs();
		$status        = $this->status();
		$delete_requeued = 0;
		$upload_ids    = array();

		if ( $id > 0 ) {
			$delete_requeued = $deletes->retry_attachment( $id );
			if ( in_array( $status->status( $id ), array( Sync_Status::FAILED, Sync_Status::DEAD ), true ) ) {
				$upload_ids = array( $id );
			}
		} else {
			$delete_requeued = $deletes->requeue_dead( 100 );
			$upload_ids      = array_map( 'intval', $status->failed_attachments( 10 ) );
		}
		if ( $delete_requeued > 0 ) {
			Delete_Worker::kick();
		}

		$index  = new Index_Repository();
		$queued = 0;
		foreach ( $upload_ids as $attachment_id ) {
			$queued += $this->enqueue_upload_retry( (int) $attachment_id, $status, $index );
		}
		if ( $queued > 0 ) {
			Action_Worker::schedule( 15 );
		}

		if ( 0 === $queued && 0 === $delete_requeued ) {
			wp_send_json_error( array( 'message' => __( '没有可重试的上传或删除任务。', 'sakura-imgopt' ) ), 400 );
		}

		$result = array(
			'processed' => count( $upload_ids ),
			'queued'    => $queued,
			'succeeded' => 0,
			'failed'    => 0,
			'deletes'   => $delete_requeued,
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: upload jobs, 2: delete jobs. */
					__( '已将 %1$d 个上传任务和 %2$d 个删除任务加入持久队列。', 'sakura-imgopt' ),
					(int) $queued,
					(int) $delete_requeued
				),
				'result'  => $result,
			)
		);
	}

	/**
	 * Convert held failure evidence into durable sync-center upload jobs.
	 *
	 * @param int                    $attachment_id Attachment ID.
	 * @param Sync_Status            $status State store.
	 * @param Index_Repository       $index Queue repository.
	 * @return int Accepted/deduplicated job count.
	 */
	private function enqueue_upload_retry( int $attachment_id, Sync_Status $status, Index_Repository $index ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}
		$options = $this->options();
		$target  = Synchronous_Uploader::target_hash_for( $options );
		$profile = (string) $options->active_profile_id();
		$entries = $status->failures( $attachment_id );
		if ( ! $entries ) {
			$uploader = new Synchronous_Uploader( $options, $this->adapter(), $status );
			foreach ( $uploader->manifest( $attachment_id ) as $key => $file ) {
				$entries[] = array( 'key' => $key, 'version' => (string) $file['version'] );
			}
		}

		$queued = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) ) {
				continue;
			}
			$entry_profile = '' !== (string) ( $entry['profile'] ?? '' ) ? (string) $entry['profile'] : $profile;
			$entry_target  = '' !== (string) ( $entry['target'] ?? '' ) ? (string) $entry['target'] : $target;
			$job = $index->enqueue_job(
				$entry_profile,
				(string) $entry['key'],
				'upload',
				0,
				$attachment_id,
				$entry_target,
				(string) ( $entry['version'] ?? '' )
			);
			if ( ! is_wp_error( $job ) ) {
				++$queued;
			}
		}
		return $queued;
	}

	/** Redirect to the S3 settings screen; never returns. */
	private function redirect( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\S3_Storage_Module', __NAMESPACE__ . '\\S3_Settings' );
