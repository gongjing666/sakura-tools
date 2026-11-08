<?php
/**
 * Instance-based S3 option resolution.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes the s3_options array, legacy migration and constant overrides.
 *
 * Unlike the legacy static adapter this object can be injected and tested.
 */
class Options {

	/**
	 * Option name.
	 */
	public const OPTION = 's3_options';

	/**
	 * Default uploads path.
	 */
	public const DEFAULT_UPLOAD_PATH = 'wp-content/uploads';

	/**
	 * Resolved options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Whether stored credentials could not be decrypted.
	 *
	 * @var bool
	 */
	private bool $credentials_unreadable = false;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $saved Raw option value (optional for tests).
	 */
	public function __construct( ?array $saved = null ) {
		$saved = null === $saved ? get_option( self::OPTION, array() ) : $saved;
		$saved = is_array( $saved ) ? $saved : array();

		$this->options = wp_parse_args( $saved, self::defaults() );

		// Legacy installs without `enabled` keep syncing when credentials exist.
		if ( ! array_key_exists( 'enabled', $saved ) && ! empty( $saved['bucket'] ) && ! empty( $saved['key'] ) && ! empty( $saved['secret'] ) ) {
			$this->options['enabled'] = 'true';
		}

		if ( defined( 'S3_UPLOADS_USE_CONSTANTS' ) && S3_UPLOADS_USE_CONSTANTS === true ) {
			$constants = array(
				'S3_UPLOADS_PROVIDER' => 'provider',
				'S3_UPLOADS_BUCKET'   => 'bucket',
				'S3_UPLOADS_REGION'   => 'region',
				'S3_UPLOADS_ENDPOINT' => 'endpoint',
				'S3_UPLOADS_KEY'      => 'key',
				'S3_UPLOADS_SECRET'   => 'secret',
			);
			foreach ( $constants as $constant => $key ) {
				if ( defined( $constant ) && constant( $constant ) !== '' ) {
					$this->options[ $key ] = constant( $constant );
				}
			}
		}

		foreach ( array( 'key', 'secret' ) as $field ) {
			$value = isset( $this->options[ $field ] ) && is_string( $this->options[ $field ] ) ? $this->options[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$decrypted = Credential_Cipher::decrypt( $value );
			if ( false === $decrypted ) {
				$this->credentials_unreadable = true;
				$this->options[ $field ]    = '';
				continue;
			}
			$this->options[ $field ] = $decrypted;
		}
	}

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => 'false',
			'provider'        => 'cloudflare',
			'bucket'          => '',
			'region'          => 'us-east-1',
			'endpoint'        => '',
			'key'             => '',
			'secret'          => '',
			'no_thumb'        => 'false',
			'no_local_saving' => 'false',
			'upload_url_path' => '',
			'object_acl'      => 'public-read',
			'active_profile_id' => '',
			'profiles'        => array(),
			'retired_targets' => array(),
		);
	}

	/**
	 * All resolved options.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->options;
	}

	/**
	 * One option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * Raw profiles keyed by independent profile ID.
	 *
	 * Key and secret values are encrypted; this is for internal persistence
	 * and switching only, never for direct rendering.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function profiles(): array {
		$profiles = $this->options['profiles'] ?? array();
		return is_array( $profiles ) ? $profiles : array();
	}

	/**
	 * One raw profile by ID.
	 *
	 * @param string $profile_id Profile ID.
	 * @return array<string,mixed>
	 */
	public function profile( string $profile_id ): array {
		$profile = $this->profiles()[ $profile_id ] ?? array();
		return is_array( $profile ) ? $profile : array();
	}

	/**
	 * Materialise a saved profile as a working options snapshot.
	 *
	 * H-01: upload/delete queue rows store the profile they were created under.
	 * A worker that always used the *current* options would replay those rows
	 * into whatever bucket is active now — after a settings switch, objects land
	 * in the wrong account. The queue row therefore carries the profile id, and
	 * the worker rebuilds this snapshot for that id. Credentials stay encrypted
	 * in the database and are decrypted by the Options constructor.
	 *
	 * @param string $profile_id Profile ID stored on the queue row.
	 * @return array<string,mixed>|null Null when the profile no longer exists.
	 */
	public function profile_options( string $profile_id ): ?array {
		if ( '' === $profile_id ) {
			return null;
		}

		$profile = $this->profile( $profile_id );
		if ( ! $profile ) {
			return null;
		}

		$profile['enabled']            = 'true';
		$profile['active_profile_id']  = $profile_id;

		return $profile;
	}

	/**
	 * Archived configuration for a target a queued job was created against.
	 *
	 * Editing a profile in place (new bucket/endpoint) used to strand its delete
	 * jobs: the worker refused to run them because the fingerprint no longer
	 * matched, leaving orphans in the old bucket that nobody could see or remove.
	 * The saver keeps the previous profile under `retired_targets` so the worker
	 * can still finish those deletions.
	 *
	 * @param string $profile_id Profile the job belongs to.
	 * @param string $hash       Fingerprint stored on the queue row.
	 * @return array<string,mixed>|null
	 */
	public function retired_target( string $profile_id, string $hash ): ?array {
		if ( '' === $profile_id || '' === $hash ) {
			return null;
		}

		$retired = $this->options['retired_targets'] ?? array();
		if ( ! is_array( $retired ) || ! isset( $retired[ $profile_id ] ) || ! is_array( $retired[ $profile_id ] ) ) {
			return null;
		}

		$profile = $retired[ $profile_id ][ $hash ] ?? null;

		return is_array( $profile ) ? $profile : null;
	}

	/**
	 * Active profile ID, with a legacy provider/bucket fallback.
	 */
	public function active_profile_id(): string {
		$active = (string) ( $this->options['active_profile_id'] ?? '' );
		if ( '' !== $active && isset( $this->profiles()[ $active ] ) ) {
			return $active;
		}

		$provider = (string) ( $this->options['provider'] ?? '' );
		$bucket   = (string) ( $this->options['bucket'] ?? '' );
		foreach ( $this->profiles() as $id => $profile ) {
			if ( ! is_array( $profile ) || (string) ( $profile['provider'] ?? '' ) !== $provider ) {
				continue;
			}
			if ( '' === $bucket || (string) ( $profile['bucket'] ?? '' ) === $bucket ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Safe profile metadata for the picker. Never exposes credentials.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function saved_profiles(): array {
		$labels = Providers::all();
		$active = $this->active_profile_id();
		$result = array();

		foreach ( $this->profiles() as $id => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$id       = (string) $id;
			$provider = (string) ( $profile['provider'] ?? '' );
			$label    = $labels[ $provider ] ?? $provider;
			$name     = trim( (string) ( $profile['name'] ?? '' ) );
			if ( '' === $name ) {
				$name = self::default_profile_name( $provider, (string) ( $profile['bucket'] ?? '' ) );
			}

			$key    = isset( $profile['key'] ) && is_string( $profile['key'] ) ? $profile['key'] : '';
			$secret = isset( $profile['secret'] ) && is_string( $profile['secret'] ) ? $profile['secret'] : '';
			$saved  = '' !== $key && '' !== $secret;
			$valid  = false;

			if ( $saved ) {
				$candidate = new self(
					array_merge(
						$profile,
						array(
							'enabled'  => 'true',
							'provider' => $provider,
						)
					)
				);
				$valid = ! $candidate->credentials_unreadable() && $candidate->is_configured();
			}

			$result[ $id ] = array(
				'id'       => $id,
				'name'     => $name,
				'provider' => $provider,
				'label'    => $label,
				'bucket'   => (string) ( $profile['bucket'] ?? '' ),
				'endpoint' => (string) ( $profile['endpoint'] ?? '' ),
				'region'   => (string) ( $profile['region'] ?? '' ),
				'saved'    => $saved,
				'complete' => $valid,
				'active'   => $id === $active,
				'saved_at' => (int) ( $profile['saved_at'] ?? 0 ),
			);
		}

		return $result;
	}

	/**
	 * Saved profile count per provider, for the select labels.
	 *
	 * @return array<string,int>
	 */
	public function provider_saved_counts(): array {
		$counts = array();
		foreach ( $this->saved_profiles() as $profile ) {
			if ( empty( $profile['saved'] ) ) {
				continue;
			}
			$provider = (string) $profile['provider'];
			$counts[ $provider ] = ( $counts[ $provider ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * New independent profile ID.
	 */
	public static function new_profile_id(): string {
		return 'prof_' . substr( md5( uniqid( 'profile', true ) ), 0, 12 );
	}

	/**
	 * Deterministic ID for legacy provider-keyed profiles.
	 *
	 * @param string $provider Provider.
	 * @param string $bucket   Bucket.
	 * @param string $endpoint Endpoint.
	 */
	public static function profile_id_for( string $provider, string $bucket, string $endpoint ): string {
		return 'prof_' . substr( md5( $provider . '|' . $bucket . '|' . $endpoint ), 0, 12 );
	}

	/**
	 * Human-readable default profile name.
	 *
	 * @param string $provider Provider.
	 * @param string $bucket   Bucket.
	 */
	public static function default_profile_name( string $provider, string $bucket ): string {
		$label = Providers::all()[ $provider ] ?? $provider;
		return trim( $label . ( '' !== $bucket ? ' · ' . $bucket : '' ) );
	}

	/**
	 * Migrate pre-profile and provider-keyed profiles to independent IDs once.
	 */
	public static function migrate_legacy_profile(): void {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			return;
		}

		$profiles    = isset( $saved['profiles'] ) && is_array( $saved['profiles'] ) ? $saved['profiles'] : array();
		$active_id   = isset( $saved['active_profile_id'] ) ? (string) $saved['active_profile_id'] : '';
		$provider    = isset( $saved['provider'] ) ? (string) $saved['provider'] : 'cloudflare';
		$bucket      = isset( $saved['bucket'] ) ? (string) $saved['bucket'] : '';
		$endpoint    = isset( $saved['endpoint'] ) ? (string) $saved['endpoint'] : '';
		$upload_path = self::resolve_upload_path( $saved );

		$key    = isset( $saved['key'] ) && is_string( $saved['key'] ) ? $saved['key'] : '';
		$secret = isset( $saved['secret'] ) && is_string( $saved['secret'] ) ? $saved['secret'] : '';

		if ( array() === $profiles && '' !== $key && '' !== $secret ) {
			$id = self::profile_id_for( $provider, $bucket, $endpoint );
			$profiles[ $id ] = array(
				'id'              => $id,
				'name'            => self::default_profile_name( $provider, $bucket ),
				'provider'        => $provider,
				'bucket'          => $bucket,
				'region'          => (string) ( $saved['region'] ?? 'us-east-1' ),
				'endpoint'        => $endpoint,
				'key'             => $key,
				'secret'          => $secret,
				'object_acl'      => (string) ( $saved['object_acl'] ?? 'public-read' ),
				'upload_path'     => $upload_path,
				'upload_url_path' => (string) ( $saved['upload_url_path'] ?? '' ),
				'saved_at'        => time(),
			);
			$active_id = $id;
		} elseif ( array() !== $profiles ) {
			$migrated = array();
			foreach ( $profiles as $key_id => $profile ) {
				if ( ! is_array( $profile ) ) {
					continue;
				}

				$id = isset( $profile['id'] ) && is_string( $profile['id'] ) ? trim( $profile['id'] ) : '';
				if ( '' === $id ) {
					$id = self::profile_id_for(
						(string) ( $profile['provider'] ?? (string) $key_id ),
						(string) ( $profile['bucket'] ?? '' ),
						(string) ( $profile['endpoint'] ?? '' )
					);
				}
				while ( isset( $migrated[ $id ] ) ) {
					$id .= '-' . substr( md5( (string) $key_id ), 0, 4 );
				}

				$profile['id']       = $id;
				$profile['provider'] = (string) ( $profile['provider'] ?? (string) $key_id );
				if ( '' === trim( (string) ( $profile['name'] ?? '' ) ) ) {
					$profile['name'] = self::default_profile_name( $profile['provider'], (string) ( $profile['bucket'] ?? '' ) );
				}
				$migrated[ $id ] = $profile;
			}
			$profiles = $migrated;
		}

		if ( array() === $profiles ) {
			return;
		}

		if ( '' === $active_id || ! isset( $profiles[ $active_id ] ) ) {
			$active_id = '';
			foreach ( $profiles as $id => $profile ) {
				if ( (string) ( $profile['provider'] ?? '' ) === $provider ) {
					$active_id = (string) $id;
					if ( (string) ( $profile['bucket'] ?? '' ) === $bucket ) {
						break;
					}
				}
			}
			if ( '' === $active_id ) {
				$active_id = (string) array_key_first( $profiles );
			}
		}

		if ( $profiles === ( $saved['profiles'] ?? array() ) && $active_id === (string) ( $saved['active_profile_id'] ?? '' ) ) {
			return;
		}

		$saved['profiles']          = $profiles;
		$saved['active_profile_id'] = $active_id;
		update_option( self::OPTION, $saved, false );
	}

	/**
	 * Whether object storage is enabled.
	 */
	public function is_enabled(): bool {
		return 'true' === ( $this->options['enabled'] ?? 'false' );
	}

	/**
	 * Whether stored credentials exist but cannot be decrypted.
	 */
	public function credentials_unreadable(): bool {
		return $this->credentials_unreadable;
	}

	/**
	 * Return the machine-readable reasons the storage configuration is unusable.
	 *
	 * Endpoint is required for every supported S3-compatible provider. The
	 * legacy `aws` provider may omit it to use the SDK's default endpoint.
	 *
	 * @return string[]
	 */
	public function configuration_errors(): array {
		$errors = array();
		$provider = (string) ( $this->options['provider'] ?? '' );
		if ( 'aws' !== $provider && ! Providers::exists( $provider ) ) {
			$errors[] = 'provider';
		}

		foreach ( array( 'bucket', 'key', 'secret' ) as $field ) {
			if ( '' === trim( (string) ( $this->options[ $field ] ?? '' ) ) ) {
				$errors[] = $field;
			}
		}

		$endpoint = trim( (string) ( $this->options['endpoint'] ?? '' ) );
		if ( '' === $endpoint ) {
			if ( 'aws' !== $provider ) {
				$errors[] = 'endpoint';
			}
		} elseif ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$errors[] = 'endpoint';
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Whether bucket/key/secret/endpoint are present and valid.
	 */
	public function is_configured(): bool {
		return array() === $this->configuration_errors();
	}

	/**
	 * Resolve the object-key prefix for an options snapshot.
	 *
	 * Priority: an explicit WordPress `upload_path` option (a site-wide custom
	 * uploads directory, or a value written by older plugin versions) is kept
	 * as-is and never overwritten; then the prefix stored with the S3 options;
	 * finally the uploads directory derived from wp_upload_dir() relative to
	 * ABSPATH. Only the resolved value is used for object keys - the plugin no
	 * longer writes WordPress' own `upload_path` option.
	 *
	 * @param array $options Options snapshot, may contain `upload_path`.
	 */
	public static function resolve_upload_path( array $options = array() ): string {
		$core = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', '' ) ), '/' );
		if ( '' !== $core ) {
			return $core;
		}

		$stored = isset( $options['upload_path'] ) ? trim( str_replace( '\\', '/', (string) $options['upload_path'] ), '/' ) : '';
		if ( '' !== $stored ) {
			return $stored;
		}

		$derived = self::derive_upload_prefix();
		return '' !== $derived ? $derived : self::DEFAULT_UPLOAD_PATH;
	}

	/**
	 * Where the resolved prefix comes from.
	 *
	 * @param array $options Options snapshot.
	 * @return string wordpress_option|plugin|derived|fallback
	 */
	public static function upload_path_source( array $options = array() ): string {
		$core = trim( str_replace( '\\', '/', (string) get_option( 'upload_path', '' ) ), '/' );
		if ( '' !== $core ) {
			return 'wordpress_option';
		}

		$stored = isset( $options['upload_path'] ) ? trim( str_replace( '\\', '/', (string) $options['upload_path'] ), '/' ) : '';
		if ( '' !== $stored ) {
			return 'plugin';
		}

		return '' !== self::derive_upload_prefix() ? 'derived' : 'fallback';
	}

	/**
	 * Derive the uploads prefix from the real uploads directory.
	 */
	private static function derive_upload_prefix(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$base    = self::normalize_path( $basedir );
		$root    = self::normalize_path( (string) ABSPATH );
		if ( '' === $base || '' === $root ) {
			return '';
		}
		if ( 0 !== strpos( $base . '/', $root . '/' ) ) {
			return '';
		}
		return trim( substr( $base, strlen( $root ) ), '/' );
	}

	/**
	 * Normalize a filesystem path to a forward-slash form without a trailing slash.
	 *
	 * @param string $path Raw path.
	 */
	private static function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );
		return rtrim( (string) $path, '/' );
	}

	/**
	 * Uploads path relative to the WordPress root.
	 */
	public function upload_path(): string {
		return self::resolve_upload_path( $this->options );
	}

	/**
	 * Validated ACL.
	 */
	public function object_acl(): string {
		$acl = (string) ( $this->options['object_acl'] ?? 'public-read' );
		return in_array( $acl, array( 'public-read', 'private' ), true ) ? $acl : 'public-read';
	}
}
