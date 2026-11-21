<?php
/**
 * Page head copy coverage.
 *
 * Every registered tab renders through UI\Page, which falls back to
 * Hero::tagline( $page ) for the sentence under the title. A page without an
 * entry silently renders without the gradient line, which looks like a bug to
 * anyone comparing screens — so the map is checked against the registry.
 *
 * @package Sakura_ImageOptimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Admin\UI\Hero;

require_once dirname( __DIR__, 2 ) . '/src/Admin/Registry.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/UI/Hero.php';

/**
 * Covers Hero::tagline() against the tabs the plugin actually registers.
 */
final class HeroTaglineTest extends TestCase {

	/**
	 * Every tab needs its own sentence, with exactly one <em> for the gradient.
	 */
	public function test_every_registered_tab_has_a_tagline(): void {
		$missing = array();

		foreach ( Registry::modules() as $module => $definition ) {
			foreach ( (array) $definition['tabs'] as $tab => $unused ) {
				$page = $module . '-' . $tab;

				if ( '' === Hero::tagline( $page ) ) {
					$missing[] = $page;
				}
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'Add a line for each page to src/Admin/UI/Hero.php::taglines(); otherwise the page head has no gradient sentence.'
		);
	}

	/**
	 * The gradient is a highlight: more than one <em> turns the whole sentence
	 * into low-contrast decoration.
	 */
	public function test_each_tagline_highlights_once(): void {
		foreach ( Registry::modules() as $module => $definition ) {
			foreach ( (array) $definition['tabs'] as $tab => $unused ) {
				$page = $module . '-' . $tab;
				$line = Hero::tagline( $page );

				if ( '' === $line ) {
					continue;
				}

				$this->assertSame( 1, substr_count( $line, '<em>' ), 'Hero tagline for ' . $page . ' should emphasise exactly one phrase.' );
			}
		}
	}
}
