<?php
/**
 * 备份目录公开可达性探测。
 *
 * 插件会在备份目录写入 .htaccess，但 Nginx、Caddy、IIS 以及 PHP 内置服务器
 * 都不会读取它；目录是否真的被拒绝访问，只能由服务器实际应答来判定。
 * 这里发一次只读 HTTP 请求，判定结果为 exposed / protected / unknown。
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Backup;


defined( 'ABSPATH' ) || exit;

/**
 * 探测备份目录是否可被公网直接下载。
 */
final class Exposure_Probe {

	/** 探测结果 option（不自动加载）。 */
	public const OPTION = 'sakura_imgopt_backup_probe';

	/** 结果缓存秒数。 */
	private const CACHE_TTL = 3600;

	/** 探测请求超时秒数。 */
	private const TIMEOUT = 3;

	/**
	 * 执行探测并写入 option。
	 *
	 * @param bool $force 忽略缓存，强制重新探测。
	 * @return array<string,mixed>
	 */
	public function run( bool $force = false ): array {
		if ( ! $force ) {
			$cached = self::latest();
			if ( null !== $cached && isset( $cached['time'] ) && ( time() - (int) $cached['time'] ) < self::CACHE_TTL ) {
				return $cached;
			}
		}

		$result = array(
			'state'      => 'unknown',
			'code'       => 0,
			'url'        => '',
			'sample'     => '',
			'message'    => '',
			'server'     => self::server(),
			'time'       => time(),
		);

		$dir = Backup::base_dir();
		if ( ! is_dir( $dir ) ) {
			$result['message'] = __( '备份目录尚未创建（当前没有原图备份），无需检查。', 'sakura-imgopt' );
			return $this->store( $result );
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			$result['message'] = sprintf(
				/* translators: %s: upload directory error message. */
				__( '无法取得上传目录地址：%s', 'sakura-imgopt' ),
				(string) $upload['error']
			);
			return $this->store( $result );
		}

		$sample   = $this->first_file( $dir );
		$sentinel = '';
		$file_body = '';

		if ( '' === $sample ) {
			$sentinel = $this->write_sentinel( $dir, $file_body );
			if ( '' === $sentinel ) {
				$result['message'] = __( '备份目录存在但没有可读取的文件，且无法写入探测文件，请检查目录权限。', 'sakura-imgopt' );
				return $this->store( $result );
			}
			$sample = $sentinel;
		}

		$relative       = ltrim( $sample, '/' );
		$result['sample'] = $relative;
		$result['url']    = trailingslashit( rtrim( (string) $upload['baseurl'], '/' ) . '/' . Backup::DIR ) . $relative;

		try {
			$probe = $this->probe( $result['url'], $sentinel ? (string) $file_body : '' );
		} finally {
			if ( '' !== $sentinel ) {
				$this->delete_sentinel( $dir . '/' . $sentinel );
			}
		}

		$result = array_merge( $result, $probe );
		$result['message'] = $this->message(
			(string) $probe['state'],
			(int) $probe['code'],
			$result['server'],
			(string) ( $probe['detail'] ?? '' )
		);

		return $this->store( $result );
	}

	/**
	 * 最近一次探测结果（不论是否过期）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest(): ?array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['state'] ) ) {
			return null;
		}

		return $stored;
	}

	/**
	 * 识别当前 Web 服务器。仅用于提示文案，不参与判定。
	 *
	 * @return array{label:string,software:string,reads_htaccess:bool|null}
	 */
	public static function server(): array {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$lower    = strtolower( $software );

		$label = '';
		$reads = null;

		if ( false !== strpos( $lower, 'openresty' ) ) {
			$label = 'OpenResty';
			$reads = false;
		} elseif ( false !== strpos( $lower, 'nginx' ) ) {
			$label = 'Nginx';
			$reads = false;
		} elseif ( false !== strpos( $lower, 'litespeed' ) ) {
			$label = 'LiteSpeed';
			$reads = true;
		} elseif ( false !== strpos( $lower, 'apache' ) ) {
			$label = 'Apache';
			$reads = true;
		} elseif ( false !== strpos( $lower, 'microsoft-iis' ) ) {
			$label = 'IIS';
			$reads = false;
		} elseif ( '' === $software ) {
			$label = 'PHP ' . PHP_SAPI;
		} else {
			$label = $software;
		}

		return array(
			'label'          => $label,
			'software'       => $software,
			'reads_htaccess' => $reads,
		);
	}

	/**
	 * 取目录内第一个普通文件（相对备份目录）。
	 *
	 * @param string $dir 备份目录绝对路径。
	 * @return string 相对路径；没有文件时返回空串。
	 */
	private function first_file( string $dir ): string {
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);
		} catch ( \Exception $e ) {
			return '';
		}

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || $file->isLink() || ! $file->isFile() ) {
				continue;
			}

			$name = $file->getFilename();
			if ( 'index.php' === $name || '.htaccess' === $name ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) );
			return ltrim( $relative, '/' );
		}

		return '';
	}

	/**
	 * 目录为空时写入一个带随机内容的探测文件。
	 *
	 * @param string $dir  备份目录绝对路径。
	 * @param string $body 写入的内容（引用返回）。
	 * @return string 相对路径；失败返回空串。
	 */
	private function write_sentinel( string $dir, string &$body ): string {
		$body = 'sakura-probe-' . bin2hex( random_bytes( 16 ) );
		$name = '.sakura-probe-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$path = rtrim( $dir, '/' ) . '/' . $name;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 失败走返回值，不输出警告。
		if ( false === @file_put_contents( $path, $body ) ) {
			$body = '';
			return '';
		}

		return $name;
	}

	/**
	 * 删除探测文件。
	 *
	 * @param string $path 绝对路径。
	 * @return void
	 */
	private function delete_sentinel( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 清理失败不影响探测结论。
			@unlink( $path );
		}
	}

	/**
	 * 发起探测请求并判定状态。
	 *
	 * @param string $url           探测地址。
	 * @param string $expected_body 哨兵文件内容；空串表示探测的是真实图片。
	 * @return array{state:string,code:int}
	 */
	private function probe( string $url, string $expected_body ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
				'user-agent'  => 'SakuraTools/' . ( defined( 'SAKURA_TOOLS_VERSION' ) ? SAKURA_TOOLS_VERSION : '1.0' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'state'  => 'unknown',
				'code'   => 0,
				'detail' => $response->get_error_message(),
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$type     = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body     = (string) wp_remote_retrieve_body( $response );
		$verdict  = self::classify( $code, $type, $body, $expected_body );

		return array(
			'state'  => $verdict['state'],
			'code'   => $code,
			'detail' => $verdict['detail'],
		);
	}

	/**
	 * 依据 HTTP 应答判定备份目录是否暴露（纯函数，便于测试）。
	 *
	 * @param int    $code           HTTP 状态码。
	 * @param string $content_type   Content-Type。
	 * @param string $body           响应体。
	 * @param string $expected_body  哨兵内容；空串表示探测真实图片。
	 * @return array{state:string,detail:string}
	 */
	public static function classify( int $code, string $content_type, string $body, string $expected_body ): array {
		$type = strtolower( $content_type );

		if ( 200 === $code ) {
			// 500/502 页面、主题 404 页常以 200 + text/html 返回，不能据此认定可下载。
			if ( false !== strpos( $type, 'text/html' ) ) {
				return array( 'state' => 'unknown', 'detail' => 'html' );
			}

			if ( '' !== $expected_body ) {
				return array(
					'state'  => ( false !== strpos( $body, $expected_body ) ) ? 'exposed' : 'unknown',
					'detail' => 'body-mismatch',
				);
			}

			return array( 'state' => 'exposed', 'detail' => '' );
		}

		if ( in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
			return array( 'state' => 'protected', 'detail' => '' );
		}

		return array( 'state' => 'unknown', 'detail' => '' );
	}

	/**
	 * 生成给后台展示的说明。
	 *
	 * @param string                $state  状态。
	 * @param int                   $code   HTTP 状态码。
	 * @param array<string,mixed>   $server 服务器信息。
	 * @param string                $detail 附加信息。
	 * @return string
	 */
	private function message( string $state, int $code, array $server, string $detail = '' ): string {
		$server_label = isset( $server['label'] ) ? (string) $server['label'] : '';

		if ( 'exposed' === $state ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: server label. */
				__( '警告：备份目录可被公网直接下载（HTTP %1$d，服务器：%2$s）。任何人拿到链接就能下走原图，请立即在站点配置中拒绝该目录。', 'sakura-imgopt' ),
				$code,
				$server_label
			);
		}

		if ( 'protected' === $state ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: server label. */
				__( '已保护：服务器拒绝访问备份目录（HTTP %1$d，服务器：%2$s），原图备份无法被直接下载。', 'sakura-imgopt' ),
				$code,
				$server_label
			);
		}

		if ( 'html' === $detail ) {
			$detail = __( '服务器返回了 HTML 页面（常见于主题 404 页），无法据此判断。', 'sakura-imgopt' );
		} elseif ( 'body-mismatch' === $detail ) {
			$detail = __( '服务器返回 200，但内容与探测文件不一致（可能被缓存或改写）。', 'sakura-imgopt' );
		} elseif ( '' !== $detail ) {
			$detail = sprintf(
				/* translators: %s: transport error message. */
				__( '请求失败：%s', 'sakura-imgopt' ),
				$detail
			);
		}

		return sprintf(
			/* translators: 1: server label, 2: detail. */
			__( '无法判定（服务器：%1$s）。%2$s 请手动访问备份目录里的任意文件确认：返回 403/404 即已保护，返回图片内容则需要补拒绝规则。', 'sakura-imgopt' ),
			$server_label,
			$detail
		);
	}

	/**
	 * 写入 option 并返回结果。
	 *
	 * @param array<string,mixed> $result 结果。
	 * @return array<string,mixed>
	 */
	private function store( array $result ): array {
		update_option( self::OPTION, $result, false );
		return $result;
	}
}
