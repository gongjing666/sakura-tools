<?php
/**
 * Observes completed uploads without scheduling or executing transfers.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;
use Sakura\ImageOptimizer\Sync\Checksums;
use Sakura\ImageOptimizer\Sync\Index_Repository;

defined( 'ABSPATH' ) || exit;

/** Keeps upload-time metrics and index/MD5 recording separate from workers. */
final class Upload_Observer implements Module {
	private Options $options;
	private Upload_Metrics $metrics;

	public function __construct( ?Options $options = null, ?Upload_Metrics $metrics = null ) {
		$this->options = $options ?? new Options();
		$this->metrics = $metrics ?? new Upload_Metrics();
	}

	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'sakura_imgopt_s3_uploaded', array( $this, 'record_uploaded' ), 10, 4 );
		$hooks->add_action( 'sakura_imgopt_s3_upload_failed', array( $this, 'record_failed' ), 10, 4 );
	}

	/**
	 * Record the MD5 already computed by Object_Store; never start a transfer.
	 *
	 * @param string $key Object key.
	 * @param string $filename Local path.
	 * @param string $md5 MD5, empty for multipart uploads.
	 * @param string $etag Remote ETag.
	 */
	public function record_uploaded( $key, $filename, $md5, $etag = '' ): void {
		$key     = (string) $key;
		$md5     = strtolower( (string) $md5 );
		$profile = (string) $this->options->active_profile_id();
		if ( '' === $key || '' === $profile ) {
			return;
		}
		$bytes = is_string( $filename ) && is_file( $filename ) ? (int) filesize( $filename ) : 0;
		$this->metrics->record_batch(
			array(
				array( 'ok' => true, 'ms' => 0, 'bytes' => $bytes, 'attachment' => 0, 'key' => $key, 'profile' => $profile ),
			)
		);
		$file = is_string( $filename ) ? $filename : '';
		if ( '' !== $file && is_file( $file ) ) {
			$index = new Index_Repository();
			if ( $index->record_uploaded( $profile, $key, $file, (int) filesize( $file ), (int) filemtime( $file ), $md5, (string) $etag ) ) {
				return;
			}
		}
		if ( Checksums::is_md5_etag( $md5 ) ) {
			( new Index_Repository() )->record_md5( $profile, $key, $md5 );
		}
	}

	/**
	 * Persist a failed transfer as evidence, including its attachment when known.
	 *
	 * Failures are not skipped when the key is empty or the profile is missing:
	 * a rejected path is exactly the kind of evidence the old option lost.
	 *
	 * @param string         $key           Object key.
	 * @param string         $filename      Local path.
	 * @param \WP_Error|mixed $error         Transfer error.
	 * @param int            $attachment_id Attachment ID, or 0.
	 */
	public function record_failed( $key, $filename, $error, $attachment_id = 0 ): void {
		$key      = (string) $key;
		$filename = is_string( $filename ) ? $filename : '';
		$profile  = (string) $this->options->active_profile_id();

		if ( is_wp_error( $error ) ) {
			$code    = (string) $error->get_error_code();
			$message = (string) $error->get_error_message();
		} else {
			$code    = 'sakura_s3_upload_failed';
			$message = is_string( $error ) ? $error : '';
		}

		$bytes = '' !== $filename && is_file( $filename ) ? (int) filesize( $filename ) : 0;
		$this->metrics->record_batch(
			array(
				array(
					'ok'         => false,
					'ms'         => 0,
					'bytes'      => $bytes,
					'error'      => $message,
					'error_code' => $code,
					'attachment' => (int) $attachment_id,
					'key'        => $key,
					'profile'    => $profile,
				),
			)
		);
	}
}
