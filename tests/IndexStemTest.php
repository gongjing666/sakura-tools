<?php
/**
 * Index keys must be matched by stem, not only by the extension the metadata
 * currently names.
 *
 * A restore writes the original extension back and a later optimisation
 * converts it again, so one attachment can leave both a .webp and a .jpg row
 * behind. Deleting the attachment has to clear both, otherwise the leftover row
 * counts as a phantom "synced" file forever.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Sync\Index_Repository;

final class IndexStemTest extends TestCase {

	public function test_stem_strips_the_extension(): void {
		$this->assertSame(
			'wp-content/uploads/2026/11/20261126-183729-Pfmb7-Sakura',
			Index_Repository::stem( 'wp-content/uploads/2026/11/20261126-183729-Pfmb7-Sakura.webp' )
		);
		$this->assertSame(
			'wp-content/uploads/2026/11/20261126-183729-Pfmb7-Sakura',
			Index_Repository::stem( 'wp-content/uploads/2026/11/20261126-183729-Pfmb7-Sakura.jpg' )
		);
	}

	public function test_stem_ignores_dots_inside_directories(): void {
		$this->assertSame(
			'wp-content/uploads/v1.2/file',
			Index_Repository::stem( 'wp-content/uploads/v1.2/file.png' )
		);
	}

	public function test_stem_returns_empty_without_an_extension(): void {
		$this->assertSame( '', Index_Repository::stem( 'wp-content/uploads/2026/11/README' ) );
		$this->assertSame( '', Index_Repository::stem( 'wp-content/uploads/.hidden' ) );
		$this->assertSame( '', Index_Repository::stem( '' ) );
	}
}
