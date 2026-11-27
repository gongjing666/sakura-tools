<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Bucket_Identity;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Actions;
use Sakura\ImageOptimizer\S3\Probe_Runner;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Settings_Saver;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\S3\Delete_Jobs;
use Sakura\ImageOptimizer\S3\Upload_Metrics;
use Sakura\ImageOptimizer\S3\Delete_Worker;
use Sakura\ImageOptimizer\S3\Doge_Cloud_Resolver;
use Sakura\ImageOptimizer\Sync\Action_Worker;
use Sakura\ImageOptimizer\Sync\Index_Repository;
use Sakura\ImageOptimizer\Support\Hook_Loader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** S3 存储后台设置：菜单、钩子与四个职责服务的装配。 */
class Storage_Module implements Module {
	const MENU_SLUG = 'sakura-tools-storage';

	/** @var Options|null */
	private ?Options $options = null;
	/** @var Storage_Adapter|null */
	private ?Storage_Adapter $adapter = null;
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

	private function status(): Sync_Status {
		return $this->status ??= new Sync_Status();
	}

	private function page(): S3_Storage_Page {
		return $this->page ??= new S3_Storage_Page( $this->options() );
	}

	/** @param Hook_Loader $hooks Hook loader. */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_post_sakura_s3_alerts_save', array( $this, 'save_alerts' ) );
		$hooks->add_action( 'admin_post_sakura_s3_alerts_test', array( $this, 'test_alert' ) );
		$hooks->add_action( 'admin_post_sakura_s3_prometheus_rotate', array( $this, 'rotate_prometheus' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'storage', 'connection' ) ) {
			return;
		}

		// The shell bundle (admin-app.js) is enqueued by Page_Assets; this screen
		// keeps no bundle of its own.
		\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();
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

	public function render(): void {
		$this->page()->render();
	}

	/**
	 * Requeue failed uploads and dead deletes.
	 *
	 * @param int $attachment_id Attachment to retry, 0 for "all recent".
	 * @return array<string,mixed>|\WP_Error
	 */
	public function handle_retry( int $attachment_id ) {
		if ( ! $this->options()->is_enabled() || ! $this->options()->is_configured() ) {
			return new \WP_Error( 'sio_retry_disabled', __( 'S3 未启用或配置不完整，已拒绝重试。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		// R18: the failure list belongs to the previous bucket, so retrying it
		// while the reconcile gate is up would push objects into a bucket nobody
		// has scanned yet (or re-delete from it). The sync center disables this
		// button; a stale tab still holds a valid nonce, so refuse here too.
		if ( is_array( $this->options()->reconcile_pending() ) ) {
			return new \WP_Error(
				'sio_reconcile_required',
				__( '存储目标刚切换，索引还是上一个桶的。请先到同步中心点「开始完整核对」重建索引。', 'sakura-imgopt' ),
				array( 'status' => 409 )
			);
		}

		$id            = max( 0, $attachment_id );
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
			return new \WP_Error( 'sio_nothing_to_retry', __( '没有可重试的上传或删除任务。', 'sakura-imgopt' ), array( 'status' => 400 ) );
		}

		$result = array(
			'processed' => count( $upload_ids ),
			'queued'    => $queued,
			'succeeded' => 0,
			'failed'    => 0,
			'deletes'   => $delete_requeued,
		);

		return array(
			'message' => sprintf(
				/* translators: 1: upload jobs, 2: delete jobs. */
				__( '已将 %1$d 个上传任务和 %2$d 个删除任务加入持久队列。', 'sakura-imgopt' ),
				(int) $queued,
				(int) $delete_requeued
			),
			'result'  => $result,
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

}
