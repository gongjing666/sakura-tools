<?php
/**
 * Capability definitions for the user file space module.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the four capabilities from SECURITY-DELETE.md §1.
 *
 * `sufm_admin_delete_files` is necessary but never sufficient: the executor
 * additionally needs `delete_post` on every attachment it touches.
 */
final class Capabilities {

	/**
	 * Upload, read/download, rename or move unlocked files, create folders.
	 */
	public const MANAGE_OWN = 'sufm_manage_own_files';

	/**
	 * Request deletion of own targets, list own requests, withdraw while pending.
	 */
	public const REQUEST_DELETE = 'sufm_request_delete_own_files';

	/**
	 * Review (approve/reject/revoke) delete requests.
	 */
	public const REVIEW = 'sufm_admin_review_deletions';

	/**
	 * Execute approved batches after an independent confirmation.
	 */
	public const MANAGE_MEDIA = 'sufm_manage_media_folders';
	public const TRANSFER = 'sufm_transfer_media_files';

	public const DELETE_FILES = 'sufm_admin_delete_files';

	/**
	 * Decide that an uploader is sanctioned (warn, block uploads, block login).
	 *
	 * Kept apart from DELETE_FILES on purpose: removing a file and judging the
	 * person who uploaded it are different risks, so an operator can be trusted
	 * with one and not the other.
	 */
	public const SANCTION_USERS = 'sufm_admin_sanction_users';

	/**
	 * The two user-facing capabilities granted to every role.
	 *
	 * @return array<int, string>
	 */
	public static function user_caps(): array {
		return array( self::MANAGE_OWN, self::REQUEST_DELETE );
	}

	/**
	 * The two administrator capabilities.
	 *
	 * @return array<int, string>
	 */
	public static function admin_caps(): array {
		return array( self::REVIEW, self::DELETE_FILES, self::MANAGE_MEDIA, self::TRANSFER, self::SANCTION_USERS );
	}

	/**
	 * Grant capabilities. Called from activation; idempotent.
	 */
	public static function grant(): void {
		$user_roles = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		foreach ( $user_roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( self::user_caps() as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::admin_caps() as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$admin->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Remove every capability this plugin granted, from every role.
	 *
	 * Called on deactivation and uninstall. Removing all six (not just the two
	 * media ones) keeps the role table honest: a site that deactivates the
	 * plugin should not keep advertising capabilities that nothing checks.
	 */
	public static function revoke(): void {
		$all = array_merge( self::user_caps(), self::admin_caps() );

		foreach ( wp_roles()->roles as $role_name => $role_data ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( $all as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		delete_option( 'sufm_media_caps_version' );
		delete_option( self::SEED_OPTION );
	}

	/**
	 * Option remembering that the default grants already ran once.
	 *
	 * `grant()` cannot run on every request: since P4 the operator can take a
	 * capability away on the matrix card, and a per-request grant would silently
	 * put it back on the next page load.
	 */
	public const SEED_OPTION = 'sufm_caps_seeded';

	/**
	 * Grant the defaults once per installation.
	 *
	 * The matrix card (P4) is the authority afterwards: it calls
	 * `mark_managed()` on every save, and this method becomes a no-op.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		if ( '1' === (string) get_option( self::SEED_OPTION, '' ) ) {
			return;
		}

		self::grant();
		update_option( self::SEED_OPTION, '1', false );
	}

	/**
	 * Remember that a human edited the matrix, so defaults must not come back.
	 *
	 * @return void
	 */
	public static function mark_managed(): void {
		update_option( self::SEED_OPTION, '1', false );
	}

	/**
	 * Grant the sanction capability once, on sites that predate it.
	 *
	 * Runs on the same admin_init pass as the schema upgrade. `grant_defaults()`
	 * cannot carry this one: on an existing site it is already a no-op because
	 * the operator has edited the matrix, so a capability added later would
	 * never reach the administrator role.
	 */
	public static function upgrade_sanction_caps(): void {
		if ( '1' === (string) get_option( 'sufm_sanction_caps_version', '' ) ) {
			return;
		}

		$role = get_role( 'administrator' );

		if ( $role ) {
			$role->add_cap( self::SANCTION_USERS );
		}

		update_option( 'sufm_sanction_caps_version', '1', false );
	}

	/** Grant only the newly introduced caps once during upgrade, not on each request. */
	public static function upgrade_media_caps(): void {
		if ( '1' === (string) get_option( 'sufm_media_caps_version', '' ) ) { return; }
		$role = get_role( 'administrator' );
		if ( ! $role ) { return; }
		$role->add_cap( self::MANAGE_MEDIA ); $role->add_cap( self::TRANSFER );
		update_option( 'sufm_media_caps_version', '1', false );
	}

	/**
	 * Current user may read and manage their own space.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function can_manage_own( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::MANAGE_OWN );
	}

	/**
	 * Reviewer capability.
	 *
	 * @return bool
	 */
	public static function can_review(): bool {
		return current_user_can( self::REVIEW );
	}

	/**
	 * Executor capability; every attachment still needs `delete_post`.
	 *
	 * @return bool
	 */
	public static function can_execute(): bool {
		return current_user_can( self::DELETE_FILES );
	}
}
