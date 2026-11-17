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
	 * Delete a saved profile. The active profile cannot be deleted.
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
		if ( $profile_id === (string) ( $raw['active_profile_id'] ?? '' ) ) {
			return $this->failure( 'active' );
		}

		unset( $profiles[ $profile_id ] );
		$raw['profiles'] = $profiles;
		update_option( Options::OPTION, $raw, false );

		return array(
			'success' => true,
			'error'   => '',
		);
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
