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
 * Owns the capabilities this plugin checks.
 *
 * `sufm_admin_delete_files` is necessary but never sufficient: the executor
 * additionally needs `delete_post` on every attachment it touches. The A7
 * split keeps user/quota management, audit reads and capacity sync on their
 * own capabilities instead of borrowing the delete one.
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
	 * Read the user list and edit per-user quotas (including the reset action).
	 *
	 * Kept apart from DELETE_FILES: seeing every account and rewriting its
	 * capacity is a management action, not a deletion action.
	 */
	public const MANAGE_USERS = 'sufm_manage_users';

	/**
	 * Read the audit trail (capability changes, reviews, deletions).
	 *
	 * Read-only and separate from REVIEW / DELETE_FILES so an operator can
	 * inspect what happened without being able to act again.
	 */
	public const VIEW_AUDIT = 'sufm_view_audit';

	/**
	 * Start and inspect the capacity sync / reconcile job.
	 *
	 * Recomputes the ledger from the database; it neither deletes an attachment
	 * nor reviews a request.
	 */
	public const MANAGE_SYNC = 'sufm_manage_sync';

	/**
	 * The two user-facing capabilities granted to every role.
	 *
	 * @return array<int, string>
	 */
	public static function user_caps(): array {
		return array( self::MANAGE_OWN, self::REQUEST_DELETE );
	}

	/**
	 * The administrator defaults; the matrix is the only way other roles get them.
	 *
	 * @return array<int, string>
	 */
	public static function admin_caps(): array {
		return array( self::REVIEW, self::DELETE_FILES, self::MANAGE_USERS, self::VIEW_AUDIT, self::MANAGE_SYNC, self::MANAGE_MEDIA, self::TRANSFER, self::SANCTION_USERS );
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
	 * Called on deactivation and uninstall. Removing every one (not just the two
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
		delete_option( self::CAP_SPLIT_OPTION );
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
	 * Option remembering that the A7 management caps reached the administrator.
	 */
	public const CAP_SPLIT_OPTION = 'sufm_cap_split_version';

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
	 * Grant the A7 management capabilities to the administrator once.
	 *
	 * `grant_defaults()` is already a no-op on existing sites, so a capability
	 * added later has to travel through the same one-shot upgrade path as the
	 * sanction and media capabilities. This is the plugin's documented admin
	 * default, not a copy of DELETE_FILES: only the `administrator` role is
	 * touched. Every other role receives the new capabilities solely through the
	 * capability matrix, so a role that only has DELETE_FILES keeps just that.
	 */
	public static function upgrade_capability_split(): void {
		if ( '1' === (string) get_option( self::CAP_SPLIT_OPTION, '' ) ) {
			return;
		}

		$role = get_role( 'administrator' );

		if ( $role ) {
			$role->add_cap( self::MANAGE_USERS );
			$role->add_cap( self::VIEW_AUDIT );
			$role->add_cap( self::MANAGE_SYNC );
		}

		update_option( self::CAP_SPLIT_OPTION, '1', false );
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

	/**
	 * User list and per-user quota management.
	 *
	 * @return bool
	 */
	public static function can_manage_users(): bool {
		return current_user_can( self::MANAGE_USERS );
	}

	/**
	 * Audit-log read capability.
	 *
	 * @return bool
	 */
	public static function can_view_audit(): bool {
		return current_user_can( self::VIEW_AUDIT );
	}

	/**
	 * Capacity sync / reconcile capability.
	 *
	 * @return bool
	 */
	public static function can_manage_sync(): bool {
		return current_user_can( self::MANAGE_SYNC );
	}
}
