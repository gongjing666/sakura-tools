<?php
/**
 * Tests for the upload target fingerprint.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Options;
use Sakura\ImageOptimizer\S3\Synchronous_Uploader;

final class TargetFingerprintTest extends TestCase {

	/**
	 * Options pointing at one bucket, with two saved profiles for it.
	 *
	 * @param string $active Active profile id.
	 */
	private function options( string $active = 'prof_new' ): Options {
		return new Options(
			array(
				'enabled'           => 'true',
				'provider'          => 'dogecloud',
				'bucket'            => 'doss-v2-abc',
				'endpoint'          => 'https://cos.ap-chengdu.myqcloud.com',
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => $active,
				'profiles'          => array(
					'prof_new' => array(
						'id'       => 'prof_new',
						'provider' => 'dogecloud',
						'bucket'   => 'doss-v2-abc',
						'endpoint' => 'https://cos.ap-chengdu.myqcloud.com',
					),
					'prof_old' => array(
						'id'       => 'prof_old',
						'provider' => 'dogecloud',
						'bucket'   => 'doss-v2-abc',
						'endpoint' => 'https://cos.ap-chengdu.myqcloud.com',
					),
				),
			)
		);
	}

	public function test_fingerprint_ignores_the_profile_row(): void {
		$this->assertSame(
			Synchronous_Uploader::target_hash_for( $this->options( 'prof_new' ) ),
			Synchronous_Uploader::target_hash_for( $this->options( 'prof_old' ) )
		);
	}

	public function test_fingerprint_changes_with_the_bucket(): void {
		$other = new Options(
			array(
				'provider'    => 'dogecloud',
				'bucket'      => 'doss-v2-other',
				'endpoint'    => 'https://cos.ap-chengdu.myqcloud.com',
				'upload_path' => 'wp-content/uploads',
			)
		);

		$this->assertNotSame(
			Synchronous_Uploader::target_hash_for( $this->options() ),
			Synchronous_Uploader::target_hash_for( $other )
		);
	}

	public function test_legacy_row_fingerprint_is_still_accepted(): void {
		$options = $this->options( 'prof_new' );
		$legacy  = hash(
			'sha256',
			implode(
				'|',
				array( 'prof_old', 'dogecloud', 'doss-v2-abc', 'https://cos.ap-chengdu.myqcloud.com', 'wp-content/uploads' )
			)
		);

		$this->assertTrue( Synchronous_Uploader::target_matches( $legacy, $options ) );
	}

	public function test_foreign_fingerprint_is_rejected(): void {
		$this->assertFalse( Synchronous_Uploader::target_matches( str_repeat( 'a', 64 ), $this->options() ) );
		$this->assertFalse( Synchronous_Uploader::target_matches( '', $this->options() ) );
	}

	public function test_queue_row_profile_is_accepted_even_when_deleted(): void {
		$options = new Options(
			array(
				'provider'          => 'minio',
				'bucket'            => 'local',
				'endpoint'          => 'https://minio.example.com',
				'upload_path'       => 'wp-content/uploads',
				'active_profile_id' => 'prof_new',
				'profiles'          => array(),
			)
		);
		$legacy = hash(
			'sha256',
			implode( '|', array( 'prof_gone', 'minio', 'local', 'https://minio.example.com', 'wp-content/uploads' ) )
		);

		$this->assertFalse( Synchronous_Uploader::target_matches( $legacy, $options ) );
		$this->assertTrue( Synchronous_Uploader::target_matches( $legacy, $options, 'prof_gone' ) );
	}

	public function test_hashes_are_unique(): void {
		$hashes = Synchronous_Uploader::target_hashes_for( $this->options() );

		$this->assertSame( $hashes, array_values( array_unique( $hashes ) ) );
		$this->assertContains( Synchronous_Uploader::target_hash_for( $this->options() ), $hashes );
		$this->assertCount( 3, $hashes );
	}
}
