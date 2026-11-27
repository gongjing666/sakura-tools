<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Storage_Payload;
use Sakura\ImageOptimizer\Admin\UI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the S3 settings screen and read-only redirect notices. */
final class S3_Storage_Page {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$payload = ( new Storage_Payload( $this->options ) )->build();
		require SAKURA_TOOLS_PATH . 'includes/views/storage-island.php';
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
	public static function lost_local_for( string $bound_id, bool $risk_locked ): int {
		if ( ! $risk_locked || '' === $bound_id ) {
			return 0;
		}

		$profile = ( new Options() )->profile_options( $bound_id );
		if ( null === $profile || array() === $profile ) {
			return 0;
		}

		return ( new Sync_Status() )->lost_local_count( new Options( $profile ) );
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
	public static function delete_refusal( string $profile_id, bool $is_active, bool $enabled, string $bound_id, bool $risk_locked, int $profile_count ): string {
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
	public static function delete_confirm( int $count, bool $is_unbind, bool $risk_locked, string $name = '' ): array {
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
	 * The copy lives here as data because two clients draw it: the PHP renderer
	 * builds markup for the legacy script, and the React island renders its own
	 * dialog from the same words.
	 *
	 * @param string $from   Previous profile name.
	 * @param string $to     New profile name.
	 * @param bool   $frozen Whether the behaviour switches are frozen.
	 * @return array{tone:string,kicker:string,title:string,message:string,items:string[],button:string}
	 */
	public static function switch_notice( string $from, string $to, bool $frozen ): array {
		$items = array();

		$items[] = __( '缩略图地址改写已自动关闭：图片域名变了，缓存的缩略图地址也已作废。到「缩略图优化」核对图片域名后重新开启。', 'sakura-imgopt' );
		$items[] = __( '同步中心的数据还是上一个桶的：下载、上传、删除与刷新差异已暂停，点「开始完整核对」重建索引后自动恢复。', 'sakura-imgopt' );

		if ( $frozen ) {
			$items[] = __( '「上传行为」两个开关仍按原来的绑定生效，新的改动要等解绑之后。', 'sakura-imgopt' );
		}

		$to_label = '' !== $to ? $to : __( '新配置', 'sakura-imgopt' );
		$title    = '' !== $from
			? sprintf( /* translators: 1: previous profile name, 2: new profile name. */ __( '已从「%1$s」切换到「%2$s」', 'sakura-imgopt' ), $from, $to_label )
			: sprintf( /* translators: %s: new profile name. */ __( '已切换到「%s」', 'sakura-imgopt' ), $to_label );

		return array(
			'tone'    => 'warn',
			'kicker'  => 'OBJECT STORAGE / SWITCHED',
			'title'   => $title,
			'message' => __( '这次点击换了存储桶，有两处需要你重新确认：', 'sakura-imgopt' ),
			'items'   => $items,
			'button'  => __( '知道了，去同步中心核对', 'sakura-imgopt' ),
		);
	}

	/**
	 * Markup form of switch_notice() for the legacy (non-island) renderer.
	 *
	 * @param string $from   Previous profile name.
	 * @param string $to     New profile name.
	 * @param bool   $frozen Whether the behaviour switches are frozen.
	 */
	public static function switch_modal_html( string $from, string $to, bool $frozen ): string {
		$notice = self::switch_notice( $from, $to, $frozen );

		$body = '<ul class="dialog__list">';
		foreach ( $notice['items'] as $item ) {
			$body .= '<li>' . esc_html( $item ) . '</li>';
		}
		$body .= '</ul>';

		return UI::modal_html(
			array(
				'id'        => 'sakura-s3-switch-modal',
				'return_to' => '#sakura-s3-save-form [data-sakura-action="toggle-profile"]',
				'tone'      => $notice['tone'],
				'kicker'    => $notice['kicker'],
				'title'     => $notice['title'],
				'message'   => $notice['message'],
				'body'      => $body,
				'button'    => $notice['button'],
			)
		);
	}
	/**
	 * Operator-facing text for a refused save.
	 *
	 * Shared by the redirect notice (?saved=0&error=code) and the REST save
	 * path, so a refusal reads the same whichever transport carried it.
	 *
	 * @param string $code Error code from Settings_Saver::apply().
	 */
	public static function error_message( string $code ): string {
		$errors = array(
			'bucket'           => __( '还没有填存储桶名称。这个表单是用来新增一份配置的，想修改已保存的配置，请点上面的配置小卡片。', 'sakura-imgopt' ),
			'endpoint'         => __( '「服务地址」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
			'url'              => __( '「图片地址前缀」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
			'path'             => __( '「云端路径前缀」不能包含「..」这类上级目录写法。', 'sakura-imgopt' ),
			'config'           => __( '启用前必须填写存储桶名称、Access Key、Secret 和服务地址（使用 AWS 官方服务时地址可留空）。', 'sakura-imgopt' ),
			'crypto'           => __( '服务器缺少加密组件，无法安全保存密钥，请联系主机商开启。', 'sakura-imgopt' ),
			'delete'           => __( '无法删除该配置，请刷新页面后重试。', 'sakura-imgopt' ),
			'delete_active'    => __( '当前使用中的配置不能删除。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
			'delete_bound'     => __( '当前使用中的配置不能删除，而且它还是绑定的那一份。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
			'pending_profiles' => __( '高风险锁定下不能保留其它配置。请先删除其余配置（它们指向的桶里没有这些附件），再删除当前配置。', 'sakura-imgopt' ),
			'locked'           => __( '当前处于高风险锁定：本地副本已删除，只有这份配置对应的存储桶里有这些文件，切到别的配置会变成死链。如需更换服务商或存储桶，请走「关闭 S3 → 删除其余全部配置 → 删除当前配置」。', 'sakura-imgopt' ),
			'risk_ack'         => __( '「上传成功后删除本地文件」是高风险选项，需要先确认风险再保存。', 'sakura-imgopt' ),
		);

		return $errors[ $code ] ?? __( '设置未保存。', 'sakura-imgopt' );
	}

	/**
	 * Read-only redirect notice, mapped from the query string.
	 *
	 * Public and static because the React bootstrap in Storage_Payload needs
	 * the same words after a native POST redirect as the PHP renderer does.
	 *
	 * @return array{message:string,tone:string}
	 */
	public static function notice_payload(): array {
		$success = array(
			'message' => '',
			'tone'    => 'ok',
		);

		if ( isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			$success['message'] = __( '已删除保存的配置。', 'sakura-imgopt' );
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

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect error code; it does not change state.
			$key = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

			return array(
				'message' => self::error_message( $key ),
				'tone'    => 'bad',
			);
		}
		return $success;
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\S3_Storage_Page', __NAMESPACE__ . '\\Settings_Page' );
