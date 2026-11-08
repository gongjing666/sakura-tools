<?php
/** Remote-delete queue policy tests. */
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Delete_Jobs;

final class DeleteJobsTest extends TestCase {
	public function test_dead_threshold(): void {
		$this->assertSame( 8, Delete_Jobs::MAX_ATTEMPTS );
	}

	public function test_backoff_doubles_and_caps(): void {
		foreach ( array( 1 => 60, 2 => 120, 3 => 240, 4 => 480, 5 => 960, 6 => 1920, 7 => 3600, 8 => 3600, 20 => 3600, PHP_INT_MAX => 3600 ) as $attempt => $seconds ) {
			$this->assertSame( $seconds, Delete_Jobs::backoff( $attempt ) );
		}
		$this->assertSame( 60, Delete_Jobs::backoff( 0 ) );
		$this->assertSame( 60, Delete_Jobs::backoff( -3 ) );
	}

	public function test_profile_id_preserves_case_and_limits_column_length(): void {
		$this->assertSame( 'Prof_A-12', Delete_Jobs::normalize_profile_id( 'Prof_A-12!?/' ) );
		$this->assertSame( str_repeat( 'A', 40 ), Delete_Jobs::normalize_profile_id( str_repeat( 'A', 80 ) ) );
	}
}
