<?php
/**
 * Background action queue worker.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage_Adapter;
use Sakura\ImageOptimizer\S3\Sync_Status;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;
use Sakura\ImageOptimizer\S3\Sync_Actions;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Executes queued upload/download/delete jobs with exponential backoff.
 *
 * Jobs are produced for large objects or batches by the controller; each tick
 * runs at most ten jobs so a slow object cannot monopolize a cron request.
 */
final class Action_Worker implements Module {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'sakura_imgopt_sync_job_tick';

	/**
	 * Jobs per tick.
	 */
	private const BATCH = 10;

	private Options $options;
	private Storage_Adapter $adapter;
	private Index_Repository $index;

	/**
	 * Constructor.
	 *
	 * @param Options          $options Options.
	 * @param Storage_Adapter  $adapter Adapter.
	 * @param Index_Repository $index   Index.
	 */
	public function __construct( Options $options, Storage_Adapter $adapter, Index_Repository $index ) {
		$this->options = $options;
		$this->adapter = $adapter;
		$this->index   = $index;
	}

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule a tick.
	 *
	 * @param int $delay Delay seconds.
	 * @return void
	 */
	public static function schedule( $delay = 60 ): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 10, (int) $delay ), self::HOOK );
	}

	/**
	 * Run due jobs.
	 *
	 * @return void
	 */
	public function run(): void {
		$jobs = $this->index->due_jobs( self::BATCH );
		foreach ( $jobs as $job ) {
			$job_id  = (int) $job->id;
			$profile = (string) $job->profile_id;
			$key     = (string) $job->object_key;
			$action  = (string) $job->action;
			$target  = (string) ( $job->target_hash ?? '' );
			$attachment_id = (int) ( $job->attachment_id ?? 0 );

			$claim_token = $this->index->job_start( $job_id );
			if ( false === $claim_token ) {
				continue;
			}

			$resolved = $this->resolve( $profile );
			if ( is_wp_error( $resolved ) ) {
				$result = $resolved;
			} elseif ( '' !== $target && ! Synchronous_Uploader::target_matches( $target, $resolved['options'], $profile ) ) {
				$result = new \WP_Error( 'sio_job_target_changed', __( '排队后存储目标已变更，已停止该动作，请重新发起。', 'sakura-imgopt' ) );
			} else {
				try {
					$result = $resolved['adapter']->with_mutation_check(
						fn(): bool => $this->index->job_renew( $job_id, $claim_token ),
						fn() => $this->execute( $key, $action, $resolved['options'], $resolved['adapter'] )
					);
				} catch ( \Throwable $e ) {
					$result = new \WP_Error( 'sio_job_exception', $e->getMessage() );
				}
			}
			if ( is_wp_error( $result ) ) {
				$state = $this->index->job_failure( $job_id, $result->get_error_message(), $claim_token );
				if ( empty( $state['applied'] ) ) {
					continue;
				}
				Metrics::bump( array( 'actions_failed' => 1 ) );
				if ( ! empty( $state['dead'] ) ) {
					Metrics::bump( array( 'jobs_dead' => 1 ) );
				}
				$this->index->event(
					$profile,
					'error',
					'action_failed',
					sprintf(
						/* translators: 1: action, 2: object key, 3: error. */
						__( '队列动作 %1$s 失败：%2$s — %3$s', 'sakura-imgopt' ),
						$action,
						$key,
						$result->get_error_message()
					),
					array(
						'job'      => $job_id,
						'attempts' => (int) $state['attempts'],
						'dead'     => ! empty( $state['dead'] ),
					)
				);
				continue;
			}

			if ( ! $this->index->job_success( $job_id, $claim_token ) ) {
				continue;
			}
			$this->index->apply_action( $profile, $key, $action );
			$this->write_back( $attachment_id, $key, $action, $resolved, $target, $profile );
			Metrics::bump( array( 'actions_ok' => 1 ) );
			$this->index->event( $profile, 'info', 'action_ok', sprintf( /* translators: 1: action, 2: object key. */ __( '队列动作 %1$s 完成：%2$s', 'sakura-imgopt' ), $action, $key ), array( 'job' => $job_id ) );
		}

		if ( $this->index->has_queued_jobs() ) {
			self::schedule( 60 );
		}
	}

	/**
	 * Execute one action.
	 *
	 * @param string $key    Object key.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function execute( string $key, string $action, Options $options, Storage_Adapter $adapter ) {
		$actions = new Sync_Actions( $options, $adapter );
		switch ( $action ) {
			case 'upload':
				return $actions->upload( $key );
			case 'download':
				return $actions->download( $key );
			case 'delete':
				return $actions->delete( $key );
		}
		return new \WP_Error( 'sio_job_action', __( '未知的队列动作。', 'sakura-imgopt' ) );
	}

	/**
	 * Resolve the options and adapter captured when the job was queued.
	 *
	 * @param string $profile_id Stored profile id.
	 * @return array{options:Options,adapter:Storage_Adapter}|\WP_Error
	 */
	private function resolve( string $profile_id ) {
		if ( '' === $profile_id || $profile_id === (string) $this->options->active_profile_id() ) {
			return array( 'options' => $this->options, 'adapter' => $this->adapter );
		}
		$saved = $this->options->profile_options( $profile_id );
		if ( ! is_array( $saved ) || empty( $saved['bucket'] ) ) {
			return new \WP_Error( 'sio_job_profile_missing', __( '该任务创建时的存储配置已不存在或不可用，已停止动作。', 'sakura-imgopt' ) );
		}
		$options = new Options( $saved );
		return array( 'options' => $options, 'adapter' => new Storage_Adapter( $options ) );
	}

	/**
	 * Reflect a successful manual action in attachment sync state.
	 *
	 * Public so the inline (non-queued) upload path can reuse the exact same
	 * bookkeeping as the background worker.
	 *
	 * @param int                 $attachment_id Attachment id (0 when unknown).
	 * @param string              $key           Object key.
	 * @param string              $action        Action.
	 * @param array<string,mixed> $resolved      Resolved options/adapter.
	 * @param string              $job_target    Target captured when queued.
	 * @param string              $profile_id    Profile the row belongs to.
	 * @return void
	 */
	public function write_back( int $attachment_id, string $key, string $action, $resolved, string $job_target = '', string $profile_id = '' ): void {
		if ( $attachment_id <= 0 || ! is_array( $resolved ) ) {
			return;
		}
		$status = new Sync_Status();
		if ( 'delete' === $action ) {
			$status->mark_object_removed( $attachment_id, $key );
			return;
		}
		// Upload and download rebuild the same bookkeeping: the manifest lists
		// what is on disk, and a restored key is known to sit in this bucket
		// because it was just read from it.
		if ( 'upload' !== $action && 'download' !== $action ) {
			return;
		}
		$status->record_object( $attachment_id, $key );
		$uploader = new Synchronous_Uploader( $resolved['options'], $resolved['adapter'], $status );
		$desired  = $uploader->desired_keys( $attachment_id );
		$recorded = $status->objects( $attachment_id );
		if ( array() !== $desired && array() === array_values( array_diff( $desired, $recorded ) ) ) {
			$versions = $status->versions( $attachment_id );
			foreach ( $uploader->manifest( $attachment_id ) as $manifest_key => $file ) {
				$versions[ (string) $manifest_key ] = (string) $file['version'];
			}
			$status->set_versions( $attachment_id, $versions );
			$resolved_target = Synchronous_Uploader::target_hash_for( $resolved['options'] );
			// A row queued before the fingerprint dropped the profile row id still
			// points at this same bucket, so it may finish and re-stamp the
			// attachment with the current form.
			if ( '' !== $job_target && ! Synchronous_Uploader::target_matches( $job_target, $resolved['options'], $profile_id ) ) {
				return;
			}
			$status->mark_synced( $attachment_id, $resolved_target, $profile_id );
		}
	}
}
