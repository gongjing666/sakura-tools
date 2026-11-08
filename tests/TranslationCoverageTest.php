<?php
/**
 * Translation coverage guard.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Fails when a PHP UI string bypasses the text domain.
 */
final class TranslationCoverageTest extends TestCase {

	/**
	 * Strings that are matched, not printed.
	 *
	 * `子比` detects the theme; the four guard messages are the theme's own
	 * upload-limit copy, used as mb_stripos() needles in Uploader::handle().
	 * Translating a needle would make the match depend on the site language.
	 */
	private const ALLOWED = array( '子比', '过于频繁', '今日上限', '上传上限', '达到上限' );

	/**
	 * Every Chinese PHP string literal must be passed to a translation function.
	 */
	public function test_no_untranslated_chinese_literals(): void {
		$root    = dirname( __DIR__ );
		$skip    = array( '/vendor/', '/tests/', '/languages/', '/stubs/', '/.git/' );
		$funcs   = array( '__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_x', '_ex', '_n', 'esc_html_x', 'esc_attr_x' );
		$raw     = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path = $file->getPathname();
			foreach ( $skip as $segment ) {
				if ( false !== strpos( $path, $segment ) ) {
					continue 2;
				}
			}

			$source = file_get_contents( $path );
			if ( false === $source || ! preg_match( '/[\x{4e00}-\x{9fff}]/u', $source ) ) {
				continue;
			}

			$tokens = token_get_all( $source );
			foreach ( $tokens as $index => $token ) {
				if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
					continue;
				}

				$text = trim( $token[1], "\"'" );
				if ( ! preg_match( '/[\x{4e00}-\x{9fff}]/u', $text ) || in_array( $text, self::ALLOWED, true ) ) {
					continue;
				}

				$translated = false;
				for ( $lookback = max( 0, $index - 8 ); $lookback < $index; $lookback++ ) {
					if ( is_array( $tokens[ $lookback ] ) && T_STRING === $tokens[ $lookback ][0] && in_array( $tokens[ $lookback ][1], $funcs, true ) ) {
						$translated = true;
						break;
					}
				}

				if ( ! $translated ) {
					$raw[] = str_replace( $root . '/', '', $path ) . ':' . $token[2] . ' ' . $text;
				}
			}
		}

		$this->assertSame( array(), $raw, "Untranslated Chinese literals:\n" . implode( "\n", $raw ) );
	}
}
