<?php
/**
 * Tests for the fast-search token.
 *
 * @package Sakura\ImageOptimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Search_Token;

/**
 * Signed search token behaviour.
 */
final class SearchTokenTest extends TestCase {

	public function test_create_returns_verifiable_parts(): void {
		$token = Search_Token::create( 7, 'prof_test', 600 );

		$this->assertSame( 7, $token['u'] );
		$this->assertSame( 'prof_test', $token['p'] );
		$this->assertGreaterThan( time(), $token['e'] );
		$this->assertTrue( Search_Token::verify( $token['u'], $token['e'], $token['p'], $token['s'] ) );
	}

	public function test_verify_rejects_wrong_profile(): void {
		$token = Search_Token::create( 7, 'prof_test', 600 );

		$this->assertFalse( Search_Token::verify( $token['u'], $token['e'], 'other', $token['s'] ) );
	}

	public function test_verify_rejects_expired_token(): void {
		$user    = 7;
		$profile = 'prof_test';
		$expires = time() - 10;

		$this->assertFalse( Search_Token::verify( $user, $expires, $profile, Search_Token::sign( $user, $expires, $profile ) ) );
	}

	public function test_verify_rejects_malformed_signature(): void {
		$token = Search_Token::create( 7, 'prof_test', 600 );

		$this->assertFalse( Search_Token::verify( $token['u'], $token['e'], $token['p'], 'not-a-signature' ) );
		$this->assertFalse( Search_Token::verify( $token['u'], $token['e'], $token['p'], str_repeat( 'a', 64 ) ) );
	}

	public function test_create_clamps_ttl(): void {
		$short = Search_Token::create( 7, 'prof_test', 1 );
		$long  = Search_Token::create( 7, 'prof_test', 99999 );

		$this->assertGreaterThanOrEqual( time() + 59, $short['e'] );
		$this->assertLessThanOrEqual( time() + 3600, $long['e'] );
	}

	public function test_secret_is_stable(): void {
		$this->assertSame( Search_Token::secret(), Search_Token::secret() );
		$this->assertSame( 64, strlen( Search_Token::secret() ) );
	}
}
