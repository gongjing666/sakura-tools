<?php
/**
 * Cross-switch conflict detection for the thumbnail rewriter.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Thumbnail\Settings;

/**
 * `Settings::conflicts()` is pure on purpose: it must answer "what would break
 * if I saved this" before anything is written.
 */
final class ThumbnailConflictsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options'] = array();
	}

	/** URL rewriting against a private bucket produces unsigned URLs. */
	public function test_private_acl_with_url_rewrite_is_reported(): void {
		$this->assertContains( 'private_acl', $this->codes( $this->opt(), $this->s3() ) );
	}

	/** Public-read keeps the rewritten URLs reachable. */
	public function test_public_acl_is_clean(): void {
		$s3               = $this->s3();
		$s3['object_acl'] = 'public-read';

		$this->assertSame( array(), $this->codes( $this->opt(), $s3 ) );
	}

	/** Rewriting to sub-sizes that are never uploaded produces 404s. */
	public function test_no_thumb_with_url_rewrite_is_reported(): void {
		$s3             = $this->s3();
		$s3['no_thumb'] = 'true';

		$this->assertContains( 'no_thumb', $this->codes( $this->opt(), $s3 ) );
	}

	/** A disabled rewriter cannot conflict with anything. */
	public function test_disabled_rewrite_is_clean(): void {
		$opt            = $this->opt();
		$opt['enabled'] = 0;

		$this->assertSame( array(), $this->codes( $opt, $this->s3() ) );
	}

	/** A custom template enables params mode beyond Bitiful. */
	public function test_template_provider_is_not_bitiful_only(): void {
		$opt                                         = $this->opt();
		$opt['provider_mode']                        = 'params';
		$opt['advanced']['custom_provider_template'] = 'https://img.example.com/{path}?w={width}';
		$s3                                          = $this->s3();
		$s3['provider']                              = 'aliyun';

		$this->assertContains( 'private_acl', $this->codes( $opt, $s3 ) );
	}

	/** Native sizing never rewrites URLs. */
	public function test_native_mode_does_not_rewrite(): void {
		$opt                  = $this->opt();
		$opt['provider_mode'] = 'native';

		$this->assertSame( array(), $this->codes( $opt, $this->s3() ) );
	}

	/** A failed probe in auto mode degrades to "none". */
	public function test_failed_probe_degrades_to_none(): void {
		$opt                  = $this->opt();
		$opt['provider_mode'] = 'auto';

		$this->assertSame(
			array(),
			$this->codes( $opt, $this->s3(), array( 'ok' => false, 'mode' => 'style' ) )
		);
	}

	/**
	 * Rewriting configuration used by most cases.
	 *
	 * @return array<string,mixed>
	 */
	private function opt(): array {
		$opt                  = Settings::defaults();
		$opt['enabled']       = 1;
		$opt['provider_mode'] = 'style';
		$opt['style_map']     = array(
			'thumbnail' => 'siothumb',
			'medium'    => 'siothumb',
		);

		return $opt;
	}

	/**
	 * Off-site storage options with a reachable custom host.
	 *
	 * @return array<string,mixed>
	 */
	private function s3(): array {
		return array(
			'provider'        => 'bitiful',
			'object_acl'      => 'private',
			'no_thumb'        => 'false',
			'upload_url_path' => 'https://cdn.example.com/wp-content/uploads',
		);
	}

	/**
	 * Conflict codes for one combination.
	 *
	 * @param array<string,mixed>              $opt   Options.
	 * @param array<string,mixed>              $s3    S3 options.
	 * @param array{ok?:bool,mode?:string}     $probe Probe override.
	 * @return array<int,string>
	 */
	private function codes( array $opt, array $s3, array $probe = array( 'ok' => true, 'mode' => 'style' ) ): array {
		return array_map(
			static function ( array $conflict ): string {
				return (string) $conflict['code'];
			},
			Settings::conflicts( $opt, $s3, $probe )
		);
	}
}
