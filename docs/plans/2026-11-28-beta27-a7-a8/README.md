# beta-27：A7 真实角色验收 + A8-1 修复证据

- 日期：2026-11-28
- 工作区：`/root/work/sakura-tools`，版本 `1.30.0-beta-27`（beta-26 为验证基线）
- 环境：`https://demo.77il.cn`（PHP 8.4.28 / WP 7.1.5 / 子比 9.2），生产未触碰
- 关联报告：[2026-11-27-react-rest-audit.md](../../2026-11-27-react-rest-audit.md)

## A8-1 代码级前后对照（已关闭）

同一 harness（`/tmp/beta27-a81-harness.php`，基于 `tests/bootstrap.php` 的 WP shim）分别加载：

- 修复前：demo staging 上的 beta-26 源码（A7 已实现、A8-1 未修）
- 修复后：工作区 beta-27 源码

输入相同：`quota_default_bytes/max_file_bytes/max_user_files/daily_upload_bytes/max_folder_depth/tree_style/folder_accent/allowed_types` 传数组；`sufm_space_settings` 的 `packages/points/points_packages/level_rule` 字段传数组。

| 观测值 | beta-26（修复前） | beta-27（修复后） |
|---|---|---|
| PHP warning | 7 条 `Array to string conversion` | 0 |
| `quota_default_bytes` | `1048576`（1 MB） | `536870912`（默认 512 MB） |
| `max_file_bytes` | `1024`（1 KB） | `52428800`（默认 50 MB） |
| `max_user_files` | `1` | `1000`（默认） |
| `daily_upload_bytes` | `1048576` | `536870912` |
| `max_folder_depth` | `1` | `8`（默认） |
| `allowed_types` 中的嵌套数组 | 产生 warning | 跳过，保留 `image/webp` |
| 套餐 `label` | `Array` | `a1`（数组按缺失字段处理，回落到 id） |
| 套餐 `days` / `active` | `1` / `true` | `0` / `false` |
| 积分套餐（gb/points 为数组） | `1 GB / 1 积分` 被保留 | 整行丢弃（`[]`） |
| `points.points_per_gb` 等 | `1` | `90 / 10 / 20`（默认） |
| `level_rule` | `points_per_step=1`、`bytes_per_step=1` | `1000` / `104857600` |

原始 JSON：

- [evidence/code/before-beta26.json](evidence/code/before-beta26.json)
- [evidence/code/after-beta27.json](evidence/code/after-beta27.json)

对应回归测试：`tests/UserFilesCoreTest.php`（原始字节数组）、`tests/SpaceSettingsTest.php`（Space 非标量）、`tests/Zibll/ZibllSettingsTest.php`（duration/unit 数组）、`tests/UserFilesCapsGateTest.php`（grants 读门）。

## A7 / A8-2 真实角色验证（已闭环）

- HTTP：`evidence/http/matrix-beta27.md`，9 个临时用户 + 匿名 × 12 端点 = 120 格；与 beta-26 基线逐格 diff 仅 4 格变化，全部是 grants 门（review/review_audit/review_sync 200→403，users 403→200），`unexpected=0`；PATCH 被 demo nginx 405 拦截，另有 `rest_do_request` 补证（`aux-patch-dispatch-beta27.json`）。
- 浏览器：`evidence/browser.md`。管理员 queue 4 卡、10 列矩阵 1440/390 无横向溢出、抽屉与焦点回流正常；review 仅 queue 卡、review_users 有 users 卡；users/audit/sync 单项能力角色菜单隐藏且 queue 403；处罚弹窗完整只读表单（关闭后焦点缺口已在 beta-28 修复，见 `docs/plans/2026-11-28-beta28-a8-1-6/`）。
- QM：`evidence/browser/qm-beta27.json`。非空队列 toolbar 0.28 s / 15.5 MB / 18 查询 / 0 error；`#qm` 面板 DOM 未挂载，数值取 `QueryMonitorData.data`。
- 清理：`evidence/cleanup.md`。队列 3→0 / 6→0、临时用户 9→0、主题开关恢复 `'1'`、QM inactive、debug.log 165 行不变。

## beta-27 部署与独立探针（lead 复核）

- 部署：备份 `/root/backups/2026-11-28-beta27-pre-sync/demo-sakura-tools`（145M）后整目录 rsync；demo 三处版本 `1.30.0-beta-27`，bundle SHA-256 `3b946d61…1fc1e`（489,780 B），6 个改动 PHP 在 demo 侧 `php -l` 通过，`debug.log` 保持 165 行。
- grants 读门（真实 cookie + nonce）：`admin/users/review_users` 200；`review/audit/delete/review_audit/review_sync/sync` 403。beta-26 基线中 `review` 为 200。
- 角色页（真实 HTTP）：`review` 200、版本 beta-27、`permissions` 全 false、卡片仅 settings/queue/execute；`review_users` 200、`users:true`、含 users 卡；`users/audit/sync` 单项能力访问 files 模块 403。
- 原始输出：[evidence/http/independent-page-probe.txt](evidence/http/independent-page-probe.txt)。

## 质量门禁（PHP 8.4.28）

- `php -l`：工作区全部改动 PHP 通过
- PHPUnit：`996 tests / 4553 assertions / 5 skipped`
- PHPStan：`[OK] No errors`
- PHPCS strict：`0 error / 0 warning`；PHPCompatibility：`0 error / 0 warning`
- `npm run build`：`assets/ui/dist/admin-app.js` 489,780 B，`node --check` 通过
- `git diff --check`：通过
- 三处版本号：`sakura-tools.php:6`、`sakura-tools.php:22`、`readme.txt:7` 均为 `1.30.0-beta-27`
- bundle SHA-256：`3b946d61fbfaa267c2754aca89604af73e8bed0ae8c014c7b4982f198d17fc1e`

## 清理与限制

- 清理（lead 已独立复核）：9 个 `a7a8_` 临时用户全部删除（仅剩 `yexu`）；`wp_sufm_delete_requests` 3→0、`wp_sufm_delete_request_items` 6→0（marker 0 / 孤儿 0）；`zibll_options.user_disable_admin` 恢复 `'1'`；Query Monitor inactive（`is-active` exit 1）；`debug.log` 165 行、md5 `417da014f3ab49979b56a3cfb825afe7` 不变；events 42→42；`/tmp/a7a8` 已删。详见 `evidence/cleanup.md`。
- 未闭环：PATCH 的 HTTP gate 被 demo nginx 405 拦在 PHP 前，只有 `rest_do_request` 补证；QM `#qm` 面板 DOM 未挂载；处罚弹窗 GET 未单独记状态码；业务写 POST（review/execute/sanction/sync/reconcile/quota/reset）按约束未测；A8-2 #4/#7/#8 仍未验证。