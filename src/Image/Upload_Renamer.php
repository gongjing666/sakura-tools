<?php
/**
 * Upload filename renaming and attachment title synchronization.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Image;

use Sakura\ImageOptimizer\Contracts\File_System_Interface;
use Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the configured rename rule before/after WordPress moves an upload.
 */
final class Upload_Renamer {

	/**
	 * Renamed filename stem awaiting attachment-title sync.
	 *
	 * @var string
	 */
	private string $renamed_title = '';

	/**
	 * Filesystem boundary.
	 *
	 * @var File_System_Interface
	 */
	private File_System_Interface $files;

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository_Interface
	 */
	private Settings_Repository_Interface $settings;

	/**
	 * Constructor.
	 *
	 * @param File_System_Interface                $files    Filesystem repository.
	 * @param Settings_Repository_Interface|null   $settings Settings repository.
	 */
	public function __construct( File_System_Interface $files, ?Settings_Repository_Interface $settings = null ) {
		$this->files    = $files;
		$this->settings = $settings ?? new Settings_Repository();
	}

	/**
	 * Delete the replaced original file.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public function delete_replaced_file( string $path ): bool {
		return $this->files->delete( $path );
	}

	/**
	 * Rename before WordPress moves the uploaded file.
	 *
	 * @param array $file Upload item.
	 * @return array
	 */
	public function pre_upload_rename( $file ) {
		$enabled = (bool) $this->settings->rename_enabled();
		if ( ! $enabled ) {
			return $file;
		}

		$rule = $this->settings->rename_rule();
		if ( empty( $rule ) || ! isset( $file['name'] ) ) {
			return $file;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$new_name = $this->generate_renamed_filename( (string) $file['name'], $tmp_name );
		if ( '' === $new_name ) {
			return $file;
		}

		$this->renamed_title = pathinfo( $new_name, PATHINFO_FILENAME );
		$file['name']        = $new_name;
		return $file;
	}

	/**
	 * Fallback rename after the file has been moved into uploads.
	 *
	 * @param array $upload Upload result (passed by reference).
	 * @return string Final path.
	 */
	public function ensure_uploaded_file_renamed( &$upload ) {
		if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload['file'] ?? '';
		}

		$enabled = (bool) $this->settings->rename_enabled();
		if ( ! $enabled ) {
			return $upload['file'];
		}

		$current_path   = $upload['file'];
		$current_name   = wp_basename( $current_path );
		$generated_name = $this->generate_renamed_filename( $current_name, (string) $current_path );
		if ( '' === $generated_name || $generated_name === $current_name ) {
			return $current_path;
		}

		$target_path = trailingslashit( dirname( $current_path ) ) . $generated_name;
		$target_path = wp_unique_filename( dirname( $current_path ), wp_basename( $target_path ) );
		$target_path = trailingslashit( dirname( $current_path ) ) . $target_path;

		if ( ! $this->files->move( $current_path, $target_path ) ) {
			return $current_path;
		}

		$upload['file'] = $target_path;
		if ( ! empty( $upload['url'] ) ) {
			$upload['url'] = str_replace( $current_name, wp_basename( $target_path ), $upload['url'] );
		}
		$this->renamed_title = pathinfo( wp_basename( $target_path ), PATHINFO_FILENAME );

		return $target_path;
	}

	/**
	 * Sync the media-library title/slug with the renamed file.
	 *
	 * @param array $data Attachment data.
	 * @param array $postarr Raw attachment data.
	 * @param array $unsanitized_postarr Unslashed attachment data.
	 * @param bool  $update Whether this is an update.
	 * @return array
	 */
	public function sync_attachment_title( $data, $postarr, $unsanitized_postarr = array(), $update = false ) {
		unset( $postarr, $unsanitized_postarr );

		if ( $update || '' === $this->renamed_title ) {
			return $data;
		}

		if ( ( $data['post_type'] ?? '' ) !== 'attachment' ) {
			return $data;
		}

		$title = sanitize_text_field( $this->renamed_title );
		if ( '' === $title ) {
			return $data;
		}

		$data['post_title']  = $title;
		$data['post_name']   = sanitize_title( $title );
		$this->renamed_title = '';

		return $data;
	}

	/**
	 * Generate a filename from the rename rule.
	 *
	 * @param string $filename Original filename.
	 * @param string $source   Path to the uploaded bytes, for `{hash}` and `{wxh}`.
	 * @return string
	 */
	private function generate_renamed_filename( string $filename, string $source = '' ): string {
		$rule = $this->settings->rename_rule();
		if ( empty( $rule ) ) {
			return '';
		}

		$info = pathinfo( $filename );
		$ext  = isset( $info['extension'] ) ? strtolower( (string) $info['extension'] ) : '';
		$stem = (string) $info['filename'];

		// 压缩包的复合扩展名（backup.tar.gz）当成一个整体，否则 `{ext}` 只会
		// 留下 `.gz`，{Date}-{rand} 这样的规则会把 `.tar` 一起丢掉。
		if ( preg_match( '/\.(tar\.(?:gz|bz2|xz|zst|lz))$/i', $filename, $compound ) ) {
			$ext  = strtolower( $compound[1] );
			$stem = substr( $filename, 0, -strlen( '.' . $compound[1] ) );
		}

		// 没有扩展名就保持原文件名：重命名会把文件的类型线索一起弄丢。
		if ( '' === $ext ) {
			return '';
		}

		$original_name = $stem;

		$replacers = array(
			'{filename}' => $original_name,
			'{ext}'      => $ext,
			'{date}'     => current_time( 'Ymd' ),
			'{time}'     => current_time( 'His' ),
			'{year}'     => current_time( 'Y' ),
			'{month}'    => current_time( 'm' ),
			'{day}'      => current_time( 'd' ),
			'{yy}'       => current_time( 'y' ),
			'{week}'     => current_time( 'W' ),
			'{dow}'      => current_time( 'N' ),
			'{ts}'       => (string) time(),
			'{rand}'     => self::random_string( 5 ),
			'{rand8}'    => self::random_string( 8 ),
			// 只有规则里真的用到才去读文件：给几百 MB 的视频算 md5 会拖慢上传。
			'{hash}'     => str_contains( $rule, '{hash}' ) ? self::content_hash( $source ) : '',
			'{wxh}'      => str_contains( $rule, '{wxh}' ) ? self::image_size_token( $source, $ext ) : '',
			'{user}'     => self::uploader_token(),
			'{fixed}'    => $this->settings->rename_fixed(),
		);

		// `{fixed}`、`{user}`、`{hash}`、`{wxh}` 可能是空串（没填、没登录、
		// 拿不到临时文件），空值时连它一侧的连接符一起去掉，
		// 免得规则里留下 `sakura--20261121` 这样的双分隔符。
		$rule = self::prune_empty_tokens( $rule, $replacers );

		$new_name = str_replace( array_keys( $replacers ), array_values( $replacers ), $rule );
		$new_name = sanitize_file_name( $new_name );
		if ( empty( $new_name ) ) {
			$new_name = $original_name;
		}

		if ( ! str_ends_with( strtolower( $new_name ), '.' . $ext ) ) {
			$new_name .= '.' . $ext;
		}

		return $new_name;
	}

	/**
	 * Drop empty placeholders together with one adjacent divider.
	 *
	 * Only tokens whose value is an empty string are touched, so a rule may mix
	 * filled and unfilled placeholders without leaving doubled separators.
	 *
	 * @param string                $rule   Rename rule.
	 * @param array<string, string> $tokens Token => replacement value.
	 * @return string
	 */
	private static function prune_empty_tokens( string $rule, array $tokens ): string {
		$pruned = false;

		foreach ( $tokens as $token => $value ) {
			if ( '' !== $value ) {
				continue;
			}

			if ( str_contains( $rule, $token ) ) {
				$pruned = true;
			}

			// 只吃掉占位符**前面**那一个连接符：两侧都吃会把邻居粘起来
			// （`{hash}-{wxh}-{filename}` 会变成 `{hash}{filename}`）。
			$rule = (string) preg_replace( '/[-_.]?' . preg_quote( $token, '/' ) . '/', '', $rule );
		}

		if ( ! $pruned ) {
			return $rule;
		}

		// 删完之后可能留下 `--` 或首尾悬挂的连接符，收拢成一个再修边。
		$rule = (string) preg_replace( '/([-_.])[-_.]+/', '$1', $rule );

		return (string) preg_replace( '/^[-_.]+|[-_.]+$/', '', $rule );
	}

	/**
	 * First eight hex characters of the file's md5, for content-addressed names.
	 *
	 * Uploads are not all images — a video or an archive can be hundreds of
	 * megabytes, and md5-ing that on every upload would stall the request, so
	 * anything past 32 MB just drops the token instead.
	 *
	 * @param string $source Path to the uploaded bytes.
	 * @return string Empty when the bytes are unreadable or too large.
	 */
	private static function content_hash( string $source ): string {
		if ( '' === $source || ! is_readable( $source ) ) {
			return '';
		}

		$bytes = @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Local temp file; an unreadable size just drops the token.
		if ( is_int( $bytes ) && $bytes > 32 * 1024 * 1024 ) {
			return '';
		}

		$hash = @md5_file( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_md5_file -- Local temp file; unreadable bytes just drop the token.

		return is_string( $hash ) ? substr( $hash, 0, 8 ) : '';
	}

	/**
	 * `1920x1080` from the uploaded image.
	 *
	 * Only still images carry a size the filename can describe; videos, PDFs and
	 * archives return an empty token, which prune_empty_tokens() then removes
	 * together with its divider.
	 *
	 * @param string $source Path to the uploaded bytes.
	 * @param string $ext    Lowercase extension of the upload.
	 * @return string Empty when the dimensions cannot be read (SVG, video, archive).
	 */
	private static function image_size_token( string $source, string $ext ): string {
		$image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'apng', 'bmp', 'tiff', 'tif', 'ico' );
		if ( ! in_array( $ext, $image_exts, true ) || '' === $source || ! is_readable( $source ) ) {
			return '';
		}

		$size = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Local temp file; unreadable bytes just drop the token.

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return '';
		}

		return (int) $size[0] . 'x' . (int) $size[1];
	}

	/**
	 * Current uploader's login, reduced to filename-safe ASCII.
	 *
	 * @return string Empty for guests (front-end uploads, cron, CLI).
	 */
	private static function uploader_token(): string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return '';
		}

		$user  = wp_get_current_user();
		$login = ( is_object( $user ) && isset( $user->user_login ) ) ? (string) $user->user_login : '';
		$login = (string) preg_replace( '/[^A-Za-z0-9_-]+/', '', $login );

		return trim( substr( $login, 0, 20 ), '-_' );
	}

	/**
	 * Example values for the placeholder preview under the settings field.
	 *
	 * Mirrors the replacers in generate_renamed_filename() so the preview and a
	 * real upload never disagree about which tokens exist.
	 *
	 * @param string $fixed Current value of the user-defined `{fixed}` text.
	 * @return array<string, string>
	 */
	public static function preview_tokens( string $fixed = '' ): array {
		$uploader = self::uploader_token();

		return array(
			'{filename}' => 'sakura',
			'{ext}'      => 'webp',
			'{date}'     => (string) current_time( 'Ymd' ),
			'{time}'     => (string) current_time( 'His' ),
			'{year}'     => (string) current_time( 'Y' ),
			'{month}'    => (string) current_time( 'm' ),
			'{day}'      => (string) current_time( 'd' ),
			'{yy}'       => (string) current_time( 'y' ),
			'{week}'     => (string) current_time( 'W' ),
			'{dow}'      => (string) current_time( 'N' ),
			'{ts}'       => (string) time(),
			'{rand}'     => 'Kx7Qm',
			'{rand8}'    => 'Kx7Qm9Ta',
			'{hash}'     => 'a3f19c7d',
			'{wxh}'      => '1920x1080',
			'{user}'     => '' === $uploader ? 'author' : $uploader,
			'{fixed}'    => $fixed,
		);
	}

	/**
	 * Render a rule with the example values, for the live preview.
	 *
	 * Same trailing-extension rule as generate_renamed_filename(): a rule that
	 * never mentions {ext} still produces a name with one.
	 *
	 * @param string $rule  Rename rule.
	 * @param string $fixed Current value of the user-defined `{fixed}` text.
	 * @return string Example filename, extension included.
	 */
	public static function preview_filename( string $rule, string $fixed = '' ): string {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			$rule = '{date}-{time}-{rand}';
		}

		$tokens = self::preview_tokens( $fixed );

		// 与 generate_renamed_filename() 一致：空占位符连连接符一起去掉。
		$rule = self::prune_empty_tokens( $rule, $tokens );

		$name = str_replace( array_keys( $tokens ), array_values( $tokens ), $rule );
		$name = sanitize_file_name( $name );
		if ( '' === $name ) {
			$name = 'sakura';
		}

		if ( 'webp' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$name .= '.webp';
		}

		return $name;
	}

	/**
	 * Random lowercase/uppercase/digits string without ambiguous characters.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random_string( int $length = 5 ): string {
		$chars  = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$max    = strlen( $chars ) - 1;
		$result = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$result .= $chars[ random_int( 0, $max ) ];
		}
		return $result;
	}
}
