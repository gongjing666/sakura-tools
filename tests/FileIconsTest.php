<?php
/**
 * The bundled file-type icons must ship with the plugin.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests {
	use PHPUnit\Framework\TestCase;

	/**
	 * Static asset checks for `assets/file-icons/`.
	 *
	 * A missing SVG does not fail loudly in the browser: the row silently falls
	 * back to the text chip, which looks like "the icon set does not work". The
	 * list lives in Tab::file_icons(), so the two have to be checked together.
	 */
	final class FileIconsTest extends TestCase {

		/**
		 * Icon keys the frontend can ask for.
		 *
		 * @return string[]
		 */
		private function expected(): array {
			return array(
				'pdf',
				'word',
				'excel',
				'powerpoint',
				'text',
				'markdown',
				'zip',
				'audio',
				'video',
				'image',
				'photoshop',
				'php',
				'js',
				'typescript',
				'css',
				'html',
				'json',
				'xml',
				'sql',
				'python',
				'csv',
				'android',
				'binary',
			);
		}

		/**
		 * Absolute path to the icon directory.
		 *
		 * @return string
		 */
		private function dir(): string {
			return dirname( __DIR__ ) . '/assets/file-icons/';
		}

		public function test_every_icon_the_script_data_exposes_exists(): void {
			foreach ( $this->expected() as $name ) {
				$path = $this->dir() . $name . '.svg';

				$this->assertFileExists( $path, 'missing icon: ' . $name );
				$this->assertGreaterThan( 100, (int) filesize( $path ), 'icon looks empty: ' . $name );
			}
		}

		public function test_each_icon_is_a_standalone_svg(): void {
			foreach ( $this->expected() as $name ) {
				$svg = (string) file_get_contents( $this->dir() . $name . '.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled asset in a test.

				$this->assertStringContainsString( '<svg', $svg, $name );
				$this->assertStringContainsString( 'viewBox', $svg, $name );
				$this->assertStringNotContainsString( '<?xml', ltrim( $svg ), $name . ' should start with <svg for <img> use' );
			}
		}

		public function test_the_licence_travels_with_the_icons(): void {
			$licence = (string) file_get_contents( $this->dir() . 'LICENSE' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled asset in a test.

			$this->assertStringContainsString( 'MIT', $licence );
			$this->assertStringContainsString( 'vscode-icons', $licence );
		}

		public function test_the_plugin_does_not_bundle_a_stray_icon(): void {
			$files = glob( $this->dir() . '*.svg' );
			$this->assertIsArray( $files );

			$bundled = array_map(
				static function ( string $path ): string {
					return basename( $path, '.svg' );
				},
				$files
			);

			sort( $bundled );
			$expected = $this->expected();
			sort( $expected );

			$this->assertSame( $expected, $bundled, 'assets/file-icons/ must match Tab::file_icons()' );
		}
	}
}
