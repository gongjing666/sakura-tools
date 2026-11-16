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

	/**
	 * Tencent COS appends the account APPID to every bucket name. It is the same
	 * ten digits for every bucket on the account, so keeping it in the shortened
	 * form turns the one informative part ("sakura-clover") into digits the
	 * operator cannot tell apart.
	 */
	public function test_tencent_bucket_drops_the_account_appid(): void {
		$bucket = 'sakura-clover-1301234331';

		$this->assertSame( 'sakura-clover', Bucket_Label::short( $bucket, 'tencent' ) );
		$this->assertSame( 'sakura-clover', Bucket_Label::display( $bucket, '', 'tencent' ) );
		// Scoped to the provider that appends it: other vendors keep their tail.
		$this->assertSame( 'sakura-c…4331', Bucket_Label::short( $bucket ) );
		$this->assertSame( 'sakura-c…4331', Bucket_Label::short( $bucket, 'bitiful' ) );
	}

	public function test_tencent_bucket_without_appid_is_left_alone(): void {
		$this->assertSame( 'sakura-clover', Bucket_Label::short( 'sakura-clover', 'tencent' ) );
		$this->assertSame( 'my-bucket', Bucket_Label::display( 'my-bucket', '', 'tencent' ) );
	}

	/**
	 * A bucket that is nothing but the APPID keeps its original value: an empty
	 * label would read as "no bucket configured".
	 */
	public function test_tencent_bucket_that_is_only_the_appid_keeps_its_value(): void {
		$this->assertSame( '1301234331', Bucket_Label::short( '1301234331', 'tencent' ) );
	}

	/**
	 * Long Tencent names omit the APPID from the shortened form but keep the
	 * head/tail shape, and the full value still travels in the title.
	 */
	public function test_long_tencent_name_shortens_the_custom_part(): void {
		$this->assertSame( 'a-very-l…name', Bucket_Label::short( 'a-very-long-custom-name-1301234331', 'tencent' ) );
	}

	/**
	 * The full-width form used for default profile names: drop the account APPID,
	 * keep everything else verbatim, and never hand back an empty label.
	 */
	public function test_strip_removes_only_the_vendor_suffix(): void {
		$this->assertSame( 'sakura-clover', Bucket_Label::strip( 'sakura-clover-1301234331', 'tencent' ) );
		$this->assertSame( 'sakura-clover-1301234331', Bucket_Label::strip( 'sakura-clover-1301234331' ) );
		$this->assertSame( 'sakura-clover', Bucket_Label::strip( '  sakura-clover  ', 'tencent' ) );
		// Eight digits are part of a real custom name, not an APPID.
		$this->assertSame( 'my-bucket-12345678', Bucket_Label::strip( 'my-bucket-12345678', 'tencent' ) );
		// A bucket that is only digits keeps its value instead of shrinking to ''.
		$this->assertSame( '1301234331', Bucket_Label::strip( '1301234331', 'tencent' ) );
	}
}
