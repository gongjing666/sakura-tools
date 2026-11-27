<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Card_Form;
use Sakura\ImageOptimizer\S3\Storage_Module;
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
	 * Decide whether a save writes a new profile instead of rewriting one.
	 *
	 * Editing a saved profile keeps its id: the connection form always posts the
	 * id it is showing, and moving that profile to another bucket, space,
	 * endpoint or path is still the same account, so rewriting it in place is
	 * what the operator means. Minting a second profile behind their back — which
	 * is what used to happen whenever the target moved — looked like a phantom
	 * duplicate in the picker.
	 *
	 * Switching provider is not an edit of that profile: another provider means
	 * another account, another bucket and new credentials, so a save under it is
	 * an additional configuration. Treat it as "save as new" even when the
	 * checkbox is unticked, otherwise the connection the operator was editing is
	 * silently replaced — the exact way a second storage account eats the first.
	 *
	 * @param bool                $posted           Whether the operator ticked "save as new".
	 * @param bool                $provider_changed Whether the submitted provider differs from the edited profile.
	 * @param array<string,mixed> $editing          Profile the form is editing (empty when there is none).
	 */
	public static function save_as_new_for_save( bool $posted, bool $provider_changed, array $editing ): bool {
		if ( $posted ) {
			return true;
		}

		// Only a real edit can be overwritten; a new profile has nothing to lose.
		return $provider_changed && array() !== $editing;
	}

	/**
	 * Pick the profile a save writes to.
	 *
	 * A saved profile is edited in place unless save_as_new_for_save() says the
	 * save is a new one. Moving a target is handled where it actually matters:
	 * the previous target is archived under `retired_targets` so queued delete
	 * jobs can still reach the old objects, and the profile's reconciliation rows
	 * are purged in save(), so the sync center cannot print the old bucket's
	 * counts under the new address.
	 *
	 * @param array<string,array<string,mixed>> $profiles     Saved profiles keyed by id.
	 * @param string                            $submitted_id Profile the form was editing ('' for none).
	 * @param bool                              $save_as_new  Whether this save writes a new profile (see save_as_new_for_save()).
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

	/**
	 * Top-level option keys that mirror whichever profile is live.
	 *
	 * @var string[]
	 */
	private const MIRROR_FIELDS = array( 'provider', 'bucket', 'space', 'region', 'endpoint', 'key', 'secret', 'object_acl', 'upload_path', 'upload_url_path' );

	/**
	 * Keep the live mirror on a save that does not write the live profile.
	 *
	 * The mirror is what uploads, the status strip and the media URL rewriting
	 * read, so a stored-but-unused profile must not overwrite it. The values are
	 * copied from the option exactly as WordPress stores them — credentials are
	 * still ciphertext there. Reading them from Options::all() instead hands back
	 * the decrypted view, and writing that into the option would either store the
	 * secret in plaintext or silently re-encrypt it under a fresh nonce on every
	 * unrelated save, which makes two saves of the same form look like a
	 * credential change in every diff.
	 *
	 * @param array<string,mixed> $stored Prepared option being written.
	 * @param array<string,mixed> $raw    Option exactly as stored, credentials still encrypted.
	 * @return array<string,mixed>
	 */
	public static function keep_live_mirror( array $stored, array $raw ): array {
		foreach ( self::MIRROR_FIELDS as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$stored[ $field ] = $raw[ $field ];
			}
		}

		return $stored;
	}

	/**
	 * Option keys that belong to the profile list, not to the form being saved.
	 *
	 * save() rebuilds the option from scratch, so any key it does not know about is
	 * dropped on every save. That was already true of `retired_targets` (the
	 * archive Delete_Jobs reads after a target moved); `binding` and
	 * `behaviour_locked` would be lost the same way, and losing the binding on an
	 * unrelated credential save means the site silently unlocks itself.
	 *
	 * @param array<string,mixed> $stored Option being written, before it is persisted.
	 * @param array<string,mixed> $raw    Option exactly as stored.
	 * @return array<string,mixed>
	 */
	public static function keep_reserved_keys( array $stored, array $raw ): array {
		$defaults = array(
			'retired_targets'   => array(),
			'binding'           => array(),
			'behaviour_locked'  => 'false',
			'reconcile_pending' => array(),
		);
		foreach ( $defaults as $key => $fallback ) {
			if ( array_key_exists( $key, $stored ) ) {
				continue;
			}
			$stored[ $key ] = $raw[ $key ] ?? $fallback;
		}

		return $stored;
	}

	/**
	 * Whether the submitted save is trying to reopen the risky switch (R2).
	 *
	 * Only a false-to-true transition needs the acknowledgement: keeping the box
	 * ticked, or unticking it, is not the irreversible part.
	 *
	 * @param string $old_value Value stored before this request.
	 * @param string $new_value Effective value this request would store.
	 */
	public static function risk_ack_missing( string $old_value, string $new_value, string $ack ): bool {
		if ( 'true' !== $new_value || 'true' === $old_value ) {
			return false;
		}

		return '1' !== $ack;
	}

	public function apply( array $input, string $card ): array {
		if ( 'behaviour' === $card ) {
			return $this->apply_behaviour( $input );
		}

		$post = static function ( string $key ) use ( $input ): string {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
		};
		$has  = static function ( string $key ) use ( $input ): bool {
			return isset( $input[ $key ] );
		};
		$flag = static function ( string $key ) use ( $input ): bool {
			if ( ! isset( $input[ $key ] ) ) {
				return false;
			}

			$value = $input[ $key ];
			if ( is_bool( $value ) ) {
				return $value;
			}

			return is_scalar( $value ) && ! in_array( (string) $value, array( '', '0', 'false' ), true );
		};

		$connection_only = 'connection' === $card;

		$old       = $this->options->all();
		$raw       = get_option( Options::OPTION, array() );
		$raw       = is_array( $raw ) ? $raw : array();
		/** @var array<string,array<string,mixed>> $profiles */
		$profiles  = isset( $raw['profiles'] ) && is_array( $raw['profiles'] ) ? $raw['profiles'] : array();
		$providers = Providers::all();
		$provider  = sanitize_key( $post( 'provider' ) );
		if ( ! isset( $providers[ $provider ] ) ) {
			$provider = 'cloudflare';
		}
		$submitted_id     = sanitize_key( $post( 'profile_id' ) );
		$save_as_new      = $flag( 'save_as_new' );
		/** @var array<string,mixed> $editing Profile the form is editing, when it still exists. */
		$editing = '' !== $submitted_id && isset( $profiles[ $submitted_id ] ) && is_array( $profiles[ $submitted_id ] ) ? $profiles[ $submitted_id ] : array();
		// Compare against the profile being edited, not the active one: the
		// provider picker can load a saved config without activating it, and a
		// provider change there has to invalidate that entry's credentials.
		$provider_changed = $provider !== (string) ( array() !== $editing ? ( $editing['provider'] ?? '' ) : ( $old['provider'] ?? '' ) );
		// A different provider is a different account, so this save adds a
		// profile instead of rewriting the one the form was showing.
		$save_as_new      = self::save_as_new_for_save( $save_as_new, $provider_changed, $editing );
		$profile_name     = sanitize_text_field( $post( 'profile_name' ) );
		$bucket_raw       = sanitize_text_field( $post( 'bucket' ) );
		// 空间名由「获取 SDK 参数」写入隐藏字段；没有这个字段的服务商保持空串。
		$space_raw        = self::space_for_save(
			sanitize_text_field( $post( 'space' ) ),
			$bucket_raw,
			$editing
		);
		// The connection form starts empty on purpose (it is the "add a
		// configuration" form), so an accidental submit must not mint a profile
		// that has credentials but no bucket: it would sit on the panel as a
		// broken card nobody can enable.
		if ( '' === $bucket_raw ) {
			throw new Save_Refused( 'bucket' );
		}
		$endpoint_raw     = trim( $post( 'endpoint' ) );
		$endpoint         = '' === $endpoint_raw ? '' : esc_url_raw( $endpoint_raw, array( 'http', 'https' ) );
		if ( '' !== $endpoint_raw && '' === $endpoint ) {
			throw new Save_Refused( 'endpoint' );
		}
		$profile_id       = self::profile_id_for_save( $profiles, $submitted_id, $save_as_new );
		$url_raw          = trim( $post( 'upload_url_path' ) );
		$upload_url       = '' === $url_raw ? '' : esc_url_raw( untrailingslashit( $url_raw ), array( 'http', 'https' ) );
		if ( '' !== $url_raw && '' === $upload_url ) {
			throw new Save_Refused( 'url' );
		}
		$key         = self::clean_credential( $post( 'key' ) );
		$secret      = self::clean_credential( $post( 'secret' ) );
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
			// The S3 switch lives on the profile cards now, not in this form.
			// Deriving it from a checkbox that no longer exists would turn storage
			// off on every ordinary config save.
			'enabled'         => (string) ( $old['enabled'] ?? 'false' ),
			'provider'        => $provider,
			'bucket'          => $bucket_raw,
			'space'           => $space_raw,
			'region'          => $has( 'region' ) ? sanitize_text_field( $post( 'region' ) ) : 'us-east-1',
			'endpoint'        => $endpoint,
			'key'             => '' !== $key ? $key : (string) ( $kept['key'] ?? '' ),
			'secret'          => '' !== $secret ? $secret : (string) ( $kept['secret'] ?? '' ),
			'no_thumb'        => $connection_only ? (string) ( $old['no_thumb'] ?? 'false' ) : ( $flag( 'no_thumb' ) ? 'true' : 'false' ),
			'no_local_saving' => $connection_only ? (string) ( $old['no_local_saving'] ?? 'false' ) : ( $flag( 'no_local_saving' ) ? 'true' : 'false' ),
			'upload_url_path' => $upload_url,
			'object_acl'      => self::object_acl_for_save( $post( 'object_acl' ), $editing, $old ),
		);

		// R11 (07-S3存储生命周期): both switches are frozen once one upload was
		// confirmed. The rendered inputs are disabled while frozen, and a disabled
		// input posts nothing at all — reading the request here would read
		// "unchecked" and quietly turn the risky switch off. Take the value from
		// the freeze instead, so the option keeps saying what the site is doing.
		if ( $this->options->behaviour_frozen() ) {
			$behaviour                  = $this->options->behaviour_snapshot();
			$options['no_thumb']        = $behaviour['no_thumb'];
			$options['no_local_saving'] = $behaviour['no_local_saving'];
		} elseif ( self::risk_ack_missing( (string) ( $old['no_local_saving'] ?? 'false' ), (string) $options['no_local_saving'], $post( 'no_local_saving_ack' ) ) ) {
			// R2: the dialog is a courtesy, not the guard. The server re-checks the
			// acknowledgement so a replayed or hand-built request cannot slip past it.
			throw new Save_Refused( 'risk_ack' );
		}
		if ( ! $connection_only ) {
			$upload_concurrency = self::sanitize_upload_concurrency( $has( 'upload_concurrency' ) ? $post( 'upload_concurrency' ) : 1 );
			update_option( 'sakura_imgopt_upload_concurrency', $upload_concurrency, false );
		}

		$upload_path = trim( str_replace( '\\', '/', sanitize_text_field( $post( 'upload_path' ) ) ), '/' );
		if ( '' === $upload_path ) {
			$upload_path = Options::DEFAULT_UPLOAD_PATH;
		}
		if ( '.' !== $upload_path && ( preg_match( '#(^|/)\.\.(/|$)#', $upload_path ) || false !== strpos( $upload_path, "\0" ) ) ) {
			throw new Save_Refused( 'path' );
		}

		// Never overwrite WordPress' own upload_path option: when the site already
		// declares an uploads directory, keys keep following it (see §17.6).
		$site_path = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', '' ) ), '/' );
		if ( '' !== $site_path ) {
			$upload_path = $site_path;
		}

		$options['upload_path'] = $upload_path;

		/** @var array<string,mixed> $stored */
		$stored = $options;
		foreach ( array( 'key', 'secret' ) as $field ) {
			if ( '' === (string) $stored[ $field ] || Credential_Cipher::is_encrypted( (string) $stored[ $field ] ) ) {
				continue;
			}
			$encrypted = Credential_Cipher::encrypt( (string) $stored[ $field ] );
			if ( false === $encrypted ) {
				throw new Save_Refused( 'crypto' );
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
		$will_write = '' !== (string) $stored['key'] && '' !== (string) $stored['secret'];

		// R4 (07-S3存储生命周期): a bound site may keep adding and switching
		// profiles, but the profile holding the binding is no longer editable in the
		// five fields that decide where objects land. Editing it is the one move
		// that would leave every already-synced attachment pointing at objects that
		// are not there, so it is refused instead of confirmed.
		$binding = $this->options->binding();
		if (
			$will_write
			&& null !== $binding
			&& (string) ( $binding['profile_id'] ?? '' ) === $profile_id
			&& ! $this->options->binding_allows( $stored )
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries a field list to the redirect, never printed raw.
			throw new Save_Refused( 'locked', array( 'fields' => implode( ',', $this->options->changed_identity_fields( $stored ) ) ) );
		}
		$target_moved    = false;
		$previous_bucket = '';
		if ( $will_write && isset( $profiles[ $profile_id ] ) && is_array( $profiles[ $profile_id ] ) ) {
			$previous        = $profiles[ $profile_id ];
			$previous_key    = Delete_Jobs::target_hash( $previous );
			$next_key        = Delete_Jobs::target_hash( $stored );
			$previous_bucket = Bucket_Identity::from_profile( $previous, $this->options )->hash();
			if ( '' !== $previous_key && ! hash_equals( $previous_key, $next_key ) ) {
				$target_moved = true;
				$retired      = isset( $stored['retired_targets'] ) && is_array( $stored['retired_targets'] ) ? $stored['retired_targets'] : array();
				$bucket       = isset( $retired[ $profile_id ] ) && is_array( $retired[ $profile_id ] ) ? $retired[ $profile_id ] : array();
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

		// Which configuration the site is using is decided by the cards on the
		// profile panel (see Profile_Manager::activate()), not by saving the
		// connection form. A save here only writes the row it was asked to write:
		// storing a second profile must not silently move the live bucket, and
		// editing one profile must not steal the position from another.
		$previous_active   = (string) ( $raw['active_profile_id'] ?? '' );
		$active_profile_id = isset( $profiles[ $previous_active ] ) ? $previous_active : '';
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
			// The very first configuration still becomes the current one, so the
			// panel has a card to light up; it stays switched off until the
			// operator clicks it.
			if ( '' === $active_profile_id ) {
				$active_profile_id = $profile_id;
			}
		}
		$saved_is_live = $will_write && $profile_id === $active_profile_id;

		// Only a configuration that is actually serving uploads has to be complete;
		// an unfinished draft is allowed to sit in the list until it is finished.
		// Switching storage on is what activates a profile now, and that path
		// (Profile_Manager::activate) refuses an incomplete one on its own.
		if ( $saved_is_live && 'true' === (string) $stored['enabled'] && ! ( new Options( $stored ) )->is_configured() ) {
			throw new Save_Refused( 'config' );
		}

		if ( ! $saved_is_live ) {
			// Keep the live top-level mirror on the profile that is actually in
			// use: the same keys drive uploads, the status strip and the media
			// URL rewriting, so letting a stored-but-unused profile overwrite
			// them would point the whole site at a bucket nobody selected.
			$stored = self::keep_live_mirror( $stored, $raw );
		}

		$stored['profiles']          = $profiles;
		$stored['active_profile_id'] = $active_profile_id;
		$stored                      = self::keep_reserved_keys( $stored, $raw );

		update_option( Options::OPTION, $stored, false );
		if ( $target_moved ) {
			// Drop the rows of the bucket this profile used to name. The bucket has
			// to be handed over: the repository resolves buckets from the option it
			// reads now, which already points at the new address, and the index is
			// partitioned by bucket (R19.7), so asking it with the profile id would
			// reach for the new bucket and delete what the next scan is about to
			// rebuild. Rows another configuration row still names are kept, which
			// is exactly the standby case this rule exists for.
			( new Index_Repository() )->purge_index( $profile_id, $previous_bucket );
		}
		if ( $saved_is_live ) {
			if ( 'true' === (string) $stored['enabled'] && '' !== $upload_url ) {
				Upload_URL::apply( $upload_url );
			} else {
				Upload_URL::restore( (string) ( $old['upload_url_path'] ?? $upload_url ) );
			}
		}

		$redirect = array( 'saved' => '1' );
		if ( '' !== $submitted_id && $submitted_id !== $profile_id ) {
			$redirect['created'] = '1';
		}
		if ( $target_moved ) {
			$redirect['moved'] = '1';
		}

		return $redirect;
	}

	/**
	 * Save the upload-behaviour card without touching connection credentials.
	 *
	 * @param array<string,mixed> $input Request fields, already unslashed.
	 * @return array<string,string> Redirect arguments describing the outcome.
	 */
	private function apply_behaviour( array $input ): array {
		$raw = get_option( Options::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		$flag = static function ( string $key ) use ( $input ): bool {
			if ( ! isset( $input[ $key ] ) ) {
				return false;
			}

			$value = $input[ $key ];
			if ( is_bool( $value ) ) {
				return $value;
			}

			return is_scalar( $value ) && ! in_array( (string) $value, array( '', '0', 'false' ), true );
		};
		$ack  = isset( $input['no_local_saving_ack'] ) && is_scalar( $input['no_local_saving_ack'] ) ? (string) $input['no_local_saving_ack'] : '';

		// Same two guards as apply(): the freeze wins over the request, and
		// reopening the risky switch has to carry the acknowledgement (R2 / R11).
		if ( $this->options->behaviour_frozen() ) {
			$behaviour              = $this->options->behaviour_snapshot();
			$raw['no_thumb']        = $behaviour['no_thumb'];
			$raw['no_local_saving'] = $behaviour['no_local_saving'];
		} else {
			$no_thumb        = $flag( 'no_thumb' ) ? 'true' : 'false';
			$no_local_saving = $flag( 'no_local_saving' ) ? 'true' : 'false';
			if ( self::risk_ack_missing( (string) ( $raw['no_local_saving'] ?? 'false' ), $no_local_saving, $ack ) ) {
				throw new Save_Refused( 'risk_ack' );
			}

			$raw['no_thumb']        = $no_thumb;
			$raw['no_local_saving'] = $no_local_saving;
		}

		update_option( Options::OPTION, $raw, false );
		$concurrency = isset( $input['upload_concurrency'] ) && is_scalar( $input['upload_concurrency'] ) ? (string) $input['upload_concurrency'] : '1';
		update_option(
			'sakura_imgopt_upload_concurrency',
			self::sanitize_upload_concurrency( $concurrency ),
			false
		);

		return array( 'saved' => '1' );
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_save' );

		$post  = wp_unslash( $_POST );
		$input = is_array( $post ) ? $post : array();

		try {
			$args = $this->apply( $input, Card_Form::current_card() );
		} catch ( Save_Refused $refused ) {
			$this->redirect( array_merge( array( 'saved' => '0', 'error' => $refused->reason() ), $refused->args() ) );

			return;
		}

		$this->redirect( $args );
	}

	/** Redirect to the S3 settings page; never returns. */
	private function redirect( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => Storage_Module::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
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
