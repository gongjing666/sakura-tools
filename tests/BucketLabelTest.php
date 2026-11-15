<?php
/**
 * Bucket label shortening: the operator-facing name for a storage target.
 *
 * @package Sakura_Image_Optimizer
 */

use Sakura\ImageOptimizer\S3\Bucket_Label;

/**
 * @covers \Sakura\ImageOptimizer\S3\Bucket_Label
 */
class BucketLabelTest extends \PHPUnit\Framework\TestCase {

	public function test_short_bucket_names_survive_untouched(): void {
		$this->assertSame( 'nboke', Bucket_Label::short( 'nboke' ) );
		$this->assertSame( 'abcdefghijklmnopqrstuv', Bucket_Label::short( 'abcdefghijklmnopqrstuv' ) );
	}

	public function test_long_bucket_names_keep_head_and_tail(): void {
		$bucket = 'doss-v2-328e5d1039000fe8-1258813047';

		$this->assertSame( 'doss-v2-…3047', Bucket_Label::short( $bucket ) );
		$this->assertLessThan( strlen( $bucket ), strlen( Bucket_Label::short( $bucket ) ) );
	}

	/**
	 * The space name is what the operator typed into the provider console, so it
	 * wins over any rewriting of the bucket id.
	 */
	public function test_space_name_wins_over_the_bucket_id(): void {
		$this->assertSame( 'nboke', Bucket_Label::display( 'doss-v2-328e5d1039000fe8-1258813047', 'nboke' ) );
	}

	public function test_missing_space_falls_back_to_the_shortened_bucket(): void {
		$this->assertSame( 'doss-v2-…3047', Bucket_Label::display( 'doss-v2-328e5d1039000fe8-1258813047', '' ) );
		$this->assertSame( 'nboke', Bucket_Label::display( 'nboke', '   ' ) );
	}

	public function test_empty_bucket_stays_empty(): void {
		$this->assertSame( '', Bucket_Label::display( '', '' ) );
	}
}
