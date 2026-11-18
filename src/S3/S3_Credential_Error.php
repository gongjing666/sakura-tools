<?php
/**
 * 临时凭据取不到，或已过期且续期失败。
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types = 1 );

namespace Sakura\ImageOptimizer\S3;

defined( 'ABSPATH' ) || exit;

/**
 * 凭据链断了。
 *
 * 单独一类是为了让下游分得清两种失败：Endpoint 不通 / 桶不存在属于「网络与
 * 配置」，用户该去查服务地址和桶名；凭据取不到属于「凭据链」，该去查服务商
 * 接口是否可达、密钥是否被吊销、服务器时间是否偏差过大。合成一句「请求失败」
 * 会让人朝错误的方向改配置。
 *
 * 继承 RuntimeException，所以既有的 catch ( \Throwable ) 全都不用改。
 */
final class S3_Credential_Error extends \RuntimeException {
}
