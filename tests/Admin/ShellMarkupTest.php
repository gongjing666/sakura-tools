<?php
/**
 * Shell markup contract.
 *
 * The top bar and the tab strip are printed by PHP and, with the
 * sakura_imgopt_admin_app experiment on, hydrated by React. Hydration compares
 * nodes and text, so the two renderers have to agree on structure, on the text
 * inside each node, and on whitespace — a newline between two tags is a text
 * node the client tree does not have, and React then throws the server tree
 * away and rebuilds it (measured against React 19).
 *
 * This test locks that contract: assets/ui/src/shared/ui.jsx is rendered to
 * static markup from the same chrome description (npm run fixture), and the
 * two trees are compared node by node. Change one side without the other and
 * this fails, with the byte offset of the first difference.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Shell;

if ( ! defined( 'SAKURA_TOOLS_PATH' ) ) {
	define( 'SAKURA_TOOLS_PATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/src/Admin/Route.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Registry.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/UI.php';
require_once dirname( __DIR__, 2 ) . '/src/Admin/Shell.php';

final class ShellMarkupTest extends TestCase {

	/**
	 * The chrome data and the markup React produces from it.
	 *
	 * @return array{module:string,tab:string,args:array<string,mixed>,chrome:array<string,mixed>}
	 */
	private function fixture(): array {
		$path = dirname( __DIR__ ) . '/fixtures/admin-shell.json';
		$raw  = file_get_contents( $path );

		self::assertIsString( $raw, 'tests/fixtures/admin-shell.json is missing; run npm run fixture.' );

		$data = json_decode( $raw, true );

		self::assertIsArray( $data, 'tests/fixtures/admin-shell.json is not valid JSON; run npm run fixture.' );

		return $data;
	}

	/**
	 * The markup React printed for that description.
	 *
	 * @return string
	 */
	private function react_markup(): string {
		$path = dirname( __DIR__ ) . '/fixtures/admin-shell.html';
		$raw  = file_get_contents( $path );

		self::assertIsString( $raw, 'tests/fixtures/admin-shell.html is missing; run npm run fixture.' );

		return trim( $raw );
	}

	/**
	 * The two renderers print the same tree.
	 *
	 * @return void
	 */
	public function test_php_shell_matches_the_react_markup(): void {
		$fixture = $this->fixture();
		$php     = Shell::html( $fixture['module'], $fixture['tab'], $fixture['args'] );

		$this->assertSameTree( $this->tree( $this->react_markup() ), $this->tree( $php ) );
	}

	/**
	 * The fixture still describes what the server says today.
	 *
	 * A new module, a renamed tab or an edited brand SVG shows up here first:
	 * the fixture is a snapshot, and refreshing it is a deliberate act.
	 *
	 * @return void
	 */
	public function test_the_fixture_describes_the_current_chrome(): void {
		$fixture = $this->fixture();

		self::assertSame(
			$fixture['chrome'],
			Shell::header_data( $fixture['module'], $fixture['tab'], $fixture['args'] ),
			'The chrome description changed; run npm run fixture to refresh tests/fixtures/admin-shell.html.'
		);
	}

	/**
	 * No whitespace-only text nodes anywhere in the shell.
	 *
	 * This is the rule that hydration punishes hardest: the browser merges the
	 * two strings of a split text run into one node, and leaves a newline
	 * between two tags as a node React never creates.
	 *
	 * @return void
	 */
	public function test_the_shell_prints_no_whitespace_text_nodes(): void {
		$fixture = $this->fixture();
		$html    = Shell::html( $fixture['module'], $fixture['tab'], $fixture['args'] );
		$xpath   = new DOMXPath( $this->document( $html ) );

		$blank = array();

		foreach ( $xpath->query( '//text()[normalize-space(.) = ""]' ) as $node ) {
			$blank[] = (string) $node->nodeValue;
		}

		self::assertSame(
			array(),
			$blank,
			'Whitespace between two tags becomes a text node React does not render, and hydration fails.'
		);
	}

	/**
	 * Every navigation target names the page it opens.
	 *
	 * The shell router intercepts a click by reading data-sakura-route; a link
	 * without one silently becomes a full document navigation.
	 *
	 * @return void
	 */
	public function test_every_navigation_target_carries_its_page_id(): void {
		$fixture = $this->fixture();
		$html    = Shell::html( $fixture['module'], $fixture['tab'], $fixture['args'] );
		$xpath   = new DOMXPath( $this->document( $html ) );

		$routes = array();

		foreach ( $xpath->query( '//*[@data-sakura-route]' ) as $node ) {
			$route = $node instanceof DOMElement ? (string) $node->getAttribute( 'data-sakura-route' ) : '';

			self::assertNotSame( '', $route, 'A shell link carries an empty route id.' );
			self::assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $route );

			$routes[] = $route;
		}

		$expected = count( $fixture['chrome']['mods'] );
		foreach ( $fixture['chrome']['tabs'] as $tab ) {
			if ( ! empty( $tab['enabled'] ) ) {
				$expected++;
			}
		}

		self::assertCount( $expected, $routes );
	}

	/**
	 * Parse a fragment the way the browser would.
	 *
	 * @param string $html Fragment.
	 * @return DOMDocument
	 */
	private function document( string $html ): DOMDocument {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML(
			'<?xml encoding="UTF-8"><div id="sakura-probe">' . trim( $html ) . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $document;
	}

	/**
	 * Canonical description of a fragment's tree.
	 *
	 * Attributes are compared after decoding — &amp; and &#038; are the same
	 * URL — and in a stable order. Text is compared byte for byte, so a stray
	 * newline is a difference and a merged run is not silently accepted.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private function tree( string $html ): string {
		$document = $this->document( $html );
		$probe    = $document->documentElement;

		self::assertInstanceOf( DOMElement::class, $probe, 'The shell markup is not parseable HTML.' );

		return $this->walk( $probe );
	}

	/**
	 * Walk one node into the canonical form.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function walk( DOMNode $node ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$out .= 'text(' . $child->nodeValue . '){';
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof DOMElement ) {
				continue;
			}

			$attributes = array();

			foreach ( $child->attributes as $attribute ) {
				$attributes[ strtolower( $attribute->nodeName ) ] = (string) $attribute->nodeValue;
			}

			ksort( $attributes );

			$out .= 'element(' . strtolower( $child->nodeName );

			foreach ( $attributes as $name => $value ) {
				$out .= ' ' . $name . '=' . $value;
			}

			$out .= '){' . $this->walk( $child ) . '}';
		}

		return $out;
	}

	/**
	 * Assert two canonical trees are identical, and say where they part ways.
	 *
	 * @param string $expected React's tree.
	 * @param string $actual   PHP's tree.
	 * @return void
	 */
	private function assertSameTree( string $expected, string $actual ): void {
		// A tree that failed to parse would otherwise make this assertion
		// vacuous: two empty strings compare equal and the contract looks locked.
		self::assertNotSame( '', $expected, 'The React fixture produced no parseable tree.' );
		self::assertNotSame( '', $actual, 'Shell::render() produced no parseable tree.' );
		self::assertGreaterThan( 10, substr_count( $expected, 'element(' ), 'The canonical tree is too small to be the chrome.' );

		if ( $expected === $actual ) {
			self::assertTrue( true );

			return;
		}

		$at  = 0;
		$max = min( strlen( $expected ), strlen( $actual ) );

		while ( $at < $max && $expected[ $at ] === $actual[ $at ] ) {
			$at++;
		}

		self::fail(
			sprintf(
				"The shell markup and assets/ui/src/shared/ui.jsx disagree at byte %d of the canonical tree.\n\nReact: %1$s\nPHP:   %2$s\n\nChange both renderers, then run npm run fixture.",
				$at,
				substr( $expected, max( 0, $at - 80 ), 240 ),
				substr( $actual, max( 0, $at - 80 ), 240 )
			)
		);
	}
}
