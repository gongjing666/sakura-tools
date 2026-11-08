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
		try {
			$options = $this->options->all();
			Client_Factory::make( $options )->headBucket( array( 'Bucket' => (string) $options['bucket'] ) );
			$this->redirect( array( 'tested' => '1' ) );
		} catch ( \Throwable $e ) {
			Log::write( __( '连接测试失败：', 'sakura-imgopt' ) . $e->getMessage() );
			$this->redirect(
				array(
					'tested'  => '0',
					'message' => __( '连接失败，请检查服务商、地域、Endpoint、存储桶和凭据。详细错误仅在 WP_DEBUG 日志中记录。', 'sakura-imgopt' ),
				)
			);
		}
	}

	/** Redirect to the S3 settings page; never returns. */
	private function redirect( array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => S3_Storage_Module::MENU_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
