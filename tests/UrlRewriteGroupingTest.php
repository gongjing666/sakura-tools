<?php
/**
 * URL rewrite task grouping tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Url_Rewrite_Service;

/**
 * Row grouping decides what the default selection touches, so it is tested
 * without a database: only the site's own theme and plugin rows may be
 * pre-selected, and an unrelated plugin's options must land in "other".
 */
final class UrlRewriteGroupingTest extends TestCase {

	/**
	 * Call the private grouping helper.
	 *
	 * @param string $table  Table name.
	 * @param string $option Option name for options-table rows.
	 */
	private function group( string $table, string $option = '' ): string {
		$method = new ReflectionMethod( Url_Rewrite_Service::class, 'group_for' );
		$method->setAccessible( true );

		return (string) $method->invoke( null, $table, $option );
	}

	/**
	 * Options are split by the component that owns them.
	 */
	public function test_options_are_split_by_owner(): void {
		$wpdb = new Sakura_Test_Wpdb();
		$wpdb->install();

		try {
			$this->assertSame( 'theme', $this->group( 't_options', 'zibll_options' ) );
			$this->assertSame( 'theme', $this->group( 't_options', 'zibll_options_backup' ) );
			$this->assertSame( 'plugin', $this->group( 't_options', 's3_options' ) );
			$this->assertSame( 'plugin', $this->group( 't_options', 'sakura_imgopt_oss_thumb' ) );
			$this->assertSame( 'plugin', $this->group( 't_options', 'sakura_imgopt_backup_probe' ) );
		} finally {
			$wpdb->restore();
		}
	}

	/**
	 * Foreign plugin options stay out of the default selection.
	 */
	public function test_foreign_options_are_other(): void {
		$wpdb = new Sakura_Test_Wpdb();
		$wpdb->install();

		try {
			$this->assertSame( 'other', $this->group( 't_options', 'elementor_settings' ) );
			$this->assertSame( 'other', $this->group( 't_options', 'woocommerce_db_version' ) );
		} finally {
			$wpdb->restore();
		}
	}

	/**
	 * Tables are grouped by name.
	 */
	public function test_tables_are_grouped_by_name(): void {
		$wpdb = new Sakura_Test_Wpdb();
		$wpdb->install();

		try {
			$this->assertSame( 'content', $this->group( 't_posts' ) );
			$this->assertSame( 'content', $this->group( 't_postmeta' ) );
			$this->assertSame( 'users', $this->group( 't_usermeta' ) );
			$this->assertSame( 'plugin', $this->group( 't_sufm_file_tokens' ) );
			$this->assertSame( 'plugin', $this->group( 't_zse_lottery_prize_catalog' ) );
			$this->assertSame( 'other', $this->group( 't_custom_table' ) );
		} finally {
			$wpdb->restore();
		}
	}
}
