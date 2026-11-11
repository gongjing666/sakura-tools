<?php
/**
 * View partial. Included from a settings/media method; uses the including scope.
 *
 * @package Sakura_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var string $engine */
/** @var string $option_group */
/** @var string $menu_slug */

/** @var \Sakura\ImageOptimizer\Image_Optimizer_Module $this */

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

global $wp_settings_sections, $wp_settings_fields;

$sections = isset( $wp_settings_sections[ $menu_slug ] ) ? $wp_settings_sections[ $menu_slug ] : array();
$fields   = isset( $wp_settings_fields[ $menu_slug ] ) ? $wp_settings_fields[ $menu_slug ] : array();
$sufm_order = array( 'sakura_imgopt_section_thumbs', 'sakura_imgopt_section_rename', 'sakura_imgopt_section_compress', 'sakura_imgopt_section_backup_policy', 'sakura_imgopt_section_bulk' );
$stats_url = admin_url( 'admin.php?page=sakura-tools-stats' );

settings_errors();
?>
<div class="sakura-sync-app">
	<div class="sky-layer" aria-hidden="true"></div>
	<div class="sakura-layer" aria-hidden="true"></div>
	<div class="app">
		<?php
		\Sakura\ImageOptimizer\Admin\Shell::header(
			'image',
			'rules',
			/* translators: %s: image engine name */
			array( 'status' => sprintf( __( '图片组件 %s', 'sakura-imgopt' ), $engine ) )
		);
		?>

			<section class="hero">
				<div class="hero-copy">
					<p class="eyebrow">SAKURA TOOLS / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
					<h1><?php esc_html_e( '图像优化', 'sakura-imgopt' ); ?></h1>
					<p class="lede"><?php esc_html_e( '轻量、可控的 WordPress 图片优化中心。统一管理缩略图裁剪、上传重命名、格式转换、原图备份与批量处理。', 'sakura-imgopt' ); ?></p>
				</div>
			</section>
			<div class="compact-cards compact-cards--settings" data-sakura-layout="image" data-sakura-group="main">
				<?php
				$kickers = array(
					'sakura_imgopt_section_thumbs'   => __( '01 尺寸', 'sakura-imgopt' ),
					'sakura_imgopt_section_rename'   => __( '02 命名', 'sakura-imgopt' ),
					'sakura_imgopt_section_compress' => __( '03 压缩', 'sakura-imgopt' ),
					'sakura_imgopt_section_bulk'     => __( '04 批量', 'sakura-imgopt' ),
				);
				$render_section = static function ( $section_id, $section ) use ( $fields, $kickers, $option_group ) {
					$section_fields = isset( $fields[ $section_id ] ) ? $fields[ $section_id ] : array();
					$wide           = ( 'sakura_imgopt_section_thumbs' === $section_id );
					$slug           = str_replace( 'sakura_imgopt_section_', '', $section_id );
					echo '<section class="card' . ( $wide ? ' card--wide' : '' ) . ' card--' . esc_attr( $slug ) . '" data-sakura-card="' . esc_attr( $slug ) . '">';
					$kicker = isset( $kickers[ $section_id ] ) ? $kickers[ $section_id ] : strtoupper( str_replace( 'sakura_imgopt_section_', '', $section_id ) );
					echo '<div class="card-head"><div><p class="section-kicker">' . esc_html( $kicker ) . '</p><h2>' . wp_kses_post( $section['title'] ) . '</h2></div></div>';
					echo '<div class="card-body">';
					echo '<form id="sakura-imgopt-card-' . esc_attr( $slug ) . '" method="post" action="options.php">';
					settings_fields( $option_group );
					\Sakura\ImageOptimizer\Admin\Card_Form::fields( (string) $slug );
					if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
						echo '<div class="section-note">';
						call_user_func( $section['callback'] );
						echo '</div>';
					}
					if ( ! empty( $section_fields ) ) {
						echo '<table class="form-table" role="presentation">';
						foreach ( $section_fields as $field ) {
							$field_args = is_array( $field['args'] ) ? $field['args'] : array();
							// The thumbs checklist owns its own label so the label and the
							// scan button can sit on one horizontal line above the table.
							if ( 'sakura_imgopt_section_thumbs' === $section_id && 'sakura_imgopt_disabled_sizes' === ( $field['id'] ?? '' ) ) {
								$field_args['show_label'] = true;
								$field_args['label']      = wp_strip_all_tags( $field['title'] );
								echo '<tr class="sakura-imgopt-thumbs-row"><td colspan="2" class="sakura-imgopt-thumbs-cell">';
								call_user_func( $field['callback'], $field_args );
								echo '</td></tr>';
								continue;
							}
							echo '<tr><th scope="row">' . wp_kses_post( $field['title'] ) . '</th><td>';
							call_user_func( $field['callback'], $field_args );
							echo '</td></tr>';
						}
						echo '</table>';
					}
					echo '<div class="card-savebar"><button type="submit" class="btn btn--primary">' . esc_html__( '保存设置', 'sakura-imgopt' ) . '</button></div>';
					echo '</form>';
					echo '</div></section>';
				};

				foreach ( $sufm_order as $section_id ) {
					if ( isset( $sections[ $section_id ] ) ) {
						$render_section( $section_id, $sections[ $section_id ] );
					}
				}
				foreach ( $sections as $section_id => $section ) {
					if ( in_array( $section_id, $sufm_order, true ) ) {
						continue;
					}
					$section_fields = isset( $fields[ $section_id ] ) ? $fields[ $section_id ] : array();
					if ( empty( $section_fields ) ) {
						continue;
					}
					$render_section( $section_id, $section );
				}
				?>
			</div>
	</div>
</div>
