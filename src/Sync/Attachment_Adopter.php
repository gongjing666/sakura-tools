<?php
/**
 * Restore media-library records for files that already exist.
 *
 * Objects can reach the uploads directory without an attachment row: a bulk
 * download from the bucket, an older deletion that left files behind, or a
 * conversion that wrote a sibling file. The reconciliation screen counts
 * files, the media library counts attachments, so the gap is invisible until
 * someone compares the two numbers.
 *
 * This class closes that gap in one direction only — it never deletes and
 * never overwrites an existing attachment. Callers decide when to adopt.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Adopt existing upload files into the media library.
 */
final class Attachment_Adopter {

	/**
	 * Uploads prefix used by the sync index keys.
	 */
	private const KEY_PREFIX = 'wp-content/uploads/';
	private const DERIVED_SCALED = '-(scaled|rotated)[.][A-Za-z0-9]+$';
	private const DERIVED_SIZED  = '-[0-9]+x[0-9]+[.][A-Za-z0-9]+$';
	private const DERIVED_EDITED = '-e[0-9]{10,}[.][A-Za-z0-9]+$';

	/**
	 * Index table.
	 *
	 * @var string
	 */
	private string $index;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->index = $wpdb->prefix . 'sio_sync_index';
	}

	/**
	 * Convert an object key to a path relative to the uploads directory.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public function relative( string $key ): string {
		$key = ltrim( trim( $key ), '/' );
		return 0 === strpos( $key, self::KEY_PREFIX ) ? substr( $key, strlen( self::KEY_PREFIX ) ) : $key;
	}

	/**
	 * Absolute path for a key, or an empty string when it escapes uploads.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public function absolute( string $key ): string {
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		$rel     = $this->relative( $key );
		if ( '' === $base || '' === $rel || false !== strpos( $rel, '..' ) ) {
			return '';
		}
		return trailingslashit( $base ) . $rel;
	}

	/**
	 * Attachment id already pointing at this key, or 0.
	 *
	 * @param string $key Object key.
	 * @return int
	 */
	public function attachment_id( string $key ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
				$this->relative( $key )
			)
		);
	}

	/**
	 * Whether a key can be adopted: inside uploads, file present, mime allowed.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	public function can_adopt( string $key ): bool {
		if ( $this->attachment_id( $key ) > 0 ) {
			return false;
		}
		$path = $this->absolute( $key );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		return '' !== $this->mime( $path );
	}

	/**
	 * Resolve an allowed mime type for a local file.
	 *
	 * @param string $path Absolute path.
	 * @return string Empty string when WordPress would not accept the file.
	 */
	private function mime( string $path ): string {
		$allowed = get_allowed_mime_types();
		$check   = wp_check_filetype_and_ext( $path, wp_basename( $path ), $allowed );
		$type    = isset( $check['type'] ) ? (string) $check['type'] : '';
		if ( '' === $type ) {
			$guessed = wp_check_filetype( wp_basename( $path ), $allowed );
			$type    = isset( $guessed['type'] ) ? (string) $guessed['type'] : '';
		}
		return $type;
	}

	/**
	 * Create the attachment row (and metadata) for an existing file.
	 *
	 * @param string $key     Object key.
	 * @param int    $user_id Post author; 0 keeps the attachment unowned.
	 * @return int|\WP_Error Attachment id on success.
	 */
	public function adopt( string $key, int $user_id = 0 ) {
		$existing = $this->attachment_id( $key );
		if ( $existing > 0 ) {
			return $existing;
		}

		$path = $this->absolute( $key );
		$rel  = $this->relative( $key );
		if ( '' === $path || ! is_file( $path ) ) {
			return new \WP_Error( 'sakura_adopt_missing', __( '本地文件不存在，无法恢复媒体库记录。', 'sakura-imgopt' ) );
		}

		$type = $this->mime( $path );
		if ( '' === $type ) {
			return new \WP_Error( 'sakura_adopt_type', __( '文件类型不在允许列表中，未恢复媒体库记录。', 'sakura-imgopt' ) );
		}

		// Files restored from the bucket are article attachments, not file-space
		// uploads: keep them out of the user quota ledger.
		add_filter( 'sufm_skip_attachment_adoption', '__return_true' );
		// Restoring a row is a local repair: do not offload every generated
		// size while the attachment is created.
		add_filter( 'sakura_imgopt_s3_skip_finalize', '__return_true' );

		$uploads = wp_upload_dir( null, false );
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';

		$data = array(
			'post_mime_type' => $type,
			'post_title'     => sanitize_text_field( wp_basename( $rel ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => max( 0, $user_id ),
			'guid'           => trailingslashit( $baseurl ) . $rel,
		);

		$id = wp_insert_attachment( $data, $path, 0, true );
		remove_filter( 'sufm_skip_attachment_adoption', '__return_true' );

		if ( is_wp_error( $id ) ) {
			remove_filter( 'sakura_imgopt_s3_skip_finalize', '__return_true' );
			return $id;
		}

		update_post_meta( (int) $id, '_wp_attached_file', $rel );

		if ( wp_attachment_is_image( $id ) ) {
			$meta = wp_generate_attachment_metadata( (int) $id, $path );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( (int) $id, $meta );
			}
		}

		remove_filter( 'sakura_imgopt_s3_skip_finalize', '__return_true' );

		return (int) $id;
	}

	/**
	 * Local files indexed without an attachment row.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,string> Keys relative to uploads.
	 */
	public function missing( int $limit = 100 ): array {
		global $wpdb;
		$profile = ( new \Sakura\ImageOptimizer\S3\Options() )->active_profile_id();
		if ( '' === $profile ) {
			return array();
		}

		$like = self::KEY_PREFIX . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT SUBSTRING(i.object_key, %d) FROM {$this->index} i
				LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_wp_attached_file' AND pm.meta_value=SUBSTRING(i.object_key, %d)
				WHERE i.profile_id=%s AND i.seen_local=1 AND i.object_key LIKE %s AND pm.post_id IS NULL
				AND i.object_key NOT REGEXP %s
				AND i.object_key NOT REGEXP %s
				AND i.object_key NOT REGEXP %s
				ORDER BY i.object_key ASC LIMIT %d",
				strlen( self::KEY_PREFIX ) + 1,
				strlen( self::KEY_PREFIX ) + 1,
				$profile,
				$like,
				self::DERIVED_SCALED,
				self::DERIVED_SIZED,
				self::DERIVED_EDITED,
				max( 1, $limit )
			)
		);

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Count of indexed local files without an attachment row.
	 *
	 * @return int
	 */
	public function count_missing(): int {
		global $wpdb;
		$profile = ( new \Sakura\ImageOptimizer\S3\Options() )->active_profile_id();
		if ( '' === $profile ) {
			return 0;
		}

		$like = self::KEY_PREFIX . '%';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->index} i
				LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_key='_wp_attached_file' AND pm.meta_value=SUBSTRING(i.object_key, %d)
				WHERE i.profile_id=%s AND i.seen_local=1 AND i.object_key LIKE %s AND pm.post_id IS NULL
				AND i.object_key NOT REGEXP %s
				AND i.object_key NOT REGEXP %s
				AND i.object_key NOT REGEXP %s",
				strlen( self::KEY_PREFIX ) + 1,
				$profile,
				$like,
				self::DERIVED_SCALED,
				self::DERIVED_SIZED,
				self::DERIVED_EDITED
			)
		);
	}
}
