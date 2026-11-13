<?php
/**
 * Monitoring and alert settings panel for the S3 screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Prometheus/Webhook controls and handles their admin-post forms.
 *
 * The forms are deliberately server-rendered: each action is a normal POST with
 * a nonce, so the screen still works when JavaScript is unavailable and no
 * extra AJAX surface is exposed for credential-like values.
 */
final class Monitoring_Panel {

	private const NOTICE_KEY = 'sakura_imgopt_s3_monitor_notice_';

	/**
	 * Render the panel.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config = Alerting::get();
		$notice = self::pull_notice();

		\Sakura\ImageOptimizer\Admin\UI::card(
			array(
				'id'          => 'monitoring',
				'title'       => __( '监控与告警', 'sakura-imgopt' ),
				'description' => __( 'Prometheus 只读端点供监控系统抓取；Webhook 在死信或失败率超阈值时推送 JSON，可直接接 Slack、飞书、钉钉或自建网关。', 'sakura-imgopt' ),
				'class'       => 'card--wide',
			),
			static function () use ( $config, $notice ): void {
				$notice_tone = 'info';

				if ( null !== $notice ) {
					$notice_tone = 'success' === $notice['type'] ? 'ok' : ( 'error' === $notice['type'] ? 'bad' : 'info' );
					// Saving the endpoint or firing a test webhook is an event: prompt it.
					\Sakura\ImageOptimizer\Admin\UI::modal(
						array(
							'id'        => 'sakura-monitor-result-modal',
							'return_to' => '.monitor-savebar .btn',
							'tone'    => $notice_tone,
							'size'    => 'compact',
							'kicker'  => 'MONITORING / RESULT',
							'title'   => 'ok' === $notice_tone ? __( '操作已完成', 'sakura-imgopt' ) : ( 'bad' === $notice_tone ? __( '没有完成', 'sakura-imgopt' ) : __( '已处理', 'sakura-imgopt' ) ),
							'message' => (string) $notice['message'],
						)
					);
				}
				?>
				<div class="monitor-grid">
					<section class="monitor-panel" aria-labelledby="sakura-monitor-prometheus-title">
						<h3 class="monitor-panel__title" id="sakura-monitor-prometheus-title"><?php esc_html_e( 'Prometheus 抓取端点', 'sakura-imgopt' ); ?></h3>
						<p class="small-text"><?php esc_html_e( '地址含只读令牌，请按密钥管理；令牌泄露后可立即轮换。也支持 Authorization: Bearer 令牌。', 'sakura-imgopt' ); ?></p>
						<?php
						\Sakura\ImageOptimizer\Admin\UI::field(
							array(
								'type'       => 'text',
								'id'         => 'sakura-s3-prometheus-url',
								'label'      => __( '抓取地址', 'sakura-imgopt' ),
								'value'      => Prometheus::url(),
								'readonly'   => true,
								'full'       => true,
								'autocomplete' => 'off',
								'attrs'      => array( 'spellcheck' => 'false' ),
							)
						);
						?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="monitor-panel__actions">
							<input type="hidden" name="action" value="sakura_s3_prometheus_rotate" />
							<?php wp_nonce_field( 'sakura_s3_prometheus_rotate' ); ?>
							<button type="submit" class="btn btn--ghost btn--small"><?php esc_html_e( '轮换令牌', 'sakura-imgopt' ); ?></button>
						</form>
					</section>

					<section class="monitor-panel" aria-labelledby="sakura-monitor-alerts-title">
						<h3 class="monitor-panel__title" id="sakura-monitor-alerts-title"><?php esc_html_e( 'Webhook 告警', 'sakura-imgopt' ); ?></h3>
						<form id="sakura-s3-alerts-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="sakura_s3_alerts_save" />
							<?php wp_nonce_field( 'sakura_s3_alerts_save' ); ?>

							<div class="field-list">
								<div class="field field--full field--check">
									<div class="field__main">
										<span class="field__label"><?php esc_html_e( '告警开关', 'sakura-imgopt' ); ?></span>
										<p class="field__hint"><?php esc_html_e( '启用后才会推送；保存或测试都会校验地址是否可访问。', 'sakura-imgopt' ); ?></p>
									</div>
									<div class="field__control">
										<label class="check check--stacked">
											<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> />
											<span class="check__box" aria-hidden="true"></span>
											<span class="check__text"><strong class="check__title"><?php esc_html_e( '启用 Webhook 告警', 'sakura-imgopt' ); ?></strong></span>
										</label>
									</div>
								</div>

								<?php
								\Sakura\ImageOptimizer\Admin\UI::field(
									array(
										'type'        => 'url',
										'id'          => 'sakura-s3-alert-webhook',
										'name'        => 'webhook',
										'label'       => __( 'Webhook 地址', 'sakura-imgopt' ),
										'value'       => (string) $config['webhook'],
										'placeholder' => 'https://hooks.slack.com/services/...',
										'full'        => true,
									)
								);
								?>
								<div class="monitor-fields">
									<?php
									\Sakura\ImageOptimizer\Admin\UI::field(
										array(
											'type'  => 'number',
											'id'    => 'sakura-s3-alert-rate',
											'name'  => 'fail_rate',
											'label' => __( '失败率阈值（%）', 'sakura-imgopt' ),
											'value' => (string) $config['fail_rate'],
											'min'   => 1,
											'max'   => 100,
											'step'  => 1,
										)
									);
									\Sakura\ImageOptimizer\Admin\UI::field(
										array(
											'type'  => 'number',
											'id'    => 'sakura-s3-alert-attempts',
											'name'  => 'min_attempts',
											'label' => __( '最少尝试次数', 'sakura-imgopt' ),
											'value' => (string) $config['min_attempts'],
											'min'   => 1,
											'max'   => 100000,
											'step'  => 1,
										)
									);
									\Sakura\ImageOptimizer\Admin\UI::field(
										array(
											'type'  => 'number',
											'id'    => 'sakura-s3-alert-cooldown',
											'name'  => 'cooldown',
											'label' => __( '冷却时间（分钟）', 'sakura-imgopt' ),
											'value' => (string) $config['cooldown'],
											'min'   => 5,
											'max'   => 1440,
											'step'  => 1,
										)
									);
									?>
								</div>
							</div>
						</form>

						<div class="card__savebar monitor-savebar">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="monitor-savebar__aside">
								<input type="hidden" name="action" value="sakura_s3_alerts_test" />
								<?php wp_nonce_field( 'sakura_s3_alerts_test' ); ?>
								<button type="submit" class="btn btn--ghost btn--small"><?php esc_html_e( '发送测试告警', 'sakura-imgopt' ); ?></button>
							</form>
							<button type="submit" form="sakura-s3-alerts-form" class="btn btn--primary"><?php esc_html_e( '保存告警设置', 'sakura-imgopt' ); ?></button>
						</div>
					</section>
				</div>
				<?php
			}
		);
	}

	/**
	 * Persist the alert form.
	 */
	public static function handle_save(): void {
		self::guard( 'sakura_s3_alerts_save' );

		Alerting::save(
			array(
				'enabled'      => isset( $_POST['enabled'] ) ? 1 : 0,
				'webhook'      => isset( $_POST['webhook'] ) ? (string) wp_unslash( $_POST['webhook'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() validates and escapes the URL.
				'fail_rate'    => isset( $_POST['fail_rate'] ) ? absint( wp_unslash( $_POST['fail_rate'] ) ) : 25,
				'min_attempts' => isset( $_POST['min_attempts'] ) ? absint( wp_unslash( $_POST['min_attempts'] ) ) : 20,
				'cooldown'     => isset( $_POST['cooldown'] ) ? absint( wp_unslash( $_POST['cooldown'] ) ) : 30,
			)
		);

		self::set_notice( 'success', __( '告警设置已保存。', 'sakura-imgopt' ) );
		self::redirect();
	}

	/**
	 * Send a manual test webhook.
	 */
	public static function handle_test(): void {
		self::guard( 'sakura_s3_alerts_test' );

		$result = Alerting::test();
		self::set_notice( ! empty( $result['ok'] ) ? 'success' : 'error', (string) $result['message'] );
		self::redirect();
	}

	/**
	 * Rotate the Prometheus token.
	 */
	public static function handle_rotate(): void {
		self::guard( 'sakura_s3_prometheus_rotate' );

		Prometheus::rotate();
		self::set_notice( 'success', __( 'Prometheus 令牌已轮换，请更新抓取配置。', 'sakura-imgopt' ) );
		self::redirect();
	}

	/**
	 * Capability and nonce check for admin-post actions.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( string $nonce ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ), 403 );
		}

		check_admin_referer( $nonce );
	}

	/** Redirect back to the S3 screen. */
	private static function redirect(): void {
		wp_safe_redirect( \Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'connection' ) );
		exit;
	}

	/**
	 * Store a one-shot notice for the current user.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 */
	private static function set_notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			array(
				'type'    => in_array( $type, array( 'success', 'error' ), true ) ? $type : 'info',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read and delete the pending notice.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private static function pull_notice(): ?array {
		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'type'    => (string) ( $notice['type'] ?? 'info' ),
			'message' => (string) $notice['message'],
		);
	}
}
