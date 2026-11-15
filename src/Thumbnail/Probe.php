<?php
/**
 * Provider capability probe.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Thumbnail;


defined( 'ABSPATH' ) || exit;

/**
 * Verifies with real HEAD requests which URL syntax the bucket honours.
 *
 * The result is cached for one hour. The probe never assumes that a provider
 * supports a mode: a bucket with origin-file protection answers 200 to `?w=`
 * while returning the default style, which is why the response headers and
 * byte size are inspected instead of the status code alone.
 */
final class Probe {

	public const CACHE_TTL = 3600;

	/**
	 * Optional HTTP transport: callable(string $url): array{code:int,headers:array<string,string>,bytes:int}.
	 *
	 * @var callable|null
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param callable|null $http Optional transport for tests.
	 */
	public function __construct( ?callable $http = null ) {
		$this->http = $http;
	}

	/**
	 * Run the probe, or return the cached result.
	 *
	 * @param bool $force Ignore the cache.
	 */
	public function run( bool $force = false ): Provider_Status {
		$cached = Settings::probe_status();
		if ( ! $force && $cached->checked_at > 0 && ( time() - $cached->checked_at ) < self::CACHE_TTL && Provider_Status::STATE_UNKNOWN !== $cached->state ) {
			return $cached;
		}

		$provider = Settings::provider_id();
		$carrier  = Provider_Registry::supports( $provider, 'style' ) || Provider_Registry::supports( $provider, 'params' );
		if ( ! $carrier ) {
			$status = new Provider_Status(
				Provider_Status::MODE_NONE,
				Provider_Status::STATE_UNSUPPORTED,
				__( '当前对象存储不支持内置的图片处理语法（', 'sakura-imgopt' ) . ( '' === $provider ? __( '未配置', 'sakura-imgopt' ) : $provider ) . __( '）。请在下面填写自定义地址模板，或改用缤纷云、多吉云。', 'sakura-imgopt' ),
				time()
			);
			Settings::store_probe( $status );

			return $status;
		}

		$options    = Settings::get();
		$styles     = isset( $options['style_map'] ) && is_array( $options['style_map'] ) ? $options['style_map'] : array();
		$style_name = '';
		$style_size = '';
		foreach ( array( 'medium', 'thumbnail', 'large' ) as $key ) {
			if ( isset( $styles[ $key ] ) && is_string( $styles[ $key ] ) && '' !== $styles[ $key ] ) {
				$style_name = $styles[ $key ];
				$style_size = $key;
				break;
			}
		}

		$expected = 500;
		if ( '' !== $style_size && isset( $options['sizes'][ $style_size ]['width'] ) ) {
			$expected = max( 1, (int) $options['sizes'][ $style_size ]['width'] );
		}

		// The sample has to be wider than the box we ask for, otherwise the
		// provider has nothing to shrink: imageView2 never upscales, so it answers
		// 200 with the original and the byte comparison proves nothing.
		$sample = $this->sample( $expected );
		if ( null === $sample ) {
			$status = new Provider_Status(
				Provider_Status::MODE_STYLE,
				Provider_Status::STATE_UNKNOWN,
				__( '没有可用于测试的图片，请先上传一张图片。', 'sakura-imgopt' ),
				time()
			);
			Settings::store_probe( $status );

			return $status;
		}

		[ $url, $width, $height ] = $sample;
		$origin = $this->head( $url );
		$origin_bytes = (int) ( $origin['headers']['x-btf-origin-size'] ?? 0 );
		if ( $origin_bytes <= 0 ) {
			$origin_bytes = (int) $origin['bytes'];
		}

		$style_result  = null;
		$params_result = null;

		// Each carrier spells the same two modes differently, so the probe asks
		// the registry for its own implementation instead of assuming Bitiful.
		$style_provider  = Provider_Registry::make( $provider, 'style' );
		$params_provider = Provider_Registry::make( $provider, 'params' );

		if ( '' !== $style_name && null !== $style_provider ) {
			$style_rendition = new Rendition( $expected, $expected, Rendition::MODE_CROP, 'auto', 0, 'auto', $style_name );
			$style_url       = $style_provider->decorate( $url, $style_rendition );
			if ( $style_url !== $url ) {
				$style_result = $this->evaluate( 'style', $style_url, $expected, $origin_bytes, $style_name, $provider );
			}
		}

		if ( null !== $params_provider ) {
			$params_rendition = new Rendition( $expected, $expected, Rendition::MODE_CROP, 'auto', 0, 'auto' );
			$params_url       = $params_provider->decorate( $url, $params_rendition );
			if ( $params_url !== $url ) {
				$params_result = $this->evaluate( 'params', $params_url, $expected, $origin_bytes, '', $provider );
			}
		}

		$native = $this->native_available();

		$status = $this->pick( $style_result, $params_result, $native, $url, $origin_bytes, $width, $height );
		Settings::store_probe( $status );

		return $status;
	}

	/**
	 * Pick the best working mode.
	 *
	 * @param array<string,mixed>|null $style  Style result.
	 * @param array<string,mixed>|null $params Params result.
	 * @param bool                     $native Native sizes available.
	 * @param string                   $url    Sample URL.
	 * @param int                      $origin Origin bytes.
	 * @param int                      $width  Original width.
	 * @param int                      $height Original height.
	 */
	private function pick( ?array $style, ?array $params, bool $native, string $url, int $origin, int $width, int $height ): Provider_Status {
		foreach ( array( array( $style, Provider_Status::MODE_STYLE ), array( $params, Provider_Status::MODE_PARAMS ) ) as $pair ) {
			$result = $pair[0];
			$mode   = $pair[1];
			if ( is_array( $result ) && Provider_Status::STATE_OK === $result['state'] ) {
				return $this->status( $mode, Provider_Status::STATE_OK, (string) $result['evidence'], $url, (int) $result['target'], (int) $result['bytes'], $origin );
			}
		}

		if ( $native ) {
			return $this->status( Provider_Status::MODE_NATIVE, Provider_Status::STATE_OK, __( '云存储不支持地址改写，但本地有 WordPress 生成的尺寸可用。', 'sakura-imgopt' ), $url, 0, 0, $origin );
		}

		$fallback = $style ?? $params;
		if ( is_array( $fallback ) ) {
			return $this->status( Provider_Status::MODE_STYLE === ( $fallback['mode'] ?? '' ) ? Provider_Status::MODE_STYLE : Provider_Status::MODE_PARAMS, (string) $fallback['state'], (string) $fallback['evidence'], $url, (int) $fallback['target'], (int) $fallback['bytes'], $origin );
		}

		/* translators: 1: original image width, 2: original image height. */
		$evidence = sprintf( __( '无法生成测试地址（原图 %1$d×%2$d）。', 'sakura-imgopt' ), $width, $height );
		return $this->status( Provider_Status::MODE_NONE, Provider_Status::STATE_UNSUPPORTED, $evidence, $url, 0, 0, $origin );
	}

	/**
	 * Build a status object.
	 *
	 * @param string $mode     Mode.
	 * @param string $state    State.
	 * @param string $evidence Evidence.
	 * @param string $url      Sample.
	 * @param int    $target   Target width.
	 * @param int    $bytes    Bytes.
	 * @param int    $origin   Origin bytes.
	 */
	private function status( string $mode, string $state, string $evidence, string $url, int $target, int $bytes, int $origin ): Provider_Status {
		return new Provider_Status( $mode, $state, $evidence, time(), $url, $target, $bytes, $origin );
	}

	/**
	 * Evaluate one candidate.
	 *
	 * @param string $mode         params|style.
	 * @param string $url          Candidate URL.
	 * @param int    $expect_width Expected width.
	 * @param int    $origin       Origin bytes.
	 * @param string $style        Style name for evidence.
	 * @return array<string,mixed>
	 */
	private function evaluate( string $mode, string $url, int $expect_width, int $origin, string $style, string $provider = '' ): array {
		$response = $this->head( $url );
		$code     = (int) $response['code'];
		$headers  = $response['headers'];
		$bytes    = (int) $response['bytes'];
		$target   = (int) ( $headers['x-coreix-target-width'] ?? 0 );
		$default  = (string) ( $headers['x-bitiful-defaultstyle'] ?? '' );
		// DogeCloud reports nothing about the produced rendition: no width header,
		// no default-style marker. Its only observable proof is the byte count,
		// which is why the shrink test below is the sole pass criterion there.
		$measured = $target > 0;

		if ( 403 === $code || 404 === $code ) {
			/* translators: %d: HTTP status code. */
			$evidence = sprintf( __( '服务器返回 %d，请求被拒绝。', 'sakura-imgopt' ), $code );
			return array(
				'mode'     => $mode,
				'state'    => Provider_Status::STATE_BLOCKED,
				'evidence' => $evidence,
				'target'   => $target,
				'bytes'    => $bytes,
			);
		}

		if ( 200 !== $code ) {
			if ( 0 === $code ) {
				$evidence = __( '请求失败（网络不通或服务器不支持）。', 'sakura-imgopt' );
			} else {
				/* translators: %d: HTTP status code. */
				$evidence = sprintf( __( '服务器返回 %d。', 'sakura-imgopt' ), $code );
			}
			return array(
				'mode'     => $mode,
				'state'    => Provider_Status::STATE_UNKNOWN,
				'evidence' => $evidence,
				'target'   => $target,
				'bytes'    => $bytes,
			);
		}

		$expected_hint = $this->expected_hint( $mode, $expect_width, '' !== $style ? $style : '', $provider );
		if ( $target > 0 && abs( $target - $expect_width ) > max( 20, (int) round( $expect_width * 0.2 ) ) ) {
			return array(
				'mode'     => $mode,
				'state'    => Provider_Status::STATE_BLOCKED,
				'evidence' => sprintf( /* translators: %1$d: returned width in px, %2$d: expected width in px, %3$s: mode hint. */ __( '返回宽度 %1$dpx，预期 %2$dpx（%3$s）。', 'sakura-imgopt' ), $target, $expect_width, $expected_hint ),
				'target'   => $target,
				'bytes'    => $bytes,
			);
		}

		if ( 'params' === $mode && '' !== $default ) {
			return array(
				'mode'     => $mode,
				'state'    => Provider_Status::STATE_BLOCKED,
				'evidence' => __( '云存储设置了默认样式，覆盖了地址里的宽高参数。', 'sakura-imgopt' ),
				'target'   => $target,
				'bytes'    => $bytes,
			);
		}

		if ( $origin > 0 && $bytes > 0 && $bytes >= $origin * 0.95 ) {
			return array(
				'mode'     => $mode,
				'state'    => Provider_Status::STATE_BLOCKED,
				'evidence' => sprintf( /* translators: 1: returned file size, 2: original file size. */ __( '返回的文件 %1$s，和原图 %2$s 差不多大，看起来没有真正缩小。', 'sakura-imgopt' ), self::format_bytes( $bytes ), self::format_bytes( $origin ) ),
				'target'   => $target,
				'bytes'    => $bytes,
			);
		}

		if ( ! $measured && $origin > 0 && $bytes > 0 ) {
			// Provider without a width header (DogeCloud): the shrunken file is the
			// only proof, so say so instead of reporting a missing header as if the
			// check had been skipped.
			$evidence = sprintf( /* translators: 1: returned file size, 2: original file size. */ __( '访问正常（200）：文件从 %2$s 缩到 %1$s，服务端未报告宽度。', 'sakura-imgopt' ), self::format_bytes( $bytes ), self::format_bytes( $origin ) );
		} else {
			$evidence = sprintf( /* translators: 1: returned width, 2: returned file size, 3: original file size. */ __( '访问正常（200）：宽度 %1$s，文件 %2$s，原图 %3$s。', 'sakura-imgopt' ), $target > 0 ? $target . 'px' : __( '目标宽度头缺失', 'sakura-imgopt' ), self::format_bytes( $bytes ), self::format_bytes( $origin ) );
		}

		return array(
			'mode'     => $mode,
			'state'    => Provider_Status::STATE_OK,
			'evidence' => $evidence,
			'target'   => $target,
			'bytes'    => $bytes,
		);
	}

	/**
	 * Evidence hint for the tested syntax.
	 *
	 * @param string $mode   Mode.
	 * @param int    $width  Width.
	 * @param string $style  Style.
	 */
	private function expected_hint( string $mode, int $width, string $style, string $provider = '' ): string {
		if ( 'style' === $mode && '' !== $style ) {
			return __( '命名样式 ', 'sakura-imgopt' ) . $style;
		}

		if ( 'dogecloud' === $provider ) {
			return '?imageView2/1/w/' . $width . '/h/' . $width;
		}

		return '?w=' . $width . '&h=' . $width . '&mode=crop';
	}

	/**
	 * Find a real image attachment wider than the box the probe will request.
	 *
	 * A sample smaller than the requested width cannot demonstrate anything:
	 * imageView2 never upscales, so it answers with the original file and the
	 * byte comparison passes for the wrong reason.
	 *
	 * @param int $min_width Minimum width in pixels, 0 accepts anything.
	 * @return array{0:string,1:int,2:int}|null
	 */
	private function sample( int $min_width = 0 ): ?array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
			return null;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				// A handful of recent uploads may all be smaller than the requested
				// box, so look further back before giving up.
				'numberposts'    => 20,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$fallback = null;

		foreach ( (array) $ids as $id ) {
			$url = wp_get_attachment_url( (int) $id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ! preg_match( '/\.(jpe?g|png|webp|avif)$/i', $path ) ) {
				continue;
			}
			$width  = 0;
			$height = 0;
			if ( function_exists( 'wp_get_attachment_metadata' ) ) {
				$meta = wp_get_attachment_metadata( (int) $id );
				if ( is_array( $meta ) ) {
					$width  = (int) ( $meta['width'] ?? 0 );
					$height = (int) ( $meta['height'] ?? 0 );
				}
			}

			if ( $min_width <= 0 || $width > $min_width ) {
				return array( $url, $width, $height );
			}

			if ( null === $fallback ) {
				$fallback = array( $url, $width, $height );
			}
		}

		// Every candidate is smaller than the requested box: the probe will report
			// that it could not observe a shrink, which is the honest answer.
		return $fallback;
	}

	/**
	 * Whether a generated intermediate size exists on disk.
	 */
	private function native_available(): bool {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'wp_get_attachment_metadata' ) || ! function_exists( 'wp_upload_dir' ) ) {
			return false;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'numberposts'    => 3,
				'fields'         => 'ids',
			)
		);

		foreach ( (array) $ids as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( ! is_array( $meta ) || empty( $meta['sizes']['medium']['file'] ) ) {
				continue;
			}
			$uploads = wp_upload_dir();
			$base    = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
			$dir     = isset( $meta['file'] ) ? dirname( (string) $meta['file'] ) : '';
			$file    = $base . ( '' !== $dir && '.' !== $dir ? '/' . ltrim( $dir, '/' ) : '' ) . '/' . (string) $meta['sizes']['medium']['file'];
			if ( '' !== $base && file_exists( $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * HEAD a URL through WordPress or the injected transport.
	 *
	 * @param string $url URL.
	 * @return array{code:int,headers:array<string,string>,bytes:int}
	 */
	private function head( string $url ): array {
		if ( is_callable( $this->http ) ) {
			$result = ( $this->http )( $url );
			if ( is_array( $result ) ) {
				return array(
					'code'    => (int) ( $result['code'] ?? 0 ),
					'headers' => $this->normalize_headers( isset( $result['headers'] ) && is_array( $result['headers'] ) ? $result['headers'] : array() ),
					'bytes'   => (int) ( $result['bytes'] ?? 0 ),
				);
			}
		}

		if ( ! function_exists( 'wp_remote_head' ) ) {
			return array( 'code' => 0, 'headers' => array(), 'bytes' => 0 );
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'user-agent'  => 'SakuraTools/' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : 'dev' ) . '; ' . home_url(),
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return array( 'code' => 0, 'headers' => array(), 'bytes' => 0 );
		}

		$code    = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$headers = function_exists( 'wp_remote_retrieve_headers' ) ? wp_remote_retrieve_headers( $response ) : array();
		$body    = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';

		$normalized = $this->normalize_headers( $headers );
		$bytes      = (int) ( $normalized['content-length'] ?? 0 );
		if ( $bytes <= 0 && '' !== $body ) {
			$bytes = strlen( $body );
		}

		return array( 'code' => $code, 'headers' => $normalized, 'bytes' => $bytes );
	}

	/**
	 * Normalize a header collection to lowercase keys.
	 *
	 * @param mixed $headers Headers.
	 * @return array<string,string>
	 */
	private function normalize_headers( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		if ( is_object( $headers ) && method_exists( $headers, 'toArray' ) ) {
			$headers = $headers->toArray();
		}
		if ( ! is_array( $headers ) ) {
			return array();
		}

		$out = array();
		foreach ( $headers as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$out[ strtolower( (string) $key ) ] = (string) $value;
		}

		return $out;
	}

	/**
	 * Human readable byte count.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function format_bytes( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 2 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}

		return $bytes . ' B';
	}
}
