<?php
namespace Sakura\ImageOptimizer;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Bucket_Identity;
use Sakura\ImageOptimizer\S3\Client_Factory;
use Sakura\ImageOptimizer\S3\Connection_Probe;
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
		$manager    = new Profile_Manager();

		if ( 'off' === $intent ) {
			$result = $manager->disable();
		} elseif ( '' === $profile_id ) {
			wp_send_json_error( array( 'message' => __( '没有选中任何配置。', 'sakura-imgopt' ) ), 400 );
		} else {
			$result = $manager->enable_profile( $profile_id );
		}

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $this->toggle_error_message( (string) ( $result['error'] ?? '' ) ) ), 400 );
		}

		$payload = S3_Storage_Page::state_payload();

		// A switch is the one click that invalidates work the operator already
		// did: the thumbnail rewrite is turned off and the sync center now shows
		// a bucket nobody has scanned. The card's toast is far from the controls
		// that changed, so the same click also answers with the shared dialog;
		// the client opens it in place of the toast.
		if ( ! empty( $result['moved'] ) ) {
			$payload['switchModal'] = S3_Storage_Page::switch_modal_html(
				(string) ( $result['from'] ?? '' ),
				(string) ( $payload['activeName'] ?? '' ),
				! empty( $payload['behaviourFrozen'] )
			);
			$payload['switched']    = true;
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Operator-facing text for a failed card toggle.
	 *
	 * @param string $error Error code from Profile_Manager.
	 */
	private function toggle_error_message( string $error ): string {
		if ( 'incomplete' === $error ) {
			return __( '这份配置不完整或密钥无法读取，补全后再试。', 'sakura-imgopt' );
		}
		if ( 'path_conflict' === $error ) {
			return __( '站点已指定自己的上传目录，这份配置的云端路径与它不一致，无法启用。', 'sakura-imgopt' );
		}
		if ( 'locked' === $error ) {
			// R10: a high-risk binding deleted the local copies, so the other profiles
			// point at buckets that never received these attachments. Switching to one
			// is a guaranteed dead link, and the only exit is the documented teardown.
			return __( '当前处于高风险锁定：本地副本已删除，只有这份配置对应的存储桶里有这些文件，切到别的配置会变成死链。如需更换存储服务商或存储桶，请先点当前配置卡片关闭 S3 存储，删除其余全部存储配置，再删除当前配置。', 'sakura-imgopt' );
		}

		return __( '没有找到这份配置，可能已被删除，请刷新页面。', 'sakura-imgopt' );
	}

	/** Delete a saved profile. */
	public function delete_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		check_admin_referer( 'sakura_s3_delete_' . $profile_id );

		$manager = new Profile_Manager();
		// Capture the bucket before the row is gone: the index is partitioned by
		// bucket (R19.7) and a deleted profile can no longer name its own.
		$repository     = new Index_Repository();
		$profile_row    = $this->options()->profile( $profile_id );
		$profile_bucket = array() !== $profile_row ? Bucket_Identity::from_profile( $profile_row, $this->options() )->hash() : '';
		$result         = $manager->delete_profile( $profile_id );

		if ( empty( $result['success'] ) ) {
			$code = (string) ( $result['error'] ?? '' );
			$this->redirect(
				array(
					'saved' => '0',
					'error' => in_array( $code, array( 'delete_active', 'delete_bound', 'pending_profiles', 'missing' ), true ) ? $code : 'delete',
				)
			);
		}

		// R8 steps 2-3 (07-S3存储生命周期): scans, events and the delete queue are
		// partitioned by the profile id, so dropping the profile without its rows
		// would leave that history — and a queue that retries forever against an
		// adapter that no longer exists — with no screen able to show or clear it.
		// The index rows are partitioned by bucket instead (R19.7) and stay put
		// while another configuration row still names the same bucket.
		$repository->purge_index( $profile_id, $profile_bucket );
		( new Delete_Jobs() )->forget_profile( $profile_id );

		// There is no R8 step 7 on purpose (07-S3存储生命周期 R8 只做 6 步).
		// Earlier drafts of 08-OpenList迁移通道 planned a resident OpenList mirror
		// mount per profile that this delete would have to unmount too. That
		// migration channel is not being built (see 08 §11): moving objects from
		// one bucket to another stays a manual job, so a profile delete has no
		// external mount to clean up and must never depend on OpenList.

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

	/**
	 * Resolve 多吉云 SDK parameters for a storage-space name.
	 *
	 * 多吉云's console only exposes the space name; the S3 client needs the
	 * appid-suffixed s3Bucket and the COS endpoint, and both live in the same
	 * temporary-credential response the client already fetches. Asking the
	 * operator to hand-copy them is how a working account ends up pointed at
	 * `api.dogecloud.com` with a 404.
	 *
	 * Credentials come from the form when the operator typed a new pair (so a
	 * first-time setup can resolve before anything is saved), otherwise from
	 * the active profile.
	 */
	public function doge_buckets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_doge_buckets' );

		$space  = isset( $_POST['space'] ) ? sanitize_text_field( wp_unslash( $_POST['space'] ) ) : '';
		$key    = isset( $_POST['key'] ) ? Settings_Saver::clean_credential( (string) wp_unslash( $_POST['key'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? Settings_Saver::clean_credential( (string) wp_unslash( $_POST['secret'] ) ) : '';

		if ( '' === $key || '' === $secret ) {
			$saved  = $this->options()->all();
			$key    = (string) ( $saved['key'] ?? '' );
			$secret = (string) ( $saved['secret'] ?? '' );
		}

		if ( '' === $key || '' === $secret ) {
			wp_send_json_error(
				array( 'message' => __( '请先填写多吉云的 Access Key 和 Secret Key，或保存一份可用的配置。', 'sakura-imgopt' ) ),
				400
			);
		}

		$buckets = ( new S3_Doge_Cloud( $key, $secret ) )->get_buckets();
		if ( ! is_array( $buckets ) ) {
			wp_send_json_error(
				array( 'message' => __( '多吉云接口没有返回存储空间列表，请检查 Access Key 和 Secret Key 是否正确。', 'sakura-imgopt' ) ),
				400
			);
		}

		wp_send_json_success( Doge_Cloud_Resolver::resolve( $buckets, $space ) );
	}

	/**
	 * Dry-run the connection settings that are currently typed in the form.
	 *
	 * The save path can only report a verdict after a profile exists, which
	 * means a wrong endpoint costs a save, a failed upload and a cleanup. This
	 * endpoint takes the same values straight off the form, including a brand
	 * new key pair that was never stored, and returns the probe verdict so the
	 * operator can fix a typo before anything is written.
	 */
	public function probe_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_probe' );

		$saved = $this->options()->all();
		$probe = $saved;

		if ( isset( $_POST['provider'] ) ) {
			$probe['provider'] = sanitize_key( wp_unslash( $_POST['provider'] ) );
		}

		foreach ( array( 'bucket', 'region' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$probe[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['endpoint'] ) ) {
			$probe['endpoint'] = esc_url_raw( trim( (string) wp_unslash( $_POST['endpoint'] ) ), array( 'http', 'https' ) );
		}

		// A blank credential means "keep the saved one": the form masks the
		// stored key so it is never re-submitted unless it was replaced.
		foreach ( array( 'key', 'secret' ) as $field ) {
			$typed = isset( $_POST[ $field ] ) ? Settings_Saver::clean_credential( (string) wp_unslash( $_POST[ $field ] ) ) : '';
			if ( '' !== $typed ) {
				$probe[ $field ] = $typed;
			}
		}

		$candidate = new Options( $probe );
		$missing   = $candidate->configuration_errors();
		if ( array() !== $missing ) {
			wp_send_json_error( array( 'message' => $this->missing_fields_message( $missing ) ), 400 );
		}

		try {
			$client = Client_Factory::make( $probe );
			// 凭据惰性取：不预检的话，取不到 token 会被下面的探针说成 Endpoint 不通。
			Client_Factory::preflight( $probe );
		} catch ( \Throwable $error ) {
			// The verdict still reaches the operator: a client that never got
			// built is a failed test, not a broken request.
			wp_send_json_success(
				array(
					'ok'      => false,
					'message' => $error->getMessage(),
					'code'    => '',
					'status'  => 0,
					'latency' => 0,
				)
			);
		}

		wp_send_json_success(
			Connection_Probe::diagnose(
				$client,
				(string) $probe['bucket'],
				array( (string) $probe['key'], (string) $probe['secret'] )
			)
		);
	}

	/**
	 * Test one saved profile without switching to it.
	 *
	 * The card is a saved configuration, not the live one: testing it through
	 * the form probe would test whatever happens to be typed there. This reads
	 * the profile's own stored credentials, builds a client for it and reports
	 * the verdict, so a saved but unused bucket can be checked for a dead key or
	 * a bucket that was deleted in the provider console before anything is
	 * switched over to it.
	 */
	public function probe_profile(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( 'sakura_s3_probe_profile' );

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$snapshot   = $this->options()->profile_options( $profile_id );
		if ( ! is_array( $snapshot ) ) {
			wp_send_json_error( array( 'message' => __( '找不到这份配置，它可能已经被删除了。', 'sakura-imgopt' ) ), 404 );
		}

		/*
		 * The snapshot carries ciphertext by design (that is what the queue
		 * worker stores). Committing the Options constructor is what decrypts
		 * it, and the client factory must never see the raw row: it reads key and
		 * secret straight off the array it is handed, and a ciphertext pair would
		 * fail the signature check while looking like a wrong key.
		 */
		$candidate = new Options( $snapshot );
		$resolved  = $candidate->all();
		$missing   = $candidate->configuration_errors();
		if ( array() !== $missing ) {
			wp_send_json_success(
				array(
					'ok'      => false,
					'message' => $this->missing_fields_message( $missing ),
					'code'    => '',
					'status'  => 0,
					'latency' => 0,
				)
			);
		}

		try {
			$client = Client_Factory::make( $resolved );
			// 同上：这份配置可能是惰性凭据的，预检才能把取 token 的失败讲清楚。
			Client_Factory::preflight( $resolved );
		} catch ( \Throwable $error ) {
			wp_send_json_success(
				array(
					'ok'      => false,
					'message' => $error->getMessage(),
					'code'    => '',
					'status'  => 0,
					'latency' => 0,
				)
			);
		}

		wp_send_json_success(
			Connection_Probe::diagnose(
				$client,
				(string) $resolved['bucket'],
				array( (string) $resolved['key'], (string) $resolved['secret'] )
			)
		);
	}

	/**
	 * Name the fields the probe cannot run without.
	 *
	 * @param string[] $fields Error keys from Options::configuration_errors().
	 */
	private function missing_fields_message( array $fields ): string {
		$labels = array(
			'provider' => __( '服务商', 'sakura-imgopt' ),
			'bucket'   => __( '存储桶名称', 'sakura-imgopt' ),
			'key'      => __( 'Access Key', 'sakura-imgopt' ),
			'secret'   => __( 'Secret Key', 'sakura-imgopt' ),
			'endpoint' => __( '服务地址', 'sakura-imgopt' ),
		);

		$names = array();
		foreach ( $fields as $field ) {
			$names[] = $labels[ $field ] ?? $field;
		}

		return sprintf(
			/* translators: %s: comma-separated field names. */
			__( '还差这些内容才能测试：%s。', 'sakura-imgopt' ),
			implode( '、', $names )
		);
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
