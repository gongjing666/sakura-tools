<?php
/**
 * Builds the React bootstrap payload for the S3 storage screen.
 *
 * The screen is an island: PHP prints the breadcrumb and both tab rows, then
 * hands React one JSON document with everything the five cards need. Keeping
 * the derivation here (instead of in the view) means the REST save path can
 * rebuild the same document after a save, so the header status card and the
 * profile cards never disagree with what is stored.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Admin\Rest\Storage_Controller;
use Sakura\ImageOptimizer\S3\Bucket_Label;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Providers;
use Sakura\ImageOptimizer\S3\S3_Storage_Page;
use Sakura\ImageOptimizer\S3\Upload_Capabilities;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles everything the storage island renders.
 */
final class Storage_Payload {

	/** Storage options reader. */
	private Options $options;

	public function __construct( ?Options $options = null ) {
		$this->options = null !== $options ? $options : new Options();
	}

	/**
	 * The whole bootstrap document.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$page = $this->page_args();

		return array(
			'version'    => SAKURA_TOOLS_VERSION,
			'status'     => $this->status(),
			'alerts'     => $this->alerts(),
			'notice'     => S3_Storage_Page::notice_payload(),
			'page'       => $page,
			'head'       => UI\Page::head_data( $page ),
			'chrome'     => Shell::header_data( 'storage', 'connection' ),
			'rest'       => array(
				'url'        => esc_url_raw( rest_url( Storage_Controller::NS . '/storage' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'metrics'    => array(
					'refresh' => esc_url_raw( rest_url( Storage_Controller::NS . '/storage/metrics' ) ),
				),
				'monitoring' => array(
					'save'   => esc_url_raw( rest_url( Storage_Controller::NS . '/storage/monitoring' ) ),
					'test'   => esc_url_raw( rest_url( Storage_Controller::NS . '/storage/monitoring/test' ) ),
					'rotate' => esc_url_raw( rest_url( Storage_Controller::NS . '/storage/monitoring/rotate' ) ),
				),
			),
			'strings'    => $this->strings(),
			'profiles'   => $this->profiles(),
			'connection' => $this->connection(),
			'behaviour'  => $this->behaviour(),
			'metrics'    => self::section( 'Sakura\\ImageOptimizer\\Admin\\Storage_Metrics_Card' ),
			'monitoring' => self::section( 'Sakura\\ImageOptimizer\\Admin\\Storage_Monitoring_Card' ),
		);
	}

	/**
	 * Ask one of the delegated card builders for its section, when it exists.
	 *
	 * The metrics and monitoring cards are built by their own classes. They are
	 * looked up by name instead of referenced directly so this file keeps
	 * working while either of them is absent (partial deploy, older checkout),
	 * and so a missing card degrades to an empty section instead of a fatal.
	 *
	 * @param string $section_class Fully qualified card class.
	 * @return array<string,mixed>
	 */
	private static function section( string $section_class ): array {
		if ( ! class_exists( $section_class ) ) {
			return array();
		}

		$builder = array( $section_class, 'build' );

		if ( ! is_callable( $builder ) ) {
			return array();
		}

		$built = call_user_func( $builder );

		return is_array( $built ) ? $built : array();
	}

	/**
	 * Header status card.
	 *
	 * The badge names the state in words because the sub line already carries
	 * the provider and the on/off word; reading a colour to answer "is storage
	 * on?" is the thing the badge exists to stop (03-后台UI §7).
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$options    = $this->options->all();
		$bucket     = (string) ( $options['bucket'] ?? '' );
		$provider   = (string) ( $options['provider'] ?? '' );
		$enabled    = $this->options->is_enabled();
		$configured = $this->options->is_configured();
		$label      = (string) ( Providers::all()[ $provider ] ?? 'S3' );

		$badge = array(
			'text' => __( '未配置', 'sakura-imgopt' ),
			'tone' => 'bad',
		);
		if ( $configured ) {
			$badge = $enabled
				? array(
					'text' => __( '已启用', 'sakura-imgopt' ),
					'tone' => 'ok',
				)
				: array(
					'text' => __( '已停用', 'sakura-imgopt' ),
					'tone' => 'neutral',
				);
		}

		return array(
			'label'  => __( '当前存储桶', 'sakura-imgopt' ),
			'value'  => '' !== $bucket ? Bucket_Label::display( $bucket, $this->options->space(), $provider ) : __( '未配置', 'sakura-imgopt' ),
			'sub'    => $label . ' · ' . ( $enabled ? __( '已启用', 'sakura-imgopt' ) : __( '已停用', 'sakura-imgopt' ) ),
			'tone'   => $configured ? ( $enabled ? 'ok' : 'warn' ) : 'bad',
			'badge'  => $badge,
			'id'     => 'sakura-s3-status-value',
			'sub_id' => 'sakura-s3-status-sub',
			'title'  => $bucket,
		);
	}

	/**
	 * Conditions that stay true after this request, unlike a save result.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function alerts(): array {
		$alerts = array();

		if ( $this->options->credentials_unreadable() ) {
			$alerts[] = array(
				'tone'    => 'bad',
				'kicker'  => 'SECURITY',
				'title'   => __( '已保存的访问密钥无法解密', 'sakura-imgopt' ),
				'message' => __( '可能更换了固定密钥或站点安全密钥。请重新填写 Access Key 和 Secret；旧密钥不会显示出来。', 'sakura-imgopt' ),
			);
		}

		if ( defined( 'S3_UPLOADS_USE_CONSTANTS' ) && true === S3_UPLOADS_USE_CONSTANTS ) {
			$alerts[] = array(
				'tone'    => 'warn',
				'kicker'  => 'CONSTANTS',
				'title'   => __( '连接参数由站点常量覆盖', 'sakura-imgopt' ),
				'message' => __( '站点配置文件里写死了云存储参数，非空的常量会覆盖这里填写的连接信息。', 'sakura-imgopt' ),
			);
		}

		return $alerts;
	}

	/**
	 * Page arguments shared by the first paint and the React head.
	 *
	 * @return array<string,mixed>
	 */
	private function page_args(): array {
		return array(
			'id'       => 'storage-connection',
			'module'   => 'storage',
			'tab'      => 'connection',
			'eyebrow'  => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
			'title'    => __( 'S3 存储', 'sakura-imgopt' ),
			'lede'     => __( '把优化后的图片安全地同步到云存储。支持常见的 S3 兼容服务，并沿用原来的文件路径和图片地址前缀。', 'sakura-imgopt' ),
			'status'   => $this->status(),
			'actions'  => array(
				array(
					'label'   => __( '打开同步中心', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'sync' ),
					'variant' => 'primary',
				),
				array(
					'label'   => __( '删除队列', 'sakura-imgopt' ),
					'url'     => Registry::url( 'storage', 'deletes' ),
					'variant' => 'ghost',
				),
			),
			'island'   => true,
			'skeleton' => array(
				'cards' => array( 'profiles', 'connection', 'behaviour', 'upload-metrics', 'monitoring' ),
			),
		);
	}

	/**
	 * Cards, profiles and dialogs the island renders, as data.
	 *
	 * @return array<string,mixed>
	 */
	private function strings(): array {
		return array(
			'saving'              => __( '正在保存…', 'sakura-imgopt' ),
			'saved'               => __( '已保存。', 'sakura-imgopt' ),
			'saveFailed'          => __( '保存没有完成，请稍后重试。', 'sakura-imgopt' ),
			'probeRunning'        => __( '正在测试…', 'sakura-imgopt' ),
			'probeOk'             => __( '连接成功，存储桶可以访问。', 'sakura-imgopt' ),
			'probeFailed'         => __( '连接失败。', 'sakura-imgopt' ),
			'probeTitleOk'        => __( '连接可用', 'sakura-imgopt' ),
			'probeTitleBad'       => __( '连接不可用', 'sakura-imgopt' ),
			/* translators: %s: round trip in milliseconds. */
			'probeFast'           => __( '响应很快，这份配置可用。往返耗时 %s 毫秒。', 'sakura-imgopt' ),
			/* translators: %s: round trip in milliseconds. */
			'probeSlow'           => __( '能用但偏慢，往返耗时 %s 毫秒，上传大图时会明显等待。', 'sakura-imgopt' ),
			'probeProfileTitle'   => __( '配置连接测试', 'sakura-imgopt' ),
			'probeProfilesFailed' => __( '这份配置不完整或密钥无法读取，暂时测不了。', 'sakura-imgopt' ),
			'profileCreated'      => __( '已勾选「另存为新配置」，填写新的 Access Key 和 Secret 后保存。', 'sakura-imgopt' ),
			'profileLoadedKey'    => __( '已载入这份配置。密钥留空表示沿用这份配置已保存的密钥；点「保存为新配置」才会生效。', 'sakura-imgopt' ),
			'providerSwitchNote'  => __( '已更换服务商：这次保存会新建一份配置，原来那份保持不动。想切回原来那份配置，点上方的配置卡片切换即可，不要在这里把服务商改回去再保存。', 'sakura-imgopt' ),
			'dogeFilled'          => __( '已填入存储桶名称和服务地址，确认无误后记得保存。', 'sakura-imgopt' ),
			'dogeFailed'          => __( '获取 SDK 参数失败，请检查 Access Key、Secret Key 和网络后重试。', 'sakura-imgopt' ),
			'dogePick'            => __( '这个账号下有多个存储空间。选一个，插件会自动填入对应的存储桶名称和服务地址。', 'sakura-imgopt' ),
			// Busy state of the 获取 SDK 参数 button. It has to be a short phrase: BusyLabel
			// sizes the button to the wider of its two labels, and dogePick above is a
			// whole sentence, which blew the button up to 500px next to a 660px input.
			'dogeBusy'            => __( '获取中…', 'sakura-imgopt' ),
			'toggledOn'           => __( '已启用这份配置。', 'sakura-imgopt' ),
			'toggledOff'          => __( '已关闭 S3 存储。新上传的图片不会再同步到云端，媒体地址也回到站点域名；这份配置仍然保留，再点一次卡片即可重新启用。', 'sakura-imgopt' ),
			'toggleFailed'        => __( '操作没有完成，请稍后重试。', 'sakura-imgopt' ),
			'riskAckTitle'        => __( '这个选项会删除本地文件', 'sakura-imgopt' ),
			'riskAckText'         => __( '勾选后，新上传的图片及其缩略图只有在全部成功传到云端并回读校验通过后，才会删除服务器上的本地文件；任何一张失败都会保留整组本地文件。', 'sakura-imgopt' ),
			'riskAckItems'        => array(
				__( '只影响本机新上传的附件，从同步中心手动上传历史附件时始终保留本地副本。', 'sakura-imgopt' ),
				__( '本地文件删除后本插件无法恢复，只能从云端重新下载；如果云端对象后来被删除或被覆盖，这些附件将永久无法显示。', 'sakura-imgopt' ),
				__( '首次成功上传后，本选项和这份存储配置会一起锁定，届时无法再更改。锁定之后如果想重新选择存储服务商或存储桶，唯一的办法是把已保存的存储配置全部删除（不能切换、不能改字段），再重新新增一份。', 'sakura-imgopt' ),
			),
			'riskAckAccept'       => __( '我已了解，继续勾选', 'sakura-imgopt' ),
			'dogeNonce'           => wp_create_nonce( 'sakura_s3_doge_buckets' ),
			'probeNonce'          => wp_create_nonce( 'sakura_s3_probe' ),
			'probeProfileNonce'   => wp_create_nonce( 'sakura_s3_probe_profile' ),
			'toggleNonce'         => wp_create_nonce( 'sakura_s3_toggle_profile' ),
			'cancel'              => __( '取消', 'sakura-imgopt' ),
			'closeDialog'         => __( '知道了', 'sakura-imgopt' ),
			'confirmDelete'       => __( '确认删除', 'sakura-imgopt' ),
			'lockTitle'           => __( '已随绑定锁定，不能在这里切换。', 'sakura-imgopt' ),
		);
	}

	/**
	 * The saved-configuration card.
	 *
	 * @return array<string,mixed>
	 */
	public function profiles(): array {
		$options    = $this->options->all();
		$enabled    = $this->options->is_enabled();
		$binding    = $this->options->binding();
		$bound_id   = null !== $binding ? (string) ( $binding['profile_id'] ?? '' ) : '';
		$risk_locked = null !== $binding && 'true' === (string) ( $binding['no_local_saving'] ?? 'false' );

		$saved = array();
		foreach ( $this->options->saved_profiles() as $profile_id => $profile ) {
			if ( ! empty( $profile['saved'] ) ) {
				$saved[ (string) $profile_id ] = (array) $profile;
			}
		}
		$total = count( $saved );
		// Lazy: this walks synced attachments, so it only runs when a high-risk
		// dialog can actually quote the number.
		$lost_local = S3_Storage_Page::lost_local_for( $bound_id, $risk_locked );

		$items = array();
		foreach ( $saved as $profile_id => $profile ) {
			$is_active = ! empty( $profile['active'] );
			$complete  = ! empty( $profile['complete'] );
			$is_live   = $is_active && $enabled;
			$refusal   = S3_Storage_Page::delete_refusal( $profile_id, $is_active, $enabled, $bound_id, $risk_locked, $total );

			$badge = __( '已保存', 'sakura-imgopt' );
			if ( $is_live ) {
				$badge = __( '使用中', 'sakura-imgopt' );
			} elseif ( $is_active ) {
				$badge = __( '已停用', 'sakura-imgopt' );
			}

			$label = __( '删除此配置', 'sakura-imgopt' );
			$title = __( '删除此配置', 'sakura-imgopt' );
			if ( 'delete_active' === $refusal ) {
				$label = __( '使用中不可删除', 'sakura-imgopt' );
				$title = __( '当前使用中的配置不能删除。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' );
			} elseif ( 'delete_bound' === $refusal ) {
				$label = __( '使用中不可删除', 'sakura-imgopt' );
				$title = __( '当前使用中的配置不能删除，而且它还是绑定的那一份。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' );
			} elseif ( 'pending_profiles' === $refusal ) {
				$label = __( '需先删除其余配置', 'sakura-imgopt' );
				$title = __( '高风险锁定：请先点当前配置卡片关闭 S3 存储，删除其余全部配置，再删除当前配置。', 'sakura-imgopt' );
			}

			$is_unbind = $is_active && ! $enabled && '' !== $bound_id && $bound_id === $profile_id;
			$confirm   = S3_Storage_Page::delete_confirm( $is_unbind ? $lost_local : 0, $is_unbind, $risk_locked, (string) ( $profile['name'] ?? '' ) );

			$bucket = (string) ( $profile['bucket'] ?? '' );

			$items[] = array(
				'id'              => $profile_id,
				'name'            => (string) ( $profile['name'] ?? '' ),
				'label'           => (string) ( $profile['label'] ?? '' ),
				'provider'        => (string) ( $profile['provider'] ?? '' ),
				'providerLabel'   => (string) ( Providers::all()[ (string) ( $profile['provider'] ?? '' ) ] ?? '' ),
				'bucket'          => $bucket,
				'bucketDisplay'   => '' !== $bucket
					? Bucket_Label::display( $bucket, (string) ( $profile['space'] ?? '' ), (string) ( $profile['provider'] ?? '' ) )
					: __( '未填写存储桶', 'sakura-imgopt' ),
				'endpoint'        => '' !== (string) ( $profile['endpoint'] ?? '' ) ? (string) $profile['endpoint'] : __( '默认服务地址', 'sakura-imgopt' ),
				'region'          => (string) ( $profile['region'] ?? '' ),
				'space'           => (string) ( $profile['space'] ?? '' ),
				'uploadPath'      => (string) ( $profile['upload_path'] ?? '' ),
				'uploadUrlPath'   => (string) ( $profile['upload_url_path'] ?? '' ),
				'complete'        => $complete,
				'current'         => $is_active,
				'live'            => $is_live,
				'badge'           => $badge,
				'badgeTone'       => $is_live ? 'ok' : 'neutral',
				'warning'         => $complete ? '' : __( '配置不完整，补全后可切换', 'sakura-imgopt' ),
				'delete'          => array(
					'label'    => $label,
					'title'    => $title,
					'disabled' => '' !== $refusal,
					'confirm'  => array(
						'title'   => (string) $confirm['title'],
						'text'    => (string) $confirm['text'],
						'items'   => array_values( (array) $confirm['items'] ),
						'require' => (string) $confirm['require'],
					),
				),
				'probe'           => array(
					'label' => __( '连接测试', 'sakura-imgopt' ),
					'title' => __( '用这份配置自己的密钥测试存储桶是否响应', 'sakura-imgopt' ),
				),
			);
		}

		return array(
			'id'          => 'profiles',
			'kicker'      => 'PROFILES',
			'wide'        => true,
			'placement'   => Layout::card( Layout::layout_for_page( 'storage-connection' ), 'profiles' ),
			'title'       => __( '已保存的配置', 'sakura-imgopt' ),
			'description' => __( '点一下卡片就启用这份配置：卡片变成樱花粉即表示正在使用。再点一次正在使用的卡片会关闭 S3 存储（卡片退回浅粉，配置仍在）。切换配置不用重新填写密钥。', 'sakura-imgopt' ),
			'addLabel'    => __( '新增账户配置', 'sakura-imgopt' ),
			'empty'       => array(
				'title' => __( '还没有保存的配置', 'sakura-imgopt' ),
				'text'  => __( '在下面的「添加存储配置」里填好并保存后，它会出现在这里。', 'sakura-imgopt' ),
			),
			'items'       => $items,
			'activeId'    => $this->options->active_profile_id(),
			'enabled'     => $enabled,
			'bound'       => '' !== $bound_id,
			'riskLocked'  => $risk_locked,
			'lostLocal'   => $lost_local,
		);
	}

	/**
	 * The "add a configuration" card.
	 *
	 * @return array<string,mixed>
	 */
	public function connection(): array {
		$options          = $this->options->all();
		$upload_path      = $this->options->upload_path();
		$upload_path_src  = $this->options->upload_path_source( $options );
		$provider_key     = (string) ( $options['provider'] ?? '' );
		$provider_counts  = $this->options->provider_saved_counts();
		$constants_locked = defined( 'S3_UPLOADS_USE_CONSTANTS' ) && true === S3_UPLOADS_USE_CONSTANTS;

		$default_hints = array(
			'endpoint'     => __( '只支持 http:// 或 https://。使用 AWS 官方服务时可以留空。', 'sakura-imgopt' ),
			'bucket'       => __( '填服务商控制台里的存储桶名称（Bucket）。', 'sakura-imgopt' ),
			'bucket_label' => __( '存储桶名称', 'sakura-imgopt' ),
			'region'       => __( 'AWS 兼容服务通常填 us-east-1；按服务商给出的地域填写即可。', 'sakura-imgopt' ),
			'placeholder'  => 'https://example.r2.cloudflarestorage.com',
		);
		$provider_hints = array(
			'dogecloud' => array(
				'endpoint'     => __( '多吉云填控制台「SDK 参数」里的 s3Endpoint；api.dogecloud.com 是管理 API，填它会 404。', 'sakura-imgopt' ),
				'bucket'       => __( '填多吉云控制台的存储空间名称，再点右侧按钮自动换成 s3Bucket（形如 doss-v2-3081…-1258813047）和 s3Endpoint，不用手抄。', 'sakura-imgopt' ),
				'bucket_label' => __( '存储空间名称', 'sakura-imgopt' ),
				'region'       => __( '多吉云填 automatic；用「获取 SDK 参数」会填入具体地域（如 ap-chengdu），两者都能用。', 'sakura-imgopt' ),
				'placeholder'  => 'https://cos.ap-chengdu.myqcloud.com',
			),
			'minio'     => array(
				'endpoint'     => __( '填 MinIO 的 S3 API 地址，通常是 http://主机:9000；9001 是管理控制台端口，填它连不上。', 'sakura-imgopt' ),
				'bucket'       => __( '填控制台里已经创建好的桶名；MinIO 不会在上传时自动建桶。', 'sakura-imgopt' ),
				'region'       => __( 'MinIO 默认 us-east-1；服务端如果显式配置了别的 region，按服务端填写。', 'sakura-imgopt' ),
				'placeholder'  => 'http://127.0.0.1:9000',
			),
		);

		$hints = array( 'default' => $default_hints );
		foreach ( Providers::all() as $provider_id => $provider_name ) {
			$hints[ (string) $provider_id ] = isset( $provider_hints[ $provider_id ] )
				? array_merge( $default_hints, $provider_hints[ $provider_id ] )
				: $default_hints;
		}
		unset( $provider_id, $provider_name );

		$current_hints = $hints[ $provider_key ] ?? $default_hints;

		$providers = array();
		foreach ( Providers::selectable() as $key => $label ) {
			$count     = (int) ( $provider_counts[ $key ] ?? 0 );
			$providers[] = array(
				'value' => (string) $key,
				'label' => $count > 0
					/* translators: %d: number of saved profiles for this provider. */
					? sprintf( __( '%1$s（已保存 %2$d 个）', 'sakura-imgopt' ), (string) $label, $count )
					/* translators: %s: provider label. */
					: sprintf( __( '%1$s（未保存）', 'sakura-imgopt' ), (string) $label ),
			);
		}
		// The active provider may have been hidden from the picker; without this
		// entry the select would silently show the first option instead.
		if ( '' !== $provider_key && ! isset( Providers::selectable()[ $provider_key ] ) ) {
			$all_labels = Providers::all();
			$providers[] = array(
				'value' => $provider_key,
				'label' => sprintf(
					/* translators: %s: provider label. */
					__( '%s（当前配置，已隐藏新建入口）', 'sakura-imgopt' ),
					(string) ( $all_labels[ $provider_key ] ?? $provider_key )
				),
			);
		}

		$path_hint = '';
		if ( 'wordpress_option' === $upload_path_src ) {
			$path_hint = sprintf(
				/* translators: %s: uploads path. */
				__( '站点自己修改过上传目录（WordPress 的 upload_path 设置）：%s。云端路径会沿用这个值；本插件不会再改它，如需更换请先调整站点设置。', 'sakura-imgopt' ),
				$upload_path
			);
		} elseif ( 'plugin' === $upload_path_src ) {
			$path_hint = sprintf(
				/* translators: %s: uploads path. */
				__( '当前使用已保存的前缀：%s。修改后，已上传的文件和新路径会对不上，只有确认文件已经迁移时才改。', 'sakura-imgopt' ),
				$upload_path
			);
		} else {
			$path_hint = sprintf(
				/* translators: %s: uploads path. */
				__( '默认跟随 WordPress 的上传目录（当前：%s）；想放在根目录就填「.」。修改后，已上传的文件和新路径会对不上。', 'sakura-imgopt' ),
				$upload_path
			);
		}

		$has_key    = '' !== (string) ( $options['key'] ?? '' );
		$has_secret = '' !== (string) ( $options['secret'] ?? '' );

		return array(
			'id'          => 'connection',
			'kicker'      => 'NEW PROFILE',
			'wide'        => true,
			'placement'   => Layout::card( Layout::layout_for_page( 'storage-connection' ), 'connection' ),
			'title'       => __( '添加存储配置', 'sakura-imgopt' ),
			'description' => __( '这张卡片用来新增一份存储配置，默认是空表单。要修改已经保存的配置，点上面「服务商的已保存配置」里的小卡片，它会把那份的值填进来；启用、停用和切换都在「已保存的配置」卡片里点一下完成。密钥会加密保存，页面上不会显示。', 'sakura-imgopt' ),
			'save'        => __( '保存为新配置', 'sakura-imgopt' ),
			'lockedNote'  => $constants_locked ? __( '站点配置文件里写死了云存储参数，非空的常量会覆盖这里填写的连接信息。', 'sakura-imgopt' ) : '',
			'note'        => __( '七牛云、百度云、又拍云的 S3 兼容性还在验证，新建配置时暂不列出；已保存的配置仍可照常切换。', 'sakura-imgopt' ),
			'providers'   => $providers,
			'provider'    => $provider_key,
			'hints'       => $hints,
			'currentHints' => $current_hints,
			'savedProfiles' => array_values( array_map(
				static function ( array $profile ): array {
					return array(
						'id'            => (string) ( $profile['id'] ?? '' ),
						'name'          => (string) ( $profile['name'] ?? '' ),
						'label'         => (string) ( $profile['label'] ?? '' ),
						'provider'      => (string) ( $profile['provider'] ?? '' ),
						'bucket'        => (string) ( $profile['bucket'] ?? '' ),
						'space'         => (string) ( $profile['space'] ?? '' ),
						'region'        => (string) ( $profile['region'] ?? '' ),
						'endpoint'      => (string) ( $profile['endpoint'] ?? '' ),
						'uploadPath'    => (string) ( $profile['upload_path'] ?? '' ),
						'uploadUrlPath' => (string) ( $profile['upload_url_path'] ?? '' ),
					);
				},
				array_values( array_filter(
					$this->options->saved_profiles(),
					static fn( array $profile ): bool => ! empty( $profile['saved'] )
				) )
			) ),
			'fields'      => array(
				'profileName' => array(
					'label'       => __( '配置名称', 'sakura-imgopt' ),
					'placeholder' => __( '例如：主站图床、备份桶', 'sakura-imgopt' ),
					'hint'        => __( '用于区分同一服务商下的多个存储桶；留空会自动按服务商和存储桶命名。', 'sakura-imgopt' ),
				),
				'saveAsNew'   => array(
					'label' => __( '另存为新配置（保留当前配置）', 'sakura-imgopt' ),
					'hint'  => __( '保存一份新配置不会自动启用它，也不会改动「上传行为」里的设置和文章里已有的图片地址；要启用请点上面配置卡片。', 'sakura-imgopt' ),
				),
				'provider'    => array(
					'label'      => __( '服务商', 'sakura-imgopt' ),
					'savedLabel' => __( '服务商的已保存配置', 'sakura-imgopt' ),
					'testLabel'  => __( '测试连接', 'sakura-imgopt' ),
					'testTitle'  => __( '用下面表单里当前填写的参数测试一次，不会保存，也不会上传文件。', 'sakura-imgopt' ),
				),
				'bucket'      => array(
					'label' => (string) $current_hints['bucket_label'],
					'hint'  => (string) $current_hints['bucket'],
					'doge'  => __( '获取 SDK 参数', 'sakura-imgopt' ),
				),
				'region'      => array(
					'label'       => __( '地域', 'sakura-imgopt' ),
					'placeholder' => 'us-east-1',
					'hint'        => (string) $current_hints['region'],
				),
				'endpoint'    => array(
					'label'       => __( '服务地址', 'sakura-imgopt' ),
					'placeholder' => (string) $current_hints['placeholder'],
					'hint'        => (string) $current_hints['endpoint'],
				),
				'key'         => array(
					'label'       => __( 'Access Key', 'sakura-imgopt' ),
					'placeholder' => $has_key ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入访问密钥', 'sakura-imgopt' ),
				),
				'secret'      => array(
					'label'       => __( 'Secret Key', 'sakura-imgopt' ),
					'placeholder' => $has_secret ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入密钥', 'sakura-imgopt' ),
				),
				'uploadPath'  => array(
					'label'   => __( '云端路径前缀', 'sakura-imgopt' ),
					'hint'    => $path_hint,
					'locked'  => 'wordpress_option' === $upload_path_src,
				),
				'uploadUrlPath' => array(
					'label'       => __( '图片地址前缀', 'sakura-imgopt' ),
					'placeholder' => 'https://cdn.example.com/wp-content/uploads',
					'hint'        => __( '保存后会写入 WordPress 的图片地址设置，前台图片会换用这个域名。留空表示继续使用站点自己的地址。', 'sakura-imgopt' ),
				),
			),
			'values'      => array(
				'profileName'   => '',
				'profileId'     => '',
				'saveAsNew'     => false,
				'bucket'        => '',
				'space'         => '',
				'region'        => '',
				'endpoint'      => '',
				'uploadPath'    => $upload_path,
				'uploadUrlPath' => (string) ( $options['upload_url_path'] ?? '' ),
			),
		);
	}

	/**
	 * The upload-behaviour card.
	 *
	 * @return array<string,mixed>
	 */
	public function behaviour(): array {
		$options          = $this->options->all();
		$concurrency      = ( new Settings_Repository() )->upload_concurrency();
		$parallel         = Upload_Capabilities::supports_parallel();
		$effective        = Upload_Capabilities::effective( $concurrency );
		$frozen           = $this->options->behaviour_frozen();
		$behaviour        = $this->options->behaviour_snapshot();
		$binding          = $this->options->binding();
		$risk_locked      = null !== $binding && 'true' === (string) ( $binding['no_local_saving'] ?? 'false' );
		$locked_at        = null !== $binding ? (int) ( $binding['locked_at'] ?? 0 ) : 0;
		$locked_label     = '';
		if ( $locked_at > 0 ) {
			$locked_label = function_exists( 'wp_date' )
				? (string) wp_date( 'Y-m-d H:i', $locked_at )
				: gmdate( 'Y-m-d H:i', $locked_at );
		}

		$no_thumb        = $frozen ? 'true' === (string) $behaviour['no_thumb'] : 'true' === (string) ( $options['no_thumb'] ?? 'false' );
		$no_local_saving = $frozen ? 'true' === (string) $behaviour['no_local_saving'] : 'true' === (string) ( $options['no_local_saving'] ?? 'false' );

		$lock_note = '';
		if ( $frozen ) {
			$lock_note = '' !== $locked_label
				/* translators: %s: lock time. */
				? sprintf( __( '%s 起已锁定。', 'sakura-imgopt' ), $locked_label )
				: __( '已锁定。', 'sakura-imgopt' );
			$lock_note .= $risk_locked
				? __( '这次绑定还开启了「上传成功后删除本地文件」，本地副本已不在。如需更换存储服务商或存储桶，只能先点当前配置卡片关闭 S3 存储，删除其余全部存储配置，再删除当前配置，然后重新新增。', 'sakura-imgopt' )
				: __( '如需更换存储桶，请先点当前配置卡片关闭 S3 存储，再删除它。', 'sakura-imgopt' );
			$lock_note .= __( '「不上传 WordPress 生成的缩略图」与「上传成功后删除本地文件」已随绑定一起固定，只有把存储配置全部删除之后才能重新选择。', 'sakura-imgopt' );
		} else {
			$lock_note = __( '这份配置还没有成功上传过。完成第一次上传后，服务商、存储桶、服务地址、云端路径与「上传成功后删除本地文件」将被锁定。', 'sakura-imgopt' );
		}

		$concurrency_options = array();
		for ( $i = 1; $i <= Settings_Repository::MAX_UPLOAD_CONCURRENCY; $i++ ) {
			$concurrency_options[] = array(
				'value' => (string) $i,
				'label' => (string) $i,
			);
		}

		return array(
			'id'          => 'behaviour',
			'kicker'      => 'BEHAVIOUR',
			'wide'        => false,
			'placement'   => Layout::card( Layout::layout_for_page( 'storage-connection' ), 'behaviour' ),
			'title'       => __( '上传行为', 'sakura-imgopt' ),
			'description' => __( '对所有存储配置生效。', 'sakura-imgopt' ),
			'save'        => __( '保存设置', 'sakura-imgopt' ),
			'frozen'      => $frozen,
			'riskLocked'  => $risk_locked,
			'lockNote'    => $lock_note,
			'switches'    => array(
				array(
					'name'    => 'no_thumb',
					'label'   => __( '不上传 WordPress 生成的缩略图', 'sakura-imgopt' ),
					'hint'    => __( '一般不建议开启，否则部分自适应图片可能无法从云端读取。', 'sakura-imgopt' ),
					'checked' => $no_thumb,
					'danger'  => false,
					'locked'  => $frozen,
					// Only a frozen card needs the words: an editable switch shows
					// its state itself.
					'state'   => $frozen ? ( $no_thumb ? __( '已开启', 'sakura-imgopt' ) : __( '已关闭', 'sakura-imgopt' ) ) : '',
				),
				array(
					'name'    => 'no_local_saving',
					'label'   => __( '上传成功后删除本地文件（高风险）', 'sakura-imgopt' ),
					'hint'    => __( '勾选后，只有新上传的图片及其缩略图都成功传到云端，才会删除本地文件；不勾选就一直保留。手动同步历史附件时始终保留本地文件。', 'sakura-imgopt' ),
					'checked' => $no_local_saving,
					'danger'  => true,
					'locked'  => $frozen,
					'state'   => $frozen ? ( $no_local_saving ? __( '已开启', 'sakura-imgopt' ) : __( '已关闭', 'sakura-imgopt' ) ) : '',
				),
			),
			'concurrency' => array(
				'label'        => __( '云端传输并发数', 'sakura-imgopt' ),
				'hint'         => __( '同一次上传里同时向云端传输的文件数，默认 1。调高会缩短多尺寸图片的等待时间，但更占 PHP 工作进程和带宽；服务器或 HTTP 传输环境不支持并行时会自动降级。', 'sakura-imgopt' ),
				'value'        => (string) $concurrency,
				'options'      => $concurrency_options,
				'parallel'     => $parallel,
				'effective'    => $effective,
				'fallbackNote' => $concurrency > 1 && ! $parallel
					/* translators: %d: effective concurrency. */
					? sprintf( __( '当前 HTTP 传输环境不支持并行，实际使用 %d。', 'sakura-imgopt' ), $effective )
					: '',
			),
		);
	}
}
