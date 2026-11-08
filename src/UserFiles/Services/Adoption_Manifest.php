<?php
/** Trusted, read-only artifact inventory for first adoption. */
declare( strict_types=1 );
namespace Nboke\UserFiles\Services;
use WP_Error;
defined( 'ABSPATH' ) || exit;
final class Adoption_Manifest {

	public function build( int $attachment_id ) {
		global $wpdb;
		$metadata = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $attachment_id ) ) as $row ) { $metadata[ $row->meta_key ] = maybe_unserialize( $row->meta_value ); }
		if ( $wpdb->last_error ) { return $this->error(); }
		$uploads = wp_upload_dir();
		$base = realpath( (string) $uploads['basedir'] );
		$original = (string) ( $metadata['_wp_attached_file'] ?? '' );
		if ( '' !== $original && ! path_is_absolute( $original ) ) { $original = (string) $uploads['basedir'] . '/' . $original; }
		if ( ! $base || ! is_file( $original ) ) { return $this->error(); }
		$paths = array( $original => 'original' );
		$meta = $metadata['_wp_attachment_metadata'] ?? array();
		$dir = dirname( $original );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) ) { $paths[ $base . '/' . ltrim( (string) $meta['file'], '/' ) ] = 'original'; }
			if ( ! empty( $meta['original_image'] ) ) { $paths[ $dir . '/' . (string) $meta['original_image'] ] = 'original_image'; }
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
				if ( ! is_array( $size ) || empty( $size['file'] ) ) { return $this->error(); }
				$paths[ $dir . '/' . (string) $size['file'] ] = 'size';
			}
		}
		foreach ( (array) ( $metadata['_wp_attachment_backup_sizes'] ?? array() ) as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) { return $this->error(); }
			$paths[ $dir . '/' . (string) $size['file'] ] = 'backup';
		}
		$artifacts = array();
		foreach ( $paths as $path => $kind ) {
			$real = realpath( $path );
			if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) { return $this->error(); }
			$part      = $path;
			$base_len  = strlen( $base );
			$part_len  = strlen( $part );
			while ( $part_len > $base_len ) {
				if ( is_link( $part ) ) { return $this->error(); }
				$parent = dirname( $part );
				if ( $parent === $part ) { break; }
				$part     = $parent;
				$part_len = strlen( $part );
			}
			clearstatcache( true, $real );
			$bytes = filesize( $real );
			$checksum = hash_file( 'sha256', $real );
			if ( false === $bytes || false === $checksum ) { return $this->error(); }
			$artifacts[ $real ] = array( 'local_path' => $real, 'kind' => $kind, 'logical_bytes' => (int) $bytes, 'checksum' => $checksum, 'remote_context' => '' );
		}
		ksort( $artifacts );
		return array_values( $artifacts );
	}
	private function error(): WP_Error {
		return new WP_Error( 'sufm_manifest_unproven', __( '无法完整核验该附件的文件、插件元数据及制品清单，未修改原附件。请先修复缺失文件或使用对应清单适配器。', 'sakura-imgopt' ), array( 'status' => 409 ) );
	}
}
