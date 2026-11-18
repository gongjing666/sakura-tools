<?php
/**
 * S3 connection probe tests.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Sakura\ImageOptimizer\S3\Connection_Probe;
use Sakura\Vendor\Aws\S3\S3Client;

/**
 * Loads the prefixed SDK so the fake can extend the real client.
 */
if ( ! class_exists( S3Client::class ) ) {
	require_once dirname( __DIR__ ) . '/vendor/s3/vendor-prefixed/autoload.php';
}

/**
 * Stands in for the bundled SDK client and records which calls were made.
 */
final class Probe_Fake_Client extends S3Client {

	/**
	 * Failure queued for headBucket.
	 *
	 * @var \Throwable|null
	 */
	public $head_failure;

	/**
	 * Failure queued for listObjectsV2.
	 *
	 * @var \Throwable|null
	 */
	public $list_failure;

	/**
	 * Calls made, in order.
	 *
	 * @var array<int,string>
	 */
	public $calls = array();

	/**
	 * Accept any configuration.
	 *
	 * @param array<string,mixed> $config Config.
	 */
	public function __construct( array $config = array() ) {
		unset( $config );
	}

	/**
	 * Record and maybe fail.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	public function headBucket( array $args ): array {
		$this->calls[] = 'headBucket';
		if ( null !== $this->head_failure ) {
			throw $this->head_failure;
		}
		return array();
	}

	/**
	 * Record and maybe fail.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	public function listObjectsV2( array $args ): array {
		$this->calls[] = 'listObjectsV2';
		if ( null !== $this->list_failure ) {
			throw $this->list_failure;
		}
		return array();
	}
}

/**
 * Covers the diagnostics that replace the generic "check everything" line.
 */
final class ConnectionProbeTest extends TestCase {

	/**
	 * A bucket that answers HEAD is reported as reachable with no extra call.
	 */
	public function test_head_success_is_one_call(): void {
		$client = new Probe_Fake_Client();
		$result = Connection_Probe::diagnose( $client, 'bucket' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['message'] );
		$this->assertSame( array( 'headBucket' ), $client->calls );
	}

	/**
	 * HEAD refused but listing allowed still counts as usable, with a note.
	 */
	public function test_head_refused_but_list_allowed(): void {
		$client               = new Probe_Fake_Client();
		$client->head_failure = new RuntimeException( '403 Forbidden', 403 );

		$result = Connection_Probe::diagnose( $client, 'bucket' );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'HEAD', $result['message'] );
		$this->assertSame( array( 'headBucket', 'listObjectsV2' ), $client->calls );
	}

	/**
	 * Both calls failing surfaces the real code from the GET body.
	 *
	 * This is the case the old test hid: the SDK had no code for the bare HEAD
	 * 403, so the operator was told to check every setting.
	 */
	public function test_invalid_key_is_named(): void {
		$client               = new Probe_Fake_Client();
		$client->head_failure = new RuntimeException( '403 Forbidden' );
		$client->list_failure = new RuntimeException( '<Error><Code>InvalidAccessKeyId</Code><Message>The Access Key Id you provided does not exist in our records.</Message></Error>' );

		$result = Connection_Probe::diagnose( $client, 'bucket', array( 'AKIALONGSECRETKEYVALUE' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'InvalidAccessKeyId', $result['code'] );
		$this->assertStringContainsString( 'InvalidAccessKeyId', $result['message'] );
		$this->assertStringContainsString( 'Access Key Id you provided does not exist', $result['message'] );
	}

	/**
	 * A credential echoed back by a proxy never reaches the operator message.
	 *
	 * The key here is deliberately not an AWS error code, so the server text is
	 * appended verbatim and the redaction is what has to hide it.
	 */
	public function test_secret_is_redacted(): void {
		$client               = new Probe_Fake_Client();
		$client->head_failure = new RuntimeException( '403 Forbidden' );
		$client->list_failure = new RuntimeException( 'Proxy rejected request for AccessKey AKIAEXAMPLEKEY12345 at the gateway' );

		$result = Connection_Probe::diagnose( $client, 'bucket', array( 'AKIAEXAMPLEKEY12345' ) );

		$this->assertStringNotContainsString( 'AKIAEXAMPLEKEY12345', $result['message'] );
		$this->assertStringContainsString( '***', $result['message'] );
	}

	/**
	 * 凭据没取到的时候，「Endpoint 没有响应」是纯粹的误导。
	 *
	 * 两个请求根本没发出去，既没有 HTTP 状态也没有错误码——这时候该说的是
	 * 「多吉云接口怎么了」，而不是让用户去查服务地址和证书。
	 */
	public function test_credential_failure_is_not_reported_as_a_dead_endpoint(): void {
		$reason = '多吉云接口返回错误（code 403）：AccessKey Format Error。请检查 Access Key 和 Secret Key。';
		$client               = new Probe_Fake_Client();
		$client->head_failure = new \Sakura\ImageOptimizer\S3\S3_Credential_Error( $reason );
		$client->list_failure = new \Sakura\ImageOptimizer\S3\S3_Credential_Error( $reason );

		$result = Connection_Probe::diagnose( $client, 'bucket' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'AccessKey Format Error', $result['message'] );
		$this->assertStringNotContainsString( 'Endpoint 没有响应', $result['message'] );
	}

	/**
	 * Nothing at all reaching the service is described as unreachable.
	 */
	public function test_transport_failure_explains_endpoint(): void {
		$client               = new Probe_Fake_Client();
		$client->head_failure = new RuntimeException( 'cURL error 6: Could not resolve host: s3.example.net' );
		$client->list_failure = new RuntimeException( 'cURL error 6: Could not resolve host: s3.example.net' );

		$result = Connection_Probe::diagnose( $client, 'bucket' );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Endpoint', $result['message'] );
	}

	/**
	 * A 404 from a management API host carries no XML code, so the code has to
	 * come out of the exception text — that is what tells the operator the
	 * address, not the key, is wrong.
	 */
	public function test_path_style_rejection_is_named(): void {
		$client               = new Probe_Fake_Client();
		$client->head_failure = new RuntimeException( '403 Forbidden' );
		$client->list_failure = new RuntimeException( 'Error executing "ListObjectsV2"; AWS HTTP error: Client error: 403 PathStyleDomainForbidden The bucket you are attempting to access must be addressed using COS virtual-styled domain.' );

		$result = Connection_Probe::diagnose( $client, 'bucket' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'PathStyleDomainForbidden', $result['code'] );
		$this->assertStringContainsString( '服务地址', $result['message'] );
	}
}
