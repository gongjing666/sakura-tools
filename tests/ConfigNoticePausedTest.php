<?php
/**
 * Gating for the "object storage is paused" dialog.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\S3\Config_Notice;
use Sakura\ImageOptimizer\S3\Options;

require_once dirname( __DIR__ ) . '/src/Admin/Registry.php';
require_once dirname( __DIR__ ) . '/src/S3/Config_Notice.php';

/**
 * A complete connection with the master switch off is a state of its own:
 * it is not a configuration error, so the setup dialog stays silent, and the
 * sync center has no way to say the bucket stopped receiving files. These
 * tests pin the fourth state and, just as importantly, that it never fires at
 * the same time as the other two dialogs.
 */
final class ConfigNoticePausedTest extends TestCase {

	/**
	 * Reset the capability and per-user meta shims between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['sakura_test_can']       = true;
		$GLOBALS['sakura_test_user_meta'] = array();
	}

	/**
	 * Tear down the shims so no other test inherits them.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['sakura_test_can'], $GLOBALS['sakura_test_user_meta'] );
	}

	/**
	 * Build a fully configured storage snapshot.
	 *
	 * @param string $enabled Either "true" or "false".
	 * @return Options
	 */
	private function options( string $enabled ): Options {
		return new Options(
			array(
				'enabled'  => $enabled,
				'provider' => 'bitiful',
				'bucket'   => 'text-demo',
				'endpoint' => 'https://s3.bitiful.net',
				'key'      => 'access-key',
				'secret'   => 'secret-key',
			)
		);
	}

	/**
	 * The dialog exists for exactly one combination: fields complete, switch
	 * off, and the operator has not muted it.
	 */
	public function test_prompts_only_for_a_complete_but_disabled_configuration(): void {
		$this->assertTrue( Config_Notice::should_prompt_paused( $this->options( 'false' ) ) );
		$this->assertFalse( Config_Notice::should_prompt_paused( $this->options( 'true' ) ) );
	}

	/**
	 * A missing bucket is the setup dialog's business, not this one's; the two
	 * must never render together.
	 */
	public function test_missing_fields_belong_to_the_setup_dialog_instead(): void {
		$incomplete = new Options(
			array(
				'enabled'  => 'false',
				'provider' => 'bitiful',
				'bucket'   => '',
				'endpoint' => 'https://s3.bitiful.net',
				'key'      => 'access-key',
				'secret'   => 'secret-key',
			)
		);

		$this->assertNotSame( array(), $incomplete->configuration_errors() );
		$this->assertFalse( Config_Notice::should_prompt_paused( $incomplete ) );
		$this->assertTrue( Config_Notice::should_show( $incomplete ) );
	}

	/**
	 * Complete-but-disabled is not a setup error, so the setup dialog stays
	 * silent and the paused one takes over.
	 */
	public function test_the_two_dialogs_are_mutually_exclusive(): void {
		$disabled = $this->options( 'false' );

		$this->assertFalse( Config_Notice::should_show( $disabled ) );
		$this->assertTrue( Config_Notice::should_prompt_paused( $disabled ) );
		$this->assertSame( '', Config_Notice::modal_html( $disabled, 'sync' ) );
	}

	/**
	 * "Do not remind me again" is a permanent per-user mute shared with the
	 * setup dialog.
	 */
	public function test_dismissed_users_are_not_prompted(): void {
		$GLOBALS['sakura_test_user_meta'][1][ Config_Notice::META_KEY ] = '1';

		$this->assertFalse( Config_Notice::should_prompt_paused( $this->options( 'false' ) ) );
	}

	/**
	 * A plain close no longer parks the dialog. An operator who dismissed it by
	 * reflex still has an unfinished problem, and this dialog is the only thing
	 * on the screen that says so.
	 */
	public function test_a_plain_close_does_not_quiet_the_dialog(): void {
		$options = $this->options( 'false' );

		$this->assertTrue( Config_Notice::should_prompt_paused( $options ) );
		// The X, the backdrop and the ghost button all close without writing.
		$this->assertSame( 3, substr_count( Config_Notice::paused_html( $options ), 'data-config-close' ) );
	}

	/**
	 * The twelve-hour window is gone. A timestamp left behind by 1.25.6 or
	 * earlier must neither suppress the prompt nor survive the visit.
	 */
	public function test_a_leftover_snooze_from_an_older_version_is_ignored_and_removed(): void {
		$GLOBALS['sakura_test_user_meta'][1][ Config_Notice::SNOOZE_META_KEY ] = (string) ( time() + 3600 );

		$this->assertTrue( Config_Notice::should_prompt_paused( $this->options( 'false' ) ) );
		$this->assertArrayNotHasKey( Config_Notice::SNOOZE_META_KEY, $GLOBALS['sakura_test_user_meta'][1] );
	}

	/**
	 * The dialog is an operator prompt: someone who cannot manage options
	 * never sees it.
	 */
	public function test_users_without_the_capability_are_never_prompted(): void {
		$GLOBALS['sakura_test_can'] = false;

		$this->assertFalse( Config_Notice::should_prompt_paused( $this->options( 'false' ) ) );
	}

	/**
	 * The primary action navigates to the connection screen, where the S3
	 * switch lives.
	 */
	public function test_the_primary_action_links_to_the_connection_screen(): void {
		$html = Config_Notice::paused_html( $this->options( 'false' ) );

		$this->assertStringContainsString( 'id="sakura-s3-paused-modal"', $html );
		$this->assertStringContainsString( 'id="sakura-s3-paused-title"', $html );
		$this->assertStringContainsString( 'id="sakura-s3-paused-desc"', $html );
		$this->assertStringContainsString( 'OBJECT STORAGE / PAUSED', $html );
		$this->assertStringContainsString( 'data-config-primary', $html );
		$this->assertStringContainsString( 'data-config-dont-remind', $html );
		$this->assertStringContainsString( 'admin.php?page=sakura-tools-storage', $html );
		$this->assertStringContainsString( 'data-nonce="nonce-sakura_s3_config_notice"', $html );
		$this->assertStringNotContainsString( 'href="#sakura-s3-connection"', $html );
	}

	/**
	 * Nothing renders for the two states this dialog does not own.
	 */
	public function test_nothing_renders_when_enabled_or_incomplete(): void {
		$this->assertSame( '', Config_Notice::paused_html( $this->options( 'true' ) ) );

		$incomplete = new Options( array( 'enabled' => 'false', 'provider' => 'bitiful' ) );
		$this->assertSame( '', Config_Notice::paused_html( $incomplete ) );
	}

	/**
	 * The primary target is resolved through the registry, so the paused
	 * dialog keeps working if the storage slug ever moves.
	 */
	public function test_the_primary_url_follows_the_registry(): void {
		$this->assertStringContainsString(
			Registry::url( 'storage', 'connection' ),
			Config_Notice::paused_html( $this->options( 'false' ) )
		);
	}
}
