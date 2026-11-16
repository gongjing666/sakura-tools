<?php
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Providers;

final class S3ProvidersTest extends TestCase {

	public function test_provider_catalog(): void {
		$providers = Providers::all();
		$this->assertCount( 10, $providers );
		$this->assertArrayHasKey( 'cloudflare', $providers );
		$this->assertArrayHasKey( 'bitiful', $providers );
		$this->assertSame( '缤纷云', $providers['bitiful'] );
	}

	public function test_selectable_hides_unverified_providers(): void {
		$selectable = Providers::selectable();
		$this->assertCount( 7, $selectable );
		foreach ( array( 'cloudflare', 'backblaze', 'minio', 'tencent', 'aliyun', 'bitiful', 'dogecloud' ) as $provider ) {
			$this->assertArrayHasKey( $provider, $selectable );
			$this->assertTrue( Providers::exists( $provider ) );
		}
		foreach ( array( 'qiniu', 'upyun', 'baidu' ) as $provider ) {
			$this->assertArrayNotHasKey( $provider, $selectable );
			$this->assertTrue( Providers::exists( $provider ) );
			$this->assertContains( $provider, Providers::hidden() );
		}
	}
}
