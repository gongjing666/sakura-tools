<?php
/**
 * Activation routine.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Writes defaults and migrates legacy options on activation.
 */
final class Activation {

	/**
	 * Run activation.
	 */
	public static function run(): void {
		$defaults = array(
			'disabled_sizes'        => array(),
			'enable_compression'    => 0,
			'compression_mode'      => 'compress_only',
			'convert_format'        => 'webp',
			'source_formats'        => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_SOURCE_FORMATS,
			'quality'               => 82,
			'max_file_size_mb'      => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_FILE_SIZE_MB,
			'max_image_width'       => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_WIDTH,
			'max_image_height'      => \Sakura\ImageOptimizer\Settings\Settings_Repository::DEFAULT_MAX_IMAGE_HEIGHT,
			'backup_enabled'        => 1,
			'backup_retention_days' => 0,
			'backup_max_mb'         => 0,
			'rename_enabled'        => 0,
			'rename_rule'           => '{date}-{time}-{rand}',
			'batch_size'            => 1,
			'ajax_delay'            => 500,
			'upload_concurrency'    => 1,
			'delete_data_on_uninstall' => 0,
		);

		// S3 存储沿用已停用的 S3 Uploads 配置键，实现无损接管。
		// 新安装默认关闭；旧配置完整时由运行时自动视为启用，不覆盖已有凭据与媒体路径。
		if ( get_option( 's3_options', false ) === false ) {
			add_option(
				's3_options',
				array(
					'enabled'         => 'false',
					'provider'        => 'cloudflare',
					'bucket'          => '',
					'region'          => 'us-east-1',
					'endpoint'        => '',
					'key'             => '',
					'secret'          => '',
					'no_thumb'        => 'false',
					'no_local_saving' => 'false',
					'upload_url_path' => '',
					'object_acl'      => 'public-read',
				),
				'',
				false
			);
		}

		// 从旧 irmia_* 配置迁移到新 sakura_* 配置。
		// 只在新配置不存在时迁移，避免覆盖用户后续保存的新配置。
		foreach ( $defaults as $key => $val ) {
			$new_key = 'sakura_imgopt_' . $key;
			$old_key = 'irmia_imgopt_' . $key;

			if ( false !== get_option( $new_key ) ) {
				continue;
			}

			$old_val = get_option( $old_key, null );
			update_option( $new_key, null !== $old_val ? $old_val : $val );
		}

		// 迁移旧版 irmia_imgopt_disable_all_thumbs / sakura_imgopt_disable_all_thumbs → 新 disabled_sizes
		foreach ( array( 'irmia_imgopt_disable_all_thumbs', 'sakura_imgopt_disable_all_thumbs' ) as $legacy_key ) {
			$old_disable = get_option( $legacy_key, null );
			if ( null !== $old_disable ) {
				if ( 1 === (int) $old_disable && array() === get_option( 'sakura_imgopt_disabled_sizes', array() ) ) {
					// 旧版开启了"全部禁用" → 迁移为禁用所有已知尺寸
					$all_sizes   = get_intermediate_image_sizes();
					$all_sizes[] = '_big_threshold';
					update_option( 'sakura_imgopt_disabled_sizes', $all_sizes );
				}
				delete_option( $legacy_key );
			}
		}

		// 创建/升级对账索引表。
		\Sakura\ImageOptimizer\Sync\Schema::maybe_install();

		// 上传指标事件表：并发写入用追加行，而不是读改写 option。
		\Sakura\ImageOptimizer\S3\Upload_Metrics::maybe_install();

		// 用户文件空间：建表并记录版本；后台任务由 Module 在 init 上排程。
		\Nboke\UserFiles\Schema::maybe_install();
		if ( ! wp_next_scheduled( 'sufm_tick' ) ) {
			wp_schedule_single_event( time() + 30, 'sufm_tick' );
		}

		// 索引维护（事件保留、kind 回填续跑）。
		\Sakura\ImageOptimizer\Sync\Scan_Worker::schedule_maintenance();

		// 远端删除补偿队列：每分钟处理到期的删除任务。
		\Sakura\ImageOptimizer\S3\Delete_Worker::schedule();
		\Sakura\ImageOptimizer\Sync\Verify_Worker::schedule();

		// 上传告警：每 15 分钟检查一次；Prometheus 令牌按需生成。
		\Sakura\ImageOptimizer\S3\Alerting::schedule();
		\Sakura\ImageOptimizer\S3\Prometheus::token();

		// 注册每日备份清理定时任务（按「备份管理」页策略执行）。
		if ( ! wp_next_scheduled( 'sakura_imgopt_daily_backup_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sakura_imgopt_daily_backup_cleanup' );
		}

		// 版本号最后写：前面的建表/排程任一步失败时，Upgrade_Guard 下次请求仍会重跑。
		$installed_version = get_option( 'sakura_imgopt_version', '' );
		if ( '' === $installed_version ) {
			add_option( 'sakura_imgopt_version', SAKURA_TOOLS_VERSION, '', false );
		} elseif ( $installed_version !== SAKURA_TOOLS_VERSION ) {
			update_option( 'sakura_imgopt_version', SAKURA_TOOLS_VERSION, false );
		}

	}
}
