<?php
/**
 * 多吉云 SDK parameter resolver tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Doge_Cloud_Resolver;

/**
 * The console shows a storage-space name while the S3 client wants the opaque
 * s3Bucket and the COS endpoint; picking the wrong one produces a 404 that
 * reads like a broken plugin, so the mapping has its own tests.
 */
final class DogeCloudResolverTest extends TestCase {

	private const BUCKETS = array(
		array(
			'name'            => 'nboke',
			's3Bucket'        => 'doss-v2-328e5d1039000fe8-1258813047',
			's3Endpoint'      => 'https://cos.ap-chengdu.myqcloud.com/',
			's3EndpointHost'  => 'https://doss-v2-328e5d1039000fe8-1258813047.cos.ap-chengdu.myqcloud.com',
		),
		array(
			'name'            => 'demo',
			's3Bucket'        => 'doss-v2-0000000000000000-1258813047',
			's3Endpoint'      => 'https://cos.ap-guangzhou.myqcloud.com',
			's3EndpointHost'  => 'https://doss-v2-0000000000000000-1258813047.cos.ap-guangzhou.myqcloud.com',
		),
	);

	/**
	 * The typed space name wins over list order.
	 */
	public function test_resolve_by_space_name(): void {
		$result = Doge_Cloud_Resolver::resolve( self::BUCKETS, 'demo' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'ok', $result['code'] );
		$this->assertSame( 'doss-v2-0000000000000000-1258813047', $result['match']['bucket'] );
		$this->assertSame( 'https://cos.ap-guangzhou.myqcloud.com', $result['match']['endpoint'] );
		$this->assertSame( 'ap-guangzhou', $result['match']['region'] );
	}

	/**
	 * Console names are typed by hand: casing must not decide the match.
	 */
	public function test_space_name_match_is_case_insensitive(): void {
		$result = Doge_Cloud_Resolver::resolve( self::BUCKETS, '  NBoke ' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'nboke', $result['match']['space'] );
	}

	/**
	 * The resolved value lands in the same field the name came from, so a
	 * second click must accept the s3Bucket it just wrote instead of calling
	 * the space unknown.
	 */
	public function test_resolve_is_idempotent_after_fill(): void {
		$result = Doge_Cloud_Resolver::resolve( self::BUCKETS, 'doss-v2-0000000000000000-1258813047' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'demo', $result['match']['space'] );
	}

	/**
	 * Nothing typed plus exactly one space: there is only one answer.
	 */
	public function test_single_space_needs_no_name(): void {
		$result = Doge_Cloud_Resolver::resolve( array( self::BUCKETS[0] ), '' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'nboke', $result['match']['space'] );
	}

	/**
	 * Several spaces and no name: guessing would silently write the wrong
	 * bucket, so the caller is asked to let the operator pick.
	 */
	public function test_several_spaces_without_name_asks_to_choose(): void {
		$result = Doge_Cloud_Resolver::resolve( self::BUCKETS, '' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'choose', $result['code'] );
		$this->assertCount( 2, $result['choices'] );
		$this->assertSame( array(), $result['match'] );
	}

	/**
	 * A wrong name must list what does exist instead of failing silently.
	 */
	public function test_unknown_space_lists_available_names(): void {
		$result = Doge_Cloud_Resolver::resolve( self::BUCKETS, 'nboke2' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown', $result['code'] );
		$this->assertStringContainsString( 'nboke2', $result['message'] );
		$this->assertStringContainsString( 'nboke', $result['message'] );
		$this->assertStringContainsString( 'demo', $result['message'] );
		$this->assertCount( 2, $result['choices'] );
	}

	/**
	 * Entry without the SDK pair is useless for the form and is dropped, so an
	 * account with only such entries reads as empty rather than half-filled.
	 */
	public function test_entries_without_sdk_parameters_are_dropped(): void {
		$result = Doge_Cloud_Resolver::resolve(
			array( array( 'name' => 'legacy' ) ),
			''
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'empty', $result['code'] );
	}

	/**
	 * Non-array rows come from a changed API shape; they must not fatal.
	 */
	public function test_catalogue_ignores_non_array_rows(): void {
		$this->assertSame( array(), Doge_Cloud_Resolver::catalogue( array( 'x', null, 42 ) ) );
	}

	/**
	 * Trailing slashes would produce double slashes in the client's endpoint.
	 */
	public function test_catalogue_normalises_endpoint(): void {
		$catalogue = Doge_Cloud_Resolver::catalogue( self::BUCKETS );

		$this->assertSame( 'https://cos.ap-chengdu.myqcloud.com', $catalogue[0]['endpoint'] );
		$this->assertSame( 'ap-chengdu', $catalogue[0]['region'] );
	}

	/**
	 * Only the COS host carries a region; anything else must return empty so
	 * the caller keeps whatever the operator already typed.
	 */
	public function test_region_parsing(): void {
		$this->assertSame( 'ap-chengdu', Doge_Cloud_Resolver::region_from( 'https://cos.ap-chengdu.myqcloud.com' ) );
		$this->assertSame( '', Doge_Cloud_Resolver::region_from( 'https://s3.bitiful.net' ) );
		$this->assertSame( '', Doge_Cloud_Resolver::region_from( '' ) );
		$this->assertSame( '', Doge_Cloud_Resolver::region_from( 'https://cos.myqcloud.com' ) );
	}

	/**
	 * The button only appears for this provider; the constant keeps the two
	 * sides from drifting apart.
	 */
	public function test_provider_constant(): void {
		$this->assertSame( 'dogecloud', Doge_Cloud_Resolver::PROVIDER );
	}
}
