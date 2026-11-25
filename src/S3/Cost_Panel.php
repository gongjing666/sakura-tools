<?php
/**
 * Cloud storage cost estimator screen.
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\S3;

use Sakura\ImageOptimizer\Admin\Assets;
use Sakura\ImageOptimizer\Admin\Page_Assets;
use Sakura\ImageOptimizer\Admin\Registry;
use Sakura\ImageOptimizer\Contracts\Module;
use Sakura\ImageOptimizer\Support\Hook_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the storage/cost tab: one form, one estimate, no persistence.
 *
 * The calculator is stateless on purpose. Everything it needs comes from the
 * submitted form, so a repeated request costs one CPU-only run and writes
 * nothing to the database.
 */
final class Cost_Panel implements Module {

	/**
	 * Nonce action shared by the screen and its endpoint.
	 */
	public const NONCE = 'sakura_storage_cost';

	/**
	 * React island bundle, relative to the plugin root.
	 */
	public const BUNDLE = 'assets/ui/dist/cost.js';

	/**
	 * Config cache shared by enqueue and render.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $data_cache = null;

	/**
	 * Register hooks.
	 *
	 * @param Hook_Loader $hooks Hook loader.
	 */
	public function register_hooks( Hook_Loader $hooks ): void {
		$hooks->add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		$hooks->add_action( 'wp_ajax_sakura_storage_cost_estimate', array( $this, 'ajax_estimate' ) );
	}

	/**
	 * Only load the page bundle on this tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		unset( $hook );

		if ( ! Registry::on( 'storage', 'cost' ) ) {
			return;
		}

		Page_Assets::set_data( 'storage-cost', $this->page_config() );
		Assets::enqueue_page();

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			wp_enqueue_script(
				'sakura-admin-cost',
				SAKURA_TOOLS_URL . self::BUNDLE,
				array( 'sakura-admin-core' ),
				Page_Assets::asset_version( self::BUNDLE ),
				true
			);
		}
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$defaults = Cost_Estimator::defaults();
		$result   = Cost_Estimator::estimate( $defaults );
		$priced   = Cost_Estimator::PRICED_AT;

		if ( is_readable( SAKURA_TOOLS_PATH . self::BUNDLE ) ) {
			$data = ( new \Sakura\ImageOptimizer\Admin\Cost_Payload( $result ) )->build();

			require __DIR__ . '/../../includes/views/cost-island.php';

			return;
		}

		require __DIR__ . '/../../includes/views/storage-cost.php';
	}

	/**
	 * Recalculate with the demand submitted by the form.
	 */
	public function ajax_estimate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'sakura-imgopt' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );

		$input = array(
			'storage_gb' => $this->post_float( 'storage_gb' ),
			'traffic_gb' => $this->post_float( 'traffic_gb' ),
			'read_wan'   => $this->post_float( 'read_wan' ),
			'write_wan'  => $this->post_float( 'write_wan' ),
			'image_gb'   => $this->post_float( 'image_gb' ),
			'hit_rate'   => $this->post_float( 'hit_rate' ),
			'busy_rate'  => $this->post_float( 'busy_rate' ),
			'https_rate' => $this->post_float( 'https_rate' ),
			'mode'       => $this->post_string( 'mode' ),
			'fx'         => $this->post_float( 'fx' ),
		);

		wp_send_json_success( Cost_Estimator::estimate( $input ) );
	}

	/**
	 * Config handed to assets/ui/js/pages/storage-cost.js.
	 *
	 * @return array<string,mixed>
	 */
	public function page_config(): array {
		if ( null === $this->data_cache ) {
			$this->data_cache = array(
				'defaults' => Cost_Estimator::defaults(),
				'pricedAt' => Cost_Estimator::PRICED_AT,
			);
		}

		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'defaults' => $this->data_cache['defaults'],
			'pricedAt' => $this->data_cache['pricedAt'],
			'strings'  => array(
				'estimating' => __( '正在估算…', 'sakura-imgopt' ),
				'done'       => __( '已按当前需求重新估算。', 'sakura-imgopt' ),
				'unknown'    => __( '估算失败，请稍后重试。', 'sakura-imgopt' ),
				'cheapest'   => __( '最低价', 'sakura-imgopt' ),
				'usd'        => __( '美元计价', 'sakura-imgopt' ),
				'modeCdn'    => __( 'CDN 组合（存储桶 + 同厂商 CDN）', 'sakura-imgopt' ),
				'modeDirect' => __( '源站直出（不走 CDN）', 'sakura-imgopt' ),
				'empty'      => __( '没有估算结果。', 'sakura-imgopt' ),
			),
		);
	}

	/**
	 * Read a float from the request.
	 *
	 * @param string $key Field name.
	 * @return float
	 */
	private function post_float( string $key ): float {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_estimate().
		return isset( $_POST[ $key ] ) && is_numeric( wp_unslash( $_POST[ $key ] ) ) ? (float) wp_unslash( $_POST[ $key ] ) : 0.0;
	}

	/**
	 * Read a string from the request.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_estimate().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}
}
