<?php
namespace Sakura\ImageOptimizer\S3;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Renders the S3 settings screen and read-only redirect notices. */
final class S3_Storage_Page {

	private Options $options;

	private ?Sync_Status $status;

	public function __construct( Options $options, ?Sync_Status $status = null ) {
		$this->options = $options;
		$this->status  = $status;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options          = $this->options->all();
		$upload_path      = $this->options->upload_path();
		$upload_path_src  = $this->options->upload_path_source( $options );
		$concurrency      = ( new \Sakura\ImageOptimizer\Settings\Settings_Repository() )->upload_concurrency();
		$parallel         = Upload_Capabilities::supports_parallel();
		$effective        = Upload_Capabilities::effective( $concurrency );
		$constants_locked = defined( 'S3_UPLOADS_USE_CONSTANTS' ) && true === S3_UPLOADS_USE_CONSTANTS;
		$notice           = $this->notice();
		$credentials_bad  = $this->options->credentials_unreadable();
		$saved_profiles   = $this->options->saved_profiles();
		$provider_counts  = $this->options->provider_saved_counts();
		$active_id        = $this->options->active_profile_id();
		$active_profile   = '' !== $active_id ? $this->options->profile( $active_id ) : array();
		$active_name      = (string) ( $active_profile['name'] ?? '' );
		?>
		<div class="sakura-sync-app">
			<div class="sky-layer"></div>
			<div class="sakura-layer"></div>
			<div class="app">
				<?php
				\Sakura\ImageOptimizer\Admin\Shell::header(
					'storage',
					'connection',
					array(
						'status'      => $this->options->is_enabled()
							? esc_html__( '同步已启用', 'sakura-imgopt' )
							: esc_html__( '同步已停用', 'sakura-imgopt' ),
						'status_tone' => $this->options->is_enabled() ? 'ok' : 'off',
					)
				);
				?>
				<section class="hero">
					<div class="hero-copy">
						<p class="eyebrow">OBJECT STORAGE / <?php echo esc_html( SAKURA_TOOLS_VERSION ); ?></p>
						<h1><?php esc_html_e( 'S3 存储', 'sakura-imgopt' ); ?></h1>
						<p class="lede"><?php esc_html_e( '把优化后的图片安全地同步到云存储。支持常见的 S3 兼容服务，并沿用原来的文件路径和图片地址前缀。', 'sakura-imgopt' ); ?></p>
						<div class="hero-actions">
							<a class="btn" href="<?php echo esc_url( \Sakura\ImageOptimizer\Admin\Registry::url( 'storage', 'sync' ) ); ?>"><?php esc_html_e( '打开同步中心', 'sakura-imgopt' ); ?></a>
						</div>
					</div>
					<aside class="hero-status">
						<div class="hero-status-top"><span class="hero-status-label<?php echo $this->options->is_configured() ? '' : ' is-warn'; ?>"><span class="dot"></span> <?php echo $this->options->is_configured() ? esc_html__( '配置可用', 'sakura-imgopt' ) : esc_html__( '等待配置', 'sakura-imgopt' ); ?></span></div>
						<div class="hero-status-main"><strong><?php echo esc_html( '' !== (string) $options['bucket'] ? (string) $options['bucket'] : __( '未配置', 'sakura-imgopt' ) ); ?></strong><span>· <?php echo esc_html( \Sakura\ImageOptimizer\S3\Providers::all()[ $options['provider'] ] ?? 'S3' ); ?></span></div>
						<div class="hero-status-sub"><?php echo $this->options->is_enabled() ? esc_html__( '已启用 · 上传后自动同步', 'sakura-imgopt' ) : esc_html__( '已停用', 'sakura-imgopt' ); ?></div>
					</aside>
				</section>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped values. ?>

			<?php if ( $credentials_bad ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( '已保存的访问密钥无法解密（可能更换了固定密钥或站点安全密钥）。请重新填写 Access Key 和 Secret；旧密钥不会显示出来。', 'sakura-imgopt' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $constants_locked ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( '站点配置文件里写死了云存储参数，非空的常量会覆盖这里填写的连接信息。', 'sakura-imgopt' ); ?></p></div>
			<?php endif; ?>

			<section class="sakura-s3-profiles" aria-labelledby="sakura-s3-profiles-title">
				<div class="sakura-s3-profiles-head">
					<div>
						<h2 id="sakura-s3-profiles-title"><?php esc_html_e( '已保存的配置', 'sakura-imgopt' ); ?></h2>
						<p><?php esc_html_e( '点击已保存的配置就能切换，不用重新填写密钥。同一服务商可保存多个账户：填好新的 Access Key 和 Secret，勾选「另存为新配置」后保存。', 'sakura-imgopt' ); ?></p>
					</div>
					<button type="button" class="button sakura-s3-add-profile" id="sakura-s3-add-profile"><?php esc_html_e( '新增账户配置', 'sakura-imgopt' ); ?></button>
				</div>
				<?php if ( array() === $saved_profiles ) : ?>
					<p class="sakura-s3-profiles-empty"><?php esc_html_e( '还没有保存的配置。填写并保存后，它会出现在这里。', 'sakura-imgopt' ); ?></p>
				<?php else : ?>
					<div class="sakura-s3-profile-grid">
						<?php foreach ( $saved_profiles as $profile_id => $profile ) : ?>
							<?php if ( empty( $profile['saved'] ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<div class="sakura-s3-profile-item">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sakura-s3-profile-form">
								<input type="hidden" name="action" value="sakura_s3_switch">
								<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
								<?php wp_nonce_field( 'sakura_s3_switch' ); ?>
								<button type="submit" class="sakura-s3-profile<?php echo ! empty( $profile['active'] ) ? ' is-active' : ''; ?>"<?php disabled( empty( $profile['complete'] ) ); ?>>
									<span class="sakura-s3-profile-name">
										<?php echo esc_html( (string) $profile['name'] ); ?>
										<span class="sakura-s3-profile-badge"><?php echo ! empty( $profile['active'] ) ? esc_html__( '使用中', 'sakura-imgopt' ) : esc_html__( '已保存', 'sakura-imgopt' ); ?></span>
									</span>
									<span class="sakura-s3-profile-meta"><?php echo esc_html( (string) $profile['label'] ); ?></span>
									<span class="sakura-s3-profile-meta"><?php echo esc_html( '' !== (string) $profile['bucket'] ? (string) $profile['bucket'] : __( '未填写存储桶', 'sakura-imgopt' ) ); ?></span>
									<span class="sakura-s3-profile-meta"><?php echo esc_html( '' !== (string) $profile['endpoint'] ? (string) $profile['endpoint'] : __( '默认服务地址', 'sakura-imgopt' ) ); ?></span>
									<?php if ( empty( $profile['complete'] ) ) : ?>
										<span class="sakura-s3-profile-warning"><?php esc_html_e( '配置不完整，补全后可切换', 'sakura-imgopt' ); ?></span>
									<?php endif; ?>
								</button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sakura-s3-profile-delete<?php echo ! empty( $profile['active'] ) ? ' is-disabled' : ''; ?>" onsubmit="return confirm('<?php echo esc_js( __( '确定删除这个已保存的配置吗？删除后不可恢复，但不会影响当前活动配置。', 'sakura-imgopt' ) ); ?>');">
								<input type="hidden" name="action" value="sakura_s3_delete">
								<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
								<?php wp_nonce_field( 'sakura_s3_delete_' . $profile_id ); ?>
								<button type="submit" class="button-link-delete"<?php disabled( ! empty( $profile['active'] ) ); ?> title="<?php echo ! empty( $profile['active'] ) ? esc_attr__( '使用中的配置不能删除，请先切换到其他配置', 'sakura-imgopt' ) : esc_attr__( '删除此配置', 'sakura-imgopt' ); ?>"><?php echo ! empty( $profile['active'] ) ? esc_html__( '使用中不可删除', 'sakura-imgopt' ) : esc_html__( '删除此配置', 'sakura-imgopt' ); ?></button>
							</form>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<form id="sakura-s3-save-form" class="sakura-s3-save-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-sakura-layout="s3" data-sakura-group="main">
				<input type="hidden" name="action" value="sakura_s3_save">
				<input type="hidden" name="profile_id" value="<?php echo esc_attr( $active_id ); ?>">
				<?php wp_nonce_field( 'sakura_s3_save' ); ?>
				<section class="card" data-sakura-card="behaviour">
					<div class="card-head">
						<div><p class="section-kicker">BEHAVIOUR</p><h2><?php esc_html_e( '上传行为', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '同样对所有存储配置生效。', 'sakura-imgopt' ); ?></p></div>
					</div>
					<div class="card-body">
						<label class="sakura-s3-check"><input type="checkbox" name="no_thumb" value="1" form="sakura-s3-save-form" <?php checked( $options['no_thumb'], 'true' ); ?>><span><strong><?php esc_html_e( '不上传 WordPress 生成的缩略图', 'sakura-imgopt' ); ?></strong><span class="description"><?php esc_html_e( '一般不建议开启，否则部分自适应图片可能无法从云端读取。', 'sakura-imgopt' ); ?></span></span></label>
						<label class="sakura-s3-check"><input type="checkbox" name="no_local_saving" value="1" form="sakura-s3-save-form" <?php checked( $options['no_local_saving'], 'true' ); ?>><span><strong><?php esc_html_e( '上传成功后删除本地文件（高风险）', 'sakura-imgopt' ); ?></strong><span class="description sakura-s3-danger"><?php esc_html_e( '勾选后，只有新上传的图片及其缩略图都成功传到云端，才会删除本地文件；不勾选就一直保留。手动同步历史附件时始终保留本地文件。', 'sakura-imgopt' ); ?></span></span></label>
						<div class="sakura-s3-advanced">
							<p class="sakura-s3-advanced-title"><?php esc_html_e( '高级设置', 'sakura-imgopt' ); ?></p>
							<div class="sakura-s3-field">
								<label class="sakura-s3-field-label" for="sakura-s3-upload-concurrency"><?php esc_html_e( '云端传输并发数', 'sakura-imgopt' ); ?></label>
								<select id="sakura-s3-upload-concurrency" name="upload_concurrency" form="sakura-s3-save-form">
									<?php for ( $i = 1; $i <= \Sakura\ImageOptimizer\Settings\Settings_Repository::MAX_UPLOAD_CONCURRENCY; $i++ ) : ?>
										<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $concurrency, $i ); ?>><?php echo esc_html( (string) $i ); ?></option>
									<?php endfor; ?>
								</select>
								<p class="description"><?php esc_html_e( '同一次上传里同时向云端传输的文件数，默认 1。调高会缩短多尺寸图片的等待时间，但更占 PHP 工作进程和带宽；服务器或 HTTP 传输环境不支持并行时会自动降级。', 'sakura-imgopt' ); ?></p>
								<?php if ( $concurrency > 1 && ! $parallel ) : ?>
									<p class="description sakura-s3-danger"><?php echo esc_html( sprintf( /* translators: %d: effective concurrency. */ __( '当前 HTTP 传输环境不支持并行，实际使用 %d。', 'sakura-imgopt' ), $effective ) ); ?></p>
								<?php endif; ?>
							</div>
						</div>
						<div class="card-savebar">
							<button type="submit" class="btn btn--primary"><?php esc_html_e( '保存设置', 'sakura-imgopt' ); ?></button>
						</div>
					</div>
				</section>

				<section class="card" data-sakura-card="connection">
					<div class="card-head">
						<div><p class="section-kicker">CONNECTION</p><h2><?php esc_html_e( '连接设置', 'sakura-imgopt' ); ?></h2><p><?php esc_html_e( '配置保存在网站数据库里，密钥会加密保存，页面上不会显示。', 'sakura-imgopt' ); ?></p></div>
					</div>
					<div class="card-body">
						<p class="description"><?php esc_html_e( '下面这些设置会跟着每份配置单独保存；切换配置时，访问权限、云端路径和图片地址前缀也会一起切换。', 'sakura-imgopt' ); ?></p>
						<table class="form-table" role="presentation">
<tr><th scope="row"><?php esc_html_e( '启用 S3 存储', 'sakura-imgopt' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $options['enabled'], 'true' ); ?>> <?php esc_html_e( '新上传的图片自动同步到云存储', 'sakura-imgopt' ); ?></label></td></tr>
					<tr><th scope="row"><label for="sakura-s3-provider"><?php esc_html_e( '服务商', 'sakura-imgopt' ); ?></label></th><td><select id="sakura-s3-provider" name="provider">
					<?php
					foreach ( Providers::all() as $key => $label ) :
						$count = (int) ( $provider_counts[ $key ] ?? 0 );
						$saved_label = $count > 0
							/* translators: %d: number of saved profiles for this provider. */
							? sprintf( __( '（已保存 %d 个）', 'sakura-imgopt' ), $count )
							: __( '（未保存）', 'sakura-imgopt' );
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $options['provider'], $key ); ?>><?php echo esc_html( $label . $saved_label ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th scope="row"><label for="sakura-s3-profile-name"><?php esc_html_e( '配置名称', 'sakura-imgopt' ); ?></label></th><td><input class="regular-text" id="sakura-s3-profile-name" name="profile_name" type="text" value="<?php echo esc_attr( $active_name ); ?>" placeholder="<?php esc_attr_e( '例如：主站图床、备份桶', 'sakura-imgopt' ); ?>"><p class="description"><?php esc_html_e( '用于区分同一服务商下的多个存储桶；留空会自动按服务商和存储桶命名。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( '保存方式', 'sakura-imgopt' ); ?></th><td><label><input type="checkbox" name="save_as_new" value="1"> <?php esc_html_e( '另存为新配置（保留当前配置）', 'sakura-imgopt' ); ?></label><p class="description"><?php esc_html_e( '切换配置不会顺手开启或关闭同步，也不会改动缩略图和「删除本地文件」这类全局设置；文章里已有的图片地址不会被批量替换。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><label for="sakura-s3-bucket"><?php esc_html_e( '存储桶名称', 'sakura-imgopt' ); ?></label></th><td><input class="regular-text" id="sakura-s3-bucket" name="bucket" type="text" value="<?php echo esc_attr( $options['bucket'] ); ?>" autocomplete="off"></td></tr>
					<tr><th scope="row"><label for="sakura-s3-region"><?php esc_html_e( '地域', 'sakura-imgopt' ); ?></label></th><td><input class="regular-text" id="sakura-s3-region" name="region" type="text" value="<?php echo esc_attr( $options['region'] ); ?>" placeholder="us-east-1"></td></tr>
					<tr><th scope="row"><label for="sakura-s3-endpoint"><?php esc_html_e( '服务地址', 'sakura-imgopt' ); ?></label></th><td><input class="regular-text" id="sakura-s3-endpoint" name="endpoint" type="url" value="<?php echo esc_attr( $options['endpoint'] ); ?>" placeholder="https://example.r2.cloudflarestorage.com"><p class="description"><?php esc_html_e( '只支持 http:// 或 https://。使用 AWS 官方服务时可以留空。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><label for="sakura-s3-key">Access Key</label></th><td><input class="regular-text" id="sakura-s3-key" name="key" type="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $options['key'] ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入访问密钥', 'sakura-imgopt' ) ); ?>"><p class="description"><?php esc_html_e( '密钥会加密保存在数据库里，页面上不会显示。建议在站点配置文件里设置一个固定的加密密钥；更换后需要重新填写。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><label for="sakura-s3-secret">Secret Key</label></th><td><input class="regular-text" id="sakura-s3-secret" name="secret" type="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $options['secret'] ? __( '已保存，留空保持不变', 'sakura-imgopt' ) : __( '请输入密钥', 'sakura-imgopt' ) ); ?>"><p class="description"><?php esc_html_e( '密钥会加密保存在数据库里，页面上不会显示。更换加密密钥或站点安全密钥后，旧密钥会失效，需要重新填写。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><label for="sakura-s3-acl"><?php esc_html_e( '文件访问权限', 'sakura-imgopt' ); ?></label></th><td><select id="sakura-s3-acl" name="object_acl"><option value="public-read" <?php selected( $options['object_acl'], 'public-read' ); ?>><?php esc_html_e( '公开读取', 'sakura-imgopt' ); ?></option><option value="private" <?php selected( $options['object_acl'], 'private' ); ?>><?php esc_html_e( '私有', 'sakura-imgopt' ); ?></option></select><p class="description"><?php esc_html_e( '只在上传时作为权限参数提交，能否生效由云存储服务商决定：Cloudflare R2、MinIO 会忽略；缤纷云等由存储桶策略或 CDN 决定。只影响之后上传的文件，不会改变已有文件。', 'sakura-imgopt' ); ?></p></td></tr>
					<tr><th scope="row"><label for="sakura-s3-path"><?php esc_html_e( '云端路径前缀', 'sakura-imgopt' ); ?></label></th><td>
						<?php if ( 'wordpress_option' === $upload_path_src ) : ?>
							<input class="regular-text code" id="sakura-s3-path" type="text" value="<?php echo esc_attr( $upload_path ); ?>" readonly>
							<input type="hidden" name="upload_path" value="<?php echo esc_attr( $upload_path ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: uploads path. */ __( '站点自己修改过上传目录（WordPress 的 upload_path 设置）：%s。云端路径会沿用这个值；本插件不会再改它，如需更换请先调整站点设置。', 'sakura-imgopt' ), $upload_path ) ); ?></p>
						<?php elseif ( 'plugin' === $upload_path_src ) : ?>
							<input class="regular-text code" id="sakura-s3-path" name="upload_path" type="text" value="<?php echo esc_attr( $upload_path ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: uploads path. */ __( '当前使用已保存的前缀：%s。修改后，已上传的文件和新路径会对不上，只有确认文件已经迁移时才改。', 'sakura-imgopt' ), $upload_path ) ); ?></p>
						<?php else : ?>
							<input class="regular-text code" id="sakura-s3-path" name="upload_path" type="text" value="<?php echo esc_attr( $upload_path ); ?>">
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: uploads path. */ __( '默认跟随 WordPress 的上传目录（当前：%s）；想放在根目录就填「.」。修改后，已上传的文件和新路径会对不上。', 'sakura-imgopt' ), $upload_path ) ); ?></p>
						<?php endif; ?>
					</td></tr>
					<tr><th scope="row"><label for="sakura-s3-url"><?php esc_html_e( '图片地址前缀', 'sakura-imgopt' ); ?></label></th><td><input class="regular-text code" id="sakura-s3-url" name="upload_url_path" type="url" value="<?php echo esc_attr( $options['upload_url_path'] ); ?>" placeholder="https://cdn.example.com/wp-content/uploads"><p class="description"><?php esc_html_e( '保存后会写入 WordPress 的图片地址设置。不会自动替换文章里已经写好的旧地址。', 'sakura-imgopt' ); ?></p></td></tr>
						</table>
						<div class="card-savebar">
							<button type="submit" class="btn btn--primary"><?php esc_html_e( '保存连接配置', 'sakura-imgopt' ); ?></button>
						</div>
					</div>
				</section>

				<section class="card" data-sakura-card="test">
					<div class="card-head">
						<div><p class="section-kicker">PROBE</p><h2><?php esc_html_e( '连接测试', 'sakura-imgopt' ); ?></h2></div>
					</div>
					<div class="card-body">
						<p class="description"><?php esc_html_e( '只检查能不能访问存储桶，不会上传测试文件。', 'sakura-imgopt' ); ?></p>
						<div class="card-savebar">
							<button type="submit" class="button button-primary" form="sakura-s3-test-form"><?php esc_html_e( '测试连接', 'sakura-imgopt' ); ?></button>
						</div>
					</div>
				</section>
			</form>

			<form id="sakura-s3-test-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
				<input type="hidden" name="action" value="sakura_s3_test">
				<?php wp_nonce_field( 'sakura_s3_test' ); ?>
			</form>
			<script>
			(function() {
				var add = document.getElementById('sakura-s3-add-profile');
				if (!add) return;
				add.addEventListener('click', function() {
					var form = document.querySelector('input[name="action"][value="sakura_s3_save"]');
					if (!form) return;
					form = form.closest('form');
					var newBox = form.querySelector('input[name="save_as_new"]');
					var name = form.querySelector('input[name="profile_name"]');
					if (newBox) newBox.checked = true;
					if (name) { name.value = ''; name.focus(); }
					form.scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
			})();
			</script>
			<?php Sync_Center_Panel::render( $this->status ?? new Sync_Status() ); ?>
			<?php Monitoring_Panel::render(); ?>
			</div>
			<?php echo Config_Notice::modal_html( $this->options, 'connection' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated with escaped values. ?>
		</div>
		<?php
	}

	private function notice(): string {
		if ( isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '已删除保存的配置。', 'sakura-imgopt' ) . '</p></div>';
		}
		if ( isset( $_GET['switched'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '已切换配置；是否启用和上传策略保持不变。', 'sakura-imgopt' ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			if ( '1' === sanitize_key( wp_unslash( $_GET['saved'] ) ) ) {
				return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '云存储设置已保存。', 'sakura-imgopt' ) . '</p></div>';
			}
			$errors = array(
				'endpoint' => __( '「服务地址」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'url'      => __( '「图片地址前缀」必须是有效的 http:// 或 https:// 地址。', 'sakura-imgopt' ),
				'path'     => __( '「云端路径前缀」不能包含「..」这类上级目录写法。', 'sakura-imgopt' ),
				'config'   => __( '启用前必须填写存储桶名称、Access Key、Secret 和服务地址（使用 AWS 官方服务时地址可留空）。', 'sakura-imgopt' ),
				'crypto'   => __( '服务器缺少加密组件，无法安全保存密钥，请联系主机商开启。', 'sakura-imgopt' ),
				'switch'       => __( '无法切换：配置不存在或还不完整，请补全后保存。', 'sakura-imgopt' ),
				'switch_path'  => __( '无法切换：该配置的云端路径和站点当前上传目录不一致，切换后已上传的文件会找不到。请先统一路径，或调整站点的上传目录设置。', 'sakura-imgopt' ),
				'delete'       => __( '无法删除该配置，请刷新页面后重试。', 'sakura-imgopt' ),
				'delete_active' => __( '当前使用中的配置不能删除，请先切换到其他配置。', 'sakura-imgopt' ),
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect error code; it does not change state.
			$key = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
			return '<div class="notice notice-error"><p>' . esc_html( $errors[ $key ] ?? __( '设置未保存。', 'sakura-imgopt' ) ) . '</p></div>';
		}
		if ( isset( $_GET['tested'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; it does not change state.
			if ( '1' === sanitize_key( wp_unslash( $_GET['tested'] ) ) ) {
				return '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '连接成功，存储桶可以访问。', 'sakura-imgopt' ) . '</p></div>';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect message; it does not change state.
			$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : __( '连接失败。', 'sakura-imgopt' );
			return '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
		return '';
	}
}

// 兼容旧类名（1.7.0 更名）。
\class_alias( __NAMESPACE__ . '\\S3_Storage_Page', __NAMESPACE__ . '\\Settings_Page' );
