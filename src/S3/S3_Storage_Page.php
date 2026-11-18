<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\UI;
use Sakura\ImageOptimizer\Admin\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the S3 settings screen and read-only redirect notices. */
final class S3_Storage_Page {

	private Options $options;

	private ?Sync_Status $status;

	public function __construct( Options $options, ?Sync_Status $status = null ) {
		$this->options = $options;
		$this->status  = $status;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options          = $this->options->all();
		$upload_path      = $this->options->upload_path();
		$upload_path_src  = $this->options->upload_path_source( $options );
		$concurrency      = ( new \Sakura\ImageOptimizer\Settings\Settings_Repository() )->upload_concurrency();
		$parallel         = Upload_Capabilities::supports_parallel();
		$effective        = Upload_Capabilities::effective( $concurrency );
		$constants_locked = defined( 'S3_UPLOADS_USE_CONSTANTS' ) && true === S3_UPLOADS_USE_CONSTANTS;
		$notice           = $this->notice();
		$credentials_bad  = $this->options->credentials_unreadable();
		$saved_profiles   = $this->options->saved_profiles();
		$provider_counts  = $this->options->provider_saved_counts();
		$active_id        = $this->options->active_profile_id();
		$active_profile   = '' !== $active_id ? $this->options->profile( $active_id ) : array();
		$active_name      = (string) ( $active_profile['name'] ?? '' );
		$bucket           = (string) ( $options['bucket'] ?? '' );
		// Reads the active profile too: profiles stored before the space existed
		// only carry it on their own record, not on the top-level options.
		$space            = $this->options->space();
		$provider_label   = (string) ( Providers::all()[ $options['provider'] ] ?? 'S3' );
		$enabled          = $this->options->is_enabled();
		$configured       = $this->options->is_configured();
		// 状态卡显示空间名（多吉云 nboke），没有就用缩写；完整桶名进 title。
		// 缩写要知道厂商：腾讯云的桶名尾巴是账号级 APPID，留着只剩数字。
		$status_value     = '' !== $bucket ? Bucket_Label::display( $bucket, $space, (string) ( $options['provider'] ?? '' ) ) : __( '未配置', 'sakura-imgopt' );
		$status_sub       = $provider_label . ' · ' . ( $enabled ? __( '已启用', 'sakura-imgopt' ) : __( '已停用', 'sakura-imgopt' ) );
		$status_tone      = $configured ? ( $enabled ? 'ok' : 'warn' ) : 'bad';

		// R3/R7/R10/R11 (07-S3存储生命周期). Everything the two freeze rules need
		// is derived once here: which profile owns the binding, whether that binding
		// deleted local copies (high risk), and whether the upload-behaviour card is
		// frozen. Recomputing it inside the card closures would let the two rules
		// drift apart.
		$binding          = $this->options->binding();
		$bound_id         = null !== $binding ? (string) ( $binding['profile_id'] ?? '' ) : '';
		$risk_locked      = null !== $binding && 'true' === (string) ( $binding['no_local_saving'] ?? 'false' );
		$behaviour_frozen = $this->options->behaviour_frozen();
		$behaviour        = $this->options->behaviour_snapshot();
		$locked_at        = null !== $binding ? (int) ( $binding['locked_at'] ?? 0 ) : 0;
		$locked_label     = '';
		if ( $locked_at > 0 ) {
			$locked_label = function_exists( 'wp_date' )
				? (string) wp_date( 'Y-m-d H:i', $locked_at )
				: gmdate( 'Y-m-d H:i', $locked_at );
		}
		/*
		 * R7/R10: the number the confirmation dialog quotes. Only attachments that
		 * were confirmed against the bound bucket count — objects synced to some
		 * other profile are not lost by deleting this one. Counted lazily: it walks
		 * the synced attachments, which is fine on the settings screen and would not
		 * be fine on a hot path.
		 */
		$lost_local = self::lost_local_for( $bound_id, $risk_locked );

		UI::page(
			array(
				'id'      => 'storage-connection',
				'module'  => 'storage',
				'tab'     => 'connection',
				'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( 'S3 存储', 'sakura-imgopt' ),
				'lede'    => __( '把优化后的图片安全地同步到云存储。支持常见的 S3 兼容服务，并沿用原来的文件路径和图片地址前缀。', 'sakura-imgopt' ),
				'status'  => array(
					'label'  => __( '当前存储桶', 'sakura-imgopt' ),
					'id'     => 'sakura-s3-status-value',
					'sub_id' => 'sakura-s3-status-sub',
					'value'  => $status_value,
					'title'  => $bucket,
					'sub'    => $status_sub,
					'tone'   => $status_tone,
				),
				'actions' => array(
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
			),
			function () use ( $notice, $credentials_bad, $constants_locked, $saved_profiles, $options, $active_id, $enabled, $provider_counts, $upload_path, $upload_path_src, $concurrency, $parallel, $effective, $bound_id, $risk_locked, $lost_local, $behaviour_frozen, $behaviour, $locked_label ) {
				/*
				 * A save or a connection test is an event, not a state: it gets the
				 * shared prompt dialog so the operator cannot miss the result while
				 * scrolling a long form. Persistent conditions below keep the inline
				 * strip, because they are still true tomorrow.
				 */
				if ( '' !== (string) $notice['message'] ) {
					UI::modal(
						array(
							'id'        => 'sakura-s3-result-modal',
							'return_to' => '#sakura-s3-save-form .card__savebar .btn, #sakura-s3-test-connection',
							'tone'    => (string) $notice['tone'],
							'size'    => 'compact',
							'kicker'  => 'OBJECT STORAGE / RESULT',
							'title'   => 'ok' === (string) $notice['tone']
								? __( '操作已完成', 'sakura-imgopt' )
								: __( '没有完成', 'sakura-imgopt' ),
							'message' => (string) $notice['message'],
						)
					);
				}

				if ( $credentials_bad ) {
					UI::alert(
						__( '已保存的访问密钥无法解密（可能更换了固定密钥或站点安全密钥）。请重新填写 Access Key 和 Secret；旧密钥不会显示出来。', 'sakura-imgopt' ),
						'bad'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
				}

				if ( $constants_locked ) {
					UI::alert(
						__( '站点配置文件里写死了云存储参数，非空的常量会覆盖这里填写的连接信息。', 'sakura-imgopt' ),
						'warn'
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
				}

				/*
				 * 不同服务商对「存储桶」和「服务地址」的定义差别很大：多吉云在控制台里
				 * 显示的是存储空间名称，真正要填的是 SDK 参数里的 s3Bucket / s3Endpoint，
				 * 而 api.dogecloud.com 只是管理 API。把这类差异写进提示，比让人对着
				 * 404 猜要省事。
				 */
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

				$provider_key = (string) $options['provider'];
				$field_hints  = isset( $provider_hints[ $provider_key ] ) ? array_merge( $default_hints, $provider_hints[ $provider_key ] ) : $default_hints;
				$live_hints   = array( 'default' => $default_hints );
				foreach ( Providers::all() as $provider_id => $provider_name ) {
					$live_hints[ (string) $provider_id ] = isset( $provider_hints[ $provider_id ] ) ? array_merge( $default_hints, $provider_hints[ $provider_id ] ) : $default_hints;
				}
				unset( $provider_id, $provider_name );

				$page_strings = array(
					'profileCreated' => __( '已勾选「另存为新配置」，填写新的 Access Key 和 Secret 后保存。', 'sakura-imgopt' ),
					/* translators: %s: time of the last metrics refresh. */
					'metricsUpdated' => __( '更新于 %s', 'sakura-imgopt' ),
					'metricsNever'   => __( '尚未记录', 'sakura-imgopt' ),
					'metricsLoading' => __( '正在刷新…', 'sakura-imgopt' ),
					'metricsFailed'  => __( '指标刷新失败，请稍后重试。', 'sakura-imgopt' ),
					/* translators: 1: failed request count, 2: failure rate percentage. */
					'metricsRate'    => __( '失败 %1$d · 失败率 %2$s', 'sakura-imgopt' ),
					/* translators: %s: slowest request duration. */
					'metricsMax'     => __( '最慢 %s', 'sakura-imgopt' ),
					/* translators: %s: failure rate percentage over the last 14 days. */
					'metricsWeek'    => __( '近 14 天失败率 %s', 'sakura-imgopt' ),
					'metricsNoError' => __( '暂无错误记录', 'sakura-imgopt' ),
					'dogeFilled'     => __( '已填入存储桶名称和服务地址，确认无误后记得保存。', 'sakura-imgopt' ),
					'dogeFailed'     => __( '获取 SDK 参数失败，请检查 Access Key、Secret Key 和网络后重试。', 'sakura-imgopt' ),
					'dogePick'       => __( '这个账号下有多个存储空间。选一个，插件会自动填入对应的存储桶名称和服务地址。', 'sakura-imgopt' ),
					'dogeNonce'      => wp_create_nonce( 'sakura_s3_doge_buckets' ),
					'probeOk'        => __( '连接成功，存储桶可以访问。', 'sakura-imgopt' ),
					'probeFailed'    => __( '连接失败。', 'sakura-imgopt' ),
					'probeTitleOk'   => __( '连接可用', 'sakura-imgopt' ),
					'probeTitleBad'  => __( '连接不可用', 'sakura-imgopt' ),
					'probeNonce'     => wp_create_nonce( 'sakura_s3_probe' ),
					'probeProfileNonce' => wp_create_nonce( 'sakura_s3_probe_profile' ),
					'probeProfileTitle' => __( '配置连接测试', 'sakura-imgopt' ),
					/* translators: %s: round trip in milliseconds. */
					'probeFast'      => __( '响应很快，这份配置可用。往返耗时 %s 毫秒。', 'sakura-imgopt' ),
					/* translators: %s: round trip in milliseconds. */
					'probeSlow'      => __( '能用但偏慢，往返耗时 %s 毫秒，上传大图时会明显等待。', 'sakura-imgopt' ),
					'probeProfilesFailed' => __( '这份配置不完整或密钥无法读取，暂时测不了。', 'sakura-imgopt' ),
					'profileLoadedKey' => __( '已载入这份配置。密钥留空表示沿用这份配置已保存的密钥；点「保存为新配置」才会生效。', 'sakura-imgopt' ),
					'providerSwitchNote' => __( '已更换服务商：这次保存会新建一份配置，原来那份保持不动。想切回原来那份配置，点上方的配置卡片切换即可，不要在这里把服务商改回去再保存。', 'sakura-imgopt' ),
					'toggleNonce'    => wp_create_nonce( 'sakura_s3_toggle_profile' ),
					'toggledOn'      => __( '已启用这份配置。', 'sakura-imgopt' ),
					'toggledOff'     => __( '已关闭 S3 存储。新上传的图片不会再同步到云端，媒体地址也回到站点域名；这份配置仍然保留，再点一次卡片即可重新启用。', 'sakura-imgopt' ),
					'toggleFailed'   => __( '操作没有完成，请稍后重试。', 'sakura-imgopt' ),
					// R2 (07-S3存储生命周期): the four-part warning shown before the risky
					// switch may be turned on. Split as message + items so the dialog can
					// draw the consequences, the scope, the irreversibility and the lock
					// separately instead of as one unreadable paragraph.
					'riskAckTitle'   => __( '这个选项会删除本地文件', 'sakura-imgopt' ),
					'riskAckText'    => __( '勾选后，新上传的图片及其缩略图只有在全部成功传到云端并回读校验通过后，才会删除服务器上的本地文件；任何一张失败都会保留整组本地文件。', 'sakura-imgopt' ),
					'riskAckItems'   => array(
						__( '只影响本机新上传的附件，从同步中心手动上传历史附件时始终保留本地副本。', 'sakura-imgopt' ),
						__( '本地文件删除后本插件无法恢复，只能从云端重新下载；如果云端对象后来被删除或被覆盖，这些附件将永久无法显示。', 'sakura-imgopt' ),
						__( '首次成功上传后，本选项和这份存储配置会一起锁定，届时无法再更改。锁定之后如果想重新选择存储服务商或存储桶，唯一的办法是把已保存的存储配置全部删除（不能切换、不能改字段），再重新新增一份。', 'sakura-imgopt' ),
					),
					'riskAckAccept'  => __( '我已了解，继续勾选', 'sakura-imgopt' ),
				);
				?>
				<div class="sakura-admin__data" data-sakura-connection-strings="<?php echo esc_attr( wp_json_encode( array( 'strings' => $page_strings, 'hints' => $live_hints, 'profiles' => array_values( $saved_profiles ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) ); ?>" hidden></div>
				<?php

				echo '<div class="card-grid" id="sakura-s3-save-form" data-sakura-layout="s3" data-sakura-group="main">';

				UI::card(
					array(
						'id'          => 'profiles',
						'kicker'      => 'PROFILES',
						'title'       => __( '已保存的配置', 'sakura-imgopt' ),
						'description' => __( '点一下卡片就启用这份配置：卡片变成深色即表示正在使用。再点一次正在使用的卡片会关闭 S3 存储（卡片变浅，配置仍在）。切换配置不用重新填写密钥。', 'sakura-imgopt' ),
						'anchor'      => 'sakura-s3-profiles',
						'class'       => 'card--wide',
						'actions'     => array(
							array(
								'label'   => __( '新增账户配置', 'sakura-imgopt' ),
								'button'  => true,
								'id'      => 'sakura-s3-add-profile',
								'variant' => 'ghost',
								'attrs'   => array( 'data-sakura-action' => 'add-profile' ),
							),
						),
					),
					function () use ( $saved_profiles, $enabled, $bound_id, $risk_locked, $lost_local ) {
						$visible = array();
						foreach ( $saved_profiles as $profile_id => $profile ) {
							if ( ! empty( $profile['saved'] ) ) {
								$visible[ (string) $profile_id ] = (array) $profile;
							}
						}

						if ( array() === $visible ) {
							UI::empty_state(
								__( '还没有保存的配置', 'sakura-imgopt' ),
								__( '在下面的「添加存储配置」里填好并保存后，它会出现在这里。', 'sakura-imgopt' )
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
							return;
						}

						echo '<div class="profile-grid" data-sakura-role="profiles">';
						foreach ( $visible as $profile_id => $profile ) {
							$is_active = ! empty( $profile['active'] );
							$complete  = ! empty( $profile['complete'] );
							// R7/R10: whether this row may be deleted, and why not. The
							// server enforces the same table; computing it here as well
							// keeps the button honest before it is pressed.
							$delete_locked = self::delete_refusal( (string) $profile_id, $is_active, $enabled, $bound_id, $risk_locked, count( $visible ) );
							// 深色 = 这份配置正在使用（active + 已启用）；浅色 = 只是保存着。
							$is_live   = $is_active && $enabled;
							$badge     = __( '已保存', 'sakura-imgopt' );
							if ( $is_live ) {
								$badge = __( '使用中', 'sakura-imgopt' );
							} elseif ( $is_active ) {
								$badge = __( '已停用', 'sakura-imgopt' );
							}
							echo '<div class="profile-item">';
							echo '<button type="button" class="profile-card' . ( $is_live ? ' is-active' : '' ) . ( $is_active ? ' is-current' : '' ) . '" data-sakura-action="toggle-profile" data-profile-id="' . esc_attr( $profile_id ) . '"' . ( $is_active ? ' aria-pressed="' . ( $is_live ? 'true' : 'false' ) . '"' : ' aria-pressed="false"' );
							disabled( ! $complete );
							echo '>';
							echo '<span class="profile-card__top"><span class="profile-card__name">' . esc_html( (string) ( $profile['name'] ?? '' ) ) . '</span>';
							UI::badge( $badge, $is_live ? 'ok' : 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
							echo '</span>';
							echo '<span class="profile-card__meta">' . esc_html( (string) ( $profile['label'] ?? '' ) ) . '</span>';
							// 配置卡上显示空间名或缩写桶名，完整 s3Bucket 放 title。
							$card_bucket = (string) ( $profile['bucket'] ?? '' );
							$card_space  = (string) ( $profile['space'] ?? '' );
							echo '<span class="profile-card__meta profile-card__meta--mono"' . ( '' !== $card_bucket ? ' title="' . esc_attr( $card_bucket ) . '"' : '' ) . '>' . esc_html( '' !== $card_bucket ? Bucket_Label::display( $card_bucket, $card_space, (string) ( $profile['provider'] ?? '' ) ) : __( '未填写存储桶', 'sakura-imgopt' ) ) . '</span>';
							echo '<span class="profile-card__meta profile-card__meta--mono">' . esc_html( '' !== (string) ( $profile['endpoint'] ?? '' ) ? (string) $profile['endpoint'] : __( '默认服务地址', 'sakura-imgopt' ) ) . '</span>';
							if ( ! $complete ) {
								echo '<span class="profile-card__warning">' . esc_html__( '配置不完整，补全后可切换', 'sakura-imgopt' ) . '</span>';
							}
							echo '</button>';

							/*
							 * Both actions sit in one row under the card. The probe button is
							 * a sibling of the delete form, not a submit inside it, so testing
							 * a configuration can never submit the delete.
							 */
							echo '<div class="profile-item__actions">';
							echo '<button type="button" class="btn btn--quiet btn--small profile-probe" data-sakura-action="probe-profile" data-profile-id="' . esc_attr( $profile_id ) . '" title="' . esc_attr__( '用这份配置自己的密钥测试存储桶是否响应', 'sakura-imgopt' ) . '">' . esc_html__( '连接测试', 'sakura-imgopt' ) . '</button>';

							/*
							 * R7/R10. The prompt a delete opens depends on what it costs:
							 * a locked row gets no prompt at all (the button is disabled and
							 * its title names the step that unblocks it); deleting the bound
							 * profile unbinds the site and gets the full warning; everything
							 * else keeps the plain "this cannot be undone" line.
							 */
							$bound_here = '' !== $bound_id && $bound_id === (string) $profile_id;
							$is_unbind  = $is_active && ! $enabled && $bound_here;
							$confirm    = self::delete_confirm( $lost_local, $is_unbind, $risk_locked, (string) ( $profile['name'] ?? '' ) );
							$delete_label = __( '删除此配置', 'sakura-imgopt' );
							$delete_title = __( '删除此配置', 'sakura-imgopt' );

							if ( 'delete_active' === $delete_locked ) {
								$delete_label = __( '使用中不可删除', 'sakura-imgopt' );
								$delete_title = __( '当前使用中的配置不能删除。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' );
							} elseif ( 'delete_bound' === $delete_locked ) {
								$delete_label = __( '使用中不可删除', 'sakura-imgopt' );
								$delete_title = __( '当前使用中的配置不能删除，而且它还是绑定的那一份。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' );
							} elseif ( 'pending_profiles' === $delete_locked ) {
								$delete_label = __( '需先删除其余配置', 'sakura-imgopt' );
								$delete_title = __( '高风险锁定：请先点当前配置卡片关闭 S3 存储，删除其余全部配置，再删除当前配置。', 'sakura-imgopt' );
							}

							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="profile-item__delete"';
							if ( '' === $delete_locked ) {
								echo ' data-sakura-confirm="' . esc_attr( $confirm['text'] ) . '" data-sakura-confirm-title="' . esc_attr( $confirm['title'] ) . '"';
								if ( array() !== $confirm['items'] ) {
									echo ' data-sakura-confirm-items="' . esc_attr( (string) wp_json_encode( $confirm['items'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) ) . '"';
								}
								if ( '' !== $confirm['require'] ) {
									echo ' data-sakura-require-text="' . esc_attr( $confirm['require'] ) . '"';
								}
							}
							echo '>';
							echo '<input type="hidden" name="action" value="sakura_s3_delete" />';
							echo '<input type="hidden" name="profile_id" value="' . esc_attr( $profile_id ) . '" />';
							wp_nonce_field( 'sakura_s3_delete_' . $profile_id );
							echo '<button type="submit" class="btn btn--quiet btn--small profile-delete"';
							disabled( '' !== $delete_locked );
							echo ' title="' . esc_attr( $delete_title ) . '">';
							echo esc_html( $delete_label );
							echo '</button>';
							echo '</form>';
							echo '</div>';
							echo '</div>';
						}
						echo '</div>';
					}
				);

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

				$provider_options = array();
				foreach ( Providers::selectable() as $key => $label ) {
					$count  = (int) ( $provider_counts[ $key ] ?? 0 );
					$suffix = $count > 0
						/* translators: %d: number of saved profiles for this provider. */
						? sprintf( __( '（已保存 %d 个）', 'sakura-imgopt' ), $count )
						: __( '（未保存）', 'sakura-imgopt' );
					$provider_options[ (string) $key ] = (string) $label . $suffix;
				}
				// 当前激活的厂商可能已经被隐藏；不补进下拉，页面会显示成第一个选项。
				$active_provider = (string) $options['provider'];
				if ( '' !== $active_provider && ! isset( $provider_options[ $active_provider ] ) ) {
					$all_labels = Providers::all();
					$label      = isset( $all_labels[ $active_provider ] ) ? (string) $all_labels[ $active_provider ] : $active_provider;
					$provider_options[ $active_provider ] = $label . __( '（当前配置，已隐藏新建入口）', 'sakura-imgopt' );
				}

				UI::card(
					array(
						'id'          => 'connection',
						'kicker'      => 'NEW PROFILE',
						'title'       => __( '添加存储配置', 'sakura-imgopt' ),
						'description' => __( '这张卡片用来新增一份存储配置，默认是空表单。要修改已经保存的配置，点上面「服务商的已保存配置」里的小卡片，它会把那份的值填进来；启用、停用和切换都在「已保存的配置」卡片里点一下完成。密钥会加密保存，页面上不会显示。', 'sakura-imgopt' ),
						'anchor'      => 'sakura-s3-connection',
						'wide'        => true,
						'form'        => array(
							'id'          => 'sakura-s3-connection-form',
							'action'      => admin_url( 'admin-post.php' ),
							'markers'     => 'fields',
							'marker_card' => 'connection',
							'hidden'      => array(
								'action'     => 'sakura_s3_save',
								// Blank on purpose: this form adds a configuration. To edit
								// one, pick it from the chips under the provider picker,
								// which fills this field with that profile's id.
								'profile_id' => '',
							),
						),
						'save'        => __( '保存为新配置', 'sakura-imgopt' ),
					),
					function () use ( $options, $provider_options, $upload_path, $upload_path_src, $path_hint, $field_hints ) {
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => '_wpnonce',
								'value' => wp_create_nonce( 'sakura_s3_save' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<div class="field-list">';

						/*
						 * Provider and its saved configs share one row.
						 *
						 * The chips used to sit on their own line under the picker, where
						 * they read as a footnote to the provider instead of the shortcut
						 * they are. Side by side, the two labels line up and the picker's
						 * control box is the same height as the chips next to it, so the
						 * row scans as "pick a provider, jump to one you already saved".
						 *
						 * Both halves are rendered here rather than through two fields:
						 * the field-list grid would otherwise put them in separate cells
						 * and they could never share a baseline. When the provider has no
						 * saved configs the right half stays hidden and the row collapses
						 * to a single column.
						 *
						 * The 测试连接 button rides with the select: it tests the values
						 * currently typed in the form, before anything is saved, so a typo
						 * in the endpoint shows up as a real error code instead of a
						 * profile that has to be deleted again. Building the select by hand
						 * is what buys the row; the markup mirrors Field_View::select().
						 */
						UI::field(
							array(
								'type'    => 'custom',
								'id'      => 'sakura-s3-provider',
								'full'    => true,
								'control' => static function () use ( $options, $provider_options ) {
									$select = '<select class="field__grow" id="sakura-s3-provider" name="provider" data-sakura-provider-select="1">';
									foreach ( $provider_options as $provider_value => $provider_label ) {
										$select .= '<option value="' . esc_attr( (string) $provider_value ) . '"' . selected( (string) $options['provider'], (string) $provider_value, false ) . '>' . esc_html( (string) $provider_label ) . '</option>';
									}
									$select .= '</select>';

									return '<div class="s3-provider-row" data-sakura-provider-row>'
										. '<div class="s3-provider-row__pick">'
										. '<label class="field__label" for="sakura-s3-provider">' . esc_html__( '服务商', 'sakura-imgopt' ) . '</label>'
										. '<div class="field__row">'
										. $select
										. '<button type="button" class="btn btn--ghost" id="sakura-s3-test-connection" title="' . esc_attr__( '用下面表单里当前填写的参数测试一次，不会保存，也不会上传文件。', 'sakura-imgopt' ) . '">' . esc_html__( '测试连接', 'sakura-imgopt' ) . '</button>'
										. '</div>'
										. '</div>'
										/*
										 * Saved configs for the chosen provider. Clicking one only
										 * loads its values into this form — it does not activate it,
										 * does not send a request and does not touch the stored
										 * option. Nothing changes on the site until the operator
										 * presses 保存为新配置. The list is rendered empty and filled
										 * by storage-connection.js from the data node below, so the
										 * chips follow the provider picker without a page reload.
										 */
										. '<div class="s3-provider-row__saved" data-sakura-provider-profiles hidden>'
										. '<span class="field__label">' . esc_html__( '服务商的已保存配置', 'sakura-imgopt' ) . '</span>'
										. '<div class="profile-chips__list" data-sakura-provider-profile-list role="list"></div>'
										. '</div>'
										. '</div>';
								},
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<p class="card__note s3-field-note">' . esc_html__( '七牛云、百度云、又拍云的 S3 兼容性还在验证，新建配置时暂不列出；已保存的配置仍可照常切换。', 'sakura-imgopt' ) . '</p>';

						UI::field(
							array(
								'type'        => 'text',
								'id'          => 'sakura-s3-profile-name',
								'name'        => 'profile_name',
								'label'       => __( '配置名称', 'sakura-imgopt' ),
								'value'       => '',
								'placeholder' => __( '例如：主站图床、备份桶', 'sakura-imgopt' ),
								'hint'        => __( '用于区分同一服务商下的多个存储桶；留空会自动按服务商和存储桶命名。', 'sakura-imgopt' ),
								'full'        => false,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						$this->check_field(
							'save_as_new',
							__( '另存为新配置（保留当前配置）', 'sakura-imgopt' ),
							__( '保存一份新配置不会自动启用它，也不会改动「上传行为」里的设置和文章里已有的图片地址；要启用请点上面配置卡片。', 'sakura-imgopt' ),
							false,
							false,
							false
						);

						/*
						 * Filled by storage-connection.js when the provider picker no longer
						 * matches the profile the form is editing: a save then writes a new
						 * configuration, and the checkbox above is forced on and disabled so
						 * the form cannot claim otherwise.
						 */
						echo '<p class="s3-auto-new-note" data-sakura-auto-new-note hidden><span data-sakura-auto-new-text></span></p>';

						/*
						 * The bucket row carries the DogeCloud resolver button. It ships
						 * hidden and is revealed by storage-connection.js only for that
						 * provider, so every other provider keeps the plain text field and
						 * the button never appears without JavaScript to drive it.
						 */
						UI::field(
							array(
								'type'    => 'custom',
								'id'      => 'sakura-s3-bucket',
								'label'   => (string) $field_hints['bucket_label'],
								'hint'    => (string) $field_hints['bucket'],
								'full'    => true,
								'control' => static function () {
									return '<div class="field__row">'
										// 「获取 SDK 参数」把空间名写进这个隐藏字段，保存时随配置一起存下来，
										// 之后状态卡、配置卡和选择器就用它代替 40 位的 s3Bucket。
										// 表单默认留空：它是新增配置用的；要改一份已有配置，点上面「这份
										// 服务商的已保存配置」里的小卡片，脚本会把那份的值填进来。
										. '<input type="hidden" id="sakura-s3-space" name="space" value="" />'
										. '<input type="text" class="field__grow" id="sakura-s3-bucket" name="bucket" value="" autocomplete="off" />'
										. '<button type="button" class="btn btn--ghost btn--small" data-sakura-doge-resolve hidden>' . esc_html__( '获取 SDK 参数', 'sakura-imgopt' ) . '</button>'
										. '</div>';
								},
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'        => 'text',
								'id'          => 'sakura-s3-region',
								'name'        => 'region',
								'label'       => __( '地域', 'sakura-imgopt' ),
								'value'       => '',
								'placeholder' => 'us-east-1',
								'hint'        => (string) $field_hints['region'],
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'        => 'url',
								'id'          => 'sakura-s3-endpoint',
								'name'        => 'endpoint',
								'label'       => __( '服务地址', 'sakura-imgopt' ),
								'value'       => '',
								'placeholder' => (string) $field_hints['placeholder'],
								'hint'        => (string) $field_hints['endpoint'],
								'full'        => false,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'         => 'password',
								'id'           => 'sakura-s3-key',
								'name'         => 'key',
								'label'        => __( 'Access Key', 'sakura-imgopt' ),
								'value'        => '',
								'autocomplete' => 'new-password',
								'placeholder'  => '' !== (string) $options['key'] ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入访问密钥', 'sakura-imgopt' ),
								'hint'         => __( '加密保存，页面不回显；更换站点加密密钥后需要重新填写。', 'sakura-imgopt' ),
								'full'         => false,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'         => 'password',
								'id'           => 'sakura-s3-secret',
								'name'         => 'secret',
								'label'        => __( 'Secret Key', 'sakura-imgopt' ),
								'value'        => '',
								'autocomplete' => 'new-password',
								'placeholder'  => '' !== (string) $options['secret'] ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入密钥', 'sakura-imgopt' ),
								'hint'         => __( '加密保存，页面不回显；更换加密密钥或站点安全密钥后需要重填。', 'sakura-imgopt' ),
								'full'         => false,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'   => 'custom',
								'id'     => 'sakura-s3-path',
								'label'  => __( '云端路径前缀', 'sakura-imgopt' ),
								'hint'   => $path_hint,
								'full'   => true,
								'control' => function () use ( $upload_path, $upload_path_src ) {
									$readonly = 'wordpress_option' === $upload_path_src;
									$html     = '<input class="code" id="sakura-s3-path" type="text" value="' . esc_attr( $upload_path ) . '"' . ( $readonly ? ' readonly' : ' name="upload_path"' ) . ' />';
									if ( $readonly ) {
										$html .= '<input type="hidden" name="upload_path" value="' . esc_attr( $upload_path ) . '" />';
									}
									return $html;
								},
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'        => 'url',
								'id'          => 'sakura-s3-url',
								'name'        => 'upload_url_path',
								'label'       => __( '图片地址前缀', 'sakura-imgopt' ),
								'value'       => '',
								'placeholder' => 'https://cdn.example.com/wp-content/uploads',
								'hint'        => __( '保存后会写入 WordPress 的图片地址设置。不会自动替换文章里已经写好的旧地址。', 'sakura-imgopt' ),
								'full'        => true,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
						echo '</div>';
					}
				);

				UI::card(
					array(
						'id'          => 'behaviour',
						'kicker'      => 'BEHAVIOUR',
						'title'       => __( '上传行为', 'sakura-imgopt' ),
						'description' => __( '对所有存储配置生效。', 'sakura-imgopt' ),
						'anchor'      => 'sakura-s3-behaviour',
						'form'        => array(
							'id'          => 'sakura-s3-behaviour-form',
							'action'      => admin_url( 'admin-post.php' ),
							'markers'     => 'fields',
							'marker_card' => 'behaviour',
							'hidden'      => array(
								'action'     => 'sakura_s3_save',
								'profile_id' => $active_id,
							),
						),
						'save'        => __( '保存设置', 'sakura-imgopt' ),
					),
					function () use ( $options, $concurrency, $parallel, $effective, $behaviour_frozen, $behaviour, $risk_locked, $locked_label ) {
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => '_wpnonce',
								'value' => wp_create_nonce( 'sakura_s3_save' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						/*
						 * R2 (07-S3存储生命周期): the dialog is a courtesy, the server is the
						 * guard. This value stays 0 until the operator has read the warning and
						 * agreed to it in storage-connection.js; a hand-built or replayed POST
						 * never carries 1, so the save comes back as error=risk_ack.
						 */
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => 'no_local_saving_ack',
								'value' => '0',
							),
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						/*
						 * R11: the two switches are frozen once an upload has been
						 * confirmed. They render disabled and, more importantly, the
						 * values shown are the frozen snapshot — not whatever a stray
						 * POST left in the option.
						 */
						$no_thumb        = $behaviour_frozen ? 'true' === (string) $behaviour['no_thumb'] : 'true' === (string) $options['no_thumb'];
						$no_local_saving = $behaviour_frozen ? 'true' === (string) $behaviour['no_local_saving'] : 'true' === (string) $options['no_local_saving'];

						echo '<div class="field-list">';

						/*
						 * §7 of 07-S3存储生命周期. The lock is invisible otherwise: the card
						 * would just look like two disabled checkboxes with no reason.
						 */
						$lock_note = '';
						if ( $behaviour_frozen ) {
							$lock_note = '' !== $locked_label
								? sprintf(
									/* translators: %s: lock time. */
									__( '%s 起已锁定。', 'sakura-imgopt' ),
									$locked_label
								)
								: __( '已锁定。', 'sakura-imgopt' );
							if ( $risk_locked ) {
								$lock_note .= __( '这次绑定还开启了「上传成功后删除本地文件」，本地副本已不在。如需更换存储服务商或存储桶，只能先点当前配置卡片关闭 S3 存储，删除其余全部存储配置，再删除当前配置，然后重新新增。', 'sakura-imgopt' );
							} else {
								$lock_note .= __( '如需更换存储桶，请先点当前配置卡片关闭 S3 存储，再删除它。', 'sakura-imgopt' );
							}
							$lock_note .= __( '「不上传 WordPress 生成的缩略图」与「上传成功后删除本地文件」已随绑定一起固定，只有把存储配置全部删除之后才能重新选择。', 'sakura-imgopt' );
						} else {
							$lock_note = __( '这份配置还没有成功上传过。完成第一次上传后，服务商、存储桶、服务地址、云端路径与「上传成功后删除本地文件」将被锁定。', 'sakura-imgopt' );
						}
						echo '<p class="behaviour-lock' . ( $behaviour_frozen ? ' is-locked' : '' ) . '" data-sakura-role="behaviour-lock">' . esc_html( $lock_note ) . '</p>';

						$this->check_field(
							'no_thumb',
							__( '不上传 WordPress 生成的缩略图', 'sakura-imgopt' ),
							__( '一般不建议开启，否则部分自适应图片可能无法从云端读取。', 'sakura-imgopt' ),
							$no_thumb,
							false,
							true,
							$behaviour_frozen
						);
						$this->check_field(
							'no_local_saving',
							__( '上传成功后删除本地文件（高风险）', 'sakura-imgopt' ),
							__( '勾选后，只有新上传的图片及其缩略图都成功传到云端，才会删除本地文件；不勾选就一直保留。手动同步历史附件时始终保留本地文件。', 'sakura-imgopt' ),
							$no_local_saving,
							true,
							true,
							$behaviour_frozen
						);

						$options_map = array();
						for ( $i = 1; $i <= \Sakura\ImageOptimizer\Settings\Settings_Repository::MAX_UPLOAD_CONCURRENCY; $i++ ) {
							$options_map[ (string) $i ] = (string) $i;
						}
						UI::field(
							array(
								'type'        => 'select',
								'id'          => 'sakura-s3-upload-concurrency',
								'name'        => 'upload_concurrency',
								'label'       => __( '云端传输并发数', 'sakura-imgopt' ),
								'hint'        => __( '同一次上传里同时向云端传输的文件数，默认 1。调高会缩短多尺寸图片的等待时间，但更占 PHP 工作进程和带宽；服务器或 HTTP 传输环境不支持并行时会自动降级。', 'sakura-imgopt' ),
								'value'       => (string) $concurrency,
								'options'     => $options_map,
								'full'        => true,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						if ( $concurrency > 1 && ! $parallel ) {
							UI::notice(
								sprintf(
									/* translators: %d: effective concurrency. */
									__( '当前 HTTP 传输环境不支持并行，实际使用 %d。', 'sakura-imgopt' ),
									$effective
								),
								'warn'
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
						}
						echo '</div>';
					}
				);

				Sync_Center_Panel::render( $this->status ?? new Sync_Status() );

				Monitoring_Panel::render();

				echo '</div>';

				/*
				 * Result shell for the dry-run connection probe. The tone, title and
				 * message are rewritten by storage-connection.js for every attempt,
				 * so the markup stays here while the verdict stays in JavaScript.
				 */
				$probe_modal = UI::modal_html(
					array(
						'id'       => 'sakura-s3-probe-modal',
						'tone'     => 'info',
						'kicker'   => 'PROBE',
						'title'    => __( '连接测试', 'sakura-imgopt' ),
						'message'  => '',
						'size'     => 'compact',
						'autoshow' => false,
					)
				);
				echo $probe_modal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped values.

				/*
				 * Shell for the storage-space picker. The list is filled by
				 * storage-connection.js once the account reports more than one space;
				 * rendering it empty here keeps the dialog markup in one place instead
				 * of assembling modal chrome in JavaScript.
				 */
				$doge_modal = UI::modal_html(
					array(
						'id'       => 'sakura-s3-doge-modal',
						'tone'     => 'info',
						'kicker'   => 'DOGECLOUD',
						'title'    => __( '选择存储空间', 'sakura-imgopt' ),
						'message'  => (string) $page_strings['dogePick'],
						'body'     => '<div class="doge-spaces" data-sakura-doge-spaces></div>',
						'size'     => 'compact',
						'autoshow' => false,
					)
				);
				echo $doge_modal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped values.

				echo Config_Notice::modal_html( $this->options, 'connection' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped values.
			}
		);
	}

	/**
	 * One checkbox row with a bold label and a description.
	 */
	private function check_field( string $name, string $text, string $hint, bool $checked, bool $danger = false, bool $full = false, bool $disabled = false ): void {
		UI::field(
			array(
				'type'    => 'custom',
				'class'   => 'field--check',
				'full'    => $full,
				'control' => static function () use ( $name, $text, $hint, $checked, $danger, $disabled ) {
					return '<label class="check check--stacked' . ( $disabled ? ' is-locked' : '' ) . '"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . ( $disabled ? ' disabled' : '' ) . ' /><span class="check__box" aria-hidden="true"></span><span class="check__text"><strong class="check__title">' . esc_html( $text ) . '</strong><span class="check__hint' . ( $danger ? ' is-danger' : '' ) . '">' . esc_html( $hint ) . '</span></span></label>';
				},
			)
		);
	}

	/**
	 * Attachments that would be lost by unbinding the current binding.
	 *
	 * R7/R10 quote this number in the confirmation dialog. Only attachments whose
	 * fingerprint points at the bound bucket count: files synced to some other
	 * profile survive this delete. Walking post meta is fine on a settings screen
	 * and would not be fine on a hot path, so callers decide when to ask.
	 *
	 * @param string $bound_id    Profile holding the binding, '' when unbound.
	 * @param bool   $risk_locked Whether that binding ran with no_local_saving.
	 */
	private static function lost_local_for( string $bound_id, bool $risk_locked ): int {
		if ( ! $risk_locked || '' === $bound_id ) {
			return 0;
		}

		$profile = ( new Options() )->profile_options( $bound_id );
		if ( null === $profile || array() === $profile ) {
			return 0;
		}

		return ( new Sync_Status() )->lost_local_count( Synchronous_Uploader::target_hash_for( new Options( $profile ) ) );
	}

	/**
	 * Why this profile may not be deleted, as an error code, or '' when it may.
	 *
	 * This is the R7 decision table (plus R10's extra condition), evaluated in the
	 * renderer so the button can be disabled before it is pressed. The server
	 * answers from the same table in Profile_Manager::delete_profile(); this copy
	 * exists so the operator is not invited to click something that will bounce.
	 *
	 * @param string $profile_id    Profile being considered.
	 * @param bool   $is_active     Whether it is the active profile.
	 * @param bool   $enabled       Whether S3 is currently on.
	 * @param string $bound_id      Profile holding the binding, '' when unbound.
	 * @param bool   $risk_locked   Whether that binding ran with no_local_saving.
	 * @param int    $profile_count Saved profiles in total.
	 */
	private static function delete_refusal( string $profile_id, bool $is_active, bool $enabled, string $bound_id, bool $risk_locked, int $profile_count ): string {
		// R7 rows 2 and 3: the profile the site is uploading to cannot go. Pausing
		// it first is the documented move, so the refusal is not a dead end.
		if ( $is_active && $enabled ) {
			return '' !== $bound_id && $bound_id === $profile_id ? 'delete_bound' : 'delete_active';
		}

		// R10: a high-risk lock means the local copies are already gone, so no
		// other profile can serve as a fallback. Only the last one may be deleted.
		if ( $is_active && ! $enabled && '' !== $bound_id && $bound_id === $profile_id && $risk_locked && $profile_count > 1 ) {
			return 'pending_profiles';
		}

		return '';
	}
	/**
	 * Prompt a delete opens with, shared by the renderer and the toggle payload.
	 *
	 * One source for the copy: the card markup and state_payload() must produce
	 * the same dialog, otherwise a toggle repaint silently swaps a warning for a
	 * plain confirm. R7 covers the unbind case; R10 adds the two lines that only
	 * apply once the local copies are already gone.
	 *
	 * @param int    $count       Attachments whose local copy is gone.
	 * @param bool   $is_unbind   Whether this delete also unbinds the site.
	 * @param bool   $risk_locked Whether the binding ran with no_local_saving.
	 * @param string $name        Profile name, typed back once the lock is high risk.
	 *
	 * @return array{title:string,text:string,items:string[],require:string}
	 */
	private static function delete_confirm( int $count, bool $is_unbind, bool $risk_locked, string $name = '' ): array {
		$confirm = array(
			'title'   => __( '删除已保存的配置', 'sakura-imgopt' ),
			'text'    => __( '确定删除这个已保存的配置吗？删除后不可恢复，它对应的核对记录、计数与日志也会一起清空，但不会影响当前活动配置。', 'sakura-imgopt' ),
			'items'   => array(),
			'require' => '',
		);

		if ( ! $is_unbind ) {
			return $confirm;
		}

		$confirm['title'] = __( '删除这份配置并解绑', 'sakura-imgopt' );
		$confirm['text']  = __( '删除后这份配置就没了，站点回到未配置状态，图片地址会还原为站点域名。', 'sakura-imgopt' );
		$confirm['items'] = array(
			__( '这份配置、它的核对记录、计数、删除队列与归档都会消失，本站回到未配置状态，图片地址会还原为站点域名。', 'sakura-imgopt' ),
			__( '云端对象不会跟着删除：桶里的文件仍在，需要自行到服务商控制台处理。', 'sakura-imgopt' ),
		);

		if ( ! $risk_locked ) {
			return $confirm;
		}

		$confirm['items'][] = sprintf(
			/* translators: %d: number of attachments whose local copy is gone. */
			__( '这 %d 个附件将永久无法显示：它们的本地副本在上传成功后已被删除，现在只存在于这个桶里。', 'sakura-imgopt' ),
			$count
		);
		$confirm['items'][] = __( '这是最后一次机会：删掉之后，除非用完全相同的参数重建这份配置，否则这些附件再也回不来。', 'sakura-imgopt' );
		$confirm['items'][] = __( '这里没有「切到另一份配置继续用」的选项：别的配置指向的桶里没有这些对象。', 'sakura-imgopt' );

		// R10 asks the operator to type the name back: a plain confirm is too easy
		// to click through when the cost is permanent.
		$confirm['require'] = $name;

		return $confirm;
	}

	/**
	 * Runtime state the profile cards need after a one-click toggle.
	 *
	 * The page never reloads on a toggle, so the card, the status strip and the
	 * banner all have to be repainted from one payload. Returning it here keeps
	 * the AJAX handler from re-deriving the same strings the renderer already
	 * owns.
	 *
	 * @return array<string,mixed>
	 */
	public static function state_payload(): array {
		$options        = new Options();
		$active_id      = $options->active_profile_id();
		$active_profile = '' !== $active_id ? $options->profile( $active_id ) : array();
		$bucket         = (string) $options->get( 'bucket', '' );
		$provider       = (string) $options->get( 'provider', '' );
		$provider_label = (string) ( Providers::all()[ $provider ] ?? 'S3' );
		$enabled        = $options->is_enabled();
		$configured     = $options->is_configured();

		$binding     = $options->binding();
		$bound_id    = null !== $binding ? (string) ( $binding['profile_id'] ?? '' ) : '';
		$risk_locked = null !== $binding && 'true' === (string) ( $binding['no_local_saving'] ?? 'false' );
		$saved       = array();
		foreach ( $options->saved_profiles() as $profile_id => $profile ) {
			if ( ! empty( $profile['saved'] ) ) {
				$saved[ (string) $profile_id ] = $profile;
			}
		}
		$total = count( $saved );
		// Same lazy count the renderer does: it walks synced attachments, so it only
		// runs when a high-risk dialog can actually quote the number.
		$lost_local = self::lost_local_for( $bound_id, $risk_locked );

		$profiles = array();
		foreach ( $saved as $profile_id => $profile ) {
			$is_active = ! empty( $profile['active'] );
			$is_live   = $is_active && $enabled;
			$badge     = __( '已保存', 'sakura-imgopt' );
			if ( $is_live ) {
				$badge = __( '使用中', 'sakura-imgopt' );
			} elseif ( $is_active ) {
				$badge = __( '已停用', 'sakura-imgopt' );
			}

			// R7/R10, from the same table the renderer and the server use: after a
			// toggle repaints the cards, the delete button has to end up in the same
			// state a reload would have produced.
			$refusal = self::delete_refusal( $profile_id, $is_active, $enabled, $bound_id, $risk_locked, $total );

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

			// R7/R10 prompt copy travels with the state so a toggle repaint can
			// retarget the same form without a reload.
			$is_unbind = $is_active && ! $enabled && '' !== $bound_id && $bound_id === $profile_id;
			$confirm   = self::delete_confirm( $is_unbind ? $lost_local : 0, $is_unbind, $risk_locked, (string) ( $profile['name'] ?? '' ) );

			$profiles[ $profile_id ] = array(
				'live'           => $is_live,
				'current'        => $is_active,
				'badge'          => $badge,
				'badgeTone'      => $is_live ? 'ok' : 'neutral',
				'deleteLabel'    => $label,
				'deleteTitle'    => $title,
				'deleteDisabled' => '' !== $refusal,
				'confirmTitle'   => $confirm['title'],
				'confirmText'    => $confirm['text'],
				'confirmItems'   => $confirm['items'],
				'confirmRequire' => $confirm['require'],
			);
		}

		return array(
			'enabled'         => $enabled,
			'configured'      => $configured,
			'activeId'        => $active_id,
			'bound'           => '' !== $bound_id,
			'riskLocked'      => $risk_locked,
			'behaviourFrozen' => $options->behaviour_frozen(),
			'tone'            => $configured ? ( $enabled ? 'ok' : 'warn' ) : 'bad',
			'statusValue'     => '' !== $bucket
				? Bucket_Label::display( $bucket, $options->space(), $provider )
				: __( '未配置', 'sakura-imgopt' ),
			'statusTitle'     => $bucket,
			'statusSub'       => $provider_label . ' · ' . ( $enabled ? __( '已启用', 'sakura-imgopt' ) : __( '已停用', 'sakura-imgopt' ) ),
			'providerLabel'   => $provider_label,
			'activeName'      => (string) ( $active_profile['name'] ?? '' ),
			'profiles'        => $profiles,
		);
	}

	/**
	 * Dialog shown right after a card click moves the site to another bucket.
	 *
	 * A switch invalidates three things at once: the thumbnail rewrite is
	 * turned off, the sync center now describes a bucket nobody has scanned,
	 * and the media URLs changed. The toast on the card is nowhere near those
	 * controls, so the same response carries a dialog the client opens.
	 *
	 * @param string $from      Previous profile name.
	 * @param string $to        New profile name.
	 * @param bool   $frozen    Whether the behaviour switches are frozen.
	 */
	public static function switch_modal_html( string $from, string $to, bool $frozen ): string {
		$items = array();

		$items[] = __( '缩略图地址改写已自动关闭：图片域名变了，缓存的缩略图地址也已作废。到「缩略图优化」核对图片域名后重新开启。', 'sakura-imgopt' );
		$items[] = __( '同步中心的数据还是上一个桶的：下载、上传、删除与刷新差异已暂停，点「开始完整核对」重建索引后自动恢复。', 'sakura-imgopt' );

		if ( $frozen ) {
			$items[] = __( '「上传行为」两个开关仍按原来的绑定生效，新的改动要等解绑之后。', 'sakura-imgopt' );
		}

		$body  = '<ul class="dialog__list">';
		foreach ( $items as $item ) {
			$body .= '<li>' . esc_html( $item ) . '</li>';
		}
		$body .= '</ul>';

		$to_label = '' !== $to ? $to : __( '新配置', 'sakura-imgopt' );
		$title    = '' !== $from
			? sprintf( /* translators: 1: previous profile name, 2: new profile name. */ __( '已从「%1$s」切换到「%2$s」', 'sakura-imgopt' ), $from, $to_label )
			: sprintf( /* translators: %s: new profile name. */ __( '已切换到「%s」', 'sakura-imgopt' ), $to_label );

		return UI::modal_html(
			array(
				'id'        => 'sakura-s3-switch-modal',
				'return_to' => '#sakura-s3-save-form [data-sakura-action="toggle-profile"]',
				'tone'      => 'warn',
				'kicker'    => 'OBJECT STORAGE / SWITCHED',
				'title'     => $title,
				'message'   => __( '这次点击换了存储桶，有两处需要你重新确认：', 'sakura-imgopt' ),
				'body'      => $body,
				'button'    => __( '知道了，去同步中心核对', 'sakura-imgopt' ),
			)
		);
	}
	/**
	 * Read-only redirect notice, mapped from the query string.
	 *
	 * @return array{message:string,tone:string}
	 */
	private function notice(): array {
		$success = array(
			'message' => '',
			'tone'    => 'ok',
		);

		if ( isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			$success['message'] = __( '已删除保存的配置。', 'sakura-imgopt' );
			return $success;
		}
		if ( isset( $_GET['switched'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			$success['message'] = __( '已切换配置；是否启用和上传策略保持不变。', 'sakura-imgopt' );
			return $success;
		}
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			if ( '1' === sanitize_key( wp_unslash( $_GET['saved'] ) ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
				$moved   = isset( $_GET['moved'] );
				$created = isset( $_GET['created'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
				if ( $moved ) {
					// The profile kept its id but now points somewhere else, so the
					// reconciliation rows for the previous target were dropped.
					$success['message'] = $created
						? __( '已保存为新的存储配置，并指向新的存储位置；请在同步中心重新核对一次。', 'sakura-imgopt' )
						: __( '已更新这份配置，并指向新的存储位置；旧的核对记录已清空，请在同步中心重新核对一次。', 'sakura-imgopt' );
					return $success;
				}
				$success['message'] = $created
					? __( '已保存为新的存储配置。', 'sakura-imgopt' )
					: __( '云存储设置已保存。', 'sakura-imgopt' );
				return $success;
			}

			$errors = array(
				'bucket'           => __( '还没有填存储桶名称。这个表单是用来新增一份配置的，想修改已保存的配置，请点上面的配置小卡片。', 'sakura-imgopt' ),
				'endpoint'         => __( '「服务地址」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'url'              => __( '「图片地址前缀」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'path'             => __( '「云端路径前缀」不能包含「..」这类上级目录写法。', 'sakura-imgopt' ),
				'config'           => __( '启用前必须填写存储桶名称、Access Key、Secret 和服务地址（使用 AWS 官方服务时地址可留空）。', 'sakura-imgopt' ),
				'crypto'           => __( '服务器缺少加密组件，无法安全保存密钥，请联系主机商开启。', 'sakura-imgopt' ),
				'switch'           => __( '无法切换：配置不存在或还不完整，请补全后保存。', 'sakura-imgopt' ),
				'switch_path'      => __( '无法切换：该配置的云端路径和站点当前上传目录不一致，切换后已上传的文件会找不到。请先统一路径，或调整站点的上传目录设置。', 'sakura-imgopt' ),
				'delete'           => __( '无法删除该配置，请刷新页面后重试。', 'sakura-imgopt' ),
				'delete_active'    => __( '当前使用中的配置不能删除。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
				'delete_bound'     => __( '当前使用中的配置不能删除，而且它还是绑定的那一份。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
				'pending_profiles' => __( '高风险锁定下不能保留其它配置。请先删除其余配置（它们指向的桶里没有这些附件），再删除当前配置。', 'sakura-imgopt' ),
				'locked'           => __( '当前处于高风险锁定：本地副本已删除，只有这份配置对应的存储桶里有这些文件，切到别的配置会变成死链。如需更换服务商或存储桶，请走「关闭 S3 → 删除其余全部配置 → 删除当前配置」。', 'sakura-imgopt' ),
				'risk_ack'         => __( '「上传成功后删除本地文件」是高风险选项，需要先确认风险再保存。', 'sakura-imgopt' ),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect error code; it does not change state.
			$key = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

			return array(
				'message' => $errors[ $key ] ?? __( '设置未保存。', 'sakura-imgopt' ),
				'tone'    => 'bad',
			);
		}
		if ( isset( $_GET['tested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			if ( '1' === sanitize_key( wp_unslash( $_GET['tested'] ) ) ) {
				/*
				 * A successful probe can still carry a note, for example when
				 * the bucket allows listing but refuses HEAD. Show it instead of
				 * the plain success line so a partial policy is not hidden.
				 */
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect message; it does not change state.
				$note                 = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
				$success['message']   = '' !== $note ? $note : __( '连接成功，存储桶可以访问。', 'sakura-imgopt' );
				return $success;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect message; it does not change state.
			$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : __( '连接失败。', 'sakura-imgopt' );

			return array(
				'message' => $message,
				'tone'    => 'bad',
			);
		}

		return $success;
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\S3_Storage_Page', __NAMESPACE__ . '\\Settings_Page' );
