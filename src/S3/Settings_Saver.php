<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\S3_Storage_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Handles the admin-post save action for S3 settings. */
final class Settings_Saver {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Normalize the site-wide upload concurrency (1-4, default 1).
	 *
	 * @param mixed $raw Submitted value.
	 */
	public static function sanitize_upload_concurrency( $raw ): int {
		$value = is_numeric( $raw ) ? (int) $raw : 1;
		return max( 1, min( \Sakura\ImageOptimizer\Settings\Settings_Repository::MAX_UPLOAD_CONCURRENCY, $value ) );
	}

	/**
	 * Pick the profile a save writes to, minting a new id when the target moved.
	 *
	 * A saved profile *is* a target. Editing one in place to point at a
	 * different provider, bucket or endpoint made the sync center — which reads
	 * by profile id — print the previous bucket's counts under the new name, and
	 * left queued delete jobs aimed at objects the entry no longer claims. A new
	 * id keeps both profiles and their records separate, so deleting a config
	 * later is the only thing that can discard its reconciliation data.
	 *
	 * @param array<string,array<string,mixed>> $profiles     Saved profiles keyed by id.
	 * @param string                            $submitted_id Profile the form was editing ('' for none).
	 * @param bool                              $save_as_new  Whether the operator asked for a copy.
	 * @param string                            $provider     Submitted provider.
	 * @param string                            $bucket       Submitted bucket.
	 * @param string                            $endpoint     Submitted endpoint ('' when empty).
	 */
	public static function profile_id_for_save( array $profiles, string $submitted_id, bool $save_as_new, string $provider, string $bucket, string $endpoint ): string {
		if ( $save_as_new || '' === $submitted_id || ! isset( $profiles[ $submitted_id ] ) ) {
			return Options::new_profile_id();
		}
		$existing = $profiles[ $submitted_id ];
		$moved    = (string) ( $existing['provider'] ?? '' ) !== $provider
			|| (string) ( $existing['bucket'] ?? '' ) !== $bucket
			|| (string) ( $existing['endpoint'] ?? '' ) !== $endpoint;
		return $moved ? Options::new_profile_id() : $submitted_id;
	}

	/**
	 * Normalize an access key or secret without rewriting its characters.
	 *
	 * `sanitize_text_field()` strips `%`, `<` and tags, which silently corrupts
	 * some providers' keys and makes authentication failures hard to diagnose.
	 * Credentials are only ever echoed through `esc_attr()`/`esc_html()`.
	 *
	 * @param string $value Raw posted value.
	 */
	public static function clean_credential( string $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return trim( (string) $value );
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_save' );

		$card = Card_Form::current_card();
		if ( 'behaviour' === $card ) {
			$this->save_behaviour();
			return;
		}
		$connection_only = 'connection' === $card;

		$old       = $this->options->all();
		$raw       = get_option( Options::OPTION, array() );
		$raw       = is_array( $raw ) ? $raw : array();
		/** @var array<string,array<string,mixed>> $profiles */
		$profiles  = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
		$providers = Providers::all();
		$provider  = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'cloudflare';
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = 'cloudflare';
		}
		$provider_changed = $provider !== (string) ( $old['provider'] ?? '' );
		$submitted_id     = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$save_as_new      = ! empty( $_POST['save_as_new'] );
		$profile_name     = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ) : '';
		$bucket_raw       = isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '';
		$endpoint_raw     = isset( $_POST['endpoint'] ) ? trim( wp_unslash( $_POST['endpoint'] ) ) : '';
		$endpoint         = '' === $endpoint_raw ? '' : esc_url_raw( $endpoint_raw, array( 'http', 'https' ) );
		if ( '' !== $endpoint_raw && '' === $endpoint ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'endpoint' ) );
		}
		$profile_id       = self::profile_id_for_save( $profiles, $submitted_id, $save_as_new, $provider, $bucket_raw, $endpoint );
		$url_raw    = isset( $_POST['upload_url_path'] ) ? trim( wp_unslash( $_POST['upload_url_path'] ) ) : '';
		$upload_url = '' === $url_raw ? '' : esc_url_raw( untrailingslashit( $url_raw ), array( 'http', 'https' ) );
		if ( '' !== $url_raw && '' === $upload_url ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'url' ) );
		}
		$key         = isset( $_POST['key'] ) ? self::clean_credential( (string) wp_unslash( $_POST['key'] ) ) : '';
		$secret      = isset( $_POST['secret'] ) ? self::clean_credential( (string) wp_unslash( $_POST['secret'] ) ) : '';
		if ( $save_as_new && ! $provider_changed ) {
			// Duplicate the current config: blank fields keep the active credentials.
			if ( '' === $key ) {
				$key = (string) ( $old['key'] ?? '' );
			}
			if ( '' === $secret ) {
				$secret = (string) ( $old['secret'] ?? '' );
			}
		}
		$options     = array(
			'enabled'         => isset( $_POST['enabled'] ) ? 'true' : 'false',
			'provider'        => $provider,
			'bucket'          => $bucket_raw,
			'region'          => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1',
			'endpoint'        => $endpoint,
			'key'             => '' !== $key ? $key : ( $provider_changed ? '' : (string) ( $old['key'] ?? '' ) ),
			'secret'          => '' !== $secret ? $secret : ( $provider_changed ? '' : (string) ( $old['secret'] ?? '' ) ),
			'no_thumb'        => $connection_only ? (string) ( $old['no_thumb'] ?? 'false' ) : ( isset( $_POST['no_thumb'] ) ? 'true' : 'false' ),
			'no_local_saving' => $connection_only ? (string) ( $old['no_local_saving'] ?? 'false' ) : ( isset( $_POST['no_local_saving'] ) ? 'true' : 'false' ),
			'upload_url_path' => $upload_url,
			'object_acl'      => isset( $_POST['object_acl'] ) && in_array( wp_unslash( $_POST['object_acl'] ), array( 'public-read', 'private' ), true ) ? wp_unslash( $_POST['object_acl'] ) : 'public-read',
		);
		if ( ! $connection_only ) {
			$upload_concurrency = self::sanitize_upload_concurrency( isset( $_POST['upload_concurrency'] ) ? wp_unslash( $_POST['upload_concurrency'] ) : 1 );
			update_option( 'sakura_imgopt_upload_concurrency', $upload_concurrency, false );
		}

		$upload_path = isset( $_POST['upload_path'] ) ? trim( str_replace( '\\', '/', sanitize_text_field( wp_unslash( $_POST['upload_path'] ) ) ), '/' ) : Options::DEFAULT_UPLOAD_PATH;
		if ( '' === $upload_path ) {
			$upload_path = Options::DEFAULT_UPLOAD_PATH;
		}
		if ( '.' !== $upload_path && ( preg_match( '#(^|/)\.\.(/|$)#', $upload_path ) || false !== strpos( $upload_path, "\0" ) ) ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'path' ) );
		}

		// Never overwrite WordPress' own upload_path option: when the site already
		// declares an uploads directory, keys keep following it (see §17.6).
		$site_path = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', '' ) ), '/' );
		if ( '' !== $site_path ) {
			$upload_path = $site_path;
		}

		$options['upload_path'] = $upload_path;

		$candidate = new Options( $options );
		if ( 'true' === $options['enabled'] && ! $candidate->is_configured() ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'config' ) );
		}

		/** @var array<string,mixed> $stored */
		$stored = $options;
		foreach ( array( 'key', 'secret' ) as $field ) {
			if ( '' === (string) $stored[ $field ] ) {
				continue;
			}
			$encrypted = Credential_Cipher::encrypt( (string) $stored[ $field ] );
			if ( false === $encrypted ) {
				$this->redirect( array( 'saved' => '0', 'error' => 'crypto' ) );
			}
			$stored[ $field ] = $encrypted;
		}

		// Audit A03: a profile is edited in place when its provider/bucket/endpoint
		// changes, which strands delete jobs already queued against the old target.
		// Keep the previous configuration keyed by its fingerprint so the worker can
		// still remove those objects instead of marking the jobs dead.
		if ( isset( $profiles[ $profile_id ] ) && is_array( $profiles[ $profile_id ] ) ) {
			$previous     = $profiles[ $profile_id ];
			$previous_key = Delete_Jobs::target_hash( $previous );
			$next_key     = Delete_Jobs::target_hash( $stored );
			if ( '' !== $previous_key && ! hash_equals( $previous_key, $next_key ) ) {
				$retired = isset( $stored['retired_targets'] ) && is_array( $stored['retired_targets'] ) ? $stored['retired_targets'] : array();
				$bucket  = isset( $retired[ $profile_id ] ) && is_array( $retired[ $profile_id ] ) ? $retired[ $profile_id ] : array();
				$bucket[ $previous_key ] = $previous + array( 'retired_at' => time() );
				// Keep the archive small: newest five targets per profile.
				if ( count( $bucket ) > 5 ) {
					uasort( $bucket, static fn( $a, $b ) => (int) ( $a['retired_at'] ?? 0 ) <=> (int) ( $b['retired_at'] ?? 0 ) );
					$bucket = array_slice( $bucket, -5, 5, true );
				}
				$retired[ $profile_id ]    = $bucket;
				$stored['retired_targets'] = $retired;
			}
		}

		$active_profile_id = '';
		if ( '' !== (string) $stored['key'] && '' !== (string) $stored['secret'] ) {
			$profile_name = $this->unique_profile_name( $profiles, $profile_name, $provider, (string) $stored['bucket'], $profile_id );
			$profiles[ $profile_id ] = array(
				'id'              => $profile_id,
				'name'            => $profile_name,
				'provider'        => $provider,
				'bucket'          => (string) $stored['bucket'],
				'region'          => (string) $stored['region'],
				'endpoint'        => (string) $stored['endpoint'],
				'key'             => (string) $stored['key'],
				'secret'          => (string) $stored['secret'],
				'object_acl'      => (string) $stored['object_acl'],
				'upload_path'     => $upload_path,
				'upload_url_path' => (string) $stored['upload_url_path'],
				'saved_at'        => time(),
			);
			$active_profile_id = $profile_id;
		} elseif ( ! $provider_changed && isset( $profiles[ $profile_id ] ) ) {
			$active_profile_id = $profile_id;
		}
		$stored['profiles']          = $profiles;
		$stored['active_profile_id'] = $active_profile_id;

		update_option( Options::OPTION, $stored, false );
		if ( 'true' === $options['enabled'] && '' !== $upload_url ) {
			Upload_URL::apply( $upload_url );
		} else {
			Upload_URL::restore( (string) ( $old['upload_url_path'] ?? $upload_url ) );
		}

		// Editing the target of a saved profile mints a new id instead of
		// repointing the old one, so say that out loud: the operator is looking
		// at a different profile now, and the previous one kept its records.
		$redirect = array( 'saved' => '1' );
		if ( '' !== $submitted_id && $submitted_id !== $profile_id ) {
			$redirect['created'] = '1';
		}
		$this->redirect( $redirect );
	}

	/**
	 * Save the upload-behaviour card without touching connection credentials.
	 */
	private function save_behaviour(): void {
		$raw = get_option( Options::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$raw['no_thumb']        = isset( $_POST['no_thumb'] ) ? 'true' : 'false';
		$raw['no_local_saving'] = isset( $_POST['no_local_saving'] ) ? 'true' : 'false';

		update_option( Options::OPTION, $raw, false );
		update_option(
			'sakura_imgopt_upload_concurrency',
			self::sanitize_upload_concurrency( isset( $_POST['upload_concurrency'] ) ? wp_unslash( $_POST['upload_concurrency'] ) : 1 ),
			false
		);

		$this->redirect( array( 'saved' => '1' ) );
	}

	/** Redirect to the S3 settings page; never returns. */
	private function redirect( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => S3_Storage_Module::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Keep saved profile names distinguishable when one provider has multiple accounts.
	 *
	 * @param array<string,array<string,mixed>> $profiles Existing profiles.
	 * @param string                            $name     Submitted name.
	 * @param string                            $provider Provider slug.
	 * @param string                            $bucket   Bucket name.
	 * @param string                            $id       Current profile ID.
	 */
	private function unique_profile_name( array $profiles, string $name, string $provider, string $bucket, string $id ): string {
		if ( '' === $name ) {
			$name = Options::default_profile_name( $provider, $bucket );
		}

		$used = array();
		foreach ( $profiles as $profile_id => $profile ) {
			if ( (string) $profile_id === $id ) {
				continue;
			}
			$used[] = (string) ( $profile['name'] ?? '' );
		}

		if ( ! in_array( $name, $used, true ) ) {
			return $name;
		}

		$base = $name;
		$i    = 2;
		while ( in_array( $name . ' (' . $i . ')', $used, true ) ) {
			++$i;
		}

		return $name . ' (' . $i . ')';
	}
}
