# 樱花工具箱 开发维护说明

本文档用于帮助后续开发者快速接手 `sakura-tools` 插件的维护工作。

## 0. 运行环境与权限

- **Web 服务器运行用户**：`www`
- **插件文件所属用户/组**：`www:www`
- **文件权限**：`644`（PHP 等代码文件）
- **目录权限**：`755`
- **测试方式**：本轮开发使用 `root` 用户进行语法检查与功能验证，但生产环境中插件由 `www` 用户运行。
- **重要**：每次修改文件后，必须执行：

```bash
chown www:www <文件路径>
chmod 644 <文件路径>   # PHP 文件
chmod 755 <目录路径>   # 目录
```

否则可能出现 Web 端无法读取插件文件或权限过高导致安全风险。

当前已验证正确的操作示例：

```bash
chown www:www /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/includes/class-settings.php
chmod 644 /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/includes/class-settings.php
stat -c '%a %U:%G' <文件路径>   # 预期输出: 644 www:www
```

## 1. 插件概览

- **插件名**：樱花工具箱
- **作者**：星晴 & Claude
- **官网**：https://www.nboke.com
- **版本**：1.3.3
- **Text Domain**：sakura-imgopt

插件目录：

```text
/wp-content/plugins/sakura-tools/
```

当前文件结构：

```text
sakura-tools/
├── sakura-tools.php
├── uninstall.php
├── DEVELOPMENT.md
└── includes/
    ├── class-gd-handler.php
    ├── class-image-processor.php
    ├── class-imagick-handler.php
    ├── class-media-library.php
    ├── class-settings.php
    └── class-thumbnail-manager.php
```

插件主要用途：对 WordPress 媒体库图片进行优化处理，支持按配置选择源图片格式、输出格式、缩略图处理策略，并通过 GD / Imagick 等后端执行图片处理。

## 2. 主要文件职责

### `sakura-tools.php`

插件主入口文件。负责：

- 定义插件基础常量（`SAKURA_TOOLS_VERSION` / `SAKURA_TOOLS_PATH` / `SAKURA_TOOLS_URL` / `SAKURA_TOOLS_INC`）。
- 自动加载 `Sakura\ImageOptimizer\` 命名空间下的类文件。
- 注册激活 / 停用钩子。
- 激活时写入默认配置，并自动从旧 `irmia_imgopt_*` 迁移到新 `sakura_imgopt_*` 配置。
- 在 `plugins_loaded` 钩子中初始化各功能模块。

### `uninstall.php`

插件卸载清理脚本。仅在用户于 WordPress 后台点击「删除」时执行（停用不触发）。

清理内容：

1. 删除所有 `sakura_imgopt_*` 选项（含旧版 `irmia_imgopt_*` 遗留键）。
2. 删除所有 `sakura_queue_*` 批量优化队列 transient（含 `_transient_` 和 `_transient_timeout_` 两套记录）。
3. 多站点（Multisite）下逐站点清理。

**设计决策**：卸载脚本不会删除或还原任何已优化的图片文件——图片是用户的真实资产，卸载插件不应破坏媒体库内容。

### `includes/class-settings.php`

后台设置页核心文件。负责：

- 注册插件设置项（`sakura_imgopt_settings` option group）。
- 渲染后台设置页面。
- 输出图片格式、尺寸、压缩质量等配置字段。
- 注入设置页所需的后台 CSS。
- 包含四个设置分区：禁止裁剪、上传重命名、图片压缩与格式转换、批量优化性能。
- 批量优化性能分区下方有 OSS / 云存储不适用警告提示。

### `includes/class-media-library.php`

媒体库增强模块。负责：

- 媒体库列表页格式筛选下拉框。
- 批量操作入口：「图像优化」（将选中图片 ID 存入 transient 队列，跳转进度页）。
- 异步进度页：JS 循环发送 AJAX 请求，分批处理，实时显示进度条。
- AJAX 处理：`sakura_process_batch`（处理图片）和 `sakura_get_progress`（查询进度，备用）。
- 附件数据同步：优化后更新 `post_mime_type`、`metadata`、缩略图文件名等。
- 旧文件清理：格式转换后删除原文件。

### `includes/class-image-processor.php`

图片处理调度核心。负责：

- 根据设置判断是否需要处理图片。
- 调用 GD 或 Imagick 处理器。
- 控制输出格式、质量、文件路径等逻辑。
- 与缩略图处理流程协作。
- 上传时自动重命名（按规则模板，支持 `{filename}` `{ext}` `{date}` `{time}` `{rand}` `{rand8}`）。
- 源格式过滤：只处理设置中勾选的源格式。
- 批量优化时复用同一处理流程。

### `includes/class-gd-handler.php`

GD 图片处理后端。负责：

- 使用 PHP GD 扩展读取、转换、压缩图片。
- 处理 GD 支持的格式。
- 在 Imagick 不可用时作为兜底方案。

维护注意：GD 对部分新格式支持有限，新增格式前需要检查服务器 GD 编译能力。

### `includes/class-imagick-handler.php`

Imagick 图片处理后端。负责：

- 使用 Imagick 扩展读取、转换、压缩图片。
- 支持更丰富的格式和更好的图片处理能力。
- 写入时使用临时文件（`.sakuratmp_` 前缀）+ 原子替换，避免处理失败导致原文件损坏。

维护注意：不同服务器的 ImageMagick 编译格式支持可能不同，不能只根据 PHP 扩展是否存在判断格式一定可用。

### `includes/class-thumbnail-manager.php`

缩略图管理逻辑。负责：

- 读取 WordPress 注册的图片尺寸。
- 按设置项 `sakura_imgopt_disabled_sizes` 禁止指定尺寸生成。
- 支持禁止「2560px 缩放版」（`_big_threshold`）。
- 原图始终保留。

## 3. 后台「源图片格式」间距实现说明

源图片格式复选框间距拆分方式：

```html
<label class="sakura-imgopt-source-format-item" style="display:inline-block;margin:0 0 8px 0;white-space:nowrap;">
  <input type="checkbox" style="margin:0 5px 0 0;vertical-align:middle;" />
  <span style="vertical-align:middle;margin-right:10px;">PNG</span>
</label>
```

| 位置 | 控制方式 | 当前值 |
|---|---|---:|
| 方框 □ 到文字 | `input` 的 `margin-right` | `5px` |
| 文字到右侧下一个方框 | `span` 的 `margin-right` | `10px` |
| 整个 `label` 自身右侧间距 | `label` 的 `margin-right` | `0` |
| 每行底部间距 | `label` 的 `margin-bottom` | `8px` |

后台 CSS 中也应保持同样逻辑：

```css
.sakura-imgopt-source-format-item {
  display: inline-block;
  margin: 0 0 8px 0;
  white-space: nowrap;
}
.sakura-imgopt-source-format-item input[type="checkbox"] {
  margin: 0 5px 0 0;
  vertical-align: middle;
}
.sakura-imgopt-source-format-item span {
  vertical-align: middle;
  margin-right: 10px;
}
```

## 4. 品牌重命名记录

原始开发阶段使用临时代号 `IRMIA`（无实际含义），最终正式定名为 `Sakura`。

### 重命名范围

| 类别 | 旧值 | 新值 |
|---|---|---|
| 插件名 | IRMIA Image Optimizer | 樱花工具箱 |
| 作者 | IRMIA DevKit | 星晴 & Claude |
| 插件官网 | (GitHub 模板地址) | https://www.nboke.com |
| 媒体库操作名 | IRMIA 优化 | 图像优化 |
| 命名空间 | `IRMIA\ImageOptimizer` | `Sakura\ImageOptimizer` |
| 常量前缀 | `IRMIA_IMGOPT_*` | `SAKURA_TOOLS_*` |
| Option 前缀 | `irmia_imgopt_*` | `sakura_imgopt_*` |
| Transient 前缀 | `irmia_queue_*` | `sakura_queue_*` |
| AJAX action | `irmia_process_batch` | `sakura_process_batch` |
| Nonce | `irmia_process_batch` | `sakura_process_batch` |
| Text Domain | `irmia-imgopt` | `sakura-imgopt` |
| CSS class / HTML id | `irmia-imgopt-*` / `irmia-*` | `sakura-imgopt-*` / `sakura-*` |
| 临时文件后缀 | `.irmiatmp_` | `.sakuratmp_` |
| 插件目录 | `irmia-image-optimizer/` | `sakura-tools/` |
| 主文件 | `irmia-image-optimizer.php` | `sakura-tools.php` |

### 旧配置迁移

主文件 `sakura-tools.php` 激活时自动执行迁移：

1. 遍历所有默认配置键，检查旧 `irmia_imgopt_*` 是否存在，存在则复制到 `sakura_imgopt_*` 并删除旧键。
2. 检查旧 `irmia_imgopt_disable_all_thumbs`（更早期的废弃字段），迁移到 `sakura_imgopt_disabled_sizes`。

迁移完成后旧键自动清除，不会残留。

### 重命名前备份

```text
/root/.irmia/sakura-cleanup-before_20260626_102825.tar.gz
```

## 5. 卸载脚本说明

`uninstall.php` 仅在 WordPress 后台「删除插件」时触发（停用不触发）。

清理内容：

1. 删除所有 `sakura_imgopt_*` 选项，含：
   - 缩略图/压缩/转换/重命名/批量性能等设置项；
   - 备份相关设置 `sakura_imgopt_backup_enabled`、`sakura_imgopt_backup_retention_days`、`sakura_imgopt_backup_max_mb`；
   - 统计聚合 `sakura_imgopt_stats`；
   - 可能残留的旧 `irmia_imgopt_*` / `sakura_imgopt_disable_all_thumbs` 键。
2. 删除备份用量缓存 transient `sakura_imgopt_backup_usage`。
3. 删除所有 `sakura_queue_*` transient（含 `_transient_` 和 `_transient_timeout_` 两套记录）。
4. 删除所有插件 postmeta：
   - `sakura_imgopt_log`（每张附件的处理明细日志）；
   - `_sakura_imgopt_backup`（每张附件的备份元数据）。
5. 递归删除备份目录 `uploads/sakura-imgopt-backup/` 及其全部内容（含软链接防护，不跟随符号链接）。
6. 多站点（Multisite）下逐站点清理。

**设计决策**：

- 卸载脚本**不会删除或还原任何已优化的媒体库图片**——图片是用户的真实资产，卸载插件不应破坏媒体库内容。
- 但卸载脚本**会删除原图备份目录** `sakura-imgopt-backup/`，因为那是插件自身产生的中间数据。

> ⚠️ **用户卸载注意事项**：媒体库中已转换的图片在卸载后**不会自动还原**，且原图备份目录会被一并删除。如需找回原图，正确顺序是：**先在后台批量「还原」→ 确认无误后再删除插件**。如想保留备份目录，请在卸载前自行手动备份该目录。

## 6. 批量优化异步队列设计

### 流程

1. 媒体库列表页勾选图片 → 选择「图像优化」批量操作。
2. 附件 ID 列表存入 transient `sakura_queue_{user_id}`，跳转到进度页。
3. 进度页 JS 按设置中的 `batch_size`（每批张数）和 `ajax_delay`（批次间延迟）循环发送 AJAX 请求。
4. 每次请求处理一批图片，返回成功/失败计数。
5. 全部完成后显示汇总，提供「返回媒体库」链接。

### 性能参数

后台设置页「批量优化性能」分区可调：

- **每批处理张数**（`sakura_imgopt_batch_size`）：1–20，默认 1。
- **批次间延迟**（`sakura_imgopt_ajax_delay`）：0–5000ms，默认 500。

### OSS / 云存储限制

批量优化仅适用于存储在本地服务器的图片。若已使用阿里云 OSS、腾讯云 COS、七牛、又拍云等云存储插件接管媒体库，图片实际存放在云端、本地无副本，此功能将无法处理。设置页对应分区下方有警告提示。

## 7. 验证方式

每次修改 PHP 文件后，建议执行完整检查流程：

```bash
# 1. PHP 语法检查（所有文件）
for f in /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/sakura-tools.php \
         /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/uninstall.php \
         /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/includes/*.php; do
    php -l "$f"
done

# 2. 修正文件归属与权限
chown -R www:www /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/
find /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/ -name '*.php' -exec chmod 644 {} +
find /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/ -type d -exec chmod 755 {} +

# 3. 确认最终状态
stat -c '%a %U:%G' /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/sakura-tools.php
```

预期输出：

```
No syntax errors detected in .../sakura-tools.php
644 www:www
```

## 8. 打包归档

最终成品包：

```text
/root/.irmia/sakura-tools_20260626_103123.zip
SHA256: 86c5b7c403d0f13a6d994719e40ec035888597e36b0f9690cb6f8b0450dcf167
大小: 29K
```

打包命令：

```bash
cd /www/wwwroot/demo.77il.cn/wp-content/plugins/
zip -r /root/.irmia/sakura-tools_$(date +%Y%m%d_%H%M%S).zip sakura-tools/
```

## 9. 后续开发建议

### 设置页 UI

- 当前有部分样式同时存在于 inline style 和后台注入 CSS 中。
- 长期建议将后台样式统一抽离到独立 CSS 文件，通过 `admin_enqueue_scripts` 只在设置页加载。

### 图片格式支持

新增格式时需同时检查：

1. 设置页可选格式列表。
2. 保存配置时的 sanitize / validate 逻辑。
3. GD 支持情况。
4. Imagick 支持情况。
5. WordPress MIME 类型和媒体库兼容性。
6. 前端浏览器兼容性。

### 图片处理稳定性

修改处理逻辑时，建议至少测试：

- JPG / PNG / GIF / WebP / AVIF（如服务器支持）
- 带透明通道的 PNG / WebP
- 大尺寸图片
- WordPress 缩略图生成流程

### 安全性

- 表单字段输出使用 `esc_attr()` / `esc_html()`。
- 保存设置时使用合适的 sanitize 函数。
- 后台操作应检查权限（`manage_options`）。
- 文件路径操作必须避免目录穿越。
- AJAX 请求使用 `check_ajax_referer()` 校验 nonce。

### 备份目录与 token 缓存的 Web 服务器加固

`uploads/sakura-imgopt-backup/` 存放压缩前的原图，旧版多吉云 token 曾缓存在 `wp-content/.sakura-s3-cache/`，两者都不应被直链访问。插件会写 `.htaccess`（Apache 2.2 / 2.4），但 **Nginx 不读取 `.htaccess`**，必须在站点配置中显式 deny：

```nginx
# 放在静态资源正则 location 之前，^~ 可避免被 \.(jpg|png)$ 等规则捕获
location ^~ /wp-content/uploads/sakura-imgopt-backup/ {
    return 403;
}

# 旧版 token 缓存目录（升级后可能残留）
location ^~ /wp-content/.sakura-s3-cache/ {
    return 403;
}
```

修改后执行 `nginx -t && nginx -s reload`，并确认备份文件 URL 返回 403 而不是图片内容。新版本的多吉云 token 已改存数据库 transient，不再写入该目录。

**页面自检（1.9.1 起）**：「备份管理」页新增「备份目录访问保护」卡片，点「检测是否可被公网访问」会由服务器真实请求备份目录中的一个文件：

* 优先取已有备份文件，目录为空时写入 `.sakura-probe-*.txt`（随机内容）并在请求后删除；
* `200` 且内容匹配 / 图片字节 → `exposed`（红字警告）；
* `401 / 403 / 404 / 410` → `protected`（绿字）；
* `200 + text/html`（主题 404 页）、超时、5xx → `unknown`，提示手动访问确认。

判定逻辑在 `src/Backup/Exposure_Probe.php::classify()`（纯函数，有 PHPUnit 用例），结果缓存 1 小时；`SERVER_SOFTWARE` 只用于提示「当前服务器是否读取 .htaccess」，不参与判定。IIS / Caddy 不会读 `.htaccess`，同样需要站点级拒绝规则。

## 10. 当前状态摘要

截至 2026-10-23：

- **品牌**：樱花工具箱，作者「星晴 & Claude」，官网 `https://www.nboke.com`。
- **版本**：1.3.3
- **目录**：`/www/wwwroot/www.nboke.com/wp-content/plugins/sakura-tools/`
- **所有 PHP 文件语法检查通过**。
- **文件归属**：`www:www`，权限：`644`（文件）/ `755`（目录）。
- **内部标识已彻底统一为 `sakura` 前缀**，仅保留激活时旧 `irmia_*` 配置自动迁移逻辑。
- **最终打包**：`sakura-tools_20260626_103123.zip`（29K）。
- **WordPress 当前状态**：插件已停用，可直接在后台重新启用（目录路径已更新，WordPress 会识别为新插件）。

## 11. 上传 Fatal 与备份逻辑修复（2026-06-26）

本次定位并修复三个缺陷，均通过真实媒体库操作复现验证。

### 11.1 上传报「服务器无法处理图片」（Fatal）

- **现象**：网页端上传图片弹「服务器无法处理图片」，但 CLI 全链路测试（Imagick 引擎、AVIF 编码、内存峰值）全部正常。
- **排查**：开启 `WP_DEBUG_LOG` 抓真实 FPM 请求，`debug.log` 直指
  `Call to undefined method Image_Processor::relative_to_uploads()`
  位于 `class-image-processor.php:307`。
- **根因**：`handle_upload` 往 `pending_records` 写入 `'origin_rel' => $this->relative_to_uploads($file_path)`，但该方法在整个项目中**从未定义**；且下游 `consume_pending()` 用 `backup_rel` 自行反推 `origin_rel`，根本不消费这个键——属多余的死代码，且方法不存在导致每次上传必 Fatal。
- **修复**：删除 `pending_records` 中的 `origin_rel` 这一行。零副作用。
- **教训**：底层引擎/内存/格式能力测试无法覆盖插件 hook 内的新增代码路径；遇到「网页报错但 CLI 正常」应第一时间开 `WP_DEBUG_LOG` 取第一手 Fatal，而非反复猜测环境差异（CLI 与 FPM 用的是两份不同 ini，但本例并非环境问题）。

### 11.2 删除附件时备份原图未清理（孤儿文件）

- **现象**：媒体库删除图片后，`uploads/sakura-imgopt-backup/` 中对应原图残留。
- **根因**：全项目未挂任何 `delete_attachment` 钩子，`Backup::delete_backup()` 仅在还原流程被调用。
- **修复**：在 `Image_Processor::__construct()` 挂
  `add_action('delete_attachment', [$this, 'on_delete_attachment'], 10, 1)`，
  回调调用 `Backup::delete_backup()`。还原流程不走 `wp_delete_attachment`，不受影响。

### 11.3 媒体库「图像优化」按钮再压缩时不生成备份

- **现象**：图片还原后，再通过媒体库「图像优化」按钮压缩，备份目录无新原图。
- **根因**：`Backup::store()` 全项目仅在 `handle_upload`（上传链路）调用一次；媒体库按钮走的是 `Media_Library::ajax_process_batch → process_file`，整条链路绕过备份。
- **修复**：在 `ajax_process_batch` 优化分支于 `process_file` 之前备份原图，并加三重防护：
  1. `Backup::has_backup()` 守卫——已有有效备份则不重复备份，避免覆盖更早的真原图；
  2. 处理失败时清理本次刚产生的孤儿备份；
  3. 处理成功后才 `Backup::save_meta()` 写还原元数据。

### 11.4 收尾

- 排查期间临时开启的 `WP_DEBUG_LOG` / `WP_DEBUG_DISPLAY` 已移除，`wp-config.php` 恢复为仅 `WP_DEBUG=false`。
- 临时探针脚本、`wp-config.php.sakura-bak` 备份、`wp-content/debug.log` 均已清理。
- 改动文件：`includes/class-image-processor.php`、`includes/class-media-library.php`，`php -l` 均通过。


## 12. P2 质量与体验（2026-10-23）

版本 1.3.3。对应分析报告 P2 第 12–20 项。

### 12.1 兼容性与版本

- 插件头新增 `Requires at least: 6.4`、`Requires PHP: 8.1`、`License: GPL-2.0-or-later`、`License URI`。
- 版本常量、`assets/admin.css` 注释、本文档版本/路径/日期同步为 1.3.3。
- `Image_Processor::sync_attachment_title()` 增加默认参数，旧版 WordPress 只传 3 个参数时不再 fatal。

### 12.2 设置页体验

- `ajax_delay` 上限统一为 0–2000（说明、输入框 `max`、`sanitize_ajax_delay()`）。
- `render_page()` 与 `render_backup_page()` 增加 `settings_errors()`，保存后显示“设置已保存”。

### 12.3 资源加载

- `Settings` 用 `$page_hooks` 记录三个子页注册返回的 hook；`enqueue_admin_assets()` 只按 `$hook` 加载 `admin.css` 与内联脚本。
- 仪表盘等非插件页不再读取 `$_GET['page']`，也不加载插件样式。

### 12.4 OSS 通用作用域

- 新增 `row_classes`、`post_classes`、`thumb_classes`、`sidebar_classes` 配置，逗号分隔，默认值适配子比。
- `can_filter()` 不再强制子比主题；非子比主题可按自己的列表/条目/缩略图容器 class 配置。
- 侧边栏 Ajax 兜底仍仅对子比主题显示/启用。

### 12.5 Imagick 临时文件

- `Imagick_Handler::process()` 把 `$tmp_path` 提前初始化，并在 `finally` 中删除残留 `.sakuratmp_*`；写入失败、rename 失败都不会留下垃圾文件。

### 12.6 PHPStan 与 CI

- `phpstan.neon` 自包含：`scanFiles` 载入 `stubs/phpstan-plugin-dependencies.php`，`bootstrapFiles` 声明插件常量；不再引用不存在的 `../.wp-plugin-tools/config/phpstan.neon`。
- 新增 `composer.json`（`phpstan/phpstan ^2.1`、`szepeviktor/phpstan-wordpress ^2.0`）、`composer.lock`、`.github/workflows/phpstan.yml`。
- 本地 PHPStan 2.3.1 level 5 通过（0 errors）；开发 `vendor/` 不随插件发布，CI 执行 `composer install` 后运行。

### 12.7 国际化与文件权限

- 已生成 `languages/sakura-imgopt.pot`；`load_plugin_textdomain()` 保持启用。存量中文硬编码尚未全量迁移。
- 插件文件统一 644、目录 755，属主 `www:www`。

### 12.8 验证

- `php -l`：19 个非 vendor PHP 文件全部通过。
- 后台：设置页保存提示正常；统计页、备份页、OSS 页正常；插件页加载 `admin.css?ver=1.3.3`，仪表盘不加载。
- `sanitize_ajax_delay(5000)` 返回 2000；自定义 OSS class 测试输出 `?w=300`。
- Imagick 不可写目标路径测试返回失败，无 `.sakuratmp_*` 残留。

## 13. 渲染层、测试与发布规范（1.3.3）

### 13.1 页面视图

- 后台页面 HTML 已从类中拆到 `includes/views/`：`settings-main.php`、`settings-backup.php`、`settings-stats.php`、`oss-settings.php`、`media-progress.php`。
- 类方法只准备 view 所需的局部变量后 `require`；view 中调用插件类必须使用 `\Sakura\ImageOptimizer\...` 全限定名（view 不在类作用域内），私有属性通过局部变量传入。
- 新增/修改视图后至少回归：设置页、备份页、统计页、OSS 页、批量进度页。

### 13.2 测试与静态分析

- `tests/AnimationDetectorTest.php`、`tests/BackupPathTest.php`、`tests/HookLoaderTest.php` 为纯 PHP 单元测试，不依赖 WordPress 运行环境；`tests/bootstrap.php` 提供 `ABSPATH`、`apply_filters()`、`add_action()`、`add_filter()` shim。
- 运行：`composer test`（PHPUnit 10.5）；静态分析：`composer phpstan`（level 5）。当前 18 tests / 38 assertions 全部通过（`ModuleRegistrationTest` 验证模块接口与集中钩子注册）。
- `.github/workflows/tests.yml` 在 PHP 8.1/8.2/8.3 上跑单元测试；`phpstan.yml` 跑静态分析。CI 需先 `composer install`。
- `ModuleRegistrationTest` 覆盖接口实现、集中注册、构造不挂钩子、S3 启停分支。

### 13.3 发布文件

- `readme.txt` 与 `LICENSE` 已加入；发布包应包含插件 PHP、`includes/views/`、`languages/`、`vendor/s3/`，不包含根 `vendor/`、`tests/`、`.github/`、`.phpunit.cache`。
- 文件权限保持 644、目录 755，属主为 Web 运行用户（本机 `www:www`）。

## 14. 分层架构 Phase 1（1.3.3）

### 14.1 目标与边界

本轮只做“组合根 + 生命周期 + 条件加载”，不改图片处理业务逻辑、不改 option/postmeta 名称、不改 URL 规则；目的是把入口从 171 行降到只负责常量和装配，并为后续领域拆分留出边界。

### 14.2 新增目录与类

- `src/`：PSR-4 命名空间 `Sakura\ImageOptimizer\`，自动加载器先查 `src/`，找不到再回退 `includes/class-*.php`。
- `src/Plugin.php`：组合根。按原顺序实例化 `Settings` → `Thumbnail_Manager` → `Image_Processor` → `Media_Library` → `Oss_Thumbnail` → `S3_Settings` → `S3\Storage`；`is_admin()` 只决定 `Settings`、`Media_Library`、`S3_Settings` 是否加载。
- `src/Activation.php`：原 `register_activation_hook` 内的默认值、S3 初始化、旧配置迁移、URL 接管、cron 注册整体迁入。
- `src/Deactivation.php`：恢复 `upload_url_path`、清理 cron。
- `src/Support/Hook_Loader.php`：延迟注册 action/filter，先收集、后 `register()`；Phase 2A 后接管 7 个模块的静态钩子及 cron，并提供 `actions()`/`filters()` 供测试。
- `src/Contracts/Module.php` + 各模块 `register_hooks()`：模块不再在构造函数内直接挂 WordPress 钩子；`Plugin` 统一遍历注册。
- 入口 `sakura-tools.php` 降到 62 行，只保留插件头、常量、自动加载、激活/停用注册、`plugins_loaded` 启动。

### 14.3 加载策略

- `Settings`、`Media_Library`、`S3_Settings` 是纯后台模块，只在 `is_admin()` 为真时实例化；`admin-ajax.php` 也满足该条件，批量处理/AJAX 不受影响。
- `Thumbnail_Manager`、`Image_Processor`、`Oss_Thumbnail`、`S3\Storage` 在前后台与 REST 都保留，避免上传/同步链路在非后台请求中失效。
- `WP_CLI` 下仍按需 `require_once includes/class-s3-cli.php`，不经过自动加载。
- `load_plugin_textdomain()` 仍在 `plugins_loaded` 回调开头执行，不改变翻译加载时机。

### 14.4 验证与已知限制

- `php -l`、PHPStan level 5（0 errors）、PHPUnit 18/38 通过；Phase 2B 后为 27/51，见 14.5。
- WP-CLI：`class_exists()` 能加载 `Plugin`/`Activation`/`Hook_Loader`；`Activation::run()` 可执行；cron 钩子存在。
- 前台首页 200；后台设置、S3、OSS、统计、备份、进度页正常；WP-CLI 上传 20×20 PNG 可正常转 WebP 并写入备份 meta，删除附件后备份文件被清理。
- 停用/激活链路实测：`Deactivation::run()` 恢复 `upload_url_path` 并清除 cron；`Activation::run()` 重新应用原 CDN 前缀并恢复每日任务。
- Phase 2A 已完成模块接口与集中注册：7 个模块实现 `Module`，构造函数不再挂钩子；`Thumbnail_Manager` 的 init 尺寸过滤与 `S3_Settings::migrate_history()` 的临时 `posts_where` 仍为运行期动态注册。
- 限制：尚未引入依赖注入/仓储，`Plugin` 仍是手工装配；`Image_Processor` 仍承担上传、备份、还原与元数据编排。下一步可拆 `Image`/`Backup`/`S3`/`Oss`/`Admin` 领域服务。

### 14.5 分层架构 Phase 2B（1.3.3）

**范围**：在 Phase 2A 模块接口之上，先拆图片处理域，引入备份仓储接口、引擎管理器和还原服务，并在 `Plugin` 做构造注入；不改 option/postmeta、备份目录、URL 规则和钩子签名。

**新增**

- `src/Contracts/Backup_Repository_Interface.php`：备份数据操作契约。
- `src/Backup/Backup_Repository.php`：委托既有 `Backup` 静态实现，保持路径校验与清理逻辑不变。
- `src/Image/Engine_Manager.php`：Imagick→GD 引擎选择，统一失败返回。
- `src/Image/Restore_Service.php`：还原、生成文件清理、MIME 猜测与 `is_restoring()` 静态标志。

**装配**

- `Image_Processor` 构造可注入仓储/引擎/还原服务；`delete_attachment`、上传备份、pending 写 meta、还原删备份均走 `$this->backup`。
- `Media_Library` 构造可注入共享的 `Image_Processor`/`Backup_Repository`；批量 AJAX 不再每次 `new Image_Processor()`。
- `Plugin` 共享创建 `Backup_Repository`、`Image_Processor`、`Media_Library`，保持模块顺序。

**测试与验证**

- `tests/BackupRepositoryTest.php` 5 例、`tests/RestoreServiceTest.php` 4 例；PHPUnit **27 tests / 51 assertions** 通过。
- PHPStan level 5：0 errors；全量非 vendor PHP `php -l` 通过。
- WP-CLI 上传 20×20 PNG（附件 888128）→ WebP、写备份 meta；还原为 PNG 并删除备份；删除附件后文件清理。
- 前台 200；设置、S3、OSS、统计、备份、进度页正常。
- 注意：新增 service/view 文件必须属主 `www:www`、文件 644、目录 755，否则前台会 500（`require_once Permission denied`）。

**仍存边界**

- 无 DI 容器；`Stats`/`File_System` 未做仓储；S3/Oss/Admin 未拆领域服务。
- `Image_Processor` 仍是上传/备份/还原/元数据编排者；`Restore_Service` 的路径归一化仍调用 `Backup` 静态方法，属渐进迁移。
- 统计聚合在测试清理后按现存日志重算为 90 条，历史测试附件日志不可恢复。

**测试命令**（开发依赖需 `composer install`，完成后可移除根 `vendor/`，保留 `vendor/s3/`）：

```bash
composer test
composer phpstan
```


### 14.6 分层架构 Phase 2C（1.3.3）

**范围**：统计/文件系统仓储、OSS URL 重写、后台资源加载、S3 客户端工厂；不引入容器，不改 option/postmeta、备份目录、URL 规则与钩子签名。

**新增**

- `src/Contracts/Stats_Repository_Interface.php`、`src/Stats/Stats_Repository.php`。
- `src/Contracts/File_System_Interface.php`、`src/Filesystem/File_System_Repository.php`。
- `src/Oss/Url_Rewriter.php`：URL/srcset 重写、优化后缀与签名查询判定等纯函数。
- `src/Admin/Assets.php`：后台 CSS/内联样式/脚本。
- `src/S3/Client_Factory.php`：S3Client 构建与进程内缓存。

**装配**

- `Image_Processor`/`Media_Library` 构造注入统计与文件系统仓储；`Restore_Service` 注入文件仓储。
- `Settings` 注入统计仓储，保存/重置统计走仓储；资源加载委托 `Assets`。
- `Oss_Thumbnail` 的 `rewrite_srcset()`/`rewrite_image_url()` 委托 `Url_Rewriter`；`S3_Storage::get_client()` 委托 `Client_Factory`。
- `Plugin` 共享构建 `Backup_Repository`/`Stats_Repository`/`File_System_Repository` 并注入。

**验证**

- PHPUnit **38 tests / 77 assertions**；PHPStan level 5 **0 errors**；非 vendor PHP `php -l` 50 个文件通过。
- WP-CLI 上传 20×20 PNG（附件 888129）→ WebP/备份；`Restore_Service` 还原 PNG 并删备份；删除附件后无残留；统计校正为 90。
- 后台设置、S3、OSS、统计、备份、进度页正常；前台 200；统计页 90 次/300.32 MB，备份页 90 个文件/328.90 MB。
- 新增文件必须 `www:www`、644；目录 755。否则前台会因 `require_once Permission denied` 返回 500。

**仍存边界**

- 无 DI 容器；备份/统计 view 仍调用静态 `Backup`/`Stats`。
- `Image_Processor` 仍是上传/备份/还原/元数据编排者。
- OSS 缓冲区/作用域解析、S3 上传队列/元数据/删除仍在原类；仅拆出可独立测试的纯函数与工厂。

### 14.7 分层架构 Phase 2D（1.3.3）

**范围**：备份/统计视图改为仓储注入；OSS DOM 作用域过滤、S3 上传队列拆为独立服务；不引入容器，不改 option/postmeta、备份目录、URL 规则与钩子签名。

**新增/调整**

- `src/Oss/Html_Filter.php`：`filter_scoped_html`、作用域栈、媒体属性重写、class 匹配；`Oss_Thumbnail` 707→494 行。
- `src/S3/Upload_Queue.php`：附件排队、shutdown flush、元数据上传、指纹去重；`S3_Storage` 361→321 行。
- `Settings` 构造注入 `Backup_Repository_Interface`；备份/统计视图使用 `$backup`、`$stats_repo`，不再直接调用静态类。
- `Plugin` 将同一 `$backup`/`$stats` 注入 `Settings`、`Media_Library` 与处理链。
- `S3_Storage` 默认构建 `Upload_Queue`，以闭包注入上传/Key/选项/请求守卫，避免反向依赖。

**验证**

- PHPUnit **40 tests / 79 assertions**；PHPStan level 5 **0 errors**；非 vendor PHP `php -l` 通过。
- 前台 200，OSS 参数仍生效；设置、备份、统计、S3、OSS、进度页正常。
- 备份页 90 文件/328.90 MB，统计页 90 次/300.32 MB。
- 新文件必须 `www:www`、644；本轮再次因 600/root 触发前台 500，已修复。

**仍存边界**

- 无容器；`S3_Storage` 的上传/删除仍静态调用 SDK 客户端，`S3_Settings` 迁移逻辑未拆。
- OSS 的缓冲启动、侧边栏 Ajax 判定与设置页仍在 `Oss_Thumbnail`。
- `Image_Processor` 仍是上传/备份/还原/元数据编排者；视图注入仅覆盖备份/统计。

### 14.8 分层架构 Phase 2E（1.3.3）

**范围**：S3 单文件上传、批量删除、历史附件迁移服务化；不改 Key、ACL、错误文案、迁移游标与 CLI 行为。

**新增/调整**

- `src/S3/Object_Store.php`：注入 client/options/acl/log callable；流式 `putObject`、ContentType、成功后删本地、`deleteObjects` 1000 键分片。
- `S3_Storage::upload_file()`/`delete_keys()`：保留静态兼容层，先校验/规范化 Key，再委托 `Object_Store`；类 321→306 行。
- `src/S3/Migration_Service.php`：注入 attachment_files/key_from_path/upload_file/query_batch；承接游标、逐文件上传、失败聚合、done 判定。
- `S3_Settings::migrate_history()`：保留 cap/nonce/配置检查与 `WP_Query` 游标查询，循环改由服务执行；类 436→425 行。
- `S3_CLI` 继续通过静态兼容层上传，不改变命令输出。

**测试**

- `ObjectStoreTest` 3 例（成功上传删本地、异常保留本地、1001 键分片删除）。
- `MigrationServiceTest` 2 例（批量上传/游标/完成、路径与上传失败聚合）。
- 合计 **45 tests / 107 assertions**；PHPStan level 5 **0 errors**；`php -l` 通过。

**运行验证**

- 前台 200（81,214 B），OSS 参数生效；S3 后台页正常；新类可加载。
- 未连生产桶，未做真实 MinIO/S3 写入；测试使用内存 fake 客户端。
- 新文件属主/权限统一 `www:www`、644。

**仍存边界**

- 无容器；`S3_Storage::get_client()`/`options()` 仍静态。
- 未做 MinIO/S3 端到端集成测试。
- S3 元数据同步仍由 `Upload_Queue` 负责。

### 14.9 分层架构 Phase 2F（1.3.3）

**范围**：本地 S3 兼容端点端到端验证 Phase 2E 的上传、删除、迁移服务；不触碰生产桶。

**环境**

- `moto` S3：`http://127.0.0.1:9000`，path-style，`us-east-1`，桶 `sakura-integration`/`sakura-phpunit`。
- MinIO 镜像受镜像站限制未启动，使用 moto 的 S3 兼容实现。
- `wp eval-file` + `pre_option_s3_options` 注入端点，未改数据库配置。

**结果**

- `S3_Storage::upload_file()` 4096 B → HEAD 4096；`delete_keys()` 后 HEAD 不存在。
- `Migration_Service` 2 文件 → `uploaded=2`、`failed=[]`，对象 HEAD 2048 B。
- `tests/S3IntegrationTest.php` 默认跳过；设置 `SAKURA_S3_ENDPOINT` 后 1 测试/4 断言通过。
- 全量：默认 46 tests / 107 assertions / 1 skipped；连 moto 时 46 tests / 111 assertions。
- PHPStan 0；非 vendor `php -l` 58 个通过。

**仍存边界**

- 本地兼容端点不等于 MinIO/生产服务；未验证真实服务商签名、ACL、分片上传、限流重试。
- 生产启用前建议在 staging 桶用临时前缀写入一次。

### 14.10 分层架构 Phase 2G（1.3.3）

**范围**：本地 S3Proxy 端到端验证；第三方 S3 兼容服务的校验和兼容、批量删除回退与错误码映射。

**改动**

- `src/S3/Client_Factory.php`：`request_checksum_calculation`/`response_checksum_validation` 设为 `when_required`，避免默认 CRC32 头导致兼容服务 `NotImplemented`。
- `src/S3/Object_Store.php`：上传异常使用 `Error_Mapper`；`deleteObjects` 失败回退逐键 `deleteObject`。
- `src/S3/Error_Mapper.php`：稳定错误码（auth/not_found/throttled/timeout/rejected）与中文消息。
- 测试：`ErrorMapperTest` 2 例，`ObjectStoreTest` 增加删除回退。

**验证**

- S3Proxy 上传 2048 B、HEAD 2048、ACL AllUsers READ、删除后 404；错误凭据返回 `sakura_s3_auth_failed`。
- 默认 49 tests / 120 assertions / 1 skipped；连接端点 49/124；PHPStan 0；60 个非 vendor PHP `php -l` 通过。

**边界**

- S3Proxy 不是 MinIO/AWS；未覆盖显式分片上传、限流重试计数、生产桶策略。

### 14.11 深度优化：图片上传门面与 S3 配置实例化（1.3.3）

**范围**：拆分 `Image_Processor`，把 S3 option 解析与上传路径从静态适配器抽成实例；不引入容器，不改 option/postmeta/URL/钩子优先级。

**结构**

- `includes/class-image-processor.php` 300 行，只做 `Module` 钩子与回调委托。
- `src/Image/File_Processor.php` 193 行：文件处理、跳过原因、质量/格式。
- `src/Image/Upload_Handler.php` 457 行：重命名、备份、上传替换、逐尺寸元数据。
- `src/Image/Engine_Manager.php` 62 行、`src/Image/Restore_Service.php` 208 行。
- `src/S3/Options.php` 144 行：默认值、legacy 启用、常量覆盖、路径/ACL。
- `src/S3/Storage_Adapter.php` 139 行：实例化 Key 映射与上传；`S3_CLI` 不再直接调用静态上传/Key 方法。
- `S3_Storage` 保留静态门面（14.12 已移除）；配置方法委托 `S3\\Options`，旧钩子/旧调用不变。

**测试**

- 新增 `FileProcessorTest` 3、`UploadHandlerTest` 1、`OptionsTest` 4、`StorageAdapterTest` 3。
- 全量 **60 tests / 149 assertions / 1 skipped**；PHPStan level 5 **0 errors**；64 个非 vendor PHP `php -l` 通过。

**权限事故**

- 新建文件为 root `600`，前台报 `Permission denied` 500；统一 `chown www:www`、644/755 后恢复。新增文件必须纳入权限检查。

**验证与边界**

- 主设置/S3/统计/备份/OSS/进度页正常；前台 200；上传→WebP→还原→JPEG；备份失败硬失败。
- `wp sakura-s3 ... --dry-run` 正常；未连接生产桶。
- 14.12 已移除 S3 静态门面并拆分 `Upload_Handler`；无容器；默认无 WordPress 集成测试。

### 14.12 移除 S3 静态门面与 Upload_Handler 二阶段拆分（1.3.3）

**S3**

- 删除 `includes/class-s3-storage.php` / `S3_Storage`；新增 `src/S3/Storage.php`（实例模块，163 行）与 `src/S3/Log.php`。
- `Storage_Adapter` 承担 Key 映射、归一化、上传、删除、附件文件枚举；`S3_Settings`、`S3_CLI`、`S3_Doge_Cloud`、`Plugin` 全部改走实例。
- `Upload_Queue` 通过闭包注入上传/Key/配置/跳过回调，不再依赖具体存储类。
- 钩子优先级与 option 结构不变。

**Upload_Handler**

- `src/Image/Upload_Handler.php` 457→196 行，只保留编排。
- 新增 `Upload_Renamer`（214）、`Upload_Backup`（73）、`Metadata_Processor`（222）。
- `Image_Processor` 构造签名不变，内部自动组装四个上传服务。

**测试与验证**

- 新增 `UploadRenamerTest` 2 例、`UploadBackupTest` 2 例；全量 **63 tests / 151 assertions / 1 skipped**。
- PHPStan level 5 **0 errors**；70 个非 vendor PHP `php -l` 通过。
- 运行时 Storage 注册 3 actions / 2 filters；`S3_Storage` 类不存在；S3 CLI dry-run 正常。
- 上传 888133 → WebP、备份 meta 完整，删除后清理；统计恢复 90/90/0；前台 200。

**边界**

- 无容器；`S3_Settings` 已在 14.13 拆分；S3 集成测试默认跳过；版本仍 1.3.3。


### 14.13 S3_Settings 按职责拆分（1.3.3）

- `includes/class-s3-settings.php` 457→102 行：只保留 Module、菜单、资源与装配。
- 新增 `src/S3/Settings_Page.php`（182）、`Settings_Saver.php`（86）、`Connection_Tester.php`（52）、`History_Migrator.php`（69）、`Providers.php`（30）、`Upload_URL.php`（43）。
- `Activation`/`Deactivation` 改用 `Upload_URL::apply()/restore()`；`S3_Settings` 中旧的静态 URL 方法与 `providers()` 已删除。
- 保存、连接测试、迁移的 capability/nonce/字段清洗保持不变；迁移仍为 3 个/批。
- 新增 `S3ProvidersTest`；全量 64 tests / 155 assertions / 1 skipped；PHPStan 0；77 个 PHP 文件 lint 通过。
- 浏览器 S3 页正常；未修改保存成功；保存后 option 与 `upload_url_path` 复核不变。未执行连接测试/历史迁移，避免访问/写入生产桶。

### 14.14 后台 UI 视觉重构（1.3.3）

- `assets/admin.css` 重写为 1200 行蓝/白/淡粉主题；新增 `admin-clouds.svg`、`admin-sakura.svg`，按插件页面 `body:has()` 隔离。
- `views/media-progress.php` 改为统一 hero/卡片结构；`Media_Library` 为进度页补 `admin_enqueue_scripts` 与 `admin_title`。
- S3/OSS 独立页面 enqueue 统一使用 `Admin\Assets::version()`（文件 mtime 破缓存）；按钮四态、单选框、圆形复选框、禁用态、横向溢出与移动端表格在验收中修复。
- 主设置页改为两列等宽卡片（CSS multi-column 自动平衡，`settings-main.php` 自定义渲染 sections/fields），`<=1200px` 单列；页面高度约 3062→1994px。
- 容器取消 1180px 上限，宽度随 `#wpbody-content` 自适应；1800px 视口下 wrap 1577px、卡片各 779px。
- 插件全部复选框统一 18px 圆形外框 + 8px 白点；白点绝对定位（top/left 50% + translate(-50%,-50%)）居中，覆盖 WP 21px SVG 对勾；设置页、S3 页 3 个、OSS 页 5 个计算验证，窄屏居中。
- 尺寸表窄屏在卡片内横向滚动；`large` 隐藏时用隐藏值保留。
- 1400px 与 iframe 760/400px 抽查通过；保存成功且 option 不变；未改业务逻辑、option 名称、钩子与 URL；无视觉回归自动化。


### 14.15 WordPress 规范落地（1.3.3）

- 生命周期：`Activation` 只写默认值、迁移旧配置并记录 `sakura_imgopt_version`；`Deactivation` 只清 cron；新增 `src/Uninstaller.php`，默认保留持久数据，`sakura_imgopt_delete_data_on_uninstall=1` 时才删除 options/postmeta/备份并恢复媒体 URL。
- 卸载开关位于「备份管理 → 自动清理策略」；默认关闭，关闭时仍清理队列与 token 缓存，但保留设置、S3 凭据和备份。
- 权限：媒体批量/AJAX 从 `manage_options` 改为 `upload_files`，并逐附件校验 `edit_post`；无可编辑附件时回跳 `sakura_bulk_blocked`；设置/备份 AJAX 继续 `manage_options`。
- 资源：`Admin\Assets` 只按 `$hook` 加载；`wp_set_script_translations` 已接入；POT 258 条。
- 依赖：AWS SDK 用 Strauss 前缀化为 `Sakura\Vendor\Aws`，输出 `vendor/s3/vendor-prefixed/`（约 7.8M），删除未前缀 `vendor/s3/vendor/`；`Client_Factory`、`S3_CLI`、集成测试与 PHPStan stubs 已切换。
- 兼容：CI 矩阵增加 PHP 8.4；Requires WordPress 6.4 / PHP 8.2（原 8.1，因 SDK 要求于 14.18 修正）。
- 验证：67 tests / 158 assertions / 1 skipped；PHPStan 0；83 个 PHP 文件 lint；前台 200；S3 CLI 正常；主设置/备份/S3 页面浏览器复核；作者账号批量处理被阻止。
- 本地矩阵：PHP 8.3.36 与 8.4.27 均跑通 lint 83、PHPUnit 67/158、PHPStan 0；PHP 8.1 已不再支持。
- 边界：未在真实站点执行卸载（破坏性）；多站点卸载未做运行验证；`Settings` 仍直接使用 Settings API 选项，未强行包 repository；动态 JS 文案未全量进 POT。

### 14.16 Settings Repository 与 i18n（1.3.3）

- 新增 `src/Contracts/Settings_Repository_Interface.php` 与 `src/Settings/Settings_Repository.php`；option 前缀、默认值、typed readers、范围/白名单归一集中管理。
- `Plugin` 创建单一 repository 并注入 `Settings`、`Image_Processor`、`Media_Library`；`File_Processor`/`Upload_Handler`/`Upload_Renamer`/`Upload_Backup`/`Thumbnail_Manager`/`Backup::clean_expired` 均经 repository 读取。
- `Settings` 类内不再直接 `get_option( 'sakura_imgopt_*' )`；剩余直接读取仅限 `Activation`/`Uninstaller` 生命周期，以及 Stats/OSS/S3 独立 option。
- `views/media-progress.php` 的内联 JS 改为 `i18n` 对象 + `wp_json_encode`，补齐 translators 注释；`Media_Library`、`Connection_Tester`、DogeCloud 日志与 OSS 设置标签全部进入 `__()`。
- POT 349 条；`wp i18n make-pot` 无 placeholder 警告。
- 验证：PHPUnit 74 tests / 184 assertions / 1 skipped；PHPStan 0；86 个非 vendor PHP 文件 lint；设置页保存成功、option 读回一致；前台 200。

### 14.17 S3 生产级可靠性（1.3.3）

**状态与安全删除**

- 新增 `src/S3/Sync_Status.php`；postmeta：`_sakura_s3_status`、`_sakura_s3_error`、`_sakura_s3_time`、`_sakura_s3_objects`、`_sakura_s3_jobs`。
- 上传成功记录对象 Key；删除附件只删 `objects()` 中记录的 Key，无记录直接跳过。批量/单条删除仍按 1000 Key 分片。
- 媒体库新增「S3 同步」列；附件列表显示 synced/pending/failed 与错误。

**重试队列**

- 失败任务存 attachment postmeta，按 Key 去重；`attempts`、`next_attempt` 控制退避。5 分钟 cron `sakura_imgopt_s3_retry`；`60 × 2^attempts`，封顶 3600 秒，8 次后 `dead`。
- `src/S3/Retry_Service.php`：transient 锁、逐 job 重放、成功后删除 job 并在无待处理任务时标 synced；本地文件缺失/上传失败写回错误。
- `Storage` 通过 `Upload_Queue` 成功/失败回调入队；`queue_attachment` 只标 pending，不覆盖已 synced；metadata 空更新也不再回退。
- `Migration_Service` 回调记录 Key、失败入队、成功且无 job 时标 synced。

**配置守卫**

- `Storage::register_hooks()`：S3 启用但 Bucket/Key/Secret 缺失时恢复 `upload_url_path`、不接管媒体 URL、不入队、不注册重试。
- `Settings_Saver` 拒绝保存「启用 + 缺凭据」，返回 `config` 错误；页面展示缺失项。

**后台可观测**

- 新增 `src/S3/Sync_Panel.php`：失败附件、状态、最近错误、单条/全部重试；AJAX `sakura_s3_retry` 校验 `manage_options` + nonce，单条或 10 条/批。
- 失败任务发 `admin_notices`；面板和通知都链接到 S3 页。
- `assets/admin.css` 增加 `.sakura-s3-sync` 卡片/表格样式。

**Multipart**

- `Object_Store`：16 MB 及以上（含 16 MB）走 multipart，分片 8 MB（5–100 MB），支持过滤器；逐片上传，失败 `abortMultipartUpload`。
- 兼容 `Aws\Result` 的 ArrayAccess 与数组两种返回；ETag 缺失立即失败。

**生命周期**

- `Activation` 注册 `sakura_imgopt_5min` 与 `sakura_imgopt_s3_retry`；`Deactivation`/`Uninstaller` 清 cron。
- `Uninstaller` 删除状态/对象/任务 postmeta（按卸载开关）。

**验证**

- PHPUnit：默认 99 tests / 265 assertions / 2 skipped；连接本地 S3Proxy 时 99 tests / 273 assertions / 0 skipped；PHPStan 0；94 个非 vendor PHP lint。
- 真实 S3：小图附件 888135 上传后 synced、objects=1、jobs=0；删除附件后状态/objects 清空；17 MB 文件 multipart 上传并删除成功。
- 本地 S3Proxy 2.4.0（filesystem 后端，127.0.0.1:9000）：17 MB multipart 上传、HEAD、删除通过；DeleteObjects 未实现时回退逐个删除成功。
- 伪造失败任务：面板显示，AJAX 重试返回 processed=1，缺本地文件保持 failed、attempts+1。
- `wp cron event list` 存在 5 分钟任务；S3 设置页正常；前台 200。

**边界**

- 队列按附件 postmeta 存储，适合媒体库规模；百万级全局任务应迁移独立表。
- 面板只列最近 20 个失败附件，批量单次最多 10 个任务；旧附件无状态/objects 时不接管、不删除远端对象。
- multipart 在缤纷云与本地 S3Proxy 2.4.0 实测，未覆盖 MinIO/AWS 全部分片边界；PHP 8.1 已不再支持。

### 14.18 PHP 版本与 AWS SDK 审计（1.3.3）

**结论**

- 插件内 AWS SDK 实际为 **3.402.0**（`Sakura\Vendor\Aws\Sdk::VERSION`），是当前 Packagist 最新版本，并未过时。
- SDK 3.402.0 的 `php` 约束是 **>=8.2**；插件头原先写 `Requires PHP: 8.1` 是不准确的，已改为 `8.2`。
- PHP 8.1 已于 **2025-12-31** 结束安全支持；继续声明 8.1 会让 8.1 站点误以为可安装。PHP 8.2 安全支持到 **2026-12-31**，上线新站建议直接 8.3+。

**修正**

- `sakura-tools.php`、`readme.txt`：`Requires PHP: 8.2`。
- CI：PHPUnit 矩阵 `8.2/8.3/8.4`；PHPStan job 改 PHP 8.2。
- `vendor/s3/composer.lock` 原为 3.388.8（支持 8.1），与实际前缀化 SDK 3.402.0 不一致；已更新为 3.402.0 + Guzzle 8.3.0。
- `vendor/s3/composer.json` 仍为 `aws/aws-sdk-php: ^3.387`，允许 3.402.0。

**验证**

- 本地 PHP 8.3.36 / 8.4.27 通过；PHP 8.2 本机不可用，交由 CI。
- PHPUnit：默认 99 tests / 265 assertions / 2 skipped；本地 S3Proxy 下 99/273 / 0 skipped。
- PHPStan 0；94 个非 vendor PHP lint 通过。

**边界**

- PHP 8.2 接近 EOL；若发布面向新环境，可把最低版本进一步提高到 8.3，但会缩小兼容范围。
- 插件业务代码本身未必使用 8.2 语法；最低版本由捆绑 SDK 的约束决定。

### 14.19 配置不完整硬阻断（1.3.3）

**规则**

- S3 开启时必须满足：provider 合法、Bucket / Key / Secret 非空、Endpoint 为 HTTP/HTTPS；`aws` provider 可用空 Endpoint 走 SDK 默认端点。
- 不满足时：恢复 `upload_url_path`、阻止图片上传、停用 S3 队列/删除/重试钩子、拒绝历史迁移/CLI/手动重试/保存。
- 图片通过 `wp_handle_upload_prefilter` / `wp_handle_sideload_prefilter` 直接返回错误，不再“先本地优化、后上传失败”。
- 非图片附件不拦截上传，但 S3 钩子未注册，只保留本地副本。
- 已存在的失败队列保留；补全配置后 cron/手动重试继续。

**关键位置**

- `src/S3/Options.php::configuration_errors()`
- `src/S3/Storage.php::register_hooks()` / `block_incomplete_upload()`
- `src/S3/History_Migrator.php`
- `includes/class-s3-cli.php::require_configuration()`
- `includes/class-s3-settings.php::retry_failed()`
- `src/S3/Settings_Saver.php` / `Settings_Page.php`
- `src/S3/Client_Factory.php`

**验证**

- 临时清空线上 endpoint：`wp media import` 被拒且无附件；`wp sakura-s3 upload-file --dry-run` 退出 1。
- 恢复 endpoint 后前台 200；PHPUnit 105/274/2 skipped、PHPStan 0、lint 通过。

### 14.20 S3 凭据加密存储（1.3.3）

**规则**

- `s3_options.key/secret` 必须密文入库；后台输入框始终为空，页面源码不输出明文或密文。
- 加密优先 Sodium secretbox，回退 OpenSSL AES-256-GCM；密文前缀 `sio1s:` / `sio1o:`。
- 推荐 `wp-config.php`：

  ```php
  define( 'SAKURA_TOOLS_CREDENTIAL_KEY', '至少 32 字节的随机串' );
  ```

  未定义时从 `wp_salt('auth')` 派生；解密会同时尝试常量与 salts 两套密钥。
- 更换固定密钥或 salts 后旧凭据不可解密；设置页提示重新输入，不静默明文回退。
- 首次请求自动迁移旧明文；已加密值不重复加密。

**实现**

- `src/S3/Credential_Cipher.php`：encrypt/decrypt/is_encrypted/migrate_legacy_option。
- `Options`：构造时解密；失败标记 `credentials_unreadable()`，配置守卫继续硬阻断。
- `Settings_Saver`：保存前加密；留空字段保留旧凭据并重新加密。
- `Plugin::boot()`：调用 `Credential_Cipher::migrate_legacy_option()`。
- `Settings_Page`：加密/轮换提示；Key/Secret `value=""`。

**验证**

- PHPUnit 112/297/2 skipped；PHPStan 0；`CredentialCipherTest` 7 例。
- 线上迁移后 raw option 为密文，解密哈希与迁移前备份一致，`is_configured()` true。
- 页面源码无密文；输入框 value 为空；留空保存后仍为密文且哈希不变。

**边界**

- 密钥必须保存在数据库之外；丢失即无法恢复，只能重填。
- 同时拿到数据库与 `wp-config.php` 的攻击者仍可解密；本方案不等于 KMS。
- 未处理数据库备份文件本身的加密。

### 14.21 多服务商配置保存与切换（1.3.3）

**规则**

- `s3_options.profiles` 保存每个服务商一份连接配置；顶层字段始终是当前活动配置。
- Key/Secret 在 profile 中仍为 `sio1s:` / `sio1o:` 密文，切换时只复制密文，不解密到页面。
- 服务商下拉框显示 `（已保存）` / `（未保存）`；已保存卡片可点击切换。
- 切换只在 profile 完整且可解密时成功；不完整 profile 的卡片禁用。
- 切换不改变 `enabled`、`no_thumb`、`no_local_saving` 等全局策略，也不改写文章内历史 URL。

**实现**

- `Options::profiles()/profile()/saved_providers()/migrate_legacy_profile()`。
- `src/S3/Profile_Manager.php`：校验 profile、复制活动字段、维护 `upload_path` 与 `upload_url_path`。
- `Settings_Page`：已保存服务商卡片区 + 下拉保存状态。
- `Settings_Saver`：保存时写入/刷新当前 profile；切换服务商且未填凭据时不会复用旧服务商凭据。
- `S3_Settings`：`admin_post_sakura_s3_switch`（`manage_options` + nonce）。

**验证**

- 旧单配置自动迁移为 bitiful profile；页面标签「缤纷云（已保存）」，卡片「使用中」。
- 切换提示、URL 保持、留空保存保留 profile 与密文。
- 临时第二 profile 布局验证后清理。
- PHPUnit 117/323/2 skipped；PHPStan 0；POT 361。

**边界**

- 同一服务商只支持一个 profile；多账号需改为 profile id 模型。
- profile 不保存 `enabled`/上传策略；这些仍是全局设置。
- 配置不完整的 profile 只能补全后切换，不会自动修复。

### 14.22 独立 Profile ID 与删除（1.3.3）

> 本节取代 14.21 的 provider slug 主键设计。

**数据模型**

- `s3_options.profiles` 改为独立 ID 键：`prof_...`；每项包含 `id/name/provider/bucket/region/endpoint/key/secret/object_acl/upload_path/upload_url_path/saved_at`。
- 新增 `s3_options.active_profile_id`；顶层活动字段保留，兼容既有 S3 客户端、CLI、Upload_URL。
- `Options::migrate_legacy_profile()` 把旧 provider 键 profile 转为 ID，保留密文、名称与 `saved_at`。
- 「对象访问权限」「本地文件夹」「媒体 URL 前缀」属于连接字段，随 profile 保存/切换/删除；设置页放在「连接配置」内。

**多账户**

- 同一服务商可保存多个独立 profile；用独立 ID 与「配置名称」区分账户，不以 provider 作为唯一键。
- 页头「新增账户配置」会勾选「另存为新配置」、清空名称并聚焦，用户填写该账户 Key/Secret 后保存。
- 名称留空时按 `provider · bucket` 生成；同名自动追加 ` (2)`、` (3)`，避免卡片重名。
- 每份 profile 各自持有加密凭据；切换不改全局启用状态、上传策略或文章历史 URL。

**保存**

- 设置页增加「配置名称」「另存为新配置」；表单提交 `profile_id`。
- 勾选另存为新配置始终生成新 ID；切换服务商时即使未勾选，也会生成新 ID，避免覆盖旧 profile。
- Key/Secret 留空且仍编辑同一 profile 时保留旧密文；换服务商留空不会复用旧凭据。
- `enabled/no_thumb/no_local_saving` 仍是全局策略，不写入 profile。

**切换**

- 卡片使用 `profile_id` 提交；`Profile_Manager::switch_profile()` 按 ID 校验、复制连接字段、维护路径与 URL。
- 切换不改 `enabled`、`no_thumb`、`no_local_saving`，也不批量改写文章内容中的历史 URL；若 profile 的上传路径/URL 不同，会按该 profile 更新连接路径设置。

**删除**

- 所有卡片显示删除控件；非活动为「删除此配置」，活动为禁用态「使用中不可删除」。
- `admin_post_sakura_s3_delete`：`manage_options` + 每 profile nonce；删除前确认。
- 活动 profile 拒绝删除（`delete_active`），前端按钮同时禁用；必须先切换。
- 删除只移除 profile 数据，不改顶层活动配置、URL 或 S3 对象。

**验证**

- 线上保存第二 profile、切换回原配置、删除测试 profile 均通过；活动 profile 删除返回 `active`。
- 活动卡片显示禁用删除按钮；新增账户按钮会勾选另存为并聚焦名称；名称冲突自动加序号。
- 切换前后 `enabled/no_thumb/no_local_saving/upload_url_path` 不变。
- PHPUnit 119 tests / 334 assertions / 2 skipped；PHPStan 0；POT 重新生成。

**边界**

- 不支持“删除活动配置并自动切换”。
- 删除后密文不可恢复；配置名称只用于后台识别。
- 多站点仍按站点独立保存。

### 14.23 全量 i18n 适配（1.3.3）

- 用户可见 PHP 文案全部使用 `sakura-imgopt` 文本域；带参数文案使用编号占位符。
- `Size_Catalog`/`Crop_Config_Scanner` 的常量数组改为返回翻译值的方法。
- JS 动态文案由 `wp_localize_script()` 注入；后台样式脚本与同步中心脚本均调用 `wp_set_script_translations()`。
- `languages/sakura-imgopt.pot` 重新生成，651 条 msgid；未附带 en_US 翻译文件，英文界面需补齐 `.po/.mo`。
- 验证：PHPUnit 120/335/2 skipped（含 `TranslationCoverageTest`）、PHPStan 0、72 个 PHP 文件 lint 通过。


### 14.24 S3 同步中心（1.3.3）

**页面**

- 后台「樱花工具箱 → S3 同步中心」，slug `sakura-tools-s3-sync`；仅 `manage_options`，仅该页加载 `s3-sync-center.css/js`。
- 视觉按已确认静态稿一比一移植：品牌栏、hero 连接摘要、KPI 五卡、任务中心、连接与队列健康、云端体积环图、六类差异筛选表、日志、对账口径。
- 对账扫描只读；行内上传/下载/删除已接真实动作。删除必须通过弹窗风险说明 + 圆形确认复选框 + 服务端 `confirm=DELETE`，且对象 Key 必须命中当前快照。

**数据层**

- `src/S3/Inventory.php`：本地 `wp_upload_dir()` 扫描 + `Storage_Adapter::list_objects()` 云列举；按 Key/大小/修改时间分类为 synced/pending/failed/remote/mismatch；transient 缓存 30 分钟。
- `src/S3/Object_Store::list_objects()`：`listObjectsV2` 分页 1000，最多 50000 对象，只读。
- `src/S3/Storage_Adapter::list_objects()`：使用当前 profile 的 `upload_path` 前缀，返回统一对象数组。
- `src/S3/Sync_Center.php`：`Module` 注册页面；`sakura_s3_sync_scan` 只读扫描；`sakura_s3_sync_test` headBucket 测速；`sakura_s3_sync_export` CSV 导出。

**性能与限制**

- 表格最多保留 300 行差异；本地 25000 文件、云端 50000 对象上限；超出会截断，页面顶部显示截断警告，KPI 汇总仍基于已扫描子集。
- 快照缓存 30 分钟；“重新对账”立即刷新，连接测试不写库。
- 云端列举需要 `s3:ListBucket`；大桶/海量小文件会受端点分页与执行时限影响。

**验证**

- 线上只读对账：本地 100 文件、云端 107 对象、差异 112（synced 95/pending 5/remote 12）。
- 桌面 1400px、移动 390px 无页面级横向溢出；筛选/分页/搜索/日志级别/连接测试正常。
- `#wpcontent` 默认左内边距在该页归零，页面背景全宽，左侧无白色空条。
- PHPUnit 120/335/2 skipped；PHPStan 0；非 vendor PHP lint 通过。

### 14.25 S3 行内动作（1.3.3）

**服务**

- `S3\Sync_Actions`：`upload`、`download`、`delete`；Key 归一化后必须落在 uploads realpath 内，拒绝 `wp-config.php`/`..`/NUL。
- `Object_Store::download_file()`：流式临时文件 + rename，不覆盖本地已有文件。
- `Sync_Center::ajax_action()`：`manage_options` + `sakura_s3_sync_action` nonce + S3 已启用且配置完整；Key 必须命中当前快照。
- 删除需要 `confirm=DELETE`；上传覆盖需要 `confirm=REPLACE`；动作完成后重建快照。

**界面**

- 待上传行「上传」、仅云端行「下载」「删除远端」、校验差异行「重新上传」。
- 删除弹窗展示 Key、名称/大小、红色风险说明和圆形确认复选框；未勾选时按钮禁用。

**验证**

- 真实 S3 临时对象走完上传 → 删除本地 → 下载 → 删除远端；内容一致，测试对象已清理。
- 删除无 `confirm` 返回 400；路径穿越被拒绝；浏览器弹窗与刷新通过。

**边界**

- 删除只删云端对象，不动本地媒体/备份；下载不覆盖已有本地文件。
- 上传不自动创建媒体库记录。


## 14.26 P1 数据库索引与分批扫描（2026-10-25）

### 表结构

- `Schema`：`sio_sync_index/scans/counts/jobs/events`；`VERSION=1.0.0`。
- `sio_sync_index` 唯一键 `profile_id + object_key_hash`，保存 local path、type、sizes、mtime、status、source、scan_id、seen_scan。
- `sio_sync_scans` 保存 `phase/scan_cursor/processed/total/bytes/state/error`，用于断点与 stale 恢复。

### 流程

- `Index_Repository::start_scan()`：advisory lock；10 分钟未更新的 running 自动 terminated。
- `Scan_Service::tick()`：local → remote（每 tick 最多 2 页、每页 1000）→ reconcile。
- 前端启动后连续 `ajax_scan_step`；页面关闭时可在任务表恢复。
- `page()` keyset 分页，限制 200；页面首屏 50，JS 服务端筛选/搜索。

### 容量

- 本地扫描 25 万上限、云端 5 万对象上限；超出时明确提示截断。
- 100 万对象目标的容量结论来自 `s3-bench/` 分层压测，不是生产端到端。

### 验证

- 本地 100、云端 108、差异 112；首屏 50、下一页 50/12、搜索 3、仅云端 12。
- UI 上传/下载/删除流程通过；测试文件/对象清理。
- PHPUnit 120/335/2 skipped；PHPStan 0。

### 未完成

- 后台 worker 已由 14.27 接入；前端轮询仍是即时交互路径。
- 10 万级/百万级生产回归未做。
- 附件级状态与 Key 级索引尚未映射。

## 14.27 P2 后台 Scan Worker（2026-10-25）

- 新增 `src/Sync/Scan_Worker.php`：`schedule()` 单次 `sakura_imgopt_sync_scan_tick`，`run()` 推进 2 页、running 时 10 秒后续排。
- `Plugin` 在非 admin 请求也构建 worker；此前只在 admin 内构建，WP-Cron 回调会缺失。
- `Sync_Center::ajax_scan` 启动后立即调度 worker；前端 tick 保留为即时反馈。
- 验证：scan 11 由 cron 在 0.357 s 内跑完，状态 done、本地 100 / 云端 108 / 差异 112；`has_action` 在 WP-CLI 下为 true。
- 边界：依赖 WP-Cron；无 tick 互斥锁；无显式取消/继续；仅小规模验证。

## 14.28 P3/P4 索引规模化、断点续跑与迁移（2026-10-25）

### 索引与 schema

- `src/Sync/Schema.php` 升到 `1.1.0`：`index` 增加 `kind varchar(16)` 与 `KEY profile_kind (profile_id,kind,object_key_hash)`；`scans` 增加 `cloud_image/cloud_video/cloud_archive`（云对象类型预聚合）。`install()` 检测旧版本后调用 `migrate_kind()`（有界 `backfill_kind(20000)`，剩余排 `sakura_imgopt_sync_kind_backfill`），并删除旧 `sakura_imgopt_s3_inventory` transient。
- `src/Sync/Kind.php`：扩展名 → image/video/archive/other；`src/Sync/Keyset_Cursor.php`：32 字节 sha256 游标编码/校验。
- `Index_Repository`：
  - `page()` 搜索改为**前缀** `LIKE '<prefix>%'`，类型改 `kind = ?`，游标走 `Keyset_Cursor`；
  - `summary()`/`cloud_types()` 只读 `counts` + `scans` 预聚合，不再对全表 `REGEXP`；
  - `apply_action()` 改为**增量**更新 counts（读旧行→按状态差调整），不再每次全量 `GROUP BY`；
  - 新增 `resume_scan()`/`cancel_scan()`/`state()`/`tick` 锁/`cleanup()`（events 30 天、dead jobs 30 天、scans 90 天）/`purge_index()`/`backfill_kind()`/`rebuild_type_counts()`/`optimize()`（扫描结束执行 `ANALYZE TABLE`）/`recent_scans()`。

### 扫描与 Worker

- `Scan_Service` 重写：本地阶段用确定性 DFS（`scandir` 排序、跳过 symlink 与备份/缓存目录），以**相对路径游标**断点续跑；每 500 行 upsert 并保存 processed/bytes/游标；云端阶段沿用 ContinuationToken，1/2/4 s 退避重试 3 次，字节数改为累计写入；`tick($id, $max_pages, $max_seconds)` 支持时间预算，`GET_LOCK('sio_tick_<scan>')` 防止 AJAX 与 cron 并发；每批检查取消；整轮成功后才 reconcile/purge_stale/rebuild_counts/ANALYZE/finish。
- `Scan_Worker`：单次 tick 20 s 预算，running 时 2 s 后续排（busy 4 s），捕获 `Throwable` 记为 `sio_scan_exception`；新增每日 `sakura_imgopt_sync_cleanup` 与 kind 回填续跑。
- `Sync_Center`：新增 `cancel`/`resume` AJAX 与 nonce；scan/step 使用 12 s tick 预算；`view_data()` 暴露 scan 状态/阶段/可继续；导出带上当前筛选；前端 400 ms 轮询、停止/继续按钮、页面打开时自动续接运行中的扫描。

### 运维与迁移

- 新增 `wp sakura-sync scan|status|rebuild|cleanup|purge`（`src/Sync/CLI.php`）；`scan` 支持 `--profile/--resume/--pages/--seconds/--local-root`。
- `Uninstaller` 卸载时清掉三个 sync cron、DROP 五张 sync 表、删除 schema/flag option 与旧 Inventory transient（索引是派生数据，不应留孤儿表）。
- `Inventory` 下线为仅 `format_bytes`；`Activation` 调度维护事件。

### 验证

- PHPUnit 130 tests / 358 assertions / 2 skipped；PHPStan 0 errors；TranslationCoverage 通过。
- 100k 本地：取消 → 继续同一 scan → 4.51 s 完成，peak 105.8 MB，行数 100108、dups 0。
- 100 万云端（mock_s3.py，1000/页，3 ms/页）：5 个 tick 74.59 s，peak 107.8 MB，行数 1,000,000、dups 0；`cloud_types` image=1,000,000。
- 1M 行延迟：all 0.95 ms、synced 0.59 ms、pending 0.4 ms（ANALYZE 后）、type 0.78 ms、summary 0.30 ms；前缀搜索命中 ~1.3 s、未命中 ~2.6 s；days=30 ~2.4 s（均为低频筛选，见设计 13.5.3）。
- 真实 S3（缤纷云 nboke）：生产 108 对象单页 ListObjectsV2 307 ms；据此 100 万串行约 5–6 min，满足 ≤10 min。
- UI（真实浏览器）：点击「重新对账」scan #14 由 AJAX+Worker 6 s 内 done；停止/继续显隐、运行中自动续接、导出筛选、失败行动作通过。
- 迁移：真实库 option 升 1.1.0，kind 列/索引与 scans 新列就位；112 行 kind 回填完成；生产 scan #16 正常。

### 已知偏差/未完成

- 1M 索引约 607 MB（设计 440 MB），因新增 kind 等值索引；删 `profile_kind` 可回落但类型筛选变慢。
- 前缀搜索与「最近 N 天」在 1M 下仍是全表过滤（1.3–2.6 s / 2.4 s），未加 object_key 与 mtime 索引。
- 对账只比 size，未实现 ETag/mtime 2 s 判定（multipart ETag 不可直接比 MD5）。
- 列举串行，无 4 路并发；Attachment 上传未双写索引（需下一次对账）；无 feature flag 回滚（表可重建）。

## 14.29 v1.4.1：<100 ms 搜索、附件双写与本机 MinIO 验证（2026-10-25）

产品确认后实施三项：搜索必须 <100 ms、附件上传即写索引、改用本机自建 S3（不再消耗真实 bucket 免费额度）。

### 搜索：按 object_key 顺序的 keyset

- `src/Sync/Schema.php` 升到 **1.2.0**：`index` 增加 `KEY profile_key (profile_id, object_key)`（`object_key` 为 varbinary(1024)，索引 key_len 1188）。无需重建表，1M 行在线加索引秒级完成。
- `src/Sync/Keyset_Cursor.php`：新增 value 游标 `from_value()` / `decode_value()`（`"\x02"` 前缀 + base64 原 key；解码校验 ≤1024 字节且无 NUL），与原有 hash 游标互不兼容（单测覆盖）。
- `Index_Repository::page()`：有 `$search` 时走 `object_key LIKE 'prefix%'` + `ORDER BY object_key ASC`，游标为上一页最后 key；无搜索时仍用 `object_key_hash` keyset（`status_keyset` 覆盖状态页）。两者不能交叉解码。
- 实测 1M 行（真实索引表）：key 模式 broad 0.19 ms、deep 0.20 ms、selective 0.12 ms、miss 0.10 ms；`find()` 0.12 ms；仓库 `page()` 0.16–0.65 ms。原 hash 模式 broad 0.63–0.80 ms。
- 体积：data 190.1 MB + index 459.1 MB = **649.2 MB/100 万**（比 1.1.0 的 607 MB 增约 42 MB）。
- 说明：`admin-ajax` 端到端 50 行约 159 ms，主要花在 WordPress 引导；SQL 切片 <1 ms 且不随行数增长。

### 附件双写：`src/Sync/Attachment_Indexer.php`

- 注册 `add_attachment`(20) → `index_attachment()`、`wp_update_attachment_metadata`(20) → `on_metadata()`（返回元数据不变）、`delete_attachment`(10) → `forget_attachment()`；`ready()` 要求已启用 + active profile + 配置完整，可用 `sakura_imgopt_sync_attachment_index` 过滤关闭。
- `keys_for_attachment()` 由 `Storage_Adapter::attachment_files()` 推导 uploads 相对路径，key = `upload_path + '/' + relative`；文件不存在也能算 key（上传瞬间 metadata 尚未写入）。
- `Index_Repository::record_local()`：按 hash 查旧行 → 计算 `local_status()`（ignored/failed 保留；有远端则 size 相等 synced 否则 mismatch；否则 pending）→ upsert → 状态变化时更新 status/error → 增量 `adjust_counts()`。`forget_local()`：本地-only 直接删行；有远端降级为 remote；本地-only 的 ignored 行也删除（避免幽灵行）。
- 实测：`wp media import /tmp/sio-upload-test.png`（被插件转 webp）后索引立即出现 pending 行（size 3,254），counts pending +1；`wp_delete_attachment` 后行消失、counts 回 4/12/96；替换文件 70→1234 B 后 `local_size` 更新；ignored 行删除后清理。
- 修复两个实际 bug：
  1. `forget_attachment()` 曾把 `keys_for_attachment()` 的关联数组（key=>path）直接传给 `forget_local()`，`array_map('strval')` 取到 path 导致删不到行；改为 `array_keys()`。
  2. `$wpdb->prepare('%s', hash('sha256', $key, true))` 的二进制摘要在 utf8mb4 下被 MariaDB 判为「无效数据」，`upsert_local()` 整批失败；所有 hash 参数改走 `UNHEX(%s)`（ASCII hex），涉及 upsert/find/page 游标/IN 查询。`local_mtime/remote_mtime` 为 null 时用 `NULLIF(%s,'')` 防止空串进 DATETIME。

### 本机 MinIO 百万级验证

- 容器：`sio-minio`（`minio/minio:latest`），`-p 9100:9000 -p 9101:9001`，账号 `benchadmin/benchsecret123`，数据 `/root/minio-data`（首次误放 tmpfs `/tmp` 触发 inode 耗尽，已迁到 ext4）。
- 播种：`s3-bench/minio_seed.py`（纯标准库 SigV4，线程池，修过 create_bucket 修改全局 `BODY_HASH` 导致的 403）；1,000,000 对象 301.6 s（3,316/s，0 错误）。
- 扫描：内存 `Options`（endpoint `http://127.0.0.1:9100`、bucket `bench`、profile `bench_minio_1m`）+ 100 个同 key 本地文件 + `Scan_Service(..., '/tmp/sio-local-minio')`；4 个 tick：**90.16 s / 峰值 107.8 MB / rows 1,000,000 / dups 0**；counts `remote 999900 / synced 100`；`cloud_image=1,000,000`。
- MinIO 页面延迟：all 0.79 ms、deep 1.09 ms、selective 0.20 ms、broad 0.44 ms、broad+status 0.47 ms、miss 0.16 ms、summary 0.22 ms、find 0.12 ms、synced 0.45 ms。
- 跑完即 `purge_index` + `ANALYZE` + 删 scans，避免共享库膨胀。

### 测试与验证

- PHPUnit **133 tests / 366 assertions / 2 skipped**；PHPStan 0 errors。
- 基准脚本 `s3-bench/search_bench.php` 现含仓库层 `page()` 计时与 selective/broad/miss/deep 四类；`s3-bench/README.md` 记录命令与结果。
- 迁移：生产库 `sakura_imgopt_sync_schema=1.2.0`，`SHOW INDEX` 含 `profile_key`；生产 counts 仍 96/4/12、索引 112 行。
- UI：浏览器搜索 `2026` 返回 50 行；媒体导入/删除双写验证通过；PHP fatal 已排查（新增文件权限 600 导致，统一 www:www 644）。

### 已知边界

- 搜索仍是前缀匹配，子串/模糊未支持；「最近 N 天」仍为全表 mtime 过滤（1M≈2.4 s）。
- 双写只覆盖 WordPress 媒体库附件；直接写入 uploads 的文件依赖下一次对账。
- 真实公网 bucket 未再跑（用户额度限制），MinIO 结果作为 1M 列举证据；生产 108 对象 307 ms 校准仍有效。
- 1M 索引 649 MB 已被产品接受。

## 14.30 v1.5.0：动作队列、失败重试、回滚开关与可观测性（2026-10-25）

按设计方案复审后补齐的第三轮内容。

### 架构

- **Schema 1.3.0**：`scans` 增加 `attempts`；`jobs` 增加 `object_key varbinary(1024)`（原表只有 hash）。`Schema::maybe_install()` 在线 dbDelta，旧数据保留。
- **扫描失败重试**：`Scan_Worker::handle_failure()` 区分 fatal（配置不完整、`sio_local_limit`、`sio_local_cursor`、`sio_uploads_unavailable`、`sio_unknown_profile`）与可重试错误；可重试时 `record_scan_failure()` 累加 attempts 并以 10 s / 20 s 重排，第 3 次才 `fail_scan`。CLI 扫描同样受益。
- **动作队列**：`Action_Worker` 钩子 `sakura_imgopt_sync_job_tick`，每次最多 10 个；>50 MB 上传与批量动作由 `Sync_Center::ajax_action()` 入队（删除仍要求逐个 `confirm=DELETE`）；`Index_Repository::job_failure()` 按 `60 × 2^attempt` 退避、封顶 3600 s，8 次后 dead；成功走 `apply_action()` 并写 `action_ok` 事件。`admin_init` 兜底调度，队列非空时 worker 自续排。
- **轻量重算**：`Scan_Service::refresh()` 只做 `recalculate()` + `rebuild_counts()` + `summary()`，不访问 S3；`Sync_Center::ajax_refresh()`（按钮“刷新差异”）与每日 `sakura_imgopt_sync_daily_reconcile` 使用同一路径。
- **回滚开关**：`Feature`（option `sakura_imgopt_sync_index_enabled`，默认 1）。关闭后 `view_data()` 走 `Legacy_Snapshot`（30 分钟 transient、本地 25 k、云端 50 k、300 行），页面顶部显示兼容条并禁用扫描/搜索/分页/行内动作；`Attachment_Indexer::ready()` 同时检查开关，停止双写。表、option、历史数据全部保留。
- **可观测性**：`Metrics`（autoload=false option）记录 `pages/batches/batch_ms/s3_429/s3_5xx/scans/scan_ms/actions_ok/actions_failed/jobs_dead`；健康卡显示“本轮指标”“动作队列待执行/死信”；`admin_notices()` 在同步中心提示 24 h 内扫描失败、dead 任务、配置错误；删除动作额外写 `confirm` 事件。
- **前端**：`#sakura-sync-scan-2` 改为调用 refresh；`[id^=sakura-sync-toggle-index]` 处理停用/启用；动作响应 `queued` 时 toast 并在 1.6 s 后刷新；兼容模式下禁用搜索、分页、行内按钮。

### 验证

- `Schema::maybe_install()` 后 `schema=1.3.0`，`scans.attempts`、`jobs.object_key` 均存在；索引 112 行、counts 不变。
- 队列 eval：入队 → 失败 attempts=1、next_at=+60 s → 连续重试至 dead，`jobs_summary dead=1`；测试行已删除。
- 页面 `view_data()`：索引模式 1.87 ms；兼容快照冷 348.5 ms / 热 0.78 ms。
- 响应式：390/900/1400 宽 iframe 无横向溢出。
- PHPUnit 135 tests / 371 assertions / 2 skipped；PHPStan 0 errors；`php -l` 全绿。

### 运维/回滚

```bash
wp option update sakura_imgopt_sync_index_enabled 0   # 回滚到兼容快照
wp option update sakura_imgopt_sync_index_enabled 1   # 恢复索引
wp cron event run sakura_imgopt_sync_job_tick         # 手动跑队列
wp sakura-sync status                                  # 只读查看计数
```

### 注意

- `Action_Worker::schedule()` 不能声明 `int $delay`：它同时被 `admin_init` 调用，WP 会把钩子参数（字符串）传入，导致 TypeError；已改为无类型参数并 `(int)` 转换。
- 新建文件默认 root:0600 会让 web 请求 `require_once` 失败（Metrics.php 曾导致整页致命）；部署后需 `chown www:www` + `chmod 644`。
- 兼容条必须放在 `.sakura-sync-app` 内部，否则拿不到 SVG 精灵与 `alert-strip` 样式（曾出现黑色三角与布局溢出）。

## 14.31 S3 首传持久队列（v1.6.0，2026-10-25；历史）

> **历史章节（v1.6.0–v1.11.x）**：v1.12.0 已移除异步上传队列，改为请求内同步上传。`Upload_Jobs`、`Upload_Worker`、`sakura_imgopt_s3_upload_tick`、watchdog 与 `sakura_imgopt_async_upload` 均已删除；`Deactivation`/`Uninstaller` 仍会清理这些旧 cron 名。下面描述的是当时的设计，不要按它排查现网上传。

### 背景

v1.5.0 之前，首次上传在 `add_attachment`/`shutdown` 里同步执行。S3 慢、PHP 超时或进程被杀时，附件会永久停在 `pending`，`Retry_Service` 只处理“已经写入失败任务”的附件，无法自愈；删除附件只尝试一次远端删除，失败后也没有补偿。

### 结构

- 新表 `{prefix}sio_s3_uploads`（`src/S3/Upload_Jobs.php`，schema option `sakura_imgopt_s3_queue_schema=1.0.0`）：
  `attachment_id / object_key / object_key_hash / local_path / delete_local / state / attempts / next_at / last_error`，
  唯一键 `(attachment_id, object_key_hash)`，索引 `(state,next_at)`。本地路径存 uploads 相对路径。
- `src/S3/Upload_Worker.php`：1 分钟 cron `sakura_imgopt_s3_upload_tick` 排水，单次最多 20 个任务 / 20 秒；GET_LOCK 防并发；`running` 超过 10 分钟视为崩溃可重领；有剩余任务时 5 秒后续排。上传成功后 `record_object()`，该附件无队列行且无死信时置 `synced`。
- 退避 `60 × 2^(n-1)` 秒，封顶 1 小时；第 8 次失败置 `dead` 并写 `Sync_Status::mark_dead()`（显示“已停止重试”）。
- 看门狗 `sakura_imgopt_s3_upload_watchdog`（5 分钟）：查 `pending` 且 `post_modified_gmt` 超过 10 分钟、又没有 queued/running 行的附件，按现存文件重新入队；找不到文件则标失败。
- `Storage`：`sakura_imgopt_async_upload` 过滤器默认 true；`add_attachment` 与 metadata 钩子只入队并 `Upload_Worker::kick()`，`shutdown` 不再同步 flush；删除附件清理队列行；重试入口重置死信。
- 激活时 `Upload_Worker::schedule()` 建表并注册 1 分钟/5 分钟事件；停用/卸载清理事件，卸载删表与 schema option。
- 站点可用过滤器临时回退旧行为：`add_filter( 'sakura_imgopt_async_upload', '__return_false' );`

### 验证

- `phpunit`：138 tests / 383 assertions / 2 skipped；PHPStan 0。
- MinIO 端到端（`http://127.0.0.1:9100`，bucket `bench`）：
  - 入队 → `Upload_Worker::run()` → 队列表行消失、`list_page` 能看到对象；
  - 缺失本地文件：第 1 次失败 `attempts=1`、`next_at=+60s`；预置 `attempts=7` 后再次失败 → `state=dead, attempts=8`；
  - 真实附件：`Storage::queue_attachment()` 入队、worker 上传后 `Sync_Status` 从 `pending` 变 `synced`，`_sakura_imgopt_s3_objects` 记录 key；
  - 看门狗：构造 1 小时前修改、状态 `pending`、无队列行的附件，运行后队列出现该附件的行。
- 调度实测：`wp cron event list` 中存在 `sakura_imgopt_s3_upload_tick`（1 分钟）与 `sakura_imgopt_s3_upload_watchdog`（5 分钟）。

### 注意

- 迁移后首次上传不再“请求内完成”：媒体库状态先显示 `pending`，通常 1 个 cron tick 内变 `synced`。若站点禁用 WP-Cron 且没有系统 cron，需要 `wp cron event run sakura_imgopt_s3_upload_tick` 或配置系统 cron。
- 队列行按附件+对象去重，同一文件重复入队不会产生第二行；重复 PUT 是幂等的，但可能多一次对象写入。
- 死信只通过媒体库状态与后台 notice 暴露，尚未做邮件/Webhook 告警。
- 真实生产桶未跑大流量验证（用户额度限制）；上传逻辑用 MinIO + 真实 S3 SDK 路径验证。

## 14.32 v1.6.1：远端删除补偿（2026-10-25；历史结构 + 当前实现）

> **历史章节（v1.6.1）**：`defer_attachment()`、`clear_key()`、`clear_attachment()` 已删除；下面“结构”已按 v1.12.0 的独立 `Delete_Worker` 更新，末尾“当前实现”补充还原对账。

### 结构

- `src/S3/Delete_Jobs.php`：队列表 `{prefix}sio_s3_deletes`，schema option `sakura_imgopt_s3_delete_schema=1.0.0`。字段 `attachment_id/object_key/object_key_hash/state/attempts/next_at/last_error`；`UNIQUE(object_key_hash)` 去重，`KEY (state,next_at)`、`KEY (attachment_id,state)`。
- `Storage::delete_remote_attachment()`：读取 `_sakura_imgopt_s3_objects` 后把全部 Key 写入队列，然后清空附件状态并 `Delete_Worker::kick()`；远端删除完全由 Worker 执行，请求内不再调用 `delete_keys()`（v1.6.1 的同步删除/`defer_attachment()` 兜底已移除）。
- `src/S3/Delete_Worker.php`：独立 1 分钟 cron `sakura_imgopt_s3_delete_tick`（`sakura_imgopt_1min`），单次最多 20 个任务 / 20 秒；`GET_LOCK` + 行租约防并发；附件状态非空且非 `synced` 时推迟 300 秒；删除前用 `Restore_Plan::is_referenced()` 与当前 metadata 复核，仍被引用则保留并记 `[delete queue] kept ...`；成功记 `[delete queue] removed orphan ...`。失败走 `Delete_Jobs::backoff()`（60×2^(n-1)，第 8 次 dead）。
- `Delete_Worker::schedule()` 建表/注册 cron；`kick()` 只为到期任务排一次近时事件。`Delete_Jobs::clear_key()` 已删除：上传/还原流程不再需要在删除前“抢回”同名 Key，引用守卫与 `Restore_Plan` 负责判断。
- `Storage::render_failed_notice()`：同时统计 dead 删除行（提示可能继续计费）与超过 10 分钟未推进的补删行（提示检查 WP-Cron）；S3 设置页重试会 `retry_attachment()`/`requeue_dead(100)` 并 kick。
- `Uninstaller`：清 cron、DROP `sio_s3_deletes`、删 schema option。
- 测试：`tests/DeleteJobsTest.php`；bootstrap require `Delete_Jobs.php`。

### 验证

- PHPUnit 140 tests / 386 assertions / 2 skipped；PHPStan 0。
- MinIO（`127.0.0.1:9100`，bucket `bench`）：
  - 成功：上传 → 删除 → 对象消失、队列 0 行、`_sakura_imgopt_s3_status` 清空；
  - 失败：bucket `bench-nope` → 队列 `queued`、`next_at` 未来、对象仍在；修正配置后 worker 删除成功、队列清空；
  - 死信：`attempts=7` + 错误 bucket → 第 8 次 `dead`，`last_error` 有值；`requeue_dead()` 后 `queued/attempts=0`。
- 表已安装：`sakura_imgopt_s3_delete_schema=1.0.0`；`SHOW CREATE TABLE` 正常。

### 注意

- 删除补偿只覆盖“插件记录过 Key 的附件”；历史上未写入 `_sakura_imgopt_s3_objects` 的对象无法推断 Key，不在回收范围。
- dead 行不会自动删除（保留孤儿证据），仅人工重试；队列表通常很小，未设过期清理。
- worker 使用独立 cron；若站点禁用 WP-Cron，需系统 cron 触发 `sakura_imgopt_s3_delete_tick`（不是上传 tick）。

### 当前实现（v1.12.0+，2026-11）

- 上传：`Synchronous_Uploader::finalize()` 在 `add_attachment` / `wp_update_attachment_metadata` 请求内完成；没有上传队列、后台 Worker 或 watchdog。历史迁移也逐个附件调用 `finalize()`（`manual=true`、`preserve_local=true`）。
- 还原：`Restore_Service` 先 `sakura_imgopt_restore_started`，再 `wp_generate_attachment_metadata` + `wp_update_attachment_metadata`。还原期间的 metadata finalize 传 `preserve_local=true`、`keep_recorded=true`：保留本地文件，且暂不覆盖 `_sakura_imgopt_s3_objects`。随后 `sakura_imgopt_restored` → `Storage::reconcile_attachment()` 用旧对象集合与新的 `desired_keys()` 算 stale，补传新格式，全部 stale 成功入队后才 `set_objects()` 新集合；入队不足则保留 pending 标记，交给 `resume_pending_restores()` 重试。
- 删除：`delete_remote_attachment` 只入队 + 清状态 + kick；独立 `Delete_Worker` 消费，引用守卫避免误删仍被 metadata 引用的对象。

## 14.33 v1.6.2：上传一致性校验（2026-10-25）

### 结构

- 新增 `src/S3/Upload_Verify.php`（纯函数）：
  - `normalize_etag()`：去引号/空白/小写；
  - `multipart_etag(array $part_md5s)`：按 `md5(concat(binary part MD5))-N` 计算 S3 分片 ETag，非法 MD5 返回空串；
  - `compare(local_size, remote_size, expected_etag, remote_etag)`：尺寸优先，其次 ETag（`hash_equals`）；缺失 ETag 视为失败；返回可读中文原因或 null。
- `src/S3/Object_Store.php`：
  - 上传前 `filesize()`，不可读直接 `sakura_s3_stat_failed`（不再进入上传）。
  - 单段：`md5_file()` + `putObject(['ContentMD5' => base64(binary md5)])`；本地 MD5 失败则 `sakura_s3_hash_failed`。
  - 分片：每片 `md5($part)`，`uploadPart` 带 `ContentMD5`，累计 part MD5；`upload_multipart()` 返回期望的分片 ETag。
  - 上传后：新增 `verify_remote()` 调 `HeadObject`，比较 `ContentLength` 与规范化后的 `ETag`；失败返回 `sio_upload_verify_failed`，并 `discard_remote()` 尽力删除坏对象；本地文件保留。
  - 新增 `sakura_imgopt_verify_upload`（默认 true）与 `sakura_imgopt_upload_content_md5`（默认 true）两个过滤器。
- 仅在校验通过后执行 `delete_local`；请求内同步上传把校验失败当作普通上传失败并记录错误，没有异步重试队列（v1.12.0 起）。

### 验证

- 单测：`tests/UploadVerifyTest.php`（规范化、分片 ETag、尺寸/ETag/缺失 ETag、非法 part MD5）；`tests/ObjectStoreTest.php` 增加“截断检测”（head 返回较短 ContentLength → `sio_upload_verify_failed`、deleteObject 被调用、本地文件保留）与“单段带 ContentMD5”“分片期望 ETag”断言；fake client 均补 `headObject`。
- PHPUnit **149 tests / 402 assertions / 2 skipped**；PHPStan 0；`php -l` 通过。
- MinIO（`http://127.0.0.1:9100`，bucket `bench`）：
  - 2 KB 单段：`upload_file` 成功，68 ms（含 HEAD）；
  - 17 MB 分片：成功，246 ms（含 3 片 ContentMD5 与 HEAD），`list_page` 可见；
  - HEAD 的 ContentLength/ETag 与本地 MD5、分片 ETag 一致。
- 故障注入用 fake client 完成，未触碰真实桶。

### 注意

- SSE-KMS 或 ETag 非 MD5 的存储、禁止 `HeadObject` 的写凭据：需 `add_filter('sakura_imgopt_verify_upload', '__return_false')`，否则上传会判失败。
- 某些兼容存储拒绝 `Content-MD5`：关闭 `sakura_imgopt_upload_content_md5`。
- 校验成本：单段一次 HEAD；大文件多一次顺序 MD5 读与每片 MD5；本机测试 2 KB/68 ms、17 MB/246 ms。
- 真实生产桶未跑（额度限制），默认开启；上线后若出现 `sio_upload_verify_failed` 且 HEAD 的 ETag 非 MD5，应关闭校验并在日志确认。

### 14.34 历史迁移大库化（v1.6.3；历史）

> **历史章节（v1.6.3）**：v1.12.0 起历史迁移改为逐附件同步上传；下面“只入队”的队列描述已被取代。

- `src/S3/History_Migrator.php` 重写：
  - 常量 `STATE_OPTION=sakura_imgopt_s3_migrate_state`、`DEFAULT_BATCH=500`、`MAX_BATCH=2000`、`DEFAULT_SECONDS=20.0`、`LOCK_PREFIX=sio_hist_`。
  - `run_batch(bool $reset,int $batch_size,float $max_seconds,bool $dry_run=false)`：`get_posts(post_type=attachment, post_status=inherit, post_mime_type=image/video, orderby=ID ASC, posts_per_page=$batch, post__not_in=failed)`，每页按 `id > cursor` 过滤；每批检查时间预算；`status` 在 `idle→running→done/failed` 间迁移；`save()` 写 option；`GET_LOCK` 互斥，冲突返回 `sio_migrate_busy`。
  - 扫描逐附件调用 `Synchronous_Uploader::finalize()`（`manual=true`、`preserve_local=true`），每个附件在请求内上传并校验，本地副本保留；没有上传队列/Worker。
  - `enqueue_attachment()`：按 `adapter->attachment_files()` 生成 key=>相对路径，过滤备份目录；空文件跳过；缺失文件计 failed；`dry_run` 只计数。
  - `reset(bool $persist=true)`、`defaults()`、`normalize()`（只接受标量，数组回退默认值）、`saved_state()`。
  - AJAX `migrate()`：校验权限/nonce/enabled/configured，`reset/batch_size/seconds` 来自 POST，busy→409。
- `src/S3/Settings_Page.php` 历史迁移区块重写：状态行 + 进度条 + “同步历史附件/重新开始” + WP-CLI 提示；初始状态从 option 读取（idle/running/done/failed 文案）。
  - 事故与恢复：编辑时误删整个文件，已从 `/root/WordPress/backups/sakura-imgopt-pre-sync-index-20261025/src/S3/Settings_Page.php` 恢复（282 行），再仅替换迁移区块；页面 profile/连接配置/同步状态均验证正常。
- `includes/class-s3-cli.php` 新增 `migrate`：`--batch-size`（25–2000）、`--seconds`（1–120）、`--reset`、`--limit`、`--once`、`--dry-run`；循环输出 `Scanned X/Y | queued ...`，结束 `Success:`。
- `tests/HistoryMigratorTest.php` + bootstrap require；`normalize()` 修过数组转字符串 warning，`run_batch` 冗余条件修过 PHPStan。
- 验证：dry-run 99 附件/0.58s/队列 0 行；锁冲突报错；游标续跑只扫其后 3 个；PHPUnit 152/416/2 skipped；PHPStan 0。
- 注意：迁移现在逐附件请求内上传（v1.12.0 起），大批量会拉长单次请求；`--dry-run` 不写状态；本机验证用 MinIO，真实生产桶未跑（额度限制）。

### 14.35 上传可观测性（v1.6.4；历史）

> **历史章节（v1.6.4）**：上传已改为请求内同步，`Upload_Worker`/`Upload_Jobs` 已删除；指标现由 `Upload_Observer` 在 `sakura_imgopt_s3_uploaded` 上记录，`snapshot()` 的 backlog 不再读取上传队列。

- `src/S3/Upload_Metrics.php`：
  - option `sakura_imgopt_s3_upload_metrics`，`VERSION=1.0.0`，`KEEP_DAYS=14`。
  - `record_batch(array $samples)`：每个样本 `ok/ms/bytes/error/attachment/key/dead`；累加 `totals`、写 UTC 日桶、更新 `last_error`、prune 14 天、`update_option(..., false)`。
  - `snapshot(?array $counts=null)`：today/week/totals 派生 rate/avg/max/bytes 文本；backlog 仅来自调用方注入，默认为 0（`Upload_Jobs::counts()` 已随异步队列删除）；last_error 带 `has/ago`。
  - `reset()`；`duration()/bytes()/ago()` 静态格式化（ago 在无 `human_time_diff` 的测试环境回退秒数）。
- 原 `Upload_Jobs::counts()` 已删除；后台卡片的“积压”现在恒为 0，不再代表上传队列。
- `Upload_Worker`：
  - 新增 `Upload_Metrics $metrics`（构造第 6 个可选参数）。
  - `run()` 收集 `$samples`，每轮结束 `record_batch()` 一次；`process()` 返回样本，`upload_file()` 前后 `microtime(true)` 计时，`filesize()` 作为字节；失败时记录错误/附件/Key/dead。
  - `record_failure()` 返回 `jobs->fail()` 的 attempts/dead，供样本使用。
- UI：`Sync_Panel` 新增 `#sakura-s3-metrics` 卡片（积压/今日成功/平均耗时/今日上传量/最后错误），30s 自动 AJAX 刷新 + 手动按钮；`S3_Settings::upload_metrics()` 注册 `wp_ajax_sakura_s3_upload_metrics`（manage_options + nonce）；`assets/admin.css` 追加卡片与 900/600px 响应式。
- CLI：`wp sakura-sync stats [--format=json]`（注意命令是 `sakura-sync`，不是 `sakura-s3`）。
- 测试：`tests/UploadMetricsTest.php`（空快照、成功/失败/死信聚合、14 天 prune、reset）；`tests/bootstrap.php` 补 `DAY_IN_SECONDS`/`KB_IN_BYTES`/`MB_IN_BYTES`/`GB_IN_BYTES` 与 require；**156 tests / 441 assertions / 2 skipped**，PHPStan 0。
- E2E（MinIO 9100，bench 配置）：成功 2 KB/66ms；缺失文件失败 + dead；指标快照 1 ok/2 fail/66.7%/22ms/2.0 KB，last_error 正常；页面刷新后积压随队列实时归零；测后 `reset()` 清空，生产页面回到空状态。
- 注意：只覆盖异步 Worker；同步回退路径不计；UTC 日桶；无外部告警；删除队列不在卡片内。

### 14.36 可观测性告警与 Prometheus（v1.6.5）

**新增**

- `src/S3/Alerting.php`
  - `OPTION='sakura_imgopt_s3_alerts'`，`sanitize()` 只接受 http(s)+host 的 Webhook；阈值/冷却用整数夹取。
  - `evaluate()` 纯函数：`dead`（当前死信 ≥ 阈值）与 `failure_rate`（近 14 天 attempts ≥ min_attempts 且 rate ≥ 阈值）。
  - `maybe_alert()` 先查配置/令牌/冷却，再 `dispatch()`：`wp_remote_post` JSON、5s、`redirection=0`，2xx 写 `sakura_imgopt_s3_alert_last`。
  - cron：`sakura_imgopt_s3_alert_check` + `sakura_imgopt_15min`，`schedule()` 在激活、init、admin_init 兜底。
- `src/S3/Prometheus.php`
  - 40 位十六进制令牌，`sakura_imgopt_s3_prometheus_token`（autoload=false）。
  - 前台入口 `?sakura_imgopt_metrics=<token>`，也接受 `Authorization: Bearer`；403/200，`nocache_headers()`。
  - `format()` 为纯函数，便于单测；指标见设计方案 21.2。
- `src/S3/Monitoring_Panel.php`
  - 设置页“监控与告警”区块；三个 admin-post：保存告警、发送测试、轮换令牌。
  - 一次性 notice 按用户存 transient（60 秒），避免多管理员互相看到提示。
- `includes/class-s3-settings.php` 注册三个 admin-post；`Settings_Page` 在同步面板后挂载。
- `src/Activation.php` 调度 alert cron 并生成 Prometheus 令牌。
- `Uninstaller::monitoring_options()` 删除 metrics/alerts/token 三个 option，`drop_sync_tables()` 同时删 `sakura_imgopt_s3_alert_last` transient，并 `wp_unschedule_hook('sakura_imgopt_s3_alert_check')`。

**验证**

- Prometheus：无令牌/坏令牌 403；正确令牌 200；Bearer 200；轮换后旧 403、新 200。
- Webhook：制造 1 条死信 + 100% 失败率，JSON `reasons=["dead","failure_rate"]`，`text`/`metrics`/`summary` 完整；冷却期内再次触发无新请求。
- 页面：保存成功提示；测试告警提示“已发送”；接收端收到 `test:true`。
- PHPUnit 168 tests / 473 assertions / 2 skipped；PHPStan 0。

**注意**

- Webhook 无重试队列；失败只 `Log::write()`（WP_DEBUG 时可见）。
- 无 HMAC；建议接收端用不可猜 URL 或网关校验。
- 依靠 WP-Cron；生产建议系统 cron 触发 `wp cron event run --due-now`。
- `maybe_serve` 在 `init` 直接输出并 `exit`，不会被主题/页面缓存当成普通页面（但仍建议 CDN 排除该 query）。

### 14.37 搜索端到端提速（v1.6.6）

**问题**：`Index_Repository::page()` 的 SQL 0.1–0.7 ms，但浏览器走 `admin-ajax.php` 端到端 137–159 ms；差异来自管理端完整引导。

**实现**

- `fast-search.php`（插件根目录）：`SHORTINIT` + `wp-load.php`，引导 1.5–2 ms；令牌校验后直接 `$wpdb` keyset 查询。查询条件与游标编码和 `page()` 保持一致（状态白名单、`upload_path` 前缀、kind、days、hash/value 两种游标、`LIMIT n+1`）。
- `src/Sync/Search_Token.php`：`secret()` 由 `AUTH_KEY`/`SECURE_AUTH_SALT` 派生；`create()` 默认 600 秒、页面注入用 1800 秒（上限 3600）；`sign()`/`verify()` 用 `hash_equals`。
- `Sync_Center::enqueue_assets()` 注入 `searchUrl` 与 `{u,e,p,s}`；`assets/s3-sync-center.js` 的 `loadPage()` 优先 `fastPage()`，异常时回退 `request('sakura_s3_sync_page', …)`。
- 端点内校验：参数格式 → HMAC → `usermeta` administrator 角色 → 查询；错误统一 403 JSON，不输出路径/异常。

**验证**

- 浏览器 6 次采样：fast 5.7 ms、admin-ajax 137 ms（同查询 `search=2026`）。
- curl：8.3–9.6 ms；首屏之外输入“2026”触发 1 次 fast 请求（7 ms），行数据与仓库一致。
- 五种筛选 + 第二页与 `Index_Repository::page()` 的 key 集合/游标完全一致。
- 403：无令牌、坏签名、过期、非管理员、profile 不符。
- 回退：把令牌改成无效值后仍能通过 admin-ajax 正常加载。
- 全量测试 174/487/2 skipped，PHPStan 0；新增 `tests/SearchTokenTest.php` 6 个用例。

**注意**

- 直接 PHP 文件必须允许 Web 访问；若被安全规则拦截会自动回退，不影响功能。
- 令牌在查询串中，可能进 access log；页面刷新会取新令牌，TTL 30 分钟。若需要更严格，可改为 Bearer-only 并缩短 TTL。
- 端点只读元数据，不返回文件内容，也不改变任何状态。

### 14.38 days 过滤提速（v1.6.7）

- Schema **1.4.0**：`sio_sync_index.recent_at datetime NULL`（GREATEST(local_mtime, remote_mtime, 1970)）、
  `KEY profile_recent (profile_id, recent_at, object_key_hash)`；新增
  `RECENT_READY='sakura_imgopt_sync_recent_ready'`、`BACKFILL_RECENT_HOOK`。
- `Index_Repository`：`upsert_local/upsert_remote/record_local` 写 `recent_at`（重复键用 GREATEST）；
  `backfill_recent(20000)`、`pending_recent_count()`、`recent_ready()`；`page()` 在
  `days>0 && ready` 时 `recent_at >= ?`、`FORCE INDEX (profile_recent)`、
  `ORDER BY recent_at DESC, object_key_hash DESC`，复合游标 `Keyset_Cursor::from_recent/decode_recent`
  （marker `\x03`、`datetime|hex`）；未 ready 回退旧 OR。
- `fast-search.php` 同步 recent-first 逻辑，避免快慢端点顺序不一致；`assets/s3-sync-center.js`
  在 days 过滤时显示「· 按最近修改排序」。
- 行为：只改 `days>0` 的排序为最近修改优先；默认视图顺序不变；「最近变化」改为 max(local, remote)。
- 迁移：`Schema::maybe_install` 加列/索引 → `backfill_recent` 分批 → pending=0 置 ready；
  Worker 事件每 60 秒续跑，`CLI::rebuild` 同步回填；Uninstaller 清 ready option 与 cron。
- 1M 基准（本地 MariaDB + mock/MinIO）：旧 OR `days=1/7/30/90` 123.01/13.85/3.60/1.38 ms；
  新索引 0.30/0.33/0.31/0.30 ms；deep 0.67 ms；pending 2.08 ms；image 0.52 ms；
  search 0.98/1.09 ms。20 页 1000 行无重复；EXPLAIN range `profile_recent`、无 filesort。
- 测试：176 tests / 495 assertions / 2 skipped；PHPStan 0。
- 注意：索引约 +30–40 MB/1M；回填未完成时仍走旧 OR；真实公网桶未压测。


### 14.39 v1.6.8：ETag / MD5 内容一致性校验（2026-10-25）

- 背景：对账只比较 size，同尺寸不同内容会误标 synced；设计把 ETag 列为可选校验，需要补齐。
- 表结构 1.5.0：`sio_sync_index.local_md5 char(32) NULL`、`verify_state tinyint(3) unsigned NOT NULL DEFAULT 0`（0 未校验/1 匹配/2 不可校验/3 不匹配）。无回填列，首轮后台校验补 MD5。
- 写入路径：
  - `Object_Store::upload_file()` 成功后触发 `sakura_imgopt_s3_uploaded`（单段才有本地 MD5；多段传空），`Upload_Observer::record_uploaded()` 监听并调用 `Index_Repository::record_md5()` 写入索引。
  - 索引 upsert：size/mtime/ETag 未变保留校验和，变化清空；remote ETag 统一 `Checksums::normalize_etag()`。
  - `reconcile()`：双方存在、size 相等且 MD5 与单段 ETag 可比时判定 synced/mismatch；size 不等写 `sio_size_mismatch`。
- 校验服务：`Verify_Service::run(seconds, limit, profile)` 用 keyset + 复合游标（`sakura_imgopt_sync_verify_cursor`），GET_LOCK，流式 `Checksums::hash_file()` 带 deadline（1 MiB 块；到点不前进游标）。mismatch 改状态与 counts，匹配时恢复；查询同时覆盖 `sio_etag_mismatch` 以便自愈。
- 调度/命令：`Verify_Worker`（`sakura_imgopt_sync_verify_tick`，5 分钟 crate 20 秒）；`wp sakura-sync verify [--profile] [--seconds] [--limit] [--once] [--reset]`；Activation/Deactivation/Uninstaller 已接。
- 实测：生产 96 个 synced 全部匹配（0 mismatch/0 unverifiable）；同尺寸篡改 1 个文件 → mismatch 1、counts 95/1，还原后恢复 96；96 文件 28.7 MB 0.65 秒。
- 测试：179 tests / 507 assertions / 2 skipped；PHPStan 0。`tests/bootstrap.php` 增加 `do_action` shim；`Checksums` 缺文件 `@fopen` 避免 warning。
- 注意：比较的是上次列举保存的 ETag，不额外 HEAD；多段/SSE-KMS 不可比记 2；S3 静默损坏（ETag 不变）无法发现；1M 默认占空比需数小时，可用 CLI 提高速度。

### 14.40 v1.6.9：批量选择与输入式删除确认（2026-10-25）

- 表格首列复选框 + 表头全选（仅当前页、最多 10 项）；选中后出现批量工具条。
- 批量工具条：批量上传 / 批量下载 / 批量删除远端 / 清除选择。按钮按所选行状态启用：上传仅 local 且非 synced；下载仅仅云端；删除仅有云端副本。
- 批量上传若包含云端已存在的行，浏览器 confirm 一次；服务端仍要求 `confirm=REPLACE`，否则 409。
- 删除弹窗由“勾选确认”改为“输入 DELETE”；单条与批量共用，批量标题/按钮显示数量。
- 服务端 `ajax_action`：批量删除先校验 `confirm=DELETE`，逐 key 校验状态，全部不可执行时 400 且不入队；事件 `action_batch_<type>`。
- 验收：选中 2 行同步行→上传/下载禁用、删除启用；小写 delete 禁用、DELETE 启用；全选上限 10；错误 confirm 请求返回 400/409，`sio_sync_jobs` 保持 0、计数不变。
- 测试：179 tests / 507 assertions / 2 skipped；PHPStan 0。
- 注意：批量操作只负责入队，实际执行由 Action_Worker 完成；选择在翻页/筛选后清空；不可撤销。

### 14.41 v1.7.0：模块命名统一与后台 UI 宽度规范（2026-10-26）

- 命名约定：后台页面模块统一为 `<功能>_Module` / `<功能>_Page`，数据/服务类保持原名（`Options`、`Object_Store`、`Settings_Repository`、`*_Worker` 等）。
- 类与文件重命名（旧类名通过 `class_alias` 保留，外部引用不断裂）：
  - `Settings` → `Image_Optimizer_Module`（`includes/class-image-optimizer-module.php`）
  - `S3_Settings` → `S3_Storage_Module`（`includes/class-s3-storage-module.php`）
  - `Oss_Thumbnail` → `Thumbnail_Optimizer_Module`（`includes/class-thumbnail-optimizer-module.php`）
  - `Media_Library` → `Media_Library_Module`（`includes/class-media-library-module.php`）
  - `Sync_Center` → `Sync_Center_Module`（`src/S3/Sync_Center_Module.php`）
  - `Settings_Page` → `S3_Storage_Page`（`src/S3/S3_Storage_Page.php`）
  - `Sync_Panel` → `Sync_Center_Panel`（`src/S3/Sync_Center_Panel.php`）
  - 保留 `Sakura\ImageOptimizer\Settings` 命名空间（`src/Settings/Settings_Repository.php`）不改，避免数据层被迫迁移。
- 自动加载：PSR-4 仍按 `src/` 路径；`includes/` 旧式回退文件名与新类名的 kebab 形式一致；`tests/bootstrap.php` 的 require 路径已同步。
- 菜单顺序：图像优化 → 缩略图优化 → 优化统计 → 备份管理 → S3 存储 → S3 同步中心（`Image_Optimizer_Module::reorder_submenu()`）。
- 宽度规范：`.sakura-imgopt-wrap` 与同步中心 `.sakura-sync-app .app` 统一 `max-width:1600px`、水平居中、左右内边距 34px；同步页不再单独去掉 WordPress 的 20px 左侧槽位，六页内容左边界一致。`.sakura-sync-app` 也纳入背景选择器 `body:has(...)`。
- 缓存：CSS/JS 版本跟随插件版本，1.7.0 起旧样式自动失效。
- 验收：六页均 200 且无致命错误；侧栏顺序正确；S3 存储页与同步中心视口 1400px 下内容区 214–1351px 对齐；PHPUnit 179 tests / 507 assertions / 2 skipped；PHPStan 0。

### 14.42 v1.7.1：统一后台页头与英雄区动作（2026-10-26）

- 页头统一（同批完成）：新增共享 partial `includes/views/page-topbar.php`，五页统一渲染「S 标 + 樱花工具箱 / 页面名 + 状态 pill + 快捷入口」；S3 同步中心保留原 topbar（含连接状态）。
- Hero 动作区：图像优化（保存更改 / 查看统计）、缩略图优化（保存设置）、优化统计（返回图像优化 / 重置统计）、备份管理（保存清理策略）、S3 存储（保存 S3 配置 / 打开同步中心）。按钮通过 `form="<id>"` 关联下方表单，表单新增 id：`sakura-imgopt-settings-form`、`sakura-imgopt-oss-form`、`sakura-backup-policy-form`、`sakura-s3-save-form`。
- 样式：`assets/admin.css` 新增 `.sakura-imgopt-topbar/.brand/.pill/.toplink/.hero-actions`；窄屏隐藏路径文字，topbar 换行。
- 验证：五页 hero 顶部动作按钮点击均触发表单提交（图像优化与 S3 存储实测出现“设置已保存”）；截图见 `/root/Downloads/main-topbar.png`、`/root/Downloads/stats-topbar-v2.png`、`/root/Downloads/backup-topbar.png`、`/root/Downloads/thumb-topbar.png`、`/root/Downloads/s3-topbar-v171.png`。
- 兼容：旧类名仍可 `class_exists`；若外部代码直接 `new Settings()` 需先触发自动加载，建议改用新类名。

## 14.43 v1.8.0 单一样式框架与页面紧凑化

用户反馈：后台仍加载两套 CSS/JS（`admin.*` 与 `s3-sync-center.*`），页面 div 的 class 也分属两套体系；要求“只保留一套框架”，并把内容做紧凑。

### 资产合并
- `assets/s3-sync-center.css` 的全部规则迁入 `assets/admin.css`，补上设置页需要的表单层（`.card-body`、`.card-actions`、`.compact-cards`、`.stat-strip`、`.btn`、WP `.button` 适配）；删除 `s3-sync-center.css`。
- `assets/s3-sync-center.js` 迁入 `assets/admin.js`（扫描轮询、分页、批量选择、删除确认、指标/迁移 AJAX），删除 `s3-sync-center.js`。
- 现在全插件只 enqueue `sakura-imgopt-admin` 一个 style + 一个 script；`Sync_Center_Module`、`S3_Storage_Module`、`Thumbnail_Optimizer_Module`、`Admin\Assets` 全部指向同一对文件。
- 旧的 `includes/views/page-topbar.php` 与 `sakura-imgopt-wrap / hero / kicker / panel / topbar` 页面壳已删除，不再保留第二套页面结构。

### 页面结构
- 所有后台页统一为：
  `.sakura-sync-app > .sky-layer + .sakura-layer + .app > .topbar + .hero + .card…`
- 品牌栏：`S` 标 + `樱花工具箱 / <页面名>` + 右侧状态 pill / 快捷入口。
- hero：`eyebrow`、`h1`、`lede`、`hero-actions`、`hero-status`，动作按钮通过 `form="<表单 id>"` 关联下方表单。
- 内容卡片统一 `.card > .card-head + .card-body`，按钮 `.btn / .btn--primary / .btn--small / .btn--danger`。
- 已改造：图像优化、缩略图优化、优化统计、备份管理、S3 存储、批量处理页。S3 页保留少量页面级组件类（`.sakura-s3-profiles`、`.sakura-s3-cli`、`.sakura-s3-metrics`、`.sakura-s3-monitoring`），但样式全部在统一 `admin.css` 内，使用同一套变量与圆角/阴影。
- WordPress 原生 `form-table` 在 `.sakura-sync-app` 内重设为紧凑两列（label 210px、行距 9px），不再继承数据表的右对齐与最小宽度。
- 页面节奏收紧：hero padding 26/30、卡片头 16/20、表单行 9px，去掉重复的 section 标题。

### 验证
- 六个后台页均返回 200、无“严重错误”，每页只加载一次 `assets/admin.css`，不再出现 `s3-sync-center.css`。
- 图像优化与 S3 存储的 hero 保存按钮实测提交并出现保存提示。
- `php -l` 全部视图/页面类通过；PHPUnit 179 tests / 507 assertions / 2 skipped，PHPStan 0 errors；`node --check assets/admin.js` 通过。
- 改动前备份：`/root/backups/sakura-tools-pre-refactor-20261026-081625.tar.gz`。

## 14.44 v1.8.1 两列布局与间距修正

用户反馈：1.8.0 的 `.compact-cards` 在宽屏下自动排成三列，卡片被挤成 320px 左右，单选/说明文字频繁折行；S3 页 hero 与卡片之间有一条突兀的空白带。要求“最多两列，紧凑但不要硬挤”。

- `.compact-cards` 固定为 `repeat(2, minmax(0, 1fr))`，`gap: 20px`，`align-items: start`；1100px 以下切单列。`card--wide` 仍跨两列。
- 两列卡片内 `.form-table` 的 label 列收窄到 150px，输入框继续 100% 宽，减少无意义折行；尺寸表 th 恢复自适应。
- 后台 `.notice` 统一为卡片化提示条（10px 14px、左 4px 强调色、圆角 10px），保存提示不再显示成一条没有内容的空白带。
- S3 页“连接测试”卡补 `h2`，与其它卡头一致。
- 验证：1.8.1 下图像优化为两列（缩略图卡跨列），备份为两列，S3 仍为单列纵向卡片；六页 200、单资源、PHPUnit 179/507/2、PHPStan 0。
- 追加修正：WP 核心给 `.card` 自带 `padding:20px`，造成卡头/表单双重内边距与首行缩进；统一覆盖为 `padding:0`，由 `.card-head` / `.card-body` 控制内部间距。

## 14.45 v1.8.2 瀑布式卡片布局

用户反馈 1.8.1 两列网格仍有“卡片被同行拉高”造成的大片空白，要求真正的瀑布式。

- `.compact-cards` 不再使用 grid；卡片由 `assets/admin.js` 的 `layoutCompactCards()` 按当前高度放入较短的一列，形成 masonry。
- DOM 中生成 `.masonry > .masonry-col × 2`；卡片在 DOM 间移动，不重建节点，原有表单/事件绑定保持有效。
- `.card--wide`（缩略图尺寸卡）继续独占整行，不参与两列分配。
- 监听 `DOMContentLoaded`、`load` 与 `resize`（180ms 防抖）重新分配；无 JS 时退化为自然的单列堆叠。
- 1100px 以下两列改为上下单列。
- 验证：图像优化页 RENAME 卡下方直接接 BULK 卡，空白消失；压缩卡继续在右列；备份页两卡各自一列；六页 200、PHPUnit 179/507/2、PHPStan 0、`node --check assets/admin.js` 通过。

## 14.46 v1.8.3 卡片间距修正

用户反馈：hero 下边框与第一张卡上边框贴得太近，视觉上像两条边框叠在一起。

- `.app > .hero { margin-bottom: 28px; }`，所有使用统一壳的页面生效（图像优化、缩略图、统计、备份、S3、同步中心）。
- 瀑布列间距 20 → 24px；宽卡与后续列之间 20 → 24px。
- 不改变卡片自身内边距与表单密度，只调整块级间距。
- 验证：缩略图页 hero 底边与卡片顶边间距 28px；六页 200，无致命错误；版本 1.8.3。

## 14.47 v1.8.4 卡片与按钮间距补齐

用户指出两处边界仍贴在一起：S3 页“连接测试”独立卡片贴着上方表单卡；缩略图页底部“保存设置”按钮贴着卡片下边框。

- `.app > .card + .card`、`.app > .sakura-s3-actions/.sakura-s3-cli/.sakura-s3-metrics/.sakura-s3-monitoring` 统一 `margin-top: 24px`。
- `.card-actions` 改为 flex 容器，`padding: 22px 2px 6px`，按钮与上方卡片保持 22px。
- 不改变卡片内部 padding 与按钮尺寸。
- 验证：S3 页“连接测试/历史迁移/上传队列”三张卡之间 24px；缩略图页按钮距卡片 22px；六页 200。

## 15. 跨配置失效与三项暂缓决策（2026-10-28）

### 15.1 存储配置变更时作废探测与缓存（已实现）

**问题**：探测结果只记录 mode/state（不含 provider/host），主题缓存 `post_thumbnail_url_{size}` 里存的是旧域名的完整 URL；切换厂商或图片域名后，两者都还在描述旧目标——页面要么指向 404，要么显示“探测通过”但其实没测过新 host。此前 `update_option_s3_options` 没有任何钩子。

**实现**（`includes/class-thumbnail-optimizer-module.php`）：

- 挂 `update_option_s3_options`（10，2 参），`on_s3_options_changed( $old, $new )` 比较 `provider`、`upload_url_path`、`endpoint` 三个字段；只有**确实变化**才动作（避免每次保存都清缓存）。
- 动作：`delete_option( Settings::PROBE_OPTION )` → `Settings::flush_caches()` → `set_transient( 'sakura_imgopt_thumb_probe_reset', 1, 300 )`。
- 探测被删后 `effective_mode()` 自然降级为 `none`（安全侧），页面显示“待确认”。
- 下一次进入缩略图页或 S3 页时，`render_conflict_notice()` 读到 transient，显示一次说明：“存储配置已变更……请重新探测确认后再启用”。

**验证**：

- `tests/ThumbnailProbeInvalidationTest.php`（4 例）：provider / upload_url_path / endpoint 变化均删除探测并写缓存报告；仅改 bucket 不动探测。
- 现场（sio-e2e）：改 `provider` → `probe=false`、`transient=1`、`sakura_imgopt_thumb_cache_flush` 写入（`cache_state=none`，无对象缓存）；恢复配置后 probe 仍为空。

**回滚**：删除该钩子与两个方法即可；不涉及数据结构。

### 15.2 决策：保存时不阻断（保留警告）

私有桶 + 签名 CDN、或保存后再配 CDN 都是合法流程；Settings API 里“返回旧值”会静默丢弃同表单其他字段。结论：**只警告不阻断**（`Settings::conflicts()` + `admin_notices`）。若未来要更硬，采用“勾选确认后放行”，不要无条件拒绝。

### 15.3 决策：不改写同步索引的 Key

改 `upload_path` 后索引 Key 保持旧前缀（已实测），页面会整体 `mismatch`。直接批量 `UPDATE object_key/hash` 有三个硬风险：唯一键冲突、把“本来就以该串开头”的行改坏、大表长事务。正确形态是**标记 stale + 入队一次 refresh 重扫**，并在页面提示“前缀已变，需重建索引”。本轮不做。

### 15.4 决策：不自动清理 `style_map`

`style_map` 只在 `provider=bitiful` 时被 `resolve_mode()` 读取；切到其它厂商后运行时完全惰性（单测覆盖：bitiful→style、aliyun→none）。自动清理会删用户配置、切回需重填，收益接近零。保留，UI 已注明“仅缤纷云命名样式使用”。

## 16. 文件空间搜索：二元组倒排与 MeiliSearch 评估（2026-11-07）

### 16.1 搜索索引（schema 1.5.0 / 1.6.0）

完整决策与测量见 `DECISIONS.md` 的「搜索索引：mime_group 冗余 + 二元组倒排」与「『全部文件』= 根目录；搜索与分类才跨目录」两节，这里只留开发时的速查：

- `sufm_files.mime_group`（1.5.0）：分类页签不再 JOIN `wp_posts`，走 `owner_group_lifecycle(owner_id,lifecycle,mime_group,id)`。
- `sufm_file_tokens(file_id, token)`（1.6.0）：文件名相邻二元组倒排，`PRIMARY KEY(token,file_id)`；`Files::tokens_for()` 是唯一切词入口，`create()` / `rename()` 内联同步，`set_lifecycle('purged')` 同步删除。
- 查询：`token_index_ready()` → `rare_token()`（有界探针，`LIMIT 1500`，最多 4 个）→ 命中稀有 token 才用 `STRAIGHT_JOIN` 派生表，否则整条退回 `display_name LIKE`；LIKE 始终作为精确校验。
- 回填：install 分批、`sufm_tick` 每分钟扫尾（水位 `sufm_tokens_after`）、`wp sufm backfill` 一次跑完。
- 实测：5 万行全库搜索 50–81ms（LIKE）→ 0.2–5ms（预筛）；生产 126 文件端到端 0.5–5ms。

### 16.2 决策：暂不接入 MeiliSearch 做文件检索

用户问「服务器上的 MeiliSearch 能不能加速文件检索」。评估结论是**暂不接入**，理由与触发条件如下（详细版见 `DECISIONS.md`「不接入 MeiliSearch 做文件检索」）。

**本机实例（只读探测）**：meilisearch 1.55.0，`/www/wwwroot/MeiliSearch/`，仅监听 `127.0.0.1:7700`，RSS ≈736MB；nginx 以 `ms.77il.cn` 公网反代、靠 master key 鉴权；仅有 `www_nboke_com_posts`（41 文档，文章搜索在用）一个有效索引；`/etc/systemd/system` 无 unit，重启不自动拉起。

**决定性的一条是语义，不是速度**：

| 查询 | MeiliSearch | 说明 |
| --- | --- | --- |
| `媒体工具` | 2 条 / 0–1ms | 中文分词有效 |
| `Saku` | 1 条 | 前缀匹配有效 |
| `体工` | **0 条** | 词中间片段搜不到 |

文件名是 `20261106-233155-aQ4zj.webp` 这类机器串，用户搜的常是中间片段；Meili 的分词 + 前缀模型对不上，要支持只能自己塞二元组字段，等于把现有索引搬家再加一层 HTTP。

**速度**：现状 0.2–5ms（退化 LIKE 50–80ms/5 万行）；Meili 引擎 0–1ms，PHP 侧端到端约 2–4ms。同一量级，没有赢面。

**将来的触发条件**（任一成立再启动）：需要搜正文（PDF/OCR）、需要错字容忍/拼音、单站文件 20 万+、要做统一全站搜索。

**接入时的形态**：DB 为唯一真相，Meili 只做派生缓存；`create / rename / purge / 移动` 同步、`wp sufm reindex` 可重复全量重建、同步水位可观测、服务不可用自动回退 SQL、`owner_id` 过滤在服务端强制、密钥只在服务端。

**运维待办（未执行）**：master key 写在进程命令行（`ps` 可见）→ 改 0600 配置文件或环境变量；`ms.77il.cn` 加 IP 白名单或仅内网；补 systemd unit/宝塔守护。
