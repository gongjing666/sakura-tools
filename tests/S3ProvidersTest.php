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
}
