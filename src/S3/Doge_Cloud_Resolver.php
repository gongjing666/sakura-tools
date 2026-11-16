<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 多吉云连接参数建议。
 *
 * 多吉云控制台只显示「存储空间名称」（即它的 bucket 字段），而 S3 客户端
 * 需要的是带 appid 的 s3Bucket 和 COS 地域端点 s3Endpoint。这两个值只在
 * /auth/tmp_token.json 的响应里给出来（data.Buckets[]），凭据本身就在这个
 * 接口里拿，所以「按空间名反查 SDK 参数」不需要用户手抄，也不需要额外权限。
 *
 * 这里只做「输入空间名 → 输出候选参数」，不读写存档，也不碰任何密钥：
 * 调用方负责把结果填回表单，保存仍然走 Settings_Saver。
 */
final class Doge_Cloud_Resolver {

	/** 该服务商的标识，避免调用方再判断一次。 */
	public const PROVIDER = 'dogecloud';

	/**
	 * 按空间名称匹配 SDK 参数。
	 *
	 * @param array<int,array<string,mixed>> $buckets 接口返回的 Buckets 列表。
	 * @param string                         $space   用户填写的空间名称，空表示唯一的那个。
	 * @return array<string,mixed> {
	 *     @type bool                   $ok       Whether a bucket was resolved.
	 *     @type string                 $code     Machine-readable result code.
	 *     @type string                 $message  Operator-facing explanation.
	 *     @type array<string,string>   $match    bucket / endpoint / host / space / region.
	 *     @type array<int,array<string,string>> $choices Hosts the operator may pick from.
	 * }
	 */
	public static function resolve( array $buckets, string $space ): array {
		$catalogue = self::catalogue( $buckets );
		$space     = trim( $space );

		if ( array() === $catalogue ) {
			return self::fail(
				'empty',
				__( '多吉云这个账号下没有可用的存储空间。请先在控制台新建一个空间再试。', 'sakura-imgopt' )
			);
		}

		if ( '' === $space ) {
			if ( 1 === count( $catalogue ) ) {
				return self::succeed( $catalogue[0], $catalogue );
			}
			return self::fail(
				'choose',
				sprintf(
					/* translators: %d: number of storage spaces. */
					__( '这个账号下有 %d 个存储空间，请先在上面填写空间名称，再获取 SDK 参数。', 'sakura-imgopt' ),
					count( $catalogue )
				),
				$catalogue
			);
		}

		/*
		 * 解析成功后表单里留下的是 s3Bucket，不是空间名称。再次点按钮时若只认
		 * 空间名称，就会把刚填好的值判成「没有这个空间」，所以这里也接受已经
		 * 写进表单的 s3Bucket，让重复点击保持幂等。
		 */
		$wanted = strtolower( $space );
		foreach ( $catalogue as $entry ) {
			if ( strtolower( $entry['space'] ) === $wanted || strtolower( $entry['bucket'] ) === $wanted ) {
				return self::succeed( $entry, $catalogue );
			}
		}

		return self::fail(
			'unknown',
			sprintf(
				/* translators: 1: storage space name entered by the operator, 2: comma separated available names. */
				__( '多吉云账号下没有名为「%1$s」的存储空间。可用的空间：%2$s。', 'sakura-imgopt' ),
				$space,
				implode( '、', array_column( $catalogue, 'space' ) )
			),
			$catalogue
		);
	}

	/**
	 * 把接口返回的 Buckets 数组整理成只含本插件会用到的字段。
	 *
	 * 缺 s3Bucket 或 s3Endpoint 的条目直接丢弃：那样的空间填进表单只会得到
	 * 一个更难诊断的 404，不如当成不存在。
	 *
	 * @param array<int,array<string,mixed>> $buckets Raw list.
	 * @return array<int,array<string,string>>
	 */
	public static function catalogue( array $buckets ): array {
		$out = array();
		foreach ( $buckets as $bucket ) {
			if ( ! is_array( $bucket ) ) {
				continue;
			}
			$space    = trim( (string) ( $bucket['name'] ?? '' ) );
			$target   = trim( (string) ( $bucket['s3Bucket'] ?? '' ) );
			$endpoint = trim( (string) ( $bucket['s3Endpoint'] ?? '' ) );
			if ( '' === $space || '' === $target || '' === $endpoint ) {
				continue;
			}
			$out[] = array(
				'space'    => $space,
				'bucket'   => $target,
				'endpoint' => untrailingslashit( $endpoint ),
				'host'     => untrailingslashit( trim( (string) ( $bucket['s3EndpointHost'] ?? '' ) ) ),
				'region'   => self::region_from( $endpoint ),
			);
		}
		return $out;
	}

	/**
	 * 从 COS 端点里读出地域，例如 https://cos.ap-chengdu.myqcloud.com → ap-chengdu。
	 *
	 * 返回空串表示没认出来，调用方应保留用户原来填写的地域。
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return string
	 */
	public static function region_from( string $endpoint ): string {
		$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		if ( '' === $host ) {
			return '';
		}
		if ( preg_match( '/^cos\.([a-z0-9-]+)\.myqcloud\.com$/i', $host, $matches ) ) {
			return strtolower( $matches[1] );
		}
		return '';
	}

	/**
	 * @param array<string,string>             $entry     Resolved entry.
	 * @param array<int,array<string,string>>  $catalogue All entries.
	 * @return array<string,mixed>
	 */
	private static function succeed( array $entry, array $catalogue ): array {
		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => sprintf(
				/* translators: 1: storage space name, 2: s3Bucket value. */
				__( '已按空间「%1$s」填入 SDK 参数（存储桶 %2$s）。', 'sakura-imgopt' ),
				$entry['space'],
				$entry['bucket']
			),
			'match'   => $entry,
			'choices' => $catalogue,
		);
	}

	/**
	 * @param string                          $code      Result code.
	 * @param string                          $message   Operator-facing text.
	 * @param array<int,array<string,string>> $catalogue Known spaces.
	 * @return array<string,mixed>
	 */
	private static function fail( string $code, string $message, array $catalogue = array() ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
			'match'   => array(),
			'choices' => $catalogue,
		);
	}
}
