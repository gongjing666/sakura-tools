<?php
/**
 * Capability matrix card: who may delete directly and who may only request.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Capabilities;
use Nboke\UserFiles\Repositories\Events;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * The card edits the six capabilities this plugin owns.
 *
 * WordPress' own `delete_posts` / `delete_others_posts` are deliberately out of
 * scope: they are shared with posts, pages and comments, so flipping them from
 * a media card would change far more than the file space. The matrix shows what
 * the plugin configured and what the role actually resolves to, because another
 * plugin (or a `user_has_cap` filter) can grant or deny the same capability.
 */
final class Capability_Matrix {

	/**
	 * POST action name.
	 */
	public const ACTION = 'sufm_save_caps';

	/**
	 * Nonce action.
	 */
	public const NONCE = 'sufm_save_caps';

	/**
	 * How many role:±cap entries the audit row carries.
	 */
	private const AUDIT_LIMIT = 20;

	/**
	 * The capability labels shown as columns, in the order operators think
	 * about them: use the space, ask for a delete, review it, delete outright,
	 * then the media-library extras.
	 *
	 * @return array<string, array{abbr:string,label:string,hint:string}>
	 */
	public static function caps(): array {
		return array(
			Capabilities::MANAGE_OWN     => array(
				'abbr'  => __( '管', 'sakura-imgopt' ),
				'label' => __( '管理自己的文件', 'sakura-imgopt' ),
				'hint'  => __( '上传、下载、重命名、移动与建文件夹。', 'sakura-imgopt' ),
			),
			Capabilities::REQUEST_DELETE => array(
				'abbr'  => __( '申', 'sakura-imgopt' ),
				'label' => __( '申请删除', 'sakura-imgopt' ),
				'hint'  => __( '提交删除申请、撤回待审申请。', 'sakura-imgopt' ),
			),
			Capabilities::REVIEW         => array(
				'abbr'  => __( '审', 'sakura-imgopt' ),
				'label' => __( '审核申请', 'sakura-imgopt' ),
				'hint'  => __( '批准、驳回、撤销申请，并查看申请详情。', 'sakura-imgopt' ),
			),
			Capabilities::DELETE_FILES   => array(
				'abbr'  => __( '删', 'sakura-imgopt' ),
				'label' => __( '直接删除文件', 'sakura-imgopt' ),
				'hint'  => __( '跳过申请直接删除；仍要 WordPress 的 delete_post 权限，且只能删自己有权删的附件。', 'sakura-imgopt' ),
			),
			Capabilities::MANAGE_MEDIA   => array(
				'abbr'  => __( '媒', 'sakura-imgopt' ),
				'label' => __( '媒体库文件夹', 'sakura-imgopt' ),
				'hint'  => __( '在媒体库整理多用户文件夹。', 'sakura-imgopt' ),
			),
			Capabilities::TRANSFER       => array(
				'abbr'  => __( '移', 'sakura-imgopt' ),
				'label' => __( '转移归属', 'sakura-imgopt' ),
				'hint'  => __( '把媒体库文件转给另一个用户。', 'sakura-imgopt' ),
			),
			Capabilities::SANCTION_USERS => array(
				'abbr'  => __( '罚', 'sakura-imgopt' ),
				'label' => __( '处罚用户', 'sakura-imgopt' ),
				'hint'  => __( '对违规上传者发出警告、限制上传、撤销处罚。删文件不等于能罚人，这两项刻意分开。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Role slugs in reading order: the five built-ins first, then any custom
	 * role the site added, so the matrix stays predictable.
	 *
	 * @return array<int, string>
	 */
	public static function roles(): array {
		$all       = (array) wp_roles()->roles;
		$preferred = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		$roles     = array();

		foreach ( $preferred as $name ) {
			if ( isset( $all[ $name ] ) ) {
				$roles[] = $name;
			}
		}

		foreach ( array_keys( $all ) as $name ) {
			$name = (string) $name;

			if ( ! in_array( $name, $preferred, true ) ) {
				$roles[] = $name;
			}
		}

		return $roles;
	}

	/**
	 * Human label for a role slug.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function role_label( string $role ): string {
		$names = self::role_names();

		if ( isset( $names[ $role ] ) ) {
			return $names[ $role ];
		}

		$all   = (array) wp_roles()->roles;
		$label = isset( $all[ $role ]['name'] ) ? (string) $all[ $role ]['name'] : '';

		return '' !== $label ? $label : $role;
	}

	/**
	 * Chinese names for the five roles WordPress ships with.
	 *
	 * The matrix prints the English slug next to it: administrators recognise
	 * "Editor" from other plugins, but the person operating the site may not.
	 * Custom roles keep their own name (they are site-specific anyway).
	 *
	 * @return array<string, string>
	 */
	public static function role_names(): array {
		return array(
			'administrator' => __( '管理员', 'sakura-imgopt' ),
			'editor'        => __( '编辑', 'sakura-imgopt' ),
			'author'        => __( '作者', 'sakura-imgopt' ),
			'contributor'   => __( '投稿者', 'sakura-imgopt' ),
			'subscriber'    => __( '订阅者', 'sakura-imgopt' ),
		);
	}

	/**
	 * What the plugin stored on the role (raw capability map).
	 *
	 * @param string $role Role slug.
	 * @param string $cap  Capability.
	 * @return bool
	 */
	public static function configured( string $role, string $cap ): bool {
		$object = get_role( $role );

		if ( ! $object ) {
			return false;
		}

		$caps = (array) $object->capabilities;

		return ! empty( $caps[ $cap ] );
	}

	/**
	 * What the role actually resolves to right now (`has_cap`, filtered).
	 *
	 * @param string $role Role slug.
	 * @param string $cap  Capability.
	 * @return bool
	 */
	public static function effective( string $role, string $cap ): bool {
		$object = get_role( $role );

		return $object ? (bool) $object->has_cap( $cap ) : false;
	}

	/**
	 * Whether a cell is pinned on.
	 *
	 * The administrator's direct-delete capability is not editable: a matrix
	 * save with a stale form must never be able to lock the operator out of the
	 * one action the page exists to make possible.
	 *
	 * @param string $role Role slug.
	 * @param string $cap  Capability.
	 * @return bool
	 */
	public static function locked( string $role, string $cap ): bool {
		return 'administrator' === $role && Capabilities::DELETE_FILES === $cap;
	}

	/**
	 * Whether the role can delete directly but only on its own attachments.
	 *
	 * `sufm_admin_delete_files` is necessary but never sufficient: WordPress
	 * still asks `delete_others_posts` for somebody else's attachment, so a role
	 * missing it can only delete what it uploaded. The matrix marks those rows
	 * so the scope is not read as "can delete anything".
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function limited( string $role ): bool {
		return self::effective( $role, Capabilities::DELETE_FILES )
			&& ! self::effective( $role, 'delete_others_posts' );
	}

	/**
	 * Value a submitted cell resolves to.
	 *
	 * @param string               $role   Role slug.
	 * @param string               $cap    Capability.
	 * @param array<string, mixed> $posted Submitted matrix, role => cap => value.
	 * @return bool
	 */
	public static function desired( string $role, string $cap, array $posted ): bool {
		if ( self::locked( $role, $cap ) ) {
			return true;
		}

		$row = isset( $posted[ $role ] ) && is_array( $posted[ $role ] ) ? $posted[ $role ] : array();

		return ! empty( $row[ $cap ] );
	}

	/**
	 * Register the save handler.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 * @return void
	 */
	public static function register( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'save' ) );
	}

	/**
	 * Apply the submitted matrix, audit the diff and go back to the card.
	 *
	 * @return void
	 */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Capabilities::can_review() ) {
			wp_die( esc_html__( '你没有权限修改文件空间的删除权限。', 'sakura-imgopt' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only used as a boolean lookup below.
		$posted = isset( $_POST['sufm_caps'] ) ? (array) wp_unslash( $_POST['sufm_caps'] ) : array();
		$diff   = array();

		foreach ( self::roles() as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( array_keys( self::caps() ) as $cap ) {
				$wanted = self::desired( $role_name, $cap, $posted );

				if ( $wanted === self::configured( $role_name, $cap ) ) {
					continue;
				}

				if ( $wanted ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}

				$diff[] = $role_name . ( $wanted ? ':+' : ':-' ) . $cap;
			}
		}

		Capabilities::mark_managed();

		if ( $diff ) {
			( new Events() )->log(
				array(
					'actor_id'    => get_current_user_id(),
					'action'      => 'cap_change',
					'result'      => 'ok',
					'safe_detail' => implode( ',', array_slice( $diff, 0, self::AUDIT_LIMIT ) ),
				)
			);
		}

		$target = wp_validate_redirect( (string) wp_get_referer(), Registry::url( 'files', 'queue' ) );

		wp_safe_redirect( add_query_arg( 'sufm_caps', $diff ? 'saved' : 'same', $target ) );
		exit;
	}
}
