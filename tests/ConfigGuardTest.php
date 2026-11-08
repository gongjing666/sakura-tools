<?php
/**
 * Incomplete S3 configuration guards.
 *
 * @package Sakura_Image_Optimizer
 */

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Storage;

final class ConfigGuardTest extends TestCase {

	private function storage( array $options ): Storage {
		return new Storage( new Options( $options ) );
	}

	public function test_incomplete_config_blocks_image_upload(): void {
		$storage = $this->storage(
			array(
				'enabled'  => 'true',
				'provider' => 'cloudflare',
				'bucket'   => 'bucket',
				'key'      => 'key',
				'secret'   => 'secret',
			)
		);

		$file = $storage->block_incomplete_upload(
			array(
				'name' => 'photo.jpg',
				'type' => 'image/jpeg',
			)
		);

		$this->assertArrayHasKey( 'error', $file );
		$this->assertStringContainsString( 'endpoint', $file['error'] );
	}

	public function test_complete_config_does_not_block_image_upload(): void {
		$storage = $this->storage(
			array(
				'enabled'  => 'true',
				'provider' => 'cloudflare',
				'bucket'   => 'bucket',
				'key'      => 'key',
				'secret'   => 'secret',
				'endpoint' => 'https://s3.example.com',
			)
		);

		$file = $storage->block_incomplete_upload(
			array(
				'name' => 'photo.jpg',
				'type' => 'image/jpeg',
			)
		);

		$this->assertArrayNotHasKey( 'error', $file );
	}

	public function test_non_image_upload_is_not_blocked(): void {
		$storage = $this->storage(
			array(
				'enabled' => 'true',
			)
		);

		$file = $storage->block_incomplete_upload(
			array(
				'name' => 'plugin.zip',
				'type' => 'application/zip',
			)
		);

		$this->assertArrayNotHasKey( 'error', $file );
	}
}
