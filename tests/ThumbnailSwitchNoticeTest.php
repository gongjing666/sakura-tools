<?php
/**
 * Switching the storage target turns the thumbnail rewrite off.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sakura\ImageOptimizer\Thumbnail\Settings;
use Sakura\ImageOptimizer\Thumbnail_Optimizer_Module;

require_once dirname( __DIR__ ) . '/includes/class-thumbnail-optimizer-module.php';

/**
 * A style name, a media host and a processing template all encode one carrier:
 * after the carrier changes, whatever the rewrite emits points at storage the
 * site no longer uses. The switch is therefore turned off, and the user says
 * yes again on the page where the new host is visible.
 */
final class ThumbnailSwitchNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options']    = array();
		$GLOBALS['sakura_test_transients'] = array();
		$this->reset_flush_state();
	}

	/** A different provider turns the rewrite off. */
	public function test_provider_change_disables_rewrite(): void {
		$this->seed( 1 );

		$this->change( array( 'provider' => 'bitiful' ), array( 'provider' => 'dogecloud' ) );

		$this->assertSame( 0, (int) get_option( Settings::OPTION )['enabled'] );
		$this->assertNotFalse( get_transient( 'sakura_imgopt_thumb_switch_notice' ) );
	}

	/** A different media host turns the rewrite off. */
	public function test_host_change_disables_rewrite(): void {
		$this->seed( 1 );

		$this->change(
			array( 'upload_url_path' => 'https://a.example.com/wp-content/uploads' ),
			array( 'upload_url_path' => 'https://b.example.com/wp-content/uploads' )
		);

		$this->assertSame( 0, (int) get_option( Settings::OPTION )['enabled'] );
	}

	/** Picking another saved profile is a switch even with the same fields. */
	public function test_profile_change_disables_rewrite(): void {
		$this->seed( 1 );

		$this->change( array( 'active_profile_id' => 'prof_a' ), array( 'active_profile_id' => 'prof_b' ) );

		$this->assertSame( 0, (int) get_option( Settings::OPTION )['enabled'] );
	}

	/** Rotating a key is not a switch: the rewrite keeps running. */
	public function test_key_rotation_keeps_rewrite(): void {
		$this->seed( 1 );

		$this->change( array( 'key' => 'old' ), array( 'key' => 'new' ) );

		$this->assertSame( 1, (int) get_option( Settings::OPTION )['enabled'] );
		$this->assertFalse( get_transient( 'sakura_imgopt_thumb_switch_notice' ) );
	}

	/** Object ACL and concurrency are not identity either. */
	public function test_acl_and_concurrency_keep_rewrite(): void {
		$this->seed( 1 );

		$this->change(
			array( 'object_acl' => 'public-read', 'upload_concurrency' => '4' ),
			array( 'object_acl' => 'private', 'upload_concurrency' => '8' )
		);

		$this->assertSame( 1, (int) get_option( Settings::OPTION )['enabled'] );
	}

	/** An already-off switch stays off and raises no notice. */
	public function test_switch_while_off_raises_no_notice(): void {
		$this->seed( 0 );

		$this->change( array( 'provider' => 'bitiful' ), array( 'provider' => 'dogecloud' ) );

		$this->assertSame( 0, (int) get_option( Settings::OPTION )['enabled'] );
		$this->assertFalse( get_transient( 'sakura_imgopt_thumb_switch_notice' ) );
	}

	/** Other stored keys survive the forced save. */
	public function test_switch_keeps_the_rest_of_the_option(): void {
		$this->seed( 1 );

		$this->change( array( 'provider' => 'bitiful' ), array( 'provider' => 'dogecloud' ) );

		$stored = get_option( Settings::OPTION );
		$this->assertSame( 'style', (string) $stored['provider_mode'] );
		$this->assertSame( 'siothumb', (string) $stored['style_map']['thumbnail'] );
	}

	/**
	 * Store an enabled/disabled option with a known style map.
	 *
	 * @param int $enabled 1 or 0.
	 */
	private function seed( int $enabled ): void {
		$opt                    = Settings::defaults();
		$opt['enabled']         = $enabled;
		$opt['provider_mode']   = 'style';
		$opt['style_map']       = array( 'thumbnail' => 'siothumb' );
		update_option( Settings::OPTION, $opt );
		set_transient( 'sakura_imgopt_thumb_switch_notice', 1, 300 );
		delete_transient( 'sakura_imgopt_thumb_switch_notice' );
		Settings::reset();
	}

	/**
	 * Run the option-changed handler.
	 *
	 * @param array<string,mixed> $old Previous options.
	 * @param array<string,mixed> $new New options.
	 */
	private function change( array $old, array $new ): void {
		( new Thumbnail_Optimizer_Module() )->on_s3_options_changed( $old, $new );
		Settings::reset();
	}

	/**
	 * `flush_caches()` runs once per request, so tests must reset its guard.
	 */
	private function reset_flush_state(): void {
		$flushed = new ReflectionProperty( Settings::class, 'caches_flushed' );
		$flushed->setAccessible( true );
		$flushed->setValue( null, false );

		$last = new ReflectionProperty( Settings::class, 'last_flush' );
		$last->setAccessible( true );
		$last->setValue( null, null );
	}
}
