<?php
/**
 * Per-card settings writes for the image screens.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;

use Sakura\ImageOptimizer\Image\Crop_Config_Scanner;
use Sakura\ImageOptimizer\Settings\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Saves one settings card at a time, over REST instead of options.php.
 *
 * The card boundary — a card only writes the options it owns — is what
 * guard_options() does on the form path. Validation has to live here as well:
 * register_setting() runs on admin_init, and an admin_init hook never fires on
 * a REST request, so update_option() reaches the database without the module's
 * sanitize_* callbacks. clamp() mirrors those callbacks for the options these
 * cards write; tests/Admin/ImageSettingsTest.php pins the ranges.
 */
final class Image_Settings {

	/**
	 * Options each rules card owns.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const RULES_CARDS = array(
		'thumbs'   => array( 'sakura_imgopt_disabled_sizes' ),
		'rename'   => array( 'sakura_imgopt_rename_enabled', 'sakura_imgopt_rename_rule', 'sakura_imgopt_rename_fixed' ),
		'compress' => array(
			'sakura_imgopt_enable_compression',
			'sakura_imgopt_compression_mode',
			'sakura_imgopt_quality',
			'sakura_imgopt_convert_format',
			'sakura_imgopt_source_formats',
			'sakura_imgopt_max_file_size_mb',
			'sakura_imgopt_max_image_width',
			'sakura_imgopt_max_image_height',
			'sakura_imgopt_backup_enabled',
		),
		'bulk'     => array( 'sakura_imgopt_batch_size', 'sakura_imgopt_ajax_delay' ),
	);

	/**
	 * Options stored as arrays of strings.
	 *
	 * @var array<int,string>
	 */
	private const ARRAY_OPTIONS = array( 'sakura_imgopt_disabled_sizes', 'sakura_imgopt_source_formats' );

	/**
	 * Inclusive bounds, mirroring the module's sanitize_* callbacks.
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	private const RANGES = array(
		'sakura_imgopt_quality'          => array( 1, 100 ),
		'sakura_imgopt_batch_size'       => array( 1, 20 ),
		'sakura_imgopt_ajax_delay'       => array( 0, 2000 ),
		'sakura_imgopt_max_file_size_mb' => array( 0, 1024 ),
		'sakura_imgopt_max_image_width'  => array( 0, 20000 ),
		'sakura_imgopt_max_image_height' => array( 0, 20000 ),
		'sakura_imgopt_backup_retention_days' => array( 0, PHP_INT_MAX ),
		'sakura_imgopt_backup_max_mb'    => array( 0, PHP_INT_MAX ),
	);

	/**
	 * Closed sets, mirroring the module's sanitize_* callbacks.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ENUMS = array(
		'sakura_imgopt_compression_mode' => array( 'compress_only', 'compress_convert', 'convert_only' ),
		'sakura_imgopt_convert_format'   => array( 'webp', 'avif' ),
	);

	/**
	 * Options stored as 0/1.
	 *
	 * @var array<int,string>
	 */
	private const FLAG_OPTIONS = array(
		'sakura_imgopt_rename_enabled',
		'sakura_imgopt_enable_compression',
		'sakura_imgopt_backup_enabled',
		'sakura_imgopt_delete_data_on_uninstall',
	);

	/**
	 * Settings repository.
	 *
	 * @var Settings_Repository
	 */
	private Settings_Repository $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Repository $settings Repository.
	 */
	public function __construct( Settings_Repository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Options a rules card owns.
	 *
	 * @param string $card Card id.
	 * @return array<int,string>
	 */
	public static function rules_options( string $card ): array {
		return self::RULES_CARDS[ $card ] ?? array();
	}

	/**
	 * Save one rules card.
	 *
	 * @param string              $card  Card id (thumbs|rename|compress|bulk).
	 * @param array<string,mixed> $input Submitted values keyed by option base name.
	 * @return array{card:string,values:array<string,mixed>,notice:array<string,string>}
	 */
	public function save_rules_card( string $card, array $input ): array {
		$owned = self::rules_options( $card );

		$requested = array();

		foreach ( $owned as $option ) {
			$key = str_starts_with( $option, Settings_Repository::PREFIX )
				? substr( $option, strlen( Settings_Repository::PREFIX ) )
				: $option;

			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$raw                = $this->cast( $option, $input[ $key ] );
			$requested[ $key ]  = $raw;

			update_option( $option, $this->clamp( $option, $raw ) );
		}

		$values = $this->rules_values( $card );

		return array(
			'card'   => $card,
			'values' => $values,
			'notice' => $this->notice( $requested, $values ),
		);
	}

	/**
	 * Current values for one rules card, keyed by option base name.
	 *
	 * @param string $card Card id.
	 * @return array<string,mixed>
	 */
	public function rules_values( string $card ): array {
		$values = array();

		foreach ( self::rules_options( $card ) as $option ) {
			$key  = substr( $option, strlen( Settings_Repository::PREFIX ) );
			$raw  = $this->settings->get( $option );
			// Flags must reach the island as numbers: the option stores the string
			// "0", and a checkbox bound with Boolean("0") shows as ticked while the
			// feature is off (rename was rendered on while rename_enabled was 0).
			$values[ $key ] = in_array( $option, self::FLAG_OPTIONS, true ) ? (int) $raw : $raw;
		}

		return $values;
	}

	/**
	 * Cast a submitted value to the shape the option expects.
	 *
	 * Ranges and enum membership are left to the registered sanitizers, so the
	 * stored value can only ever be what options.php would have accepted.
	 *
	 * @param string $option Full option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	private function cast( string $option, $value ) {
		if ( in_array( $option, self::FLAG_OPTIONS, true ) ) {
			return is_bool( $value ) ? (int) $value : absint( $value );
		}

		if ( in_array( $option, self::ARRAY_OPTIONS, true ) ) {
			if ( is_string( $value ) ) {
				$value = explode( ',', $value );
			}
			if ( ! is_array( $value ) ) {
				return array();
			}

			return array_values( array_map( 'strval', $value ) );
		}

		if ( is_bool( $value ) ) {
			return (int) $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value;
		}

		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Keep a submitted value inside the range the option accepts.
	 *
	 * @param string $option Full option name.
	 * @param mixed  $value  Cast value.
	 * @return mixed
	 */
	private function clamp( string $option, $value ) {
		if ( isset( self::RANGES[ $option ] ) ) {
			list( $min, $max ) = self::RANGES[ $option ];
			$number            = absint( is_scalar( $value ) ? $value : 0 );

			return max( $min, min( $max, $number ) );
		}

		if ( isset( self::ENUMS[ $option ] ) ) {
			return in_array( $value, self::ENUMS[ $option ], true ) ? $value : self::ENUMS[ $option ][0];
		}

		if ( 'sakura_imgopt_source_formats' === $option ) {
			return $this->allowed_formats( (array) $value );
		}

		if ( 'sakura_imgopt_disabled_sizes' === $option ) {
			return $this->allowed_sizes( (array) $value );
		}

		if ( 'sakura_imgopt_rename_rule' === $option ) {
			return $this->clean_rule( (string) $value );
		}

		if ( 'sakura_imgopt_rename_fixed' === $option ) {
			return $this->clean_fixed( (string) $value );
		}

		return $value;
	}

	/**
	 * Source formats the importer accepts.
	 *
	 * @param array<int,mixed> $formats Submitted formats.
	 * @return array<int,string>
	 */
	private function allowed_formats( array $formats ): array {
		$allowed = array();
		foreach ( $formats as $format ) {
			$format = strtolower( sanitize_key( (string) $format ) );
			if ( in_array( $format, Settings_Repository::SOURCE_FORMATS, true ) ) {
				$allowed[] = $format;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Size names that may be disabled.
	 *
	 * @param array<int,mixed> $sizes Submitted names.
	 * @return array<int,string>
	 */
	private function allowed_sizes( array $sizes ): array {
		$known = array_merge(
			array_keys( ( new Image_Report() )->sizes() ),
			Crop_Config_Scanner::stored_names(),
			$this->stored_sizes(),
			array( '_big_threshold', 'large' )
		);

		$cleaned = array();
		foreach ( $sizes as $name ) {
			$name = sanitize_key( (string) $name );
			if ( in_array( $name, $known, true ) ) {
				$cleaned[] = $name;
			}
		}

		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * Names currently stored as disabled, so a size can always be re-enabled.
	 *
	 * @return array<int,string>
	 */
	private function stored_sizes(): array {
		$stored = get_option( 'sakura_imgopt_disabled_sizes', array() );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Rename rule with only the supported placeholders left in place.
	 *
	 * @param string $rule Submitted rule.
	 * @return string
	 */
	private function clean_rule( string $rule ): string {
		$allowed = array(
			'{filename}',
			'{ext}',
			'{date}',
			'{time}',
			'{year}',
			'{month}',
			'{day}',
			'{yy}',
			'{week}',
			'{dow}',
			'{ts}',
			'{rand}',
			'{rand8}',
			'{hash}',
			'{wxh}',
			'{user}',
			'{fixed}',
		);

		$rule = (string) preg_replace_callback(
			'/\{[a-zA-Z0-9_]+\}/',
			static function ( $matches ) use ( $allowed ) {
				return in_array( $matches[0], $allowed, true ) ? $matches[0] : '';
			},
			$rule
		);
		$rule = (string) preg_replace( '~[\\/:*?"<>|]+~', '-', $rule );
		$rule = trim( $rule, " -_." );

		return '' === $rule ? '{date}-{time}-{rand}' : $rule;
	}

	/**
	 * Fixed text: ASCII letters, digits, dash and underscore, 24 chars max.
	 *
	 * @param string $fixed Submitted text.
	 * @return string
	 */
	private function clean_fixed( string $fixed ): string {
		$fixed = (string) preg_replace( '/[^A-Za-z0-9_-]+/', '', $fixed );

		return trim( substr( $fixed, 0, 24 ), '-_' );
	}

	/**
	 * Notice for the save prompt.
	 *
	 * @param array<string,mixed> $submitted Values that were sent.
	 * @param array<string,mixed> $stored    Values now in the database.
	 * @return array<string,string>
	 */
	private function notice( array $submitted, array $stored ): array {
		$adjusted = 0;

		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $submitted ) ) {
				continue;
			}

			if ( $this->comparable( $submitted[ $key ] ) !== $this->comparable( $value ) ) {
				++$adjusted;
			}
		}

		if ( 0 === $adjusted ) {
			return array(
				'tone'    => 'ok',
				'kicker'  => 'SETTINGS / SAVED',
				'title'   => __( '设置已保存', 'sakura-imgopt' ),
				'message' => __( '这一张卡的设置已经写入，页面其它卡片不受影响。', 'sakura-imgopt' ),
			);
		}

		return array(
			'tone'    => 'warn',
			'kicker'  => 'SETTINGS / CHECK',
			'title'   => __( '已保存，部分数值按规则调整', 'sakura-imgopt' ),
			/* translators: %d: number of adjusted fields. */
			'message' => sprintf( __( '有 %d 项超出允许范围或格式不符，已按最近的合法值写入。', 'sakura-imgopt' ), $adjusted ),
		);
	}

	/**
	 * Normalise a value so two shapes of the same setting compare equal.
	 *
	 * @param mixed $value Stored or submitted value.
	 * @return string
	 */
	private function comparable( $value ): string {
		if ( is_array( $value ) ) {
			$value = array_map( 'strval', $value );
			sort( $value );

			return implode( ',', $value );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
