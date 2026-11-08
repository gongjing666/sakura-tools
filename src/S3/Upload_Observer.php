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
				array( 'ok' => true, 'ms' => 0, 'bytes' => $bytes, 'attachment' => 0, 'key' => $key ),
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
}
