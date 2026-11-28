<?php
/**
 * Per-card data and save path for the file-space settings page.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Nboke\UserFiles\Admin;

use Nboke\UserFiles\Frontend\Providers\Generic;
use Nboke\UserFiles\Services\Theme_Limits;
use Nboke\UserFiles\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One owner for the shape and the write path of the two editable cards.
 *
 * The first paint and the REST response share data(), so a saved card can never
 * disagree with the payload the same page loaded with.
 */
final class Settings_Cards {

	/**
	 * Folder appearance card.
	 */
	public const APPEARANCE = 'appearance';

	/**
	 * Upload and quota policy card.
	 */
	public const SETTINGS = 'settings';

	/**
	 * Settings stored in bytes but edited in megabytes.
	 *
	 * @var array<int,string>
	 */
	private const MB_FIELDS = array(
		'quota_default_bytes',
		'max_file_bytes',
		'daily_upload_bytes',
		'site_storage_budget_bytes',
	);

	/**
	 * Integer settings edited as whole numbers.
	 *
	 * @var array<int,string>
	 */
	private const INT_FIELDS = array(
		'max_user_files',
		'max_user_folders',
		'max_folder_depth',
		'daily_upload_files',
		'max_concurrent_uploads',
		'upload_requests_per_minute',
		'site_storage_warning_percent',
		'max_pending_delete_requests',
		'max_delete_request_items',
		'theme_upload_limit_ten_minutes',
		'theme_upload_limit_day',
	);

	/**
	 * Editable card ids.
	 *
	 * @return array<int,string>
	 */
	public static function cards(): array {
		return array( self::APPEARANCE, self::SETTINGS );
	}

	/**
	 * Card data in the shape both payload and save response use.
	 *
	 * @param string              $card     Card id.
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<string,mixed>
	 */
	public static function data( string $card, array $settings ): array {
		if ( self::SETTINGS !== $card ) {
			return array(
				'values'        => self::appearance_values( $settings ),
				'styles'        => self::styles(),
				'accentChoices' => self::accent_choices(),
				'current'       => self::appearance_current( $settings ),
			);
		}

		return array(
			'values'       => self::policy_values( $settings ),
			'groups'       => self::policy_groups(),
			'themeLimits'  => self::theme_limits( $settings ),
			'allowedTypes' => self::allowed_types( $settings ),
		);
	}

	/**
	 * Save one card and return the data the page replaces it with.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( string $card, array $fields ): array|WP_Error {
		if ( ! in_array( $card, self::cards(), true ) ) {
			return new WP_Error(
				'sakura_settings_card',
				__( '未知的设置卡片。', 'sakura-imgopt' ),
				array( 'status' => 400 )
			);
		}

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$clean  = self::clean_fields( $card, $fields );

		$settings = Settings::sanitize( array_merge( Settings::defaults(), $stored, $clean ) );

		update_option( Settings::OPTION, $settings, false );

		/*
		 * update_option_sufm_settings also writes the theme upload limits back and
		 * refreshes source/synced_at, so read the stored value instead of returning
		 * the array that was just written.
		 */
		$fresh = Settings::get();
		$data  = self::data( $card, $fresh );

		Generic::flush_page_cache();

		return array(
			'card'     => $card,
			'sections' => array( $card => $data ),
			'notice'   => self::notice( $card, $clean, $data['values'] ),
		);
	}

	/**
	 * Keep only the fields the card owns.
	 *
	 * @param string              $card   Card id.
	 * @param array<string,mixed> $fields Submitted fields.
	 * @return array<string,mixed>
	 */
	private static function clean_fields( string $card, array $fields ): array {
		$clean = array();

		if ( self::APPEARANCE === $card ) {
			foreach ( array( 'tree_style', 'folder_accent' ) as $key ) {
				if ( array_key_exists( $key, $fields ) ) {
					$clean[ $key ] = is_scalar( $fields[ $key ] ) ? (string) $fields[ $key ] : '';
				}
			}

			return $clean;
		}

		foreach ( self::INT_FIELDS as $key ) {
			if ( array_key_exists( $key, $fields ) && is_scalar( $fields[ $key ] ) && '' !== (string) $fields[ $key ] ) {
				$clean[ $key ] = $fields[ $key ];
			}
		}

		foreach ( self::MB_FIELDS as $key ) {
			$mb_key = $key . '_mb';

			// `is_scalar` must run before the string comparison: a posted array
			// would otherwise raise "Array to string conversion" and be stored as
			// the literal "Array". Non-scalars are treated as a missing field.
			if ( array_key_exists( $mb_key, $fields ) && is_scalar( $fields[ $mb_key ] ) && '' !== (string) $fields[ $mb_key ] ) {
				$clean[ $mb_key ] = $fields[ $mb_key ];
			}
		}

		if ( array_key_exists( 'allowed_types', $fields ) ) {
			$types                  = is_array( $fields['allowed_types'] ) ? $fields['allowed_types'] : array();
			$clean['allowed_types'] = array_values(
				array_filter(
					array_map( 'strval', array_filter( $types, 'is_scalar' ) ),
					static fn( string $type ): bool => '' !== $type
				)
			);
		}

		return $clean;
	}

	/**
	 * Appearance values in form units.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<string,string>
	 */
	private static function appearance_values( array $settings ): array {
		return array(
			'tree_style'    => 'mac' === (string) ( $settings['tree_style'] ?? 'windows' ) ? 'mac' : 'windows',
			'folder_accent' => (string) ( $settings['folder_accent'] ?? '' ),
		);
	}

	/**
	 * Policy values in form units, keyed like the field descriptors.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<string,mixed>
	 */
	private static function policy_values( array $settings ): array {
		$values = array();

		foreach ( self::INT_FIELDS as $key ) {
			$values[ $key ] = (string) (int) ( $settings[ $key ] ?? 0 );
		}

		foreach ( self::MB_FIELDS as $key ) {
			$values[ $key . '_mb' ] = (string) round( (int) ( $settings[ $key ] ?? 0 ) / 1048576, 2 );
		}

		$types = is_array( $settings['allowed_types'] ?? null ) ? $settings['allowed_types'] : array();

		$values['allowed_types'] = array_values( array_map( 'strval', $types ) );

		return $values;
	}

	/**
	 * Appearance labels used by the card summary.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<string,mixed>
	 */
	private static function appearance_current( array $settings ): array {
		$accent  = (string) ( $settings['folder_accent'] ?? '' );
		$custom  = Settings::is_custom_accent( $accent );
		$style   = 'mac' === (string) ( $settings['tree_style'] ?? 'windows' ) ? 'mac' : 'windows';
		$labels  = Settings::accent_choices();
		$palette = Settings::accent_palette();

		return array(
			'treeStyle'    => $style,
			'folderAccent' => $accent,
			'isCustom'     => $custom,
			'customColor'  => $custom ? $accent : ( isset( $palette[ $accent ]['ink'] ) ? (string) $palette[ $accent ]['ink'] : '#c8932b' ),
			'styleLabel'   => 'mac' === $style ? __( 'macOS Finder', 'sakura-imgopt' ) : __( 'Windows 11', 'sakura-imgopt' ),
			'accentLabel'  => $custom ? __( '自定义颜色', 'sakura-imgopt' ) : (string) ( $labels[ $accent ] ?? $labels[''] ),
		);
	}

	/**
	 * Folder style choices.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function styles(): array {
		return array(
			array(
				'value'       => 'windows',
				'label'       => __( 'Windows 11', 'sakura-imgopt' ),
				'description' => __( '圆角实心文件夹、深浅两级配色，接近资源管理器的观感。', 'sakura-imgopt' ),
			),
			array(
				'value'       => 'mac',
				'label'       => __( 'macOS Finder', 'sakura-imgopt' ),
				'description' => __( '描边文件夹、更轻的层次和字重，接近访达的观感。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Accent choices, style default first.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function accent_choices(): array {
		$labels  = Settings::accent_choices();
		$palette = Settings::accent_palette();
		$choices = array(
			array(
				'value' => '',
				'label' => (string) ( $labels[''] ?? __( '跟随样式', 'sakura-imgopt' ) ),
				'ink'   => '',
				'fill'  => '',
			),
		);

		foreach ( $labels as $key => $label ) {
			if ( '' === $key ) {
				continue;
			}

			$choices[] = array(
				'value' => (string) $key,
				'label' => (string) $label,
				'ink'   => isset( $palette[ $key ]['ink'] ) ? (string) $palette[ $key ]['ink'] : '',
				'fill'  => isset( $palette[ $key ]['fill'] ) ? (string) $palette[ $key ]['fill'] : '',
			);
		}

		return $choices;
	}

	/**
	 * Policy groups: labels and bounds only, values travel in `values`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function policy_groups(): array {
		return array(
			array(
				'title'  => __( '容量与数量', 'sakura-imgopt' ),
				'hint'   => __( '决定每个用户能存多少、能建多少。', 'sakura-imgopt' ),
				'fields' => array(
					self::field( 'quota_default_bytes_mb', __( '默认单人容量（MB）', 'sakura-imgopt' ), __( '默认 512，用户可在「用户容量」里单独调整。', 'sakura-imgopt' ), 1, 1048576 ),
					self::field( 'max_file_bytes_mb', __( '单文件上限（MB）', 'sakura-imgopt' ), __( '默认 50。', 'sakura-imgopt' ), 1, 1024 ),
					self::field( 'max_user_files', __( '每人文件数', 'sakura-imgopt' ), __( '超过后不能再上传。', 'sakura-imgopt' ), 1, 100000 ),
					self::field( 'max_user_folders', __( '每人文件夹数', 'sakura-imgopt' ), '', 1, 10000 ),
					self::field( 'max_folder_depth', __( '目录层级', 'sakura-imgopt' ), __( '根目录为第 1 层。', 'sakura-imgopt' ), 1, 32 ),
				),
			),
			array(
				'title'  => __( '上传频率与全站预算', 'sakura-imgopt' ),
				'hint'   => __( '防止集中占用带宽与服务端资源。', 'sakura-imgopt' ),
				'fields' => array(
					self::field( 'daily_upload_files', __( '每日上传文件数', 'sakura-imgopt' ), '', 1, 100000 ),
					self::field( 'daily_upload_bytes_mb', __( '每日上传量（MB）', 'sakura-imgopt' ), __( '按 UTC 自然日累计。', 'sakura-imgopt' ), 1, 1048576 ),
					self::field( 'max_concurrent_uploads', __( '并发上传数', 'sakura-imgopt' ), __( '前端同时上传的通道数。', 'sakura-imgopt' ), 1, 8 ),
					self::field( 'upload_requests_per_minute', __( '每分钟上传请求', 'sakura-imgopt' ), '', 1, 600 ),
					self::field( 'site_storage_budget_bytes_mb', __( '全站容量预算（MB）', 'sakura-imgopt' ), __( '0 表示不启用全站预算。', 'sakura-imgopt' ), 0, 1048576 ),
					self::field( 'site_storage_warning_percent', __( '预算告警阈值', 'sakura-imgopt' ), __( '百分比，达到后提示管理员。', 'sakura-imgopt' ), 10, 100 ),
				),
			),
			array(
				'title'  => __( '删除申请', 'sakura-imgopt' ),
				'hint'   => __( '限制用户提交的审核单数量与体量。', 'sakura-imgopt' ),
				'fields' => array(
					self::field( 'max_pending_delete_requests', __( '待处理申请上限', 'sakura-imgopt' ), __( '每人最多同时存在的待审核申请。', 'sakura-imgopt' ), 1, 200 ),
					self::field( 'max_delete_request_items', __( '单个申请对象上限', 'sakura-imgopt' ), __( '一个申请最多包含的文件和文件夹数量。', 'sakura-imgopt' ), 1, 10000 ),
				),
			),
		);
	}

	/**
	 * One numeric field descriptor.
	 *
	 * @param string $key   Form key.
	 * @param string $label Label.
	 * @param string $hint  Hint.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return array<string,mixed>
	 */
	private static function field( string $key, string $label, string $hint, int $min, int $max ): array {
		return array(
			'key'   => $key,
			'id'    => 'sakura-' . str_replace( '_', '-', $key ),
			'label' => $label,
			'hint'  => $hint,
			'min'   => (string) $min,
			'max'   => (string) $max,
		);
	}

	/**
	 * Theme upload limits, or null when the active theme has none.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<string,mixed>|null
	 */
	private static function theme_limits( array $settings ): ?array {
		$theme = Theme_Limits::detect();

		if ( ! is_array( $theme ) ) {
			return null;
		}

		return array(
			'source'   => (string) ( $theme['label'] ?? '' ),
			'syncedAt' => (string) ( $settings['theme_upload_limit_synced_at'] ?? '' ),
			'fields'   => array(
				self::field(
					'theme_upload_limit_ten_minutes',
					__( '每 10 分钟上传文件数', 'sakura-imgopt' ),
					__( '与主题「10 分钟」上限一致；0 表示不限制。', 'sakura-imgopt' ),
					0,
					100000
				),
				self::field(
					'theme_upload_limit_day',
					__( '每天上传文件数', 'sakura-imgopt' ),
					__( '与主题「每天」上限一致；0 表示不限制。', 'sakura-imgopt' ),
					0,
					100000
				),
			),
		);
	}

	/**
	 * Allow-list choices with their current state.
	 *
	 * @param array<string,mixed> $settings Sanitised settings.
	 * @return array<int,array<string,mixed>>
	 */
	private static function allowed_types( array $settings ): array {
		$current = is_array( $settings['allowed_types'] ?? null ) ? $settings['allowed_types'] : array();
		$rows    = array();

		foreach ( Settings::type_labels() as $mime => $label ) {
			$rows[] = array(
				'mime'    => (string) $mime,
				'label'   => (string) $label,
				'checked' => in_array( (string) $mime, $current, true ),
			);
		}

		return $rows;
	}

	/**
	 * Post-save notice; a clamped value turns the tone to warn.
	 *
	 * @param string              $card      Card id.
	 * @param array<string,mixed> $requested Submitted fields.
	 * @param array<string,mixed> $stored    Stored values in form units.
	 * @return array<string,string>
	 */
	private static function notice( string $card, array $requested, array $stored ): array {
		$title = self::APPEARANCE === $card ? __( '样式已保存', 'sakura-imgopt' ) : __( '策略已保存', 'sakura-imgopt' );

		if ( self::differs( $card, $requested, $stored ) ) {
			return array(
				'tone'    => 'warn',
				'kicker'  => 'SAVED',
				'title'   => $title,
				'message' => __( '部分值超出允许范围，已按边界保存。', 'sakura-imgopt' ),
			);
		}

		return array(
			'tone'    => 'ok',
			'kicker'  => 'SAVED',
			'title'   => $title,
			'message' => self::APPEARANCE === $card
				? __( '媒体库与前台文件管理会立即使用新的样式。', 'sakura-imgopt' )
				: __( '新策略已对新请求生效。', 'sakura-imgopt' ),
		);
	}

	/**
	 * Whether the stored values differ from what was submitted.
	 *
	 * @param string              $card      Card id.
	 * @param array<string,mixed> $requested Submitted fields.
	 * @param array<string,mixed> $stored    Stored values in form units.
	 * @return bool
	 */
	private static function differs( string $card, array $requested, array $stored ): bool {
		foreach ( $requested as $key => $value ) {
			if ( 'allowed_types' === $key ) {
				$left  = array_map( 'strval', (array) $value );
				$right = array_map( 'strval', (array) ( $stored[ $key ] ?? array() ) );
				sort( $left );
				sort( $right );

				if ( $left !== $right ) {
					return true;
				}

				continue;
			}

			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}

			if ( self::APPEARANCE === $card ) {
				if ( (string) $value !== (string) $stored[ $key ] ) {
					return true;
				}

				continue;
			}

			$is_mb = str_ends_with( (string) $key, '_mb' );
			$left  = $is_mb ? round( (float) $value, 2 ) : (float) $value;
			$right = $is_mb ? round( (float) $stored[ $key ], 2 ) : (float) $stored[ $key ];

			if ( $left !== $right ) {
				return true;
			}
		}

		return false;
	}
}
