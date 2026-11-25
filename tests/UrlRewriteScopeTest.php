<?php
/**
 * Url_Rewrite_Scope tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Scope;

/**
 * Prefix resolution for the attachment URL rewrite screen.
 */
final class UrlRewriteScopeTest extends TestCase {

	/**
	 * The public URL path wins over the object-key prefix.
	 */
	public function test_public_url_path_wins(): void {
		$scope = Url_Rewrite_Scope::from_values( 'https://bf.77il.cn/wp-content/uploads', 'wp-content/uploads' );

		$this->assertSame( 'wp-content/uploads', $scope->path_prefix() );
		$this->assertSame( Url_Rewrite_Scope::SOURCE_URL, $scope->source() );
		$this->assertSame( '/wp-content/uploads/', $scope->display_path() );
		$this->assertSame( 'https://bf.77il.cn/wp-content/uploads', $scope->url_base() );
	}

	/**
	 * A URL without a path falls back to upload_path.
	 */
	public function test_url_without_path_falls_back(): void {
		$scope = Url_Rewrite_Scope::from_values( 'https://cdn.example.com', 'wp-content/uploads' );

		$this->assertSame( 'wp-content/uploads', $scope->path_prefix() );
		$this->assertSame( Url_Rewrite_Scope::SOURCE_PATH, $scope->source() );
	}

	/**
	 * Leading/trailing slashes are normalized.
	 */
	public function test_path_is_normalized(): void {
		$scope = Url_Rewrite_Scope::from_values( 'https://cdn.example.com//images//', '/images/' );

		$this->assertSame( 'images', $scope->path_prefix() );
		$this->assertSame( '/images/', $scope->display_path() );
	}

	/**
	 * The root marker means a root-level bucket.
	 */
	public function test_dot_path_is_root(): void {
		$scope = Url_Rewrite_Scope::from_values( 'https://cdn.example.com/', '.' );

		$this->assertSame( '', $scope->path_prefix() );
		$this->assertSame( '/', $scope->display_path() );
	}

	/**
	 * The payload carries exactly the keys the React side reads.
	 */
	public function test_payload_shape(): void {
		$scope = Url_Rewrite_Scope::from_values( 'https://cdn.example.com/wp-content/uploads', 'ignored' );

		$this->assertSame(
			array(
				'pathPrefix'  => 'wp-content/uploads',
				'displayPath' => '/wp-content/uploads/',
				'source'      => Url_Rewrite_Scope::SOURCE_URL,
				'urlBase'     => 'https://cdn.example.com/wp-content/uploads',
			),
			$scope->payload()
		);
	}
}
