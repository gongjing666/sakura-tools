<?php
/**
 * Backup exposure probe tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\Backup\Exposure_Probe;

/**
 * Verifies the probe's verdict table and server detection.
 */
final class ExposureProbeTest extends TestCase {

	/**
	 * A 200 response serving an image means the directory is exposed.
	 */
	public function test_image_response_is_exposed(): void {
		$verdict = Exposure_Probe::classify( 200, 'image/webp', 'binary', '' );
		$this->assertSame( 'exposed', $verdict['state'] );
	}

	/**
	 * A 200 text/plain response must contain the sentinel content.
	 */
	public function test_sentinel_must_match(): void {
		$this->assertSame(
			'exposed',
			Exposure_Probe::classify( 200, 'text/plain', 'sakura-probe-abc', 'sakura-probe-abc' )['state']
		);

		$mismatch = Exposure_Probe::classify( 200, 'text/plain', 'other content', 'sakura-probe-abc' );
		$this->assertSame( 'unknown', $mismatch['state'] );
		$this->assertSame( 'body-mismatch', $mismatch['detail'] );
	}

	/**
	 * HTML served with 200 is usually a themed 404 page, not the file.
	 */
	public function test_html_response_is_unknown(): void {
		$verdict = Exposure_Probe::classify( 200, 'text/html; charset=UTF-8', '<html></html>', '' );
		$this->assertSame( 'unknown', $verdict['state'] );
		$this->assertSame( 'html', $verdict['detail'] );
	}

	/**
	 * Denied status codes mean the directory is protected.
	 *
	 * @dataProvider denied_codes
	 */
	public function test_denied_codes_are_protected( int $code ): void {
		$this->assertSame( 'protected', Exposure_Probe::classify( $code, 'text/html', '', '' )['state'] );
	}

	/**
	 * Status codes that deny access.
	 *
	 * @return array<string,array{int}>
	 */
	public static function denied_codes(): array {
		return array(
			'401 unauthorized' => array( 401 ),
			'403 forbidden'    => array( 403 ),
			'404 not found'    => array( 404 ),
			'410 gone'         => array( 410 ),
		);
	}

	/**
	 * Anything else is inconclusive rather than reported as exposed.
	 */
	public function test_other_codes_are_unknown(): void {
		foreach ( array( 204, 301, 500, 502, 0 ) as $code ) {
			$this->assertSame( 'unknown', Exposure_Probe::classify( $code, 'text/plain', 'x', '' )['state'] );
		}
	}

	/**
	 * Server detection maps common `SERVER_SOFTWARE` values.
	 */
	public function test_server_detection(): void {
		$original = $_SERVER['SERVER_SOFTWARE'] ?? null;

		$cases = array(
			'nginx/1.24.0'              => array( 'Nginx', false ),
			'openresty/1.21.4.1'        => array( 'OpenResty', false ),
			'Apache/2.4.58 (Ubuntu)'    => array( 'Apache', true ),
			'LiteSpeed'                 => array( 'LiteSpeed', true ),
			'Microsoft-IIS/10.0'        => array( 'IIS', false ),
		);

		try {
			foreach ( $cases as $software => $expected ) {
				$_SERVER['SERVER_SOFTWARE'] = $software;
				$server                     = Exposure_Probe::server();
				$this->assertSame( $expected[0], $server['label'], $software );
				$this->assertSame( $expected[1], $server['reads_htaccess'], $software );
			}

			$_SERVER['SERVER_SOFTWARE'] = '';
			$server                     = Exposure_Probe::server();
			$this->assertStringStartsWith( 'PHP ', $server['label'] );
			$this->assertNull( $server['reads_htaccess'] );
		} finally {
			if ( null === $original ) {
				unset( $_SERVER['SERVER_SOFTWARE'] );
			} else {
				$_SERVER['SERVER_SOFTWARE'] = $original;
			}
		}
	}

	/**
	 * A missing result is reported as null, not as a fake verdict.
	 */
	public function test_latest_without_option_is_null(): void {
		unset( $GLOBALS['sakura_test_options'][ Exposure_Probe::OPTION ] );
		$this->assertNull( Exposure_Probe::latest() );
	}
}
