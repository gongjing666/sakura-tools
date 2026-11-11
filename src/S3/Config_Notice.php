<?php
/**
 * Centered setup prompt for an incomplete S3 configuration.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a per-user setup dialog instead of a red admin notice.
 *
 * The prompt is intentionally separate from the destructive delete modal: it
 * is informational, has no danger styling, and stays dismissed for the current
 * user until they clear the saved preference. A plain "later" close snoozes it
 * for twelve hours instead of hiding it forever; the choice is stored per user
 * so it survives a browser restart and does not leak across operators.
 */
final class Config_Notice {

	/**
	 * User meta flag set when the operator asks not to see this again.
	 */
	public const META_KEY = 'sakura_imgopt_s3_config_notice_dismissed';

	/**
	 * User meta key holding the Unix timestamp until which a plain dismissal
	 * keeps the dialog quiet.
	 */
	public const SNOOZE_META_KEY = 'sakura_imgopt_s3_config_notice_snoozed_until';

	/**
	 * How long "later" keeps the dialog quiet, in seconds (12 hours).
	 */
	public const SNOOZE_SECONDS = 43200;

	/**
	 * Human labels for the machine-readable missing fields.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_LABELS = array(
		'provider' => '服务商',
		'bucket'   => '存储桶名称',
		'key'      => 'Access Key',
		'secret'   => 'Secret Key',
		'endpoint' => '服务地址',
	);

	/**
	 * Whether the dialog should be rendered for the current request.
	 *
	 * @param Options $options Storage options.
	 * @return bool
	 */
	public static function should_show( Options $options ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( array() === $options->configuration_errors() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( '1' === (string) get_user_meta( $user_id, self::META_KEY, true ) ) {
			return false;
		}

		$snoozed_until = (int) get_user_meta( $user_id, self::SNOOZE_META_KEY, true );
		if ( $snoozed_until > time() ) {
			return false;
		}

		if ( $snoozed_until > 0 ) {
			delete_user_meta( $user_id, self::SNOOZE_META_KEY );
		}

		return true;
	}

	/**
	 * Return the dialog markup, or an empty string when it is not needed.
	 *
	 * @param Options $options Storage options.
	 * @param string  $context Current screen: connection, sync or deletes.
	 * @return string
	 */
	public static function modal_html( Options $options, string $context = '' ): string {
		if ( ! self::should_show( $options ) ) {
			return '';
		}

		$is_connection = 'connection' === $context;
		$primary_href  = $is_connection ? '#sakura-s3-save-form' : Registry::url( 'storage', 'connection' );
		$primary_label = $is_connection ? __( '填写连接信息', 'sakura-imgopt' ) : __( '去补全配置', 'sakura-imgopt' );
		$missing       = array();

		foreach ( $options->configuration_errors() as $field ) {
			$missing[] = self::FIELD_LABELS[ $field ] ?? $field;
		}

		ob_start();
		?>
		<div class="sakura-modal sakura-modal--config" id="sakura-s3-config-modal" hidden aria-hidden="true" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'sakura_s3_config_notice' ) ); ?>">
			<div class="sakura-modal__backdrop" data-config-close></div>
			<div class="sakura-modal__card" role="dialog" aria-modal="true" aria-labelledby="sakura-s3-config-title" aria-describedby="sakura-s3-config-desc">
				<button type="button" class="sakura-modal__close" data-config-close aria-label="<?php esc_attr_e( '关闭', 'sakura-imgopt' ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
				</button>
				<div class="sakura-modal__icon sakura-modal__icon--info" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
						<path d="M7.2 18.5h9.9a3.6 3.6 0 0 0 .35-7.18 5.4 5.4 0 0 0-10.47-1.2A3.95 3.95 0 0 0 7.2 18.5Z"/>
						<path d="M12 9.5v5.2M9.4 12.1 12 9.5l2.6 2.6"/>
					</svg>
				</div>
				<p class="section-kicker">OBJECT STORAGE / SETUP</p>
				<h2 id="sakura-s3-config-title" tabindex="-1" data-config-focus><?php esc_html_e( '还差几步就能启用对象存储', 'sakura-imgopt' ); ?></h2>
				<p id="sakura-s3-config-desc"><?php esc_html_e( '连接信息还不完整。补全后，插件会恢复图片同步、媒体地址接管与云端清理；在补全之前，这些动作会保持暂停。', 'sakura-imgopt' ); ?></p>
				<div class="sakura-config-missing">
					<span class="sakura-config-missing-label"><?php esc_html_e( '还需要补全', 'sakura-imgopt' ); ?></span>
					<div class="sakura-config-chips">
						<?php foreach ( $missing as $label ) : ?>
							<span class="sakura-config-chip"><?php echo esc_html( $label ); ?></span>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="sakura-modal__actions sakura-config-actions">
					<label class="sakura-config-remember">
						<input type="checkbox" data-config-dont-remind>
						<span><?php esc_html_e( '不再提醒我', 'sakura-imgopt' ); ?></span>
					</label>
					<div class="sakura-config-buttons">
						<button class="btn btn--ghost" type="button" data-config-close><?php esc_html_e( '稍后处理', 'sakura-imgopt' ); ?></button>
						<a class="btn btn--primary" data-config-primary href="<?php echo esc_url( $primary_href ); ?>"><?php echo esc_html( $primary_label ); ?></a>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
