<?php
/**
 * Instance-based S3 upload adapter.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Maps local paths to keys and uploads through an injected object store.
 *
 * Used by the instance storage module, settings migration and CLI; no static
 * storage facade is involved.
 */
final class Storage_Adapter {

	/**
	 * Instance options.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Lazy object store.
	 *
	 * @var Object_Store|null
	 */
	private ?Object_Store $store = null;
	private ?Remote_Operation_Guard $mutation_guard = null;
	/** @var callable|null */
	private $mutation_check = null;

	/** Execute with a fresh ownership check immediately before remote dispatch. */
	public function with_mutation_check( callable $check, callable $operation ) {
		$previous = $this->mutation_check;
		$this->mutation_check = $check;
		try {
			return $operation();
		} finally {
			$this->mutation_check = $previous;
		}
	}

	private function guard(): Remote_Operation_Guard {
		$config = $this->options->all();
		return $this->mutation_guard ??= new Remote_Operation_Guard( Remote_Operation_Guard::scope_for( $this->options ), null, array( 'endpoint' => (string) ( $config['endpoint'] ?? '' ), 'bucket' => (string) ( $config['bucket'] ?? '' ) ) );
	}

	private static function file_evidence( string $path ): array {
		return array( 'size' => (int) @filesize( $path ), 'mtime' => (int) @filemtime( $path ), 'md5' => (string) @md5_file( $path ) );
	}

	/**
	 * Emit one failure event for Upload_Observer.
	 *
	 * This is the only place that turns a per-key WP_Error into an event, so a
	 * failed transfer is recorded exactly once even when the guard or store
	 * throws before returning a result map.
	 *
	 * @param string    $key           Object key.
	 * @param string    $path          Local path.
	 * @param \WP_Error $error         Failure.
	 * @param int       $attachment_id Attachment ID, or 0.
	 */
	private function report_failure( string $key, string $path, \WP_Error $error, int $attachment_id ): void {
		do_action( 'sakura_imgopt_s3_upload_failed', $key, $path, $error, $attachment_id );
	}

	/**
	 * Constructor.
	 *
	 * @param Options|null $options Optional options for tests.
	 */
	public function __construct( ?Options $options = null ) {
		$this->options = $options ?? new Options();
	}

	/**
	 * Resolve a local file to its object key.
	 *
	 * @param string $file Absolute local path.
	 * @return string|false
	 */
	public function key_from_path( string $file ) {
		$file = wp_normalize_path( $file );
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $file, $root ) ) {
			return $this->normalize_key( substr( $file, strlen( $root ) ) );
		}

		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		if ( 0 !== strpos( $file, $base ) ) {
			return false;
		}

		$relative = ltrim( substr( $file, strlen( $base ) ), '/' );
		$prefix   = '.' === $this->options->upload_path() ? '' : trailingslashit( $this->options->upload_path() );

		return $this->normalize_key( $prefix . $relative );
	}

	/**
	 * Upload one local file.
	 *
	 * @param string $key           Object key.
	 * @param string $filename      Local file.
	 * @param bool   $delete_local  Delete after success.
	 * @param int    $attachment_id Attachment ID, or 0.
	 * @return true|\WP_Error
	 */
	public function upload_file( string $key, string $filename, bool $delete_local = false, int $attachment_id = 0 ) {
		$requested = $key;
		$key       = $this->normalize_key( $key );
		if ( false === $key ) {
			$error = new \WP_Error( 'sakura_s3_invalid_file', __( '文件不存在、不可读或对象 Key 无效。', 'sakura-imgopt' ) );
			$this->report_failure( $requested, $filename, $error, $attachment_id );
			return $error;
		}

		if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
			$error = new \WP_Error( 'sakura_s3_invalid_file', __( '文件不存在或不可读。', 'sakura-imgopt' ) );
			$this->report_failure( $key, $filename, $error, $attachment_id );
			return $error;
		}

		try {
			$store   = $this->store();
			$results = $this->guard()->execute( array( $key ), 'upload', static function () use ( $store, $key, $filename, $delete_local ): array {
				return array( $key => $store->upload_file( $key, $filename, $delete_local ) );
			}, $this->mutation_check, array( $key => self::file_evidence( $filename ) ) );
			$result  = $results[ $key ] ?? new \WP_Error( 'sakura_s3_upload_missing_result', __( '上传结果缺失。', 'sakura-imgopt' ) );
			if ( is_wp_error( $result ) ) {
				$this->report_failure( $key, $filename, $result, $attachment_id );
			}
			return $result;
		} catch ( \Throwable $e ) {
			$error = new \WP_Error( 'sakura_s3_upload_failed', $e->getMessage() );
			$this->report_failure( $key, $filename, $error, $attachment_id );
			return $error;
		}
	}

	/**
	 * Upload several files, preserving the requested order per key.
	 *
	 * @param array<int,array{key:string,path:string}> $files         Files.
	 * @param int                                      $concurrency   Effective 1-4.
	 * @param int                                      $attachment_id Attachment ID, or 0.
	 * @return array<string,true|\WP_Error>
	 */
	public function upload_many( array $files, int $concurrency = 1, int $attachment_id = 0 ): array {
		$normalized = array();
		foreach ( $files as $file ) {
			$requested = (string) ( $file['key'] ?? '' );
			$path      = (string) ( $file['path'] ?? '' );
			$key       = $this->normalize_key( $requested );
			if ( false === $key ) {
				$error = new \WP_Error( 'sakura_s3_invalid_file', __( '文件不存在、不可读或对象 Key 无效。', 'sakura-imgopt' ) );
				$this->report_failure( $requested, $path, $error, $attachment_id );
				continue;
			}
			$normalized[] = array(
				'key'  => $key,
				'path' => $path,
			);
		}

		$paths = array_column( $normalized, 'path', 'key' );
		try {
			$store   = $this->store();
			$results = $this->guard()->execute( array_keys( $paths ), 'upload', static function ( array $keys ) use ( $store, $paths, $concurrency ): array {
				$admitted = array();
				foreach ( $keys as $key ) {
					$admitted[] = array( 'key' => $key, 'path' => $paths[ $key ] );
				}
				return $store->upload_many( $admitted, $concurrency );
			}, $this->mutation_check, array_map( static fn( $path ) => self::file_evidence( $path ), $paths ) );
		} catch ( \Throwable $e ) {
			$results = array();
			foreach ( $normalized as $file ) {
				$results[ $file['key'] ] = new \WP_Error( 'sakura_s3_upload_failed', $e->getMessage() );
			}
		}

		foreach ( $normalized as $file ) {
			$key    = (string) $file['key'];
			$result = $results[ $key ] ?? new \WP_Error( 'sakura_s3_upload_missing_result', __( '上传结果缺失。', 'sakura-imgopt' ) );
			if ( is_wp_error( $result ) ) {
				$this->report_failure( $key, (string) $file['path'], $result, $attachment_id );
			}
		}

		return $results;
	}

	/**
	 * Round-trip time to the configured bucket, in milliseconds.
	 *
	 * One LIST with max-keys=1 is the cheapest signed request that exercises the
	 * whole chain a user cares about — DNS, TLS, signature, bucket policy — while
	 * staying inside the read permission every deployment already has. Probing a
	 * guessed key with HEAD would answer 404 on an empty bucket, and a 404 takes
	 * extra bookkeeping to tell apart from a broken endpoint.
	 *
	 * @return int|\WP_Error Milliseconds, or the transport error.
	 */
	public function probe_latency() {
		try {
			$store = $this->store();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_probe_failed', $e->getMessage() );
		}

		/*
		 * Warm-up, not measured: the first call in a request also pays for the
		 * short-lived credential exchange (multiple seconds on a cold cache).
		 * Billing that to the bucket would paint the dot red for something the
		 * bucket had nothing to do with.
		 */
		$warm = $store->list_objects( '', 1 );

		if ( is_wp_error( $warm ) ) {
			return $warm;
		}

		$started = microtime( true );
		$result  = $store->list_objects( '', 1 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Normalize an object key.
	 *
	 * @param string $key Raw key.
	 * @return string|false
	 */
	public function normalize_key( string $key ) {
		$key = preg_replace( '#/+#', '/', str_replace( '\\', '/', $key ) );
		$key = ltrim( (string) $key, '/' );

		if ( '' === $key || preg_match( '#(^|/)\.\.(/|$)#', $key ) || false !== strpos( $key, "\0" ) ) {
			return false;
		}

		return $key;
	}


	/**
	 * Map an object key back to its attachment when it lives under uploads.
	 *
	 * @param string $key Object key.
	 * @return int Attachment ID, or 0 when unknown.
	 */
	public function attachment_id_for_key( string $key ): int {
		$key = $this->normalize_key( $key );
		if ( false === $key ) {
			return 0;
		}

		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$path    = wp_normalize_path( trailingslashit( ABSPATH ) . $key );
		if ( 0 !== strpos( $path, $base ) ) {
			return 0;
		}

		$relative   = ltrim( substr( $path, strlen( $base ) ), '/' );
		$candidates = array( $relative );

		// A thumbnail key belongs to the attachment of its parent file. Big
		// image uploads keep a "-scaled" parent, so try that suffix too.
		$stripped = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $relative );
		if ( is_string( $stripped ) && $stripped !== $relative ) {
			$candidates[] = $stripped;
			$candidates[] = (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $stripped );
		}

		global $wpdb;
		foreach ( array_unique( $candidates ) as $candidate ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$candidate
				)
			);
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Return the files associated with an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public function attachment_files( $attachment_id ): array {
		$paths    = array();
		$attached = get_attached_file( $attachment_id, true );
		if ( is_string( $attached ) && '' !== $attached ) {
			$paths[] = wp_normalize_path( $attached );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		if ( ! empty( $metadata['file'] ) ) {
			$uploads = wp_upload_dir( null, false );
			$main    = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $metadata['file'], '/' ) );
			$dir     = trailingslashit( dirname( $main ) );
			$paths[] = $main;

			$original = self::metadata_filename( $metadata['original_image'] ?? null );
			if ( '' !== $original ) {
				$paths[] = $dir . $original;
			}

			foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
				$size_file = self::metadata_filename( is_array( $size ) ? ( $size['file'] ?? null ) : null );
				if ( '' !== $size_file ) {
					$paths[] = $dir . $size_file;
				}
			}

			foreach ( (array) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) as $size ) {
				$size_file = self::metadata_filename( is_array( $size ) ? ( $size['file'] ?? null ) : null );
				if ( '' !== $size_file ) {
					$paths[] = $dir . $size_file;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'wp_normalize_path', $paths ) ) ) );
	}

	/**
	 * Download one object to a local file.
	 *
	 * @param string $key      Object key.
	 * @param string $filename Destination path.
	 * @return true|\WP_Error
	 */
	public function download_file( string $key, string $filename ) {
		$key = $this->normalize_key( $key );
		if ( false === $key ) {
			return new \WP_Error( 'sakura_s3_invalid_key', __( '对象 Key 无效。', 'sakura-imgopt' ) );
		}

		try {
			return $this->store()->download_file( $key, $filename );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_download_failed', $e->getMessage() );
		}
	}

	/**
	 * Create a short-lived signed URL for browser preview or download.
	 *
	 * Empty output means signing is unavailable; callers should hide the
	 * preview rather than expose an unsigned private URL.
	 *
	 * @param string $key          Object key.
	 * @param string $expires      Expiry accepted by the SDK.
	 * @param string $disposition  Optional Content-Disposition override.
	 * @param string $content_type Optional Content-Type override.
	 */
	public function presigned_url( string $key, string $expires = '+15 minutes', string $disposition = '', string $content_type = '' ): string {
		$key = $this->normalize_key( $key );
		if ( false === $key ) {
			return '';
		}

		try {
			$url = $this->store()->presigned_url( $key, $expires, $disposition, $content_type );
			return is_wp_error( $url ) ? '' : (string) $url;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Delete remote keys.
	 *
	 * @param string[] $keys Keys.
	 * @return bool|\WP_Error
	 */
	public function delete_keys( array $keys ) {
		$keys = array_values( array_filter( array_map( array( $this, 'normalize_key' ), $keys ) ) );
		if ( ! $keys ) {
			return true;
		}

		try {
			$store = $this->store();
			$results = $this->guard()->execute( $keys, 'delete', static function ( array $admitted ) use ( $store ): array {
				$result = $store->delete_keys( $admitted );
				return array_fill_keys( $admitted, $result );
			}, $this->mutation_check );
			foreach ( $results as $result ) {
				if ( true !== $result ) {
					return $result;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_delete_failed', $e->getMessage() );
		}
	}

	/**
	 * Which of these keys still exist in the bucket.
	 *
	 * Deleting an object is not proof it is gone: the provider answers a
	 * delete without confirming the result. Callers that promise the operator
	 * "已删除" use this to prove it, and a failed check is returned as an error
	 * rather than an empty list so it can never read as success.
	 *
	 * @param string[] $keys Keys.
	 * @return string[]|\WP_Error Remaining keys, or an error when the check failed.
	 */
	public function objects_exist( array $keys ) {
		$keys = array_values( array_filter( array_map( array( $this, 'normalize_key' ), $keys ) ) );
		if ( ! $keys ) {
			return array();
		}

		try {
			$store = $this->store();

			$remaining = array();

			foreach ( $keys as $key ) {
				$exists = $store->object_exists( (string) $key );

				if ( is_wp_error( $exists ) ) {
					return $exists;
				}

				if ( true === $exists ) {
					$remaining[] = (string) $key;
				}
			}

			return $remaining;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_head_failed', $e->getMessage() );
		}
	}

	/**
	 * List remote objects for the read-only inventory.
	 *
	 * @param string $prefix Object key prefix.
	 * @param int    $limit  Max objects.
	 * @return array{objects:array<int,array{key:string,size:int,modified:int}>,truncated:bool}|\WP_Error
	 */
	/**
	 * List one page of objects.
	 *
	 * @param string $prefix Prefix.
	 * @param string $token  Continuation token.
	 * @param int    $limit  Page size.
	 * @return array|\WP_Error
	 */
	public function list_page( string $prefix = '', string $token = '', int $limit = 1000 ) {
		$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
		if ( '' !== $prefix ) {
			$prefix = $this->normalize_key( $prefix );
			if ( false === $prefix ) {
				return new \WP_Error( 'sakura_s3_invalid_prefix', __( '对象前缀无效。', 'sakura-imgopt' ) );
			}
		}
		try {
			return $this->store()->list_page( $prefix, $token, $limit );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_list_failed', $e->getMessage() );
		}
	}

	public function list_objects( string $prefix = '', int $limit = 20000 ) {
		$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
		if ( '' !== $prefix ) {
			$prefix = $this->normalize_key( $prefix );
			if ( false === $prefix ) {
				return new \WP_Error( 'sakura_s3_invalid_prefix', __( '对象前缀无效。', 'sakura-imgopt' ) );
			}
		}

		try {
			return $this->store()->list_objects( $prefix, $limit );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_list_failed', $e->getMessage() );
		}
	}

	/**
	 * Return a basename-only metadata filename.
	 *
	 * @param mixed $value Metadata value.
	 * @return string
	 */
	private static function metadata_filename( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$filename = wp_basename( $value );
		return ( '' === $filename || '.' === $filename || '..' === $filename ) ? '' : $filename;
	}

	/**
	 * Build the object store once.
	 *
	 * @return Object_Store
	 */
	private function store(): Object_Store {
		if ( null === $this->store ) {
			$options = $this->options->all();
			$client  = Client_Factory::make( $options );

			$this->store = new Object_Store(
				static function () use ( $client ) {
					return $client;
				},
				static function () use ( $options ) {
					return $options;
				},
				function () {
					return $this->options->object_acl();
				},
				static function ( $message ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						do_action( 'sakura_imgopt_s3_log', preg_replace( '/[\r\n]+/', ' ', (string) $message ) );
					}
				}
			);
		}

		return $this->store;
	}
}
