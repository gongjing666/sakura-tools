# sakura-tools 仓库工作指南

给在这个仓库里改代码的 AI 会话和开发者。**本文件只放「必须先知道」和「该去哪儿读」，规则正文一律在 `docs/开发规则/`，不要在这里复述。**

- 版本基线 **1.27.5**，分支 `main`，要求 PHP 8.2+ / WordPress 6.4+
- 类、方法、钩子、文件清单：`docs/sakura-tools-代码结构-1.27.5.md`（**生成物，不要手改**）
- 设计动机（为什么这么做）：`DECISIONS.md`

## 1. 按任务选一篇文档

| 我要做什么 | 读这个 |
|---|---|
| 第一次接触，想弄清插件由什么组成 | `docs/开发规则/01-架构与装配.md` |
| 加设置项、加页面、加 AJAX 动作、加 cron | `docs/开发规则/02-新增功能.md` |
| 改后台样式、加卡片、调布局、写页签视图 | `docs/开发规则/03-后台UI.md` |
| 加表、加 option、写迁移、改卸载逻辑 | `docs/开发规则/04-数据与存储.md` |
| 写测试、处理 PHPStan / PHPCS 报错 | `docs/开发规则/05-测试与质量.md` |
| 发版、打包、部署、回滚 | `docs/开发规则/06-发布流程.md` |
| 查历史（**不代表现状**） | `docs/history/` |

入口索引与铁律原文：`docs/开发规则/00-索引.md`。**动手前至少读 01 的 §2–§4 和 02 的 §0–§1。**

## 2. 骨架（改代码前必须知道的事实）

```
sakura-tools.php        常量 + 手写 spl_autoload_register(:58-87) + 生命周期挂载
uninstall.php           卸载入口，必须先 require 主文件（见下）
fast-search.php         前台快速检索，SHORTINIT 独立入口
src/                    新代码（PSR-4 风格）
  Plugin.php            组合根：boot() :71，build_modules() :124
  Admin/                后台信息架构：Registry / Menu / Layout / Page_Assets / Card_Form
  Contracts/Module.php  模块契约 :23
includes/               1.3–1.9 旧类，仍在承重
assets/ui/              后台样式与脚本
docs/                   文档（不进发布包）
```

**两个命名空间，回落规则不同**：

| 前缀 | 先找 | 找不到再找 |
|---|---|---|
| `Sakura\ImageOptimizer\` | `src/{rel}.php` | `includes/class-{lower_snake}.php` |
| `Nboke\UserFiles\` | `src/UserFiles/{rel}.php` | **没有回落** |

新增类只要路径与命名空间对得上就会被自动加载，不需要注册。

**`includes/` 不是死代码。** 那段 15 行 legacy 清单里每个文件都在生产承重，删任何一个都会运行时 fatal 而平时不报错。新代码一律写 `src/`。

**钩子先入队，最后统一 `register()`。** 所有 `add_action` / `add_filter` 走 `Hook_Loader`（`src/Support/Hook_Loader.php`）；`register()` 幂等；注册之后再 `add_action` 会立即生效（有代码依赖这个行为）。

**模块契约**：构造函数只赋值、不做 IO、不挂钩子；钩子全部写在 `register_hooks( Hook_Loader $hooks )`。这样组合根能决定装不装，测试能在不启动 WordPress 的情况下检查钩子面。

**装配顺序**（`Plugin::build_modules()`，改顺序会让 renderer 找不到）：仓储 → admin 模块 → 媒体/缩略图 → 存储与同步 → `Admin\Menu`（**永远最后**，它要遍历已注册的 renderer）→ 队列与 UserFiles。

**后台路由四层**：
`Registry::modules()` :41 → `Admin\Menu` → `Registry::current()` :165 → 事先 `register_renderer()` 的回调。

| module | slug | 能力 | tabs |
|---|---|---|---|
| system | `sakura-tools` | `manage_options` | settings |
| image | `sakura-tools-image` | `manage_options` | rules, thumb, backup, stats |
| storage | `sakura-tools-storage` | `manage_options` | connection, sync, deletes |
| files | `sakura-tools-files` | `review` | settings, users, queue, log, zibll |

页签参数是 `t`，**不是 `tab`**；`Registry::url( $module, $tab )` :213 在默认页签时省略 `t`。`Registry::current()` 对非本插件页面返回 `array( '', '' )`——任何「当前是不是我的页」的判断都必须走它。

**新增一个页签要动五处**（漏一处就是「菜单有但打不开」或「卡片挤成一列」）：`Registry::modules()` 的 tabs、`build_modules()` 里的 `register_renderer`、`Layout::PAGES` :59、`Layout::layout_for_page()` :219 的别名（仅当页面 id ≠ 布局 id）、`includes/views/{page}.php`。

**后台资源只有一个入口**：`Page_Assets::enqueue( $page )`（`src/Admin/Page_Assets.php:47`）。样式顺序 tokens → base → components → pages/{module} → pages/{page}，脚本顺序 core → api → ui → pages/{page}。版本串由 `group_version()` :175 生成（版本号.mtime.字节数），**改完文件不用手动改版本号浏览器也会重拉**。

**生命周期四件事**：
- 激活 `Activation::run()` :22 —— 默认 option → 建表 → 排程 7 处 → **最后才写版本号**（前面任一步失败时升级守卫下次请求还会重跑）
- 升级 `Upgrade_Guard` —— `add_option()` 抢锁，300 秒过期可抢占
- 停用 `Deactivation::run()` :22 —— 清 8 个具名 cron + `wp_unschedule_hook` 兜底 + 撤 6 个能力
- 卸载 `uninstall.php` + `Uninstaller::run()` :31 —— `uninstall.php` **必须先 `require_once` 主文件**，否则 autoloader 没注册会 fatal

**数据落在哪**：23 张自定义表、三个 Schema 类（`Sync\Schema` / `UserFiles\Schema` / `S3\Upload_Metrics` + `S3\Delete_Jobs`）。option 前缀 `sakura_imgopt_`，UserFiles 用 `sufm_`。表名一律走各 Schema 的 `table()`，不手拼。

**前台入口只有三处**：`fast-search.php`、`UserFiles\Module::register_frontend()`（挂 `after_setup_theme`，此时主题 helper 才存在）、`Space_Panel::register()`。

## 3. 十条铁律（摘要，原文与理由见 `00-索引.md`）

1. 新代码写 `src/`；不要删 `includes/` 里的类。
2. 模块构造函数只赋值，钩子走 `register_hooks()`。
3. `Admin\Menu` 永远最后装配。
4. 一张卡一个表单一个 option group 切片；`Card_Form::guard_options()`（`src/Admin/Card_Form.php:112`）不能省，否则「保存一张卡清空另一张」。
5. 新增 cron 必须同时写进 `Deactivation::run()`。
6. 新增表 / option 必须同时进 `Uninstaller` 的清理清单。
7. 版本号写在 `Activation::run()` 的最后。
8. 颜色只用令牌，强调色只有 `--g-accent`（`#9d3452`）；视觉改动只写 `assets/ui/`。
9. 业务代码不直接 `get_option()`，走 `Settings_Repository`；表名走 Schema 的 `table()`。
10. 后台视觉改动必须在 1440 与 390 两个宽度看过，并走完「保存一次 → 开一次弹窗 → 关一次弹窗」。

第 10 条的扩展版（重构守恒、控件宽度上限表、验收清单）在 `docs/开发规则/03-后台UI.md` §10–§12。

## 4. 验证命令

```bash
composer test          # PHPUnit，基线 470 tests / 2411 assertions / 6 skipped
composer phpstan       # level 5，必须 [OK] No errors
composer lint:strict   # PHPCS，必须 0 error（warning 基线 36 条，不得新增）
php -l <file>          # 改动的每个 PHP 文件
node --check <file>    # 改动的每个 JS 文件
git diff --check       # 行尾空白
```

- 测试引导是**手写的最小 WordPress shim**（`tests/bootstrap.php`），不是 WP 官方套件。要用新的 WP 函数先在 shim 里补桩，否则 `Call to undefined function`。
- PHPStan 开了 `reportUnmatchedIgnoredErrors`：代码移动会让豁免正则失效并报错，那是提醒你更新 `phpstan.neon` 的 path，不是误报。
- PHPCS 已刻意关掉全部「纯格式」类 sniff。**不要为了合规去重排对齐**，那类改动会被拒绝。
- CI 不跑 PHPCS，本地这一关不能省。
- `tests/bootstrap.php` 手写的 `WP_Error` 只实现了用到的方法。

## 5. 发版与部署

三处版本号必须一致，不一致 `release.yml` 直接失败：`sakura-tools.php` 头 `* Version:`、`define( 'SAKURA_TOOLS_VERSION', ... )`、`readme.txt` 的 `Stable tag:`。

```bash
php -l sakura-tools.php && composer test && composer phpstan && composer lint:strict
bin/build-plugin-zip.sh --version X.Y.Z --out dist    # dist/ 被 .gitignore 忽略
```

线上插件目录是一个 **git checkout**：

```bash
cd /www/wwwroot/www.nboke.com/wp-content/plugins/sakura-tools
git fetch origin && git merge --ff-only origin/main
chown -R www:www .        # 部署后属主会被 git 改成 root，必须修回
```

部署前先备份、部署后看 `debug.log` 有无新增 fatal / warning。完整三关与回滚见 `06-发布流程.md`。

## 6. 这个仓库里的特殊约束

- `dist/` 被 `.gitignore` 忽略，不进版本库。
- 打包脚本用 `git archive` 只取**已跟踪文件**，然后删掉 `docs/`、`tests/`、`bin/`、`stubs/`、`.github/` 与各配置文件。`docs/` 下的文档不会进发布包，可以放心写细节；**但根目录新增的 `.md` 会进包**，要加进 `bin/build-plugin-zip.sh` 的 `rm -f` 列表。
- `.agents/worktrees/` 下可能有多份工作副本（未被 git 跟踪），改之前确认自己在哪个分支，别改错副本。
- 本地实验站：`/root/sio-e2e/site`（`php -S 127.0.0.1:18080`，域名 demo.77il.cn），装的是同一插件的实体副本，不是符号链接，**同步时要整目录拷**。
- 生产站 `/www/wwwroot/www.nboke.com`，DB 前缀 `nb_`；主题是子比（zibll），部分后台能力与它耦合（`Zibll_Bridge`）。

## 7. 文档怎么维护

- **规则只有一个出处。** 新增规则写进对应那一篇；推翻旧规则时删掉旧的，不要两份并存。
- 改代码要顺手改文档。判据是：**如果这次改动会让一个新人按文档操作失败，就必须改。**
- 本文件是**索引与摘要**，不是规则正文。规则变了先改 `docs/开发规则/`，再回来修正这里对应的行；两处冲突时以 `docs/开发规则/` 为准。
