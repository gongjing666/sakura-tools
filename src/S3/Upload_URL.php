<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the upload_url_path / upload_path takeover lifecycle. */
final class Upload_URL {

	const PREVIOUS_URL_OPTION  = 'sakura_imgopt_previous_upload_url_path';
	const PREVIOUS_PATH_OPTION = 'sakura_imgopt_previous_upload_path';

	/** Enable S3 media URL and remember the previous WordPress value. */
	public static function apply( $upload_url ): void {
		$upload_url = untrailingslashit( (string) $upload_url );
		if ( '' === $upload_url ) {
			return;
		}

		$current = untrailingslashit( (string) get_option( 'upload_url_path', '' ) );
		if ( $upload_url === $current ) {
			return;
		}

		if ( null === get_option( self::PREVIOUS_URL_OPTION, null ) ) {
			add_option( self::PREVIOUS_URL_OPTION, $current, '', false );
		}
		update_option( 'upload_url_path', $upload_url, false );
	}

	/** Restore only when the current value is still the managed one. */
	public static function restore( $managed_url = '' ): void {
		$managed_url = untrailingslashit( (string) $managed_url );
		$current     = untrailingslashit( (string) get_option( 'upload_url_path', '' ) );
		$previous    = get_option( self::PREVIOUS_URL_OPTION, null );

		if ( '' !== $managed_url && $managed_url === $current ) {
			update_option( 'upload_url_path', null === $previous ? '' : (string) $previous, false );
		}
		delete_option( self::PREVIOUS_URL_OPTION );
	}
}
