<?php
/**
 * S3 client factory tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Client_Factory;

/**
 * Covers the endpoint addressing decision, which silently breaks a correct
 * bucket/endpoint pair when it goes the wrong way.
 */
final class S3ClientFactoryTest extends TestCase {

	/**
	 * Bucket-in-host providers must not be addressed path style.
	 */
	public function test_virtual_host_providers(): void {
		foreach ( array( 'aliyun', 'tencent', 'qiniu', 'upyun', 'baidu', 'backblaze', 'bitiful', 'dogecloud' ) as $provider ) {
			$this->assertFalse(
				Client_Factory::path_style_for( $provider, 'https://s3.example.com' ),
				$provider . ' must use the virtual-host form'
			);
		}
	}

	/**
	 * 多吉云 runs on a COS backend: a path-style request is refused with
	 * 403 PathStyleDomainForbidden even though key and bucket are correct.
	 */
	public function test_doge_cloud_is_virtual_host(): void {
		$this->assertFalse( Client_Factory::path_style_for( 'dogecloud', 'https://cos.ap-chengdu.myqcloud.com' ) );
	}

	/**
	 * Self-hosted gateways keep the path form, which is why an endpoint alone
	 * used to decide the question.
	 */
	public function test_generic_providers_stay_path_style(): void {
		$this->assertTrue( Client_Factory::path_style_for( 'minio', 'https://minio.example.com' ) );
		$this->assertTrue( Client_Factory::path_style_for( 'cloudflare', 'https://account.r2.cloudflarestorage.com' ) );
	}

	/**
	 * No endpoint means AWS itself, where the SDK already picks the host form.
	 */
	public function test_no_endpoint_never_forces_path_style(): void {
		$this->assertFalse( Client_Factory::path_style_for( 'minio', '' ) );
		$this->assertFalse( Client_Factory::path_style_for( 'dogecloud', '' ) );
	}
}
