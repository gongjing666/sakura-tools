# sakura-tools 仓库工作指南

给在这个仓库里改代码的 AI 会话和开发者。**本文件只放「必须先知道」和「该去哪儿读」，规则正文一律在 `docs/开发规则/`，不要在这里复述。**

- 版本基线 **1.28.8**，分支 `main`，要求 PHP 8.2+ / WordPress 6.4+
- 开发期版本号写 `1.28.8-beta-N`（每次动代码 +1，纯 `.md` 不动），正式版本号只在开发者说「同步到 GitHub」时才加；**纯 `.md` 文档改动不碰版本号，直接 push `main`**——见 §5.1
- 类、方法、钩子、文件清单：`docs/sakura-tools-代码结构-1.28.3.md`（**生成物，不要手改**）。它由 `docs/tools/` 的五步管线产出，最后一次生成停在 1.28.3；1.28.4–1.28.8 新增的类没有进这份文档，改这些类前按 `src/` 目录直接看：`src/UserFiles/Services/Deletion.php`、`Deletion_Context.php`、`Deletion_Result.php`（P3 删除内核）、`src/UserFiles/Admin/Capability_Matrix.php`（P4 权限矩阵）、`src/UserFiles/Media/Cloud_State.php`（媒体库云端状态列）、`src/Admin/UI/Frontline.php`（V2 樱前线弧线）、`src/Admin/UI/Hero.php`（页头标语与印章的默认文案）、`src/Support/Profiler.php`（Query Monitor 计时桥）、`src/Zibll/`（授权缓存与只读请求守卫）、`src/UserFiles/Services/Takedown.php`（共享下架服务）、`src/UserFiles/Services/Sanction_Service.php`（处罚判决与执行点）
- 设计动机（为什么这么做）：`DECISIONS.md`

## 1. 按任务选一篇文档

| 我要做什么 | 读这个 |
|---|---|
| 第一次接触，想弄清插件由什么组成 | `docs/开发规则/01-架构与装配.md` |
| 加设置项、加页面、加 AJAX 动作、加 cron、加 S3 厂商 | `docs/开发规则/02-新增功能.md` |
| 改后台样式、加卡片、调布局、写页签视图 | `docs/开发规则/03-后台UI.md` |
| 写后台的 React 内容区（PHP 外壳 + React 内容区混合渲染；已迁岛的页签：系统设置、图像优化四页、S3 存储、同步中心、删除队列、链接改写、费用估算）：分工边界、组件结构、状态与保存、副作用、Actions、性能、DOM 契约、禁止清单 | `docs/开发规则/12-React代码规范.md`（**React 版本追最新 19.x，`package.json` 写 `^19.0.0`、实际 19.3.1；插件自打包，不读 WordPress 自带的 18.3.1**；esbuild 打包，产物 `assets/ui/dist/*.js` 必须提交） |
| 写 PHP 代码：语言特性能不能用、8.2–8.4 兼容、8.4 弃用、向下兼容与提交前自查 | `docs/开发规则/13-PHP代码规范.md`（**语法按 8.2 写、运行按 8.4 验**；8.3/8.4 的语法硬禁，新 API 要用守卫 + 回落） |
| 手动调外观：查某个 CSS/JS 管哪块、颜色令牌、怎么覆盖才不被下次更新冲掉 | `docs/开发规则/10-静态资源手册.md` |
| 加表、加 option、写迁移、改卸载逻辑 | `docs/开发规则/04-数据与存储.md` |
| 写测试、处理 PHPStan / PHPCS 报错 | `docs/开发规则/05-测试与质量.md` |
| 发版、打包、部署、回滚 | `docs/开发规则/06-发布流程.md` |
| 改 S3 存储的启用、切换、绑定锁定、删除，或「上传行为」两项（上传后删本地 / 不上传缩略图），改图片 URL 出口（`srcset` 不经过附件过滤器，见 R12），或改「同步中心 → 下载到媒体库」后附件状态的回写（见 R13）与该附件的用户文件空间记账（见 R14），或改缩略图 URL 改写方式的推断与换桶时它的开关（见 R15/R16），或改短效临时凭据的获取与续期（多吉云，见 R17），或改换桶后同步中心的封存与解锁（见 R18），或改「这两个是不是同一个桶」的判定与 URL / 上传跳过 / 死链计数共用的确认入口（见 R19） | `docs/开发规则/07-S3存储生命周期.md` |
| 换桶时把对象从旧桶搬到新桶 / 跨厂商迁移（插件不提供迁移通道，见 08 §11；本地有副本走插件原生上传，没有就先下载回本地） | `docs/开发规则/08-OpenList迁移通道.md` |
| 改删除行为：谁能删、删除顺序、容量怎么退、媒体库直删与违规下架（P1 已落地，P2 起待实现） | `docs/开发规则/09-删除与结算.md` |
| 改违规处置之后的事：下架完怎么处罚上传者（通知 / 禁上传 / 禁登录）、处罚表与期限、主题无关的执行点、子比小黑屋适配、邮件通道；或改下架动作本身（`Takedown` 服务，媒体库批量动作的唯一实现） | `docs/开发规则/11-违规处置与用户处罚.md`（**P1 已落地并在生产验收**；禁上传 + 处罚表 + 撤销 + 懒到期 + 共享 `Takedown` 服务；§10.4 记了「媒体管理页」加过又撤掉的原因；P2 申诉、P3 禁登录与邮件未做） |
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

**自身耗时看 Query Monitor 的「计时」面板。** `src/Support/Profiler.php` 把 boot / 后台页渲染 / S3 上传删除 / 四条后台队列 / 上传处理接到 QM 的 `qm/start|lap|stop` 上。两条规则：只在 QM 有监听者时才发钩子（没有 QM 时零开销），`lap`/`stop` 只对本类 `start` 过的名字发（否则面板里会多出 "Timer not started" 噪音行）。**QM 自己一次也不调 `qm/start`，空面板是它的设计不是故障**；细节见 `docs/开发规则/01-架构与装配.md` §2 与 `05-测试与质量.md` §6。

**模块契约**：构造函数只赋值、不做 IO、不挂钩子；钩子全部写在 `register_hooks( Hook_Loader $hooks )`。这样组合根能决定装不装，测试能在不启动 WordPress 的情况下检查钩子面。

**装配顺序**（`Plugin::build_modules()`，改顺序会让 renderer 找不到）：仓储 → admin 模块 → 媒体/缩略图 → 存储与同步 → `Admin\Menu`（**永远最后**，它要遍历已注册的 renderer）→ 队列与 UserFiles。

**后台路由四层**：
`Registry::modules()` :41 → `Admin\Menu` → `Registry::current()` :165 → 事先 `register_renderer()` 的回调。

| module | slug | 能力 | tabs |
|---|---|---|---|
| system | `sakura-tools` | `manage_options` | settings |
| image | `sakura-tools-image` | `manage_options` | rules, thumb, backup, stats |
| storage | `sakura-tools-storage` | `manage_options` | connection, sync, deletes, rewrite, cost |
| files | `sakura-tools-files` | `review` | settings, users, queue, log, zibll |
| zibll | `sakura-tools-zibll` | `manage_options` | performance |

页签参数是 `t`，**不是 `tab`**；`Registry::url( $module, $tab )` :213 在默认页签时省略 `t`。`Registry::current()` 对非本插件页面返回 `array( '', '' )`——任何「当前是不是我的页」的判断都必须走它。

**新增一个页签要动五处**（漏一处就是「菜单有但打不开」或「卡片挤成一列」）：`Registry::modules()` 的 tabs、`build_modules()` 里的 `register_renderer`、`Layout::PAGES` :59、`Layout::layout_for_page()` :219 的别名（仅当页面 id ≠ 布局 id）、`includes/views/{page}.php`。

**后台资源只有一个入口**：`Page_Assets::enqueue( $page )`（`src/Admin/Page_Assets.php:47`）。样式顺序 tokens → base → components → pages/{module} → pages/{page}，脚本顺序 core → api → ui → pages/{page}。版本串由 `group_version()` :175 生成（版本号.mtime.字节数），**改完文件不用手动改版本号浏览器也会重拉**。

**生命周期四件事**：
- 激活 `Activation::run()` :22 —— 默认 option → 建表 → 排程 7 处 → **最后才写版本号**（前面任一步失败时升级守卫下次请求还会重跑）
- 升级 `Upgrade_Guard` —— `add_option()` 抢锁，300 秒过期可抢占
- 停用 `Deactivation::run()` :22 —— 清 8 个具名 cron + `wp_unschedule_hook` 兜底 + 撤 6 个能力
- 卸载 `uninstall.php` + `Uninstaller::run()` :31 —— `uninstall.php` **必须先 `require_once` 主文件**，否则 autoloader 没注册会 fatal

**数据落在哪**：24 张自定义表、`Sync\Schema` / `UserFiles\Schema` / `S3\Upload_Metrics` / `S3\Delete_Jobs` / `S3\Url_Rewrite_Repository` 等 Schema 类。option 前缀 `sakura_imgopt_`，UserFiles 用 `sufm_`。表名一律走各 Schema 的 `table()`，不手拼。

**前台入口只有三处**：`fast-search.php`、`UserFiles\Module::register_frontend()`（挂 `after_setup_theme`，此时主题 helper 才存在）、`Space_Panel::register()`。

## 3. 十条铁律（摘要，原文与理由见 `00-索引.md`）

1. 新代码写 `src/`；不要删 `includes/` 里的类。
2. 模块构造函数只赋值，钩子走 `register_hooks()`。
3. `Admin\Menu` 永远最后装配。
4. 一张卡一个表单一个 option group 切片；`Card_Form::guard_options()`（`src/Admin/Card_Form.php:112`）不能省，否则「保存一张卡清空另一张」。
5. 新增 cron 必须同时写进 `Deactivation::run()`。
6. 新增表 / option 必须同时进 `Uninstaller` 的清理清单。
7. 版本号写在 `Activation::run()` 的最后。
8. 颜色只用令牌，强调色只有 `--g-accent`（`#c7335b`，V2 令牌值）；视觉改动只写 `assets/ui/`。
9. 业务代码不直接 `get_option()`，走 `Settings_Repository`；表名走 Schema 的 `table()`。
10. 后台视觉改动必须在 1440 与 390 两个宽度看过，并走完「保存一次 → 开一次弹窗 → 关一次弹窗」。

第 10 条的扩展版（重构守恒、控件宽度上限表、验收清单）在 `docs/开发规则/03-后台UI.md` §10–§12。

## 4. 验证命令

```bash
composer test          # PHPUnit，基线 915 tests / 3875 assertions / 6 skipped
composer phpstan       # level 5，必须 [OK] No errors
composer lint:strict   # PHPCS，必须 0 error / 0 warning（新增任何一条都当 error）
composer lint:compat   # PHPCompatibility，8.2 语法下限 / 8.4 生产运行时，必须 0 error / 0 warning
php -l <file>          # 改动的每个 PHP 文件
node --check <file>    # 改动的每个 JS 文件
git diff --check       # 行尾空白
```

- 测试引导是**手写的最小 WordPress shim**（`tests/bootstrap.php`），不是 WP 官方套件。要用新的 WP 函数先在 shim 里补桩，否则 `Call to undefined function`。
- PHPStan 开了 `reportUnmatchedIgnoredErrors`：代码移动会让豁免正则失效并报错，那是提醒你更新 `phpstan.neon` 的 path，不是误报。
- `composer lint:compat` 走 `phpcs-compat.xml.dist`（PHPCompatibility 10.0.0-alpha2，`testVersion 8.2-8.4`）守 PHP 版本边界：8.2 是最低语法、8.4 是生产运行时，细节见 `docs/开发规则/13-PHP代码规范.md` §6.3。
- PHPCS 已刻意关掉全部「纯格式」类 sniff。**不要为了合规去重排对齐**，那类改动会被拒绝。
- CI 不跑 PHPCS，本地这一关不能省。
- `tests/bootstrap.php` 手写的 `WP_Error` 只实现了用到的方法。

## 5. 发版与部署

三处版本号必须一致，不一致 `release.yml` 直接失败：`sakura-tools.php` 头 `* Version:`、`define( 'SAKURA_TOOLS_VERSION', ... )`、`readme.txt` 的 `Stable tag:`。**开发期这三处写的是 beta 号，发布时才一起换成正式号。**

### 5.1 开发期：`基准-beta-N`

没到发布点的改动**不写正式版本号**，在最新正式版本后面加 `-beta-N`：

| 阶段 | 版本号 | 什么时候改 |
|---|---|---|
| 基准（已发布） | `1.28.8` | — |
| 第 1 次代码改动 | `1.28.8-beta-1` | 动第一行 `.php` / `.js` / `.css` 前 |
| 第 2 次代码改动 | `1.28.8-beta-2` | 下一次动代码前 +1 |
| 开发者说「同步到 GitHub」 | `1.28.8` | 发版时收敛成正式号 |

- **每次动代码就 +1**：只要改动涉及 `.php` / `.js` / `.css`，动第一行代码前就把三处版本号 +1；只有整轮只改 `.md` 时才完全不动版本号。
- **三处一起改**：头部 `* Version:`、`SAKURA_TOOLS_VERSION`、`Stable tag:` 都写 `1.28.8-beta-N`。`release.yml` 与打包脚本的正则 `^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$` 接受这个形式。
- **正式版本号只由开发者拍板**：他说「同步到 GitHub」才把 beta 收敛成正式号，默认在基准上 PATCH +1（`1.28.8` → `1.28.9`）；这批改动若含新功能或破坏兼容，按 `06-发布流程.md` §1 的语义走 MINOR/MAJOR，具体号以他确认为准。
- **beta 号不要 push 到 `main`**：`release.yml` 在 push `main` 时触发，会给 `1.28.8-beta-1` 打 `v1.28.8-beta-1` tag 并发 Release（不标 prerelease）；生产的插件目录就是 `main` 的 checkout，push 等于上线。beta 号留在本地，发布时才换成正式号推。
- **changelog 只在发布时写**：beta 期不动 `readme.txt` 的 changelog，发布时把整批改动合并成一条 `= 正式版本号 =`，免得 Release 正文抽到空的 beta 段落。
- **纯文档改动不碰版本号**：只改 `.md`（`AGENTS.md`、`docs/**`、`README.md`、`CONTRIBUTING.md`）时三处版本号原样不动，既不写 beta 号也不写正式号，changelog 也不动，这一轮不跑测试（没有代码变化）；提交后直接 push `main` 同步 GitHub——`release.yml` 的 `paths` 只认 `sakura-tools.php`、`readme.txt`、`bin/build-plugin-zip.sh` 与自身，纯文档提交不会打包发版。详见 `06-发布流程.md` §1。
- **beta 号在数据库看来是降级**：`Upgrade_Guard::needs_upgrade()`（`src/Upgrade_Guard.php:60`）用 `version_compare( $installed, $current, '<' )` 判断，`1.28.8-beta-1` 比 `1.28.8` 小——已经装过 1.28.8 的站点在 beta 期**不会**自动补建表 / option / cron。beta 里新增了这些，验证前先把 `sakura_imgopt_version` 删掉或改小。
- 本地可以出 beta 包做验证：`bin/build-plugin-zip.sh --version 1.28.8-beta-1 --out dist`。
- **不自行发版**：不加正式号、不 push `main`、不打 tag。

发布检查与打包：

```bash
php -l sakura-tools.php && composer test && composer phpstan && composer lint:strict && composer lint:compat
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
- 实验站：`https://demo.77il.cn`，宝塔面板本机站点，目录 `/www/wwwroot/demo.77il.cn`（PHP 8.4.28、MariaDB 12.3.2、WP 7.1.5，与生产同版本；DB `demo`、前缀 `wp_`，主题子比已授权，管理员 `yexu`，密码不写进仓库）。装的是同一插件的**实体副本**，不是符号链接，**同步时要整目录拷**，并且必须带上 `assets/ui/dist/`（React 打包产物，`system.js` / `image.js` / `storage.js` / `sync.js` / `deletes.js` / `rewrite.js` / `cost.js`）：漏了它，系统设置、图像优化、对象存储（含链接改写/费用估算）页的脚本 404，页面会永远停在「正在读取页面信息…」的骨架屏。用这条命令，排除项里写 `/dist`（锚定根目录），**不要写 `dist`**——那样会把 `assets/ui/dist` 一起排掉：

```bash
rsync -a --delete --exclude .git --exclude .agents --exclude .dsh-ops --exclude node_modules \
  --exclude .phpunit.cache --exclude /dist \
  /root/work/sakura-tools/ /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools/ \
  && chown -R www:www /www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools
```

站上已装 sakura-tools（`wp-content/plugins/sakura-tools`，整目录拷贝，当前 `1.28.8-beta-56`，25 张表已建、`wp sakura-sync` 可用）。旧的内置服务器实验站 `/root/sio-e2e`（`php -S 127.0.0.1:18080` + docker 的 MariaDB/MinIO）已于 2026-11-24 整体删除，备份在 `/root/backups/sio-e2e-20261124-1316/`（站点文件 tar 524M + 6 个库的 sql.gz），不再作为验收环境。
- **代码改动要测，纯文档改动不测。** 改 PHP / JS / CSS 必须跑完 §4 的静态三关（外加 `php -l` / `node --check` / `git diff --check`）；需要真实 WordPress 才看得出的验证（第 10 条的 1440/390、保存与弹窗回流、console）跑在**开发者指定的环境**，他没指定就不要自行部署到实验站或生产站，只把要看的点写进交付说明。**只改 `.md` 的整轮不测**，也不动版本号，直接 push `main`。详见 `05-测试与质量.md` §6。
- 生产站 `/www/wwwroot/www.nboke.com`，DB 前缀 `nb_`；主题是子比（zibll），部分后台能力与它耦合（`Zibll_Bridge`）。

## 7. 文档怎么维护

- **规则只有一个出处。** 新增规则写进对应那一篇；推翻旧规则时删掉旧的，不要两份并存。
- 改代码要顺手改文档。判据是：**如果这次改动会让一个新人按文档操作失败，就必须改。**
- 本文件是**索引与摘要**，不是规则正文。规则变了先改 `docs/开发规则/`，再回来修正这里对应的行；两处冲突时以 `docs/开发规则/` 为准。
