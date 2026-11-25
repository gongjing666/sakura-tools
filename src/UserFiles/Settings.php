<?php
/**
 * Settings and the single source of defaults for the user file space module.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `sufm_settings` (autoload off) and clamps every value.
 *
 * PROPOSAL.md §11.2 requires one constant for every default; activation, the
 * UI and the runtime all read it from here. A per-user quota override lives in
 * usermeta so the change is site-scoped and survives option resets.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'sufm_settings';

	/**
	 * Usermeta key holding a per-user quota override in bytes.
	 */
	public const QUOTA_META = 'sufm_quota_bytes';

	/**
	 * Version of the reference-impact notice shown before a request.
	 */
	public const NOTICE_VERSION = '2026-10-31';

	/**
	 * Default quota: 512 MiB.
	 */
	public const DEFAULT_QUOTA = 536870912;

	/**
	 * Default single-file limit: 50 MiB.
	 */
	public const DEFAULT_MAX_FILE = 52428800;

	/**
	 * Folder-tree looks. Windows is the default because most of these sites are
	 * administered from Windows browsers.
	 */
	public const STYLE_WINDOWS = 'windows';

	/**
	 * Finder-style look.
	 */
	public const STYLE_MAC = 'mac';

	/**
	 * Accents a folder icon may be tinted with. Empty means "use the style's
	 * own colour", which keeps the tree readable without a choice.
	 */
	private const ACCENTS = array( '', 'amber', 'blue', 'green', 'violet', 'rose', 'slate' );

	/**
	 * Preset ink/fill pairs; the same values the front-end stylesheets use.
	 *
	 * @var array<string, array{ink: string, fill: string}>
	 */
	private const ACCENT_PALETTE = array(
		'amber'  => array( 'ink' => '#c8932b', 'fill' => '#f7dc9a' ),
		'blue'   => array( 'ink' => '#3b7dd8', 'fill' => '#cfe0f7' ),
		'green'  => array( 'ink' => '#3f9b6d', 'fill' => '#cfe9db' ),
		'violet' => array( 'ink' => '#7a5bd0', 'fill' => '#ddd3f6' ),
		'rose'   => array( 'ink' => '#d4547f', 'fill' => '#f8d7e2' ),
		'slate'  => array( 'ink' => '#5c6675', 'fill' => '#dde1e7' ),
	);

	/**
	 * Settings stored in bytes but edited in megabytes.
	 */
	private const MB_FIELDS = array(
		'quota_default_bytes',
		'max_file_bytes',
		'daily_upload_bytes',
		'site_storage_budget_bytes',
	);

	/**
	 * Every default, once.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'                     => false,
			'editor_enabled'              => false,
			'quota_default_bytes'         => self::DEFAULT_QUOTA,
			'max_file_bytes'              => self::DEFAULT_MAX_FILE,
			'max_user_files'              => 1000,
			'max_user_folders'            => 200,
			'max_folder_depth'            => 8,
			'daily_upload_files'          => 100,
			'daily_upload_bytes'          => 536870912,
			'max_concurrent_uploads'      => 2,
			'upload_requests_per_minute'  => 20,
			'max_pending_delete_requests' => 20,
			'max_delete_request_items'    => 1000,
			'site_storage_budget_bytes'   => 0,
			'site_storage_warning_percent' => 80,
			'theme_upload_limit_ten_minutes' => 0,
			'theme_upload_limit_day'         => 0,
			'theme_upload_limit_source'      => '',
			'theme_upload_limit_synced_at'   => '',
			'allowed_types'               => array(
				'image/jpeg',
				'image/png',
				'image/webp',
				'image/gif',
				'application/pdf',
				'text/plain',
				'application/zip',
				'video/mp4',
				'audio/mpeg',
			),
			'reference_notice_version'    => self::NOTICE_VERSION,
			'tree_style'                  => self::STYLE_WINDOWS,
			'folder_accent'               => '',
		);
	}

	/**
	 * MIME labels for the default allow list.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'image/jpeg'       => __( 'JPEG 图片', 'sakura-imgopt' ),
			'image/png'        => __( 'PNG 图片', 'sakura-imgopt' ),
			'image/webp'       => __( 'WebP 图片', 'sakura-imgopt' ),
			'image/gif'        => __( 'GIF 图片', 'sakura-imgopt' ),
			'application/pdf'  => __( 'PDF 文档', 'sakura-imgopt' ),
			'text/plain'       => __( '纯文本', 'sakura-imgopt' ),
			'application/zip'  => __( 'ZIP 压缩包', 'sakura-imgopt' ),
			'video/mp4'        => __( 'MP4 视频', 'sakura-imgopt' ),
			'audio/mpeg'       => __( 'MP3 音频', 'sakura-imgopt' ),
		);
	}

	/**
	 * Effective settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Whether the front-end entry is switched on.
	 *
	 * This flag only covers the theme facing entry point (the user centre tab,
	 * the shortcode page and the self-service upload endpoints). The media
	 * library side -- virtual folders, per-owner scoping, transfer and the
	 * admin columns -- is always on: it is this module's primary job and has
	 * nothing to do with which theme the site runs.
	 *
	 * Off by default: the entry point differs per theme, so a site that has not
	 * confirmed its theme should not load any of it.
	 */
	public static function entry_enabled(): bool {
		return ! empty( self::get()['enabled'] );
	}

	/**
	 * Whether the front-end editor panel is switched on.
	 *
	 * Deliberately independent of entry_enabled(): the panel is read-only, so a
	 * site that keeps the user-centre manager closed can still let authors
	 * reuse their own media while writing.
	 */
	public static function editor_enabled(): bool {
		return ! empty( self::get()['editor_enabled'] );
	}

	/**
	 * Backwards compatible alias.
	 *
	 * @deprecated Use entry_enabled(): the library side no longer follows this flag.
	 */
	public static function enabled(): bool {
		return self::entry_enabled();
	}

	/**
	 * Clamp a settings payload.
	 *
	 * @param mixed $value Raw payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $value ): array {
		$defaults = self::defaults();
		$value    = is_array( $value ) ? $value : array();

		// The appearance card submits only its own two fields; without merging
		// the stored payload first, saving it would reset every quota and policy
		// value to the defaults.
		if ( ! empty( $value['_partial'] ) ) {
			$stored = get_option( self::OPTION, array() );
			$value  = array_merge( is_array( $stored ) ? $stored : array(), $value );
		}

		// Byte limits are entered in MB in the admin form; accept those keys and
		// convert before clamping so the stored shape stays bytes everywhere else.
		foreach ( self::MB_FIELDS as $byte_key ) {
			$mb_key = $byte_key . '_mb';

			if ( isset( $value[ $mb_key ] ) && '' !== $value[ $mb_key ] ) {
				$value[ $byte_key ] = (int) round( (float) $value[ $mb_key ] * 1048576 );
			}
		}

		$out = array();

		$out['enabled']                      = ! empty( $value['enabled'] );
		$out['editor_enabled']               = ! empty( $value['editor_enabled'] );
		$out['quota_default_bytes']          = self::clamp_bytes( $value['quota_default_bytes'] ?? $defaults['quota_default_bytes'], 1048576, 1099511627776 );
		$out['max_file_bytes']               = self::clamp_bytes( $value['max_file_bytes'] ?? $defaults['max_file_bytes'], 1024, 1073741824 );
		$out['max_user_files']               = self::clamp_int( $value['max_user_files'] ?? $defaults['max_user_files'], 1, 100000 );
		$out['max_user_folders']             = self::clamp_int( $value['max_user_folders'] ?? $defaults['max_user_folders'], 1, 10000 );
		$out['max_folder_depth']             = self::clamp_int( $value['max_folder_depth'] ?? $defaults['max_folder_depth'], 1, 32 );
		$out['daily_upload_files']           = self::clamp_int( $value['daily_upload_files'] ?? $defaults['daily_upload_files'], 1, 100000 );
		$out['daily_upload_bytes']           = self::clamp_bytes( $value['daily_upload_bytes'] ?? $defaults['daily_upload_bytes'], 1048576, 1099511627776 );
		$out['max_concurrent_uploads']        = self::clamp_int( $value['max_concurrent_uploads'] ?? $defaults['max_concurrent_uploads'], 1, 8 );
		$out['upload_requests_per_minute']   = self::clamp_int( $value['upload_requests_per_minute'] ?? $defaults['upload_requests_per_minute'], 1, 600 );
		$out['max_pending_delete_requests']  = self::clamp_int( $value['max_pending_delete_requests'] ?? $defaults['max_pending_delete_requests'], 1, 200 );
		$out['max_delete_request_items']     = self::clamp_int( $value['max_delete_request_items'] ?? $defaults['max_delete_request_items'], 1, 10000 );
		$out['site_storage_budget_bytes']    = self::clamp_bytes( $value['site_storage_budget_bytes'] ?? $defaults['site_storage_budget_bytes'], 0, 1099511627776 );
		$out['site_storage_warning_percent'] = self::clamp_int( $value['site_storage_warning_percent'] ?? $defaults['site_storage_warning_percent'], 10, 100 );

		$out['theme_upload_limit_ten_minutes'] = self::clamp_int( $value['theme_upload_limit_ten_minutes'] ?? $defaults['theme_upload_limit_ten_minutes'], 0, 100000 );
		$out['theme_upload_limit_day']         = self::clamp_int( $value['theme_upload_limit_day'] ?? $defaults['theme_upload_limit_day'], 0, 100000 );
		$out['theme_upload_limit_source']      = sanitize_key( (string) ( $value['theme_upload_limit_source'] ?? $defaults['theme_upload_limit_source'] ) );
		$out['theme_upload_limit_synced_at']   = sanitize_text_field( (string) ( $value['theme_upload_limit_synced_at'] ?? $defaults['theme_upload_limit_synced_at'] ) );

		$raw_types = $value['allowed_types'] ?? $defaults['allowed_types'];
		$raw_types = is_array( $raw_types ) ? $raw_types : array();

		$types = array();
		foreach ( $raw_types as $type ) {
			$type = strtolower( trim( (string) $type ) );
			if ( '' !== $type && preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type ) ) {
				$types[ $type ] = $type;
			}
		}

		$out['allowed_types'] = array_values( $types );

		$style                            = isset( $value['tree_style'] ) ? (string) $value['tree_style'] : $defaults['tree_style'];
		$out['tree_style']                = in_array( $style, array( self::STYLE_WINDOWS, self::STYLE_MAC ), true ) ? $style : self::STYLE_WINDOWS;

		$accent = isset( $value['folder_accent'] ) ? strtolower( trim( (string) $value['folder_accent'] ) ) : '';

		if ( preg_match( '/^#[0-9a-f]{6}$/', $accent ) ) {
			$out['folder_accent'] = $accent;
		} else {
			$accent               = sanitize_key( $accent );
			$out['folder_accent'] = in_array( $accent, self::ACCENTS, true ) ? $accent : '';
		}

		$notice                            = isset( $value['reference_notice_version'] ) ? (string) $value['reference_notice_version'] : self::NOTICE_VERSION;
		$out['reference_notice_version']   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $notice ) ? $notice : self::NOTICE_VERSION;

		return $out;
	}

	/**
	 * Bytes allowed to one user: override wins over the site default.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function quota_for( int $user_id ): int {
		// Absent meta means "inherit the default"; an explicit 0 blocks uploads.
		$has_meta = metadata_exists( 'user', $user_id, self::QUOTA_META );
		$meta     = $has_meta ? max( 0, (int) get_user_meta( $user_id, self::QUOTA_META, true ) ) : 0;

		// Expansion off: byte-for-byte identical to 1.18.49 behaviour.
		if ( ! class_exists( \Nboke\UserFiles\Space_Settings::class ) || ! \Nboke\UserFiles\Space_Settings::enabled() ) {
			return $has_meta ? $meta : (int) self::get()['quota_default_bytes'];
		}

		$limit = \Nboke\UserFiles\Services\Space_Rules::limit_for( $user_id );

		if ( ! $has_meta ) {
			return $limit;
		}

		return 'replace' === \Nboke\UserFiles\Space_Settings::mode() ? $meta : min( $meta, $limit );
	}

	/**
	 * Site-wide storage budget in bytes; 0 means "no site-wide ceiling".
	 *
	 * @return int
	 */
	public static function site_storage_budget(): int {
		return max( 0, (int) self::get()['site_storage_budget_bytes'] );
	}

	/**
	 * Is this MIME type inside the allow list? SVG/HTML never pass.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_allowed_type( string $mime ): bool {
		$mime = strtolower( trim( $mime ) );

		if ( in_array( $mime, array( 'image/svg+xml', 'text/html', 'application/xhtml+xml', 'application/javascript', 'text/javascript', 'application/x-php', 'text/x-php', 'application/x-sh' ), true ) ) {
			return false;
		}

		return in_array( $mime, self::get()['allowed_types'], true );
	}

	/**
	 * Friendly label for a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	public static function label_for( string $mime ): string {
		$labels = self::type_labels();

		return isset( $labels[ $mime ] ) ? $labels[ $mime ] : $mime;
	}

	/**
	 * Selected folder-tree style.
	 *
	 * @return string
	 */
	public static function tree_style(): string {
		return (string) self::get()['tree_style'];
	}

	/**
	 * Selected folder accent, or an empty string for "style default".
	 *
	 * @return string
	 */
	public static function folder_accent(): string {
		return (string) self::get()['folder_accent'];
	}

	/**
	 * Whether an accent is a custom #rrggbb colour rather than a preset key.
	 *
	 * @param string $accent Accent value.
	 * @return bool
	 */
	public static function is_custom_accent( string $accent ): bool {
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $accent );
	}

	/**
	 * CSS class for the current accent. Custom colours share one class because
	 * the actual colour travels as inline custom properties.
	 *
	 * @param string $accent Accent value.
	 * @return string
	 */
	public static function accent_class( string $accent ): string {
		if ( '' === $accent ) {
			return '';
		}

		return self::is_custom_accent( $accent ) ? 'sufm-accent-custom' : 'sufm-accent-' . sanitize_html_class( $accent );
	}

	/**
	 * Inline custom properties for a custom accent; empty for presets.
	 *
	 * @param string $accent Accent value.
	 * @return string
	 */
	public static function accent_style( string $accent ): string {
		if ( ! self::is_custom_accent( $accent ) ) {
			return '';
		}

		$fill = self::mix_hex( $accent, '#ffffff', 0.28 );

		return '--sufm-folder-ink:' . $accent . ';--sufm-folder-fill:' . $fill . ';';
	}

	/**
	 * Preset ink/fill pairs for the admin preview and payload.
	 *
	 * @return array<string, array{ink: string, fill: string}>
	 */
	public static function accent_palette(): array {
		return self::ACCENT_PALETTE;
	}

	/**
	 * Mix a hex colour with another colour; used to derive a light folder fill.
	 *
	 * @param string $hex   Source #rrggbb.
	 * @param string $with  Target #rrggbb.
	 * @param float  $ratio Ratio of the source colour (0..1).
	 * @return string
	 */
	private static function mix_hex( string $hex, string $with, float $ratio ): string {
		$ratio = max( 0.0, min( 1.0, $ratio ) );
		$rgb   = static function ( string $value ): array {
			return array(
				hexdec( substr( $value, 1, 2 ) ),
				hexdec( substr( $value, 3, 2 ) ),
				hexdec( substr( $value, 5, 2 ) ),
			);
		};

		$from = $rgb( $hex );
		$to   = $rgb( $with );
		$out  = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$out[] = (int) round( $from[ $i ] * $ratio + $to[ $i ] * ( 1 - $ratio ) );
		}

		return sprintf( '#%02x%02x%02x', $out[0], $out[1], $out[2] );
	}

	/**
	 * Accent choices for the admin form.
	 *
	 * @return array<string, string>
	 */
	public static function accent_choices(): array {
		return array(
			''       => __( '跟随样式', 'sakura-imgopt' ),
			'amber'  => __( '琥珀', 'sakura-imgopt' ),
			'blue'   => __( '湖蓝', 'sakura-imgopt' ),
			'green'  => __( '青竹', 'sakura-imgopt' ),
			'violet' => __( '紫藤', 'sakura-imgopt' ),
			'rose'   => __( '海棠', 'sakura-imgopt' ),
			'slate'  => __( '石墨', 'sakura-imgopt' ),
		);
	}

	/**
	 * Integer clamp helper.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max ): int {
		return (int) max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Byte clamp helper; zero stays zero when the minimum is zero.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	private static function clamp_bytes( $value, int $min, int $max ): int {
		$value = (int) $value;
		if ( $value <= 0 && 0 === $min ) {
			return 0;
		}

		return (int) max( $min, min( $max, $value ) );
	}
}
