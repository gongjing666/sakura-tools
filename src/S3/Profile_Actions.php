<?php
/**
 * Card actions for the storage screen, callable from admin-post, admin-ajax
 * and the REST save path.
 *
 * The same three clicks (enable / disable / delete) used to live inside the
 * admin-ajax handlers, which answered with wp_send_json_success() and exited.
 * The React client posts the same intents to REST, so the decision logic moved
 * here and both transports wrap it. Anything that decides what a bucket switch
 * or a delete means belongs in this file, not in a controller.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Sync\Index_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Toggle and delete saved storage profiles.
 */
final class Profile_Actions {

	/**
	 * Turn storage on, off, or move it to another saved profile.
	 *
	 * @param string $profile_id Profile to enable; ignored when intent is off.
	 * @param string $intent     'on' or 'off'.
	 * @return array{ok:bool,code:string,message:string,state:array<string,mixed>,switch:array<string,mixed>|null}
	 */
	public static function toggle( string $profile_id, string $intent ): array {
		$manager = new Profile_Manager();

		if ( 'off' === $intent ) {
			$result = $manager->disable();
		} elseif ( '' === $profile_id ) {
			return self::failure( 'missing', __( '没有选中任何配置。', 'sakura-imgopt' ) );
		} else {
			$result = $manager->enable_profile( $profile_id );
		}

		if ( empty( $result['success'] ) ) {
			$code = (string) ( $result['error'] ?? '' );

			return self::failure( $code, self::toggle_error_message( $code ) );
		}

		$state  = S3_Storage_Page::state_payload();
		$switch = null;

		// A switch is the one click that invalidates work the operator already
		// did: the thumbnail rewrite is turned off and the sync center now shows
		// a bucket nobody has scanned. The card's toast sits far from the
		// controls that changed, so the same click also returns a dialog the
		// client opens in place of the toast.
		if ( ! empty( $result['moved'] ) ) {
			$state['switched']     = true;
			$state['previousName'] = (string) ( $result['from'] ?? '' );
			$switch            = S3_Storage_Page::switch_notice(
				(string) ( $result['from'] ?? '' ),
				(string) ( $state['activeName'] ?? '' ),
				! empty( $state['behaviourFrozen'] )
			);
		}

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
			'state'   => $state,
			'switch'  => $switch,
		);
	}

	/**
	 * Delete one saved profile and the rows partitioned by its id.
	 *
	 * @param string $profile_id Profile id.
	 * @return array{ok:bool,code:string,message:string}
	 */
	public static function delete( string $profile_id ): array {
		$options = new Options();
		$manager = new Profile_Manager();

		// Capture the bucket before the row is gone: the index is partitioned by
		// bucket (R19.7) and a deleted profile can no longer name its own.
		$repository     = new Index_Repository();
		$profile_row    = $options->profile( $profile_id );
		$profile_bucket = array() !== $profile_row ? Bucket_Identity::from_profile( $profile_row, $options )->hash() : '';
		$result         = $manager->delete_profile( $profile_id );

		if ( empty( $result['success'] ) ) {
			$code = (string) ( $result['error'] ?? '' );
			$code = in_array( $code, array( 'delete_active', 'delete_bound', 'pending_profiles', 'missing' ), true ) ? $code : 'delete';

			return self::failure( $code, self::delete_error_message( $code ) );
		}

		// R8 steps 2-3 (07-S3存储生命周期): scans, events and the delete queue are
		// partitioned by the profile id, so dropping the profile without its rows
		// would leave that history — and a queue that retries forever against an
		// adapter that no longer exists — with no screen able to show or clear it.
		// The index rows are partitioned by bucket instead (R19.7) and stay put
		// while another configuration row still names the same bucket.
		$repository->purge_index( $profile_id, $profile_bucket );
		( new Delete_Jobs() )->forget_profile( $profile_id );

		return array(
			'ok'      => true,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * Operator-facing text for a failed card toggle.
	 *
	 * @param string $error Error code from Profile_Manager.
	 */
	public static function toggle_error_message( string $error ): string {
		if ( 'incomplete' === $error ) {
			return __( '这份配置不完整或密钥无法读取，补全后再试。', 'sakura-imgopt' );
		}
		if ( 'path_conflict' === $error ) {
			return __( '站点已指定自己的上传目录，这份配置的云端路径与它不一致，无法启用。', 'sakura-imgopt' );
		}
		if ( 'locked' === $error ) {
			// R10: a high-risk binding deleted the local copies, so the other
			// profiles point at buckets that never received these attachments.
			// Switching to one is a guaranteed dead link, and the only exit is the
			// documented teardown.
			return __( '当前处于高风险锁定：本地副本已删除，只有这份配置对应的存储桶里有这些文件，切到别的配置会变成死链。如需更换存储服务商或存储桶，请先点当前配置卡片关闭 S3 存储，删除其余全部存储配置，再删除当前配置。', 'sakura-imgopt' );
		}

		return __( '没有找到这份配置，可能已被删除，请刷新页面。', 'sakura-imgopt' );
	}

	/**
	 * Operator-facing text for a refused delete.
	 *
	 * @param string $code Error code from Profile_Manager.
	 */
	public static function delete_error_message( string $code ): string {
		$messages = array(
			'delete_active'    => __( '当前使用中的配置不能删除。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
			'delete_bound'     => __( '当前使用中的配置不能删除，而且它还是绑定的那一份。如需更换存储桶，请先点这张卡片关闭 S3 存储。', 'sakura-imgopt' ),
			'pending_profiles' => __( '高风险锁定下不能保留其它配置。请先删除其余配置（它们指向的桶里没有这些附件），再删除当前配置。', 'sakura-imgopt' ),
		);

		return $messages[ $code ] ?? __( '无法删除该配置，请刷新页面后重试。', 'sakura-imgopt' );
	}

	/**
	 * Uniform failure shape.
	 *
	 * @param string $code    Machine-readable reason.
	 * @param string $message Operator-facing text.
	 * @return array{ok:bool,code:string,message:string,state:array<string,mixed>,switch:null}
	 */
	private static function failure( string $code, string $message ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'state'   => array(),
			'switch'  => null,
		);
	}
}
