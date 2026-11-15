<?php
namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\S3_Storage_Module;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Handles the admin-post S3 connection test. */
final class Connection_Tester {

	private Options $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'sakura-imgopt' ) );
		}
		check_admin_referer( 'sakura_s3_test' );
		if ( ! $this->options->is_configured() ) {
			$this->redirect(
				array(
					'tested'  => '0',
					'message' => __( '请先保存存储桶、Key 和 Secret。', 'sakura-imgopt' ),
				)
			);
		}
		$options = $this->options->all();

		try {
			$client = Client_Factory::make( $options );
		} catch ( \Throwable $e ) {
			Log::write( __( '连接测试失败：', 'sakura-imgopt' ) . $e->getMessage() );
			$this->redirect(
				array(
					'tested'  => '0',
					'message' => $e->getMessage(),
				)
			);
		}

		/*
		 * The probe names the real cause (dead Access Key, missing bucket,
		 * blocked endpoint) instead of the generic "check everything" line the
		 * old test printed, which sent operators hunting through settings that
		 * were fine. Report the message whatever the outcome: a bucket that
		 * refuses HEAD but allows listing is still usable for uploads.
		 */
		$probe = Connection_Probe::diagnose(
			$client,
			(string) $options['bucket'],
			array( (string) ( $options['key'] ?? '' ), (string) ( $options['secret'] ?? '' ) )
		);

		if ( ! $probe['ok'] ) {
			Log::write( __( '连接测试失败：', 'sakura-imgopt' ) . $probe['message'] );
			$this->redirect(
				array(
					'tested'  => '0',
					'message' => $probe['message'],
				)
			);
		}

		if ( '' !== $probe['message'] ) {
			Log::write( __( '连接测试提示：', 'sakura-imgopt' ) . $probe['message'] );
			$this->redirect(
				array(
					'tested'  => '1',
					'message' => $probe['message'],
				)
			);
		}

		$this->redirect( array( 'tested' => '1' ) );
	}

	/** Redirect to the S3 settings page; never returns. */
	private function redirect( array $args = array() ): never {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => S3_Storage_Module::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
