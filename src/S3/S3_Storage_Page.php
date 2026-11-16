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
		$status_value     = '' !== $bucket ? Bucket_Label::display( $bucket, $space ) : __( '未配置', 'sakura-imgopt' );
		$status_sub       = $provider_label . ' · ' . ( $enabled ? __( '已启用', 'sakura-imgopt' ) : __( '已停用', 'sakura-imgopt' ) );
		$status_tone      = $configured ? ( $enabled ? 'ok' : 'warn' ) : 'bad';

		UI::page(
			array(
				'id'      => 'storage-connection',
				'module'  => 'storage',
				'tab'     => 'connection',
				'eyebrow' => 'OBJECT STORAGE / ' . SAKURA_TOOLS_VERSION,
				'title'   => __( 'S3 存储', 'sakura-imgopt' ),
				'lede'    => __( '把优化后的图片安全地同步到云存储。支持常见的 S3 兼容服务，并沿用原来的文件路径和图片地址前缀。', 'sakura-imgopt' ),
				'status'  => array(
					'label' => __( '当前存储桶', 'sakura-imgopt' ),
					'value' => $status_value,
					'title' => $bucket,
					'sub'   => $status_sub,
					'tone'  => $status_tone,
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
			function () use ( $notice, $credentials_bad, $constants_locked, $saved_profiles, $options, $space, $bucket, $active_id, $active_name, $provider_counts, $upload_path, $upload_path_src, $concurrency, $parallel, $effective ) {
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
					'profileLoadedKey' => __( '已载入这份配置。密钥留空表示沿用这份配置已保存的密钥；点「保存连接配置」才会生效。', 'sakura-imgopt' ),
					'providerSwitchNote' => __( '已更换服务商：这次保存会新建一份配置，原来那份保持不动。想继续用原来的配置，把服务商切回去即可。', 'sakura-imgopt' ),
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
						'description' => __( '点击已保存的配置就能切换，不用重新填写密钥。同一服务商可保存多个账户：填好新的 Access Key 和 Secret，勾选「另存为新配置」后保存。', 'sakura-imgopt' ),
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
					function () use ( $saved_profiles ) {
						$visible = array();
						foreach ( $saved_profiles as $profile_id => $profile ) {
							if ( ! empty( $profile['saved'] ) ) {
								$visible[ (string) $profile_id ] = (array) $profile;
							}
						}

						if ( array() === $visible ) {
							UI::empty_state(
								__( '还没有保存的配置', 'sakura-imgopt' ),
								__( '填写并保存下面的连接设置后，它会出现在这里。', 'sakura-imgopt' )
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
							return;
						}

						echo '<div class="profile-grid" data-sakura-role="profiles">';
						foreach ( $visible as $profile_id => $profile ) {
							$is_active = ! empty( $profile['active'] );
							$complete  = ! empty( $profile['complete'] );
							echo '<div class="profile-item">';
							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="profile-item__switch">';
							echo '<input type="hidden" name="action" value="sakura_s3_switch" />';
							echo '<input type="hidden" name="profile_id" value="' . esc_attr( $profile_id ) . '" />';
							wp_nonce_field( 'sakura_s3_switch' );
							echo '<button type="submit" class="profile-card' . ( $is_active ? ' is-active' : '' ) . '"' . ( $is_active ? ' aria-current="true"' : '' );
							disabled( ! $complete );
							echo '>';
							echo '<span class="profile-card__top"><span class="profile-card__name">' . esc_html( (string) ( $profile['name'] ?? '' ) ) . '</span>';
							UI::badge( $is_active ? __( '使用中', 'sakura-imgopt' ) : __( '已保存', 'sakura-imgopt' ), $is_active ? 'ok' : 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
							echo '</span>';
							echo '<span class="profile-card__meta">' . esc_html( (string) ( $profile['label'] ?? '' ) ) . '</span>';
							// 配置卡上显示空间名或缩写桶名，完整 s3Bucket 放 title。
							$card_bucket = (string) ( $profile['bucket'] ?? '' );
							$card_space  = (string) ( $profile['space'] ?? '' );
							echo '<span class="profile-card__meta profile-card__meta--mono"' . ( '' !== $card_bucket ? ' title="' . esc_attr( $card_bucket ) . '"' : '' ) . '>' . esc_html( '' !== $card_bucket ? Bucket_Label::display( $card_bucket, $card_space ) : __( '未填写存储桶', 'sakura-imgopt' ) ) . '</span>';
							echo '<span class="profile-card__meta profile-card__meta--mono">' . esc_html( '' !== (string) ( $profile['endpoint'] ?? '' ) ? (string) $profile['endpoint'] : __( '默认服务地址', 'sakura-imgopt' ) ) . '</span>';
							if ( ! $complete ) {
								echo '<span class="profile-card__warning">' . esc_html__( '配置不完整，补全后可切换', 'sakura-imgopt' ) . '</span>';
							}
							echo '</button>';
							echo '</form>';

							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="profile-item__delete" data-sakura-confirm="' . esc_attr__( '确定删除这个已保存的配置吗？删除后不可恢复，它对应的核对记录、计数与日志也会一起清空，但不会影响当前活动配置。', 'sakura-imgopt' ) . '" data-sakura-confirm-title="' . esc_attr__( '删除已保存的配置', 'sakura-imgopt' ) . '">';
							echo '<input type="hidden" name="action" value="sakura_s3_delete" />';
							echo '<input type="hidden" name="profile_id" value="' . esc_attr( $profile_id ) . '" />';
							wp_nonce_field( 'sakura_s3_delete_' . $profile_id );
							echo '<button type="submit" class="btn btn--quiet btn--small profile-delete"';
							disabled( $is_active );
							echo ' title="' . esc_attr( $is_active ? __( '使用中的配置不能删除，请先切换到其他配置', 'sakura-imgopt' ) : __( '删除此配置', 'sakura-imgopt' ) ) . '">';
							echo esc_html( $is_active ? __( '使用中不可删除', 'sakura-imgopt' ) : __( '删除此配置', 'sakura-imgopt' ) );
							echo '</button>';
							echo '</form>';
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
						'kicker'      => 'CONNECTION',
						'title'       => __( '连接设置', 'sakura-imgopt' ),
						'description' => __( '配置保存在网站数据库里，密钥会加密保存，页面上不会显示。', 'sakura-imgopt' ),
						'anchor'      => 'sakura-s3-connection',
						'wide'        => true,
						'form'        => array(
							'id'          => 'sakura-s3-connection-form',
							'action'      => admin_url( 'admin-post.php' ),
							'markers'     => 'fields',
							'marker_card' => 'connection',
							'hidden'      => array(
								'action'     => 'sakura_s3_save',
								'profile_id' => $active_id,
							),
						),
						'save'        => __( '保存连接配置', 'sakura-imgopt' ),
					),
					function () use ( $options, $space, $bucket, $active_name, $provider_options, $upload_path, $upload_path_src, $path_hint, $field_hints ) {
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => '_wpnonce',
								'value' => wp_create_nonce( 'sakura_s3_save' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<div class="field-list">';
						$this->check_field(
							'enabled',
							__( '启用 S3 存储', 'sakura-imgopt' ),
							__( '新上传的图片自动同步到云存储，并沿用它原来的文件名和尺寸。', 'sakura-imgopt' ),
							'true' === (string) $options['enabled'],
							false,
							false
						);

						/*
						 * The probe button rides next to the provider picker: it tests the
						 * values currently typed in the form, before anything is saved, so a
						 * typo in the endpoint shows up as a real error code instead of a
						 * profile that has to be deleted again. Building the select by hand is
						 * what buys the row; the markup mirrors Field_View::select().
						 */
						UI::field(
							array(
								'type'    => 'custom',
								'id'      => 'sakura-s3-provider',
								'label'   => __( '服务商', 'sakura-imgopt' ),
								'full'    => false,
								'control' => static function () use ( $options, $provider_options ) {
									$select = '<select class="field__grow" id="sakura-s3-provider" name="provider" data-sakura-provider-select="1">';
									foreach ( $provider_options as $provider_value => $provider_label ) {
										$select .= '<option value="' . esc_attr( (string) $provider_value ) . '"' . selected( (string) $options['provider'], (string) $provider_value, false ) . '>' . esc_html( (string) $provider_label ) . '</option>';
									}
									$select .= '</select>';

									return '<div class="field__row">'
										. $select
										. '<button type="button" class="btn btn--ghost" id="sakura-s3-test-connection" title="' . esc_attr__( '用下面表单里当前填写的参数测试一次，不会保存，也不会上传文件。', 'sakura-imgopt' ) . '">' . esc_html__( '测试连接', 'sakura-imgopt' ) . '</button>'
										. '</div>';
								},
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<p class="card__note s3-field-note">' . esc_html__( '七牛云、百度云、又拍云的 S3 兼容性还在验证，新建配置时暂不列出；已保存的配置仍可照常切换。', 'sakura-imgopt' ) . '</p>';

						/*
						 * Saved configs for the chosen provider, right under the picker.
						 * Clicking one only loads its values into this form — it does not
						 * activate it, does not send a request and does not touch the
						 * stored option. Nothing changes on the site until the operator
						 * presses 保存连接配置. The list is rendered empty and filled by
						 * storage-connection.js from the data node below, so the chips
						 * follow the provider picker without a page reload.
						 */
						echo '<div class="profile-chips" data-sakura-provider-profiles hidden>'
							. '<span class="profile-chips__label">' . esc_html__( '这份服务商的已保存配置', 'sakura-imgopt' ) . '</span>'
							. '<div class="profile-chips__list" data-sakura-provider-profile-list role="list"></div>'
							. '</div>';

						UI::field(
							array(
								'type'        => 'text',
								'id'          => 'sakura-s3-profile-name',
								'name'        => 'profile_name',
								'label'       => __( '配置名称', 'sakura-imgopt' ),
								'value'       => $active_name,
								'placeholder' => __( '例如：主站图床、备份桶', 'sakura-imgopt' ),
								'hint'        => __( '用于区分同一服务商下的多个存储桶；留空会自动按服务商和存储桶命名。', 'sakura-imgopt' ),
								'full'        => false,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						$this->check_field(
							'save_as_new',
							__( '另存为新配置（保留当前配置）', 'sakura-imgopt' ),
							__( '切换配置不会顺手开启或关闭同步，也不会改动缩略图和「删除本地文件」这类全局设置；文章里已有的图片地址不会被批量替换。', 'sakura-imgopt' ),
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
								'control' => static function () use ( $space, $bucket ) {
									return '<div class="field__row">'
										// 「获取 SDK 参数」把空间名写进这个隐藏字段，保存时随配置一起存下来，
										// 之后状态卡、配置卡和选择器就用它代替 40 位的 s3Bucket。
										// 这里必须用解析后的 $space：老配置只在自己的记录里存了空间名，
										// 留空会让用户下一次保存把它冲掉。
										. '<input type="hidden" id="sakura-s3-space" name="space" value="' . esc_attr( $space ) . '" />'
										. '<input type="text" class="field__grow" id="sakura-s3-bucket" name="bucket" value="' . esc_attr( $bucket ) . '" autocomplete="off" />'
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
								'value'       => (string) $options['region'],
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
								'value'       => (string) $options['endpoint'],
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
								'value'       => (string) $options['upload_url_path'],
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
					function () use ( $options, $concurrency, $parallel, $effective ) {
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => '_wpnonce',
								'value' => wp_create_nonce( 'sakura_s3_save' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<div class="field-list">';
						$this->check_field(
							'no_thumb',
							__( '不上传 WordPress 生成的缩略图', 'sakura-imgopt' ),
							__( '一般不建议开启，否则部分自适应图片可能无法从云端读取。', 'sakura-imgopt' ),
							'true' === (string) $options['no_thumb'],
							false,
							true
						);
						$this->check_field(
							'no_local_saving',
							__( '上传成功后删除本地文件（高风险）', 'sakura-imgopt' ),
							__( '勾选后，只有新上传的图片及其缩略图都成功传到云端，才会删除本地文件；不勾选就一直保留。手动同步历史附件时始终保留本地文件。', 'sakura-imgopt' ),
							'true' === (string) $options['no_local_saving'],
							true,
							true
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
	private function check_field( string $name, string $text, string $hint, bool $checked, bool $danger = false, bool $full = false ): void {
		UI::field(
			array(
				'type'    => 'custom',
				'class'   => 'field--check',
				'full'    => $full,
				'control' => static function () use ( $name, $text, $hint, $checked, $danger ) {
					return '<label class="check check--stacked"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . ' /><span class="check__box" aria-hidden="true"></span><span class="check__text"><strong class="check__title">' . esc_html( $text ) . '</strong><span class="check__hint' . ( $danger ? ' is-danger' : '' ) . '">' . esc_html( $hint ) . '</span></span></label>';
				},
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
				'endpoint'      => __( '「服务地址」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'url'           => __( '「图片地址前缀」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'path'          => __( '「云端路径前缀」不能包含「..」这类上级目录写法。', 'sakura-imgopt' ),
				'config'        => __( '启用前必须填写存储桶名称、Access Key、Secret 和服务地址（使用 AWS 官方服务时地址可留空）。', 'sakura-imgopt' ),
				'crypto'        => __( '服务器缺少加密组件，无法安全保存密钥，请联系主机商开启。', 'sakura-imgopt' ),
				'switch'        => __( '无法切换：配置不存在或还不完整，请补全后保存。', 'sakura-imgopt' ),
				'switch_path'   => __( '无法切换：该配置的云端路径和站点当前上传目录不一致，切换后已上传的文件会找不到。请先统一路径，或调整站点的上传目录设置。', 'sakura-imgopt' ),
				'delete'        => __( '无法删除该配置，请刷新页面后重试。', 'sakura-imgopt' ),
				'delete_active' => __( '当前使用中的配置不能删除，请先切换到其他配置。', 'sakura-imgopt' ),
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
