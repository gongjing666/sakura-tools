<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Bucket_Identity;
use Sakura\ImageOptimizer\S3\Client_Factory;
use Sakura\ImageOptimizer\S3\Connection_Probe;
use Sakura\ImageOptimizer\S3\Connection_Tester;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Profile_Actions;
use Sakura\ImageOptimizer\S3\Probe_Runner;
use Sakura\ImageOptimizer\S3\Profile_Manager;
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
		$hooks->add_action( 'wp_ajax_sakura_s3_doge_buckets', array( $this, 'doge_buckets' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_probe_connection', array( $this, 'probe_connection' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_probe_profile', array( $this, 'probe_profile' ) );
		$hooks->add_action( 'wp_ajax_sakura_s3_toggle_profile', array( $this, 'toggle_profile' ) );
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! \Sakura\ImageOptimizer\Admin\Registry::on( 'storage', 'connection' ) ) {
			return;
		}

		\Sakura\ImageOptimizer\Admin\Assets::enqueue_page();

		$bundle = 'assets/ui/dist/storage.js';

		if ( is_readable( SAKURA_TOOLS_PATH . $bundle ) ) {
			wp_enqueue_script(
				'sakura-admin-storage',
				SAKURA_TOOLS_URL . $bundle,
				array( 'sakura-admin-core' ),
				\Sakura\ImageOptimizer\Admin\Page_Assets::asset_version( $bundle ),
				true
			);
		}
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

	/**
	 * Switch a saved profile on or off from its card.
	 *
	 * The card itself is the switch now: clicking the card already in use turns
	 * S3 storage off, clicking any other card switches to it and turns storage
	 * on. Keeping the toggle on the card is what makes "which bucket is live"
	 * and "is it on" a single visible state instead of two controls that can
	 * disagree.
	 */
	public function toggle_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_toggle_profile' );

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$intent     = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : 'on';
		$result     = Profile_Actions::toggle( $profile_id, $intent );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) $result['message'],
					'code'    => (string) $result['code'],
				),
				400
			);
		}

		$payload = (array) $result['state'];

		// A switch is the one click that invalidates work the operator already
		// did. The legacy script draws the dialog from markup; the React client
		// reads the structured copy that arrives with the island instead.
		if ( is_array( $result['switch'] ) ) {
			$payload['switchModal']  = S3_Storage_Page::switch_modal_html(
				(string) ( $payload['previousName'] ?? '' ),
				(string) ( $payload['activeName'] ?? '' ),
				! empty( $payload['behaviourFrozen'] )
			);
			$payload['switchNotice'] = $result['switch'];
		}

		wp_send_json_success( $payload );
	}

	/** Delete a saved profile. */
	public function delete_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		check_admin_referer( 'sakura_s3_delete_' . $profile_id );

		$result = Profile_Actions::delete( $profile_id );

		if ( empty( $result['ok'] ) ) {
			$this->redirect(
				array(
					'saved' => '0',
					'error' => (string) $result['code'],
				)
			);
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

	public function doge_buckets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_doge_buckets' );

		$result = Probe_Runner::doge_buckets( (array) wp_unslash( $_POST ) );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ), (int) $result['http'] );
		}

		wp_send_json_success( (array) $result['data'] );
	}

	public function probe_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_probe' );

		$verdict = Probe_Runner::run_form( (array) wp_unslash( $_POST ) );
		$http    = (int) ( $verdict['http'] ?? 200 );
		unset( $verdict['http'] );

		if ( $http >= 400 ) {
			wp_send_json_error( array( 'message' => (string) ( $verdict['message'] ?? '' ) ), $http );
		}

		wp_send_json_success( $verdict );
	}

	public function probe_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_probe_profile' );

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$verdict    = Probe_Runner::run_profile( $profile_id );
		$http       = (int) ( $verdict['http'] ?? 200 );
		unset( $verdict['http'] );

		if ( $http >= 400 ) {
			wp_send_json_error( array( 'message' => (string) ( $verdict['message'] ?? '' ) ), $http );
		}

		wp_send_json_success( $verdict );
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

		// R18: the failure list belongs to the previous bucket, so retrying it
		// while the reconcile gate is up would push objects into a bucket nobody
		// has scanned yet (or re-delete from it). The sync center disables this
		// button; a stale tab still holds a valid nonce, so refuse here too.
		if ( is_array( $this->options()->reconcile_pending() ) ) {
			wp_send_json_error(
				array(
					'message' => __( '存储目标刚切换，索引还是上一个桶的。请先到同步中心点「开始完整核对」重建索引。', 'sakura-imgopt' ),
					'code'    => 'sio_reconcile_required',
				),
				409
			);
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
