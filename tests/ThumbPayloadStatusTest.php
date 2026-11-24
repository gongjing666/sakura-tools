<?php
/**
 * The thumbnail head status card is recomputed, never snapshotted.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Tests;

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Thumb_Payload;
use Sakura\ImageOptimizer\Thumbnail\Settings;

require_once dirname( __DIR__ ) . '/src/Admin/Thumb_Payload.php';

/**
 * The save endpoint hands these values back after every write, so they must be
 * read from the option that was just stored. A payload built once at boot and
 * never recomputed is exactly the bug this file guards: the switch moves, the
 * card in the page head does not.
 */
final class ThumbPayloadStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['sakura_test_options']    = array();
		$GLOBALS['sakura_test_transients'] = array();
		Settings::reset();
	}

	/** The tone follows the switch that was just written. */
	public function test_status_tone_follows_the_stored_switch(): void {
		$this->seed( 0 );
		$off = ( new Thumb_Payload() )->status();

		$this->seed( 1 );
		$on = ( new Thumb_Payload() )->status();

		$this->assertSame( 'neutral', $off['tone'] );
		$this->assertSame( 'ok', $on['tone'] );
		$this->assertSame( $off['value'], $on['value'], 'Turning the switch on does not change the media host.' );
	}

	/** The sub line names the mode that is actually in force. */
	public function test_status_sub_uses_the_effective_mode(): void {
		$this->seed( 1 );

		$this->assertSame( '命名样式', ( new Thumb_Payload() )->status()['sub'] );
	}

	/** Without a media host the mode degrades, and the card says so. */
	public function test_status_reports_the_degraded_mode(): void {
		$this->seed( 1 );
		update_option( 's3_options', array() );
		Settings::reset();

		$status = ( new Thumb_Payload() )->status();

		$this->assertSame( '未找到图片域名', $status['value'] );
		$this->assertSame( '不处理', $status['sub'] );
	}

	/** One alert depends on the switch, so it has to be recomputed too. */
	public function test_alerts_warn_when_the_rewrite_cannot_take_effect(): void {
		$this->seed( 1, array( 'engine' => 'url' ) );
		update_option( 's3_options', array() );
		Settings::reset();

		$tones = array_column( ( new Thumb_Payload() )->alerts(), 'tone' );

		$this->assertContains( 'warning', $tones );
	}

	/**
	 * Store an option with a known style map and a reachable media host.
	 *
	 * @param int                 $enabled 1 or 0.
	 * @param array<string,mixed> $extra   Extra option keys.
	 */
	private function seed( int $enabled, array $extra = array() ): void {
		$opt                  = array_merge(
			Settings::defaults(),
			array(
				'enabled'       => $enabled,
				'provider_mode' => 'style',
				'style_map'     => array( 'thumbnail' => 'siothumb' ),
			),
			$extra
		);

		update_option( Settings::OPTION, $opt );
		update_option(
			's3_options',
			array(
				'provider'        => 'bitiful',
				'upload_url_path' => 'https://bf.example.com',
			)
		);

		Settings::reset();
	}
}
