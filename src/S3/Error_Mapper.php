<?php
/**
 * S3 error mapping.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

/**
 * Converts SDK failures into stable WordPress error codes and readable messages.
 */
final class Error_Mapper {

	/**
	 * Map an AWS error code to a stable WP_Error code.
	 *
	 * @param string $aws_code AWS error code.
	 * @return string
	 */
	public static function code_for( string $aws_code ): string {
		switch ( $aws_code ) {
			case 'AccessDenied':
			case 'AllAccessDisabled':
				return 'sakura_s3_access_denied';
			case 'InvalidAccessKeyId':
			case 'SignatureDoesNotMatch':
				return 'sakura_s3_auth_failed';
			case 'ExpiredToken':
			case 'InvalidToken':
				/*
				 * 与「密钥填错」分开：临时凭据过期是续期失败，不是配置错误。
				 * 合在一起会让用户去改密钥，而问题其实在接口或时间同步上。
				 */
				return 'sakura_s3_token_expired';
			case 'NoSuchBucket':
			case 'NoSuchKey':
				return 'sakura_s3_not_found';
			case 'SlowDown':
			case 'RequestLimitExceeded':
			case 'Throttling':
				return 'sakura_s3_throttled';
			case 'RequestTimeout':
			case 'RequestTimeTooSkewed':
				return 'sakura_s3_timeout';
			case 'EntityTooLarge':
			case 'MethodNotAllowed':
				return 'sakura_s3_rejected';
			case 'PathStyleDomainForbidden':
			case 'PermanentRedirect':
			case 'AuthorizationHeaderMalformed':
				return 'sakura_s3_endpoint_mismatch';
			default:
				return 'sakura_s3_error';
		}
	}

	/**
	 * Human-readable message for an AWS error code.
	 *
	 * @param string $aws_code  AWS error code.
	 * @param string $operation Operation label.
	 * @return string
	 */
	public static function message_for( string $aws_code, string $operation ): string {
		$reason = __( '远端存储请求失败', 'sakura-imgopt' );
		switch ( $aws_code ) {
			case 'AccessDenied':
			case 'AllAccessDisabled':
				$reason = __( '无权访问存储桶，请检查桶策略与对象 ACL', 'sakura-imgopt' );
				break;
			case 'InvalidAccessKeyId':
			case 'SignatureDoesNotMatch':
				$reason = __( '签名无效，请检查 Access Key/Secret', 'sakura-imgopt' );
				break;
			case 'ExpiredToken':
			case 'InvalidToken':
				$reason = __( '临时凭据已过期且自动续期失败，请检查服务商接口是否可达、服务器时间是否准确', 'sakura-imgopt' );
				break;
			case 'NoSuchBucket':
				$reason = __( '存储桶不存在，请检查桶名与端点', 'sakura-imgopt' );
				break;
			case 'NoSuchKey':
				$reason = __( '对象不存在', 'sakura-imgopt' );
				break;
			case 'SlowDown':
			case 'RequestLimitExceeded':
			case 'Throttling':
				$reason = __( '请求被限流，请稍后重试或降低并发', 'sakura-imgopt' );
				break;
			case 'RequestTimeout':
			case 'RequestTimeTooSkewed':
				$reason = __( '请求超时或服务器时间偏差过大', 'sakura-imgopt' );
				break;
			case 'EntityTooLarge':
				$reason = __( '对象超过服务端大小限制', 'sakura-imgopt' );
				break;
			case 'PathStyleDomainForbidden':
				$reason = __( '服务商要求用存储桶域名访问，请把服务地址换成控制台给出的 S3 Endpoint，不要填管理 API 地址', 'sakura-imgopt' );
				break;
			case 'PermanentRedirect':
			case 'AuthorizationHeaderMalformed':
				$reason = __( '存储桶实际所在地域与服务地址不一致，请按服务商给出的地域改写服务地址', 'sakura-imgopt' );
				break;
		}

		return sprintf( /* translators: 1: operation, 2: reason, 3: AWS error code. */ __( '%1$s：%2$s（%3$s）', 'sakura-imgopt' ), $operation, $reason, '' !== $aws_code ? $aws_code : 'unknown' );
	}

	/**
	 * Convert a throwable into a WP_Error.
	 *
	 * @param \Throwable $error     Throwable.
	 * @param string     $operation Operation label.
	 * @return \WP_Error
	 */
	public static function to_wp_error( \Throwable $error, string $operation ): \WP_Error {
		/*
		 * 凭据链的失败自带原因（接口不可达、密钥被吊销、时间偏差…），
		 * 交给通用映射只会被压成一句「远端存储请求失败（unknown）」。
		 */
		if ( $error instanceof S3_Credential_Error ) {
			return new \WP_Error(
				'sakura_s3_token_expired',
				sprintf( /* translators: 1: operation, 2: reason. */ __( '%1$s：%2$s', 'sakura-imgopt' ), $operation, $error->getMessage() ),
				array(
					'aws_code'  => '',
					'operation' => $operation,
				)
			);
		}

		$aws_code = is_callable( array( $error, 'getAwsErrorCode' ) ) ? (string) $error->getAwsErrorCode() : '';

		return new \WP_Error(
			self::code_for( $aws_code ),
			self::message_for( $aws_code, $operation ),
			array(
				'aws_code'  => $aws_code,
				'operation' => $operation,
			)
		);
	}
}
