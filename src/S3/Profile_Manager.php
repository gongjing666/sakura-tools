<?php
/**
 * Switch the active S3 configuration to a saved provider profile.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switches the top-level active config from a saved profile.
 *
 * Profiles store ciphertext, so switching copies the encrypted values without
 * ever decrypting or rendering them. The method only succeeds when the saved
 * profile is complete and its credentials are decryptable.
 */
final class Profile_Manager {

	/**
	 * Activate a provider profile without touching the S3 switch.
	 *
	 * Kept for callers that only repoint the active profile; the connection
	 * cards use enable_profile()/disable() so one click expresses both halves
	 * of the state.
	 *
	 * @param string $profile_id Profile ID.
	 * @return array{success:bool,error:string}
	 */
	public function switch_profile( string $profile_id ): array {
		return $this->activate( $profile_id, null );
	}

	/**
	 * Activate a provider profile and switch S3 storage on.
	 *
	 * Clicking a saved card is a single operator intent: "use this bucket".
	 * Splitting that into "switch" plus a separate on/off checkbox is how a
	 * half-applied state (URL still rewritten, uploads still paused) appears.
	 *
	 * @param string $profile_id Profile ID.
	 * @return array{success:bool,error:string}
	 */
	public function enable_profile( string $profile_id ): array {
		return $this->activate( $profile_id, true );
	}

	/**
	 * Switch S3 storage off while keeping the active profile selected.
	 *
	 * The profile stays active on purpose: turning the switch back on must not
	 * require re-picking a bucket, and the card keeps its "current" marker so
	 * the operator can see which bucket was paused.
	 *
	 * @return array{success:bool,error:string}
	 */
	public function disable(): array {
		$raw = get_option( Options::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return $this->failure( 'missing' );
		}

		$previous_url   = (string) ( $raw['upload_url_path'] ?? '' );
		$raw['enabled'] = 'false';
		update_option( Options::OPTION, $raw, false );

		Upload_URL::restore( $previous_url );

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Activate a profile, optionally forcing the S3 switch.
	 *
	 * @param string    $profile_id Profile ID.
	 * @param bool|null $enabled    True to switch on, false to switch off, null to leave alone.
	 * @return array{success:bool,error:string}
	 */
	private function activate( string $profile_id, ?bool $enabled ): array {
		$profile_id = sanitize_key( $profile_id );
		$raw        = get_option( Options::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return $this->failure( 'missing' );
		}

		$profiles = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
		$profile  = $profiles[ $profile_id ] ?? null;
		if ( ! is_array( $profile ) ) {
			return $this->failure( 'missing' );
		}
		$provider = isset( $profile['provider'] ) ? sanitize_key( (string) $profile['provider'] ) : '';
		if ( '' === $provider ) {
			return $this->failure( 'incomplete' );
		}

		$candidate = new Options(
			array_merge(
				$profile,
				array(
					'enabled'  => (string) ( $raw['enabled'] ?? 'false' ),
					'provider' => $provider,
				)
			)
		);
		if ( $candidate->credentials_unreadable() || ! $candidate->is_configured() ) {
			return $this->failure( 'incomplete' );
		}

		/*
		 * R10: a high-risk binding (no_local_saving='true') means the attachments
		 * uploaded under it have no local copy left. Every other profile points at a
		 * bucket that never received those objects, so switching to one would take
		 * the media URLs to a domain that answers 404 - the exact dead link R10
		 * exists to prevent. Normal locks stay switchable: the local copies are still
		 * there, so the URL falls back to the site's own domain instead of breaking.
		 *
		 * Re-activating the bound profile itself is not a switch and stays allowed,
		 * which is what makes "turn the card off, turn it back on" work.
		 */
		$binding = ( new Options( $raw ) )->binding();
		if ( self::switch_refused_by_binding( $binding, $profile_id ) ) {
			return $this->failure( 'locked' );
		}

		$previous_url = (string) ( $raw['upload_url_path'] ?? '' );
		$upload_path  = trim( str_replace( '\\', '/', (string) ( $profile['upload_path'] ?? Options::DEFAULT_UPLOAD_PATH ) ), '/' );
		if ( '' === $upload_path ) {
			$upload_path = Options::DEFAULT_UPLOAD_PATH;
		}

		foreach ( array( 'bucket', 'space', 'region', 'endpoint', 'key', 'secret', 'object_acl', 'upload_url_path' ) as $field ) {
			if ( array_key_exists( $field, $profile ) ) {
				$raw[ $field ] = $profile[ $field ];
			}
		}
		$raw['provider']          = $provider;
		$raw['active_profile_id'] = $profile_id;
		$raw['upload_path']       = $upload_path;
		if ( null !== $enabled ) {
			$raw['enabled'] = $enabled ? 'true' : 'false';
		}

		// The site may already declare its own uploads directory. Keys follow that
		// value, so a profile compiled against a different prefix cannot be
		// activated without silently pointing at missing objects.
		$site_path = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', '' ) ), '/' );
		if ( '' !== $site_path && $site_path !== $upload_path ) {
			return $this->failure( 'path_conflict' );
		}

		update_option( Options::OPTION, $raw, false );

		$upload_url = (string) ( $raw['upload_url_path'] ?? '' );
		if ( 'true' === ( $raw['enabled'] ?? 'false' ) && '' !== $upload_url ) {
			Upload_URL::apply( $upload_url );
		} else {
			Upload_URL::restore( $previous_url );
		}

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Delete a saved profile, applying the R7/R10 decision table.
	 *
	 * Deleting the bound profile is the only way to unbind, so this method owns
	 * the whole exit path (R8): the profile row, its reconciliation rows, its
	 * delete queue, its retired-target archive, the binding itself and — when the
	 * profile was the live one — the top-level mirror the URL filters read.
	 *
	 * Two states refuse the delete. A profile that is active *and* serving uploads
	 * is what the site is currently pointing at. A high-risk binding
	 * (`no_local_saving='true'`) additionally requires the profile list to shrink
	 * to just the bound profile first, because the other profiles point at buckets
	 * that never received the attachments whose local copies are already gone.
	 *
	 * @param string $profile_id Profile ID.
	 * @return array{success:bool,error:string}
	 */
	public function delete_profile( string $profile_id ): array {
		$profile_id = sanitize_key( $profile_id );
		$raw        = get_option( Options::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return $this->failure( 'missing' );
		}

		$profiles = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
		if ( ! isset( $profiles[ $profile_id ] ) ) {
			return $this->failure( 'missing' );
		}

		$options = new Options( $raw );
		$binding = $options->binding();
		$is_bound_profile = null !== $binding && (string) ( $binding['profile_id'] ?? '' ) === $profile_id;
		$is_active        = $profile_id === (string) ( $raw['active_profile_id'] ?? '' );
		$is_live          = $is_active && 'true' === (string) ( $raw['enabled'] ?? 'false' );

		// R7: the profile the site is uploading to stays put. Pausing it is the
		// documented first step, so the refusal names that step instead of a dead end.
		if ( $is_live ) {
			return $this->failure( $is_bound_profile ? 'delete_bound' : 'delete_active' );
		}

		// R10: a high-risk lock means the local copies are gone, so no other profile
		// can be a fallback. Clear the list first; only the bound profile may go last.
		if ( $is_bound_profile && 'true' === (string) ( $binding['no_local_saving'] ?? 'false' ) && count( $profiles ) > 1 ) {
			return $this->failure( 'pending_profiles' );
		}

		unset( $profiles[ $profile_id ] );
		$raw['profiles'] = $profiles;

		// R8 step 4: the archive holds a copy of this profile's identity for the
		// delete queue; once the queue is gone the copy has no reader left.
		if ( isset( $raw['retired_targets'] ) && is_array( $raw['retired_targets'] ) ) {
			unset( $raw['retired_targets'][ $profile_id ] );
		}

		if ( $is_bound_profile ) {
			// R8 step 5 / R11: unbinding, and — only when this was the last profile —
			// releasing the behaviour freeze with it.
			$raw['binding'] = array();
			if ( array() === $profiles ) {
				$raw['behaviour_locked'] = 'false';
			}
		}

		$previous_url = (string) ( $raw['upload_url_path'] ?? '' );

		if ( $is_active ) {
			// R8 step 6: nothing is left to point at, so the mirror is cleared and the
			// image domain goes back to the site's own. Leaving the old credentials in
			// the top-level keys would keep a deleted account usable by any code path
			// that reads the mirror without checking `active_profile_id`.
			$raw['active_profile_id'] = '';
			$raw['enabled']           = 'false';
			foreach ( array( 'bucket', 'space', 'region', 'endpoint', 'key', 'secret' ) as $field ) {
				$raw[ $field ] = '';
			}
		}

		update_option( Options::OPTION, $raw, false );

		if ( $is_active ) {
			Upload_URL::restore( $previous_url );
		}

		return array(
			'success' => true,
			'error'   => '',
		);
	}

	/**
	 * Whether the R10 binding refuses this activation.
	 *
	 * A high-risk binding (no_local_saving='true') means the attachments uploaded
	 * under it have no local copy left. Every other profile points at a bucket that
	 * never received those objects, so switching to one would take the media URLs
	 * to a domain that answers 404 - the exact dead link R10 exists to prevent.
	 * Normal locks stay switchable: the local copies are still there, so the URL
	 * falls back to the site's own domain instead of breaking.
	 *
	 * Re-activating the bound profile itself is not a switch and stays allowed,
	 * which is what makes "turn the card off, turn it back on" work.
	 *
	 * @param array<string,mixed>|null $binding  Binding snapshot, or null when unbound.
	 * @param string                   $target_id Profile being switched to.
	 * @return bool
	 */
	public static function switch_refused_by_binding( ?array $binding, string $target_id ): bool {
		if ( null === $binding ) {
			return false;
		}

		if ( 'true' !== (string) ( $binding['no_local_saving'] ?? 'false' ) ) {
			return false;
		}

		return (string) ( $binding['profile_id'] ?? '' ) !== $target_id;
	}

	/**
	 * Failure shape.
	 *
	 * @param string $error Error code.
	 * @return array{success:bool,error:string}
	 */
	private function failure( string $error ): array {
		return array(
			'success' => false,
			'error'   => $error,
		);
	}
}
