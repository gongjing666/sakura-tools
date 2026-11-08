<?php
/**
 * Provider capability probe result.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;

defined( 'ABSPATH' ) || exit;

/**
 * Value object persisted in the `sakura_imgopt_thumb_probe` option.
 */
final class Provider_Status {

	public const STATE_OK          = 'ok';
	public const STATE_BLOCKED     = 'blocked';
	public const STATE_UNSUPPORTED = 'unsupported';
	public const STATE_UNKNOWN     = 'unknown';

	public const MODE_PARAMS = 'params';
	public const MODE_STYLE  = 'style';
	public const MODE_NATIVE = 'native';
	public const MODE_NONE   = 'none';

	/**
	 * Constructor.
	 *
	 * @param string $mode        params|style|native|none.
	 * @param string $state       ok|blocked|unsupported|unknown.
	 * @param string $evidence    Human readable evidence.
	 * @param int    $checked_at  Unix timestamp.
	 * @param string $sample_url  Sample image URL.
	 * @param int    $target      Reported target width.
	 * @param int    $bytes       Probe response bytes.
	 * @param int    $origin_bytes Original response bytes.
	 */
	public function __construct(
		public readonly string $mode = self::MODE_NONE,
		public readonly string $state = self::STATE_UNKNOWN,
		public readonly string $evidence = '',
		public readonly int $checked_at = 0,
		public readonly string $sample_url = '',
		public readonly int $target = 0,
		public readonly int $bytes = 0,
		public readonly int $origin_bytes = 0
	) {}

	/**
	 * Build from stored data.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	public static function from_array( array $data ): self {
		$mode = isset( $data['mode'] ) && is_string( $data['mode'] ) ? $data['mode'] : self::MODE_NONE;
		if ( ! in_array( $mode, array( self::MODE_PARAMS, self::MODE_STYLE, self::MODE_NATIVE, self::MODE_NONE ), true ) ) {
			$mode = self::MODE_NONE;
		}

		$state = isset( $data['state'] ) && is_string( $data['state'] ) ? $data['state'] : self::STATE_UNKNOWN;
		if ( ! in_array( $state, array( self::STATE_OK, self::STATE_BLOCKED, self::STATE_UNSUPPORTED, self::STATE_UNKNOWN ), true ) ) {
			$state = self::STATE_UNKNOWN;
		}

		return new self(
			$mode,
			$state,
			isset( $data['evidence'] ) ? (string) $data['evidence'] : '',
			isset( $data['checked_at'] ) ? (int) $data['checked_at'] : 0,
			isset( $data['sample_url'] ) ? (string) $data['sample_url'] : '',
			isset( $data['target'] ) ? (int) $data['target'] : 0,
			isset( $data['bytes'] ) ? (int) $data['bytes'] : 0,
			isset( $data['origin_bytes'] ) ? (int) $data['origin_bytes'] : 0
		);
	}

	/**
	 * Serialize for storage.
	 *
	 * @return array<string,int|string>
	 */
	public function to_array(): array {
		return array(
			'mode'         => $this->mode,
			'state'        => $this->state,
			'evidence'     => $this->evidence,
			'checked_at'   => $this->checked_at,
			'sample_url'   => $this->sample_url,
			'target'       => $this->target,
			'bytes'        => $this->bytes,
			'origin_bytes' => $this->origin_bytes,
		);
	}

	/**
	 * Whether the probe proved this mode works.
	 */
	public function is_ok(): bool {
		return self::STATE_OK === $this->state;
	}

	/**
	 * Chinese label for the admin UI.
	 */
	public function label(): string {
		$states = array(
			self::STATE_OK          => __( '可用', 'sakura-imgopt' ),
			self::STATE_BLOCKED     => __( '被拦截', 'sakura-imgopt' ),
			self::STATE_UNSUPPORTED => __( '不支持', 'sakura-imgopt' ),
			self::STATE_UNKNOWN     => __( '未知', 'sakura-imgopt' ),
		);
		$modes  = array(
			self::MODE_PARAMS => __( 'URL 参数', 'sakura-imgopt' ),
			self::MODE_STYLE  => __( '命名样式', 'sakura-imgopt' ),
			self::MODE_NATIVE => __( 'WordPress 衍生尺寸', 'sakura-imgopt' ),
			self::MODE_NONE   => __( '不处理', 'sakura-imgopt' ),
		);

		return ( $modes[ $this->mode ] ?? $this->mode ) . ' / ' . ( $states[ $this->state ] ?? $this->state );
	}
}
