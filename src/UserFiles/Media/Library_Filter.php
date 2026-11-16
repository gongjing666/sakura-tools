<?php
/**
 * SQL predicates for the native media library; WP keeps pagination and other filters.
 *
 * @package Sakura_Image_Optimizer
 */
declare( strict_types=1 );

namespace Nboke\UserFiles\Media;

use Nboke\UserFiles\Schema;

defined( 'ABSPATH' ) || exit;

final class Library_Filter {
	/**
	 * Build a conjunction without materialising or truncating attachment IDs.
	 * Scope is constructed server-side by Media_Hooks, never accepted as raw SQL.
	 *
	 * @param array<string,mixed> $scope Validated scope.
	 */
	public static function where( array $scope ): string {
		global $wpdb;
		$files    = Schema::table( 'files' );
		$items    = Schema::table( 'delete_request_items' );
		$requests = Schema::table( 'delete_requests' );
		$post_id  = $wpdb->posts . '.ID';
		$parts    = array();
		$managed  = "sf.attachment_id = {$post_id} AND sf.lifecycle <> 'purged'";
		$owner    = max( 0, (int) ( $scope['owner'] ?? 0 ) );
		$source   = (string) ( $scope['source'] ?? '' );
		$status   = (string) ( $scope['status'] ?? '' );
		$request  = max( 0, (int) ( $scope['request'] ?? 0 ) );

		if ( ! empty( $scope['invalid'] ) ) {
			return ' AND 1=0';
		}
		if ( $owner > 0 ) {
			$parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$files} sf WHERE {$managed} AND sf.owner_id=%d)", $owner );
		}
		if ( ! empty( $scope['unassigned'] ) || 'wordpress' === $source ) { // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stored source slug, not prose.
			$parts[] = "NOT EXISTS (SELECT 1 FROM {$files} sf WHERE {$managed})";
		}
		if ( in_array( $source, array( 'userfiles', 'media' ), true ) ) {
			$parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$files} sf WHERE {$managed} AND sf.source=%s)", $source );
		}
		if ( isset( $scope['folder_ids'] ) ) {
			$ids = array_values( array_unique( array_map( 'absint', (array) $scope['folder_ids'] ) ) );
			if ( ! $ids || $owner <= 0 ) {
				return ' AND 1=0';
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$parts[] = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$files} sf WHERE {$managed} AND sf.owner_id=%d AND sf.folder_id IN ({$placeholders}))",
				array_merge( array( $owner ), $ids )
			);
		}
		if ( $request > 0 ) {
			$parts[] = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$items} si WHERE si.attachment_id={$post_id} AND si.object_type='file' AND si.request_id=%d)",
				$request
			);
		}
		if ( 'none' === $status ) {
			$parts[] = "NOT EXISTS (SELECT 1 FROM {$items} si WHERE si.attachment_id={$post_id} AND si.object_type='file')";
		} elseif ( '' !== $status ) {
			$parts[] = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$items} si INNER JOIN {$requests} sr ON sr.id=si.request_id WHERE si.attachment_id={$post_id} AND si.object_type='file' AND sr.status=%s AND NOT EXISTS (SELECT 1 FROM {$items} sn INNER JOIN {$requests} rn ON rn.id=sn.request_id WHERE sn.attachment_id=si.attachment_id AND sn.object_type='file' AND rn.id>sr.id))",
				$status
			);
		}
		return $parts ? ' AND (' . implode( ') AND (', $parts ) . ')' : '';
	}
}
