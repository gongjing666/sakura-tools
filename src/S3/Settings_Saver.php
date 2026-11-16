<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\S3_Storage_Module;
use Sakura\ImageOptimizer\Sync\Index_Repository;

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
	 * Pick the profile a save writes to.
	 *
	 * A saved profile is edited in place: the connection form always posts the
	 * id it is showing, and rewriting its provider, bucket or endpoint keeps
	 * that id. Minting a second profile behind the operator's back — which is
	 * what happened when only the target changed — looked like a phantom
	 * duplicate in the picker.
	 *
	 * Moving a target is handled where it actually matters: the previous target
	 * is archived under `retired_targets` so queued delete jobs can still reach
	 * the old objects, and the profile's reconciliation rows are purged in
	 * save(), so the sync center cannot print the old bucket's counts under the
	 * new address.
	 *
	 * @param array<string,array<string,mixed>> $profiles     Saved profiles keyed by id.
	 * @param string                            $submitted_id Profile the form was editing ('' for none).
	 * @param bool                              $save_as_new  Whether the operator asked for a copy.
	 */
	public static function profile_id_for_save( array $profiles, string $submitted_id, bool $save_as_new ): string {
		if ( $save_as_new || '' === $submitted_id || ! isset( $profiles[ $submitted_id ] ) ) {
			return Options::new_profile_id();
		}

		return $submitted_id;
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

	/**
	 * Keep the stored object ACL when the connection card no longer posts one.
	 *
	 * The control was removed because an object ACL does not decide whether a
	 * custom domain can serve a file. Older profiles may still carry private,
	 * and an unrelated save must not silently flip them back to the default.
	 *
	 * @param mixed               $submitted Posted value, when a form still sends the field.
	 * @param array<string,mixed> $editing   Profile the form is editing (empty for a new one).
	 * @param array<string,mixed> $current   Active options.
	 */
	public static function object_acl_for_save( $submitted, array $editing, array $current ): string {
		$allowed = array( 'public-read', 'private' );
		if ( is_string( $submitted ) && in_array( $submitted, $allowed, true ) ) {
			return $submitted;
		}

		$fallback = (string) ( $editing['object_acl'] ?? '' );
		if ( ! in_array( $fallback, $allowed, true ) ) {
			$fallback = (string) ( $current['object_acl'] ?? '' );
		}

		return in_array( $fallback, $allowed, true ) ? $fallback : 'public-read';
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
		$submitted_id     = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
		$save_as_new      = ! empty( $_POST['save_as_new'] );
		/** @var array<string,mixed> $editing Profile the form is editing, when it still exists. */
		$editing = '' !== $submitted_id && isset( $profiles[ $submitted_id ] ) && is_array( $profiles[ $submitted_id ] ) ? $profiles[ $submitted_id ] : array();
		// Compare against the profile being edited, not the active one: the
		// provider picker can load a saved config without activating it, and a
		// provider change there has to invalidate that entry's credentials.
		$provider_changed = $provider !== (string) ( array() !== $editing ? ( $editing['provider'] ?? '' ) : ( $old['provider'] ?? '' ) );
		$profile_name     = isset( $_POST['profile_name'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_name'] ) ) : '';
		$bucket_raw       = isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '';
		// 空间名由「获取 SDK 参数」写入隐藏字段；没有这个字段的服务商保持空串。
		$space_raw        = self::space_for_save(
			isset( $_POST['space'] ) ? sanitize_text_field( wp_unslash( $_POST['space'] ) ) : '',
			$bucket_raw,
			$editing
		);
		$endpoint_raw     = isset( $_POST['endpoint'] ) ? trim( wp_unslash( $_POST['endpoint'] ) ) : '';
		$endpoint         = '' === $endpoint_raw ? '' : esc_url_raw( $endpoint_raw, array( 'http', 'https' ) );
		if ( '' !== $endpoint_raw && '' === $endpoint ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'endpoint' ) );
		}
		$profile_id       = self::profile_id_for_save( $profiles, $submitted_id, $save_as_new );
		$url_raw    = isset( $_POST['upload_url_path'] ) ? trim( wp_unslash( $_POST['upload_url_path'] ) ) : '';
		$upload_url = '' === $url_raw ? '' : esc_url_raw( untrailingslashit( $url_raw ), array( 'http', 'https' ) );
		if ( '' !== $url_raw && '' === $upload_url ) {
			$this->redirect( array( 'saved' => '0', 'error' => 'url' ) );
		}
		$key         = isset( $_POST['key'] ) ? self::clean_credential( (string) wp_unslash( $_POST['key'] ) ) : '';
		$secret      = isset( $_POST['secret'] ) ? self::clean_credential( (string) wp_unslash( $_POST['secret'] ) ) : '';
		// A blank credential field means "keep what this profile already has".
		// The stored ciphertext is carried over verbatim so a secret the operator
		// never retyped is not decrypted and re-encrypted on every save; a brand
		// new profile inherits the active credentials instead.
		if ( '' === $key || '' === $secret ) {
			$kept = array() !== $editing ? $editing : array(
				'key'    => (string) ( $raw['key'] ?? '' ),
				'secret' => (string) ( $raw['secret'] ?? '' ),
			);
			if ( $provider_changed ) {
				// Switching provider invalidates credentials issued for the old one.
				$kept = array();
			}
		} else {
			$kept = array();
		}
		$options     = array(
			'enabled'         => isset( $_POST['enabled'] ) ? 'true' : 'false',
			'provider'        => $provider,
			'bucket'          => $bucket_raw,
			'space'           => $space_raw,
			'region'          => isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : 'us-east-1',
			'endpoint'        => $endpoint,
			'key'             => '' !== $key ? $key : (string) ( $kept['key'] ?? '' ),
			'secret'          => '' !== $secret ? $secret : (string) ( $kept['secret'] ?? '' ),
			'no_thumb'        => $connection_only ? (string) ( $old['no_thumb'] ?? 'false' ) : ( isset( $_POST['no_thumb'] ) ? 'true' : 'false' ),
			'no_local_saving' => $connection_only ? (string) ( $old['no_local_saving'] ?? 'false' ) : ( isset( $_POST['no_local_saving'] ) ? 'true' : 'false' ),
			'upload_url_path' => $upload_url,
			'object_acl'      => self::object_acl_for_save( isset( $_POST['object_acl'] ) ? wp_unslash( $_POST['object_acl'] ) : '', $editing, $old ),
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
			if ( '' === (string) $stored[ $field ] || Credential_Cipher::is_encrypted( (string) $stored[ $field ] ) ) {
				continue;
			}
			$encrypted = Credential_Cipher::encrypt( (string) $stored[ $field ] );
			if ( false === $encrypted ) {
				$this->redirect( array( 'saved' => '0', 'error' => 'crypto' ) );
			}
			$stored[ $field ] = $encrypted;
		}

		// Audit A03: a profile keeps its id when its provider/bucket/endpoint
		// changes, which strands delete jobs already queued against the old target
		// and leaves reconciliation rows counted against a bucket nobody points at
		// any more. Keep the previous configuration keyed by its fingerprint so the
		// worker can still remove those objects, and drop the stale reconciliation
		// rows so the sync center starts clean for the new target.
		// Nothing below applies unless this save actually rewrites the profile:
		// a save that leaves both credentials blank writes nothing, and purging
		// its reconciliation rows would delete records for a target that did not
		// move.
		$will_write   = '' !== (string) $stored['key'] && '' !== (string) $stored['secret'];
		$target_moved = false;
		if ( $will_write && isset( $profiles[ $profile_id ] ) && is_array( $profiles[ $profile_id ] ) ) {
			$previous     = $profiles[ $profile_id ];
			$previous_key = Delete_Jobs::target_hash( $previous );
			$next_key     = Delete_Jobs::target_hash( $stored );
			if ( '' !== $previous_key && ! hash_equals( $previous_key, $next_key ) ) {
				$target_moved = true;
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
		if ( $will_write ) {
			$profile_name = $this->unique_profile_name( $profiles, $profile_name, $provider, (string) $stored['bucket'], (string) $stored['space'], $profile_id );
			$profiles[ $profile_id ] = array(
				'id'              => $profile_id,
				'name'            => $profile_name,
				'provider'        => $provider,
				'bucket'          => (string) $stored['bucket'],
				'space'           => (string) $stored['space'],
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
		if ( $target_moved ) {
			// The rows describe objects in the previous bucket; keeping them would
			// print that bucket's counts under the new address until the next scan.
			( new Index_Repository() )->purge_index( $profile_id );
		}
		if ( 'true' === $options['enabled'] && '' !== $upload_url ) {
			Upload_URL::apply( $upload_url );
		} else {
			Upload_URL::restore( (string) ( $old['upload_url_path'] ?? $upload_url ) );
		}

		$redirect = array( 'saved' => '1' );
		if ( '' !== $submitted_id && $submitted_id !== $profile_id ) {
			$redirect['created'] = '1';
		}
		if ( $target_moved ) {
			$redirect['moved'] = '1';
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
	 * Resolve the submitted space name against the profile being edited.
	 *
	 * A form rendered before this field existed — or one where the script never
	 * ran — posts no space at all. Treating that as an empty value would erase a
	 * name the operator never touched, so a submission without one keeps what
	 * the profile already holds, but only while the bucket is unchanged: a new
	 * bucket is a different target and must not inherit the old label.
	 *
	 * @param string               $submitted Space from the request, may be empty.
	 * @param string               $bucket    Bucket from the same request.
	 * @param array<string,mixed>  $editing   Profile the form was editing.
	 */
	public static function space_for_save( string $submitted, string $bucket, array $editing ): string {
		if ( '' !== $submitted ) {
			return $submitted;
		}

		if ( '' === $bucket || $bucket !== (string) ( $editing['bucket'] ?? '' ) ) {
			return '';
		}

		return (string) ( $editing['space'] ?? '' );
	}

	/**
	 * Keep saved profile names distinguishable when one provider has multiple accounts.
	 *
	 * @param array<string,array<string,mixed>> $profiles Existing profiles.
	 * @param string                            $name     Submitted name.
	 * @param string                            $provider Provider slug.
	 * @param string                            $bucket   Bucket name.
	 * @param string                            $space    Storage space name, when the provider reports one.
	 * @param string                            $id       Current profile ID.
	 */
	private function unique_profile_name( array $profiles, string $name, string $provider, string $bucket, string $space, string $id ): string {
		if ( '' === $name ) {
			$name = Options::default_profile_name( $provider, $bucket, $space );
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
