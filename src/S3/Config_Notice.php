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
 * is informational and has no danger styling.
 *
 * Only "do not remind me again" is remembered, and only per user. Every other
 * way of closing the dialog (the X, Escape, the backdrop, the close button)
 * persists nothing, so the dialog comes back on the next page load while the
 * condition it describes is still true. Version 1.25.6 and earlier parked a
 * plain close for twelve hours; that made "later" indistinguishable from
 * "handled" — an operator who closed the dialog by reflex saw a dashboard that
 * silently kept lying, and nothing on screen asked again.
 *
 * A second variant shares the markup: the sync center needs one full scan
 * before its numbers describe anything, and a silent empty dashboard is a
 * worse first visit than a dialog that says so. All variants read the same
 * mute flag because a site cannot be in two states at once.
 */
final class Config_Notice {

	/**
	 * User meta flag set when the operator asks not to see this again.
	 */
	public const META_KEY = 'sakura_imgopt_s3_config_notice_dismissed';

	/**
	 * User meta key that older versions wrote to park a plain close for twelve
	 * hours. Nothing writes it any more; it is read once so an upgraded site can
	 * drop a timestamp that no code acts on.
	 */
	public const SNOOZE_META_KEY = 'sakura_imgopt_s3_config_notice_snoozed_until';

	/**
	 * Whether the configuration dialog should be rendered for this request.
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

		return self::is_prompt_allowed();
	}

	/**
	 * Whether the reconciliation dialog should be rendered for this request.
	 *
	 * @param Options $options     Storage options.
	 * @param bool    $configured  Whether the connection is complete and enabled.
	 * @param bool    $has_scan    Whether a scan row exists at all.
	 * @param bool    $busy        Whether a scan is queued or running right now.
	 * @return bool
	 */
	public static function should_prompt_reconcile( Options $options, bool $configured, bool $has_scan, bool $busy ): bool {
		if ( ! $configured || $has_scan || $busy ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( array() !== $options->configuration_errors() ) {
			return false;
		}

		return self::is_prompt_allowed();
	}

	/**
	 * Whether the "object storage is paused" dialog should be rendered.
	 *
	 * A complete connection with the master switch off is neither a
	 * configuration error nor a first-run state. Uploads keep working locally
	 * and the dashboard keeps showing whatever the last scan wrote, so nothing
	 * on screen would say the bucket stopped receiving files unless a dialog
	 * said it. The check runs after the incomplete one so the two never both
	 * fire: one needs missing fields, the other needs every field present.
	 *
	 * @param Options $options Storage options.
	 * @return bool
	 */
	public static function should_prompt_paused( Options $options ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( array() !== $options->configuration_errors() ) {
			return false;
		}

		if ( $options->is_enabled() ) {
			return false;
		}

		return self::is_prompt_allowed();
	}

	/**
	 * Whether the operator may still be prompted: everything except an explicit
	 * "do not remind me again" keeps asking.
	 *
	 * @return bool
	 */
	private static function is_prompt_allowed(): bool {
		$user_id = get_current_user_id();
		if ( '1' === (string) get_user_meta( $user_id, self::META_KEY, true ) ) {
			return false;
		}

		// Older versions parked a plain close for twelve hours. The dialog now
		// returns immediately, so that leftover timestamp is dead weight.
		if ( '' !== (string) get_user_meta( $user_id, self::SNOOZE_META_KEY, true ) ) {
			delete_user_meta( $user_id, self::SNOOZE_META_KEY );
		}

		return true;
	}

	/**
	 * Human label for one machine-readable missing field.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private static function field_label( string $field ): string {
		switch ( $field ) {
			case 'provider':
				return __( '服务商', 'sakura-imgopt' );
			case 'bucket':
				return __( '存储桶名称', 'sakura-imgopt' );
			case 'endpoint':
				return __( '服务地址', 'sakura-imgopt' );
			case 'key':
				return 'Access Key';
			case 'secret':
				return 'Secret Key';
			default:
				return $field;
		}
	}

	/**
	 * Shared modal chrome: card, close button and icon. No scrim — the card
	 * floats over a page that stays interactive.
	 *
	 * @param string $id          Modal element id.
	 * @param string $title_id    Title element id.
	 * @param string $desc_id     Description element id.
	 * @param string $kicker      Uppercase eyebrow.
	 * @param string $title       Heading text.
	 * @param string $description Body copy.
	 * @param string $body        Extra markup between copy and actions.
	 * @param string $actions     Action row markup.
	 * @param string $nonce       Nonce for the dismiss endpoint.
	 * @param string $tone        Icon tone: info or neutral.
	 * @return void
	 */
	private static function render_modal( string $id, string $title_id, string $desc_id, string $kicker, string $title, string $description, string $body, string $actions, string $nonce, string $tone = 'info' ): void {
		?>
		<div class="sakura-modal sakura-modal--config" id="<?php echo esc_attr( $id ); ?>" hidden aria-hidden="true" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="sakura-modal__card" role="dialog" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" aria-describedby="<?php echo esc_attr( $desc_id ); ?>">
				<button type="button" class="sakura-modal__close" data-config-close aria-label="<?php esc_attr_e( '关闭', 'sakura-imgopt' ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
				</button>
				<div class="sakura-modal__icon sakura-modal__icon--<?php echo esc_attr( $tone ); ?>" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false">
						<path d="M7.2 18.5h9.9a3.6 3.6 0 0 0 .35-7.18 5.4 5.4 0 0 0-10.47-1.2A3.95 3.95 0 0 0 7.2 18.5Z"/>
						<path d="M12 9.5v5.2M9.4 12.1 12 9.5l2.6 2.6"/>
					</svg>
				</div>
				<p class="sakura-modal__kicker"><?php echo esc_html( $kicker ); ?></p>
				<h2 id="<?php echo esc_attr( $title_id ); ?>" tabindex="-1" data-config-focus><?php echo esc_html( $title ); ?></h2>
				<p id="<?php echo esc_attr( $desc_id ); ?>"><?php echo esc_html( $description ); ?></p>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments. ?>
				<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments. ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Mute checkbox plus the ghost/primary button pair.
	 *
	 * The right-hand control is a link or a button that acts. The ghost button
	 * is the plain close: it writes nothing, so the dialog returns on the next
	 * load until the operator either fixes the condition or ticks the mute box.
	 *
	 * @param string $ghost_label   Secondary button label.
	 * @param string $primary       Primary control markup.
	 * @param bool   $with_remember Whether to render the mute checkbox.
	 * @return string
	 */
	private static function actions_markup( string $ghost_label, string $primary, bool $with_remember = true ): string {
		$remember = '';
		if ( $with_remember ) {
			$remember = '<label class="sakura-config-remember"><input type="checkbox" data-config-dont-remind><span>' . esc_html__( '不再提醒我', 'sakura-imgopt' ) . '</span></label>';
		}

		return '<div class="sakura-modal__actions sakura-config-actions">' . $remember . '<div class="sakura-config-buttons"><button class="btn btn--ghost" type="button" data-config-close>' . esc_html( $ghost_label ) . '</button>' . $primary . '</div></div>';
	}

	/**
	 * Return the configuration dialog markup, or an empty string when it is
	 * not needed.
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
		// The connection screen wraps every card in one layout canvas, so the
		// old grid id would scroll to whichever card happens to sit at the top.
		// The connection card owns its own anchor and is the right target.
		$primary_href  = $is_connection ? '#sakura-s3-connection' : Registry::url( 'storage', 'connection' );
		$primary_label = $is_connection ? __( '填写连接信息', 'sakura-imgopt' ) : __( '去补全配置', 'sakura-imgopt' );
		$missing       = array();

		foreach ( $options->configuration_errors() as $field ) {
			$missing[] = self::field_label( $field );
		}

		$chips = '';
		foreach ( $missing as $label ) {
			$chips .= '<span class="sakura-config-chip">' . esc_html( $label ) . '</span>';
		}

		$body = '<div class="sakura-config-missing"><span class="sakura-config-missing-label">' . esc_html__( '还需要补全', 'sakura-imgopt' ) . '</span><div class="sakura-config-chips">' . $chips . '</div></div>';

		$primary = '<a class="btn btn--primary" data-config-primary href="' . esc_url( $primary_href ) . '">' . esc_html( $primary_label ) . '</a>';

		ob_start();
		self::render_modal(
			'sakura-s3-config-modal',
			'sakura-s3-config-title',
			'sakura-s3-config-desc',
			'OBJECT STORAGE / SETUP',
			__( '还差几步就能启用对象存储', 'sakura-imgopt' ),
			__( '连接信息还不完整。补全后，插件会恢复图片同步、媒体地址接管与云端清理；在补全之前，这些动作会保持暂停。', 'sakura-imgopt' ),
			$body,
			self::actions_markup( __( '暂不处理', 'sakura-imgopt' ), $primary ),
			wp_create_nonce( 'sakura_s3_config_notice' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Dialog that asks for the one full scan the dashboard cannot compute.
	 *
	 * @param Options $options    Storage options.
	 * @param bool    $configured Whether the connection is complete and enabled.
	 * @param bool    $has_scan   Whether a scan row exists at all.
	 * @param bool    $busy       Whether a scan is queued or running right now.
	 * @return string
	 */
	public static function reconcile_html( Options $options, bool $configured, bool $has_scan, bool $busy ): string {
		if ( ! self::should_prompt_reconcile( $options, $configured, $has_scan, $busy ) ) {
			return '';
		}

		$primary = '<button class="btn btn--primary" type="button" data-config-primary data-sakura-action="scan" data-sakura-nonce="' . esc_attr( wp_create_nonce( 'sakura_s3_sync_scan' ) ) . '">' . esc_html__( '开始核对', 'sakura-imgopt' ) . '</button>';

		ob_start();
		self::render_modal(
			'sakura-s3-reconcile-modal',
			'sakura-s3-reconcile-title',
			'sakura-s3-reconcile-desc',
			'RECONCILIATION / REQUIRED',
			__( '还需要一次完整核对', 'sakura-imgopt' ),
			__( '同步中心的数字来自最近一次核对写入的索引。首次核对会验证连接并读取云端清单；之后的页面直接读取本地记录，不再实时访问对象存储。', 'sakura-imgopt' ),
			'',
			self::actions_markup( __( '暂不处理', 'sakura-imgopt' ), $primary ),
			wp_create_nonce( 'sakura_s3_config_notice' ),
			'neutral'
		);
		return (string) ob_get_clean();
	}

	/**
	 * Dialog that explains a complete-but-disabled storage configuration.
	 *
	 * @param Options $options Storage options.
	 * @return string
	 */
	public static function paused_html( Options $options ): string {
		if ( ! self::should_prompt_paused( $options ) ) {
			return '';
		}

		// Only the sync center mounts this dialog. The connection screen holds
		// the switch itself, so a modal there would cover the control the
		// operator was about to use.
		$primary = '<a class="btn btn--primary" data-config-primary href="' . esc_url( Registry::url( 'storage', 'connection' ) ) . '">' . esc_html__( '去启用 S3', 'sakura-imgopt' ) . '</a>';

		ob_start();
		self::render_modal(
			'sakura-s3-paused-modal',
			'sakura-s3-paused-title',
			'sakura-s3-paused-desc',
			'OBJECT STORAGE / PAUSED',
			__( '对象存储已停用', 'sakura-imgopt' ),
			__( '连接信息是完整的，但 S3 存储开关处于关闭状态：新上传的图片不会同步到云端，媒体地址也不会被接管。下面的数字停留在停用之前，重新启用后可以再核对一次。', 'sakura-imgopt' ),
			'',
			self::actions_markup( __( '暂不处理', 'sakura-imgopt' ), $primary ),
			wp_create_nonce( 'sakura_s3_config_notice' ),
			'neutral'
		);
		return (string) ob_get_clean();
	}
}
