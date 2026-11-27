<?php
/**
 * The admin page contract, enforced per route.
 *
 * Rules: docs/开发规则/14-后台页面契约.md.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\App_Manifest;
use Sakura\ImageOptimizer\Admin\Payload_Contract;

/**
 * Every allowlisted route must return the six-key payload.
 *
 * The six pages outside this round's scope (file space and the Zibll tabs)
 * are enumerated but only checked for `page` / `head` / `cards`: they still
 * carry `config` and have no `rest` block until their own migration.
 */
final class PageContractTest extends TestCase {

	/**
	 * Pages this round migrates; they must satisfy the whole contract.
	 *
	 * @var array<int,string>
	 */
	private const STRICT = array(
		'system-settings',
		'image-rules',
		'image-thumb',
		'image-backup',
		'image-stats',
		'storage-connection',
		'storage-sync',
		'storage-deletes',
		'storage-rewrite',
		'storage-cost',
	);

	/**
	 * Pages still on the legacy payload shape.
	 *
	 * @var array<int,string>
	 */
	private const LEGACY = array(
		'files-settings',
		'files-users',
		'files-queue',
		'files-log',
		'files-zibll',
		'zibll-performance',
	);

	/**
	 * Previous wpdb double.
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/**
	 * Install the permissive database double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['sakura_test_options'] = array();
		$GLOBALS['sakura_test_can']     = true;
		$_GET                           = array();

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new PageContract_Wpdb();
	}

	/**
	 * Restore the database double.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb']                = $this->previous_wpdb;
		$GLOBALS['sakura_test_options'] = array();
		$GLOBALS['sakura_test_can']     = true;
		$_GET                           = array();

		parent::tearDown();
	}

	/**
	 * Every route on the allowlist is inspected; the ten migrated pages pass.
	 *
	 * @return void
	 */
	public function test_every_route_meets_the_page_contract(): void {
		$routes = App_Manifest::routes();

		self::assertCount( 16, $routes );

		foreach ( array_keys( $routes ) as $page ) {
			try {
				$raw = App_Manifest::raw_payload( (string) $page );
			} catch ( \Throwable $error ) {
				if ( in_array( $page, self::STRICT, true ) ) {
					self::fail( $page . ': builder threw ' . $error->getMessage() );
				}
				continue;
			}

			self::assertIsArray( $raw, $page . ': no payload' );
			self::assertSame( array(), Payload_Contract::missing( $raw ), $page . ': missing ' . implode( ',', Payload_Contract::missing( $raw ) ) );

			$cards = Payload_Contract::cards( $raw['cards'] ?? array() );
			self::assertNotEmpty( $cards, $page . ': cards must be a non-empty list' );

			foreach ( $cards as $card ) {
				self::assertArrayHasKey( 'id', $card, $page . ': every card needs an id' );
			}

			if ( ! in_array( $page, self::STRICT, true ) ) {
				continue;
			}

			self::assertSame( array(), Payload_Contract::stray_keys( $raw ), $page . ': stray top-level ' . implode( ',', Payload_Contract::stray_keys( $raw ) ) );
			self::assertIsArray( $raw['strings'], $page . ': strings' );
			self::assertNotEmpty( $raw['strings'], $page . ': strings must carry the copy' );
			self::assertNotSame( '', (string) ( $raw['rest']['url'] ?? '' ), $page . ': rest.url' );
			self::assertNotSame( '', (string) ( $raw['rest']['nonce'] ?? '' ), $page . ': rest.nonce' );

			$normalised = Payload_Contract::normalize( $raw, (string) $page );
			self::assertNotSame( '', (string) $normalised['rest']['refresh'], $page . ': rest.refresh' );
		}
	}

	/**
	 * The normaliser adds refresh and turns a card map into an ordered list.
	 *
	 * @return void
	 */
	public function test_normalise_fills_refresh_and_orders_cards(): void {
		$payload = Payload_Contract::normalize(
			array(
				'page'    => array( 'id' => 'x' ),
				'head'    => array(),
				'chrome'  => array(),
				'cards'   => array( 'runtime' => array( 'title' => 'A' ), 'engine' => array( 'title' => 'B' ) ),
				'strings' => array( 'x' => 'y' ),
				'rest'    => array( 'url' => 'https://example.test/rest', 'nonce' => 'n' ),
			),
			'x'
		);

		self::assertSame( array( 'runtime', 'engine' ), array_column( $payload['cards'], 'id' ) );
		self::assertStringContainsString( '/pages/x', (string) $payload['rest']['refresh'] );
	}
}

/**
 * Permissive $wpdb double: payload builders only need shapes, not rows.
 */
class PageContract_Wpdb {

	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $base_prefix = 'wp_';
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $postmeta = 'wp_postmeta';
	/** @var string */
	public $options = 'wp_options';
	/** @var string */
	public $users = 'wp_users';
	/** @var string */
	public $usermeta = 'wp_usermeta';

	/**
	 * @param string $query Query.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return (string) $query;
	}

	/**
	 * @param string $query Query.
	 * @param string $output Output.
	 * @return array<int,mixed>
	 */
	public function get_results( $query = '', $output = 'OBJECT' ) {
		return array();
	}

	/**
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_row( $query = '' ) {
		return null;
	}

	/**
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( $query = '' ) {
		return null;
	}

	/**
	 * @param string $query Query.
	 * @return array<int,mixed>
	 */
	public function get_col( $query = '' ) {
		return array();
	}

	/**
	 * @param string $query Query.
	 * @return int
	 */
	public function query( $query ) {
		return 0;
	}

	/**
	 * @param string $table Table.
	 * @param array  $data  Data.
	 * @return int
	 */
	public function insert( $table, $data ) {
		return 0;
	}

	/**
	 * @return string
	 */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return (string) $text;
	}

	/**
	 * @param string $name Property.
	 * @return string
	 */
	public function __get( $name ) {
		return 'wp_' . $name;
	}
}