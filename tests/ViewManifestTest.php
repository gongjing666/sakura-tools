<?php
/**
 * View manifest guard.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Keeps includes/views/ and its call sites in sync.
 */
final class ViewManifestTest extends TestCase {

	/**
	 * Every PHP view shipped by the plugin, sorted by file name.
	 *
	 * Add a view here when adding it to includes/views/.
	 */
	private const VIEWS = array(
		'system-settings.php',
		'files-settings-island.php',
		'files-admin-island.php',
		'files-zibll-island.php',
		'zibll-performance-island.php',
		'editor-picker-app.php',
		'media-progress.php',
		'sync-island.php',
		'settings-island.php',
		'storage-island.php',
		'deletes-island.php',
		'rewrite-island.php',
		'cost-island.php',
		'userfiles-space-panel.php',
		'userfiles-tab.php',
	);

	/**
	 * The manifest and the directory agree in both directions.
	 */
	public function test_manifest_matches_view_directory(): void {
		$actual = array_map(
			'basename',
			(array) glob( dirname( __DIR__ ) . '/includes/views/*.php' )
		);
		sort( $actual );

		$expected = self::VIEWS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$actual,
			'Update ViewManifestTest::VIEWS when adding or removing a view.'
		);
	}

	/**
	 * Every view is included through a views/<file> path outside the view directory.
	 */
	public function test_every_view_is_referenced(): void {
		$root = dirname( __DIR__ );
		$code = $this->plugin_source( $root );

		$unreferenced = array();
		foreach ( self::VIEWS as $view ) {
			if ( false === strpos( $code, 'views/' . $view ) ) {
				$unreferenced[] = $view;
			}
		}

		$this->assertSame(
			array(),
			$unreferenced,
			"Views not referenced outside includes/views:\n" . implode( "\n", $unreferenced )
		);
	}

	/**
	 * Concatenates PHP source outside generated, test and view directories.
	 *
	 * Comments are stripped so a view mentioned only in documentation does not
	 * count as a call site.
	 */
	private function plugin_source( string $root ): string {
		$skip     = array( '/vendor/', '/node_modules/', '/tests/', '/stubs/', '/.git/', '/includes/views/' );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				static function ( $file ) use ( $skip ) {
					$path = $file->getPathname() . ( $file->isDir() ? '/' : '' );
					foreach ( $skip as $segment ) {
						if ( false !== strpos( $path, $segment ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		$code = '';
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );
			if ( false === $source ) {
				continue;
			}

			$code .= $this->strip_comments( $source );
		}

		return $code;
	}

	/**
	 * Removes comments while preserving strings and inline HTML.
	 */
	private function strip_comments( string $source ): string {
		$clean = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) ) {
				continue;
			}
			$clean .= is_array( $token ) ? $token[1] : $token;
		}
		return $clean;
	}
}
