<?php
/**
 * Tests for CDN→local URL mapping used while an attachment is not synced.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Storage;

final class StorageUrlGateTest extends TestCase {

	public function test_maps_cdn_prefix_to_local_uploads(): void {
		$this->assertSame(
			'https://www.example.com/wp-content/uploads/2026/10/a.webp',
			Storage::map_cdn_to_local(
				'https://cdn.example.com/wp-content/uploads/2026/10/a.webp',
				'https://cdn.example.com/wp-content/uploads',
				'https://www.example.com/wp-content/uploads'
			)
		);
	}

	public function test_trailing_slashes_do_not_double_up(): void {
		$this->assertSame(
			'https://www.example.com/uploads/2026/a.jpg',
			Storage::map_cdn_to_local(
				'https://cdn.example.com/uploads/2026/a.jpg',
				'https://cdn.example.com/uploads/',
				'https://www.example.com/uploads/'
			)
		);
	}

	public function test_other_hosts_stay_untouched(): void {
		$url = 'https://other.example.net/2026/10/a.jpg';
		$this->assertSame( $url, Storage::map_cdn_to_local( $url, 'https://cdn.example.com', 'https://www.example.com' ) );
	}

	public function test_empty_bases_are_noops(): void {
		$url = 'https://cdn.example.com/a.jpg';
		$this->assertSame( $url, Storage::map_cdn_to_local( $url, '', 'https://www.example.com' ) );
		$this->assertSame( $url, Storage::map_cdn_to_local( $url, 'https://cdn.example.com', '' ) );
	}

	public function test_unknown_status_keeps_cdn_url(): void {
		$this->assertFalse( Storage::should_keep_local( '', true ) );
	}

	public function test_pending_or_failed_attachments_stay_local(): void {
		$this->assertTrue( Storage::should_keep_local( 'pending', true ) );
		$this->assertTrue( Storage::should_keep_local( 'failed', true ) );
		$this->assertTrue( Storage::should_keep_local( 'dead', true ) );
	}

	public function test_synced_attachments_use_cdn(): void {
		$this->assertFalse( Storage::should_keep_local( 'synced', true ) );
	}

	public function test_synced_attachment_with_mismatched_target_stays_local(): void {
		$this->assertTrue( Storage::should_keep_local( 'synced', true, false ) );
		$this->assertFalse( Storage::should_keep_local( 'synced', true, true ) );
	}

	public function test_missing_local_file_never_blocks_cdn(): void {
		$this->assertFalse( Storage::should_keep_local( 'pending', false ) );
		$this->assertFalse( Storage::should_keep_local( 'failed', false ) );
	}
}
