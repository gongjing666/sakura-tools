<?php
/**
 * S3 object upload and deletion operations.
 *
 * @package Sakura_Image_Optimizer
 */

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Settings\Settings_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless object-store operations with injected client and configuration.
 */
final class Object_Store {

	/**
	 * Client provider.
	 *
	 * @var callable
	 */
	private $client;

	/**
	 * Options provider.
	 *
	 * @var callable
	 */
	private $options;

	/**
	 * ACL provider.
	 *
	 * @var callable
	 */
	private $acl;

	/**
	 * Logger.
	 *
	 * @var callable
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param callable $client  Returns a configured S3 client.
	 * @param callable $options Returns the storage options array.
	 * @param callable $acl     Returns the object ACL.
	 * @param callable $log     Receives a log message.
	 */
	public function __construct( callable $client, callable $options, callable $acl, callable $log ) {
		$this->client  = $client;
		$this->options = $options;
		$this->acl     = $acl;
		$this->log     = $log;
	}

	/**
	 * Upload one local file to the configured bucket.
	 *
	 * The caller must pass a normalized, non-empty key.
	 *
	 * @param string $key          Object key.
	 * @param string $filename     Local file.
	 * @param bool   $delete_local Delete the local file after success.
	 * @return true|\WP_Error
	 */
	public function upload_file( $key, $filename, $delete_local = false ) {
		if ( '' === $key || ! is_readable( $filename ) || ! is_file( $filename ) ) {
			return new \WP_Error( 'sakura_s3_invalid_file', __( '文件不存在、不可读或对象 Key 无效。', 'sakura-imgopt' ) );
		}

		$options = (array) call_user_func( $this->options );
		$filetype = wp_check_filetype( $filename );
		$content_type = ! empty( $filetype['type'] ) ? (string) $filetype['type'] : '';
		$size = filesize( $filename );
		if ( false === $size ) {
			return new \WP_Error( 'sakura_s3_stat_failed', __( '无法读取本地文件大小。', 'sakura-imgopt' ) );
		}
		$threshold = (int) apply_filters( 'sakura_imgopt_multipart_threshold', 16 * 1048576 );

		/**
		 * Verify each upload with a HEAD read-back.
		 *
		 * Disable for providers whose ETag is not an MD5 (for example
		 * SSE-KMS) or whose write credentials cannot call HeadObject.
		 *
		 * @param bool   $verify   Whether to verify.
		 * @param string $key      Object key.
		 * @param string $filename Local file.
		 */
		$verify = (bool) apply_filters( 'sakura_imgopt_verify_upload', true, $key, $filename );

		/**
		 * Send Content-MD5 so S3 rejects a rewritten/truncated body before it is
		 * stored. Disable for providers that reject the header.
		 *
		 * @param bool   $enabled Whether to send Content-MD5.
		 * @param string $key     Object key.
		 */
		$content_md5 = (bool) apply_filters( 'sakura_imgopt_upload_content_md5', true, $key );

		$multipart     = $size >= max( 5 * 1048576, $threshold );
		$local_md5     = '';
		$expected_etag = '';

		if ( $verify && ! $multipart ) {
			$local_md5 = md5_file( $filename );
			if ( false === $local_md5 ) {
				return new \WP_Error( 'sakura_s3_hash_failed', __( '无法计算本地文件 MD5，已取消上传。', 'sakura-imgopt' ) );
			}
			$expected_etag = $local_md5;
		}

		try {
			if ( $multipart ) {
				$expected_etag = $this->upload_multipart( $key, $filename, $options, $content_type, $content_md5 );

				/*
				 * Tencent COS (and DogeCloud, which serves COS behind its S3
				 * endpoint) returns a multipart ETag that is not the AWS
				 * md5-of-part-md5s form.  Part ETags are still MD5s and COS
				 * validates them in CompleteMultipartUpload, so the write is
				 * already checked part by part; comparing the final ETag would
				 * reject a complete object.  Keep the size check and the part
				 * validation, skip only the final ETag comparison.
				 */
				$provider             = (string) ( $options['provider'] ?? '' );
				$compare_final_etag   = (bool) apply_filters(
					'sakura_imgopt_verify_multipart_etag',
					! in_array( $provider, array( 'dogecloud', 'tencent' ), true ),
					$provider,
					$key
				);
				if ( ! $compare_final_etag ) {
					$expected_etag = '';
				}
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Small single-part uploads use a stream.
				$handle = fopen( $filename, 'rb' );
				if ( ! is_resource( $handle ) ) {
					return new \WP_Error( 'sakura_s3_open_failed', __( '无法读取本地文件。', 'sakura-imgopt' ) );
				}
				try {
					$put_args = array(
						'@retries' => 0,
						'Bucket' => (string) ( $options['bucket'] ?? '' ),
						'Key'    => $key,
						'Body'   => $handle,
						'ACL'    => (string) call_user_func( $this->acl ),
					);
					if ( '' !== $content_type ) {
						$put_args['ContentType'] = $content_type;
					}
					if ( '' !== $local_md5 && $content_md5 ) {
						// S3 rejects the request with BadDigest when the received
						// body does not match this checksum.
						$put_args['ContentMD5'] = base64_encode( (string) hex2bin( $local_md5 ) );
					}
					call_user_func( $this->client )->putObject( $put_args );
				} finally {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream created exclusively for this SDK request.
					fclose( $handle );
				}
			}
		} catch ( \Throwable $e ) {
			$mapped = Error_Mapper::to_wp_error( $e, __( '上传', 'sakura-imgopt' ) );
			call_user_func( $this->log, $mapped->get_error_message() . ' [' . $key . ']' );
			return $mapped;
		}

		if ( $verify ) {
			$checked = $this->verify_remote( $key, $size, $expected_etag, $options );
			if ( is_wp_error( $checked ) ) {
				// H-02: a failed HEAD/compare is not proof that the object is
				// bad. Permissions, a proxy or a transient error can break the
				// check while the bytes are complete. Deleting here would turn
				// a verification problem into permanent data loss, so the object
				// is kept, the local file stays, and the caller retries.
				call_user_func(
					$this->log,
					sprintf( /* translators: 1: object key, 2: verification error. */ __( '上传后校验失败，已保留对象 [%1$s]：%2$s', 'sakura-imgopt' ), $key, $checked->get_error_message() )
				);
				return $checked;
			}
		}

		/**
		 * Fires after an object was written and verified.
		 *
		 * @param string $key      Object key.
		 * @param string $filename Local file.
		 * @param string $md5      Local MD5 for single-part uploads, else ''.
		 * @param string $etag     ETag used for verification.
		 */
		do_action( 'sakura_imgopt_s3_uploaded', $key, $filename, $local_md5, $expected_etag );

		// Only remove the local copy after a confirmed remote success.
		if ( $delete_local && ! \Sakura\ImageOptimizer\Filesystem\File_System::delete( $filename ) ) {
			call_user_func( $this->log, sprintf( /* translators: %s: local file path. */ __( '远端上传成功，但本地文件删除失败：%s', 'sakura-imgopt' ), $filename ) );
		}
		return true;
	}

	/**
	 * Upload several files, optionally with bounded parallelism.
	 *
	 * Every file keeps the PUT -> HEAD order of upload_file(). Large files
	 * stay sequential (multipart uses the same handle for every part), while
	 * small single-part files may run concurrently through the SDK's cURL
	 * multi handler. The call returns only after every in-flight operation
	 * settled, so a request never ends with an orphan upload.
	 *
	 * @param array<int,array{key:string,path:string}> $files       Files to upload.
	 * @param int                                      $concurrency Effective 1-4.
	 * @return array<string,true|\WP_Error> Results keyed by object key.
	 */
	public function upload_many( array $files, int $concurrency = 1 ): array {
		$results    = array();
		$serial     = array();
		$parallel_files = array();
		$threshold  = (int) apply_filters( 'sakura_imgopt_multipart_threshold', 16 * 1048576 );
		$parallel   = Upload_Capabilities::supports_parallel() && $concurrency > 1;

		foreach ( $files as $file ) {
			$key  = (string) ( $file['key'] ?? '' );
			$path = (string) ( $file['path'] ?? '' );
			if ( '' === $key || '' === $path ) {
				continue;
			}
			$size = is_file( $path ) ? (int) filesize( $path ) : 0;
			if ( ! $parallel || $size >= max( 5 * 1048576, $threshold ) ) {
				$serial[ $key ] = $path;
				continue;
			}
			$parallel_files[ $key ] = $path;
		}

		foreach ( $serial as $key => $path ) {
			$results[ $key ] = $this->upload_file( $key, $path, false );
		}

		if ( $parallel && $parallel_files ) {
			$promises = ( function () use ( $parallel_files ) {
				foreach ( $parallel_files as $key => $path ) {
					// EachPromise expects a promise here. Yielding a callable
					// would resolve immediately with the closure itself, so the
					// upload would never be dispatched.
					yield $key => $this->upload_parallel_file( (string) $key, (string) $path );
				}
			} )();

			$each = new \Sakura\Vendor\GuzzleHttp\Promise\EachPromise(
				$promises,
				array(
					'concurrency' => max( 1, min( Settings_Repository::MAX_UPLOAD_CONCURRENCY, $concurrency ) ),
					'fulfilled'   => static function ( $value, $key ) use ( &$results ): void {
						$results[ (string) $key ] = true === $value ? true : $value;
					},
					'rejected'    => static function ( $reason, $key ) use ( &$results ): void {
						$results[ (string) $key ] = $reason instanceof \WP_Error
							? $reason
							: new \WP_Error( 'sakura_s3_upload_failed', (string) $reason );
					},
				)
			);
			$each->promise()->wait();
		}

		return $results;
	}

	/**
	 * One single-part upload as a promise (PUT, then HEAD verification).
	 *
	 * @param string $key      Object key.
	 * @param string $filename Local file.
	 * @return \Sakura\Vendor\GuzzleHttp\Promise\PromiseInterface
	 */
	private function upload_parallel_file( string $key, string $filename ) {
		if ( ! is_readable( $filename ) || ! is_file( $filename ) ) {
			return \Sakura\Vendor\GuzzleHttp\Promise\Create::rejectionFor(
				new \WP_Error( 'sakura_s3_invalid_file', __( '文件不存在、不可读或对象 Key 无效。', 'sakura-imgopt' ) )
			);
		}

		$options = (array) call_user_func( $this->options );
		$size    = (int) filesize( $filename );
		$md5     = md5_file( $filename );
		if ( false === $md5 ) {
			return \Sakura\Vendor\GuzzleHttp\Promise\Create::rejectionFor(
				new \WP_Error( 'sakura_s3_hash_failed', __( '无法计算本地文件 MD5，已取消上传。', 'sakura-imgopt' ) )
			);
		}

		$content_type = (string) ( wp_check_filetype( $filename )['type'] ?? '' );
		$content_md5  = (bool) apply_filters( 'sakura_imgopt_upload_content_md5', true, $key );
		$verify       = (bool) apply_filters( 'sakura_imgopt_verify_upload', true, $key, $filename );

		$args = array(
			'@retries' => 0,
			'Bucket' => (string) ( $options['bucket'] ?? '' ),
			'Key'    => $key,
			'ACL'    => (string) call_user_func( $this->acl ),
		);
		if ( '' !== $content_type ) {
			$args['ContentType'] = $content_type;
		}
		if ( $content_md5 ) {
			$args['ContentMD5'] = base64_encode( (string) hex2bin( $md5 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- One stream per in-flight request.
		$handle = fopen( $filename, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return \Sakura\Vendor\GuzzleHttp\Promise\Create::rejectionFor(
				new \WP_Error( 'sakura_s3_open_failed', __( '无法读取本地文件。', 'sakura-imgopt' ) )
			);
		}
		$args['Body'] = $handle;

		try {
			$client  = call_user_func( $this->client );
			$promise = $client->executeAsync( $client->getCommand( 'PutObject', $args ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream created above.
			fclose( $handle );
			return \Sakura\Vendor\GuzzleHttp\Promise\Create::rejectionFor( Error_Mapper::to_wp_error( $e, __( '上传', 'sakura-imgopt' ) ) );
		}

		return $promise->then(
			function ( $result ) use ( $handle, $client, $key, $filename, $size, $md5, $verify, $options ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The PUT has been sent.
				fclose( $handle );
				if ( ! $verify ) {
					do_action( 'sakura_imgopt_s3_uploaded', $key, $filename, $md5, $md5 );
					return true;
				}
				try {
					$head = $client->headObject(
						array(
							'Bucket' => (string) ( $options['bucket'] ?? '' ),
							'Key'    => $key,
						)
					);
				} catch ( \Throwable $e ) {
					return Error_Mapper::to_wp_error( $e, __( '上传后校验', 'sakura-imgopt' ) );
				}
				$mismatch = Upload_Verify::compare(
					$size,
					(int) ( $head['ContentLength'] ?? -1 ),
					$md5,
					(string) ( $head['ETag'] ?? '' )
				);
				if ( null !== $mismatch ) {
					call_user_func( $this->log, $mismatch . ' [' . $key . ']' );
					return new \WP_Error( 'sakura_s3_verify_failed', $mismatch );
				}
				do_action( 'sakura_imgopt_s3_uploaded', $key, $filename, $md5, $md5 );
				return true;
			},
			function ( $reason ) use ( $handle, $key ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream after a failed PUT.
				fclose( $handle );
				$error = $reason instanceof \Throwable
					? Error_Mapper::to_wp_error( $reason, __( '上传', 'sakura-imgopt' ) )
					: new \WP_Error( 'sakura_s3_upload_failed', (string) $reason );
				call_user_func( $this->log, $error->get_error_message() . ' [' . $key . ']' );
				return \Sakura\Vendor\GuzzleHttp\Promise\Create::rejectionFor( $error );
			}
		);
	}

	/**
	 * Upload a file with S3 multipart upload.
	 *
	 * @param string               $key          Object key.
	 * @param string               $filename     Local file.
	 * @param array<string,mixed>  $options      Storage options.
	 * @param string               $content_type MIME type.
	 * @return string Expected multipart ETag (`md5-of-part-md5s-N`), or ''.
	 */
	private function upload_multipart( string $key, string $filename, array $options, string $content_type, bool $content_md5 = true ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read one bounded part at a time.
		$handle = fopen( $filename, 'rb' );
		if ( ! is_resource( $handle ) ) {
			throw new \RuntimeException( 'Unable to open file for multipart upload.' );
		}

		$bucket  = (string) ( $options['bucket'] ?? '' );
		$part_size = max( 5 * 1048576, (int) apply_filters( 'sakura_imgopt_multipart_part_size', 8 * 1048576 ) );
		$part_size = min( $part_size, 100 * 1048576 );
		$upload_id = '';
		$parts     = array();
		$part_md5s = array();

		try {
			$create = call_user_func( $this->client )->createMultipartUpload(
				array(
					'@retries' => 0,
					'Bucket'      => $bucket,
					'Key'         => $key,
					'ACL'         => (string) call_user_func( $this->acl ),
					'ContentType' => $content_type,
				)
			);
			$upload_id = (string) ( $create['UploadId'] ?? '' );
			if ( '' === $upload_id ) {
				throw new \RuntimeException( 'S3 did not return a multipart UploadId.' );
			}

			$part_number = 1;
			while ( ! feof( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded part size.
				$part = fread( $handle, $part_size );
				if ( false === $part ) {
					throw new \RuntimeException( 'Unable to read multipart part.' );
				}
				if ( '' === $part ) {
					break;
				}

				$part_md5   = md5( $part );
				$part_args  = array(
					'@retries' => 0,
					'Bucket'     => $bucket,
					'Key'        => $key,
					'UploadId'   => $upload_id,
					'PartNumber' => $part_number,
					'Body'       => $part,
				);
				if ( $content_md5 ) {
					$part_args['ContentMD5'] = base64_encode( (string) hex2bin( $part_md5 ) );
				}
				$part_result = call_user_func( $this->client )->uploadPart( $part_args );
				$etag = (string) ( $part_result['ETag'] ?? '' );
				if ( '' === $etag ) {
					throw new \RuntimeException( 'S3 did not return a multipart ETag.' );
				}
				$parts[]     = array(
					'PartNumber' => $part_number,
					'ETag'       => $etag,
				);
				$part_md5s[] = $part_md5;
				++$part_number;
			}

			if ( ! $parts ) {
				throw new \RuntimeException( 'No multipart parts were uploaded.' );
			}

			call_user_func( $this->client )->completeMultipartUpload(
				array(
					'@retries' => 0,
					'Bucket'          => $bucket,
					'Key'             => $key,
					'UploadId'        => $upload_id,
					'MultipartUpload' => array( 'Parts' => $parts ),
				)
			);
		} catch ( \Throwable $e ) {
			if ( '' !== $upload_id ) {
				try {
					call_user_func( $this->client )->abortMultipartUpload(
						array(
							'@retries' => 0,
							'Bucket'   => $bucket,
							'Key'      => $key,
							'UploadId' => $upload_id,
						)
					);
				} catch ( \Throwable $abort ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort abort; original failure is more useful.
					// Best effort; preserve the original exception.
				}
			}
			throw $e;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the stream created exclusively for this request.
			fclose( $handle );
		}

		return Upload_Verify::multipart_etag( $part_md5s );
	}

	/**
	 * HEAD the uploaded object and compare Content-Length/ETag.
	 *
	 * @param string              $key           Object key.
	 * @param int                 $size          Local size.
	 * @param string              $expected_etag Expected ETag.
	 * @param array<string,mixed> $options       Storage options.
	 * @return true|\WP_Error
	 */
	private function verify_remote( string $key, int $size, string $expected_etag, array $options ) {
		try {
			$head = call_user_func( $this->client )->headObject(
				array(
					'Bucket' => (string) ( $options['bucket'] ?? '' ),
					'Key'    => $key,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'sio_upload_head_failed',
				sprintf(
					/* translators: %s: error message. */
					__( '上传后无法 HEAD 校验对象：%s', 'sakura-imgopt' ),
					$e->getMessage()
				),
				array( 'key' => $key )
			);
		}

		$remote_size = (int) ( $head['ContentLength'] ?? -1 );
		$remote_etag = (string) ( $head['ETag'] ?? '' );
		$problem     = Upload_Verify::compare( $size, $remote_size, $expected_etag, $remote_etag );

		if ( null !== $problem ) {
			return new \WP_Error( 'sio_upload_verify_failed', $problem, array( 'key' => $key ) );
		}

		return true;
	}

	/**
	 * List objects under a prefix.
	 *
	 * Read-only inventory call used by the sync center. It never mutates the
	 * bucket and is intentionally bounded so a huge bucket cannot exhaust the
	 * admin request.
	 *
	 * @param string $prefix Object key prefix.
	 * @param int    $limit  Maximum objects to return.
	 * @return array{objects:array<int,array{key:string,size:int,modified:int,etag?:string}>,next_token:string,truncated:bool}|\WP_Error
	 */
	public function list_page( string $prefix = '', string $token = '', int $limit = 1000 ) {
		$limit   = max( 1, min( 1000, $limit ) );
		$options = (array) call_user_func( $this->options );
		$bucket  = (string) ( $options['bucket'] ?? '' );
		$objects = array();
		try {
			$args = array(
				'Bucket'  => $bucket,
				'Prefix'  => $prefix,
				'MaxKeys' => $limit,
			);
			if ( '' !== $token ) {
				$args['ContinuationToken'] = $token;
			}
			$result = call_user_func( $this->client )->listObjectsV2( $args );
			foreach ( (array) ( $result['Contents'] ?? array() ) as $item ) {
				$key = (string) ( $item['Key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$modified = $item['LastModified'] ?? 0;
				if ( $modified instanceof \DateTimeInterface ) {
					$modified = $modified->getTimestamp();
				} elseif ( is_string( $modified ) && '' !== $modified ) {
					$modified = (int) strtotime( $modified );
				} else {
					$modified = 0;
				}
				$etag = (string) ( $item['ETag'] ?? '' );
				$objects[] = array(
					'key'      => $key,
					'size'     => max( 0, (int) ( $item['Size'] ?? 0 ) ),
					'modified' => (int) $modified,
					'etag'     => trim( $etag, '"' ),
				);
			}
			$next = (string) ( $result['NextContinuationToken'] ?? '' );
			return array(
				'objects'    => $objects,
				'next_token' => $next,
				'truncated'  => (bool) ( $result['IsTruncated'] ?? false ) || '' !== $next,
			);
		} catch ( \Throwable $e ) {
			$mapped = Error_Mapper::to_wp_error( $e, __( '列举对象', 'sakura-imgopt' ) );
			call_user_func( $this->log, $mapped->get_error_message() );
			return $mapped;
		}
	}

	/**
	 * List objects (legacy helper that materialises the full result).
	 *
	 * @param string $prefix Prefix.
	 * @param int    $limit  Max objects.
	 * @return array{objects:array<int,array{key:string,size:int,modified:int,etag?:string}>,truncated:bool}|\WP_Error
	 */
	public function list_objects( string $prefix = '', int $limit = 20000 ) {
		$limit   = max( 1, min( 100000, $limit ) );
		$objects = array();
		$token   = '';
		do {
			$page = $this->list_page( $prefix, $token, 1000 );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['objects'] as $item ) {
				$objects[] = $item;
				if ( count( $objects ) >= $limit ) {
					return array( 'objects' => $objects, 'next_token' => $token, 'truncated' => true );
				}
			}
			$token = (string) $page['next_token'];
		} while ( '' !== $token );
		return array( 'objects' => $objects, 'next_token' => '', 'truncated' => false );
	}

	/**
	 * Download one object to a local file without loading it into memory.
	 *
	 * @param string $key      Object key.
	 * @param string $filename Destination path.
	 * @return true|\WP_Error
	 */
	public function download_file( $key, $filename ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return new \WP_Error( 'sakura_s3_invalid_key', __( '对象 Key 无效。', 'sakura-imgopt' ) );
		}

		$dir = dirname( $filename );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'sakura_s3_mkdir_failed', __( '无法创建本地目录。', 'sakura-imgopt' ) );
		}
		if ( file_exists( $filename ) ) {
			return new \WP_Error( 'sakura_s3_file_exists', __( '本地文件已存在，已取消下载以免覆盖。', 'sakura-imgopt' ) );
		}

		$options  = (array) call_user_func( $this->options );
		$tmp      = $dir . '/.sakura-download-' . wp_generate_password( 16, false, false ) . '.tmp';
		$out      = null;
		$expected = -1;

		try {
			$result = call_user_func( $this->client )->getObject(
				array(
					'Bucket' => (string) ( $options['bucket'] ?? '' ),
					'Key'    => $key,
				)
			);

			$expected = isset( $result['ContentLength'] ) ? (int) $result['ContentLength'] : -1;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a downloaded object to disk.
			$out = fopen( $tmp, 'wb' );
			if ( ! is_resource( $out ) ) {
				throw new \RuntimeException( 'Unable to open the temporary download file.' );
			}

			$body = $result['Body'] ?? null;
			if ( is_resource( $body ) ) {
				$copied = stream_copy_to_stream( $body, $out );
				if ( false === $copied ) {
					throw new \RuntimeException( 'Unable to stream the object body.' );
				}
				fclose( $body );
			} elseif ( is_object( $body ) && method_exists( $body, 'read' ) ) {
				while ( ! $body->eof() ) {
					$chunk = $body->read( 1048576 );
					if ( '' === $chunk ) {
						break;
					}
					if ( false === fwrite( $out, $chunk ) ) {
						throw new \RuntimeException( 'Unable to write the downloaded object.' );
					}
				}
				if ( method_exists( $body, 'close' ) ) {
					$body->close();
				}
			} elseif ( false === fwrite( $out, (string) $body ) ) {
				throw new \RuntimeException( 'Unable to write the downloaded object.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the destination stream.
			fclose( $out );
			$out = null;

			// A truncated response must not be published as a good file
			// (audit A06): compare the byte count with Content-Length when the
			// storage layer reported one.
			clearstatcache( true, $tmp );
			if ( $expected >= 0 && (int) filesize( $tmp ) !== $expected ) {
				return new \WP_Error(
					'sakura_s3_size_mismatch',
					__( '下载的文件大小与远端声明不一致，已放弃保存。', 'sakura-imgopt' )
				);
			}

			// Publish without clobbering (audit A05). rename() silently replaces
			// a file that appeared while the download was running, so claim the
			// final name with an exclusive create instead.
			$handle = null;
			set_error_handler( static function (): bool { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contained E_WARNING probe.
				return true;
			} );
			try {
				$handle = fopen( $filename, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic claim of the destination.
			} finally {
				restore_error_handler();
			}

			if ( ! is_resource( $handle ) ) {
				return new \WP_Error(
					'sakura_s3_file_exists',
					__( '本地文件已存在（下载期间被创建），已保留原文件。', 'sakura-imgopt' )
				);
			}

			$in = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Copies the downloaded temp file.
			if ( ! is_resource( $in ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the claimed destination.
				fclose( $handle );
				wp_delete_file( $filename );
				throw new \RuntimeException( 'Unable to reopen the downloaded file.' );
			}

			$copied = stream_copy_to_stream( $in, $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the source stream.
			fclose( $in );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the destination stream.
			fclose( $handle );

			if ( false === $copied ) {
				wp_delete_file( $filename );
				throw new \RuntimeException( 'Unable to move the downloaded file into place.' );
			}

			unlink( $tmp );
			return true;
		} catch ( \Throwable $e ) {
			$mapped = Error_Mapper::to_wp_error( $e, __( '下载', 'sakura-imgopt' ) );
			call_user_func( $this->log, $mapped->get_error_message() . ' [' . $key . ']' );
			return $mapped;
		} finally {
			if ( is_resource( $out ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a failed temporary stream.
				fclose( $out );
			}
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
		}
	}

	/**
	 * Create a short-lived signed URL for one object.
	 *
	 * The SDK signs locally; no network request is made. Callers must treat the
	 * URL as a bearer credential and never log it.
	 *
	 * @param string     $key         Object key.
	 * @param string|int $expires     Expiry accepted by the SDK, e.g. '+15 minutes'.
	 * @param string     $disposition Optional Content-Disposition override.
	 * @param string     $content_type Optional Content-Type override.
	 * @return string|\WP_Error
	 */
	public function presigned_url( $key, $expires = '+15 minutes', $disposition = '', $content_type = '' ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return new \WP_Error( 'sakura_s3_invalid_key', __( '对象 Key 无效。', 'sakura-imgopt' ) );
		}

		$options = (array) call_user_func( $this->options );
		$args    = array(
			'Bucket' => (string) ( $options['bucket'] ?? '' ),
			'Key'    => $key,
		);
		if ( '' !== $disposition ) {
			$args['ResponseContentDisposition'] = $disposition;
		}
		if ( '' !== $content_type ) {
			$args['ResponseContentType'] = $content_type;
		}

		try {
			$client  = call_user_func( $this->client );
			$command = $client->getCommand( 'GetObject', $args );
			$request = $client->createPresignedRequest( $command, $expires );
			return (string) $request->getUri();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sakura_s3_presign_failed', $e->getMessage() );
		}
	}

	/**
	 * Delete object keys in chunks accepted by the S3 API.
	 *
	 * @param string[] $keys Normalized object keys.
	 * @return bool|\WP_Error
	 */
	public function delete_keys( array $keys ) {
		$keys = array_values( array_filter( array_map( 'strval', $keys ) ) );
		if ( ! $keys ) {
			return true;
		}
		try {
			$options = (array) call_user_func( $this->options );
			$client  = call_user_func( $this->client );
			$bucket  = (string) ( $options['bucket'] ?? '' );
			foreach ( array_chunk( $keys, 1000 ) as $chunk ) {
				try {
					$response = $client->deleteObjects( array(
						'@retries' => 0,
						'Bucket' => $bucket,
						'Delete' => array(
							'Objects' => array_map( static fn( $key ) => array( 'Key' => $key ), $chunk ),
							'Quiet' => true,
						),
					) );
				} catch ( \Throwable $e ) {
					/*
					 * Tencent COS (and DogeCloud behind it) rejects DeleteObjects
					 * with "Missing required header ... Content-MD5". Single-object
					 * DeleteObject needs no such header, so fall back per key
					 * instead of leaving every deletion stuck in "uncertain".
					 */
					if ( false === stripos( $e->getMessage(), 'Content-MD5' ) ) {
						throw $e;
					}
					call_user_func( $this->log, __( '批量删除被服务端拒绝（要求 Content-MD5），改为逐对象删除。', 'sakura-imgopt' ) );
					foreach ( $chunk as $key ) {
						$client->deleteObject( array(
							'@retries' => 0,
							'Bucket' => $bucket,
							'Key' => $key,
						) );
					}
					continue;
				}
				$errors = (array) ( $response['Errors'] ?? array() );
				if ( $errors ) {
					return new \WP_Error( 'sio_delete_partial', __( '云端返回部分删除失败，已停止后续删除并等待核对。', 'sakura-imgopt' ), array( 'errors' => $errors ) );
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			call_user_func( $this->log, __( '远端删除结果未确认，停止自动回退：', 'sakura-imgopt' ) . $e->getMessage() );
			return new \WP_Error( 'sio_delete_uncertain', $e->getMessage() );
		}
	}

	/**
	 * Whether one object still exists in the bucket.
	 *
	 * A 404 is the only answer that means "gone"; any other failure is returned
	 * as an error so a caller never mistakes a network problem for a completed
	 * delete.
	 *
	 * @param string $key Normalized object key.
	 * @return bool|\WP_Error
	 */
	public function object_exists( string $key ) {
		if ( '' === $key ) {
			return false;
		}

		try {
			$options = (array) call_user_func( $this->options );
			$client  = call_user_func( $this->client );

			$client->headObject(
				array(
					'Bucket' => (string) ( $options['bucket'] ?? '' ),
					'Key'    => $key,
				)
			);

			return true;
		} catch ( \Sakura\Vendor\Aws\S3\Exception\S3Exception $e ) {
			$missing = 404 === (int) $e->getStatusCode();

			if ( ! $missing ) {
				$missing = in_array( (string) $e->getAwsErrorCode(), array( 'NoSuchKey', 'NotFound' ), true );
			}

			return $missing ? false : new \WP_Error( 'sio_head_failed', $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'sio_head_failed', $e->getMessage() );
		}
	}
}
