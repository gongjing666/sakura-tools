<?php
/**
 * Credential normalization tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Settings_Saver;

final class SettingsSaverTest extends TestCase {

	/**
	 * Characters that break `sanitize_text_field()` survive untouched.
	 */
	public function test_upload_concurrency_is_clamped(): void {
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( '' ) );
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( 'many' ) );
		$this->assertSame( 1, Settings_Saver::sanitize_upload_concurrency( array( 4 ) ) );
		$this->assertSame( 4, Settings_Saver::sanitize_upload_concurrency( '4' ) );
		$this->assertSame( 4, Settings_Saver::sanitize_upload_concurrency( 9 ) );
	}

	public function test_keeps_literal_characters(): void {
		$this->assertSame( 'a%b<c>d&e', Settings_Saver::clean_credential( '  a%b<c>d&e  ' ) );
	}

	/**
	 * Control characters are removed (they would break signing).
	 */
	public function test_strips_control_characters(): void {
		$this->assertSame( 'abc', Settings_Saver::clean_credential( "a\nb\tc\r" ) );
	}

	/**
	 * Non-ASCII credentials are preserved.
	 */
	public function test_keeps_unicode_and_equals(): void {
		$this->assertSame( '密钥%20+==', Settings_Saver::clean_credential( ' 密钥%20+== ' ) );
	}
}
