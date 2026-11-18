<?php
/**
 * S3 connection diagnostics.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\Vendor\Aws\S3\S3Client;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a failed connection test into a message that names the real cause.
 *
 * The old test called headBucket() and, on any failure, told the operator to
 * "check the provider, region, endpoint, bucket and credentials". That text is
 * the same for a dead Access Key, a wrong bucket name and a firewall, so it
 * sent people hunting through settings that were fine. S3-compatible services
 * answer HEAD with a bare 403 and no XML body, which is why the SDK's error
 * code came back empty and there was nothing to show.
 *
 * This probe therefore tries HEAD first (cheap) and, only when it fails,
 * repeats the request with a GET (listObjectsV2, MaxKeys=1) whose XML body does
 * carry the code. A bucket that refuses HEAD but allows listing counts as
 * reachable, because every upload path only needs the latter.
 */
final class Connection_Probe {

	/**
	 * Maximum characters kept from a server-supplied message.
	 */
	private const MESSAGE_LIMIT = 160;

	/**
	 * Run both probes and describe the outcome in one line.
	 *
	 * Never throws: every failure is folded into the returned array so a caller
	 * can show it directly. Redaction list is applied to server text before it
	 * leaves this method, so a credential echoed back by a proxy or a WAF is
	 * never rendered into an admin notice or a URL.
	 *
	 * @param S3Client          $client  Configured client.
	 * @param string            $bucket  Bucket name.
	 * @param array<int,string> $redact  Strings to strip from server output.
	 * @return array{ok:bool,message:string,code:string,status:int,latency:int}
	 */
	public static function diagnose( S3Client $client, string $bucket, array $redact = array() ): array {
		$start = microtime( true );

		try {
			$client->headBucket( array( 'Bucket' => $bucket ) );
			return self::result( true, '', '', 0, (int) round( ( microtime( true ) - $start ) * 1000 ) );
		} catch ( \Throwable $head ) {
			$head_facts = self::facts( $head );
		}

		try {
			$client->listObjectsV2(
				array(
					'Bucket'  => $bucket,
					'MaxKeys' => 1,
				)
			);

			/*
			 * Listing works, so the credentials and the endpoint are fine; only
			 * the HEAD permission is missing. Saying so is more useful than a
			 * green tick, because s3:ListBucket without s3:GetBucketLocation is
			 * a common policy and it does not affect uploads.
			 */
			return self::result(
				true,
				self::partial_note( $head_facts ),
				$head_facts['code'],
				(int) $head_facts['status'],
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		} catch ( \Throwable $list ) {
			$list_facts = self::facts( $list );
		}

		/*
		 * Prefer the GET result: it is the one with a parsed XML body. Fall back
		 * to HEAD for transports that fail before any response (DNS, TLS), where
		 * the GET never got further than the HEAD did.
		 */
		/*
		 * 凭据根本没取到：两个请求都没有真正发出去，任何 HTTP 状态与错误码
		 * 都不存在。这时候说「Endpoint 没有响应」会把用户引到错误的排查方向，
		 * 直接把他该看的原因摆出来。
		 */
		$credential = $list instanceof S3_Credential_Error ? $list : ( $head instanceof S3_Credential_Error ? $head : null );
		if ( null !== $credential ) {
			return self::result(
				false,
				sprintf( /* translators: %s: reason the credentials could not be obtained. */ __( '连接测试失败：%s', 'sakura-imgopt' ), $credential->getMessage() ),
				'',
				0,
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		}

		$code   = '' !== $list_facts['code'] ? $list_facts['code'] : $head_facts['code'];
		$status = (int) ( $list_facts['status'] > 0 ? $list_facts['status'] : $head_facts['status'] );
		$server = '' !== $list_facts['message'] ? $list_facts['message'] : $head_facts['message'];
		$server = self::clean( $server, $redact );

		if ( '' !== $code ) {
			$message = Error_Mapper::message_for( $code, __( '连接测试', 'sakura-imgopt' ) );
			if ( $status > 0 ) {
				$message .= sprintf( /* translators: %d: HTTP status code. */ __( '（HTTP %d）', 'sakura-imgopt' ), $status );
			}
			$message = self::append_server( $message, $server, $code );
		} elseif ( 0 === $status ) {
			/*
			 * No status at all: the request died before any response, which
			 * means DNS, TLS, a proxy or a wrong host. Naming those is far more
			 * actionable than "check your settings".
			 */
			$message = self::append_server(
				__( '连接测试失败：Endpoint 没有响应，请求没有拿到任何 HTTP 状态。请检查服务地址是否写对、服务器能否解析并访问该域名，以及证书是否可信。', 'sakura-imgopt' ),
				$server,
				''
			);
		} elseif ( '' !== $server ) {
			$message = sprintf(
				/* translators: 1: HTTP status code, 2: message returned by the storage service. */
				__( '连接测试失败：HTTP %1$d，服务商没有返回错误码。原始信息：%2$s', 'sakura-imgopt' ),
				$status,
				$server
			);
		} else {
			$message = sprintf( /* translators: %d: HTTP status code. */ __( '连接测试失败：Endpoint 返回 HTTP %d，但没有错误码。请检查服务地址与存储桶名称是否匹配。', 'sakura-imgopt' ), $status );
		}

		return self::result( false, $message, $code, $status, (int) round( ( microtime( true ) - $start ) * 1000 ) );
	}

	/**
	 * Describe a bucket that allows listing but refuses HEAD.
	 *
	 * @param array{code:string,status:int,message:string} $head_facts Facts from the HEAD failure.
	 * @return string
	 */
	private static function partial_note( array $head_facts ): string {
		$status = (int) $head_facts['status'];
		$code   = (string) $head_facts['code'];

		if ( 0 === $status && '' === $code ) {
			return __( '连接可用：读取请求成功，但 HEAD 没有响应。上传与同步不受影响。', 'sakura-imgopt' );
		}

		return sprintf(
			/* translators: 1: HTTP status of the HEAD request, 2: AWS error code. */
			__( '连接可用：读取请求成功，但 HEAD 被拒绝（HTTP %1$d %2$s）。上传与同步不受影响。', 'sakura-imgopt' ),
			$status,
			'' !== $code ? $code : __( '无错误码', 'sakura-imgopt' )
		);
	}

	/**
	 * Read the AWS error code, HTTP status and server message from a throwable.
	 *
	 * @param \Throwable $error Error.
	 * @return array{code:string,status:int,message:string}
	 */
	private static function facts( \Throwable $error ): array {
		$code    = is_callable( array( $error, 'getAwsErrorCode' ) ) ? (string) $error->getAwsErrorCode() : '';
		$status  = is_callable( array( $error, 'getStatusCode' ) ) ? (int) $error->getStatusCode() : 0;
		$message = is_callable( array( $error, 'getAwsErrorMessage' ) ) ? (string) $error->getAwsErrorMessage() : '';

		/*
		 * Older SDK paths and some S3-compatible services put the code in the
		 * response body without filling the error shape, so the exception falls
		 * back to its plain message. Mining both keeps the code visible.
		 */
		if ( '' === $message ) {
			$message = (string) $error->getMessage();
		}
		if ( '' === $code && preg_match( '/\b(InvalidAccessKeyId|SignatureDoesNotMatch|AccessDenied|AllAccessDisabled|NoSuchBucket|ExpiredToken|InvalidToken|RequestTimeTooSkewed|PermanentRedirect|AuthorizationHeaderMalformed|PathStyleDomainForbidden)\b/', $message, $match ) ) {
			$code = $match[1];
		}

		return array(
			'code'    => $code,
			'status'  => $status,
			'message' => $message,
		);
	}

	/**
	 * Collapse server text into one short, credential-free line.
	 *
	 * @param string            $message Server message.
	 * @param array<int,string> $redact  Strings to remove.
	 * @return string
	 */
	private static function clean( string $message, array $redact ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) );

		foreach ( $redact as $secret ) {
			$secret = (string) $secret;
			if ( '' !== $secret && strlen( $secret ) > 3 ) {
				$message = str_replace( $secret, '***', $message );
			}
		}

		// AWSException prefixes the S3 text with the request target; keep the
		// XML <Message> when it is there, drop the wrapper noise otherwise.
		if ( preg_match( '/<Message>(.*?)<\/Message>/s', $message, $match ) ) {
			$message = trim( $match[1] );
		}

		if ( function_exists( 'mb_substr' ) ? mb_strlen( $message, 'UTF-8' ) > self::MESSAGE_LIMIT : strlen( $message ) > self::MESSAGE_LIMIT ) {
			$message = ( function_exists( 'mb_substr' ) ? mb_substr( $message, 0, self::MESSAGE_LIMIT, 'UTF-8' ) : substr( $message, 0, self::MESSAGE_LIMIT ) ) . '…';
		}

		return $message;
	}

	/**
	 * Append the server's own words unless they only restate what we said.
	 *
	 * @param string $base   Message so far.
	 * @param string $server Server text, already cleaned.
	 * @param string $code   AWS error code, empty when unknown.
	 * @return string
	 */
	private static function append_server( string $base, string $server, string $code ): string {
		if ( '' === $server ) {
			return $base;
		}
		if ( '' !== $code && self::already_explains( $server, $code ) ) {
			return $base;
		}
		if ( '' === $code && strlen( $server ) < 12 ) {
			return $base;
		}

		return $base . ' — ' . $server;
	}

	/**
	 * Whether the server text already names the code we are showing.
	 *
	 * @param string $message Server message.
	 * @param string $code    AWS error code.
	 * @return bool
	 */
	private static function already_explains( string $message, string $code ): bool {
		return strlen( $message ) < 12 || false !== strpos( $message, $code );
	}

	/**
	 * Shape the return value.
	 *
	 * @param bool   $ok      Whether the connection works.
	 * @param string $message Operator-facing message.
	 * @param string $code    AWS error code.
	 * @param int    $status  HTTP status.
	 * @param int    $latency Round trip in milliseconds.
	 * @return array{ok:bool,message:string,code:string,status:int,latency:int}
	 */
	private static function result( bool $ok, string $message, string $code, int $status, int $latency ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'code'    => $code,
			'status'  => $status,
			'latency' => max( 0, $latency ),
		);
	}
}
