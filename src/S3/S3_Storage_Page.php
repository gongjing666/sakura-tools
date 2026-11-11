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
		$provider_label   = (string) ( Providers::all()[ $options['provider'] ] ?? 'S3' );
		$enabled          = $this->options->is_enabled();
		$configured       = $this->options->is_configured();
		$status_value     = '' !== $bucket ? $bucket : __( '未配置', 'sakura-imgopt' );
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
			function () use ( $notice, $credentials_bad, $constants_locked, $saved_profiles, $options, $active_id, $active_name, $provider_counts, $upload_path, $upload_path_src, $concurrency, $parallel, $effective ) {
				if ( '' !== (string) $notice['message'] ) {
					UI::alert( (string) $notice['message'], (string) $notice['tone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.
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

				$page_strings = array(
					'profileCreated' => __( '已勾选「另存为新配置」，填写新的 Access Key 和 Secret 后保存。', 'sakura-imgopt' ),
					'metricsUpdated' => __( '更新于 %s', 'sakura-imgopt' ),
					'metricsNever'   => __( '尚未记录', 'sakura-imgopt' ),
					'metricsLoading' => __( '正在刷新…', 'sakura-imgopt' ),
					'metricsFailed'  => __( '指标刷新失败，请稍后重试。', 'sakura-imgopt' ),
					'metricsRate'    => __( '失败 %1$d · 失败率 %2$s', 'sakura-imgopt' ),
					'metricsMax'     => __( '最慢 %s', 'sakura-imgopt' ),
					'metricsWeek'    => __( '近 14 天失败率 %s', 'sakura-imgopt' ),
					'metricsNoError' => __( '暂无错误记录', 'sakura-imgopt' ),
				);
				?>
				<div class="sakura-admin__data" data-sakura-connection-strings="<?php echo esc_attr( wp_json_encode( array( 'strings' => $page_strings ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) ); ?>" hidden></div>
				<?php

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
							echo '<span class="profile-card__meta profile-card__meta--mono">' . esc_html( '' !== (string) ( $profile['bucket'] ?? '' ) ? (string) $profile['bucket'] : __( '未填写存储桶', 'sakura-imgopt' ) ) . '</span>';
							echo '<span class="profile-card__meta profile-card__meta--mono">' . esc_html( '' !== (string) ( $profile['endpoint'] ?? '' ) ? (string) $profile['endpoint'] : __( '默认服务地址', 'sakura-imgopt' ) ) . '</span>';
							if ( ! $complete ) {
								echo '<span class="profile-card__warning">' . esc_html__( '配置不完整，补全后可切换', 'sakura-imgopt' ) . '</span>';
							}
							echo '</button>';
							echo '</form>';

							echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="profile-item__delete" data-sakura-confirm="' . esc_attr__( '确定删除这个已保存的配置吗？删除后不可恢复，但不会影响当前活动配置。', 'sakura-imgopt' ) . '" data-sakura-confirm-title="' . esc_attr__( '删除已保存的配置', 'sakura-imgopt' ) . '">';
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

				echo '<div class="card-grid" id="sakura-s3-save-form" data-sakura-layout="s3" data-sakura-group="main">';

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
				foreach ( Providers::all() as $key => $label ) {
					$count  = (int) ( $provider_counts[ $key ] ?? 0 );
					$suffix = $count > 0
						/* translators: %d: number of saved profiles for this provider. */
						? sprintf( __( '（已保存 %d 个）', 'sakura-imgopt' ), $count )
						: __( '（未保存）', 'sakura-imgopt' );
					$provider_options[ (string) $key ] = (string) $label . $suffix;
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
					function () use ( $options, $active_name, $provider_options, $upload_path, $upload_path_src, $path_hint ) {
						UI::field(
							array(
								'type'  => 'hidden',
								'name'  => '_wpnonce',
								'value' => wp_create_nonce( 'sakura_s3_save' ),
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						echo '<p class="card__note">' . esc_html__( '下面这些设置会跟着每份配置单独保存；切换配置时，访问权限、云端路径和图片地址前缀也会一起切换。', 'sakura-imgopt' ) . '</p>';

						echo '<div class="field-list">';
						$this->check_field(
							'enabled',
							__( '启用 S3 存储', 'sakura-imgopt' ),
							__( '新上传的图片自动同步到云存储，并沿用它原来的文件名和尺寸。', 'sakura-imgopt' ),
							'true' === (string) $options['enabled'],
							false,
							true
						);

						UI::field(
							array(
								'type'    => 'select',
								'id'      => 'sakura-s3-provider',
								'name'    => 'provider',
								'label'   => __( '服务商', 'sakura-imgopt' ),
								'value'   => (string) $options['provider'],
								'options' => $provider_options,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'        => 'text',
								'id'          => 'sakura-s3-profile-name',
								'name'        => 'profile_name',
								'label'       => __( '配置名称', 'sakura-imgopt' ),
								'value'       => $active_name,
								'placeholder' => __( '例如：主站图床、备份桶', 'sakura-imgopt' ),
								'hint'        => __( '用于区分同一服务商下的多个存储桶；留空会自动按服务商和存储桶命名。', 'sakura-imgopt' ),
								'full'        => true,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						$this->check_field(
							'save_as_new',
							__( '另存为新配置（保留当前配置）', 'sakura-imgopt' ),
							__( '切换配置不会顺手开启或关闭同步，也不会改动缩略图和「删除本地文件」这类全局设置；文章里已有的图片地址不会被批量替换。', 'sakura-imgopt' ),
							false,
							false,
							true
						);

						UI::field(
							array(
								'type'         => 'text',
								'id'           => 'sakura-s3-bucket',
								'name'         => 'bucket',
								'label'        => __( '存储桶名称', 'sakura-imgopt' ),
								'value'        => (string) $options['bucket'],
								'autocomplete' => 'off',
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
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'        => 'url',
								'id'          => 'sakura-s3-endpoint',
								'name'        => 'endpoint',
								'label'       => __( '服务地址', 'sakura-imgopt' ),
								'value'       => (string) $options['endpoint'],
								'placeholder' => 'https://example.r2.cloudflarestorage.com',
								'hint'        => __( '只支持 http:// 或 https://。使用 AWS 官方服务时可以留空。', 'sakura-imgopt' ),
								'full'        => true,
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
								'hint'         => __( '密钥会加密保存在数据库里，页面上不会显示。建议在站点配置文件里设置一个固定的加密密钥；更换后需要重新填写。', 'sakura-imgopt' ),
								'full'         => true,
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
								'hint'         => __( '密钥会加密保存在数据库里，页面上不会显示。更换加密密钥或站点安全密钥后，旧密钥会失效，需要重新填写。', 'sakura-imgopt' ),
								'full'         => true,
							)
						); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Component escapes internally.

						UI::field(
							array(
								'type'    => 'select',
								'id'      => 'sakura-s3-acl',
								'name'    => 'object_acl',
								'label'   => __( '文件访问权限', 'sakura-imgopt' ),
								'value'   => (string) $options['object_acl'],
								'options' => array(
									'public-read' => __( '公开读取', 'sakura-imgopt' ),
									'private'     => __( '私有', 'sakura-imgopt' ),
								),
								'hint'    => __( '只在上传时作为权限参数提交，能否生效由云存储服务商决定：Cloudflare R2、MinIO 会忽略；缤纷云等由存储桶策略或 CDN 决定。只影响之后上传的文件，不会改变已有文件。', 'sakura-imgopt' ),
								'full'    => true,
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
						'id'          => 'test',
						'kicker'      => 'PROBE',
						'title'       => __( '连接测试', 'sakura-imgopt' ),
						'description' => __( '只检查能不能访问存储桶，不会上传测试文件。', 'sakura-imgopt' ),
						'anchor'      => 'sakura-s3-test',
					),
					function () {
						echo '<div class="card__savebar"><button type="submit" class="btn btn--primary" form="sakura-s3-test-form">' . esc_html__( '测试连接', 'sakura-imgopt' ) . '</button></div>';
					}
				);

				echo '</div>';

				echo '<form id="sakura-s3-test-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
				echo '<input type="hidden" name="action" value="sakura_s3_test" />';
				wp_nonce_field( 'sakura_s3_test' );
				echo '</form>';

				Sync_Center_Panel::render( $this->status ?? new Sync_Status() );
				Monitoring_Panel::render();
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
				$success['message'] = __( '云存储设置已保存。', 'sakura-imgopt' );
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
				$success['message'] = __( '连接成功，存储桶可以访问。', 'sakura-imgopt' );
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
