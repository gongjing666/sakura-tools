# 樱花工具箱（sakura-tools）代码结构分析报告

> **版本** 1.27.5 ｜ **代码基线** commit `0f29adf` ｜ **分析日期** 2026-11-14
> **分析对象** `/root/work/sakura-tools` 工作树（与生产 `/www/wwwroot/www.nboke.com/wp-content/plugins/sakura-tools` 同一版本，两边 `git describe` 都是 `v1.27.5`）；本轮只读扫描，未在生产执行任何写操作
> **测试环境** `/root/sio-e2e`（独立 WordPress + MariaDB 127.0.0.1:13316 + MinIO 127.0.0.1:19010），行为与生产一致
> **配套产物** 3D 图谱 `docs/graph-preview/index.html`（同源数据 `graph.json`）、生成器 `docs/tools/build-code-structure.mjs`、图谱说明 `docs/history/sakura-tools-图谱同步-1.25.9.md`（生成方法仍适用，数字属 1.25.9）、开发规则 `docs/开发规则/00-索引.md`

> **本版口径（2026-11-14 重生成）** 表格、图谱与统计数字统一刷新到 **1.27.5**；第 1 章后半的「上一轮更新」是按版本追加的**历史记录**（1.22.9 → 1.27.5），其中的行数、节点数与连线数只代表当时那一版，不要当作当前值。正文里的 `文件:行` 证据已在 1.27.5 工作树上重新核对；历史段落里的行号按当时版本保留，可能已偏移。

---

## 0. 阅读指南

### 0.1 文档结构

全文严格按「**结构 → 职责 → 关系 → 验证**」四段式组织，每一章只回答一个问题：

| 章 | 回答的问题 | 主要读者动作 |
|---|---|---|
| 1–2 | 代码在哪、分成哪些模块、历史分层是什么 | 建立全景，避免一上来陷进单文件 |
| 3 | 插件怎么启动、模块怎么装配、后台页面怎么路由 | 改功能前先确认挂载点 |
| 4–8 | 五个子系统各自的类、方法、钩子、数据落点、流程 | 定位改动点 |
| 9 | 数据写到了哪里（表/option/postmeta/transient/目录） | 排查"数据存哪了""卸载删不删" |
| 10 | 运行时验证记录（命令 + 结果） | 复核结论 |
| 11 | 模块与类之间的依赖强度、图谱口径 | 评估改动影响面 |
| 12 | 待确认项（本轮清零）与已确认缺陷 | 接手时先看缺陷清单 |
| 13 | 3D 图谱的节点/边定义与本次更新 | 看图前先读 |

### 0.2 证据标注规范

本文所有结论都带证据标签，没有标签的句子只出现在"阅读指南"这类元信息里：

| 标签 | 含义 | 例子 |
|---|---|---|
| `文件:行` | 静态证据：结论直接来自该位置的代码 | `src/Plugin.php:83-86` |
| **验证** | 运行证据：在测试站实际执行过，第 10 章有命令与输出 | 验证 §10.3 路由 11/11 |
| **未验证** | 只有静态阅读，未在运行时复现 | 生产环境写路径（生产保持只读） |
| **待确认** | 代码里存在歧义或超出本次范围，不下结论 | §12（含已修复缺陷的复核与接受边界） |
| **口径** | 统计方式说明，避免读者误读数字 | 入度/出度的计算方式 |

### 0.3 数字口径（先对齐，避免误读）

| 指标 | 数值 | 口径 |
|---|---:|---|
| 生产 PHP 文件 | **191** | 不含 `tests/`(79 文件 / 11,205 行) `stubs/`(2) `vendor/` `assets/`(JS/CSS) |
| 生产 PHP 行数 | **65,780** | 同上，含空行与注释。生成器按「换行符数 + 1」计数，同一批文件给出 65,971，差值约等于文件数 |
| 类 / 接口 | **176** | 含 7 个接口；另有 1 个生产全局函数（`fast-search.php:27`）未计入图谱节点。扫描器统计的 function 节点含 tests/stubs，不是生产全局函数数 |
| 钩子注册 | **207** | 仅生产代码中的具名 `add_action`/`add_filter`（150 + 57），不含 `do_action` 触发点、`add_submenu_page` 与 `Hook_Loader` 的内部转发 |
| 后台资源 | **38 文件 / 33,897 行** | `assets/` 下的 CSS/JS，另有 1 个 SVG；其中 `assets/ui/` 27 文件 / 14,407 行 |
| 自定义数据表 | **23** | 15 张 `{prefix}sufm_*` + 6 张 `{prefix}sio_sync_*` + 2 张 `{prefix}sio_s3_*`（`sio_s3_deletes`、`sio_s3_upload_events`） |
| 后台页面 | **13** | 4 个顶级模块下的 13 个 tab（system 1 + image 4 + storage 3 + files 5） |

> 说明：`includes/views/` 的 12 个模板文件不含类与函数声明，因此不出现在类索引中，但它们承担全部旧版页面渲染（见 §2.3）。

---

## 1. 结论摘要

**先看这十条，能省掉 80% 的翻文件时间。**

1. **双轨架构，且短期内不会合并。** `includes/` 是 1.3–1.9 的旧层（14 个类文件 / 6,185 行 / 14 类，另有 12 个 `views/` 模板 5,579 行），`src/` 是 1.10 之后的新层（162 类，含 7 个接口）。两层的通信方式是**构造函数注入 + 类直接调用**，没有中间容器；唯一共享的抽象是 `src/Contracts/` 的 5 个接口。旧层没有被淘汰，反而承载了图片优化的核心链路（`includes/class-image-processor.php`）。
2. **全插件的装配只有一个入口。** `src/Plugin.php:71-206` 的 `boot()`（:71-115）+ `build_modules()`（:124-206） 能看到**所有**依赖关系：先建 5 个仓储，再按固定顺序 new 出 20 多个模块/服务，最后 `Admin\Menu` 必须最后注册（`src/Plugin.php:195`）。读代码从这里开始，比按目录读快得多。
3. **模块契约极窄。** `src/Contracts/Module.php:23-31` 只有一个 `register_hooks(Hook_Loader): void`，且明确禁止在构造函数里挂 WP 钩子。所有钩子集中到 `src/Support/Hook_Loader.php`，先入队、`register()` 时一次性交付，**注册后新加的钩子立即生效**（`register_frontend` 依赖这个行为）。
4. **后台信息架构是静态声明的。** `src/Admin/Registry.php:41-105` 用一张常量数组声明「模块 → tab → 权限门 → renderer key」；`Menu` 只做建屏与分发，`Shell` 只画外壳。**加一个 tab = 改 Registry + 注册 renderer 两处**，不需要动菜单代码。
5. **功能面是 5 个子系统 + 3 条横切。** 子系统：图片优化管线（§4）、缩略图 URL 重写（§5）、对象存储 S3（§6）、同步索引 Sync（§7）、用户文件空间 UserFiles（§8）。横切：数据落点（§9）、钩子索引（§9.2）、REST/CLI/Cron（§9.3）。
6. **代码量集中在两处。** UserFiles（46 文件 / 22,262 行）与 S3（37 文件 / 12,211 行）合计 34,473 行，占生产代码的 52.4%；两者也是自定义表、加密凭据与补偿队列最集中的部分。
7. **运行时可访问性已验证，生产写路径仍保持只读。** 11 条后台路由全部 200 且无 fatal（h1 是模块名，与 tab 子页名不完全相同）、45 条 `/sufm/v1` REST 路由（51 个端点）全部注册、7 个插件 cron 事件已排程、12 项函数级探针结果符合预期（§10）。**未验证**的是生产环境的写路径（配额扣减、删除审核、卸载清理）——生产保持只读。测试站上补做了六批：5 张并发上传（§10.6）跑通「上传 → 压缩 → S3 PUT → 索引落库」，4×200 条并发指标写入（§10.11）验证事件表零丢失，1.24.0 的对账失败退避与历史 grant 回填（§10.14）验证了退避序列、租约与字段白名单，1.24.1 的快捷多选（§10.15）在用户中心与编辑页分别通过 17/17、24/24 项浏览器断言，1.24.2 的运维观测（§10.16）验证 health、守卫分页/计数与 Prometheus gauge，以及 1.24.2 的三条空白补测（§10.17：Quota 提交/回滚、删除审核→Executor、卸载两轨、扫描游标三种损坏、编辑器覆盖与插入顺序）。**补测发现的一项缺陷已在 1.24.3 修复**：显式删除数据的卸载路径曾漏删 `file_tokens` 表；现在改为遍历 `Schema::tables()`，并在隔离环境复跑 15 张表→0，见 §12.7。
8. **过期的文档已全部归档，不要当作事实源。** `DEVELOPMENT.md`（第 1 节的"当前文件结构"停在 1.3.3 的 6 个文件）与两份 1.25.9 的分析产物已移入 `docs/history/`，开头都有历史声明；`/root/WordPress/docs/sakura-image-optimizer-代码分析报告.md`（本机历史报告，未随仓库分发）停在 1.9.0 之前的命名。当前有效的规则文档是 `docs/开发规则/`，同步的代码结构产物是本文与 3D 图谱；`DECISIONS.md` 仍然有效（记录的是"为什么"，不是"现在有什么"）。
9. **文档与图谱同源，不会再脱节。** 两者都由同一份扫描事实表（来自 `dsh-codegraph` 的 `lib/scanner.php`）经 `docs/tools/build-code-structure.mjs` 生成。改代码后重跑一次命令即可同时刷新表格与图谱（§13.6）。
10. **上传指标已从单 option 改为事件表。** 旧实现每次 `record_batch()` 读改写同一个 option，4 进程各写 200 条只留下 202 条（丢 75%）；现在每条结果独立 INSERT 到 `{prefix}sio_s3_upload_events`（`src/S3/Upload_Metrics.php:79`、`:149`），`snapshot()` 用 SQL 聚合（`:204`），成功与失败分别由 `sakura_imgopt_s3_uploaded` / `sakura_imgopt_s3_upload_failed` 采集（`src/S3/Upload_Observer.php:30-31`）。返回结构不变，旧 option 只作为冻结基线读取。详见 §6.9 与 §10.11。

**更早一轮更新（2026-11-08）**：§12 原有 3 项已全部核实并处理：① `fast-search.php` **无缺陷**，生产环境没有额外封禁，入口按 HMAC + 管理员能力鉴权；② `Space_Store::grant_from_order()` **缺陷已修复**，订单按支付渠道写 `source/channel` 与订单字段，并默认只开放余额支付、补退款补偿与每日对账（§8.9、§10.12）；③ 上传失败不可见**已修复**，旧 `Upload_Observer` 只记录成功样本，该轮改为追加事件表 + 失败钩子，并发压测 800/800 条落库（§6.9、§10.11）。

**更早一轮更新（2026-11-09，1.22.9）**：版本号、常量与 readme 统一到 **1.22.9**；消除全量 PHPCS 的 5 个既有错误（4 条译者注释、1 条异常消息的窄例外），复检 **177 个 PHP 文件 0 error**；生产代码结构与图谱拓扑未变（178 文件 / 163 类 / 180 节点 / 814 连线 / 191 钩子），仅 `src/UserFiles/Editor/Assets.php` 增加 4 行使生产总行数变为 60,464。

**上一轮更新（2026-11-09，1.23.0）**：编辑页文件面板新增拖拽跟手标签、拖到正文按落点插入、右键菜单「插入」。改动只落在编辑页 fork（`assets/editor-picker*.js/css`、`includes/views/editor-picker-app.php`、`src/UserFiles/Editor/Assets.php` 文案与图标）；用户中心 `assets/userfiles.*` 与 `Frontend/Tab.php` 的界面未改，只新增一个 `dragInsert` 文案键。没有新增类、路由、钩子或数据表，图谱拓扑仍 180 节点 / 814 连线 / 191 钩子；生产总行数 60,468，UserFiles 21,174。详见 §8.10、§10.13。

**上一轮更新（2026-11-09，1.24.0）**：空间扩容支付入口改为后台勾选（默认仅 `balance`，积分有独立开关，`sufm_space_allowed_payment_methods` 只能收窄）；每日对账改为租约 + 900/1800/3600/7200/14400/21600 秒指数退避，失败不再空等 24 小时；新增 `wp sufm grants` 历史 `order:` 授权审计/回填，默认 dry-run，`--apply` 才写。生产仍 178 文件 / 163 类 / 191 钩子 / 23 张表，但总行数 61,215（UserFiles 21,833、`includes/views` 4,821）；图谱 180 节点 / **815** 连线，新增 `CLI → Space_Store` 一条 `ref`，`Space_Store → Space_Settings` 权重 22、`Space_Store → Space_Rules` 权重 48。`refund_failed` 仍坚持人工处理，该轮没有自动复活。详见 §8.9、§10.14。

**上一轮更新（2026-11-09，1.24.1 界面）**：用户中心与编辑页两套文件面板都支持快捷多选：Shift 连选、Ctrl/⌘ 点选、Ctrl/⌘A 全选、Esc 清空，网格视图补「全选/取消全选」按钮；Shift 范围只加不减、跳过锁定文件，编辑页插入条会随选区重建数量与顺序。改动只在 9 个前端、视图与文案文件（+536/−27），没有新增类、方法、钩子、REST 路由或数据表；当时生产 178 文件 / 163 类 / 180 节点 / 815 连线 / 191 钩子，总行数 61,227（UserFiles 21,838、`includes/views` 4,828）。图谱只更新 `meta.version` 与页面副标题，拓扑和边权不变。列表行复选框另加 14px 方框护栏，圆形样式只保留给网格卡。详见 §8.11、§10.15。

**上一轮更新（2026-11-09，1.24.2 S3/Sync 运维观测）**：新增只读 `wp sakura-s3 health`（16 项指标）、`remote-operations count` 与 `--limit/--offset` 分页、Prometheus 四个只读 gauge（`delete_dead`/`delete_stale`/`remote_uncertain`/`sync_jobs_dead`）；上传事件表**不自动裁剪**，顺序回滚不会重复计数（测试站 30+5+3=38），守卫 `uncertain` 行仍需人工 `release`。该轮改 9 个 PHP/测试/文档文件，没有新增类或钩子；生产仍 178 文件 / 163 类 / 180 节点 / **822** 连线（609 ref + 50 modref + 163 contains）/ 191 钩子，总行数 61,424（`includes` 6,097、S3 10,902）。图谱新增 7 条 `ref`，`S3_CLI` 与 `Remote_Operation_Guard` 的 `val` 变大。详见 §6.9.5、§7.7.1、§10.16、§13。

**上一轮补充（2026-11-09，1.24.2 运行验证回写）**：把 §12 原先三条「只有静态阅读」的空白补成运行证据：① `Quota::commit` 在测试站完成「预留 → 部分提交 → 重复提交拒绝 → 超配额回滚 → 释放」全序列；② `Requests::approve` → `Executor::preview/confirm/tick` 跑通从审核到附件删除、配额回冲、request/batch/job 全完成；③ `Uninstaller::run()` 在测试站分别跑「显式删除数据」与「保留数据」两轨；④ `Scan_Service` 的损坏 JSON、顶层目录被删、嵌套目录被删三种游标都自动重置重扫；⑤ 编辑页补测 TinyMCE、纯 `textarea` 与块编辑器，并确认插入顺序是「图片按勾选顺序在前、其余随后」，不是屏幕顺序。**此轮未改插件代码**，版本仍 1.24.2；补测同时发现卸载路径漏删 `{prefix}sufm_file_tokens`，当时登记为 §12.7，后由 1.24.3 修复。详见 §8.10.7、§10.17、§12.6–§12.7。

**上一轮更新（2026-11-09，1.24.3 卸载修复）**：把 §12.7 的已确认缺陷修掉。`src/Uninstaller.php:150-154` 的 `drop_sufm_tables()` 不再维护第二份硬编码清单，改为直接遍历 `Nboke\UserFiles\Schema::tables()`（15 张，含 `file_tokens`）；`tests/UninstallerTest.php:55-95` 新增回归测试，用 fake `$wpdb` 断言 DROP 语句数量等于 `Schema::tables()` 且显式包含 `t_sufm_file_tokens`。全量验证 427 tests / 1,296 assertions / 5 skipped、PHPStan 无错误、PHPCS 通过；隔离站 `/tmp/sio-uninstall-site` + 库 `sio_uninstall_probe` 复跑「显式删除数据」轨道，15 张 `t_sufm_*`（`t_sufm_file_tokens` 3,117 行）→ 0 张，`t_sio_*` 也 0，随后从 dump 恢复样本库。生产仍未执行卸载。该轮只加 1 行 `use`、替换删表循环并补测试，没有新增类、方法、钩子、REST 路由或数据表；生产总行数 61,425，图谱由 822 增至 **823** 连线（610 ref + 50 modref + 163 contains），新增 `Uninstaller → Schema` 一条 `ref`。详见 §10.17-C、§12.7、§13。

**上一轮更新（2026-11-10，1.24.4 覆盖升级自愈与计数修正）**：新增 `src/Upgrade_Guard.php`，在 `Plugin::boot()` 的旧数据迁移之后调用 `Activation::run()`，补上覆盖升级不会触发 activation hook 的缺口；版本相同或降级时不动，升级失败只记录 transient 并触发 `sakura_imgopt_upgrade_failed`，不阻塞前台。新增 `tests/UpgradeGuardTest.php`（10 tests / 19 assertions），覆盖同版本跳过、旧版本执行、活锁/过期锁与异常清锁。全量验证 **437 tests / 1,315 assertions / 5 skipped**、PHPStan 无错误、PHPCS 0 error（36 条既有 warning，未新增错误或告警）。生产统计修正为 **179 文件 / 61,562 行 / 164 类 / 199 具名钩子**；tests 为 76 文件 / 10,108 行。图谱 **181 节点 / 826 连线**（612 ref + 50 modref + 164 contains），新增 `Plugin → Upgrade_Guard`、`Plugin → Activation` 两条 `ref` 与 `src → Upgrade_Guard` 一条 `contains`。版本号在 1.24.4 发布提交中提升，代码、文档与图谱随同一提交发布；发布本身不再改变图谱拓扑。详见 §3.2、§9.2、§10.18、§13。

**上一轮更新（2026-11-12，1.25.0 后台 UI 重构）**：后台 13 个页签从「每页一套视图 + 一套 CSS/JS」收敛为**单一 UI 栈**。新增 `src/Admin/UI.php` 渲染门面与 `src/Admin/UI/` 下 4 个构件（`Page` / `Card` / `Field` / `Feedback`），加上 `src/Admin/Appearance.php`（外观令牌与 `inline_style()`）、`src/Admin/Card_Form.php`（按卡片保存表单）、`src/Admin/Page_Assets.php`（按页加载 tokens/base/components/layout + 分页 CSS 与脚本）、`src/Admin/Route.php`（`page_id()` → `image-rules` 这类稳定页 id）；新增 `assets/ui/`（29 文件 / 14,932 行，tokens/base/components/layout 四层公共样式 + 6 个分页 CSS + `assets/ui/js/` 的 core/api/ui/layout 与 12 个分页脚本），并删除旧的 `assets/admin*.css/js`、`assets/userfiles-admin.*`、`assets/userfiles-space-admin.*` 共 12 个文件。基础设置页被新的**系统设置**页取代：`src/Admin/System_Settings_Module.php` + `includes/views/system-settings.php` 提供运行时/引擎/自检/扩展/版式五卡，slug 直接接管顶层 `sakura-tools`；旧的 `Admin\Basic_Settings_Module` 与 `includes/views/basic-settings.php` 同时移除。另新增 `src/S3/Config_Notice.php`（连接页配置提示），并在核对行加上服务端媒体预览弹窗（图片下载、视频自绘控件、压缩包仅下载）与删除队列行内文件浏览。

**上一轮更新（2026-11-12，1.25.1 后台命令面板卸载）**：WordPress 6.9 起命令面板是**全站后台功能**——`wp_enqueue_command_palette_assets()` 挂在 `admin_enqueue_scripts` 上（`wp-includes/default-filters.php:619`），无条件把 `wp-commands` / `wp-core-commands` 连同 React、区块组件、数据层共十余个依赖塞进每一个后台页，插件页面用不到却要付约 1 MB 传输与 2–3 s 解析执行，这正是「切换后台页明显卡顿」的根因（实测见 §10.19）。1.25.1 在 `sakura-tools.php:121-137` 新增一个 `admin_enqueue_scripts` 优先级 100 的闭包：先 `sanitize_key( wp_unslash( $_GET['page'] ) )`，仅当 slug 是隐藏的批量进度页 `sakura-imgopt-progress`（不走 `Registry`），或 `Admin\Route::current_page()` 非空（插件自己的 11 个页签）时，才 `wp_dequeue_script( 'wp-core-commands' )` / `wp_dequeue_script( 'wp-commands' )` / `wp_dequeue_style( 'wp-commands' )`。其余后台页保持原样——`wp-edit-post` 把 `wp-commands` 声明为依赖，整站卸载会让区块编辑器失效；工具栏的 ⌘K 入口由核心以 `wp_script_is( 'wp-core-commands' )` 判定，脚本卸载后入口自动不输出，不需要另改菜单。测试站真实 HTTP + 真实点击实测（无 CPU 节流）：插件页传输 **1,061 KB → 76–85 KB**、`/wp-includes/js/dist/` 脚本 **45 → 3**、DCL **2.9–3.1 s → 0.6–0.8 s**、点标签切页 **3.0–3.6 s → 0.7–1.4 s**；`dashboard` / `plugins.php` / 编辑页的 5 处 `wp-commands` 引用与 ⌘K 入口原样保留，插件自身的 `sakura-admin-*` 资源与 `window.sakuraPage` 全部正常。本轮只加 1 个钩子、29 行代码，类与图谱拓扑不变。

**上一轮更新（2026-11-12，1.25.2 卡片重排修复）**：修「文件核对」卡片点筛选后样式错乱。原因有两层，缺一不可。**① CSS**：`assets/ui/pages/storage.css:9-19` 的 `.sakura-admin[data-sakura-page^="storage-"] .card__body` 是 grid 容器却没写 `align-content`，grid 行默认 `normal`≈`stretch`，卡片比内容高时剩余高度被平摊到工具栏、表格与分页之间，表现为「上下大片空白」。**② JS**：`assets/ui/js/layout.js:422-445` 的 `freeLayout()` 在 ≥1101px 把卡片放进绝对定位画布并把量到的自然高度写成 `card.style.minHeight`（`MIN_H=120`，见 `layout.js:22`；断点取 `matchMedia('(min-width: 1101px)')`，见 `layout.js:24`），只在首布局、`resize`、断点切换、拖拽落位与 `layout-refresh` 事件时重算；`assets/ui/js/pages/storage-sync.js` 的 `applyRows()` 换行后从不通知画布，卡片高度停在旧值——内容变矮就摊空白，内容变高则直接压住下一张卡（实测重叠 459px）。
修法：`storage.css` 给该 `.card__body` 加 `align-content: start`（行只占自己需要的高度）；`storage-sync.js:141-149` 新增模块级 `notifyLayout()`（内部 `admin.events.emit( 'layout-refresh' )`），并在三处调用：`applyRows()` 末尾（`:1289-1295`，覆盖筛选、搜索、每页条数与翻页）、日志级别 chips 的 `render()` 之后（`:1481-1485`）、「显示更多」的 `render()` 之后（`:1492`）。写法与既有的 `assets/ui/js/pages/files-log.js:301-307` 一致。
实测（测试站真实浏览器点击，1403px 与 1600px 视口）：工具栏高度恒 38px；「待上传 / 失败 / 仅云端 / 内容不一致」卡片 409px、「全部 / 已同步」886px，与下方「处理日志」卡间距恒 18px；900px（<1101px，单列、`min-height` 被清空）下卡片随内容自然伸缩。日志卡级别筛选时分组高度 2853 ↔ 2881 跟随变化，页脚不再被压住。同一页的连接 / 行为 / 测试三张卡高度与溢出量修复前后完全一致，无回归。本轮不改 PHP、不新增钩子与类，节点 191、连线 861、模块 17、钩子 204 全部不变；后台资源因两处改动 +28 行。

**上一轮更新（2026-11-13，1.25.3 对象存储布局画布合并）**：把「对象存储」三屏（连接配置 / 同步中心 / 删除队列）的卡片统一到一套可自由拖动的画布。此前只有同步中心是正确的：`includes/views/s3-sync-center.php:335` 的 `<div class="card-grid" data-sakura-layout="sync" data-sakura-group="main">` 把 7 张卡全包住。连接配置页只有三张卡能拖——`src/S3/S3_Storage_Page.php` 原 `:189` 的 `<div class="card-grid" id="sakura-s3-save-form" data-sakura-layout="s3" data-sakura-group="main">` 只包了 behaviour / connection / test，`profiles`（`:115` 之前渲染）与 `Sync_Center_Panel::render()` / `Monitoring_Panel::render()`（`:499` 闭合之后）都落在组外，所以凭据档案、上传统计、监控三张卡没有拖动把手也拖不动。删除队列页则是 `src/S3/Delete_Queue_Panel.php` 完全没包布局组，且 `src/Admin/Page_Assets.php:31-45` 的 `LAYOUT_PAGES` 白名单里根本没有 `storage-deletes`，连 `layout.js` / `layout.css` 都不会加载。
修法共四处：① `S3_Storage_Page.php` 把 `card-grid` 开标签上移到 `profiles` 之前、闭合 `</div>` 移到 `Monitoring_Panel::render()` 之后，六张卡同进一个 `data-sakura-layout="s3"` 组；② `Delete_Queue_Panel.php` 给队列卡套上 `data-sakura-layout="deletes"` 组；③ `Page_Assets.php:31-45` 的 `LAYOUT_PAGES` 加 `'storage-deletes' => 'deletes'`；④ `src/Admin/Layout.php:118-135` 的 `DEFAULT_LAYOUT['s3']` 补 `profiles` / `upload-metrics` / `monitoring` 三条 span 12 记录、`:153-165` 新增 `deletes` 页定义，`assets/ui/js/layout.js:34` 的 `DEFAULT_SPANS` 同步。
同时清掉一处历史残留：`DEFAULT_LAYOUT['s3']` 原 order 含 `migrate`，但该卡片自 v1.22.8 起在页面里**已不存在**（全仓库仅 `src/Admin/Layout.php` 两处引用），导致「恢复默认」与首次访问得到两种排布；现已删除，order 改为与 `S3_Storage_Page` 标记顺序一致的 `( profiles, behaviour, connection, test, upload-metrics, monitoring )`，`behaviour` 取 col 1、`connection` 取 col 7。另把 `assets/ui/js/layout.js:201-226` 的新卡插入规则从「找不到前驱就 push 末尾」改为就近插入（`var anchor = 0;` 起手，找到前驱则 `anchor = pos + 1`，最后 `order.splice( anchor, 0, id )`），声明在首位的卡片不再沉底；`src/S3/Config_Notice.php:111-114` 的提示锚点从 `#sakura-s3-save-form`（原来挂在只包三张卡的网格上，画布合并后会滚到任意一张卡）改为连接卡自身的 `#sakura-s3-connection`。
实测（测试站真实浏览器，1403px）：连接页六张卡全部带 `:scope > .card__head.sakura-layout-drag` 与 3 个 grip，`[data-sakura-layout="s3"]` 组 class 为 `card-grid sakura-layout-grid is-free is-laid`；拖动 `connection` 到左列后 `data-sakura-col` 由 7 变 2、`left` 96.08px，刷新后保持（用户元数据 `_sakura_imgopt_layout`）；`test` 卡侧边 grip 由 span 6 拉到 span 11（1041px）正常；点「恢复默认」回到 profiles / behaviour / connection / test / upload-metrics / monitoring，与首次访问一致。删除队列页 `[data-sakura-layout="deletes"]` 存在、队列卡有拖动把手与 3 个 grip、`layout.js` 已加载。窄屏 900px 时组退化为无 `is-free` 的单列堆叠，回到 1403px 恢复。控制台 0 error 0 warning。
规模（生产代码）：**189 文件 / 65,165 行 / 174 类与接口 / 204 具名钩子 / 23 张自定义表**；`src/Admin` 16 文件 3,292 行，后台资源 41 文件 / 34,447 行（其中 `assets/ui/` 29 文件 / 14,957 行），`includes/views` 5,391 行。tests 76 文件 / 10,203 行；全量验证 **441 tests / 1,332 assertions / 5 skipped**、PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 全部为 1.24.4 既有）。本轮只改布局元数据与后端渲染结构，**不新增类、不改钩子**：图谱仍 **191 节点 / 861 连线**（637 ref + 50 modref + 174 contains），模块 17 个、`modref` 50 条。详见 §9.2、§10.21、§13。

**上一轮更新（2026-11-13，1.25.4 云端体积分布空态提示）**：修「云端体积分布」在没核对过时静默显示全 0、看起来像插件坏了的问题。这张卡的每个数字都来自最近一次**完整核对**写进扫描行（`{prefix}sio_sync_scans`）的 `cloud_image` / `cloud_video` / `cloud_archive` 计数与 `remote_bytes`；单张上传只写索引行与 counts，**不会修改扫描行**（`src/Sync/Scan_Service.php:745-750` 的 `++$types[ $kind ]` 只在核对过程中累加，`:710-725` 定义 `image/video/archive` 三类），所以没有扫描记录时环图只能是 0%，而 0% 与「桶里真的是空的」在界面上无法区分。
修法：`includes/views/s3-sync-center.php:137-181` 新增一个状态机（三种空态提示块 + 环图下方的一行说明），按优先级判 `compat`（兼容模式，不读写索引）→ `scanning`（`$scan_running` 且 `total_remote` 为 0）→ `ready`（`$scan_running` 但已有上次结果）→ `interrupted`（`$scan_resumable`，即上次核对以 `failed` + `phase=remote` 收尾）→ `unscanned`（没有扫描行）→ `empty`（有扫描行但 `total_remote` 为 0）→ `ready`。`scanning` / `interrupted` / `unscanned` 三态不再画 0% 灰环，改为 `:565-579` 的 `.storage-mix__empty` 提示块（标题 + 说明 + 可选按钮）：`unscanned` 给「开始完整核对」（`data-sakura-action="scan"`，复用页面既有的扫描动作与 nonce）与「查看任务中心」两个入口，`interrupted` 给「继续核对」（`data-sakura-action="resume"`，nonce 来自 `wp_create_nonce( 'sakura_s3_sync_resume' )`，与 `src/S3/Sync_Center_Module.php:113` 的 `wp_ajax_sakura_s3_sync_resume` 对应）与「查看任务中心」，`scanning` 只给文案不给按钮。`empty` 与 `ready` 仍画环图（`empty` 是真实 0%，需要有环）但多一行 `.storage-mix__hint` 说明：`ready` 时写明「数据来自 m-d H:i 的完整核对；之后单张上传不会改变这里的占比」，`empty` 时写明「完整核对已经跑过，对象存储里目前没有可统计的文件，上传后需要再核对一次」。`.storage-mix__foot`（本地 / 云端 / 差）在所有状态下始终渲染，页脚不会因为切换状态而消失。样式在 `assets/ui/pages/storage.css:528-557`（虚线框 + `--g-wash` 底 + 居中文案 + 按钮行居中），`.storage-mix__hint` 单独一条 `:522-526`。
另改一条日志措辞：`src/S3/Sync_Center_Module.php:1791` 的「索引为空」提示从「点击“开始对账”…」改为「点击“开始完整核对”…」，与卡内按钮文案一致（`reconcile` 页的按钮此前已叫「开始完整核对」，只有这条日志没跟着改）。
实测（测试站真实浏览器，1280px 与 1403px）：`unscanned` 卡高 347px、提示块 193px、页脚 36px、两个按钮各 99px，900px 视口 337px 且无横向溢出；`empty` 386px（灰环 + 说明）；`interrupted` 326px；`scanning` 280px（用 `page.route` 冻结 `admin-ajax.php` 才测得稳定态）；`ready` 386px。卡内按钮端到端可用：点「开始完整核对」→ `admin-ajax` 200 → 页面自动 reload → 状态变 `done`、卡变 `storage-mix--ready`；点「继续核对」→ 200 → 同样转 `ready`。本轮只改一个视图文件、一个样式文件与一条日志文案，**不新增类、方法、钩子、REST 路由与数据表**：图谱仍 **191 节点 / 861 连线**、模块 17 个、`modref` 50 条、钩子 204 条全部不变。
规模（生产代码，1.25.4）：**189 文件 / 65,233 行 / 174 类与接口 / 204 具名钩子 / 23 张自定义表**；`includes/views` 12 文件 5,459 行，后台资源 41 文件 / 34,484 行（其中 `assets/ui/` 29 文件 / 14,994 行），`src/Admin` 16 文件 3,292 行，`src/S3` 37 文件 11,934 行。tests 76 文件 / 10,203 行；全量验证 **441 tests / 1,332 assertions / 5 skipped**、PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。详见 §10.22、§13。

**上一轮更新（2026-11-13，1.25.5 同步中心每月校准与引导弹窗）**：解决一个结构性问题——同步中心的每一个数字都只来自「最近一次完整核对」写进 `{prefix}sio_sync_scans` 的那一行，而那一行只有人手动点「核对」才会产生。页面本身不访问对象存储（`src/S3/Sync_Center_Module.php:1694` 的 `$summary = $this->index->summary( $profile )` 走到 `src/Sync/Index_Repository.php:1365`，全部读库），所以站点放几个月没人点，卡片就会一直描述一个早已不存在的桶。
新增 **`src/Sync/Sync_Calibrator.php`**（255 行，`final class implements Module`）。它注册一个自定义周期 `sakura_imgopt_monthly`（`add_filter( 'cron_schedules' )`，interval 写字面量 `30 * DAY_IN_SECONDS`，因为 PHPCS 的 `WordPress.WP.CronInterval.ChangeDetected` 只认字面量），并在 `init` / `admin_init` 上做一次 `ensure_scheduled()` 补排（`wp_next_scheduled()` 判重后 `wp_schedule_event( time() + DAY_IN_SECONDS, 'sakura_imgopt_monthly', self::CALIBRATE_HOOK )`），真正执行的是 `calibrate()`：`should_calibrate()` 依次校验 `Feature::enabled()`、`Options::is_enabled()`、`Options::is_configured()`，扫描处于 `queued` / `running` 时跳过，从未扫描过则直接补一次，否则比较 `stale_age()`（取 `finished_at ?: updated_at` 转 UTC 时间戳）与 `STALE_SECONDS = 30 * DAY_IN_SECONDS`；满足条件才 `Scan_Service::start( $profile, 'incremental' )` + `Scan_Worker::schedule( $id, 2 )` 走既有的无人值守分片路径，失败写一条 `warning` 级事件而不是抛出。
用 `incremental` 而非 `full` 的理由：本地侧本来就会按目录 mtime 跳过没变的目录（`src/Sync/Scan_Service.php:419-420`、`:569-577`），云端侧无论如何都要全量列举，`full` 只在需要重置 `sakura_imgopt_sync_full_at` 时才有额外意义。
**双保险**：WP-Cron 只在有访问时才推进，「30 天」可能漂移甚至漏跑，所以 `Sync_Center_Module::render()` 在渲染前调用 `is_calibration_stale( $data )`，超期就 `Sync_Calibrator::schedule_soon()` 排一个 `time() + MINUTE_IN_SECONDS` 的一次性事件。这个 kick **必须用独立钩子 `sakura_imgopt_sync_calibrate_now`**——如果与周期事件共用 `sakura_imgopt_sync_calibrate`，`wp_next_scheduled()` 会把它误判成「已经排过」而直接吞掉（实测：把扫描行 `finished_at` 改成 31 天前后刷新页面，`wp cron event list` 立刻出现 `sakura_imgopt_sync_calibrate_now`（53 秒后）而周期事件仍在 23 小时 55 分，两者互不干扰）。
**弹窗**：`src/S3/Config_Notice.php` 由 162 行重写为 273 行，把「未配置」与「未核对」两种提示收敛到同一个 `render_modal()` 骨架（icon → kicker → 标题 → 说明 → 缺失项面板 → 底部动作区），两者共用同一套「不再提醒 / 12 小时 snooze」偏好（`is_quiet_window_over()` 读 `sakura_imgopt_s3_config_notice_dismissed` 与 `..._snoozed_until`）。新增 `should_prompt_reconcile( Options, $configured, $has_scan, $busy )` 与 `reconcile_html()`：kicker `RECONCILIATION / REQUIRED`、标题「还需要一次完整核对」、主按钮 `[data-sakura-action="scan"]` 直接复用页面既有的扫描动作与 nonce，因此**不需要额外的关闭状态机**——核对成功后页面 reload，`scan_obj` 已存在，条件自然不成立，弹窗不再出现。未配置弹窗（`modal_html( $options, 'sync' )`）此前只在连接配置页挂载（`src/S3/S3_Storage_Page.php:509`），现在同步页也输出一份，主按钮按上下文指向 `#sakura-s3-connection` 或 `Registry::url( 'storage', 'connection' )`。
**校准过期提示**：`includes/views/s3-sync-center.php:56-61` 算出 `$calibrate_age` / `$calibrate_stale`，在差异提示之前插入一条 `UI::notice( ..., 'warn' )` 与「立即校准」按钮，说明期间的对象增删不会体现在下方统计里。两个弹窗的 `echo` 必须留在 `$sync_body = ob_get_clean();` 之前的缓冲段内，否则会落到 `.sakura-admin` 根节点之外，事件委托与样式前缀全部失配。
**前端**：`assets/ui/js/pages/storage-sync.js` 新增 `initDialogs()`（约 190 行），逐个接管 `#sakura-s3-config-modal` 与 `#sakura-s3-reconcile-modal`：打开时给 `body` 加 `sakura-modal-open` 锁滚动、40ms 后把焦点送进标题、Tab 在可聚焦元素间首尾回绕、`Escape` / 点背景 / 关闭按钮都能关、关闭后把焦点还给原处；没有 checkbox 的核对弹窗点任意关闭方式都按 12 小时 snooze 发一次 `sakura_s3_config_notice`。样式侧把 `assets/ui/pages/storage.css` 的 13 条 modal 规则前缀从 `[data-sakura-page="storage-connection"]` 扩成 `:is([data-sakura-page="storage-connection"], [data-sakura-page="storage-sync"])`，另补 icon 两种色调（琥珀 / 暖灰，配置缺失不是错误，不用红）、说明行 `max-width: 46ch`、`h2:focus` 的 `outline: none`（标题自动聚焦会画出黑框），以及 `@media (max-width: 520px)` 下动作区 `flex-direction: column`——注意不能写 `column-reverse`，DOM 里 checkbox 在按钮组之前，反向会把它顶到最上面；改 `column` 后主按钮正好落在最后。
实测（测试站真实浏览器）：未核对弹窗卡片 560×291、圆角 22px、`z-index: 100000`、`activeElement = sakura-s3-reconcile-title`；未配置弹窗 560×307、icon 底色 `rgb(239,238,233)`（暖灰）、chips「存储桶名称 / Access Key / Secret Key」；390px 卡片 354 宽、320px 284 宽、`document.scrollWidth === innerWidth` 无横向溢出、两个按钮各 248px 整宽；Tab 在 4 个元素内循环不逃出、Escape 关闭且滚动锁复原、点背景同样关闭。卡内「开始核对」端到端可用：点击 → `admin-ajax` 200 → 页面 reload → 新建扫描行、`.storage-mix` 变 `--ready`、弹窗消失。连接配置页无回归（六张卡仍在 `data-sakura-layout="s3"` 组内、锚点与文案不变）；控制台 0 error 0 warning。
规模（生产代码，1.25.5）：**190 文件 / 65,667 行 / 175 类与接口 / 209 具名钩子 / 23 张自定义表**；`src/Sync` 18 文件 6,048 行，`src/S3` 37 文件 12,078 行，`includes/views` 12 文件 5,486 行，`src/Admin` 16 文件 3,292 行，后台资源 41 文件 / 34,750 行（其中 `assets/ui/` 29 文件 / 15,260 行）。tests 77 文件 / 10,320 行；全量验证 **445 tests / 1,344 assertions / 5 skipped**（新增 `tests/SyncCalibratorTest.php` 4 例，并在 `tests/ModuleRegistrationTest.php` 补 `sync_calibrator` 用例）、PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。图谱 **192 节点 / 873 连线**（648 ref + 50 modref + 175 contains），模块仍 17 个、`modref` 仍 50 条。详见 §9.2、§10.23、§13。
**上一轮更新（2026-11-13，1.25.6 停用态提示与三值状态）**：修一个自相矛盾的状态——S3 连接信息填全但总开关关掉时，同步中心右上角显示「配置不完整」，副标题却照样报「缤纷云 · 覆盖率 1.5%」；同时这个状态下**两种引导弹窗都不出现**（未配置弹窗要求 `configuration_errors()` 非空，未核对弹窗要求 `is_configured` 为真，而 `is_configured` 当时被写成了 `is_enabled() && is_configured()`），页面只剩一行小字，用户分不清是漏了设计还是坏了。
修法分三层。**① 状态三值化**：`includes/views/s3-sync-center.php:243-258` 的 `$status_text` / `$status_tone` 与 `:259-269` 的 `$health_text` / `$health_warn` 都改为「配置不完整（bad）→ 已停用（warn）→ 连接正常 / 配置可用（ok）」，`:281-300` 的 `$page_status_sub` 同步三分支，停用态写作「%s · 连接信息完整，开关关闭」而不是重复一遍「已停用」。**② 新增第三种弹窗**：`src/S3/Config_Notice.php` 抽出 `should_prompt_paused( Options )`（:104）与 `paused_html( Options )`（:309），与既有的 `modal_html()` / `reconcile_html()` 共用 `is_quiet_window_over()`（:125）这一份「不再提醒 / 12 小时 snooze」偏好；kicker `OBJECT STORAGE / PAUSED`、标题「对象存储已停用」、说明新图不会同步到云端、媒体地址不会被接管、下面的数字停在停用之前，主按钮「去启用 S3」固定走 `Registry::url( 'storage', 'connection' )`。**③ 三者互斥**：`includes/views/s3-sync-center.php:957-964` 按「缺字段 > 停用 > 未核对」依次取值，第一个非空即输出；`src/S3/Sync_Center_Module.php:1914-1915` 的 `page_data` 同时暴露 `is_configured` 与 `is_enabled` 两个独立字段，`:344` 把它绑成 `$storage_enabled`，未核对弹窗的门控改为 `$is_configured && $storage_enabled && $index_enabled`。
顺带修掉两个真实缺陷：**① 变量名不一致导致功能失效**——`includes/views/s3-sync-center.php:79` 的默认值守卫原先写的是 `isset( $is_enabled )`，而这个变量从未被赋值（`page_data` 的键叫 `is_enabled`，绑定的局部变量叫 `$storage_enabled`），`isset` 恒 `false`，于是**连 S3 正常启用时也被判成停用**；已改为 `isset( $storage_enabled )`，docblock 同步改 `@var bool $storage_enabled`。**② 连接页分支是死代码**——`paused_html()` 原先带 `$context` 形参并在 `connection` 上下文把主按钮指向 `#sakura-s3-connection`，但 `src/S3/S3_Storage_Page.php:509` 只挂 `modal_html( $this->options, 'connection' )`，从不挂 paused 变体，该分支永远走不到；已把签名简化为单参、删掉分支，理由写在注释里：连接页上开关本身就在视野内，再弹一个模态只会挡住用户正要点的控件。
停用期间**仍然允许手动核对**：核对只读取云端清单，不上传、不改媒体地址（`src/S3/Storage.php:108-112` 才是停用后真正关闭的路径——不接管媒体 URL、不排上传队列），把它禁掉只会让人没法确认桶里还剩什么，所以本轮只补提示与文案，没有收窄权限。
实测（测试站真实浏览器，1403px / 390px / 320px）：停用态弹窗卡片 560×291、圆角 22px、`z-index: 100000`、icon 46×46 底色 `rgb(251,250,247)`（暖灰，不是红）、`activeElement = sakura-s3-paused-title`、body 锁滚动、标题 `outline: none`；Tab 在「复选框 → 稍后处理 → 去启用 S3 → 关闭」4 个元素内循环不逃出，Escape / 点背景 / 关闭按钮都能关且关闭后滚动锁复原、焦点还给原处；关闭即写 12 小时 snooze、勾「不再提醒我」则写永久 `sakura_imgopt_s3_config_notice_dismissed`，刷新后都不再出现。点「去启用 S3」跳到 `admin.php?page=sakura-tools-storage`，该页**没有** paused 弹窗（开关就在眼前），也没有 setup 弹窗，`input[name="enabled"]` 存在且未勾选。窄屏 390px 卡片 354 宽、两个按钮各 318px 整宽，320px 卡片 284 宽、按钮各 248px，`document.scrollWidth === innerWidth` 无横向溢出。四种状态互斥验证：缺字段（bucket 置空）只出 setup 弹窗、停用只出 paused、启用三种都不出、删除队列页不出。控制台 0 error 0 warning。
规模（生产代码，1.25.6）：**190 文件 / 65,789 行 / 175 类与接口 / 209 具名钩子 / 23 张自定义表**；`includes/views` 12 文件 5,542 行，`src/S3` 37 文件 12,144 行，`src/Sync` 18 文件 6,048 行，`src/Admin` 16 文件 3,292 行，后台资源 41 文件 / 34,750 行（其中 `assets/ui/` 29 文件 / 15,260 行）。tests 78 文件 / 10,636 行；全量验证 **455 tests / 1,369 assertions / 5 skipped**（新增 `tests/ConfigNoticePausedTest.php` 10 例），PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。本轮只改视图、提示类与前端分派，**不新增类、方法、钩子、REST 路由与数据表**：图谱仍 **192 节点 / 873 连线**（648 ref + 50 modref + 175 contains），模块 17 个、`modref` 50 条。详见 §9.2、§10.24、§13。
**上一轮更新（2026-11-13，1.25.7 弹窗关闭规则）**：修「关掉提示弹窗后就再也不提醒」的问题。反馈的现象是：在同步中心点 X 或「稍后处理」关掉弹窗，刷新页面弹窗不再出现——而这两者当时**完全等价**，都写 `sakura_imgopt_s3_config_notice_snoozed_until = time() + 12 小时`（`src/S3/Config_Notice.php` 的 `SNOOZE_SECONDS = 43200`），于是「我知道但先不动」被当成「已处理」，页面继续显示已停用 / 配置不完整，却没有任何东西再打断操作者。这与 §5.1 里「配置缺失不是错误、忽略后会影响功能」的前提直接冲突：**弹窗是页面上唯一说明功能坏了的地方**。
修法分四处。**① 关闭语义二分**：`src/S3/Config_Notice.php:121-140` 的 `is_quiet_window_over()` 改名为 `is_prompt_allowed()`，只读 `sakura_imgopt_s3_config_notice_dismissed` 一个开关；`SNOOZE_SECONDS` 常量删除，`SNOOZE_META_KEY` 保留但只用于清理旧版本写入的残留（读到非空即 `delete_user_meta()`，不再判断时间）。三个门控 `should_show()` / `should_prompt_reconcile()` / `should_prompt_paused()` 全部改调 `is_prompt_allowed()`。**② 前端不再无脑写库**：`assets/ui/js/pages/storage-sync.js:195-214` 的 `persist()` 与 `assets/ui/js/pages/storage-connection.js:198-215` 的 `persistChoice()` 都改为「没勾选 `[data-config-dont-remind]` 就直接 `return`」，只发一次 `dismiss=1`；X、Esc、点背景、次按钮、主按钮跳转都只关 DOM，不产生任何请求。**③ 文案与结构对齐**：次按钮由「稍后处理」改为「暂不处理」（`Config_Notice::actions_markup()` 的三处调用），因为它不再代表「12 小时后再问」；核对引导弹窗原先 `actions_markup( ..., $primary, false )` 不渲染复选框、只能靠关掉消失，现在补上「不再提醒我」。**④ 接口收窄**：`src/S3/Sync_Center_Module.php:581-611` 的 `ajax_dismiss_config_notice()` 只认 `dismiss=1`，`snooze=1` 仍然接受但只做清理与返回，不再写延期——避免旧缓存脚本把已废弃的窗口复活。
配套同步：`docs/后台UI规则.md:163-166` 的「关闭分两档」重写为「只有一档写库」，并补一句「1.25.6 及更早版本的 12 小时 snooze 已废弃，升级后读到残留时间戳会直接删除」；同文件 :358 的验收项由「稍后处理写入 12 小时冷却」改为「X / Esc / 点背景 / 暂不处理关掉后刷新会重新弹出」。
实测（测试站真实浏览器，1403px，`Playwright` + 临时管理员 cookie）：停用态首次进入 `#sakura-s3-paused-modal` 可见；点 X 后 reload **仍可见**（此前消失）；点「暂不处理」后 reload **仍可见**；按 Esc 后 reload **仍可见**；勾选「不再提醒我」再关，reload 后 `absent`，`wp user meta` 显示 `dismissed=1`、`snoozed_until` 为空。旧版本残留验证：手工写入 `snoozed_until=9999999999` 后调用 `should_prompt_paused()` 返回 `true`，且该 meta 在同一次请求里被删除（`after=[]`）。次按钮文案实测为 `['暂不处理', '去启用 S3']`、`hasCheckbox=true`、`[data-config-close]` 3 处（X / 遮罩 / 次按钮）；连接配置页无 paused 弹窗，未配置态仍只出 setup 弹窗。
规模（生产代码，1.25.7）：**190 文件 / 65,792 行 / 175 类与接口 / 209 具名钩子 / 23 张自定义表**；`src/S3` 37 文件 12,147 行，`includes/views` 12 文件 5,542 行，`src/Sync` 18 文件 6,048 行，`src/Admin` 16 文件 3,292 行，后台资源 41 文件 / 34,759 行（其中 `assets/ui/` 29 文件 / 15,269 行）。tests 78 文件 / 10,639 行；全量验证 **455 tests / 1,370 assertions / 5 skipped**（`tests/ConfigNoticePausedTest.php` 的 snooze 两例改写为「关掉不静默」与「旧版残留被清理」），PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。本轮只改提示类、AJAX 处理与前端关闭逻辑，**不新增类、方法、钩子、REST 路由与数据表**：图谱仍 **192 节点 / 873 连线**（648 ref + 50 modref + 175 contains），模块 17 个、`modref` 50 条。详见 §9.2、§10.25、§13。
**上一轮更新（2026-11-13，1.25.8 同步中心与存储配置一一对应）**：把「同步中心的数据属于哪份配置」这件事从约定变成机制。此前**读取**侧已经按配置隔离（`src/Sync/Schema.php:87-186` 五张表的索引全部以 `profile_id` 打头，`Index_Repository::summary()` 只查当前配置的 counts 与 `latest_scan`），缺的是**写入**侧的两处漏洞。

**① 原地改桶名会篡改配置**：`src/S3/Settings_Saver.php:38-52` 新增 `profile_id_for_save( array $profiles, string $submitted_id, bool $save_as_new, string $provider, string $bucket, string $endpoint ): string`——`save_as_new`、id 为空、id 不存在、或 `provider|bucket|endpoint` 任一变化，都返回 `Options::new_profile_id()`，否则沿用原 id。原来的判定只看 `provider`，于是把 `text-demo` 改成 `text-demo-renamed` 会写成同一条配置：同步中心拿着旧桶的 402 个本地文件、6 个云端对象、1.5% 覆盖率显示在新名字下面，而且因为它「看起来有核对记录」，未核对引导弹窗也不会出现。配置**就是**目标，不只是一种命名。副作用是保存后操作者其实已经换到另一份配置，所以 `Settings_Saver::save()` 末尾在 `$submitted_id !== $profile_id` 时给 redirect 加 `created=1`，`src/S3/S3_Storage_Page.php:549-560` 据此把成功提示换成「已保存为新的存储配置：切换了服务商、存储桶或服务地址，原配置与它的核对记录保持不变。」。

**② 删除配置会留下读不到的孤儿数据**：`src/Sync/Index_Repository.php:1993-2013` 的 `purge_index()` 原先只删 index / counts / jobs 三张表，而 `scans` 行才是同步中心「云端 N 个文件」的来源（`Index_Repository::summary()` 的 `cloud_objects = $scan->total_remote`）、`events` 是日志卡的内容。配置被删之后这两张表的行永远留在库里，界面上再也找不到能展示或清掉它的地方——`Profile_Manager::delete_profile()`（`src/S3/Profile_Manager.php:105-128`）本身只 `unset` 数组键，不做任何 DELETE，清理全由调用方负责。现在五张表一起删，CLI 的 `sync purge`（`src/Sync/CLI.php:384-405`）是同一方法的另一个调用点，docblock、确认文案与成功文案同步改成「全部索引、计数、任务、核对记录与事件日志」；删除确认（`src/S3/S3_Storage_Page.php:175`）也写明「它对应的核对记录、计数与日志也会一起清空」。

**③ 停用即冻结**：`includes/views/s3-sync-center.php:80-86` 新增 `$frozen = $is_configured && ! $storage_enabled`——只有「配置完整但开关关闭」才算暂停，从未配置的站点走未配置弹窗，不该被当成停用。随后 20 处 `disabled( $frozen )` 覆盖核对、刷新、停止、继续、重试、测试连接、切换同步记录、忽略规则保存，以及行内上传 / 下载 / 删除与批量上传 / 下载 / 删除；`src/Admin/UI/Card.php:36` 新增 `save_disabled` 参数让 `savebar()` 能渲染禁用的提交按钮（按钮留在原位，卡片不会因为冻结而重新排版）。**保留可用**：连接设置、详情预览、六个筛选 chip、搜索/类型/时间、每页条数、日志级别、导出 CSV、清除选择——这些都不碰存储桶，禁掉只会妨碍排查。页面顶部加一条 `UI::notice( ..., 'warn' )` 说明原因，`#sakura-sync-state` 带出 `data-frozen`。

**④ 前端兜底**：`assets/ui/js/pages/storage-sync.js:348-361` 新增 `isFrozen()` / `applyFrozen()`。AJAX 重画出来的行与批量栏不会继承服务端渲染的 `disabled`，所以 `actionButton()` 末尾改 `return applyFrozen( button )`、`setBatchButtonsDisabled()` 里三个触碰桶的按钮改为 `disabled || frozen`（`batchClear` 强制 `false`），主 delegate 与 tbody delegate 在 `preventDefault` 前再加一道 `if ( isFrozen() || button.disabled ) return`。实测把 scan 按钮的 `disabled` 改回 `false` 再 `dispatchEvent` 点击，`defaultPrevented=true`、没有 admin-ajax 请求、`scanId` 与 `scanState` 不变。

**⑤ 抬头改桶名**：`includes/views/s3-sync-center.php:291-292` 把状态卡的标签从「连接状态」改成「当前存储桶」，主值改为桶名（`'' !== $bucket ? $bucket : '未配置'`），副行改为 `$provider_label . ' · ' . $status_text`。同一服务商可以存多份配置，只有桶名能说明当前看的是哪一个；连接配置页（`src/S3/S3_Storage_Page.php:44-47`）早就是这么写的，同步中心此前是唯一对不上的那屏。

实测（测试站真实浏览器与 wp-cli）：停用态 `data-frozen=1`、抬头「当前存储桶 / text-demo / 缤纷云 · 已停用」、写操作按钮全部 disabled、只读全部可用；启用态 `data-frozen=0`、抬头「缤纷云 · 连接正常」、无提示条；未配置态不冻结、走未配置弹窗。改桶名后 `saved_profiles()` 从 1 条变 2 条（旧 `prof_dc340e753a3f` / `text-demo` 保留、新配置 active），同步中心随即变成 `storage-mix--unscanned`「还没有核对记录」；不改目标直接保存仍沿用原配置、只提示「云存储设置已保存。」。删除临时配置 `prof_e2etest00002` 后五张表该 profile 行数全部归 0。

规模（生产代码，1.25.8）：**190 文件 / 65,847 行 / 175 类与接口 / 209 具名钩子 / 23 张自定义表**；`src/S3` 37 文件 12,183 行，`includes/views` 12 文件 5,541 行，`src/Sync` 18 文件 6,057 行，`src/Admin` 16 文件 3,297 行，后台资源 41 文件 / 34,805 行（其中 `assets/ui/` 29 文件 / 15,315 行）。tests 78 文件 / 10,730 行；全量验证 **460 tests / 1,377 assertions / 5 skipped**（新增 `tests/SettingsSaverTest.php` 5 例），PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。本轮只改方法与行为，**不新增类、钩子、REST 路由与数据表**：图谱仍 **192 节点 / 873 连线**（648 ref + 50 modref + 175 contains），模块 17 个、`modref` 50 条。详见 §9.2、§10.26、§13。

**本轮更新（2026-11-13，1.25.9 弹窗排版）**：修对象存储三类提示弹窗（未配置 / 已停用 / 需要首次核对）的排版失衡。这三张卡共用同一套规则——`assets/ui/pages/storage.css` 里前缀为 `.sakura-admin:is([data-sakura-page="storage-connection"], [data-sakura-page="storage-sync"])` 的 `.sakura-modal__*` 选择器组，1.25.5 建立、1.25.6 扩到停用变体。

**① 失衡的真正来源不是对齐**：`.sakura-modal__card > p:not(.sakura-modal__kicker)`（`assets/ui/pages/storage.css:1129-1138`）带 `max-width: 46ch`。这个上限按等宽西文算的行宽，落到中文上大约只放下 23 个字，实测 76 字的说明段在 560px 宽的卡片里只占 368px、右侧空出 144px。先按「整体居中」做过一版对照：卡片从 291px 涨到 314px（正文居中后折行位置变了），并没有变好看。结论是先去掉行宽上限。

**② 定稿的三处改动**：`.sakura-modal__icon`（`:1109-1122`）由 `inline-flex` 改为 `display: flex` 并把 `margin-bottom` 换成 `margin: 0 auto var(--g-space-3)`——inline 级盒子会忽略 auto 左右外边距，只加 margin 图标仍停在左边（第一版就踩了这个坑，截图里图标还是左对齐）；`.sakura-modal__kicker`（`:1096-1103`）与 `.sakura-modal__card h2`（`:1049-1055`）各加 `text-align: center`；正文的 `max-width: 46ch` 改为 `max-width: none`，在 512px 的内容宽里自然折行。说明段保持 `text-align: start`，底部 `.sakura-modal__actions` 仍是 `space-between`（复选框靠左、按钮靠右），没有动。

**③ 约定写回规则文档**：`docs/后台UI规则.md:160` 在 §5.1 弹窗小节补一条对齐规则——图标、眉标、标题三行居中，说明段与底部操作区保持左对齐 / 两端对齐，且说明段不要加 `max-width: 46ch` 这类行宽上限（附中文折行过早的原因与 inline 盒子忽略 auto margin 的坑）。

实测（测试站真实浏览器，Playwright + 临时管理员 cookie，三种弹窗逐一渲染）：1403px 下停用弹窗 `.sakura-modal__card` 560×291，卡片中心、图标中心、眉标中心、标题中心**全部为 702px**；正文 `text-align: start`、宽 512px、3 行、段高 69px；按钮仍为 `['暂不处理', '去启用 S3']`、`space-between`。未核对弹窗 560×268、未配置弹窗（chip 为「还需要补全 / 存储桶名称」）同样三行居中、正文左对齐。窄屏 520px 卡片 484px、底部操作区 `flex-direction: column`；390px 卡片 354px、按钮各 318px；320px 卡片 284px、按钮各 248px，四档 `document.documentElement.scrollWidth` 均等于 `window.innerWidth`，无横向溢出。连接配置页六张卡（profiles / behaviour / connection / upload-metrics / test / monitoring）仍在 `[data-sakura-layout="s3"]` 组内、组 class 仍为 `is-free is-laid`，无回归。

规模（生产代码，1.25.9）：**190 文件 / 65,847 行 / 175 类与接口 / 209 具名钩子 / 23 张自定义表**；`src/S3` 37 文件 12,183 行，`includes/views` 12 文件 5,541 行，`src/Sync` 18 文件 6,057 行，`src/Admin` 16 文件 3,297 行，后台资源 41 文件 / **34,815 行**（其中 `assets/ui/` 29 文件 / **15,325 行**）。tests 78 文件 / 10,730 行；全量验证 **460 tests / 1,377 assertions / 5 skipped**（本轮未新增测试），PHPStan level 5 无错误、PHPCS 0 error（36 条 warning 与 1.24.4 基线相同）。本轮只改 CSS 与文档，**不新增类、方法、钩子、REST 路由与数据表**，生产 PHP 行数与 1.25.8 完全相同：图谱仍 **192 节点 / 873 连线**（648 ref + 50 modref + 175 contains），模块 17 个。详见 §9.2、§10.27、§13。

**本轮更新（2026-11-14，1.26.0 → 1.27.5 六个版本合并记录）**：这一段跨 24 个提交、48 个文件（+2,704 / −2,848），主体是后台 UI 的三次结构性调整与一次卸载清理修复。

**① 1.26.0 樱色配色、统一弹窗与稳定布局（`2b2b76d`）**：`265f957 style(admin): repaint the toolbox in a sakura palette` 把后台配色收敛到「樱雾」一套（`--g-sakura-1..4` 与 `--g-accent #9d3452`）；`b380dce feat(admin): one prompt dialog for every save and test result` 新增 `src/Admin/UI/Modal.php`（167 行）与 `UI::modal()` / `UI::modal_html()` 门面，把「保存结果 / 连接测试 / 导入导出」收进同一张卡片，WP 自带的 `settings_errors()` 绿条由 `src/Admin/UI/Page.php:79` 的 `settings_feedback()` 捕获后转成弹窗；`10369e7`、`5df107b`、`15505c4`、`70a1ec9` 四条修掉弹窗打开把读者拽到页顶、关闭后回不到原位置的问题（`sessionStorage.sakuraAdminSaveScroll` 恢复）。

**② 1.27.0 移除卡片拖拽布局引擎（`e395d66`，本版最大的净删除）**：此前的卡片位置来自一个前端引擎——它把每张卡改成绝对定位并重写文档高度，于是长页面跳动、卡片晚一帧出现、首屏曾空白两秒。现在 `src/Admin/Layout.php` 的 `const PAGES`（`:59-178`）静态声明每页的 `order` 与每卡的 `array(行, 列, 跨列)`，`UI::card()` 直接输出；一并删除 `layout.js`、`layout.css`（约 2,600 行）、右侧「布局修改」工具栏、拖拽缩放、`LAYOUT_PAGES` / `has_layout()` / `sakura-layout-enabled` 与 46px 预留留白、`Layout` 的保存端点与按用户的 `_sakura_imgopt_layout` 元数据。**这是最需要知道的约定变更：卡片排布是出厂设置，不再随用户变化**；新增页面必须在 `Layout::PAGES` 补条目（见 `docs/开发规则/02-新增功能.md`）。

**③ 1.27.1 内联 grid 属性压过断点（`6d589cf`）**：1.27.0 把 `grid-row` / `grid-column` 写成内联样式，内联声明优先级高于任何样式表，≤1100px 的单列规则失效，手机上 6 栏卡只剩半屏宽。改为内联 `--g-card-row` / `--g-card-col` / `--g-card-span` 三个自定义属性，由 `components.css` 读取，断点里置 `auto`；同行卡片改 `align-items: stretch` 等高，并按阅读顺序重排消除 10 处半满行。

**④ 1.27.2 提示弹窗改为无遮罩卡片（`6ab9b64`）**：`.sakura-modal` 不再是「全屏遮罩 + 居中卡」，遮罩节点、`--g-overlay` 铺色与 `backdrop-filter: blur(3px)` 全部删除，页面照常可滚可点；随之删掉 `scrollLock`（此前打开弹窗会把 body 改成 `position: fixed` + `top: -scrollY`，文档当帧塌成视口高、滚动条消失、整页重排）。需要模态语义的二次确认与媒体预览仍用原生 `<dialog>` + `showModal()`。

**⑤ 1.27.3 / 1.27.4（`7f3fd5c`、`8668a34`）**：1.27.3 把空间套餐的订单图标选择器从 112×96 虚线方块改成 38px chip（`assets/ui/pages/files-zibll.css`），并修掉 `.btn` 覆盖 `[hidden]` 导致「移除」按钮仍占位的问题；1.27.4 修卸载残留（外观设置 `sakura_imgopt_ui_style`、两把锁与三类 transient、两条 usermeta、`sufm_delete_tick` 单次事件、校准器自排的两个 cron），`tests/bootstrap.php` 补 cron / 多站点 / 角色桩与伪 `$wpdb`。

**⑥ 1.27.5 退出跨文档 View Transitions（`0f29adf`）**：WordPress 7.0 起对登录态后台全局启用跨文档 View Transitions，每次点模块或页签都把新旧两页整页交叉淡入 250ms，密集表格叠在一起就是文字互相穿透。插件用 `assets/ui/base.css` 的 `@view-transition { navigation: none }` 退出这套过渡（核心没有 PHP 开关），改由 `.sakura-admin__main` 的 180ms 淡入上浮自控；同时在 `assets/ui/js/core.js` 里加点击即亮的顶部 2px 樱色扫光条，填补 WordPress 引导那约 300ms 的无反馈期。实测点击到 load 从 456ms 降到 422ms（5 轮均值），VT 伪元素动画数归零。**预取路线已被证伪**：登录态后台响应头带 `Cache-Control: no-store`，Speculation Rules 直接跳过，悬停目标 3 秒服务器日志零新增请求。

**⑦ 文档重构（与本报告同批）**：`docs/后台UI规则.md` 迁为 `docs/开发规则/03-后台UI.md`；`docs/开发规则/` 下另有 00-索引、01-架构与装配、02-新增功能、04-数据与存储、05-测试与质量、06-发布流程六份新增；`DEVELOPMENT.md`、`docs/后台UI重构方案.md`、两份 1.25.9 分析产物移入 `docs/history/` 并在开头加「不代表现状」声明。

规模（生产代码，1.27.5）：**191 文件 / 65,780 行 / 176 类与接口 / 207 具名钩子 / 23 张自定义表**；`src/S3` 37 文件 12,211 行，`src/UserFiles` 46 文件 22,262 行，`src/Sync` 18 文件 6,039 行，`src/Admin` 17 文件 3,246 行，`includes/views` 12 文件 5,579 行，后台资源 38 文件 / **33,897 行**（其中 `assets/ui/` 27 文件 / **14,407 行**）。tests 79 文件 / 11,205 行；全量验证 **470 tests / 2,411 assertions / 6 skipped**，PHPStan level 5 无错误。图谱 **193 节点 / 881 连线**（655 ref + 50 modref + 176 contains），模块 17 个。


---

## 2. 文件结构

### 2.1 目录树（生产代码）

```
sakura-tools/
├── sakura-tools.php            主文件：常量、自动加载、Plugin::boot 调用
├── uninstall.php               卸载入口（守卫 + 手动加载器 + Uninstaller）
├── fast-search.php             SHORTINIT 直连的只读索引查询端点
├── includes/                   14 个类文件 / 6,185 行 / 14 类    ← 旧层（1.3–1.9）
│   ├── class-image-processor.php       钩子门面，把 7 个 WP 钩子转给 src/Image
│   ├── class-imagick-handler.php       Imagick 编码与原子替换
│   ├── class-gd-handler.php            GD 编码与原子替换
│   ├── class-file-system.php           受控 copy/move/占位/删除
│   ├── class-backup.php                原图备份、还原、占用统计
│   ├── class-stats.php                 统计聚合（option + postmeta 明细）
│   ├── class-animation-detector.php    文件头识别动图
│   ├── class-s3-doge-cloud.php         狗云对象存储的旧适配层
│   ├── class-s3-cli.php                WP-CLI 命令注册（仅 WP_CLI 时 require）
│   ├── class-media-library-module.php  媒体库批量处理与还原按钮
│   ├── class-image-optimizer-module.php 图片设置页 + 裁剪扫描 AJAX
│   ├── class-thumbnail-optimizer-module.php 缩略图子系统唯一装配点
│   ├── class-thumbnail-manager.php     按尺寸禁用缩略图生成
│   └── class-s3-storage-module.php     对象存储设置页
├── includes/views/             12 文件 / 5,579 行               ← 旧版页面模板
├── src/                        5 文件 / 935 行                  ← 组合根与升级守卫
│   ├── Plugin.php              启动、装配、注册 WP-CLI 命令
│   ├── Activation.php          默认值、迁移、建表、排程
│   ├── Deactivation.php        清排程与能力，保留数据
│   ├── Uninstaller.php         两轨卸载（临时数据 / 持久数据 opt-in）
│   └── Upgrade_Guard.php       覆盖升级自愈：版本判断、加锁、补跑 Activation
├── src/Admin/                  17 文件 / 3,246 行   后台 IA、UI 内核、资源加载与外观令牌
├── src/Image/                  10 文件 / 3,167 行   上传链路、引擎回退、元数据、还原、尺寸目录
├── src/Oss/                    2 文件 / 503 行      HTML 过滤器与 URL 重写纯函数
├── src/Thumbnail/              15 文件 / 3,772 行   缩略图策略、供应商、探测、统计、主题桥
├── src/S3/                     37 文件 / 12,211 行  凭据加密、上传、删除队列、同步中心、监控
├── src/Sync/                   18 文件 / 6,039 行   索引表、扫描状态机、动作队列、校验、CLI、每月校准
├── src/UserFiles/              46 文件 / 22,262 行  上传/配额/审核/执行/REST/空间套餐/Zibll 桥
├── src/Backup/                 2 文件 / 457 行
├── src/Contracts/              5 文件 / 387 行      5 个接口
├── src/Filesystem/ src/Settings/ src/Stats/ src/Support/   5 文件 / 628 行
├── assets/ui/                  27 文件 / 14,407 行  后台唯一样式栈：tokens/base/components + 分页 CSS 与脚本
├── assets/                     11 文件 / 19,490 行  前台与媒体库资源（editor-picker、userfiles、media-folders）
├── languages/                  翻译文件
├── tests/                      79 文件 / 11,205 行  PHPUnit（不计入生产规模）
├── stubs/                      2 文件 / 134 行      PHPStan 引导
├── vendor/                     第三方依赖（AWS SDK 等，不计入）
├── docs/开发规则/               六份开发规则 + 索引（新功能从这里查）
├── docs/history/               已归档的历史文档（开头均有「不代表现状」声明）
└── DECISIONS.md / readme.txt   决策记录与 WP 安装说明
```

> **非插件产物说明**：插件根目录曾出现 `.dsh-ops/panel/`，是 dsh-ops 面板按「当前工作目录」写入的捕获文件，不属于插件。该目录由面板代码写死（`dsh-ops/lib/panel/config.js:8` 的 `PANEL_DIR`，`lib/cli/panel.js:42` 取 `cwd`），本机 DSH 的工作目录正好是插件根，因此每开一次会话就可能多一份快照。2026-11-08 已清理归档（当时 11 个文件 / 3.3 MB）并把 `.dsh-ops/` 加入 `.gitignore`；只要 DSH 仍以本目录为工作目录，它仍会重新生成。

### 2.2 模块总览

| 模块 | 文件数 | 代码行 | 类(含接口) | 说明 |
|---|---:|---:|---:|---|
| src/UserFiles | 46 | 22308 | 46 | 用户文件空间：上传、配额、审核执行、REST、Zibll 桥接 |
| src/S3 | 37 | 12248 | 37 | 对象存储：配置/加密、上传同步、删除队列、同步中心、监控告警 |
| includes | 14 | 6199 | 14 | 1.3–1.9 时代的旧类，仍在承重（媒体库、优化器、缩略图、S3、处理器） |
| src/Sync | 18 | 6057 | 18 | 持久化同步索引：扫描、任务、校验、CLI、快速检索查询 |
| includes/views | 12 | 5591 | 0 | 旧式页面模板（由 includes 类 require 渲染） |
| src/Thumbnail | 15 | 3787 | 15 | 缩略图 URL 重写策略：上下文、尺寸、供应商、探测、缓存 |
| src/Admin | 17 | 3263 | 17 | 后台信息架构：Registry 路由表、Menu、Layout、Shell、Assets、环境自检 |
| src/Image | 10 | 3177 | 10 | 图片处理管线：改名、备份、引擎选择、元数据、还原、尺寸目录 |
| src | 5 | 940 | 5 | 组合根与生命周期：Plugin、Activation、Deactivation、Uninstaller |
| src/Oss | 2 | 505 | 2 | HTML 过滤器与 URL 重写工具（缩略图策略复用的纯函数） |
| src/Backup | 2 | 459 | 2 | 原图备份仓储与公网暴露探测 |
| 根文件 | 3 | 412 | 0 | 插件入口、卸载入口、前台快速检索入口 |
| src/Contracts | 5 | 392 | 5 | 五个接口：Module / Backup / File_System / Settings / Stats |
| src/Settings | 1 | 285 | 1 | 插件设置读写边界（选项读写、默认值与清洗） |
| src/Support | 2 | 178 | 2 | Hook_Loader（延迟注册）与 UTF-8 截断工具 |
| src/Filesystem | 1 | 97 | 1 | 文件系统仓储适配器 |
| src/Stats | 1 | 73 | 1 | 统计仓储适配器 |
| 合计 | 191 | 65971 | 176 | 生产代码；另有 tests 79 文件/11205 行、stubs 2 文件另计 |

**怎么读这张表**：行数不等于复杂度——`src/UserFiles` 22k 行里有 40% 是 REST 控制器与视图模板（重复度高、改动风险低）；`src/Sync` 只有 6.0k 行却包含状态机与 SQL 层，是改动风险最高的模块。

### 2.3 历史分层：includes 与 src 的边界

| 维度 | `includes/`（旧层） | `src/`（新层） |
|---|---|---|
| 时间 | 1.3–1.9（2023–2024） | 1.10+（2024–2025） |
| 命名 | `class-foo.php` + `Foo`，无命名空间 | PSR-4 + 命名空间 `Sakura\ImageOptimizer\…` |
| 装配 | 在各自构造函数里挂钩子 | 实现 `Contracts\Module`，只在 `register_hooks` 里挂 |
| 视图 | `includes/views/*.php` 直接 require | renderer key 注册 + Registry 分发 |
| 依赖注入 | `new` 具体类 | 构造函数注入接口（`Contracts\*`） |
| 现状 | 仍在承重：图片优化链路全部、缩略图装配、S3 设置页、媒体库批量 | 新增能力的默认位置 |

**边界的实际形状**（从依赖矩阵 §11.1 读出）：

- `includes` → `src`：152 条边（S3 43、Admin 22、Image 22、Contracts 17、Thumbnail 10、Settings 9、Sync 7、Backup 7、Support 6、Stats 5、Filesystem 3、Oss 1），说明旧层大量调用新层的服务；
- `src` → `includes`：仅 10 条边（`src` 根层），集中在 `src/S3/Storage.php` 与 `src/Image/` 对 `Image_Processor`、`Backup`、`File_System`、`Stats` 的复用；
- 结论：**新层不依赖旧层的业务逻辑，只复用它的基础设施**（备份、统计、文件系统、处理器门面）。这条边界是安全的，重构时优先动 `includes` 的 `*_Module` 类。

> 注：上表两处"以矩阵为准"的数字在 §11.1 的矩阵里可直接核对；若矩阵与本段不一致，以矩阵为准（矩阵由脚本生成，本段为人工概述）。

---

## 3. 启动、装配与后台路由

### 3.1 入口文件

`sakura-tools.php` 只做四件事：定义常量（版本、路径、URL）、注册 PSR-4 自动加载、加载 `includes/` 旧类、调用 `Sakura\ImageOptimizer\Plugin::boot()`。**没有业务逻辑**，改动入口的风险极低。

### 3.2 `Plugin::boot()` 的七步（`src/Plugin.php:71-115`）

| 步骤 | 位置 | 做什么 | 为什么重要 |
|---:|---|---|---|
| 1 | `src/Plugin.php:72-75` | 防重入：`booted` 标志 | 防止 `plugins_loaded` 被多次触发时重复挂载 |
| 2 | `src/Plugin.php:77` | `Credential_Cipher::migrate_legacy_option()` | 把明文凭据迁移为 `sio1s:` 前缀密文 |
| 3 | `src/Plugin.php:78` | `Options::migrate_legacy_profile()` | 单配置 → 多配置档（profile）迁移 |
| 4 | `src/Plugin.php:82-87` | `Upgrade_Guard::maybe_upgrade()` → `Activation::run()` | 覆盖升级不会触发 activation hook，这里按版本号补跑建表、默认值与排程 |
| 5 | `src/Plugin.php:89-91` | `Sync\Schema::maybe_install()`（仅后台或 WP-CLI） | 6 张 `sio_sync_*` 表的版本化建表 |
| 6 | `src/Plugin.php:93-96` | `build_modules()` 后逐个 `register_hooks($hooks)` | **唯一的装配点**，顺序即依赖 |
| 7 | `src/Plugin.php:114` | `$hooks->register()` | 把排队的钩子一次性交给 WordPress |

另外三处旁挂（同函数内）：`admin_init` 补挂扫描/动作的排程（`:98-101`）、`WP_CLI` 时 require `class-s3-cli.php` 并注册 `wp sakura-sync` / `wp sakura-thumb`（`:103-109`）、每日备份清理 `sakura_imgopt_daily_backup_cleanup`（`:113`）。

### 3.3 `build_modules()` 的装配顺序（`src/Plugin.php:124-206`）

顺序不是随意的，注释与代码共同说明了两条硬约束：**仓储先于消费者**、**菜单最后注册**。

```
① 5 个仓储与处理器（无钩子）
   Backup_Repository · Stats_Repository · File_System_Repository · Settings_Repository
   Image_Processor(backup, null, null, stats, files, settings)        src/Plugin.php:125-130
② 后台基础（is_admin）
   System_Settings_Module · Image_Optimizer_Module                    :135-139
   renderer: system:settings · image:rules · image:backup · image:stats  :143-146
③ 缩略图管理与处理器
   Thumbnail_Manager · $processor · Media_Library_Module              :149-153
   （卡片位置是静态表，Media_Library_Module 注册 0 个钩子，不是布局对象）
④ 缩略图子系统（is_admin 时注册 image:thumb 渲染器）                    :158-163
⑤ S3 旁支（按 配置→适配器→仓储→扫描→校准→队列→监控→索引 顺序）
   Options → Storage_Adapter → Index_Repository → Scan_Service → Scan_Worker
   → Sync_Calibrator → Action_Worker → Alerting → Prometheus
   → Attachment_Indexer → Verify_Worker                                :165-177
⑥ S3 后台（is_admin）：S3_Storage_Module · Sync_Center_Module · Delete_Queue_Panel
   renderer: storage:connection · storage:sync · storage:deletes          :179-191
⑦ Admin\Menu（必须最后）                                              :193-195
⑧ 后台/前台共用：Delete_Worker · Upload_Observer · Storage
   · \Nboke\UserFiles\Module                                       :198-203
```

> 依据：`src/Plugin.php:124-205` 的实际代码顺序；`:193-194` 上方注释写明"菜单最后注册，保证 WordPress 取屏时渲染器都已存在"。

### 3.4 Hook_Loader 的延迟注册语义（`src/Support/Hook_Loader.php:55-127`）

```
add_action/add_filter  → 入队（未注册前）
register()             → 置 registered=true，把队列一次性交给 WP
register() 之后调用     → 直接 add_action/add_filter 到 WP（不再入队）
```

这个"注册后立即生效"的行为被前台缩略图装配依赖（`register_frontend` 在 `after_setup_theme` 才执行，此时 loader 早已 register）。**改动 Hook_Loader 前必须确认这一点**，否则前台缩略图会静默失效。

### 3.5 后台路由：Registry → Route → Menu → UI → renderer

| 环节 | 位置 | 职责 |
|---|---|---|
| 声明 | `src/Admin/Registry.php:41-105` | 4 个顶级模块、13 个 tab、权限门、renderer key |
| 取页 id | `src/Admin/Route.php` | `page_id()` 把 module/tab 拼成 `image-rules` 这类稳定 id，供资源白名单与布局引擎使用 |
| 建屏 | `src/Admin/Menu.php:45-88` | `admin_menu` 建菜单与子菜单；旧 slug 在 `init`/`admin_page_access_denied` 上先跳转 |
| 分发 | `src/Admin/Menu.php:94-138` | `Route → 模块 caps → tab_gate → renderer`，缺 renderer 时渲染占位 |
| 外壳 | `src/Admin/Shell.php:33` | 页头、模块切换、tab 条 |
| 版式 | `src/Admin/Layout.php:59-178` | `const PAGES` 静态卡片定位表（11 个页面键，每卡一行列号与跨列） |
| 资源 | `src/Admin/Page_Assets.php:47` | 按 `Route::current_page()` 加载 tokens/base/components + 模块与分页 CSS、脚本 |
| 渲染 | 各模块 `register_renderer(key, callable)` | 真正的页面内容，正文一律由 `UI::page()` 开壳 |

**四个模块与 13 个 tab**（`src/Admin/Registry.php:41-105`）：

| 模块（slug） | 能力要求 | tab |
|---|---|---|
| 系统设置 `sakura-tools` | `manage_options` | settings（环境自检） |
| 图片优化 `sakura-tools-image` | `manage_options` | rules · thumb · backup · stats |
| 对象存储 `sakura-tools-storage` | `manage_options` | connection · sync · deletes |
| 文件空间 `sakura-tools-files` | `review` | settings · users(gate:execute) · queue(execute) · log(execute) · zibll(gate:zibll+manage) |

权限门有三种：`manage_options` 直接放行、`review`→`UserFiles\Capabilities::can_review()`、`execute`→`can_execute()`（`src/Admin/Registry.php:257` 的 `tab_gate()`、`:309` 的 `tab_meta()`、`:323` 的 `tab_can()`）。`legacy()` 把 6 个旧 slug（如 `sakura-tools-optimize`）重定向到新路由，**验证 §10.3 里出现的那 1 次 `page=sakura-tools-optimize` 就是这条跳转链**。

---

## 4. 子系统 A：图片优化管线（includes/ + src/Image/）

### 4.1 职责与边界

**一句话**：在文件落盘之后、S3 推送与缩略图生成之前，用 Imagick/GD 就地压缩或转换上传图片；候选文件不小于原图时保留原图；并在同一请求内压缩子尺寸、写回备份与统计。

三条硬边界（`src/Image/Upload_Handler.php:115-185`、`includes/class-image-processor.php:138-159`）：

| 边界 | 实现 | 依据 |
|---|---|---|
| 只处理 `image/*` 且总开关开启 | `handle_upload` 前置判定 | `src/Image/Upload_Handler.php:122-132` |
| 必须在 S3 与缩略图之前 | `wp_handle_upload` 用 `PHP_INT_MAX`；`wp_generate_attachment_metadata` 用 `99` | `includes/class-image-processor.php:149`、`:156` |
| 动图不处理 | 文件头识别后按 skipped 返回 | `includes/class-animation-detector.php:28-57`、`src/Image/File_Processor.php:131` |

> 为什么要抢在 S3 之前：本插件的 S3 模块在 `wp_update_attachment_metadata` 上以 `PHP_INT_MAX` 执行最终上传（`src/S3/Storage.php:140`、`:191`），子尺寸压缩在 `wp_generate_attachment_metadata@99` 完成（`includes/class-image-processor.php:156`）。如果图片处理排在后面，推到对象存储的会是未压缩版本；主文件也必须在 `wp_handle_upload@PHP_INT_MAX` 完成替换（`:149`），否则后续 metadata 记录的还是旧文件。
>
> 注：`includes/class-image-processor.php:144-148` 的注释仍按旧的 S3 uploads 钩子描述；1.25.0 的 S3 模块实际不使用 `wp_handle_upload`，以 `src/S3/Storage.php:140` 为准。

### 4.2 类清单（`src/Image/`）

| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Crop_Config_Scanner` | src/Image/Crop_Config_Scanner.php:34 |  | 7 / 25 | 5 / 3 |  | 汇总 WordPress 已知的全部图片尺寸 |
| `Engine_Manager` | src/Image/Engine_Manager.php:23 |  | 1 / 1 | 8 / 4 |  | 优先选用 Imagick，不可用时回退 GD |
| `File_Processor` | src/Image/File_Processor.php:21 |  | 7 / 11 | 12 / 9 |  | 判定文件可处理性、适用选项与胜出引擎 |
| `Metadata_Processor` | src/Image/Metadata_Processor.php:25 |  | 4 / 9 | 6 / 11 |  | 处理子尺寸并写入上传统计与备份元数据 |
| `No_Gain_Guard` | src/Image/No_Gain_Guard.php:20 |  | 2 / 2 | 6 / 0 |  | 丢弃未变小的重编码结果 |
| `Restore_Service` | src/Image/Restore_Service.php:27 |  | 3 / 6 | 10 / 7 |  | 原地还原已转换的附件 |
| `Size_Catalog` | src/Image/Size_Catalog.php:22 |  | 4 / 5 | 4 / 0 |  | 统一列出 WordPress 可生成的图片尺寸 |
| `Upload_Backup` | src/Image/Upload_Backup.php:22 |  | 4 / 4 | 5 / 7 |  | 转换前保存原图并显式报告失败 |
| `Upload_Handler` | src/Image/Upload_Handler.php:23 |  | 7 / 7 | 5 / 18 |  | 串联重命名、备份、转换、替换与子尺寸元数据 |
| `Upload_Renamer` | src/Image/Upload_Renamer.php:21 |  | 5 / 7 | 5 / 5 |  | 在上传移动前后套用重命名规则 |

旧层门面（不在上表内，位于 `includes/`，完整表见附录 D）：

| 类 | 文件:行 | 在管线中的角色 |
|---|---|---|
| `Image_Processor` | `includes/class-image-processor.php:39` | 唯一钩子适配门面，把 7 个 WP 钩子转给 `Upload_Handler` / `File_Processor` / `Metadata_Processor` / `Restore_Service` |
| `Imagick_Handler` / `GD_Handler` | `includes/class-imagick-handler.php:16` / `includes/class-gd-handler.php:16` | 两个编码引擎，负责临时文件 → 体积守卫 → 原子替换 |
| `File_System` | `includes/class-file-system.php:14` | 受控 copy/move/占位/删除（不用 `@` 抑制错误） |
| `Backup` | `includes/class-backup.php` | 原图备份、还原、占用统计 |
| `Stats` | `includes/class-stats.php` | 聚合统计（option）+ 每附件 10 条明细（postmeta） |
| `Animation_Detector` | `includes/class-animation-detector.php:20` | 只读文件头识别 GIF/APNG/WebP 动图 |
| `Image_Optimizer_Module` | `includes/class-image-optimizer-module.php:23` | 设置页、裁剪扫描 AJAX、重置统计 |

### 4.3 关键方法

| 方法 | 文件:行 | 职责 | 调用关系 |
|---|---|---|---|
| `Image_Processor::register_hooks` | `includes/class-image-processor.php:138-159` | 注册上传/命名/元数据/删除共 7 个钩子 | WP 内核触发 → 转发给内部服务 |
| `Upload_Handler::handle_upload` | `src/Image/Upload_Handler.php:115-185` | 主链路编排：跳过判定 → 改名 → 备份 → 转换 → 替换 upload 数组 → 登记待写记录 | 被 `Image_Processor` 调用；依赖 Renamer/Backup/File_Processor |
| `Upload_Backup::store` | `src/Image/Upload_Backup.php:71-83` | 备份原图；失败则删掉落盘文件并返回 false | → `Backup::store`（`includes/class-backup.php:161-210`） |
| `File_Processor::process_file` | `src/Image/File_Processor.php:54-86` | 跳过判定 + 组 quality/format + 派发引擎 | → `Engine_Manager::process` |
| `File_Processor::get_processing_skip_reason` | `src/Image/File_Processor.php:110-136` | 5 类跳过原因：unsupported / too_large_file / too_large_resolution / too_large / animated | 被 `process_file`、`handle_upload` 共用 |
| `Engine_Manager::process` | `src/Image/Engine_Manager.php:32-77` | Imagick 优先、GD 兜底，聚合 skipped 与最后的 error | → 两个 Handler |
| `No_Gain_Guard::should_keep_original` | `src/Image/No_Gain_Guard.php:39-58` | 候选 ≥ 原图即保留原图（任一为 0 视为可替换） | 被两个 Handler 调用 |
| `Metadata_Processor::process` | `src/Image/Metadata_Processor.php:110-163` | 逐子尺寸压缩（20 秒预算），写回 `sizes[k]['file']` 与 mime | 挂在 `wp_generate_attachment_metadata` |
| `Metadata_Processor::consume_pending` | `src/Image/Metadata_Processor.php:283-295` | 写统计明细/聚合与 `_sakura_imgopt_backup` | → `Stats::record`、`Backup::save_meta` |
| `Restore_Service::restore` | `src/Image/Restore_Service.php:78-186` | 备份写回原图 + 重建缩略图，失败回滚 | 被 `Image_Processor` 与媒体库 AJAX 调用 |

### 4.4 钩子（含优先级）

| 钩子 | 类型 | 优先级 | 回调 | 注册位置 |
|---|---|---:|---|---|
| `wp_handle_upload_prefilter` | filter | — | `pre_upload_rename` | `includes/class-image-processor.php:139` |
| `wp_handle_sideload_prefilter` | filter | — | 同上 | `:140` |
| `wp_insert_attachment_data` | filter | — | 标题同步 | `:142` |
| `wp_handle_upload` | filter | `PHP_INT_MAX` | `handle_upload` | `:149` |
| `wp_handle_sideload` | filter | `PHP_INT_MAX` | 同上 | `:150` |
| `wp_generate_attachment_metadata` | filter | 99 | `process_metadata` | `:156` |
| `delete_attachment` | action | — | 清理 | `:158` |
| `admin_post_sakura_imgopt_reset_stats` / `wp_ajax_sakura_imgopt_scan_crop` / `wp_ajax_sakura_imgopt_stats_page` | action | — | 设置页与统计 | `includes/class-image-optimizer-module.php:72-74` |

主动触发（供外部系统接入）：`sakura_imgopt_metadata_budget_exhausted`（`src/Image/Metadata_Processor.php:139`）、`sakura_imgopt_restore_started` / `sakura_imgopt_restored`（`src/Image/Restore_Service.php:149`、`:175`）。其中 `sakura_imgopt_metadata_budget_exhausted` 在本站没有监听者（§10.6）。

### 4.5 数据落点

- **option `sakura_imgopt_*`**（默认值与清洗见 `src/Settings/Settings_Repository.php:68-87`）：`enable_compression`、`compression_mode`、`convert_format`、`source_formats`、`quality`（默认 82）、`max_file_size_mb`（24）、`max_image_width`（3840）、`max_image_height`（2160）、`backup_enabled`（1）、`backup_retention_days`、`backup_max_mb`、`rename_enabled`、`rename_rule`、`disabled_sizes`、`batch_size`、`ajax_delay`、`upload_concurrency`、`delete_data_on_uninstall`。
- **option `sakura_imgopt_stats`**：聚合计数（`includes/class-stats.php:118-136`），shutdown 时批量落盘（`:150-157`）。
- **option `sakura_imgopt_crop_scan` / `sakura_imgopt_crop_scan_names`**：裁剪配置扫描结果（`src/Image/Crop_Config_Scanner.php:114-121`）。
- **postmeta `_sakura_imgopt_backup`** = `{backup_rel, origin_rel, converted_rel, time}`（`includes/class-backup.php:237-246`）。
- **postmeta `sakura_imgopt_log`**：每附件最多 10 条明细（`includes/class-stats.php:24`、`:96-108`）。
- **目录 `uploads/sakura-imgopt-backup/{年}/{月}/`**：含 `index.php` 与 `.htaccess`（`Require all denied` + Apache 2.2 兼容分支；`includes/class-backup.php:38-39`、`:125-153`）。**`.htaccess` 只对 Apache 生效**；Nginx/Caddy/IIS/PHP 内置服务器必须在站点层显式 deny（`src/Backup/Exposure_Probe.php:5-7`）。后台探测用 `wp_upload_dir()['baseurl']` 拼 URL（`:64`、`:89`），若 `upload_url_path` 配错会探测到错误路径而误报 `protected`；以实际公网 URL 为准（§4.7 第 5 条）。
- **transient**：`sakura_imgopt_backup_usage`（5 分钟缓存，`includes/class-backup.php:323`）；媒体库批处理队列 `sakura_queue_{user_id}`（1 小时，`includes/class-media-library-module.php:337-349`）。
- **本子系统不建表**。

### 4.6 一次上传的完整链路

```
① 改名：wp_handle_upload_prefilter → Upload_Renamer::pre_upload_rename          src/Image/Upload_Renamer.php:71-90
② 落盘后：handle_upload 判定「还原中 / 总开关关 / 非图片 / 预检命中」→ 原样返回   src/Image/Upload_Handler.php:118-139
③ 补改名：ensure_uploaded_file_renamed                                         src/Image/Upload_Handler.php:139
④ 备份：Backup::store 失败 → 写 $upload['error'] 并中止（WP 转 WP_Error）        src/Image/Upload_Handler.php:141-145
⑤ 转换：File_Processor → Engine_Manager → Handler
        临时文件 → No_Gain_Guard 比较体积 → 未变小则删临时文件返回 skipped        includes/class-imagick-handler.php:260-271
⑥ 替换：dest≠source 用 move_to_unique 占位改名；原地用 move（失败 copy+delete）  includes/class-imagick-handler.php:273-285
⑦ 元数据：wp_generate_attachment_metadata(99) 逐子尺寸转换 → 写回 → flush 统计   src/Image/Metadata_Processor.php:133-160
```

### 4.7 边界与风险（阅读代码时容易踩的点）

1. **"不变小就保留原图"是唯一的质量底线**。`No_Gain_Guard` 里"任一为 0 视为可替换"是个例外分支，意味着拿不到体积信息时会覆盖原图（`src/Image/No_Gain_Guard.php:39-58`）。
2. **子尺寸压缩有 20 秒预算**，超时只记 skipped 并 `break`，本请求不补做、也没有后续队列（`src/Image/Metadata_Processor.php:136-141`、`:193-212`）。大相册站点的首张上传可能只压了一部分尺寸。
3. **统计与备份 meta 依赖"待写记录"匹配**：待写记录以最终文件路径为键（`src/Image/Metadata_Processor.php:99-101`），flush 时先精确匹配、再匹配 `-scaled` 变体，只有在"恰好剩 1 条记录且文件名同干"时才走启发式兜底（`:222-251`）。**并发上传不会命中这个兜底分支**：`pending_records` 是实例属性（`:32`），每个 HTTP 请求都会重新构造一份 `Metadata_Processor`（`src/Image/Upload_Handler.php:72`），进程之间不共享；`async-upload.php` 一次也只处理一个文件。2026-11-08 在测试站用本地 MinIO 做 5 张并发上传，5/5 的 `_sakura_imgopt_backup`、`sakura_imgopt_log`、S3 同步记录齐全（§10.6）。真正会丢 meta 的是"同一进程一次处理多张、路径又都对不上"的构造场景，标准媒体库上传路径不可达。
4. 备份写入失败会中止整条链路（`src/Image/Upload_Handler.php:141-145`），这是刻意的"宁可不压，也不丢原图"。
5. **备份目录的 `.htaccess` 不是跨服务器保证**。插件只写 Apache 规则；Nginx/Caddy/IIS/PHP 内置服务器需站点配置显式 deny（`src/Backup/Exposure_Probe.php:5-7`）。2026-11-10 在测试站（`/root/sio-e2e`）复核：`.htaccess` 内容为 `Require all denied`，但 `https://demo.77il.cn/wp-content/uploads/sakura-imgopt-backup/2026/11/backup-test.png` 返回 HTTP 200；同一次探测却报 `protected`，因为站点 `upload_url_path=https://demo.77il.cn` 使探测 URL 变成 `https://demo.77il.cn/sakura-imgopt-backup/...`，打到了不存在的路径。生产 nginx 已在站点配置显式 `location ^~ /wp-content/uploads/sakura-imgopt-backup/ { return 403; }`（`/www/server/panel/vhost/nginx/www.nboke.com.conf:136-140`），但这是部署配置，不是插件保证。

### 4.8 待确认（已清零）

- 无。原两条已核实关闭：并发上传未复现 `consume_pending` 匹配失败；`sakura_imgopt_metadata_budget_exhausted` 在本站无外部消费方。证据与边界见 §10.6。

---

## 5. 子系统 B：缩略图 URL 重写（src/Thumbnail/ + includes 装配层）

### 5.1 职责与边界

**一句话**：把 WordPress 生成的附件图 URL 换成对象存储的实时缩放语法（如 `!style=...` 或 `?w=&h=`），**不生成缩略图、不改文件、不建表**。

它回答三个问题，三个答案分别由三个类负责：

| 问题 | 负责类 | 依据 |
|---|---|---|
| 要不要改？改成什么？ | `Policy` | `src/Thumbnail/Policy.php:67` |
| 怎么拼字符串？ | `Provider`（`Bitiful` / `Generic`） | `src/Thumbnail/Providers/Bitiful.php:83`、`src/Thumbnail/Providers/Generic.php:81` |
| 什么时候插入？ | `Decorator` | `src/Thumbnail/Decorator.php:109`、`:275` |

### 5.2 类清单（`src/Thumbnail/`）

| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Bitiful` | src/Thumbnail/Providers/Bitiful.php:24 | implements Provider | 5 / 7 | 8 / 3 |  | 构建 Bitiful 命名样式或处理参数 URL |
| `CLI` | src/Thumbnail/CLI.php:17 |  | 5 / 5 | 1 / 9 |  | 提供 wp sakura-thumb 缩略图命令 |
| `Context` | src/Thumbnail/Context.php:18 |  | 7 / 7 | 10 / 0 |  | 判断图片所在的循环与页面位置 |
| `Decorator` | src/Thumbnail/Decorator.php:23 |  | 8 / 18 | 9 / 16 |  | 按上下文与尺寸策略重写附件图片 URL |
| `Generic` | src/Thumbnail/Providers/Generic.php:24 | implements Provider | 5 / 5 | 1 / 2 |  | 展开用户自定义的缩略图后缀模板 |
| `Policy` | src/Thumbnail/Policy.php:18 |  | 4 / 5 | 10 / 4 |  | 不依赖全局状态解析目标尺寸 |
| `Probe` | src/Thumbnail/Probe.php:24 |  | 2 / 11 | 2 / 9 |  | 用真实 HEAD 请求探测桶支持的 URL 语法 |
| `Provider` | src/Thumbnail/Provider.php:21 |  | 4 / 4 | 5 / 1 |  | 把目标尺寸转换为服务商特定的图片 URL |
| `Provider_Status` | src/Thumbnail/Provider_Status.php:17 |  | 5 / 5 | 9 / 0 |  | 保存缩略图探测结果的值对象 |
| `Rendition` | src/Thumbnail/Rendition.php:21 |  | 8 / 8 | 18 / 0 |  | 描述一次目标图片尺寸与裁剪模式 |
| `Settings` | src/Thumbnail/Settings.php:22 |  | 26 / 31 | 29 / 9 |  | 统一读写 sakura_imgopt_oss_thumb 设置 |
| `Size_Catalog` | src/Thumbnail/Size_Catalog.php:17 |  | 3 / 3 | 0 / 0 |  | 为设置表与 CLI 列出核心及注册尺寸 |
| `Stats` | src/Thumbnail/Stats.php:17 |  | 3 / 5 | 9 / 1 | 1 | 按请求缓冲缩略图计数并在关闭时落盘 |
| `Theme_Bridge` | src/Thumbnail/Theme_Bridge.php:21 |  | 2 / 2 | 3 / 4 |  | 读取选项时重写 zibll 的备用缩略图 URL |
| `Theme_Cache` | src/Thumbnail/Theme_Cache.php:23 |  | 1 / 5 | 3 / 8 |  | 守卫主题的 post_thumbnail_url 缓存 |

装配层（`includes/`）：`Thumbnail_Optimizer_Module`（`includes/class-thumbnail-optimizer-module.php:32`）是唯一装配点，把上下文栈、装饰器、主题桥、主题缓存、设置页、探测表单一次性挂到 Hook_Loader；`Thumbnail_Manager`（`includes/class-thumbnail-manager.php:20`）只回答"哪些尺寸不要生成"，不改 URL。

### 5.3 关键方法

| 方法 | 文件:行 | 职责 |
|---|---|---|
| `Decorator::filter` | `src/Thumbnail/Decorator.php:109` | `wp_get_attachment_image_src` 入口，单例后转 `apply()` |
| `Decorator::apply` | `src/Thumbnail/Decorator.php:275` | 闸门链：active → S3 未同步则跳过 → URL 资格 → Context → Policy → Provider → memo → Stats |
| `Policy::resolve` | `src/Thumbnail/Policy.php:67` | 上下文/场景/overrides/sizes/style → `Rendition`，含 unknown 回退与放大拒绝 |
| `Policy::scene_size` | `src/Thumbnail/Policy.php:163` | 按场景把请求尺寸换成配置尺寸 |
| `Context::current` | `src/Thumbnail/Context.php:61` | 静态栈顶优先，否则按 WP 条件推断 archive/singular/sidebar/other |
| `Settings::resolve_mode` | `src/Thumbnail/Settings.php:867` | 由配置 + 探测结果算 style/params/native/none |
| `Settings::make_provider` | `src/Thumbnail/Settings.php:968` | 按 S3 白名单 provider 造 `Bitiful`（bitiful）或 `Generic`（其余）；取值链见 §5.7.1 |
| `Thumbnail_Manager::apply_size_filters` | `includes/class-thumbnail-manager.php:34` | 把 `disabled_sizes` 转成 3–4 个 `PHP_INT_MAX` 过滤器 |
| `Theme_Cache::guard` | `src/Thumbnail/Theme_Cache.php:33-121` | `the_post` 时修正 zibll 主题缓存的缩略图 URL |
| `Probe` | `src/Thumbnail/Probe.php:24` | 抽样 HEAD 验证前缀是否被 CDN 接受 |

### 5.4 钩子

| 钩子 | 类型 | 优先级 | 注册位置 |
|---|---|---:|---|
| `wp_get_attachment_image_src` | filter（4 参数） | 20 | `includes/class-thumbnail-optimizer-module.php:69` |
| `wp_content_img_tag` | filter（3 参数） | 20 | `:70` |
| `sakura_imgopt_spare_thumb` | filter | 20 | `:71` |
| `option_zibll_options` | filter | 20 | `:72` |
| `update_option_sakura_imgopt_oss_thumb` | action（2 参数） | 10 | `:73` |
| `the_post` / `loop_start` / `loop_end` / `wp` / `dynamic_sidebar_before` / `dynamic_sidebar_after` | action | 1 | `:62-67` |
| `template_redirect` | action | 0 | `:52`（仅 HTML 旧引擎） |
| `wp_ajax_ajax_widget_ui` / `wp_ajax_nopriv_ajax_widget_ui` | action | 0 | `:53-54`（仅 HTML 旧引擎，侧栏 AJAX 片段） |
| `init` | action | 10 | `includes/class-thumbnail-manager.php:28` |

### 5.5 数据落点

| 键 | 类型 | 说明 | 依据 |
|---|---|---|---|
| `sakura_imgopt_oss_thumb` | option（autoload） | 主配置：provider、mode、style、尺寸映射、场景开关 | `src/Thumbnail/Settings.php:24`、`:525` |
| `sakura_imgopt_thumb_probe` | option | 最近一次探测结果 | `src/Thumbnail/Settings.php:840` |
| `sakura_imgopt_thumb_stats` | option（no autoload） | 按日桶统计 rewrite/skip/fallback | `src/Thumbnail/Stats.php:98-105` |
| `sakura_imgopt_thumb_cache_flush` | option（no autoload） | 主题缓存刷新报告 | `src/Thumbnail/Settings.php:679` |
| `sakura_imgopt_thumb_probe_reset` | transient（300s） | 探测后置位，下次渲染出提示 | `includes/class-thumbnail-optimizer-module.php:318` |

### 5.6 一次取图的判定顺序（`Decorator::apply`）

```
1 active()            总开关 / engine=url / mode / host / 非后台 / REST 开关     Decorator.php:276-289
2 S3 同步未完成 → 跳过（避免回源 404）                                          Decorator.php:330
3 is_eligible_url()   host / 扩展名 / 已优化过 / 签名 URL                       Decorator.php:384、:446
4 Context::current()  页面上下文                                                Context.php:61
5 Policy::resolve()   full_cap → 上下文开关 → scene_size → overrides/sizes → style
                      → is_usable → provider_mode → guard_upscale               Policy.php:67-147
6 Provider::decorate() 拼串                                                    Decorator.php:308
7 memo 去重 + Stats::record()                                                  Decorator.php:308-322
```

### 5.7 provider 取值与 HTML 旧引擎（2026-11-08 核实）

原「待确认」两条已用静态代码 + 测试站运行期证据关闭，验证记录见 §10.7。

#### 5.7.1 缩略图 provider 的取值链

**结论**：provider 是 S3 设置白名单里的键，缩略图侧只读不写；工厂只有两个实现类——`bitiful` 特殊，其余全部走 `Generic`。

| 环节 | 位置 | 行为 |
|---|---|---|
| 枚举（唯一来源） | `src/S3/Providers.php:20-33` | 10 个键：cloudflare、minio、dogecloud、aliyun、tencent、qiniu、upyun、baidu、backblaze、bitiful |
| 存在性判断 | `src/S3/Providers.php:44-50` | `exists()` 与 `all()` 用同一列表 |
| 默认值 | `src/S3/Options.php:100` | `provider => 'cloudflare'` |
| 保存校验 | `src/S3/Settings_Saver.php:55-58` | `sanitize_key` 后不在 `Providers::all()` 内一律强制回 `cloudflare` |
| 后台下拉 | `src/S3/S3_Storage_Page.php:168` | 选项由 `Providers::all()` 渲染 |
| 生效值 | `src/Thumbnail/Settings.php:760-795` | 先读 `s3_options.provider`，活动 profile 的 `provider` 覆盖，再 `sanitize_key` 存入 `s3_config()` |
| 取值入口 | `src/Thumbnail/Settings.php:819-822` | `provider_id()` 只返回 `s3_config()['provider']` |
| 工厂 | `src/Thumbnail/Settings.php:968-979` | `bitiful` → `Bitiful(mode)`；其余 → `Generic(custom_provider_template)` |

补充事实：

- 缩略图自己的 `sakura_imgopt_oss_thumb.provider`（默认 `auto`，`src/Thumbnail/Settings.php:110`；保存时 `:277`）**全仓无读取点**，是遗留死字段。运行期把它改成 `bitiful`、而 S3 profile 仍是 `minio` 时，`provider_id()` 返回 `minio`、工厂返回 `Generic`（§10.7 第 11 条）。
- 全 `src/Thumbnail/` 只有 `sakura_imgopt_opcache_reset` 一个 `apply_filters`（`src/Thumbnail/Settings.php:695`），provider 没有第三方扩展点；新增供应商要同时改 `Providers::all()` 与 `make_provider()`。
- 因此「其余取值来源待确认」的答案是：来源只有 S3 白名单，白名单外的值在保存时被改写为 `cloudflare`；只有直接改数据库才能让未知值进入 `provider_id()`，此时工厂落到 `Generic`。

#### 5.7.2 HTML 旧引擎的缓冲边界

**结论**：`engine='html'` 时插件只开两个缓冲——整页 `template_redirect@0`（`includes/class-thumbnail-optimizer-module.php:120`）与侧栏 AJAX `wp_ajax(_nopriv)_ajax_widget_ui@0`（`:140`）；两个回调都经 `filter_scoped_html()`（`:170`）进入 `Html_Filter::filter()`（`src/Oss/Html_Filter.php:43`）。原文把 `:99`、`:128` 当作「开启点」不准确——那两行是 engine 判断，真正的 `ob_start` 在 `:120` 与 `:140`。

| 步骤 | 位置 | 条件/行为 |
|---|---|---|
| 注册 | `includes/class-thumbnail-optimizer-module.php:52-54` | `template_redirect@0`、`wp_ajax_ajax_widget_ui@0`、`wp_ajax_nopriv_ajax_widget_ui@0` |
| 整页闸门 | `:97-121` | engine=html（`:99`）→ `can_filter()`（`:103`）→ 非 `is_admin`（`:107`）→ 非 `is_feed`（`:110`）→ 非 `is_trackback`（`:113`）→ 非 `wp_doing_ajax`（`:116`）→ `ob_start`（`:120`） |
| 侧栏闸门 | `:126-141` | engine=html（`:128`）→ `can_filter()` 且 `scopes.sidebar` 非空（`:132`）→ 请求的 widget 确在侧栏（`:136`）→ `ob_start`（`:140`） |
| 侧栏判定 | `:186-205` | 读 `$_REQUEST['id']`/`['index']` 拼 `id-index`，在 `wp_get_sidebars_widgets()` 中找「侧栏名含 sidebar 且包含该 widget」 |
| 实际门槛 | `:177-181` + `Settings::legacy_options()`（`src/Thumbnail/Settings.php:986-1035`） | `legacy_options()` 强制 `enabled ← v2 enabled`、`oss_host ← media_host()`、`sidebar_enabled ← scopes.sidebar`（`src/Thumbnail/Settings.php:1022-1025`），旧 `legacy.enabled/oss_host` 存什么都不生效；真正条件是 v2 `enabled=1` 且 `s3 upload_url_path` 能解析出 host |
| 回调 | `:149-172` | `filter_list_html(force=false)` / `filter_sidebar_ajax_html(force=true)` → `new Html_Filter(Settings::legacy_options())->filter($html, $force)` |
| 解析与改写 | `src/Oss/Html_Filter.php:43-156` | host 为空或全文不含 host 直接返回（`:46`）；需 `WP_HTML_Processor`（`:50`）；有 `<html`/`<!doctype` 走整页解析，否则片段（`:54-57`）；按 sidebar/row/post/thumb 类名维护作用域栈；`force_sidebar` 时绕过作用域全量改写 |
| 改写目标 | `src/Oss/Html_Filter.php:181-207` | `IMG` 的 src/data-src/switch-src/white-src/dark-src、`IMG`/`SOURCE` 的 srcset/data-srcset、`VIDEO` 的 poster；suffix 由 `Url_Rewriter::build_scope_suffix()`（`src/Oss/Url_Rewriter.php:155`）生成，形如 `?w=250&h=250&mode=crop&pos=auto` |
| 与 url 引擎互斥 | `src/Thumbnail/Theme_Cache.php:44`、`src/Thumbnail/Decorator.php:331` | 两者都要求 `engine==='url'`，html 模式下 URL 装饰整体让位 |

**边界与副作用**（维护时必须知道）：

- **同域静态资源会被一起改写**：`Html_Filter` 按 host 匹配，不区分是否上传目录。测试站首页的主题占位图 `https://demo.77il.cn/wp-content/themes/zibll/img/thumbnail.svg` 就被加上 `?w=250&h=250&mode=crop&pos=auto`（§10.7 第 1–2 条）。生产若开 html 模式，同域主题/插件图片都会带参数。
- 改写结果里 `src` 与 `data-src` 的 `&` 实体形式不同（`&#038;` vs `&amp;`），是 `WP_HTML_Processor` 按属性重新序列化的结果，浏览器等价。
- 依赖 `WP_HTML_Processor`（WP 6.4+）；不存在时 `filter()` 原样返回（`src/Oss/Html_Filter.php:50`）。
- `is_trackback` 分支未在运行期触发（不构造 trackback 请求就无法走到），只有代码位置；其余四个闸门都有运行期对照（§10.7）。

---

## 6. 子系统 C：对象存储（src/S3/ + includes 装配层）

### 6.1 职责与边界

**一句话**：把媒体库附件在**产生它的同一请求内**同步到 S3 兼容对象存储（`src/S3/Synchronous_Uploader.php:180`），用 postmeta 记附件状态（`src/S3/Sync_Status.php:25-49`）、用自定义表做远端删除补偿（`src/S3/Delete_Jobs.php:109`），并提供只读同步中心、Prometheus 指标与 webhook 告警（`src/S3/Prometheus.php:137`、`src/S3/Alerting.php:244`）。

这也是全插件**唯一接触凭据**的子系统：`s3_options` 里的 key/secret 以 `sio1s:` 前缀密文存储（`src/S3/Credential_Cipher.php:21`，实测 `Credential_Cipher` roundtrip 通过，见 §10.5）。

### 6.2 类清单（`src/S3/`，37 个）

`| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Alerting` | src/S3/Alerting.php:24 | implements Module | 16 / 17 | 8 / 5 | 5 | 评估上传队列健康度并按需推送 JSON webhook |
| `Client_Factory` | src/S3/Client_Factory.php:20 |  | 2 / 2 | 8 / 5 |  | 按选项构建并缓存 AWS SDK 客户端 |
| `Config_Notice` | src/S3/Config_Notice.php:35 |  | 6 / 10 | 6 / 3 |  | 用居中弹窗提示 S3 配置未完成，可按用户稍后再看 |
| `Connection_Tester` | src/S3/Connection_Tester.php:11 |  | 2 / 3 | 2 / 7 |  | 处理后台提交的 S3 连接测试 |
| `Credential_Cipher` | src/S3/Credential_Cipher.php:21 |  | 5 / 7 | 12 / 1 |  | 加密写入 wp_options 的存储凭据 |
| `Delete_Jobs` | src/S3/Delete_Jobs.php:22 |  | 22 / 22 | 23 / 0 |  | 为首次删除失败的对象保存持久删除任务 |
| `Delete_Queue_Panel` | src/S3/Delete_Queue_Panel.php:23 |  | 5 / 12 | 2 / 9 | 2 | 渲染删除队列页面并处理行内操作 |
| `Delete_Worker` | src/S3/Delete_Worker.php:20 | implements Module | 8 / 15 | 9 / 19 | 5 | 只排空删除队列中的远端删除任务 |
| `Error_Mapper` | src/S3/Error_Mapper.php:15 |  | 3 / 3 | 4 / 2 |  | 把 SDK 失败映射为稳定的 WordPress 错误码 |
| `History_Migrator` | src/S3/History_Migrator.php:19 |  | 8 / 15 | 5 / 11 | 1 | 按 ID 升序分批扫描并上传历史附件 |
| `Ignored_Paths` | src/S3/Ignored_Paths.php:20 |  | 4 / 4 | 4 / 0 |  | 保存同步扫描需跳过的额外路径前缀 |
| `Inventory` | src/S3/Inventory.php:22 |  | 1 / 1 | 2 / 0 |  | 把字节宽度换算为可读格式 |
| `Legacy_Snapshot` | src/S3/Legacy_Snapshot.php:24 |  | 3 / 5 | 3 / 7 |  | 无同步索引时构建缓存的本地远端快照 |
| `Log` | src/S3/Log.php:17 |  | 1 / 1 | 10 / 0 |  | 仅在 WP_DEBUG 开启时输出 S3 日志 |
| `Migration_Service` | src/S3/Migration_Service.php:17 |  | 2 / 2 | 2 / 0 |  | 以注入回调执行纯迁移循环 |
| `Monitoring_Panel` | src/S3/Monitoring_Panel.php:21 |  | 4 / 8 | 4 / 8 |  | 渲染监控与 webhook 表单并处理提交 |
| `Object_Store` | src/S3/Object_Store.php:19 |  | 8 / 11 | 8 / 11 |  | 以注入客户端执行无状态对象存储操作 |
| `Options` | src/S3/Options.php:19 |  | 23 / 25 | 83 / 4 |  | 规范化 s3_options 并处理旧版迁移与常量覆盖 |
| `Profile_Manager` | src/S3/Profile_Manager.php:23 |  | 2 / 3 | 3 / 5 |  | 按保存的配置档切换当前生效配置 |
| `Prometheus` | src/S3/Prometheus.php:25 | implements Module | 8 / 12 | 7 / 10 | 3 | 输出受令牌保护的 Prometheus 指标文档 |
| `Providers` | src/S3/Providers.php:9 |  | 2 / 2 | 8 / 0 |  | 列出受支持的 S3 兼容服务商 |
| `Remote_Operation_Guard` | src/S3/Remote_Operation_Guard.php:14 |  | 7 / 11 | 11 / 2 |  | 以无 TTL 预约守卫远端操作并要求显式对账 |
| `Restore_Plan` | src/S3/Restore_Plan.php:27 |  | 3 / 3 | 8 / 0 |  | 比对附件新旧对象键以生成还原计划 |
| `S3_Storage_Page` | src/S3/S3_Storage_Page.php:12 |  | 2 / 4 | 2 / 20 |  | 渲染 S3 设置页与只读跳转提示 |
| `Settings_Saver` | src/S3/Settings_Saver.php:12 |  | 5 / 8 | 4 / 16 |  | 处理 S3 设置的后台提交保存 |
| `Storage` | src/S3/Storage.php:22 | implements Module | 20 / 24 | 6 / 33 | 14 | 注册 S3 钩子并协调上传入队 |
| `Storage_Adapter` | src/S3/Storage_Adapter.php:20 |  | 13 / 18 | 34 / 13 |  | 把本地路径映射为对象键并经对象存储上传 |
| `Sync_Actions` | src/S3/Sync_Actions.php:20 |  | 5 / 5 | 4 / 10 |  | 对清单选定对象执行上传、下载或删除 |
| `Sync_Center_Module` | src/S3/Sync_Center_Module.php:29 | implements Module | 18 / 38 | 2 / 63 | 15 | 添加同步中心页面及只读扫描与导出端点 |
| `Sync_Center_Panel` | src/S3/Sync_Center_Panel.php:20 |  | 1 / 1 | 2 / 6 |  | 展示上传队列吞吐指标面板 |
| `Sync_Status` | src/S3/Sync_Status.php:23 |  | 23 / 23 | 30 / 5 |  | 用附件元数据保存同步状态、对象键与重试任务 |
| `Synchronous_Uploader` | src/S3/Synchronous_Uploader.php:26 |  | 7 / 13 | 16 / 12 |  | 在创建附件的请求内完成其全部文件上传 |
| `Upload_Capabilities` | src/S3/Upload_Capabilities.php:23 |  | 3 / 3 | 8 / 2 |  | 判定打包 SDK 能否并发多个 HTTP 请求 |
| `Upload_Metrics` | src/S3/Upload_Metrics.php:27 |  | 10 / 18 | 14 / 2 |  | 把上传结果逐条追加到事件表并按 SQL 聚合快照 |
| `Upload_Observer` | src/S3/Upload_Observer.php:20 | implements Module | 4 / 4 | 3 / 12 | 2 | 记录上传成功/失败事件并回写索引 MD5 |
| `Upload_URL` | src/S3/Upload_URL.php:9 |  | 2 / 2 | 7 / 0 |  | 接管上传目录与地址选项的改写生命周期 |
| `Upload_Verify` | src/S3/Upload_Verify.php:20 |  | 3 / 3 | 6 / 0 |  | 比对本地文件与 S3 对象上报的 ETag |`

装配层（`includes/`）：`S3_Storage_Module`（`includes/class-s3-storage-module.php:25`）负责后台三页中的 connection 页与全部 admin-post/AJAX 分发；`S3_CLI`（`includes/class-s3-cli.php:14`）只在 `WP_CLI` 时被 require；`S3_Doge_Cloud`（`includes/class-s3-doge-cloud.php:16`）是多吉云临时凭据换取。

### 6.3 关键方法

| 方法 | 文件:行 | 职责 | 调用关系 |
|---|---|---|---|
| `Options::__construct` | `src/S3/Options.php:50` | 读 `s3_options`、常量覆盖、逐字段解密 | 被 `Storage` 与 `Storage_Adapter` 构造时调用 |
| `Settings_Saver::save` | `src/S3/Settings_Saver.php:44` | 校验 provider/endpoint/upload_path、加密、归档旧 target 后写 option | ⇐ `admin_post_sakura_s3_save`（`includes/class-s3-storage-module.php:87`） |
| `Credential_Cipher::encrypt` / `decrypt` | `src/S3/Credential_Cipher.php:65` / `:102` | Sodium 优先、OpenSSL 回落；无前缀按旧明文处理 | `Settings_Saver.php:130-141`、`Options.php:77-89` |
| `Profile_Manager::switch_profile` | `src/S3/Profile_Manager.php:31` | 复制已存配置（密文原样）；启用时应用 profile 的 `upload_url_path`，停用时尝试还原 | ⇐ `admin_post_sakura_s3_switch` |
| `Synchronous_Uploader::finalize` | `src/S3/Synchronous_Uploader.php:180` | 版本比对 → 并发上传 → 写状态 → 条件删本地 | ⇐ `Storage.php:191`、`Sync_Center_Module.php:814` |
| `Storage::delete_remote_attachment` | `src/S3/Storage.php:212` | 附件删除时把仍存在的 key 入删除队列 | ⇐ `delete_attachment`（`src/S3/Storage.php:144`） |
| `Delete_Jobs::enqueue` | `src/S3/Delete_Jobs.php:188` | 幂等批量入队，dead 行可复活 | ⇐ `Storage.php:229` |
| `Delete_Jobs::start` / `fail` | `src/S3/Delete_Jobs.php:304` / `:388` | 租约领 claim；失败退避，8 次转 dead | ⇐ `Delete_Worker::process_delete`（`src/S3/Delete_Worker.php:157`） |
| `Delete_Worker::run` | `src/S3/Delete_Worker.php:102` | 20 秒 / 20 条上限内排空队列 | ⇐ cron `sakura_imgopt_s3_delete_tick`（`:50`） |
| `Object_Store::upload_file` | `src/S3/Object_Store.php:74` | 单部件 PUT 或 multipart，成功后 HEAD 校验 | ⇐ `Storage_Adapter::upload_file`（`:119`） |
| `Storage_Adapter::report_failure` | `src/S3/Storage_Adapter.php:71` | 把任一 key 的 `WP_Error` 统一转成失败事件，保证每次失败只上报一次 | ⇐ `upload_file`（`:119`）与 `upload_many`（`:159`）的所有失败分支 |
| `Upload_Observer::record_failed` | `src/S3/Upload_Observer.php:78` | 从 `WP_Error` 取 code/message，连同附件 ID、对象键、profile 写事件表 | ⇐ `sakura_imgopt_s3_upload_failed`（`:31`） |
| `Upload_Metrics::record_batch` / `snapshot` | `src/S3/Upload_Metrics.php:149` / `:204` | 每条结果独立 INSERT；快照用 SQL 聚合 totals/days 并合并旧 option 基线 | ⇐ `Upload_Observer`；⇒ 后台卡片、Prometheus、Alerting、`wp sakura-sync stats` |
| `Remote_Operation_Guard::execute` | `src/S3/Remote_Operation_Guard.php:61` | 逐 key 预约，失败转 uncertain 阻断后续 | 所有远端写入（`src/S3/Storage_Adapter.php:136`、`:179`、`:346`） |
| `Sync_Center_Module::ajax_action` | `src/S3/Sync_Center_Module.php:586` | 单条/批量 upload、download、delete、adopt 分发 | ⇐ `wp_ajax_sakura_s3_sync_action` |

> `switch_profile()` 只把 profile 里的 `upload_url_path` 原样写入 WP option，不校验它是否包含 `/wp-content/uploads` 前缀（`src/S3/Profile_Manager.php:67-91`、`src/S3/Upload_URL.php:15-30`）。若保存的 URL 少了前缀，附件 URL 会稳定指向错误路径；2026-11-10 复核时测试站的 `upload_url_path=https://demo.77il.cn` 即导致 `wp_get_attachment_url()` 返回 404，而 `/wp-content/uploads/...` 文件本身可访问。

### 6.4 入口钩子

| 钩子 | 类型 | 注册位置 |
|---|---|---|
| `admin_post_sakura_s3_save` / `_switch` / `_delete` | action | `includes/class-s3-storage-module.php:67-69` |
| `admin_post_sakura_s3_test` | action | `:70` |
| `admin_post_sakura_s3_alerts_save` / `_alerts_test` | action | `:71-72` |
| `admin_post_sakura_s3_prometheus_rotate` | action | `:73` |
| `wp_ajax_sakura_s3_retry` / `_upload_metrics` | ajax | `:74-75` |
| `admin_post_sakura_s3_delete_queue` | action | `src/S3/Delete_Queue_Panel.php:42` |
| `wp_ajax_sakura_s3_sync_{scan,scan_step,cancel,resume,page,test,action,refresh,toggle_index}` | ajax（9 个） | `src/S3/Sync_Center_Module.php:102-110` |
| `admin_post_sakura_s3_sync_export` / `_ignored_save` | action | `:112-113` |
| `add_attachment` / `wp_update_attachment_metadata` / `delete_attachment` | action / filter | `src/S3/Storage.php:138` / `:140` / `:144` |
| `sakura_imgopt_s3_uploaded` | action（成功观测） | `src/S3/Upload_Observer.php:30`（`Object_Store.php:182` 触发） |
| `sakura_imgopt_s3_upload_failed` | action（失败观测） | `src/S3/Upload_Observer.php:31`（`src/S3/Storage_Adapter.php:72` 触发） |
| `sakura_imgopt_s3_delete_tick`（60 秒） / `sakura_imgopt_s3_alert_check`（15 分钟） | cron | `src/S3/Delete_Worker.php:46-51`、`src/S3/Alerting.php:375-377` |
| `sakura_imgopt_metrics` | init / query var | `src/S3/Prometheus.php:27-31`、`:74-78` |
| `wp sakura-s3` | WP-CLI | `includes/class-s3-cli.php:599` |

### 6.5 数据落点

- **option `s3_options`**：`enabled`、`provider`、`bucket`、`region`、`endpoint`、key/secret（密文）、`upload_path`、`upload_url_path`、`profiles`、`retired_targets`、`active_profile_id`（`src/S3/Options.php:24`、`:97-114`；写入 `Settings_Saver.php:165-189`）。2026-11-08 的测试站快照为 `enabled=true`、`provider=minio`（§10.5 第 8/10 项）；2026-11-10 复核时已变为 11 键、`enabled=false`、`provider=cloudflare`、`upload_url_path=''`，不要把具体值当成固定环境。
- 其余 option：`sakura_imgopt_upload_concurrency`（`src/S3/Settings_Saver.php:106`）、`sakura_imgopt_sync_ignored`（`Ignored_Paths.php:25`）、`sakura_imgopt_s3_upload_metrics`（`Upload_Metrics.php:32`，**冻结基线，只读**；升级前的累计值原样保留用于合并，不再写入）、`sakura_imgopt_s3_upload_schema`（`Upload_Metrics.php:37`，事件表建表标记，实测 `2.0.0`）、`sakura_imgopt_s3_prometheus_token`（`Prometheus.php:26`，实测 32 hex）、`sakura_imgopt_s3_alerts`（`Alerting.php:26`）、`sakura_imgopt_s3_delete_schema`（`Delete_Jobs.php:32`，实测 `1.2.2`）、`sakura_imgopt_s3_migrate_state`（`History_Migrator.php:21`）、`upload_url_path` 与旧值 `sakura_imgopt_previous_upload_url_path`（`Upload_URL.php:11`）。
- **自定义表 3 张**：`{prefix}sio_s3_deletes`（`src/S3/Delete_Jobs.php:109`，建表 `:109-129`）、`{prefix}sio_sync_operations`（`src/S3/Remote_Operation_Guard.php:26-39`）、`{prefix}sio_s3_upload_events`（`src/S3/Upload_Metrics.php:52`，建表 `:79-115`）。
- **postmeta 8 个**：`_sakura_imgopt_s3_status` / `_error` / `_objects` / `_synced_at` / `_jobs` / `_target` / `_failures` / `_object_versions`（`src/S3/Sync_Status.php:25-49`）。
- **transient**：`sakura_imgopt_s3_snapshot_compat`（`Legacy_Snapshot.php:29`）、`sakura_imgopt_s3_alert_last`（`Alerting.php:32`）、`sakura_imgopt_doge_token_<md5(ak)>`（`includes/class-s3-doge-cloud.php:30`）。
- **退役清理**：option `sakura_imgopt_upload_mode`、cron `sakura_imgopt_s3_retry` / `_upload_watchdog` / `_upload_tick`（`src/S3/Storage.php:469`、`:477-478`、`Delete_Worker.php:58`）——1.20 的"异步上传队列"已被"同请求同步上传"取代。

### 6.6 四条流程

```
① 存配置  admin-post sakura_s3_save → Settings_Saver::save 校验(:44/:56/:71/:108)
          → Credential_Cipher::encrypt(:130-141) → 写入 profiles + active_profile_id(:165-187)
          → update_option + Upload_URL::apply(:189-194)
② 上传    add_attachment 仅 mark_pending(Storage.php:138/:173)
          → wp_update_attachment_metadata 调 finalize(:140/:191)
          → manifest 与 target_hash 比对(Synchronous_Uploader.php:93/:488)
          → Remote_Operation_Guard::execute 批量上传(Storage_Adapter.php:159/:179)
          → Object_Store 写入 + HEAD 校验(Object_Store.php:74/:158)
          → do_action sakura_imgopt_s3_uploaded(:182) → Upload_Observer 写索引与指标
③ 删除    delete_attachment 读 status->objects(Storage.php:212-229)
          → Delete_Jobs::enqueue 幂等入表(:188) → Delete_Worker::kick 触发 cron(:232)
          → process_delete 领租约；附件非 SYNCED 则 postpone 300 秒(:163/:171)
          → 失败按 60 秒起步指数退避(Delete_Jobs.php:388/:73)
④ 观测    成功 sakura_imgopt_s3_uploaded(Object_Store.php:182) → record_uploaded(:42) → record_batch(:149) INSERT
          失败 Storage_Adapter 各 WP_Error → report_failure(:71) → sakura_imgopt_s3_upload_failed(:72)
          → record_failed(:78) → record_batch INSERT
          → snapshot 用 SQL 聚合事件表(:204) + 旧 option 基线 → Prometheus::format(:107/:135)
          → Alerting 每 15 分钟评估 14 天失败率(:152/:375)
```

### 6.7 设计取舍（为什么这样写）

| 取舍 | 原因（代码/注释） |
|---|---|
| **同请求同步上传**，不用后台队列 | 1.20 之前是 shutdown 兜底 + 队列，用户反馈"上传成功但图挂了"；现在在 `wp_update_attachment_metadata` 里同步完成（`src/S3/Storage.php:191`），失败才落 `_sakura_imgopt_s3_status` |
| **远端删除走补偿表** | 删除请求不能因为 S3 超时而失败，所以先入 `sio_s3_deletes` 再由 cron 排空（`src/S3/Delete_Jobs.php:22`） |
| **远端写入前有准入表** | `Remote_Operation_Guard` 逐 key 预约，避免"同一对象并发上传/删除"导致的乱序（`src/S3/Remote_Operation_Guard.php:14`） |
| 凭据密文、profile 可切换 | `Sodium` 优先、`OpenSSL` 回落；切换 profile 时密文原样复制，不重新加密（`src/S3/Profile_Manager.php:31`） |
| **指标写入用追加事件表**，不用 option 读改写 | 旧实现每次 `record_batch` 读出整个 option、在内存累加、再整体写回（清理前 `src/S3/Upload_Metrics.php:80`、`:132`），并发请求会互相覆盖：4 进程各 200 条只落 202 条。事件行由 MySQL 原子 INSERT，快照用 SQL 聚合（`:204`）；代价是表随上传量增长、`totals` 每次全量聚合 |

### 6.8 四条遗留代码的核实与清理（2026-11-08 关闭）

本轮把原「待确认」四条逐个做成「静态调用点盘点 → 历史文档溯源 → 测试站运行期验证」，四条均已给出结论并落到代码。逐条证据如下。

**① `Upload_Queue`：确认无用，已删除。**

- 事实：`src/S3/Upload_Queue.php` 共 231 行，`src/` 内 **0 实例化**；唯一引用方是 `tests/UploadQueueTest.php:49`、`:84`。`src/S3/Storage.php:135-137` 的注释已经写明「所有附件都在产生它的请求内完成上传与校验……不再有 shutdown 兜底或后台首传」。
- 历史：`DEVELOPMENT.md` §14.31 记录 1.12.0 已移除异步上传队列（`sio_s3_uploads` 表、`Upload_Worker`、每分钟 cron、看门狗、`async_upload` 过滤器全部删除）；该类是 Phase 2D 拆分后没有一并删掉的工具类（`readme.txt:638` 可追到拆分记录）。
- 处置：删除 `src/S3/Upload_Queue.php` 与 `tests/UploadQueueTest.php`，并移除 `tests/bootstrap.php` 中对应的 `require_once`。S3 模块类数 37 → 36，图谱节点 181 → 180。

**② `Upload_Metrics::snapshot()` 的 `counts` / `backlog`：生产者已不存在，已移除。**

- 事实：清理前的 `snapshot( ?array $counts = null )` 的 `$counts` 只用于合并 `queued/running/retrying/dead/due/backlog/oldest`（清理前 `src/S3/Upload_Metrics.php:162-175`），而生产调用方**全部无参**（清理前位置）：`src/S3/Prometheus.php:124`、`src/S3/Alerting.php:254/:331`、`src/S3/Sync_Center_Panel.php:29`、`src/Sync/CLI.php:155`。
- 历史：`DEVELOPMENT.md` §14.35 说明该参数是 1.6.4 为 `Upload_Jobs::counts()` 预留的注入口；1.12.0 删除异步队列后 `Upload_Jobs` 已不存在，文档原话是「后台卡片的积压现在恒为 0，不再代表上传队列」。所以不是「忘记传参」，而是**生产者被删后接口没跟着收口**。
- 处置：`snapshot()` 改为无参（`src/S3/Upload_Metrics.php:204`），删除 `$counts` 合并块与返回值里的 `backlog`；连带清掉下游恒零消费端——`Prometheus::format` 去掉 `queue_depth{state=…}`（6 个 state）与 `queue_oldest_seconds`（清理前 `src/S3/Prometheus.php:135`）；`Alerting` 去掉 `max_dead` 配置、`dead` 告警分支与 payload 里的队列字段（清理前 `src/S3/Alerting.php:152-215`，现 `evaluate()` 位于 `:152-169`）；`Monitoring_Panel` 去掉「死信阈值（0 关闭）」输入与 POST 处理（清理前 `src/S3/Monitoring_Panel.php:81-128`）；`Sync\CLI::stats` 去掉 Backlog 行（清理前 `src/Sync/CLI.php:154-184`）。相应测试改为 `snapshot()` 无参并断言不存在 `backlog` 键。
- 结果：`vendor/bin/phpunit` 391 tests / 1120 assertions / 5 skipped 全绿（清理前基线 395/1124/5 skipped）；PHPStan `[OK] No errors`；phpcs 改动文件 exit 0。测试站 `wp sakura-sync stats --format=table` 已不再输出 Backlog 行（§10.8）。

**③ 两套 `target_hash`：语义不同，不需要一致，已互相注明。**

- 上传侧 `Synchronous_Uploader::target_hash_for()`（`src/S3/Synchronous_Uploader.php:488`）= `sha256(active_profile_id|provider|bucket|endpoint|upload_path)`，写入 postmeta `_sakura_imgopt_s3_target`，回答的是「这个附件/动作属于哪个**上传目标**」。
- 删除侧 `Delete_Jobs::target_hash()`（`src/S3/Delete_Jobs.php:61`）= `sha256(wp_json_encode(provider, bucket, endpoint, region))`，写入 `sio_s3_deletes.target_hash`，回答的是「这条删除任务属于哪个**存储桶身份**」。profile 改桶后旧任务仍要能删，所以归档配置放在 `s3_options.retired_targets`（`src/S3/Settings_Saver.php:143-163`），删除侧必须带 `region` 才能在归档目标上定位对象。
- 关系：全仓**没有任何地方把两者交叉比较**。上传 hash 只与 `target_hash_for()` 的新结果比（`src/S3/Storage.php:672-674`、`src/S3/Sync_Center_Module.php:744/788/866`、`src/S3/Action_Worker.php:91/102/230`）；删除 hash 只与 `Delete_Jobs::target_hash()` 比（`src/S3/Delete_Worker.php:191-206`）。两者字段集合与归一化方式都不同（上传含 profile/路径、不含 region；删除含 region、不含 profile/路径），**统一会强制引入对方不需要的字段**，并使已存的 postmeta、`sio_s3_deletes.target_hash`、`sio_sync_jobs.target_hash` 全部失配（上传侧被判目标变更、删除侧全部 drift）。
- 处置：不改算法，只在两处 docblock 互相注明差异与「不得统一」的原因；测试站探针实测两条 hash 不相等（`equal=no`，字段与数值见 §10.8）。

**④ `Sync_Status` 三个遗留方法与 `PREVIOUS_PATH_OPTION`：保留或接线，不再算死代码。**

- `Sync_Status::enqueue()` / `jobs()` / `fail_job()`（`src/S3/Sync_Status.php:299`、`:328`、`:342`）是 1.12.0 之前异步上传的重试簿记：写 `JOBS_META=_sakura_imgopt_s3_jobs`，退避 `min(3600, 60*2^min(5,attempts))`，8 次置 `dead`。`src/` 内除定义外**无调用方**，但 `Storage::maybe_migrate`（`src/S3/Storage.php:464-516`）仍直接读同一份 postmeta，把老站遗留任务迁移成 `record_failures` 后删除，所以 `JOBS_META` 常量不能删。
- 处置：三个方法**保留**并加 `@deprecated 1.12.0`，docblock 写明只服务于遗留数据迁移、新代码不要调用（公开 API，测试仍覆盖）；`Storage::maybe_migrate` 保留。
- `Upload_URL::PREVIOUS_PATH_OPTION`（`src/S3/Upload_URL.php:12`，值 `sakura_imgopt_previous_upload_path`）原先「声明未使用」，但清理前 `Uninstaller::cleanup_persistent_data()` 一直用**字面量**读/删这个 option 来还原老站的 `upload_path`。处置：`Uninstaller` 引入 `use Sakura\ImageOptimizer\S3\Upload_URL;`，把读/还原/删除 `upload_url_path` 与 `upload_path` 旧值的三处逻辑改用 `Upload_URL::PREVIOUS_URL_OPTION` / `PREVIOUS_PATH_OPTION`（现 `src/Uninstaller.php:205-228`）。常量由此获得真实引用，字面量消失；行为不变（探针里 `previous_upload_path=NULL`、`previous_upload_url_path` 为空，说明本站没有可还原的旧值）。

### 6.9 上传指标的并发修复（2026-11-08）

§10.10-B 记录的「上传失败不可见」只解决了「有没有失败样本」，没有解决「样本会不会丢」。本轮把数据层一并换掉，目标是多人同时上传时每条成功/失败都留下可聚合的记录。

#### 6.9.1 问题：option 读改写会互相覆盖

旧实现 `record_batch()` 的路径是 `get_option()` → 内存累加 → `update_option()`（清理前 `src/S3/Upload_Metrics.php:80`、`:132`），没有锁，也没有 CAS。两个请求同时读到同一份旧值，后写回的那份会把先写回的样本整体覆盖。测试站用 4 个独立 WP-CLI 进程各写 200 条并发样本：

| 实现 | 期望 | 实际 | 丢失 |
|---|---:|---:|---:|
| 旧 option 读改写 | 800 | 202 | 598（74.75%） |
| 新事件表 INSERT | 800 | 800 | 0 |

成功样本走同一条 `record_batch()`，所以生产快照里的 91 条成功也只是「至少 91 条」，不能当作精确计数。这个结论解释了为什么仅补一个失败调用点不够：只要写入仍是读改写，失败样本同样会丢。

#### 6.9.2 方案：追加事件表 + SQL 聚合

- **写入**：`Upload_Metrics::record_batch()`（`src/S3/Upload_Metrics.php:149`）把每个样本变成一行 `INSERT`，不读旧值。`result` 取 `ok` / `dead` / `fail`，`created_at` 与 `day` 统一按 UTC 生成；`object_key` 截 1024 字节、`error_message` 截 500 字符、`profile_id` 截 40。
- **失败采集**：`Storage_Adapter` 是所有上传路径的共同入口（`Synchronous_Uploader::finalize`、`Sync_Actions`、CLI）。新增 `report_failure()`（`src/S3/Storage_Adapter.php:71`）把每个 key 的 `WP_Error` 转成 `sakura_imgopt_s3_upload_failed`（`:72`），`upload_file()`（`:119`）与 `upload_many()`（`:159`）的非法 key、文件不可读、守卫异常、缺失结果、存储异常都经过它；`Upload_Observer::record_failed()`（`src/S3/Upload_Observer.php:78`）补齐附件 ID 后写表。`Synchronous_Uploader.php:266` 把 `$attachment_id` 传给 `upload_many()`，所以失败行能直接定位到附件。
- **读取**：`snapshot()`（`src/S3/Upload_Metrics.php:204`）先按 `result` 聚合 totals，再按 `day,result` 聚合 14 天日桶，然后用 `result IN ('fail','dead')` 取最近错误，最后与旧 option 的冻结基线合并。返回结构与旧版一致（`totals`/`today`/`week`/`last_error`/`updated_at` 以及 `with_formats` 的展示字段），所以 `Prometheus`、`Alerting`、`Sync_Center_Panel`、`wp sakura-sync stats` 无需改动。
- **建表**：`maybe_install()`（`:68`）比较 `sakura_imgopt_s3_upload_schema` 与 `VERSION=2.0.0`（`:42`），不一致时由 `install()`（`:79`）用 `dbDelta()` 建表，再用 `SHOW COLUMNS` 校验 `result`/`day`/`error_message`；校验失败会记 `sakura_imgopt_s3_log` 且不写 schema 标记，下次请求会重试。激活时 `src/Activation.php:99` 主动调用一次，运行期第一次 `record_batch()`/`snapshot()` 也会惰性兜底。

表结构（`src/S3/Upload_Metrics.php:52`，测试站 `t_sio_s3_upload_events` 实测一致）：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint unsigned AI | 事件序号，`last_error` 按它倒序取最新 |
| `created_at` / `day` | datetime / date | UTC 时间与日桶键 |
| `user_id` | bigint unsigned | 触发请求的用户，CLI 为 0 |
| `attachment_id` | bigint unsigned | 附件 ID；适配器层未知时为 0 |
| `object_key` | varbinary(1024) | 对象键；非法 key 时为空串 |
| `result` | varchar(16) | `ok` / `fail` / `dead` |
| `error_code` / `error_message` | varchar(64) / varchar(500) | `WP_Error` 的 code 与 message |
| `bytes` / `duration_ms` | bigint / int unsigned | 成功时累计字节；耗时 |
| `profile_id` | varchar(40) | 上传目标 profile |

索引：`PRIMARY KEY(id)`、`day_result(day,result)`、`created_at`、`attachment_id`、`profile_day(profile_id,day)`。表为 InnoDB、`utf8mb4_unicode_520_ci`。

#### 6.9.3 兼容与卸载

- **旧 option 不迁移、不删除**：`sakura_imgopt_s3_upload_metrics` 作为冻结基线被 `legacy()`（`:322`）读取，升级后生产已有的 91 条成功立即出现在快照里，不会清零。`record_batch()` 不再写它。
- **reset 清两层**：`reset()`（`:308`）执行 `DELETE FROM` 事件表并 `delete_option(OPTION)`，但保留 `sakura_imgopt_s3_upload_schema`，避免下一次写表又触发建表。
- **卸载**：`src/Uninstaller.php:112` 的删表清单已加入 `sio_s3_upload_events`，`:122` 删除 `sakura_imgopt_s3_upload_schema`；旧 option 仍在 `monitoring_options()`（`:161`）里，按原有监控数据策略清理。
- **已知边界**：① 事件表不做 TTL 裁剪，`totals` 是自安装以来的全量，表会随上传量增长；因此**不自动裁剪**——删除事件会让 `sakura_imgopt_upload_attempts_total` 回退，与 Prometheus counter 语义冲突。若确需控制体积，只能走“归档表 + `archived_through_id` + 只读 CLI”的独立方案，不能直接 DELETE；② 顺序回滚（旧版只写 option）不会重复计数：旧版看到的是冻结基线，事件行只在升级后由 `snapshot()` 叠加。测试站基线 30 + 事件 5 + 旧版写入 3，快照 38 = 30+5+3（`tests/UploadMetricsTest.php` 的 `test_old_version_option_write_is_added_once_after_upgrade` 固化这条口径）。真正风险是旧版看不到事件行导致短期低估，升级后恢复；只有人工把事件累计写回旧 option 才会重复；③ `upload_many()` 的合法 key + 缺失文件会在进入存储前被 `Remote_Operation_Guard` 拦截，留下 `sio_sync_operations` 的 uncertain 行，同时仍写一条 `sio_remote_uncertain` 失败事件；前者需要操作员处理，后者只是观测记录（运维入口见 §6.9.5）。

#### 6.9.4 验证结果（摘要）

测试站上先删空事件表，再执行 4 进程 × 200 条失败写入：`t_sio_s3_upload_events` 得到 800 行、4 个不同 `attachment_id`，快照 `totals.fail=800`；失败采集探针写入 4 行（`attachment_id` 4242/4343/4444，空 key 与缺失文件都有记录），`error_code=sakura_s3_invalid_file`。加上旧 option 的 30 条成功基线后，快照为 `attempts=830`、`fail=800`、`rate=96.3855`；CLI、Prometheus 与后台卡片三种消费方均正常输出。验证命令与输出见 §10.11，执行后已清空事件行与守卫行，测试站快照回到 30 成功 / 0 失败。

#### 6.9.5 运维观测与裁剪评估（2026-11-09）

本轮补的是**只读观测与人工操作入口**，不改变上传、删除或同步的数据路径：

| 入口 | 输出 | 依据 |
|---|---|---|
| `wp sakura-s3 health [--format=table\|json\|csv\|yaml]` | 上传今日/14 天/累计、删除队列 pending/dead/stale、守卫 inflight/uncertain、同步 dead jobs 与 `Sync\Metrics` 快照时间 | `includes/class-s3-cli.php:522-583` |
| `wp sakura-s3 remote-operations count` | `inflight` / `uncertain` / `total` 三行只读计数 | `includes/class-s3-cli.php:90-99`、`src/S3/Remote_Operation_Guard.php:166-176` |
| `wp sakura-s3 remote-operations list --limit=<n> --offset=<n>` | 分页列出持有中的 reservation；默认 100、上限 100 | `src/S3/Remote_Operation_Guard.php:147-159` |
| `wp sakura-s3 remote-operations release --resource=<hash> --token=<token> --remote-settled --yes` | 显式解除**一个**已确认停止的 reservation；仍要求操作员声明远端已结算 | `includes/class-s3-cli.php:100-108` |
| `Prometheus` 文本 | 在原有上传指标后追加 `sakura_imgopt_delete_dead`、`sakura_imgopt_delete_stale`、`sakura_imgopt_remote_uncertain`、`sakura_imgopt_sync_jobs_dead` 四个 gauge | `src/S3/Prometheus.php:174-188`、`:210-222` |

**读法**：`delete_dead` / `delete_stale` / `remote_inflight` / `remote_uncertain` / `sync_jobs_dead` 都是对队列表的实时 `COUNT`，可直接告警；`sync_actions_failed` 来自 `Sync\Metrics` 的 option 读改写，存在并发丢计数风险，**只能作为趋势参考**，不能替代表计数。`health` 在守卫连接不可用（例如自定义 `db.php` 路由）或表缺失时把对应值写成 `n/a`，不会写库。

**裁剪评估**：本轮结论是**暂不自动裁剪**，原因有三点：① `totals` 是自安装以来的 Prometheus counter 语义，直接 `DELETE` 会让 counter 回退，触发告警误判；② 事件表是失败样本与 `last_error` 的唯一来源，裁剪会改变 14 天窗口外的排障证据；③ 生产当前为 0 行，没有需要立即处理的体积压力。若未来确实需要控制体积，推荐独立“归档表 + `archived_through_id` + 只读 CLI”的方案，保持 `totals` 连续，不先挂 cron。该决定与 §6.9.3 的已知边界一致。

---

## 7. 子系统 D：同步索引（src/Sync/）

### 7.1 职责与边界

**一句话**：把上传目录的本地文件与 S3 桶对象清单**对账成一张可断点续跑的索引表**（建表 `src/Sync/Schema.php:21`，推进 `src/Sync/Scan_Service.php:30`），并据此提供三条后台链路（分批扫描、异步动作队列、MD5 校验）与两个只读查询入口（admin-ajax 与 `fast-search.php` 的 SHORTINIT 直连脚本）。

三条边界：

| 边界 | 实现 | 依据 |
|---|---|---|
| 扫描必须可中断、可恢复 | 状态机 + `scans.scan_cursor` + 单次 tick 预算 | `src/Sync/Scan_Service.php:238`、`:280` |
| 同一时刻只允许一个扫描推进 | MySQL 命名锁 `sio_tick_` | `src/Sync/Scan_Service.php:253` |
| 动作幂等 | `dedupe_key = sha256(profile\|action\|key\|version\|target_hash)` | `src/Sync/Index_Repository.php:1063` |

### 7.2 类清单

`| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Action_Worker` | src/Sync/Action_Worker.php:28 | implements Module | 5 / 7 | 10 / 21 | 1 | 以指数退避执行队列中的上传下载删除任务 |
| `Attachment_Adopter` | src/Sync/Attachment_Adopter.php:26 |  | 8 / 9 | 4 / 2 | 2 | 把已有上传文件接纳进媒体库 |
| `Attachment_Indexer` | src/Sync/Attachment_Indexer.php:28 | implements Module | 5 / 9 | 1 / 10 | 3 | 把媒体写入实时镜像进同步索引 |
| `Checksums` | src/Sync/Checksums.php:17 |  | 3 / 3 | 8 / 0 |  | 规范化 S3 ETag 并判断能否与 MD5 比较 |
| `CLI` | src/Sync/CLI.php:25 |  | 8 / 9 | 1 / 16 |  | 用 WP-CLI 扫描、检查并维护同步索引 |
| `Fast_Search_Query` | src/Sync/Fast_Search_Query.php:24 |  | 2 / 2 | 3 / 2 |  | 构建快速搜索使用的对象列表查询 |
| `Feature` | src/Sync/Feature.php:20 |  | 2 / 2 | 8 / 0 |  | 控制持久化同步索引的运行时开关 |
| `Index_Repository` | src/Sync/Index_Repository.php:24 |  | 61 / 69 | 35 / 11 |  | 为同步索引提供批量写入与键集分页查询 |
| `Keyset_Cursor` | src/Sync/Keyset_Cursor.php:21 |  | 6 / 6 | 8 / 0 |  | 编码键集分页使用的对象键哈希边界 |
| `Kind` | src/Sync/Kind.php:20 |  | 2 / 2 | 10 / 0 |  | 把对象键归类为索引中的 kind 值 |
| `Metrics` | src/Sync/Metrics.php:20 |  | 3 / 3 | 8 / 0 |  | 维护扫描与队列动作的滚动计数器 |
| `Scan_Service` | src/Sync/Scan_Service.php:30 |  | 8 / 25 | 12 / 14 |  | 把本地文件与 S3 对象流式写入同步索引 |
| `Scan_Worker` | src/Sync/Scan_Worker.php:27 | implements Module | 9 / 10 | 11 / 8 | 5 | 经 WP-Cron 分片推进扫描进度 |
| `Schema` | src/Sync/Schema.php:21 |  | 3 / 5 | 9 / 2 |  | 创建并升级同步索引数据表 |
| `Search_Token` | src/Sync/Search_Token.php:22 |  | 4 / 4 | 4 / 0 |  | 签发并校验搜索用签名令牌 |
| `Sync_Calibrator` | src/Sync/Sync_Calibrator.php:32 | implements Module | 9 / 9 | 10 / 17 | 5 | 按每月周期在后台排一次增量核对，页面过期时补排 |
| `Verify_Service` | src/Sync/Verify_Service.php:24 |  | 2 / 7 | 4 / 6 |  | 逐行比对尚未校验的索引内容 |
| `Verify_Worker` | src/Sync/Verify_Worker.php:25 | implements Module | 6 / 6 | 3 / 8 | 4 | 分批比对本地 MD5 与远端 ETag |`

> `fast-search.php`（非类，`fast-search.php:1-12`）：绕过 admin-ajax 的只读索引查询端点，`SHORTINIT` 启动（`:42-43`），只查 `sio_sync_index`，不加载插件其余部分。

### 7.3 关键方法

| 方法 | 文件:行 | 职责 | 调用关系 |
|---|---|---|---|
| `Schema::maybe_install` | `src/Sync/Schema.php:61` | 比对 option 与 `VERSION='1.8.2'`（`:26`），必要时建表 + 迁移 | ⇐ `Activation.php:96`、`Plugin.php:89` |
| `Index_Repository::start_scan` | `src/Sync/Index_Repository.php:145` | 取 profile 锁、清理残留 running、插入 `state='running'` | ⇐ `Scan_Service::start` |
| `Index_Repository::page` | `src/Sync/Index_Repository.php:1452` | keyset 分页（status/search/kind/days，limit 夹 1–200） | ⇐ `ajax_page`、视图取数 |
| `Index_Repository::enqueue_job` | `src/Sync/Index_Repository.php:1063` | 幂等入队（dedupe_key） | ⇐ `Sync_Center_Module::ajax_action` |
| `Index_Repository::due_jobs` | `src/Sync/Index_Repository.php:1109` | 回收过期 lease、取到期 queued（上限 50） | ⇐ `Action_Worker::run` |
| `Index_Repository::job_failure` | `src/Sync/Index_Repository.php:1202` | attempts+1，≥8 置 dead，退避 `min(3600, 60*2^(n-1))` | ⇐ `Action_Worker::run` |
| `Index_Repository::reconcile` | `src/Sync/Index_Repository.php:856` | 一条 UPDATE 用 CASE 推 status（synced/mismatch/pending/remote）与 verify_state | ⇐ `Scan_Service::finalize` |
| `Index_Repository::record_verification` | `src/Sync/Index_Repository.php:685` | 写 verify_state(1/2/3)；ETag 不符时把 synced 改 mismatch | ⇐ `Verify_Service` |
| `Scan_Service::tick` | `src/Sync/Scan_Service.php:238` | 单次推进：抢 `sio_tick_` 锁（`:253`）、限时、`finally` 释放 | ⇐ ajax_scan / cron / CLI |
| `Scan_Service::advance` | `src/Sync/Scan_Service.php:280` | 阶段机 `''→local→remote→reconcile→finalize`（`:350`） | ⇐ tick |
| `Scan_Service::local_batch` | `src/Sync/Scan_Service.php:406` | 消费 `walk_local`，每 500 行 flush 一次 | ⇐ advance |
| `Scan_Service::walk_local` | `src/Sync/Scan_Service.php:549` | 目录 DFS + 游标恢复（深度上限 64，`:553`） | ⇐ local_batch |
| `Scan_Service::remote_phase` | `src/Sync/Scan_Service.php:711` | 按 S3 分页 token 列举并 `upsert_remote` | ⇐ advance |
| `Scan_Service::finalize` | `src/Sync/Scan_Service.php:832` | `reconcile`→`purge_stale`→`rebuild_counts`→`optimize`→`finish_scan` | ⇐ advance |
| `Scan_Worker::run` | `src/Sync/Scan_Worker.php:115` | 单 tick + 失败分类（`FATAL_CODES`，`:49`）+ 2/4 秒续排（`:143`） | ⇐ cron `sakura_imgopt_sync_scan_tick` |
| `Action_Worker::run` | `src/Sync/Action_Worker.php:84` | 认领（claim_token）、校验目标哈希（`:102`）、执行并写回（`:146-149`） | ⇐ cron `sakura_imgopt_sync_job_tick` |
| `Verify_Service::run` | `src/Sync/Verify_Service.php:69` | `GET_LOCK` 串行（`sio_verify_+md5`），按 profile 分片 | ⇐ `Verify_Worker::run`、CLI |
| `Verify_Service::run_profile` | `src/Sync/Verify_Service.php:124` | 逐行比 MD5 与 ETag，deadline 内可中断并保留游标 | ⇐ `run` |

### 7.4 入口钩子

| 钩子 | 类型 | 注册位置 |
|---|---|---|
| `sakura_imgopt_sync_scan_tick` | action（单次 cron，参数 scan_id） | `src/Sync/Scan_Worker.php:78` |
| `sakura_imgopt_sync_cleanup` / `sakura_imgopt_sync_daily_reconcile` | action（daily） | `src/Sync/Scan_Worker.php:79-80` |
| `sakura_imgopt_sync_kind_backfill` / `_recent_backfill` | action（单次） | `src/Sync/Scan_Worker.php:81-82` |
| `sakura_imgopt_sync_job_tick` | action（单次 cron） | `src/Sync/Action_Worker.php:63` |
| `cron_schedules`（注册 5 分钟档） | filter | `src/Sync/Verify_Worker.php:73`、`:85-92` |
| `init` / `admin_init` → `ensure_scheduled` | action | `src/Sync/Verify_Worker.php:74-75` |
| `sakura_imgopt_sync_verify_tick` | action（每 5 分钟） | `src/Sync/Verify_Worker.php:76` |
| `add_attachment` / `wp_update_attachment_metadata` / `delete_attachment` | action（优先级 20/20/10） | `src/Sync/Attachment_Indexer.php:53-55` |

> Sync 侧**没有 REST 端点**：全插件 `register_rest_route` 只出现在 `src/UserFiles/Rest/*`（如 `Controller.php:49`）。

### 7.5 数据落点

**6 张表**，前缀 `{prefix}sio_sync_`（`src/Sync/Schema.php:53-55`，表名定义在 `:87-201`）：

| 表 | 关键索引 / 说明 |
|---|---|
| `sio_sync_index` | `UNIQUE profile_object(profile_id, object_key_hash)`；`KEY status_keyset(profile_id,status,object_key_hash)`、`profile_kind`、`profile_dir`、`profile_key`、`profile_recent(profile_id,recent_at,object_key_hash)`（`:110-116`） |
| `sio_sync_scans` | 一次扫描一行：state、cursor、计数 |
| `sio_sync_counts` | 按 profile/状态聚合，供后台"概览"直读 |
| `sio_sync_jobs` | 动作队列：dedupe_key、lease、attempts、next_at |
| `sio_sync_events` | 审计事件 |
| `sio_sync_operations` | 远端变更准入账本；读写都在 `src/S3/Remote_Operation_Guard.php:39`，无自动清理（§7.7.1） |

**option**：`sakura_imgopt_sync_schema`（`:31`，实测 `1.8.2`）、`sakura_imgopt_sync_recent_ready`（`:46`）、`sakura_imgopt_sync_dir_indexed`（`:224-233` 旧行回填判定）、`sakura_imgopt_sync_full_at`（`Scan_Service.php:835`）、`sakura_imgopt_sync_metrics`（`Metrics.php:25`）、`sakura_imgopt_sync_verify_cursor`（`Verify_Service.php:34`）、`sakura_imgopt_sync_index_enabled`（`Feature.php:25`）、`sakura_imgopt_sync_ignored`（`src/S3/Ignored_Paths.php:25`）；另有迁移时删除 `sakura_imgopt_s3_inventory`（`Schema.php:237`）。

**postmeta（附件侧，`src/Sync/Sync_Status.php:25-44`）**：`_sakura_imgopt_s3_status` / `_error` / `_objects` / `_synced_at` / `_jobs` / `_target` / `_failures` / `_object_versions`；索引侧只读 `_wp_attached_file`（`Attachment_Adopter.php:84-92`）。

### 7.6 扫描主流程

```
① 建任务  ajax_scan(Sync_Center_Module.php:305) → Scan_Service::start(:131)
          → Index_Repository::start_scan(:145) 插 state='running' → 立即 tick 一次 + 续排
② 推进    cron → Scan_Worker::run(:115) → Scan_Service::tick(:238) 在预算内 advance(:280)
③ 本地    walk_local(:549) 从 scans.scan_cursor 的 JSON 目录栈恢复
          每 500 行 upsert_local 并回写 processed/local_bytes/scan_cursor(:522-527)
④ 云端    remote_phase(:711) 以 S3 分页 token 作 scan_cursor 逐页列举，累计 counts(:764-775)
⑤ 收尾    finalize(:832) → reconcile(Index_Repository.php:856) 一次性定 status/verify_state
          → purge_stale(:946) → rebuild_counts(:1300) → optimize(:965) → finish_scan(:845)
⑥ 动作    enqueue_job(:1063) → Action_Worker::run(:84) due_jobs(:1109) + job_start(:1141)
          → Sync_Actions → job_success(:1182) / job_failure(:1202) 退避重试
```

### 7.7 三项核实（2026-11-08 关闭）

旧版这里列了三条待确认；本轮已逐条落到代码位置或测试站运行记录（§10.9）。验证只在测试站 `/root/sio-e2e` 进行，临时 S3 profile、临时桶与探针均已清理，生产保持只读。

#### 7.7.1 `sio_sync_operations`：远端写入的准入账本

**结论**：它不是异步任务队列，而是「同一物理对象同一时刻只允许一个远端变更」的持久互斥记录。成功即删；结果不确定则永久保留并阻塞同一对象，直到操作员显式确认旧请求已停止后手工解除。

| 观察点 | 结论 | 代码依据 |
|---|---|---|
| 作用域 | `scope_for()` 只取 `endpoint`（去空白、转小写、去尾部 `/`，空值记作 `aws-s3`）与 `bucket` 的 sha256；profile 别名不影响冲突判断 | `src/S3/Remote_Operation_Guard.php:45-49` |
| 记录粒度 | 同一 `scope + object key` 一行，主键 `resource_hash = sha256(scope + "\n" + key)` | `:122-124`、`src/Sync/Schema.php:188-201` |
| 写入 | 发送前 `INSERT IGNORE`，`state='inflight'`，token 为 32 字节随机数的 64 位 hex，附 `context_json`（target / expected） | `:109-120` |
| 成功删除 | 每个 key 的 dispatch 返回**严格 `true` 且 release 成功**，才删除 `resource_hash + token + state='inflight'` | `:61-106`、`:126-131` |
| 不确定保留 | dispatch 抛异常、返回 `WP_Error`、返回非 `true`，或 release 失败 → `state='uncertain'`，`last_error` 截 250 字符 | `:93-105`、`:133-138` |
| 冲突语义 | 主键冲突即返回 `sio_remote_busy`；同一物理对象不会并发写入 | `:116-119` |
| 清理入口 | `pending($limit,$offset)` 只读分页（默认 100，最大 100，含崩溃遗留的 inflight）；`count_reservations($state)` 只读计数；`acknowledge_settled(resource,token)` 校验两个 64 位 hex 后无条件删除；**没有 TTL、没有自动回收** | `src/S3/Remote_Operation_Guard.php:147-198` |
| 调用面 | `Storage_Adapter::upload_file` / `upload_many` / `delete_keys` 都经 `guard()->execute(...)` | `src/S3/Storage_Adapter.php:136`、`:179`、`:346` |
| 运维入口 | `wp sakura-s3 remote-operations list` 支持 `--limit/--offset`，`count` 输出 inflight/uncertain/total，`release` 必须同时给 `--remote-settled` 并经确认；后台页面与 cron 仍不调用 | `includes/class-s3-cli.php:79-108`、`tests/RemoteOperationGuardTest.php` |

**执行顺序**（`:61-106`）：先对全部 key 逐个 `acquire`；随后才执行可选的 `check()`（队列所有权复核）。`check` 失败或抛异常时，所有已取得的 reservation 会被释放，**尚未发送任何远端写入**。只有 `check` 通过后才调用 `dispatch`。这保证了「先占位、再确认、最后发送」的顺序。

**为什么没有 TTL**：类注释（`:9-13`）写明——死掉的 PHP 进程或过期的队列租约都**不能证明** S3 请求已经停止，HEAD 200/404 也不是静默证明。因此 `uncertain` 必须保留，靠操作员查 `{prefix}sio_sync_operations` 后手工解除。构造函数默认新建独立 `wpdb` 并强制 autocommit（`:20-42`），避免调用方事务回滚把「已经发出的请求」记录擦掉；检测到 `wp-content/db.php` 会直接抛异常，因为无法确认共享账本。

运行期探针（§10.9-A）：正常 dispatch 后无残留；dispatch 返回 `WP_Error` 后写入 `state=uncertain`，同一 scope/key 再 `execute` 返回 `sio_remote_busy`；错误 token 解除返回 false、正确 token 返回 true、第二次再解除返回 false。

#### 7.7.2 同步中心仍走 admin-ajax，没有 REST

**结论**：Sync 中心的服务端入口全部是 `admin-ajax.php` / `admin-post.php`；全插件 REST 只属于 UserFiles 的 `sufm/v1`。这不是遗漏，是当前实现方式。

| 观察点 | 结论 | 代码依据 |
|---|---|---|
| 注册面 | 10 个 `wp_ajax_sakura_s3_sync_*`、1 个 `wp_ajax_sakura_s3_config_notice`、3 个 `admin_post_*`；没有 `register_rest_route` | `src/S3/Sync_Center_Module.php:108-124` |
| 资源加载 | 仅在 `Registry::on('storage','sync')` 时 `Page_Assets::set_data('storage-sync', …)` 后调 `Assets::enqueue_page()`，加载 `assets/ui/` 共享栈与 `assets/ui/js/pages/storage-sync.js` | `:148-157` |
| 页面配置 | `Page_Assets::enqueue()` 把每页数据塞进 `window.sakuraPage.data`（含 `searchUrl`=`fast-search.php` 与 `searchToken`），并另给 `ajaxUrl` / `restUrl` / 通用 nonce；同步页自己的 nonce 与文案都在 `page_config()` 里 | `src/Admin/Page_Assets.php:79-228`、`src/S3/Sync_Center_Module.php:204-220` |
| 普通请求 | 页面脚本只走 `SakuraAdmin.api.ajax(action, data)`：`URLSearchParams` 组 body、`POST page.ajaxUrl`，并自动拆 WordPress 的 `{success,data}` 信封 | `assets/ui/js/pages/storage-sync.js:198-345`、`assets/ui/js/api.js:154-176` |
| 快速翻页 | `SakuraAdmin.api.get(page.data.searchUrl, params)`，带 `searchToken`，失败或空结果回退到 `sakura_s3_sync_page` | `assets/ui/js/pages/storage-sync.js:1031-1077` |
| REST 反证 | `register_rest_route` 只出现在 `src/UserFiles/Rest/*`（如 `Controller.php:49`、`Picker_Controller.php:73/87`），命名空间为 `sufm/v1` | 全仓 grep |

实际注册的 14 个入口：

```
wp_ajax_sakura_s3_sync_scan          wp_ajax_sakura_s3_sync_scan_step
wp_ajax_sakura_s3_sync_cancel        wp_ajax_sakura_s3_sync_resume
wp_ajax_sakura_s3_sync_page          wp_ajax_sakura_s3_sync_test
wp_ajax_sakura_s3_sync_action        wp_ajax_sakura_s3_sync_refresh
wp_ajax_sakura_s3_sync_toggle_index  wp_ajax_sakura_s3_config_notice
admin_post_sakura_s3_sync_export     admin_post_sakura_s3_ignored_save
admin_post_sakura_s3_media_download
```

`storage-sync.js` 里另有 `sakura_s3_retry`，它注册在 `includes/class-s3-storage-module.php:74`，属于对象存储页的失败重试入口，不在上表 Sync 动作内，不影响「Sync 无 REST」的结论。1.25 新增的 `admin_post_sakura_s3_media_download` 是核对行媒体预览弹窗的下载通道，区别于同步动作。

运行期（§10.9-B）：1.22.8 时用后台 Cookie 请求 `/wp-admin/admin.php?page=sakura-tools-storage&t=sync` 得到 240,612 字节页面，含 `<div class="sakura-sync-app" ...>` 且加载 `assets/admin.js`。1.25 起该页换到 UI 栈：根节点是 `UI::page()` 输出的 `.sakura-admin[data-sakura-page="storage-sync"]`，脚本为 `assets/ui/js/pages/storage-sync.js`，配置经 `window.sakuraPage` 传递；页面脚本仍只调 admin-ajax 与 `fast-search.php`，不走 REST。同一 Cookie 会话 POST `action=sakura_s3_sync_page&nonce=...&status=all&limit=2` 返回 `success=true` 与 2 行数据的结论不变。

**维护含义**：新增同步操作时沿用 admin-ajax + nonce；不要假设存在可复用的 REST 客户端。真正的 REST 接口集中在 §8.5。

#### 7.7.3 `scan_cursor` 的双语义由 phase 严格分隔

**结论**：`scan_cursor` 一列确实有两种编码——local 阶段是 JSON 目录栈，remote 阶段是 S3 分页 token——但由 `phase` 选择解码器；local→remote 的切换与清空 cursor 在**同一条 UPDATE** 中完成，remote→reconcile 时 token 已为空，因此不会把 JSON 当 token，也不会把 token 当 JSON。

| 阶段 | 编码 | 写入 | 读取 / 终止 |
|---|---|---|---|
| `local` | `{"d":[目录栈],"a":"最后条目"}` | `flush_local` 每 500 行回写 `processed / local_bytes / scan_cursor`（`:509-530`） | `local_phase` 读 `:362`，`decode_cursor` 校验 `:663-682`；目录走完即结束 |
| `remote` | S3 的 opaque continuation token（MinIO 是 base64 字符串） | `remote_phase` 每页写回 `next_token`（`:764-775`） | 读 `:714`；token 为空即 `done`（`:786-793`） |
| 切换 | `''` | `advance` 初始化 local 时写 `phase='local' + scan_cursor=''`（`:287-296`）；local→remote 时同一 UPDATE 写 `phase='remote' + scan_cursor=''`（`:317-326`）；remote→reconcile 写 `phase='reconcile'`（`:347`） | 进入 remote 前先清空；remote 结束时 token 已为空，reconcile 无需再清 |

配套生命周期：

- `start_scan` 插入 `phase=''`、`scan_cursor=NULL`（`src/Sync/Index_Repository.php:166`）；`resume_scan` 保留 phase 与 cursor，只把 `state` 改回 `running`（`:186-222`）；`resumable_scan` 只接受 `state IN ('failed','cancelled') AND phase<>''`（`:271-282`）。所以恢复时一定按原 phase 解释同一列。
- local 游标失效（目录被删、旧格式）时，`local_phase` 清空 `processed/local_bytes/scan_cursor` 后重跑一次（`:376-383`）；`upsert_local` 幂等，重扫不会产生重复行。
- `finish_scan` 写 `state='done', phase='done'`（`:381-396`），不碰 cursor；但 remote 正常结束时 token 已经是空串，所以 done 行的 cursor 也是空。

运行期两组数据（详见 §10.9-C）：

| 组 | 观测 | 结果 |
|---|---|---|
| A：5000 本地 + 1001 远端 | 逐 tick 记录 `t_sio_sync_scans` 的 UPDATE | local 每 500 行写一次 JSON，最后 `{"d":[],"a":"f04999.txt"}`；切 remote 的同一 UPDATE 把 cursor 置 `''`；remote 第 1 页写入 token，第 2 页置空后 done |
| B：40000 本地，1 秒预算 | `tick($id, 1, 1)` 连续调用 | tick0 `processed=18500`、cursor `{"d":[],"a":"r18499.txt"}`；tick1 `processed=36500`、cursor `r36499.txt`（**没有回到 0**）；tick2 done、`total_local=40000`、cursor 空 |

A 组第 1 页写入的 token 原文：

```
d3AtY29udGVudC91cGxvYWRzL3Byb2JlLzA5OTkuYmluW21pbmlvX2NhY2hlOnYyLHJldHVybjpd
→ wp-content/uploads/probe/0999.bin[minio_cache:v2,return:]
```

这同时说明 token 是提供商/桶状态的不透明串，插件不做解析；它能被下一 tick 原样读回并继续分页。

**未覆盖**：A 组的本地阶段在单 tick 内完成（5000 文件未触发预算边界），因此「local JSON 中途续跑」由 B 组补证；B 组的远端计数包含桶内 392 个既有对象，与本地续跑结论无关。B 组验证后已删除 40392 条索引、1 条 scan、2 条 counts、2 条 events，还原 `s3_options`，删除本地 40000 文件。

三项至此关闭；§12 相应移除，运行记录见 §10.9。

---

## 8. 子系统 E：用户文件空间（src/UserFiles/，46 文件 / 22,262 行）

### 8.1 职责与边界

**一句话**：给登录用户提供**私有文件/文件夹 + 上传配额 + 「申请—审核—执行」删除**的能力，并把 WordPress 媒体库、Zibll 主题用户中心、订单/积分扩容接进来；状态落 15 张自定义表，异步动作由 `sufm_tick` / `sufm_delete_tick` 驱动。

这是全插件最大、也是唯一带**五层权限门**（`can_use_files` / `can_review` / `can_execute` / `can_manage_media` / `space_can_*`）的子系统。这里的 `space_can_*` 不是一个权限，而是 `space_can_use`、`space_can_review`、`space_can_manage` 三个不同门。改它之前必须先读 `src/UserFiles/Capabilities.php` 与 `src/UserFiles/Schema.php`。

**权限与开关速查（当前 1.27.5）**：

| 范围 | 当前判定 | `entry_enabled` 关闭时 |
|---|---|---|
| 用户自助文件空间 | `can_use_files`：登录用户 + `MANAGE_OWN`，且 `entry_enabled` 开启 | 自助 REST、前台入口和自助上传拒绝 |
| 编辑器 picker | `can_pick`：登录用户 + `MANAGE_OWN` + `editor_enabled` | 不受 `entry_enabled` 影响；仍可只读选择自己的文件 |
| 媒体库文件夹/转移/管理员 REST | `MANAGE_MEDIA`（另有管理员执行门） | 继续可用，不随前台入口开关关闭 |
| 后台删除 | `REVIEW` 负责审核；`DELETE_FILES` 负责预览/执行；每个附件删除还需 WordPress `delete_post` | 继续可用 |
| 空间扩容账本 | `space_can_review` 可读；`space_can_manage` 仅 `manage_options` 可写授权 | 账本/cron 继续运行 |

`Capabilities.php` 中的持久能力名为 `MANAGE_OWN`、`REQUEST_DELETE`、`REVIEW`、`MANAGE_MEDIA`、`TRANSFER`、`DELETE_FILES`；不要把它们与 REST 方法名或后台 tab 名混用。`sufm_tick` 与空间账本处理独立于 `entry_enabled`。

### 8.2 类清单

`| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Adoption_Manifest` | src/UserFiles/Services/Adoption_Manifest.php:7 |  | 1 / 2 | 2 / 2 |  | 为首次接纳构建只读的可信文件清单 |
| `Assets` | src/UserFiles/Editor/Assets.php:20 |  | 1 / 1 | 3 / 2 |  | 注册编辑器样式、脚本与配置对象 |
| `Assets` | src/UserFiles/Support/Assets.php:21 |  | 1 / 1 | 6 / 0 |  | 统一为打包资源生成防缓存版本号 |
| `Capabilities` | src/UserFiles/Capabilities.php:20 |  | 8 / 8 | 20 / 0 |  | 定义四个文件操作能力，删除另需 delete_post |
| `CLI` | src/UserFiles/CLI.php:23 |  | 7 / 8 | 0 / 13 |  | 用 WP-CLI 检查并驱动文件空间任务 |
| `Context` | src/UserFiles/Editor/Context.php:20 |  | 3 / 3 | 3 / 3 |  | 为 Zibll 两个编辑屏幕提供统一编辑器标识 |
| `Controller` | src/UserFiles/Rest/Controller.php:34 |  | 59 / 71 | 1 / 40 |  | 从会话取所有者，提供 sufm/v1 REST 接口 |
| `Db_Transaction` | src/UserFiles/Support/Db_Transaction.php:22 |  | 5 / 5 | 16 / 0 |  | 统计插件自身的事务语句以避免嵌套 |
| `Entry` | src/UserFiles/Frontend/Entry.php:24 |  | 7 / 7 | 4 / 7 |  | 选取首个可用前端提供者并上报其状态 |
| `Events` | src/UserFiles/Repositories/Events.php:22 |  | 2 / 2 | 12 / 6 |  | 记录不含路径与凭据的审计事件 |
| `Executor` | src/UserFiles/Services/Executor.php:30 |  | 6 / 21 | 4 / 22 | 2 | 执行不可逆步骤前重读审批与停止标记 |
| `Files` | src/UserFiles/Repositories/Files.php:24 |  | 15 / 20 | 25 / 6 |  | 维护受管文件记录，区分上传与媒体库接纳 |
| `Folders` | src/UserFiles/Repositories/Folders.php:28 |  | 18 / 38 | 18 / 9 |  | 维护虚拟文件夹树，不移动磁盘文件 |
| `Generic` | src/UserFiles/Frontend/Providers/Generic.php:24 | implements Provider | 11 / 12 | 3 / 4 | 2 | 通过 [sufm_files] 短代码挂载文件空间 |
| `Library_Filter` | src/UserFiles/Media/Library_Filter.php:15 |  | 1 / 1 | 2 / 2 |  | 为原生媒体库提供 SQL 过滤条件 |
| `Media_Hooks` | src/UserFiles/Media/Media_Hooks.php:37 |  | 24 / 52 | 1 / 34 | 21 | 衔接用户文件空间与 WordPress 媒体库 |
| `Media_Move_Failure` | src/UserFiles/Services/Media_Move_Failure.php:21 | extends RuntimeException | 1 / 1 | 2 / 1 |  | 表示媒体库移动失败并携带原始 WP_Error |
| `Media_Mover` | src/UserFiles/Services/Media_Mover.php:14 |  | 4 / 15 | 1 / 14 |  | 原子执行虚拟文件夹移动、转移与首次接纳 |
| `Media_Notifications` | src/UserFiles/Services/Media_Notifications.php:8 |  | 1 / 1 | 1 / 4 |  | 持久投递转移与接纳通知，失败不回滚业务 |
| `Module` | src/UserFiles/Editor/Module.php:21 |  | 3 / 3 | 1 / 10 | 4 | 仅在开关开启时接入两个写作屏幕 |
| `Module` | src/UserFiles/Module.php:33 |  | 11 / 11 | 3 / 44 | 9 | 装配文件空间的服务图并向各方注入依赖 |
| `Notifications` | src/UserFiles/Repositories/Notifications.php:23 |  | 7 / 7 | 4 / 4 |  | 按请求与状态合并至少一次通知投递 |
| `Notifier` | src/UserFiles/Services/Notifier.php:20 |  | 6 / 7 | 6 / 2 |  | 尽力投递站内通知，失败时保留待发记录 |
| `Page` | src/UserFiles/Admin/Page.php:28 |  | 8 / 9 | 1 / 18 | 5 | 服务端渲染文件管理页外壳，表格由 sufm/v1 脚本水合 |
| `Picker` | src/UserFiles/Editor/Picker.php:32 |  | 1 / 1 | 1 / 3 |  | 在页脚一次性输出文件选择面板 |
| `Picker_Controller` | src/UserFiles/Rest/Picker_Controller.php:30 |  | 5 / 8 | 1 / 11 |  | 提供按文件夹过滤的附件选择 REST 接口 |
| `Provider` | src/UserFiles/Frontend/Provider.php:24 |  | 6 / 6 | 4 / 2 |  | 定义前端挂载用户文件空间的方式 |
| `Quota` | src/UserFiles/Services/Quota.php:28 |  | 8 / 8 | 15 / 9 |  | 以租约与短事务维护文件空间配额 |
| `Rate_Limiter` | src/UserFiles/Services/Rate_Limiter.php:23 |  | 4 / 4 | 4 / 6 |  | 用独立数据表限制每日与每分钟请求 |
| `Requests` | src/UserFiles/Repositories/Requests.php:28 |  | 20 / 28 | 13 / 15 |  | 驱动文件请求状态机并冻结请求项 |
| `Schema` | src/UserFiles/Schema.php:24 |  | 5 / 7 | 42 / 2 |  | 创建并升级 sufm 数据表结构与版本标记 |
| `Settings` | src/UserFiles/Settings.php:21 |  | 14 / 16 | 33 / 4 |  | 读取并收敛 sufm_settings 与用户配额覆盖 |
| `Space_Page` | src/UserFiles/Admin/Space_Page.php:28 |  | 5 / 7 | 1 / 20 | 5 | 注册 Zibll 标签页的设置组、资源与视图 |
| `Space_Panel` | src/UserFiles/Space_Panel.php:23 |  | 10 / 11 | 2 / 10 | 4 | 服务端渲染前端空间面板并加载资源 |
| `Space_Rules` | src/UserFiles/Services/Space_Rules.php:24 |  | 8 / 13 | 12 / 6 |  | 汇总配额公式、发放聚合与请求级缓存 |
| `Space_Settings` | src/UserFiles/Space_Settings.php:20 |  | 16 / 33 | 19 / 0 |  | 为扩容选项提供默认值、读取与清洗 |
| `Space_Store` | src/UserFiles/Services/Space_Store.php:22 |  | 42 / 64 | 14 / 9 | 2 | 管理空间套餐、购买与发放记录 |
| `Sync_Service` | src/UserFiles/Services/Sync_Service.php:26 |  | 6 / 9 | 5 / 10 |  | 按附件 ID 游标执行可恢复的扫描 |
| `Tab` | src/UserFiles/Frontend/Tab.php:22 |  | 11 / 16 | 10 / 5 | 4 | 为 Zibll 用户中心添加文件管理页签与资源 |
| `Theme_Levels` | src/UserFiles/Support/Theme_Levels.php:22 |  | 3 / 4 | 2 / 0 |  | 从主题读取并统一等级价格与名称 |
| `Theme_Limits` | src/UserFiles/Services/Theme_Limits.php:22 |  | 2 / 2 | 4 / 2 |  | 适配 zibll 主题自带的上传频率限制 |
| `Time` | src/UserFiles/Support/Time.php:18 |  | 4 / 4 | 32 / 0 |  | 以 UTC 格式统一存储与比较时间戳 |
| `Uploader` | src/UserFiles/Services/Uploader.php:27 |  | 3 / 9 | 6 / 18 | 1 | 仅把经此入口的文件登记为受管文件 |
| `Zibll` | src/UserFiles/Frontend/Providers/Zibll.php:21 | implements Provider | 7 / 7 | 1 / 6 |  | 把文件空间挂为 Zibll 用户中心的标签页 |
| `Zibll_Bridge` | src/UserFiles/Services/Zibll_Bridge.php:23 |  | 9 / 16 | 17 / 0 |  | 探测 zibll 主题能力并生成请求级能力矩阵 |
| `Zibll_Order_Card` | src/UserFiles/Space/Zibll_Order_Card.php:26 |  | 4 / 12 | 2 / 0 | 3 | 为存储充值订单类型定制卡片与标题 |`

### 8.3 关键方法

| 方法 | 文件:行 | 职责 | 调用关系 |
|---|---|---|---|
| `Uploader::handle` | `src/UserFiles/Services/Uploader.php:109` | 上传全链路：校验→预留→落盘→记账→提交 | ⇐ `Controller::uploads`（`:1113`）；依赖 Quota/Files/Rate_Limiter |
| `Quota::reserve` | `src/UserFiles/Services/Quota.php:168` | 锁 usage 行 + 全站预算锁，写 reservations | ⇐ `Uploader.php:181` |
| `Quota::commit` | `src/UserFiles/Services/Quota.php:336` | reserved→used，条件 UPDATE 防超卖 | ⇐ `Uploader.php:370` |
| `Requests::create` | `src/UserFiles/Repositories/Requests.php:222` | 建申请、写 items、CAS 锁 files/folders | ⇐ `POST /delete-requests` |
| `Requests::approve` / `revoke` / `stop` | `src/UserFiles/Repositories/Requests.php:738` / `:840` / `:887` | 状态 CAS 迁移、解冻/解锁 | ⇐ review/revoke 路由、`Executor::stop`（`:332`） |
| `Executor::confirm` | `src/UserFiles/Services/Executor.php:154` | 校验口令与预览哈希，建 batch+job | ⇐ `POST /admin/delete-batches` |
| `Executor::run` | `src/UserFiles/Services/Executor.php:441` | 逐项 claim/删除/回写 items 与批次计数 | ⇐ tick→claim（`:389`） |
| `Files::create` | `src/UserFiles/Repositories/Files.php:348` | 写 files 账本并同步 token 索引 | ⇐ `Uploader.php:322`、`Sync_Service::adopt`（`:267`） |
| `Folders::write` | `src/UserFiles/Repositories/Folders.php:756` | usage 行锁 + 死锁重试的树写事务入口 | ⇐ create/rename/move/delete/reorder（`:740-744`） |
| `Space_Store::grant_from_order` | `src/UserFiles/Services/Space_Store.php:881` | 订单→space_grants 幂等发放；按渠道写 `source/channel` 与订单字段 | ⇐ `on_payment_success`（`:800`）、`reconcile`（`:2173`） |
| `Space_Store::filter_payment_methods` | `src/UserFiles/Services/Space_Store.php:249` | 按 `sufm_space_allowed_payment_methods` 白名单过滤主题返回的支付方式 | ⇐ `payment_methods`（`:194`）；空结果关闭现金收银台 |
| `Space_Store::on_order_refunded` | `src/UserFiles/Services/Space_Store.php:1268` | 退款：先占位 `refund_pending`，再按渠道补偿钱包 | ⇐ `order_refunded`（`:3016`） |
| `Space_Store::retry_refunds` | `src/UserFiles/Services/Space_Store.php:1672` | 重试 `refund_pending`，条件 UPDATE 抢占订单 | ⇐ `tick`（`:2869`） |
| `Space_Store::reconcile` | `src/UserFiles/Services/Space_Store.php:2173` | 补发 paid-no-grant 订单；每日自动或手动 REST | ⇐ `maybe_reconcile`（`:1838`）、`POST /admin/space/reconcile` |
| `Media_Hooks::guard_delete` | `src/UserFiles/Media/Media_Hooks.php:556` | 附件删除守卫（放行/收养/直删/拒绝） | ⇐ `pre_delete_attachment`（`:136`） |
| `Notifier::deliver_due` | `src/UserFiles/Services/Notifier.php:165` | 领取到期通知并调 Zibll ZibMsg | ⇐ `sufm_tick`（`:330`）、`after`（`:120`） |

### 8.4 入口钩子

| 钩子 | 类型 | 注册位置 |
|---|---|---|
| `admin_init`（maybe_install） | action | `src/UserFiles/Module.php:67` |
| `admin_init`（优先级 20，主题限流导入） | action | `src/UserFiles/Module.php:68` |
| `update_option_sufm_settings` | action | `src/UserFiles/Module.php:69` |
| `cron_schedules` | filter | `src/UserFiles/Module.php:70` |
| `init`（调度 tick） | action | `src/UserFiles/Module.php:71` |
| `sufm_tick` / `sufm_delete_tick` | action（cron） | `src/UserFiles/Module.php:72`、`:75` |
| `rest_api_init` | action | `src/UserFiles/Module.php:99` |
| `after_setup_theme` | action | `src/UserFiles/Module.php:106` |
| `wp`（优先级 20，编辑器注入） | action | `src/UserFiles/Editor/Module.php:35` |
| `wp_handle_upload_prefilter`（50） | filter | `src/UserFiles/Media/Media_Hooks.php:126` |
| `pre_delete_attachment` / `deleted_post` / `rest_pre_dispatch` | filter/action | `src/UserFiles/Media/Media_Hooks.php:136-138` |
| `user_ctnter_main_tabs_array` / `main_user_tab_content_files` | filter/action | `src/UserFiles/Frontend/Tab.php:44`、`:46` |
| `payment_order_success` / `order_refunded` | action | `src/UserFiles/Space_Panel.php:40`、`src/UserFiles/Services/Space_Store.php:3016` |

### 8.5 REST（`sufm/v1`，45 条路径 / 51 个 HTTP 端点）

命名空间注册见 `src/UserFiles/Rest/Controller.php:39`，主路径集中在 `:550-607`，空间套餐由 `register_space_routes` 单独注册（`:48-148`）。这里的 45 是运行时唯一路径数（包含 `/sufm/v1` 命名空间索引），51 是按 HTTP 方法展开的端点数；`/admin/sync` 与 `/admin/space/grants` 各自是一条路径下的 GET/POST 两个端点。

| 权限门 | 完整端点 | 判定函数 |
|---|---|---|
| `can_use_files`（自助） | GET `/summary` `/capacity` `/items` `/folders` `/upload-policy` `/delete-requests` `/delete-requests/{id}` `/operations/{id}`；POST `/uploads` `/folders` `/delete-requests` `/delete-requests/{id}/withdraw` `/batch`；PATCH `/files/{id}` `/folders/{id}` | `Controller.php:614-621` |
| `can_review`（审核） | GET `/admin/delete-requests`；POST `/admin/delete-requests/{id}/review`、`/{id}/revoke` | `Controller.php:628-630` |
| `can_execute`（执行） | POST `/admin/delete-batches/preview`、`/admin/delete-batches`、`/admin/delete-batches/{id}/stop`、`/admin/reconcile`、`/admin/reset-quotas`、`/admin/sync`；GET `/admin/delete-batches/{id}`、`/admin/users`、`/admin/events`、`/admin/sync`；PATCH `/admin/users/{id}/quota` | `Controller.php:637-639` |
| `can_manage_media` | GET/POST `/admin/folders`；PATCH/DELETE `/admin/folders/{id}`；POST `/admin/transfers/preview`、`/admin/transfers`、`/admin/folders/move`、`/admin/folders/reorder`；GET `/admin/transfers/{id}` | `Controller.php:1566` |
| 空间用户（`space_can_use`） | GET `/space/plans` `/space/grants` `/space/orders/{num}/status`；POST `/space/orders` `/space/redeem` | `Controller.php:155-159` |
| 空间审核（`space_can_review`） | GET `/admin/space/grants`；POST `/admin/space/reconcile` | `Controller.php:166-175` |
| 空间管理（`space_can_manage`） | POST `/admin/space/grants`、`/admin/space/grants/{id}/revoke`、`/admin/space/grants/{id}/extend` | `Controller.php:178-187` |
| Picker（`can_pick`） | GET `/picker/items` `/picker/item` | `src/UserFiles/Rest/Picker_Controller.php:72-114` |

> Picker 的判定是「登录 && MANAGE_OWN && editor_enabled」，**不查 `entry_enabled`**（`Picker_Controller.php:105-114`）。它与自助组的关键差异是开关和只读用途，不能概括成唯一差异。运行时验证为 45 条路径、51 个 HTTP 端点（测试站 1.24.4，2026-11-10）。

### 8.6 数据落点

- **option**：`sufm_settings`（`Settings.php:26`）、`sufm_schema_version`（`Schema.php:34`，当前 `1.6.0`）、`sufm_space_settings`（`Space_Settings.php:25`）、`sufm_sync_state`（`Sync_Service.php:31`）、`sufm_backfill_pending` / `sufm_tokens_pending`（`Schema.php:107` / `:117`）、`sufm_media_caps_version`（`Capabilities.php:120`）、`sufm_space_reconcile_last`（`Space_Store.php:40`，每日对账水位）；主题侧读 `zibll_options.brush_limit_upload`（`Theme_Limits.php:27`）。
- **usermeta**：`sufm_quota_bytes`（`Settings.php:31`，读取 `quota_for`（`:267-284`），写入 `Controller.php:1999-2002`）。
- **transient**：`sufm_embed_page`（`Frontend/Providers/Generic.php:34`）。
- **15 张表**（`src/UserFiles/Schema.php:41-59`）：`sufm_media_operations`、`sufm_folders`、`sufm_files`、`sufm_file_tokens`、`sufm_usage`、`sufm_reservations`、`sufm_artifacts`、`sufm_delete_requests`、`sufm_delete_request_items`、`sufm_delete_batches`、`sufm_jobs`、`sufm_events`、`sufm_notifications`、`sufm_rate_limits`、`sufm_space_grants`。

### 8.7 四条主流程

```
① 上传  POST /uploads → Uploader::handle(Uploader.php:112-179) 逐项校验
        → Quota::reserve 锁 usage 行(Quota.php:202-207) → wp_handle_upload
        → Files::create(Uploader.php:322) → 三桶 consume(:344) → Quota::commit(:370)
② 申请  POST /delete-requests → Requests::create 写 requests/items 并 CAS 锁 files/folders
        (Requests.php:277-351)；未确认通知版本则 400/409(:244-250)
③ 审核  POST /admin/delete-requests/{id}/review → approve 先 freeze_items、
        approval_revision+1(Requests.php:738-783)；revoke 仅 APPROVED 可用(:840-878)
④ 执行  POST /admin/delete-batches → preview 快照哈希(Executor.php:95-142)
        → confirm 建 batch+job 并调度单次 sufm_delete_tick(:154/:219/:269)
        → tick→claim→run 逐项删除(:364/:389/:441)
        → finalize_request 结算 completed/partial_failed/needs_review(:954-1005)
```

**后台巡检**：`sufm_tick` 每 60 秒（`src/UserFiles/Module.php:293-300`、`:322-349`）回收过期预留、投递通知（`Notifier.php:165`）、同步对账（`Sync_Service.php:131-207`）、重试待退款授权（`Space_Store::retry_refunds`）并做每日一次订单对账（`maybe_reconcile`）；`sufm_delete_tick` 只补跑 executor（`Module.php:356-360`）。

### 8.8 与其它子系统的接缝

| 接缝 | 方式 | 依据 |
|---|---|---|
| 与 S3 | 附件状态复用 `_sakura_imgopt_s3_*` 系列 postmeta；删除守卫在 S3 入队**之前**决定放行/拒绝 | `src/UserFiles/Media/Media_Hooks.php:556` |
| 与 Sync | `Sync_Service::adopt` 把"磁盘有、库里无"的文件收养并回填 index | `src/UserFiles/Services/Sync_Service.php:26` |
| 与 Admin IA | 向 Registry 的 `files` 模块注册 4 个 renderer（settings/users/queue/log） | `src/UserFiles/Admin/Page.php:71-78` |
| 与 Zibll | 能力探测单例 `Zibll_Bridge`；tab、订单卡、空间面板都走它 | `src/UserFiles/Services/Zibll_Bridge.php:23` |

### 8.9 空间扩容支付收口（历史变更记录；2026-11-08/09；当前代码以 1.27.5 为准）

**结论：空间扩容的现金入口由管理员在后台显式勾选，默认只开放余额；积分入口有独立开关；订单按实际支付渠道记账；退款按渠道补偿；每日对账用租约 + 指数退避；历史 order: 授权提供手动审计/回填命令。** 退款失败（refund_failed）仍坚持人工核对，不自动复活——这是有意保留的资金安全边界，不是遗漏。

> 追溯：2026-11-08 之前的 grant_from_order() 把 source/channel 固定写 'points'，并把 order_id/order_num/price/points 清零；现金（含余额）购买会被记成积分兑换并占用 points_used_bytes() 日限额。该缺陷已在同日的支付收口修复中关闭。2026-11-09 的 1.24.0 又补了两件事：对账失败不再空等 24 小时，以及历史 order: 行的手动回填入口。

#### 8.9.1 支付入口为什么收口

主题 Zibll 的余额、支付宝、微信等支付方式最终都走同一个 ZibPay::payment_order()，成功后统一 do_action('payment_order_success', $order)（wp-content/themes/zibll/zibpay/class/order-class.php:916）。插件无法只靠这个钩子区分「余额」与「外部扫码」；如果继续把主题返回的全部方式暴露在空间面板，就等于让空间订单直接生成支付宝/微信订单。

现行口径是**支付入口收口**：

- 空间扩容默认只开放余额。用户先通过主题充值余额，再用余额购买空间；充值入口仍然是主题的，站点仍然是收款方。
- 外部渠道不是被删除，而是变成管理员显式勾选；勾选后风险（未支付订单、退款、对账）由站点自行承担。
- 积分不是主题支付方式，而是插件自己的兑换产品，有独立开关。

#### 8.9.2 支付入口白名单

| 位置 | 行为 |
|---|---|
| Space_Settings::defaults()（src/UserFiles/Space_Settings.php:68-71） | 默认 allowed_methods = array( 'balance' ) |
| Space_Settings::allowed_methods()（:265-271） | 读取 sufm_space_settings，经 sanitize_methods() 去空、去重、sanitize_key、上限 20 |
| Space_Settings::sanitize()（:386-424） | allowed_methods_present=1 且没有勾选时保存空数组（关闭现金）；字段缺席的 partial 保存保留已存值，不会把未编辑的卡片误清空 |
| 后台「支付方式」卡片（includes/views/userfiles-space-admin.php:233-309） | 左侧遍历主题当前提供的通道生成 checkbox；右侧是积分 points[enabled] 独立开关；隐藏字段 allowed_methods_present=1（:245）区分「全不勾选」与「表单没提交这个字段」 |
| Space_Store::payment_methods()（src/UserFiles/Services/Space_Store.php:194） | 调主题 zibpay_get_payment_methods(21) 并归一为 id => [name]，末尾交给 filter_payment_methods() |
| Space_Store::filter_payment_methods()（:249） | 以 Space_Settings::allowed_methods() 为唯一来源；sufm_space_allowed_payment_methods 只能收窄，不能重新启用未勾选通道；空数组直接关闭现金 |
| Space_Store::purchase()（:311） | 用过滤后的 map 做服务端硬校验；不在 map 内的 method 返回 sufm_space_method_missing（:347-351），不会到达 zibpay_initiate_pay() |

**后台操作**：在「用户文件空间 → 子比专属 → 支付方式」勾选要开放的通道，保存后立即影响前台；全部取消勾选等于关闭现金收银台，前台只保留积分或整个扩容入口。诊断卡片只展示「已启用且主题当前提供」的通道，不再把主题的全部支付方式当成可购买项。

**过滤器只做减法**：

```php
add_filter( 'sufm_space_allowed_payment_methods', function ( $methods ) {
    // 例如测试站要临时隐藏 alipay，即使用户勾选了
    return array_values( array_diff( $methods, array( 'alipay' ) ) );
} );
```

它不能把未勾选的 alipay 重新加回来。旧版本里的 Space_Store::ALLOWED_METHODS 常量已删除，代码中不存在第二个默认白名单。

**积分开关**：Space_Settings::points_enabled()（:280-284）读取 points['enabled']；points_state()（Space_Store.php:3050-3075）的 enabled 同时要求开关打开且 points_per_gb > 0；redeem_points()（:3221-3245）在关闭时返回 sufm_points_off。关闭积分不会清空已配置的积分套餐或价格，只是隐藏入口并拒绝新兑换；已售出的积分授权仍按原有效期生效。

#### 8.9.3 订单发放记账

normalise_order()（Space_Store.php:999）解析 order_id/order_num/user_id/product_id/price/channel/plan_id/label/bytes/days，并补 points 与 pay_detail：points 优先取 other.points，其次取 pay_detail.points；两者都没有且渠道是 points 时，用 round(order_price) 回退。channel 来自主题订单的 pay_type。

grant_ledger_fields()（:846）把归一化结果压成 6 个账本字段，grant_from_order()（:881）用它们参数化 INSERT（:948-966），不再写死字面量：

| 订单渠道 | source | channel | order_id / order_num | price | points |
|---|---|---|---|---|---|
| balance | purchase | balance | 保留 | order_price | 0 |
| points（直兑/主题积分单） | points | points | 保留 | 0 | 实际扣减积分 |
| 其他（管理员勾选后） | purchase | sanitize_key(pay_type)，截 24 | 保留 | order_price | 0 |

meta（:927-936）同步保留 plan_id、order_num、order_id、channel、source、points；grant_key 仍是 order: + order_num，INSERT IGNORE + 唯一键保证同一订单只发一次。

**防重复发放保护仍然有效**：积分直兑会在 redeem_points() 里创建 product_id='sufm_space_points' 的订单并触发同一个成功钩子；该订单的 plan('points') 不存在且 bytes=0，grant_from_order() 返回 false，不会重复发放。改动这段时必须保留该断言。

#### 8.9.4 退款补偿

退款入口是主题 ZibPay::refund_order() 发出的 order_refunded（参数是订单表主键，不是订单号）。Space_Store::register_hooks()（:3014）以优先级 12 挂 handle_order_refunded()，转 on_order_refunded()（:1268）。

```text
order_refunded(order_id)
  → SELECT id/order_num/order_type/user_id/order_price/pay_type/product_id/other/pay_detail
  → 只认 order_type=21
  → UPDATE state='refund_pending' WHERE order_num AND state IN ('active','pending')   ← 原子占位
  → 0 行：直接返回（重复回调/已退款/已撤销）
  → 释放配额 → settle_refund() 按渠道退钱包
      balance → 加 order_price
      points  → 加 points（缺失时 round(price)）
      其他    → 只释放授权，不代付
  → 成功 state='refunded'；失败 attempts+1，满 5 次 state='refund_failed'
```

关键位置：settle_refund() :1407、fail_refund() :1522、wallet_refund_recorded() :1632、retry_refunds() :1672。

**退款失败的人工边界（1.24.0 明确保留）**：retry_refunds() 只挑 state='refund_pending' AND attempts<5 AND updated_at<=now-60，refund_failed 不会被自动拾取；主题订单已经是 status=-2，不会再发一次 order_refunded。这样做的原因是主题钱包先加钱、后写 balance_record，崩溃窗口里自动重试有重复加钱的真实风险。人工处理时应先核对订单、用户与钱包流水；wallet_refund_recorded() 命中则说明钱已经到账，只能把状态修正为 refunded，不能再次加钱。不要简单把 refund_failed 改回 refund_pending：attempts 仍 >=5 时它不会被重试，反而会卡在中间态。

**弱幂等**：主题 balance_record/points_record 最多保留 50 条且没有唯一键，wallet_refund_recorded() 的查重可能因记录被挤出而失效；这是主题数据结构的边界，插件不能单独保证 100% 幂等（详见 §8.9.8）。

#### 8.9.5 每日对账：租约 + 指数退避

maybe_reconcile()（Space_Store.php:1838）仍由 tick()（:2869）调用，但状态从单个时间戳改为数组 option sufm_space_reconcile_last（:40），结构为：

```text
{ last_attempt, last_success, next_attempt, failures, last_alert }
```

调度规则：

1. next_attempt > now 时直接返回，不碰订单表。
2. 到期后先把 last_attempt=now、next_attempt=now+900 写成租约，再执行 reconcile(true,100)（:2173）。进程中途死掉，最多 15 分钟后可再试，不会像旧实现那样锁死 24 小时。
3. 成功且 missing <= healed 且没有更多页：last_success=now、failures=0、next_attempt=now+86400。
4. 成功但 more=true（这一页 100 条已处理完，还有下一页）：next_attempt=now+900 继续排空；不当成失败。
5. 失败或 ready=false 或 missing > healed：failures++，按 RECONCILE_BACKOFF（:48，900/1800/3600/7200/14400/21600 秒）取下一次；封顶 6 小时。
6. space.reconcile.alert 每个失败序列最多每天一条（last_alert）；detail 带 missing/healed/more/error/failures/next_attempt/auto=true。
7. 旧版本写入的标量 option 会迁移为 last_attempt=旧值、next_attempt=旧值+86400，升级后不会立刻重放一次对账。

reconcile() 用 limit+1 查询判断 more（:2199-2261），因此刚好 100 条时不会误判为「还有更多」；ready=false 且 last_error 非空时返回错误并记 space.reconcile.error，不会再被读成「没有缺失订单」。手动 POST /admin/space/reconcile 保留，可随时 dry-run 或 apply。

#### 8.9.6 面板空态与积分入口

Space_Panel::build_data()（src/UserFiles/Space_Panel.php:112）先取 $plans/$methods/$points_state/$points_plans，再计算：

| 标志 | 计算 | 位置 |
|---|---|---|
| cash_ready | 现金套餐非空 **且** 过滤后的支付方式非空 | :137 |
| points_ready | 积分开关打开且单价 > 0 **且** 至少一个可售积分套餐 | :141 |

两者都 false 才返回 null（整个面板不渲染）；否则把两个标志交给模板。模板与脚本的行为：

- 现金套餐、余额支付方式、余额钱包只在 cash_ready 时显示；没有现金方式但有积分时显示提示「余额支付暂未开启，可使用积分兑换。」。
- 积分套餐、积分面板、兑换按钮只在 points_ready 时显示；没有现金套餐或没有支付方式时，默认选中积分 radio。
- assets/userfiles-space.js 的 openDialog() 焦点回退顺序为现金套餐 → 积分套餐 → 支付方式 → data-sufm-space-redeem-gb；confirmPurchase() 在 pointsMode() 为真时直接返回，积分模式不会误建现金订单。

测试站四态实测（Space_Panel::data()/markup()，临时写 sufm_space_settings 后已恢复）：

| 场景 | cash_ready | points_ready | 渲染结果 |
|---|---|---|---|
| 现金 + 积分 | true | true | 8251 字节；2 个支付 radio；默认 balance；现金与积分套餐都在 |
| 仅积分 | false | true | 6408 字节；1 个积分 radio 且默认选中；现金区隐藏；提示出现 |
| 仅现金 | true | false | 6195 字节；1 个余额 radio；积分区隐藏 |
| 都不可用 | false | false | data() === null，markup() 空串 |

**积分开关已经独立**：1.24.0 之前 points_state() 的 enabled 只等于 per_gb > 0，后台的 points.enabled 没有读取点；现在 enabled = Space_Settings::points_enabled() && 0 < per_gb，关闭开关会隐藏入口并让 redeem_points() 返回 sufm_points_off。已售出的积分授权不受影响。

#### 8.9.7 运行验证（历史测试站快照；当前基线见下方）

以下余额/积分/退款/对账探针记录属于当时测试站状态，不应当被当作所有部署的当前数据。

- **余额退款**：测试站创建余额订单 + grant，触发 ZibPay::refund_order()；balance 100→130（+30），state=refunded；重复回调余额不再变化，balance_record 命中 1 条。
- **积分退款**：积分订单 90 分；退款后 points 520→610（+90），balance 不变，points_record 命中 1 条。
- **崩溃窗口**：先手工写入同 order_num 的 space_expand_refund 余额记录，再触发回调；state=refunded，余额不再增加，证明 wallet_refund_recorded() 生效。
- **外部渠道**：alipay 订单只把 grant 置 refunded，balance/points 均无变化。
- **失败重试**：user_id=0 首退得到 refund_pending/attempts=1/error=user_missing；恢复用户并把 updated_at 调旧后 retry_refunds(20) 返回 1，最终 refunded/attempts=2，余额 +30。
- **连续失败**：attempts 2→3→4 保持 refund_pending，第 5 次置 refund_failed，error=user_missing。
- **退款重试占位**：fresh refund_pending（updated_at=now）不被抢占，retry_refunds 返回 0、余额不变；180 秒前的 stale 记录返回 1、余额 +30、state=refunded/attempts=1；第二次重试返回 0、余额不再增加。完整探针见 §10.12。
- **每日对账（退避版）**：成功写 last_success 并排到 24 小时后；失败进入 failures=1/next=+900，第二次失败 failures=2/next=+1800；租约期间的重复 tick() 不改状态；告警只写一次。完整输出见 §10.14-A。
- **历史授权回填**：合成 order: 空字段行 dry-run 为 pending，--apply --yes 后字段恢复为 purchase/balance/order_id=21/order_num=990.../price=30.00/points=0，bytes/state/label 不变；第二次运行 candidates=0。见 §10.14-B。
- **单测（历史记录）**：当时的 `phpunit --filter 'SpaceStoreReconcileTest|SpaceStoreRefundTest|SpaceStorePaymentTest|SpaceSettingsTest'` 全绿；该记录中的 SpaceStoreReconcileTest 为 10 tests / 59 assertions。

**1.27.5 当前基线（2026-11-14）**：当前同一过滤器在工作树运行结果为 **30 tests / 119 assertions，OK**；完整套件为 **470 tests / 2,411 assertions / 6 skipped**。测试站运行时注册 **45 条路径 / 51 个 HTTP 端点**；未认证 REST 返回 401，认证后 summary/capacity/items/picker 返回 200；用户 2/3 的文件、文件夹、picker 数据互不泄漏，交叉读取返回 404。以上是本次测试站和代码版本的证据，不是对所有站点配置的保证。

测试后清理：t_sufm_space_grants 恢复为 1 行（原 points: 授权），t_zibpay_order 恢复为 1 行，临时订单/grant/事件已删；sufm_space_reconcile_last 恢复为测试前的标量 1794152631，wp sufm status 读回 recon_next=1794239031；sufm_space_settings 未留下临时值。

#### 8.9.8 边界与维护要求

1. **预充值余额仍承担资金责任**：充值入口仍是主题的支付宝/微信，站点仍是收款方；退款、发票、消费者权益与资金合规不因「空间只收余额」而消失。本改动只把「空间订单的直接外部支付入口」收口，不等于零风险。
2. **外部渠道不代付退款**：若管理员勾选 alipay/wechat，插件退款只释放授权，钱包不会加钱；外部退款必须在主题/支付平台完成。
3. **主题钱包历史只有 50 条且无唯一键**：wallet_refund_recorded() 的查重可能因记录被挤出而失效；这是主题数据结构的硬边界，不能当成强幂等。
4. **refund_failed 坚持人工处理**：满 5 次后停在 refund_failed，没有自动复活、没有后台一键重开。人工核对订单/用户/钱包后，按「先查流水、再决定是否补账」的顺序处理；不要为了触发重试把状态简单改回 refund_pending。
5. **历史授权只手动回填**：新增 wp sufm grants，默认 dry-run，--apply 才写；只处理 grant_key LIKE 'order:%' 且 order_id=0 或 order_num='' 的行。points: 直兑行没有对应的主题订单，保持原样。回填只写订单号/渠道/金额/积分，不改容量、有效期、状态、标签、grant_key 或 meta。
6. **对账退避不是失败重试队列**：它只控制「下一次自动对账什么时候跑」，不重放退款，也不修复坏订单；space.reconcile.alert 仍是告警，不是自动修复。
7. **改这段代码的固定动作**：更新 tests/Services/SpaceStorePaymentTest.php、SpaceStoreRefundTest.php、SpaceStoreReconcileTest.php、tests/SpaceSettingsTest.php；跑 phpunit、phpstan、phpcs；按《图谱同步说明》重扫图谱；同步更新本节行号与 DECISIONS.md 的 ADR。

#### 8.9.9 历史订单授权的审计与回填（wp sufm grants）

1.24.0 新增的入口在 src/UserFiles/CLI.php:174，命令注册仍是 wp sufm（src/UserFiles/Module.php:116）。命令默认 dry-run，参数：

| 参数 | 作用 |
|---|---|
| --apply | 真正写库；不带时只审计 |
| --limit=<n> | 本次最多检查多少行，默认 100，服务端夹在 1..500 |
| --yes | --apply 时跳过确认提示（自动化用） |

审计范围（Space_Store::audit_legacy_order_grants() :2326）：grant_key LIKE 'order:%' 且 (order_id = 0 OR order_num = '')，按 id 升序。逐行校验（audit_legacy_order_grant() :2414）：

| 状态 | 触发条件 | 是否写库 |
|---|---|---|
| pending | 字段可恢复，当前是 dry-run | 否 |
| filled | --apply 更新成功 | 是 |
| current | 字段已与订单一致，或另一进程已更新（0 行） | 否 |
| key_mismatch | key 不以 order: 开头、后缀为空、或与已存 order_num 冲突 | 否 |
| missing_order | 主题订单表找不到该 order_num | 否 |
| ambiguous_order | 同一 order_num 有多条订单 | 否 |
| order_not_paid | 订单 status != '1'（含已退款 -2） | 否 |
| owner_mismatch | 订单 user_id 与 grant owner_id 不同 | 否 |
| not_space_product | product_id 不以 sufm_space_ 开头 | 否 |
| invalid_order | normalise_order() 返回 null | 否 |
| query_error / write_failed / unavailable | SQL 或数据库异常 | 否 |

写库只更新 order_id/order_num/price/points/source/channel/updated_at，WHERE id = %d；成功后 Space_Rules::flush(owner_id) 并写 space.grant.backfilled 事件（带 grant_id/order_id/source/channel）。CLI 输出逐行 #id key owner=… order=… status=… message，末尾汇总 candidates/pending/filled/skipped，dry-run 追加 (dry run)；审计查询失败时 WP_CLI::error 退出，不会把 SQL 错误读成「没有候选」。

**生产操作建议**：先 wp sufm grants（不加 --apply）核对候选；只对 pending 行执行 wp sufm grants --apply；missing_order/ambiguous_order/order_not_paid/owner_mismatch 必须人工查订单，不绕过。生产当前 4 行历史 grant 中，order: 行字段完整（待回填 0 行），points: 两行是无主题订单的直兑回退，不需要也不应该回填。

#### 8.9.10 运维检查清单

| 场景 | 先看 | 再做 |
|---|---|---|
| 前台没有余额入口 | 「支付方式」卡片是否勾选 balance；诊断卡「已启用购买方式」 | 勾选并保存；确认主题在登录上下文返回余额 |
| 前台出现不想要的外部渠道 | 后台勾选与 sufm_space_allowed_payment_methods 过滤器 | 取消勾选；过滤器只能收窄，不能加回 |
| 积分入口消失 | points[enabled]、points_per_gb、积分套餐 | 打开开关并保留至少一个可售套餐；关闭开关不会清空价格 |
| 对账一直不跑 | wp sufm status 的 recon_last/recon_next/recon_failed | 看 sufm_space_reconcile_last 的 next_attempt；失败按退避等待或手动 REST |
| 退款停在 refund_failed | grant 的 attempts、last_error、主题钱包流水 | 人工核对后补账/修正状态；不要自动重试 |
| 历史 order: 字段为 0 | wp sufm grants | 先 dry-run，再 --apply；不要改 points: 行 |

### 8.10 编辑页文件面板：拖拽插入与右键插入（1.23.0）

编辑页和用户中心不是同一套前端。`src/UserFiles/Editor/Assets.php:28-31` 的注释写明：面板使用自己的 `assets/editor-picker.css` 与 `assets/editor-picker-manager.js`，这两个文件是从 user-centre 的样式/脚本分叉出来的；`Tab` 只负责生成共享的 `sufmData` 配置（`Assets.php:47`）。因此本次把拖拽反馈和落点插入做在编辑页副本里，用户中心 `/user/files` 的 DOM、菜单与拖拽行为保持原样。改这一块时不要顺手去改 `assets/userfiles.js` / `assets/userfiles.css`，否则会把两个入口重新绑在一起。

#### 8.10.1 准入与文件清单

- 准入门在 `src/UserFiles/Editor/Context.php:63-70`：`Context::is_screen()`（Zibll 的 `pages/newposts.php` 模板或 `forum_post_edit` 查询变量，`:29-45`）＋桌面端＋已登录＋`Capabilities::MANAGE_OWN`＋`Settings::editor_enabled()`。`Module::start()`（`src/UserFiles/Editor/Module.php:52-64`）在 `wp@20` 决定是否 `Assets::enqueue()`，并在 `wp_footer@5` 渲染面板。
- `Assets::enqueue()`（`src/UserFiles/Editor/Assets.php:27-55`）只挂三样东西：编辑页专用 CSS、manager、editor-picker；配置对象仍由 `Tab::script_data()` 提供。用户中心走的是 `Frontend/Tab.php:189-221` 的 `sufm-front`，两条资源链互不覆盖。

| 文件 | 职责 | 本次改动 |
|---|---|---|
| `assets/editor-picker-manager.js` | 列表、右键菜单、拖拽源、跟手标签、批量条 | 新增「插入」菜单项、拖拽 badge、`sufm:drag-files` / `sufm:drag-end` 事件、批量条归位修复 |
| `assets/editor-picker.js` | 插入、落点投放层、光标映射 | `insert(forced, point)`、`rangeAtPoint()`、投放层构建与显隐、事件监听 |
| `assets/editor-picker.css` | 编辑页专用样式 | 拖拽行、跟手 badge、投放层、菜单滚动、深色主题与减少动效 |
| `includes/views/editor-picker-app.php` | 面板 SVG sprite | 新增 `#sufm-i-insert` 文档＋加号图标（`:55`） |
| `src/UserFiles/Editor/Assets.php` | 编辑页资源与文案 | 新增 `dropInsert` / `dropInsertHint`，改写 `pickHint`（`:74-75`、`:81`） |
| `src/UserFiles/Frontend/Tab.php` | 共享配置对象 | 只新增 `dragInsert` 文案键（`:358`），不改用户中心 UI |

#### 8.10.2 拖起来之后看到什么

1. **原生拖影被压掉**。`emptyDragImage()`（`editor-picker-manager.js:1872-1880`）创建一个 1×1 透明 canvas，`dragstart` 里用 `setDragImage(..., 0, 0)`（`:1995`）替换浏览器的默认缩略图；CSS 把 `.sufm-drag-ghost-image` 放在屏幕外并设 `opacity:0`（`editor-picker.css:3746-3754`）。这不是多余的一步：不压掉原生拖影，页面里会同时出现两个视觉反馈，跟手标签会被原生缩略图挡住。
2. **跟手标签是真实 DOM，不是 tooltip**。`dragBadgeNode()`（`:1858-1865`）在 `body` 下建 `.sufm-drag-badge`；`moveDragBadge()`（`:1882-1889`）把 `left/top` 设成 `clientX+16` / `clientY+18`，document 的 `drag` 与 `dragover` 都用 capture 监听（`:1891-1892`）。样式在 `editor-picker.css:3710-3729`：固定定位、`z-index:2147483600`、粉紫渐变、白边、白点、入场动画，`pointer-events:none` 保证它不抢投放层的 `drop`。
3. **文案区分文件与文件夹**。文件 badge 显示「拖到正文插入」，多选追加「· N 个」；文件夹仍显示「移动到文件夹」（`:1988-1993`）。文件夹不派发 `sufm:drag-files`（`:2002-2004`），所以不会出现正文投放层，也不会被误插进文章。
4. **拖拽源本身有状态**。源行加 `.is-dragging`（opacity .55、`scale(.985)`、阴影，CSS `:3662-3667`），面板容器加 `is-drag-moving` / `is-drag-file`（`:1998-2000`）；投放目标另用 `.is-drop-target` 高亮（CSS `:3669-3707`）。
5. **文件允许两种落点**。`effectAllowed` 对文件设为 `copyMove`（`:1982-1984`），文件夹仍为 `move`；正文投放层再把 `dropEffect` 收窄为 `copy`（`editor-picker.js:944-946`）。只写 `move` 会和投放层的 `copy` 不兼容，真实浏览器可能直接不触发 `drop`，这是本轮修掉的一个隐性 bug。
6. **拖拽结束统一复位**。`endDrag()`（`:2007-2016`）清 `.is-dragging`、清面板类、清投放目标、移除 badge、置空 `dragMove`，最后派发 `sufm:drag-end`；editor-picker 收到后 `hideDropzone()`（`editor-picker.js:1020`）。

#### 8.10.3 拖到正文：从落点到光标

1. **manager 只负责宣告“拖的是哪些文件”**。`dragstart` 里根据当前选中集合决定 targets：多选且当前行在选中集合内就带全部选中项，否则只带当前行（`editor-picker-manager.js:1968-1977`）；然后 `document.dispatchEvent(new CustomEvent('sufm:drag-files', { detail: { targets } }))`（`:2003`）。
2. **editor-picker 把投放层盖在编辑器 iframe 上**。监听器（`editor-picker.js:1012-1018`）把 targets 映射成 id 列表交给 `showDropzone()`（`:977-1010`）；`showDropzone` 先确认 `activeEditor()` 和 `editorFrame()` 都存在，否则直接返回，不显示遮挡层。`buildDropzone()`（`:922-975`）创建 `.sufm-editor-drop`，文案取 `t.dropInsert` / `t.dropInsertHint`；`placeDropzone()`（`:884-909`）用 iframe 的 `getBoundingClientRect()` 设置 fixed 层的 `left/top/width/height`，并在 `scroll` / `resize` 时重新定位（`:1022-1023`）。
3. **投放层自己接管 dragover/drop**。`dragover` 只在 `dragIds` 非空时 `preventDefault()` + `stopPropagation()`，设 `dropEffect='copy'` 并加 `.is-over`（`:936-949`）。`drop` 先复制 id 列表和 `clientX/clientY`，再 `hideDropzone()` 并调用 `insert(ids, point)`（`:957-970`）。投放层是 fixed 的独立节点，不塞进 TinyMCE 文档，所以不会污染正文 DOM，也不依赖编辑器内部的事件系统。
4. **落点转成编辑器内的 Range**。`editorFrame()`（`:833-849`）先取 `editor.iframe`，部分 TinyMCE 构建返回 null 时退回 `getContentAreaContainer().querySelector('iframe')`，最后才用 `#<editorId>_ifr`。`rangeAtPoint()`（`:851-882`）把 `clientX/clientY` 减去 iframe 的 `rect.left/top` 和 `clientLeft/clientTop`，越界返回 null；然后优先 `caretRangeFromPoint`，不支持时用 `caretPositionFromPoint` 手工构造折叠 Range。
5. **插入复用已有的 `insert()`，只多了一个可选 `point`**。`insert(forced, point)`（`:724-824`）先解析 id（显式数组 / 勾选集合 / 当前单击项），找不到编辑器就 toast 并返回；随后走已有的 REST 读取（`:751-753`），把返回项按「图片优先、其余随后」分组（`:758-769`），在 `point` 存在时取 Range（`:776`），`editor.focus()` 后 `selection.setRng(range)`，没有 Range 就 `collapse()`（`:778-784`），最后 `execCommand('mceInsertContent', false, html)`（`:786`）。插入后折叠光标、`nodeChanged()`、清空勾选与单击项（`:788-797`），并把按钮文案短暂改成「已插入」（`:799-815`）。
6. **失败与取消都有出口**。没有编辑器时 toast「找不到编辑器，请点一下正文再试」（`:741-745`）；REST 失败时恢复按钮并 toast「插入失败，请重试」（`:816-823`）；拖拽取消时 `endDrag()` 统一移除 badge 和投放层，不会留下悬空的遮挡节点。

#### 8.10.4 右键菜单「插入」

- `fileMenu(id)`（`editor-picker-manager.js:507-517`）在菜单最前面新增 `data-sufm-act="insert"` 的按钮（`:509`），图标用 `#sufm-i-insert`（模板 `:55`）。根目录下文件菜单是 6 项（插入/打开/重命名/移动到/复制链接/下载）；在子文件夹里多一项「移到上一级」（`:513`）。
- `runMenuAction()`（`:1496-1508`）识别 `insert` 后调用 `window.SUFMPicker.insert([ id ])`；没有 `point` 参数，因此插入位置就是编辑器当前光标/选区，没有选区时由 `insert()` 的 `collapse()` 落到当前 caret。`window.SUFMPicker` 在 `editor-picker.js:1251-1258` 暴露，`insert` 是其中之一。
- 编辑器未就绪（面板在但 TinyMCE 还没初始化）时，菜单不会静默失败，而是 toast「编辑器还没有准备好，请刷新页面再试。」（`:1503-1507`）。
- 文件夹菜单没有「插入」；文件夹拖拽仍然只用于移动到文件夹，不会把文件夹当附件插入正文。

#### 8.10.5 顺手修掉的批量条异常

这是编辑页 fork 里的既有 bug，不是 1.23.0 新引入的。修复前的 `updateBatch()` 在取消选择时执行 `el.head.insertBefore(el.batch, el.folders || null)`；但 `el.batch` 当时在 `.sufm-head` 下，`el.folders` 却在 `.sufm-scroll` 下，两者不是同一父节点，浏览器抛 `NotFoundError: Failed to execute 'insertBefore' on 'Node'`。选择状态归零、或插入成功后 `clearChecked()` 触发 `updateBatch()` 时都会踩到。

现在的分支（`:874-907`）按状态把批量条放回正确父节点：有选中项时挂到面包屑 `el.path`（`:888-891`）；取消选择时如果它仍在 `el.path`，先移除 `is-batching`，再 `el.head.appendChild(el.batch)`（`:892-898`），并在注释里写明「`el.folders` lives in `.sufm-scroll`, not `.sufm-head`」（`:895-896`）。用户中心没有这条批量条归位逻辑，不受影响。

#### 8.10.6 验证与边界

运行记录见 §10.13（**历史 1.23.0 验证，不是当前 1.27.5 基线**）。摘要：

| 检查 | 结果 |
|---|---|
| 语法 | `node --check` 两个 JS、`php -l` 三个 PHP 文件均通过 |
| 单测 / 静态 | PHPUnit 406 tests / 1185 assertions / 5 skipped；PHPStan 无错误；PHPCS 177 files 0 error |
| 编辑页加载 | `/newposts` 上 `window.SUFMPicker` 存在，manager started，8 个文件 |
| 右键插入 | 文件菜单 6 项，首项为「插入」；插入附件 229 到正文光标 BBB 之后 |
| 拖拽反馈 | badge 文案「拖到正文插入」；源行 `.is-dragging`；投放层与 iframe 矩形一致（407/360/676/504），`dragover` 后 `.is-visible.is-over` |
| 落点插入 | 合成拖拽插入 229；多选 424+423 插入 228/229；原生 CDP `Input.dispatchDragEvent` 插入 229 |
| 用户中心隔离 | `/user/files` 菜单没有「插入」，页面上没有 `.sufm-editor-drop`，UI 与改动前一致 |

**边界**：

1. 面板只认 TinyMCE iframe。块编辑器 `/wp-admin/post-new.php` 根本不会加载 editor-picker 资源（`Context::is_screen()` 在 `is_admin()` 直接返回 false），不是「进入了但只 toast」；纯 textarea（`wp_editor` 的 `tinymce=false`）会挂载面板，但 `activeEditor()` 为空，插入只 toast「找不到编辑器，请点一下正文再试」，也不会出现拖入投放层。详见 §8.10.7。
2. 投放层只覆盖 iframe。拖到页面其他区域不会插入，也不会移动文件；移动文件仍要把文件拖到文件夹行/面包屑上（`.is-drop-target`）。
3. 多选插入不是屏幕视觉顺序。选择模型保留勾选顺序（Shift 连选仍按当前渲染顺序），但 `insert()` 先把图片按勾选顺序放前面，再把其余按勾选顺序放后面；因此「非图片先勾、图片后勾」时，最终正文顺序会与勾选顺序不同。详见 §8.10.7。
4. 无头 Chrome 的鼠标事件无法触发真实 HTML5 `dragstart`；§10.13 的拖拽记录用合成 `DragEvent` 与 CDP `Input.dispatchDragEvent` 覆盖，真实鼠标拖拽由用户在测试站手动确认（m05324）。
5. 本次没有改 REST、数据库、cron 或 PHP 类方法；新增的只是一个图标 symbol、三条文案键和前端逻辑。图谱不收录 JS 函数，因此节点/边/钩子都不变（§13）。

#### 8.10.7 覆盖与插入顺序复核（1.24.2）

2026-11-09 在测试站 `/newposts` 用真实附件补测。测试文件夹 7 个文件：文件行 424/423/422/421/420/419 分别对应附件 229/228/227/226/225/224（`conc-d`…`conc-s`），418 对应 ZIP 附件 222。

| # | 场景 | 操作 | 结果 |
|---:|---|---|---|
| A | 两张图片反 DOM 顺序 | 正文只留 `<p>ORDER:</p>`；Ctrl 点 419（`conc-s`，附件 224）再点 424（`conc-d`，附件 229），点「插入 2 个」 | 正文为 `<p>ORDER:</p>` + `img data-edit-file-id=224` + `img data-edit-file-id=229`；顺序按勾选 419→424，不是 DOM 的 424→419 |
| B | 图片 + ZIP 混选 | 正文只留 `<p>MIX:</p>`；Ctrl 依次点 419（图片）、418（ZIP，附件 222）、424（图片） | 正文为 `img 224` → `img 229` → ZIP 下载块 `data-download-file=222`；图片保持 224→229，非图片排最后，证明 `insert()` 的 `images.concat(others)` 分组生效 |
| C | 纯 textarea | 临时 mu-plugin 把 `wp_editor_settings` 的 `tinymce`/`quicktags` 设为 false，访问 `/newposts?sufm_textarea=1` | 面板仍挂载（`window.SUFMPicker=true`、7 个文件），但 `tinymce.get('post_content')=false`、无 `#post_content_ifr`；勾选 424 后插入只 toast「找不到编辑器，请点一下正文再试」，textarea 值仍是 `TEXTAREA-BEFORE`；派发 `sufm:drag-files` 前后都没有 `.sufm-editor-drop` |
| D | 块编辑器 | 打开 `/wp-admin/post-new.php` | `body` 有 `block-editor-page`，但 editor-picker 的 `script`/`link` 均未加载，`#sufm-picker` / `#sufm-app` 不存在；面板不可达，不是「只 toast」 |

结论：**块编辑器不加载面板；纯 textarea 会加载面板但无法插入；TinyMCE 的插入顺序是「图片优先，组内保留勾选顺序」**。临时 mu-plugin 与 `?sufm_textarea=1` 参数已删除，`/newposts` 恢复 TinyMCE iframe；测试没有保存文章或写数据库。

### 8.11 文件面板快捷多选（1.24.1）

#### 8.11.1 目标与范围

1.23.0 之前，两套文件面板（用户中心 `/user/files` 与编辑页右侧面板）都只能逐个点复选框；批量操作要么全选，要么一个个点。1.24.1 给两套面板补上同一套键盘多选：Shift 连选、Ctrl/⌘ 点选、Ctrl/⌘+A 全选、Esc 清空，并在网格视图补「全选/取消全选」按钮。

这一版有意打破 1.23.0「只改编辑页、用户中心 UI 不变」的边界：用户中心也新增了路径栏提示和网格全选按钮。但两边共用的是同一套交互约定，没有复制出第三种选择模型；数据、REST、菜单、删除流程都没有改。

| 面板 | 脚本 | 选择状态 | 渲染特点 |
|---|---|---|---|
| 用户中心 | `assets/userfiles.js` | `state.selection` 以文件 ID 为键 | 列表/网格可切换，桌面端有独立文件夹栏 |
| 编辑页 | `assets/editor-picker-manager.js` + `assets/editor-picker.js` | manager 的 `state.selection`，外加编辑器侧 `state.checked` 顺序 | 永远 compact；列表/网格；选中项要同步到插入条 |

编辑页是用户中心的 fork，但两边的选择状态并不共享：manager 负责文件行勾选，editor-picker 负责「已选文件按什么顺序插入正文」。因此本轮除了给 manager 加手势，还必须把选择变化回传给编辑器（8.11.4）。

#### 8.11.2 选择模型

两套脚本使用同一组函数名与语义：

| 函数 | 用户中心 | 编辑页 | 作用 |
|---|---|---|---|
| `checkboxOf(target)` | `:807-812` | `:879-884` | 从事件目标找到复选框；支持直接点在 `.sufm-grid-check` / `.sufm-row-check` 包裹上 |
| `selectableIds()` | `:833-847` | `:902-916` | 取当前 DOM 中所有 `[data-sufm-select]`，跳过 disabled，按 DOM 顺序去重 |
| `isSelectable(id)` | `:849-851` | `:918-920` | 判断该文件是否在当前可选集合里 |
| `selectRange(id)` | `:853-875` | `:922-943` | 从 anchor 到目标按 DOM 顺序连续选中 |
| `toggleFile(id)` | `:877-889` | `:945-957` | 单项反转，并把 anchor 设为该项 |
| `clearSelection()` | `:891-896` | `:959-964` | 清空选择与 anchor |
| `allSelected()` | `:898-902` | `:966-970` | 当前可选集合是否已全选 |
| `syncSelectAll()` | `:904-926` | `:972-992` | 同步网格按钮、表头复选框的文案与状态 |
| `selectAll(on)` | `:949-958` | `:1038-1047` | 按 DOM 可选集合全选/取消 |

选择模型有三条硬约束：

1. **顺序来自 DOM，不来自对象键。** 两边的 `selectableIds()` 都用 `root.querySelectorAll('[data-sufm-select]')`，所以 Shift 连选的范围与屏幕顺序一致；搜索过滤、折叠、分页后不在 DOM 里的文件不会被选中。
2. **Shift 只加不减。** `selectRange()` 只对区间内尚未选中的项赋值，已经选中的不会被反选；锚点保持不动（用户中心 `:872`、编辑页 `:940`）。如果 anchor 已经不在当前可选集合（切换文件夹、搜索、刷新列表），则以目标项为起点。
3. **锁定项不可选。** 复选框本身带 `disabled`，`selectableIds()` 再过滤一次；Shift 区间跨过锁定文件时会跳过它，不会把它带进批量操作。

普通点击复选框仍然走原来的 `change` 处理：用户中心 `:1700-1715`、编辑页 `:1796-1811`，在切换后把 anchor 设为该项。也就是说，先普通点一个文件，再 Shift 点另一个，范围从第一个算起。

#### 8.11.3 手势与事件路径

| 操作 | 用户中心 | 编辑页 | 行为 |
|---|---|---|---|
| Shift + 点复选框 | `click` 捕获 `:1732-1748` | `:1828-1844` | `selectRange(id)`；只加不减 |
| Shift + 点文件行/卡片 | 行点击 `:1611-1620` | `:1707-1716` | 目标不是复选框/菜单/动作时，按该行文件 ID 连选 |
| Ctrl/⌘ + 点复选框或行 | 同上 | 同上 | `toggleFile(id)`，并把 anchor 设为该项 |
| Ctrl/⌘ + A | document `:1756-1778` | `:1850-1869` | 当前面板激活时全选可见文件 |
| Esc | document `:1756-1778` | manager `:1850-1869` + editor-picker `:1174-1199` | 有选择时清空；编辑页第一次不关面板 |
| 点「全选」按钮 | 网格工具栏/表头 | 网格工具栏 | `selectAll(!allSelected())` |
| 普通点文件名 | 原有打开/预览逻辑 | 只 pick，不勾选 | 语义不变 |

几个容易踩错的细节：

- **复选框捕获必须在行点击之前。** 复选框的 `click` 用捕获阶段监听（`:1732` / `:1828`），因为行点击也会冒泡到同一个 root；捕获阶段先判断修饰键并 `preventDefault()/stopPropagation()`，避免一次手势被解释成两次选择。
- **浏览器会在 `click` 前先翻转复选框。** 鼠标按下时浏览器已经做了 pre-click activation；即使 `preventDefault()` 会在事件结束后恢复 `checked`，它仍可能覆盖 `updateBatch()` 刚写入的状态。两个 fork 都在捕获分支末尾加了 `window.setTimeout(updateBatch, 0)`（用户中心 `:1747`、编辑页 `:1843`），等浏览器恢复完再按 `state.selection` 重绘一次。这是本轮修掉的真实缺陷，不是防御性代码。
- **编辑页文件名有自己的捕获监听。** `editor-picker.js:1049-1068` 原来对文件名点击 `preventDefault()/stopPropagation()`；现在遇到 Shift/Ctrl/⌘ 直接 `return`，把事件让给 manager 的行点击逻辑，否则修饰键手势到不了选择层。普通单击仍然只 pick。
- **Esc 分层。** 用户中心先关菜单，再清选择；编辑页 manager 的 Esc 只关菜单并给事件打 `__sufmMenuClosed`，editor-picker 看到这个标记就 `return`，否则在有选中项时清空选择并阻止默认行为，**不关面板**；第二次 Esc 才走原来的 `userClose()`。这样用户不会因为想取消选择而丢掉整个面板。
- **Ctrl/⌘+A 的激活条件。** 只在指针最后落在面板内（`state.pointerInside`）或事件目标在面板内、且不在输入框/文本域/下拉/contenteditable/TinyMCE 里时接管；请求进行中（`requestsMode`）或有菜单打开时不抢快捷键。输入框里的全选仍然交给浏览器。

#### 8.11.4 编辑器的选择同步

编辑页面板有两份状态，必须保持一致：

1. manager 的 `state.selection` 决定哪些行显示勾选、批量条显示多少项。
2. editor-picker 的 `state.checked` 决定插入正文的顺序（数组顺序就是插入顺序）。

同步机制是自定义事件：

- manager 的 `updateBatch()` 在每次选择变化后触发 `sufm:selection-changed`（无批量条分支 `:996`，正常分支末尾 `:1035`）。
- editor-picker 监听该事件（`:1284`），调用 `updatePicked()`，从 DOM 里已勾选的 `[data-sufm-select]` 重建 `state.checked`，再刷新插入条计数与顺序。
- manager 监听 `sufm:file-picked`（`:1871-1874`）：编辑页普通单击某文件时，把 anchor 设为该文件，这样「先单击 A，再 Shift 点 B」也符合直觉。

这样批量条的数字与实际插入内容不会脱节；用户中心没有编辑器，不需要这层同步。

#### 8.11.5 界面与文案

- 用户中心路径栏新增 `.sufm-path-tools`：提示「Shift 连选 · Ctrl/⌘ 点选 · Ctrl/⌘A 全选」和网格专用的「全选/取消全选」按钮（`includes/views/userfiles-tab.php:164-166`）。提示在 1199px 以下隐藏，按钮保留；窄屏仍可用复选框逐项选择。
- 编辑页路径栏同样有 `.sufm-path-tools` 与全选按钮（`includes/views/editor-picker-app.php:165-166`），但不放长提示；提示语并入 `pickHint`：「单击选中，双击插入；Shift 连选，Ctrl/⌘ 点选」（`src/UserFiles/Editor/Assets.php:81-83`）。
- 文案键：用户中心 `selectAllShort` / `selectNoneShort` / `selectHint`（`src/UserFiles/Frontend/Tab.php:390-392`），编辑页 `selectAllShort` / `selectNoneShort`（`src/UserFiles/Editor/Assets.php:81-82`）。
- 批量态下编辑页隐藏 path-tools（`assets/editor-picker.css:5406`），把空间让给批量条。
- 列表与网格的复选框样式不同：列表 14px 方框，网格 19px 圆形。1.24.1 在 `assets/userfiles.css:4594-4606` 与 `assets/editor-picker.css:5679-5690` 末尾加了高优先级护栏，把列表行/表头锁成 14px、3px 圆角；即使某行被误加 `.sufm-grid-check` 或旧缓存残留，也不会变成网格的圆形。网格仍保持 19px/50%。

#### 8.11.6 验证与边界

运行记录见 §10.15（**历史 1.24.1 验证，不是当前 1.27.5 基线**）。摘要：

| 检查 | 结果 |
|---|---|
| 用户中心手势 | 17/17：普通勾选、Shift 连选、跨锁定项跳过、Ctrl 点选、Ctrl+A、Esc、网格全选/取消、表头全选 |
| 编辑页手势 | 24/24：列表/网格、行点击、文件名修饰键、菜单打开时的 Esc、选择同步到批量条 |
| 响应式 | 用户中心 1440/1024/782/390 无横向溢出；提示在 1024/782 隐藏，按钮仍可用 |
| 编辑页准入 | 面板只在 ≥1360px 挂载（`src/UserFiles/Editor/Assets.php:64`、`assets/editor-picker.js:37`）；1024/782 不显示面板，不涉及本功能 |
| 样式护栏 | 列表 14x14/3px；人为给行加 `.sufm-grid-check` 仍 14/3；网格 19x19/50% |
| 静态检查 | PHPUnit 423 tests / 1257 assertions / 5 skipped；PHPStan OK；PHPCS 177 files 0 error；`node --check` 三个 JS；`git diff --check` 无输出 |

**边界**：

1. 只对「当前已加载且可见」的文件生效。切换文件夹、搜索、折叠后，不在 DOM 里的文件不会被 Ctrl+A 或 Shift 区间带上；需要先回到对应列表。
2. 不做框选、长按多选或触屏多选模式。框选会和拖到文件夹/拖到正文冲突；触屏第一版继续用复选框。Shift 连选依赖硬件键盘。
3. 普通单击的语义没有改：用户中心点空白卡不选中，编辑页点文件仍然只 pick。修饰键手势只在复选框、行/卡片的非菜单非动作区域生效。
4. 用户中心本轮也新增了提示和网格全选按钮，这是 1.24.1 有意扩大的范围；除此之外用户中心 UI 未改。两边的菜单、批量操作、删除审核流程都不受多选影响。
5. 选择状态不持久化。刷新、重新打开面板、切换文件夹都会按新的 DOM 重建；anchor 只在当前列表生命周期内有效。
6. 本次没有改 REST、数据库、cron 或 PHP 类方法；图谱不收录 JS 函数，节点/边/钩子都不变（§13）。

---

## 9. 横切：数据落点、钩子、REST、CLI 与 Cron

### 9.1 数据落点总表（23 张自定义表）

测试站实测 23 张（15 张 `sufm_*` + 6 张 `sio_sync_*` + 2 张 `sio_s3_*`，命令见 §10.2）：

| 表 | 归属 | 用途 | 建立位置 |
|---|---|---|---|
| `sufm_media_operations` | UserFiles | 媒体库操作流水 | `src/UserFiles/Schema.php:41-59` |
| `sufm_folders` | UserFiles | 虚拟文件夹树（parent 邻接表） | 同上 |
| `sufm_files` | UserFiles | 文件账本（归属、字节、状态、token） | 同上 |
| `sufm_file_tokens` | UserFiles | 文件访问令牌索引 | 同上 |
| `sufm_usage` | UserFiles | 每用户配额账本（used/reserved/revision） | 同上 |
| `sufm_reservations` | UserFiles | 配额预留（带租约） | 同上 |
| `sufm_artifacts` | UserFiles | 附件产物（缩略图等）清单 | 同上 |
| `sufm_delete_requests` | UserFiles | 删除申请（审核状态机） | 同上 |
| `sufm_delete_request_items` | UserFiles | 申请明细（冻结/解冻） | 同上 |
| `sufm_delete_batches` | UserFiles | 执行批次（口令、预览哈希） | 同上 |
| `sufm_jobs` | UserFiles | 异步作业（claim/attempts） | 同上 |
| `sufm_events` | UserFiles | 审计事件 | 同上 |
| `sufm_notifications` | UserFiles | 通知发件箱（重试） | 同上 |
| `sufm_rate_limits` | UserFiles | 三桶限流计数 | 同上 |
| `sufm_space_grants` | UserFiles | 扩容发放记录 | 同上 |
| `sio_sync_index` | Sync | 本地/远端对象索引（键集分页） | `src/Sync/Schema.php:87-201` |
| `sio_sync_scans` | Sync | 扫描任务与游标 | 同上 |
| `sio_sync_counts` | Sync | 按状态聚合计数 | 同上 |
| `sio_sync_jobs` | Sync | 动作队列（幂等 + 退避） | 同上 |
| `sio_sync_events` | Sync | 同步审计事件 | 同上 |
| `sio_sync_operations` | S3 / Sync | 远端变更准入记录 | `src/Sync/Schema.php:188-201` |
| `sio_s3_deletes` | S3 | 远端删除补偿队列 | `src/S3/Delete_Jobs.php:109` |
| `sio_s3_upload_events` | S3 | 上传结果事件（成功/失败逐条追加，快照按 SQL 聚合） | `src/S3/Upload_Metrics.php:79-115` |

> 表名在源码里以 `$wpdb->prefix . 'sufm_files'` 形式拼接（`src/UserFiles/Schema.php:41`），前缀随站点变化；测试站前缀为 `t_`。

**非表落点**（分章详见 §4.5 / §5.5 / §6.5 / §7.5 / §8.6）：option 约 60 个、postmeta 约 13 个（8 个 `_sakura_imgopt_s3_*` + `_sakura_imgopt_backup` / `sakura_imgopt_log` / `_sakura_imgopt_orig` + `sufm_quota_bytes` + 只读 `_wp_attached_file`）、transient 7 个、上传目录 1 个子目录（`sakura-imgopt-backup/`）。

### 9.2 钩子分布（207 条）

| 模块 | 钩子数 | 说明 |
|---|---:|---|
| `includes/` | 67 | 旧层：图片门面 7 + 缩略图装配 8 + S3 设置页 9 + 媒体库/设置页 AJAX 等 |
| `src/UserFiles` | 62 | 最大；REST 只注册 1 个 `rest_api_init`，其余是 cron、媒体库、Zibll、支付 |
| `src/S3` | 47 | admin-post 10 + ajax 12 + 附件 3 + cron 2 + 同步中心 10 + 其余（含失败观测与媒体下载） |
| `src/Sync` | 20 | 3 个附件钩子 + 7 个 cron + `cron_schedules`/`init`/`admin_init` + 4 条附件收养过滤（1.25.5 起的每月校准占了 5 条：`cron_schedules`、`init`、`admin_init`、`sakura_imgopt_sync_calibrate`、`sakura_imgopt_sync_calibrate_now`） |
| `src/Admin` | 5 | `admin_menu` / `admin_enqueue_scripts` / `admin_init` / `admin_body_class` / `admin_page_access_denied` |
| `src/Thumbnail` | 1 | 只有主题缓存桥（`the_post`） |
| `src/`（根） | 1 | `sakura_imgopt_daily_backup_cleanup`（回调在 `src/Plugin.php:112`，排程在 `src/Activation.php:119-120`） |
| 未归属（模板/函数） | 4 | `sakura-tools.php` 根文件的闭包（`:25`、`:96`、`:103`、`:121`） |

完整 207 条见**附录 B**（按模块分组，含文件:行、优先级、回调）。这 207 条是具名注册（150 `add_action` + 57 `add_filter`）；`add_submenu_page` 与 `Hook_Loader` 的 4 处通用转发不计入。

### 9.3 REST、CLI 与 Cron

**REST**：只有 `sufm/v1` 一个命名空间，实测 **45 条路由 / 51 个端点**（§10.4）；分组见 §8.5。S3 与 Sync **不提供 REST**，全部走 admin-ajax / admin-post。

**WP-CLI**（仅 `WP_CLI` 时加载）：

| 命令 | 注册位置 | 用途 |
|---|---|---|
| `wp sakura-sync …` | `src/Plugin.php:105-111` → `includes/class-s3-cli.php` | 扫描/索引/校验/对账 |
| `wp sakura-thumb …` | 同上 | 缩略图探测与批量 |
| `wp sakura-s3 …` | `includes/class-s3-cli.php:501` | 连接测试、迁移、删除队列 |
| `wp sufm …` | `src/UserFiles/CLI.php:23` | 诊断、对账、历史授权审计（`grants`）、导入 |

**Cron（实测 7 条插件事件，§10.3）**：

| 事件 | 周期 | 调度位置 |
|---|---|---|
| `sufm_tick` | 1 分钟 | `src/UserFiles/Module.php:293-300` |
| `sufm_delete_tick` | 单次（按需） | `src/UserFiles/Services/Executor.php:269` |
| `sakura_imgopt_s3_delete_tick` | 1 分钟 | `src/S3/Delete_Worker.php:46-51` |
| `sakura_imgopt_sync_verify_tick` | 5 分钟 | `src/Sync/Verify_Worker.php:76` |
| `sakura_imgopt_s3_alert_check` | 15 分钟 | `src/S3/Alerting.php:364-370` |
| `sakura_imgopt_sync_cleanup` / `_daily_reconcile` | 1 天 | `src/Sync/Scan_Worker.php:79-80` |
| `sakura_imgopt_daily_backup_cleanup` | 1 天 | `src/Activation.php:119-120`（回调 `src/Plugin.php:115`） |

> `sufm_delete_tick` 在实测里显示为 `now`：它是"确认执行时调度一次"的单次事件（`Executor::confirm` → `:269`），不是周期任务。

---

## 10. 运行时验证记录

**边界声明**：生产环境为**只读验证**——只执行查询、纯函数调用与 GET 请求，没有触发任何写路径。日常命令都在测试站 `/root/sio-e2e` 执行，插件以软链挂载到 `/root/work/sakura-tools`（同一份代码）。测试站上另补做了一次写验证：2026-11-08 的 5 张并发上传（§10.6），数据只落在测试站自己的 MariaDB 与 MinIO，未触及生产。

> **版本说明**：§10.1 的版本号在 2026-11-09 复核为 1.24.3，2026-11-10 发布 1.24.4；§10.4–§10.12 的命令与输出仍记录于 1.22.8（2026-11-08），§10.13 记录于 1.23.0（提交 `2fec6a3`），§10.14 记录于 1.24.0（提交 `1f88ca8`），§10.15 记录于 1.24.1（提交 `427101c`），§10.16 记录于 1.24.2（代码基线 `dfb3cf4`，运行期版本随后提升），§10.17 的三条验证补测同样记录于 1.24.2。1.24.3 只改卸载删表循环与回归测试，没有新增类、方法、钩子、REST 路由或数据表。1.24.4 增加覆盖升级自愈（`Upgrade_Guard`），不改变 §10.4–§10.17 的业务链路；§10.18 记录升级检测与并发锁的运行验证。1.24.0 相对 1.23.0 增加支付方式勾选、积分独立开关、对账退避与 `wp sufm grants`，改变的是 `src/UserFiles` 的方法与 CLI，不改变路由、钩子集合或数据表；写验证只覆盖对账与回填，没有重跑上传/删除/退款链路。1.24.1 只改文件面板的 JS/CSS/视图与文案，没有改 PHP 类方法、路由、钩子、表或 REST，因此 §10.4–§10.14 的历史记录无需重跑；1.24.2 只增加只读运维入口与指标，没有改上传、删除、同步的业务写路径。表格里出现的旧 `?ver=` 是当时观测值。

### 10.1 环境与激活状态

```
$ cd /root/sio-e2e && ./e2e.sh wp plugin get sakura-tools --fields=name,status,version
name:      sakura-tools
status:    active
version:   1.24.3
```

WordPress 核心与 PHP 版本、MariaDB 127.0.0.1:13316、MinIO 127.0.0.1:19010 均正常；后台 `admin` 可登录（用于 §10.4 的路由检查）。

### 10.2 数据表（23 张）

```
$ ./e2e.sh wp db query "SHOW TABLES LIKE 't_sufm_%'; SHOW TABLES LIKE 't_sio_%';"
t_sufm_artifacts            t_sio_s3_deletes
t_sufm_delete_batches       t_sio_s3_upload_events
t_sufm_delete_request_items t_sio_sync_counts
t_sufm_delete_requests      t_sio_sync_events
t_sufm_events               t_sio_sync_index
t_sufm_file_tokens          t_sio_sync_jobs
t_sufm_files                t_sio_sync_operations
t_sufm_folders              t_sio_sync_scans
t_sufm_jobs
t_sufm_media_operations
t_sufm_notifications
t_sufm_rate_limits
t_sufm_reservations
t_sufm_space_grants
t_sufm_usage
```

> `t_sio_s3_upload_events` 是升级后由 `Upload_Metrics::maybe_install()` 惰性创建的：建表前不存在，第一次 `snapshot()` 或 `record_batch()` 后出现，并把 `sakura_imgopt_s3_upload_schema` 写成 `2.0.0`（§10.11）。

### 10.3 Cron（7 条插件事件已排程）

```
$ ./e2e.sh wp cron event list --fields=hook,next_run_relative --format=csv | grep -E '^(sakura_|sufm_)'
hook,next_run_relative
sakura_imgopt_s3_delete_tick,now
sufm_tick,now
sakura_imgopt_sync_verify_tick,now
sakura_imgopt_s3_alert_check,now
sakura_imgopt_sync_cleanup,"4 hours 55 minutes"
sakura_imgopt_sync_daily_reconcile,"5 hours 5 minutes"
sakura_imgopt_daily_backup_cleanup,"5 hours 50 minutes"
```

### 10.4 REST 与后台路由

```
$ ./e2e.sh wp eval '$r = rest_get_server()->get_routes(); /* 统计 /sufm/v1 前缀 */'
sufm_routes=45 endpoints=51
```

后台 11 条路由（GET，带管理员 cookie）：系统 overview、图片 rules/thumb/backup/stats、存储 connection/sync/deletes、文件空间 settings/users/queue —— **全部 HTTP 200、无 PHP fatal/warning**。页面的 `h1` 是模块名，tab 是子页名，两者并非同名；原“标题与 tab 名一致”的表述不准确。其中一次请求命中了旧 slug 跳转（`page=sakura-tools-optimize` → 新路由），与 §3.5 的 `legacy()` 映射一致。

### 10.5 函数级探针（12 项，`wp eval-file /tmp/fn-probe.php`）

| # | 探针 | 期望 | 实测 | 结论 |
|---:|---|---|---|---|
| 1 | `Animation_Detector::is_animated(jpg)` | false | false | ✅ |
| 2 | `Animation_Detector::is_animated(webp)` | 视文件而定 | false | ✅（该 webp 为静态图） |
| 3 | `Checksums::normalize_etag('"abc"')` | `abc` | `abc` | ✅ 去引号 |
| 4 | `Checksums::is_md5_etag(32 hex)` / `('abc-2')` | true / false | true / false | ✅ 不误判 multipart |
| 5 | `Kind::of` 六例 | image/video/archive/other | image, image, video, archive, other, image | ✅ 全对 |
| 6 | `Credential_Cipher::encrypt` 前缀 | `sio1s:` | `sio1s:1HOd…` | ✅ |
| 7 | 密文往返 `decrypt(encrypt(x)) === x` | true | true | ✅ |
| 8 | `Options` 读取 | enabled/configured/profile | true / true / `prof_1d60d87b0606` | ✅ 15 个选项键 |
| 9 | `Index_Repository::summary(profile)` | pending/bytes | pending 2 / 22,389,628 B / synced 0 | ✅ 与索引表一致 |
| 10 | `Thumbnail\Settings::effective_mode` | provider/mode/sizes | minio / none / 4 | ✅ 未开重写，符合预期 |
| 11 | `Uninstaller::monitoring_options()` | 3 | 3 | ✅ |
| 12 | `Space_Rules::limit_for(1)` + `Quota::snapshot(1)` | 512 MB 档 | limit 536,870,912；used 22,389,628；quota 536,870,912；available 514,481,284 | ✅ used + available = quota |

**补测（2026-11-09）**：配额扣减（`Quota::commit`）、删除审核（`Requests::approve` → `Executor::run`）、卸载清理（`Uninstaller`）三条已在 §10.17 运行验证，不再是只有静态阅读；生产环境仍从未执行这三条写路径（生产只读）。上传 → S3 PUT 已在测试站跑通（§10.6），生产同样未执行。

**2026-11-10 环境漂移复核**：上表是 2026-11-08 的实测快照，不是当前测试站的固定值。复核时 `s3_options` 已全部关闭（`enabled=false`、`provider=cloudflare`、bucket 与 endpoint 为空），管理员的空间上限也已调整（`Space_Rules::limit_for(1)` 当前为 15,992,881,152 B，不再是 512 MB 档）；`Options`、`Thumbnail\Settings`、`Space_Rules` 的返回值随环境变化。函数行为本身仍成立：密文往返、ETag 归一化、Kind 分类与配额恒等式均可重复验证，但不要把表中的具体数值当成当前环境值。

### 10.6 并发上传与钩子消费方（补充验证，2026-11-08）

**A. 并发上传（测试站 + 本地 MinIO）**

- 环境：`/root/sio-e2e`，PHP 8.4 + `php -S`（`PHP_CLI_SERVER_WORKERS=4`），S3 指向 `127.0.0.1:19010` 的 MinIO（bucket `sio-e2e`）；图片设置 `enable_compression=1`、`convert_format=webp`、`quality=82`、`backup_enabled=1`。
- 方法：用 `wp eval-file` 生成 admin 的登录 cookie 与 `media-form` nonce（必须先 `wp_set_current_user(1)` 再 `wp_create_nonce('media-form')`，否则 nonce 按 uid 0 计算，上传返回 403「您点击的链接已过期」），再用 5 个 curl 进程同时 POST `/wp-admin/async-upload.php`，每个请求一张 1600×1200 的 JPEG（720–834 KB）。
- 结果：5 个请求全部 HTTP 200，返回附件 ID 225–229；单请求耗时 0.84s / 0.85s / 1.70s / 2.57s / 0.89s（4 个 worker，2 个排队），总墙钟 2.58s。逐附件核对：`_sakura_imgopt_backup`（origin `.jpg` → converted `.webp`）、`sakura_imgopt_log`（`status=success`、`engine=imagick`、压缩率 82.2%–83.2%）、`_sakura_imgopt_s3_status=synced` 与 4 个 `_sakura_imgopt_s3_objects` 全部齐全；`sio_sync_index` 新增 20 行（5 附件 × 4 对象），磁盘上 20 个 `.webp` 与 5 个备份原图。
- 结论：并发上传**没有**复现「待写记录匹配失败」。原因在代码：`pending_records` 是 `Metadata_Processor` 的实例属性（`src/Image/Metadata_Processor.php:32`），每个请求各自构造一份（`includes/class-image-processor.php:123`），进程间不共享；`async-upload.php` 一次只处理一个文件，flush 时必然是「1 条记录 + 同路径精确命中」（`src/Image/Metadata_Processor.php:230-234`）。
- 未覆盖：单请求一次上传多张（循环调用 `wp_handle_upload`）的调用方。只有这种场景会让待写记录 `count > 1`，此时只剩精确匹配与 `-scaled` 兜底（`src/Image/Metadata_Processor.php:236-251`），对不上就丢弃；本次未构造运行。

**B. `sakura_imgopt_metadata_budget_exhausted` 的消费方**

- 静态：全仓（排除 `tests/`、`stubs/`、`docs/`）只有触发点 `src/Image/Metadata_Processor.php:139`；`tests/MetadataProcessorBudgetTest.php:64` 仅断言动作发生。
- 运行时：`wp eval-file` 打印 `$wp_filter`，生产站与测试站对 `sakura_imgopt_metadata_budget_exhausted` 与过滤器 `sakura_imgopt_metadata_budget` 均为 `NONE`。
- 站级静态：`grep -rn` 扫 `/www/wwwroot/www.nboke.com/wp-content`（排除本插件）0 命中，测试站同样 0；生产激活插件为 object-cache-pro、sakura-database-care、sakura-tools、tinymce-advanced、zibll-site-enhance，主题 zibll，均不消费它。
- 结论：**本站没有外部消费方**；该 action 与过滤器是公开扩展点（供站外或后续插件接入），不是内部依赖。站外是否存在消费者无法从本站穷举，只能确认「当前站点与已安装代码没有」。

**2026-11-10 物证复核**：附件 225–229 仍存在，但当前只剩 WordPress 原生 attachment meta（`_wp_attached_file` 等），当轮记录的四类 Sakura meta 已不在库中；`sio_sync_index` 当前也是 0 行，没有当时记录的 20 行 `conc-…` 对象。上述上传结果应视为当轮快照，不能从当前测试库复现。

### 10.7 缩略图 provider 与 HTML 旧引擎（补充验证，2026-11-08）

环境：测试站 `/root/sio-e2e`（PHP 8.4.27 内置服务器 + `PHP_CLI_SERVER_WORKERS=4`，MariaDB 13316，MinIO 19010，主题 zibll，插件软链到 `/root/work/sakura-tools`）。方法：临时 mu-plugin 在 `template_redirect@5`、`shutdown@5`、`wp_ajax(_nopriv)_ajax_widget_ui@5` 记录 `ob_get_level()` 与 `ob_list_handlers()`；另用 CLI 直调 `Html_Filter::filter()`、`Providers::all()`、`Settings::make_provider()` 与 `provider_id()`。验证后删除探针并还原全部选项。

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 1 | 整页缓冲确实开启 | `engine=html`、`enabled=1`、`upload_url_path=https://demo.77il.cn`，GET `/` | `template_redirect@5`：level=2，handlers=[default output handler, `Sakura\ImageOptimizer\Thumbnail_Optimizer_Module::filter_list_html`]；`shutdown@5` level=0 |
| 2 | 改写了什么 | 同上，与基线首页对比 | 主题占位图 `.../themes/zibll/img/thumbnail.svg` 的 src 与 data-src 都被加上 `?w=250&h=250&mode=crop&pos=auto`；`mode=crop` 计数 0 → 2 |
| 3 | feed 闸门 | GET `/?feed=rss2` | `template_redirect@5`：level=1，只有 default handler（`:110` 的 `is_feed` 生效） |
| 4 | url 引擎反例 | `engine=url`、`enabled=1`，GET `/` | 无模块 handler；`mode=crop`=0 |
| 5 | enabled=0 反例 | `engine=html`、`enabled=0`，GET `/` | 无模块 handler（`can_filter()` 拦截） |
| 6 | 无 host 反例 | `engine=html`、`enabled=1`、`upload_url_path` 为空 | 无模块 handler（`media_host()` 为空） |
| 7 | 侧栏 AJAX 缓冲 | `scopes.sidebar=1`，GET `admin-ajax.php?action=ajax_widget_ui&id=block&index=2` | `wp_ajax_nopriv@5`：level=2，handlers=[default, `...::filter_sidebar_ajax_html`]；同一请求在 `scopes.sidebar=0` 时只有 default |
| 8 | 作用域改写 | CLI：`Html_Filter::filter()` 跑合成 HTML（`posts-row > posts-item.list > item-thumbnail` 内的 a/b 图、`.other` 内的 c 图、`.sidebar` 内的 d 图） | full 模式：a、b、d 被改写，c 不动；`force=true`：a、b、c、d 全改；suffix 均为 `?w=250&h=250&mode=crop&pos=auto` |
| 9 | provider 枚举 | CLI：`Providers::all()` / `exists()` | 10 个键（cloudflare…bitiful）；`exists('foo')=false`、`exists('minio')=true` |
| 10 | 工厂分支 | 活动 profile 的 provider 依次改为 minio / bitiful / bogus | minio → `Generic`；bitiful → `Bitiful`；bogus → `Generic`（绕过保存校验的未知值） |
| 11 | 死字段 | 缩略图 option 的 `provider` 改 `bitiful`，S3 profile 仍为 minio | `provider_id()=minio`、工厂 `Generic`（缩略图自己的 provider 不参与） |

补充说明：

- 第 1、7 条里叠在插件缓冲之下的 `default output handler` 来自测试站 PHP 的 `output_buffering=4096`（探针页 `ini_get('output_buffering')` 证实），不是 WordPress 或本插件所开；CLI 的 `output_buffering` 为 0，所以只有 HTTP 请求能看到这一层。
- 11 条全部是只读或临时配置验证：结束后已还原 `s3_options` 与 `sakura_imgopt_oss_thumb`、删除 mu-plugin 与日志；还原后首页 `mode=crop` 计数回到 0。
- 未覆盖：`is_trackback` 分支（不构造 trackback 请求无法走到）；`wp-admin` 标签页不经过 `template_redirect`，`:107` 的 `is_admin` 判断属防御性代码，运行期没有可对照页面。


### 10.8 §6.8 清理的验证记录（2026-11-08）

环境同 10.7（测试站 `/root/sio-e2e`，PHP 8.4.27，MariaDB 13316，MinIO 19010，插件软链到 `/root/work/sakura-tools`）；静态检查在开发工作树 `/root/work/sakura-tools` 执行。

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 1 | 清理前测试基线 | `vendor/bin/phpunit --no-coverage` | 395 tests / 1124 assertions / 5 skipped，全绿 |
| 2 | 清理后回归 | 同上（删 1 个测试文件、改 3 个测试） | 391 tests / 1120 assertions / 5 skipped，全绿 |
| 3 | 语法检查 | `php -l` 13 个改动文件 | 全部 `No syntax errors detected` |
| 4 | 静态分析 | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors` |
| 5 | 代码规范 | `vendor/bin/phpcs` 改动文件 | exit 0（5/5 文件通过） |
| 6 | CLI 输出收口 | `./e2e.sh wp sakura-sync stats --format=table` | `Today: 30 ok, 0 failed, rate 0.0%, avg 0 ms, max —, 1.22 MB`；`Last 14 days: 30 attempts, 0 failed, rate 0.0%`；**无 Backlog 行** |
| 7 | 两套指纹不同 | `wp eval-file /tmp/sio-hash-probe.php`（只读） | 上传字段 `active_profile_id\|provider\|bucket\|endpoint\|upload_path`，hash `f1fc293a7718bd13ee94d72b0988a7fd721d12e4eb7f97070109ac0ba6ac76aa`；删除字段 `provider\|bucket\|endpoint\|region`，hash `57df339c4d010edd54473161b2f6bbc2d0084d16969b2fe3dbf8412d360c6c6d`；`equal=no` |
| 8 | 遗留数据现状 | 同一探针 | `legacy_jobs_postmeta_rows=0`；`previous_upload_path=NULL`；`previous_upload_url_path` 为空字符串 |

补充说明：

- 第 7 条只读调用两个类的纯函数并回显字段名，未写任何 option/postmeta；profile 为 `prof_1d60d87b0606`（测试站 MinIO）。
- 第 8 条说明测试站没有 1.12.0 之前的遗留任务，`Storage::maybe_migrate` 的迁移分支本轮没有可触发的数据；该分支按静态阅读保留，未做破坏性造数。
- `Upload_Metrics` 的失败/死信计数仍保留在结构里（`record_batch()` 支持非 ok 样本），但生产唯一调用方 `Upload_Observer` 固定 `ok=true`——这是清理当轮发现的可观测性缺口。该缺口已在后续「上传指标并发修复」中关闭（§6.9、§10.11），本条保留为当轮记录，不再代表当前实现。

### 10.9 §7.7 三项的运行期验证记录（2026-11-08）

环境同 §10.7：测试站 `/root/sio-e2e`（PHP 8.4.27 内置服务器 + `PHP_CLI_SERVER_WORKERS=4`，MariaDB 13316，MinIO 19010，主题 zibll，插件软链到 `/root/work/sakura-tools`）。生产只读。三项验证结束后，临时 profile、临时桶、探针与临时目录均已删除，`s3_options` 已按备份还原。

**A. `sio_sync_operations` 守卫**

用注入测试站 `$wpdb` 的 `Remote_Operation_Guard` 执行，**不发送真实 S3 请求**；scope 为 `probe-20261108103524`，key 为 `probe/key.bin`。

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 1 | 正常准入 | `dispatch` 对 key 返回 `true` | `execute` 返回 `true`；`pending()` 为空；表中无残留 |
| 2 | 不确定保留 | 同一 scope/key，`dispatch` 返回 `WP_Error('simulated uncertainty')` | 返回 `sio_remote_uncertain`；DB 行 `state=uncertain`、`last_error=simulated uncertainty`、token 长度 64 |
| 3 | 冲突阻断 | 在 uncertain 未解除时对同一 scope/key 再 `execute` | 返回 `sio_remote_busy`，未调用 `dispatch` |
| 4 | 手工解除 | `acknowledge_settled(resource, 错误 token / 正确 token / 再次正确 token)` | `false / true / false`；解除后 `pending()` 为空 |

**B. 同步中心页面与 admin-ajax**

登录方式：测试站 `wp-login.php` 被 zibll 主题登录页接管，`curl` 提交表单无效；改用 `wp eval-file` 先 `wp_set_current_user(1)`，生成 `AUTH_COOKIE` 与 `LOGGED_IN_COOKIE` 后带 Cookie 请求。**nonce 绑定登录 session token，页面与 AJAX 必须复用同一份 Cookie**；换一份 Cookie 会得到 `-1`。

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 5 | 同步中心页面 | GET `/wp-admin/admin.php?page=sakura-tools-storage&t=sync` | HTTP 200；240,612 字节；标题 `对象存储 ‹ SIO E2E (manual)`；含 `<div class="sakura-sync-app" data-scan-id="0" data-scan-state="" data-index-enabled="1">`；加载 `assets/admin.js?ver=1.22.8`（**1.22.8 时记录**；1.25 起根节点改为 `UI::page()` 输出的 `<div class="sakura-admin sakura-layout-enabled" data-sakura-page="storage-sync">`，资源换成 `assets/ui/` 共享栈 + `assets/ui/js/pages/storage-sync.js`） |
| 6 | 前端配置 | 读取页面内联 `sakuraSyncCenter` | `ajaxUrl=http://127.0.0.1:18080/wp-admin/admin-ajax.php`、`searchUrl=.../sakura-tools/fast-search.php`；**没有 `restUrl`**（1.22.8 时记录；1.25 起统一为 `wp_localize_script` 注入的 `window.sakuraPage`，同步页数据落在 `sakuraPage.data`，`restUrl` 仍存在但该页不调用） |
| 7 | 列表接口 | 同一 Cookie POST `admin-ajax.php`：`action=sakura_s3_sync_page&nonce=...&status=all&limit=2` | JSON `success=true`；返回 2 行（`conc-c-300x225.webp`、`conc-s.webp`）；`cursor` 非空、`has_more=true`；counts `synced=24 / pending=2 / total=26` |

**C. `scan_cursor` 两组扫描**

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 8 | 空桶对照 | 临时 profile `probe_cursor` + 空桶，`start('probe_cursor','full')` 后 `tick($id,1,1)` | `scan_id=1`，1 tick 即 `done`，`total_remote=0` |
| 9 | 本地 JSON 写入 | 5000 个 1 字节文件，逐 tick 记录 `t_sio_sync_scans` 的 UPDATE | 本地每 500 行写一次：`processed=500/1000/…/5000`，最后 cursor `{"d":[],"a":"f04999.txt"}` |
| 10 | 阶段切换 | 同一次扫描进入 remote | `phase='remote'`、`total_local=5000`、`processed=5000` 与 `scan_cursor=''` 在**同一条 UPDATE** |
| 11 | 远端 token | 桶内补 1001 个对象后重跑（`scan_id=2`） | 第 1 页写 token `d3At…`（完整值见 §7.7.3），`total_remote=1000`；第 2 页 token 置空，`total_remote=1001`；最终 `state=done, phase=done, processed=6001` |
| 12 | 本地跨 tick 续跑 | 40000 个本地文件，1 秒预算，`tick($id,1,1)` 连续调用 | tick0 `processed=18500`、cursor `{"d":[],"a":"r18499.txt"}`；tick1 `processed=36500`、cursor `{"d":[],"a":"r36499.txt"}`；tick2 done、`total_local=40000`、cursor 空 |
| 13 | 清理 A 组 | 按 `probe_cursor` 删索引/扫描/计数/事件/任务；删临时桶对象与桶 | index 6001、scans 2、counts 2、events 4、jobs 0；删除 `probe/` 与 `wp-content/uploads/probe/` 各 1001 个对象；桶 HEAD 404 |
| 14 | 清理 B 组 | 按 `probe_resume2` 删索引/扫描/计数/事件/任务；删本地临时目录 | index 40392、scans 1、counts 2、events 2、jobs 0；`/tmp/cursor-resume-local` 已删除 |

补充说明：

- A 组的原始 SQL 轨迹共 16 条 UPDATE，按序为：初始化 local → 10 条本地批次 → 切 remote（清游标）→ 远端第 1 页（写 token）→ 第 2 页（清 token）→ reconcile → done。原始日志在 `/tmp/cursor-evidence/cursor-sql.log`，运行结果在 `/tmp/cursor-evidence/cursor-probe-result.json`（会话临时文件，不作为仓库产物）。
- B 组的 40392 条索引 = 40000 个本地文件 + 392 个桶内既有对象；本地续跑只看 `processed` 与 cursor 的变化，392 个远端对象不参与该结论。
- 两组验证后 `s3_options` 的 md5 都回到 `e223e71b2fe351912289ccffebb5435d`，active profile 回到 `prof_1d60d87b0606`（bucket `sio-e2e`），profiles 只剩该 profile。
- 清理后测试站最终状态：`sio_sync_index=26`（synced 24 / pending 2）、`sio_sync_scans=0`、`sio_sync_counts=3`、`sio_sync_events=1`、`sio_sync_jobs=0`、`sio_sync_operations=0`。
- 未覆盖：A 组的 local 阶段在单 tick 内跑完，未触发预算边界；「local 中途续跑」由 B 组补证。B 组没有构造「JSON 损坏/目录被删」的 cursor-missing 路径，该路径仅有静态阅读（`src/Sync/Scan_Service.php:376-383`）。

### 10.10 §12 三项的运行期验证记录（2026-11-08）

本轮三项都在**生产只读**环境核实：WP-CLI 只读查询、`curl` 只读请求、`ReflectionMethod` 纯函数调用；没有创建订单、上传、删除或修改 option。`fast-search.php` 的外部 Web 限制只能由生产 nginx 回答，因此没有用测试站替代；测试站保留同版本代码用于对照。

**A. `fast-search.php` 的 Web 可达性**

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 1 | 缺少参数 | `curl` 直接请求端点 | HTTP 403；`{"success":false,"data":{"message":"Forbidden."}}` |
| 2 | 错误签名 | `u=1`、未来 `e`、活动 `p`、`s=deadbeef` | HTTP 403；错误签名不会进入查询 |
| 3 | 有效管理员签名 | `Search_Token::create(1, 'prof_9463a17e7884', 300)` 生成参数；`limit=1`、`status=all` | HTTP 200；`Content-Type: application/json`；438 字节；`success=true`；`total=132`；`has_more=true`；返回 1 行（`status=synced`、`type=image`）；token 未输出、未落盘 |
| 4 | 外部 Web 限制 | 检查生产 nginx 与站点根目录 | `/www/server/panel/vhost/nginx/www.nboke.com.conf` 无 `fast-search` 专属 `location`；只有通用 `enable-php-84.conf` 与 wp-config/wp-login 等特例；`/www/wwwroot/www.nboke.com/.htaccess` 不存在 |

补充：令牌实现为 `src/Sync/Search_Token.php:32`（`TTL=600`）、`:53-55`（HMAC `v1|u|e|p`）、`:65-73`（`create()`，TTL 夹取 60–3600）；端点校验见 `fast-search.php:53-68`，管理员能力检查见 `:72-82`。本轮用 300 秒令牌，过期后不复用。

**B. S3 上传指标：只有成功样本**

命令（生产只读）：

```
wp eval '$m=(new \Sakura\ImageOptimizer\S3\Upload_Metrics())->snapshot(); echo wp_json_encode(array("totals"=>$m["totals"],"week"=>$m["week"],"last_error"=>$m["last_error"]));' --allow-root --path=/www/wwwroot/www.nboke.com
```

| # | 验证点 | 结果 |
|---:|---|---|
| 5 | 累计指标 | `attempts=91`、`ok=91`、`fail=0`、`dead=0`、`rate=0`、`bytes=50940161` |
| 6 | 14 天窗口 | `attempts=91`、`ok=91`、`fail=0`、`dead=0`、`rate=0`（与累计相同，说明样本都在窗口内） |
| 7 | 最近错误 | `last_error.has=false`；没有失败样本可展示 |

静态对照：修复前的 `src/S3/Upload_Observer.php:29-31` 只挂 `sakura_imgopt_s3_uploaded`；`:41-53` 只构造 `array('ok' => true, ...)` 后调 `record_batch()`。`src/S3/Object_Store.php:182`、`:322`、`:345` 的 `do_action('sakura_imgopt_s3_uploaded')` 都在 PUT/HEAD 校验成功之后；失败路径（`:160-171`、`:311-315`、`:332-343`、`:348-354`）直接返回 `WP_Error`/拒绝，不触发该钩子。`Upload_Metrics::record_batch()` 本身支持 `ok=false` 与 `dead`（清理前 `src/S3/Upload_Metrics.php:84-125`），但生产没有第二个调用方。`Alerting::evaluate()` 用 `week.attempts` 与 `week.rate` 判 `failure_rate`（`src/S3/Alerting.php:152-169`），所以失败率恒 0。

**以上是修复前的生产证据。** 2026-11-08 本轮已按 §6.9 改为事件表并补上失败钩子，测试站复测见 §10.11；本节保留原始只读证据用于追溯，不再代表当前实现。

**C. `Space_Store::grant_from_order()` 的字段写入**

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 8 | 解析结果 | 生产只读反射调用私有 `normalise_order()`，输入见 §8.9 | `order_id=123`、`order_num=probe-order`、`price=30`、`channel=balance`、`bytes=1073741824` |
| 9 | 生产发放记录 | `wp eval` 聚合 `sufm_space_grants` | `order:` 1 行：`source=purchase`、`channel=balance`，`order_id`/`order_num`/`price` 均非 0；`points:` 3 行：1 行有订单字段，2 行订单字段为 0；3 行 `price=0` |
| 10 | 写入差异 | 对照**修复前**的 `src/UserFiles/Services/Space_Store.php:830-843` | INSERT 的 `source`/`channel` 为字面量 `'points'`，`order_id=0`、`order_num=''`、`price=0`、`points=0`；解析值只进入 `meta`（`:811-818`）；修复后的写入见 §8.9.3 |
| 11 | 积分日限额影响 | 读 `:2014-2022`、`:1933`、`:2154`、`:2350` | `points_used_bytes()` 按 `source='points'` 汇总当日字节；现金订单被写成 `points` 后会占用 `used_gb`，可能触发「今日兑换已达上限」 |

**边界**：第 8 条是纯函数反射，不写库；第 9 条是只读聚合；**生产没有创建真实订单**。静态代码已足以证明解析值未进入 INSERT；第 11 条是代码路径推导，未在生产制造现金订单。修复后已补现金/积分两类发放测试（`SpaceStorePaymentTest`）；现金单写 `purchase`，不再满足 `points_used_bytes()` 的 `source='points'` 条件（§8.9.3）。

**D. 结论汇总**

| 原 §12 # | 项 | 结论 | 证据强度 |
|---:|---|---|---|
| 1 | `fast-search.php` 外部限制 | **无缺陷**：无额外 nginx/.htaccess 限制；无 token/错签名 403，有效管理员签名 200 | 运行期 403/200 + 配置静态核对 |
| 2 | `grant_from_order()` 字段 | **原缺陷，本轮已修复**：原 `source/channel` 写死 `points`、订单字段清零；现按渠道参数化写入（§8.9.3） | 反射 + 生产聚合 + 静态 SQL + 测试站发放记录 |
| 3 | 上传失败不可见 | **原缺陷，本轮已修复**：修复前只记录 `ok=true`，失败路径不触发；生产 91 次成功、0 次失败样本，失败率恒 0。现改为事件表 + `sakura_imgopt_s3_upload_failed`（§6.9） | 生产快照 + 钩子调用图 + §10.11 并发复测 |

### 10.11 上传指标并发修复的运行期验证（2026-11-08）

环境同 §10.7：测试站 `/root/sio-e2e`（PHP 8.4.27，MariaDB 13316，MinIO 19010），插件软链到 `/root/work/sakura-tools`，表前缀 `t_`。以下操作**全部在测试站**执行，生产只读；验证结束后已清空事件行与守卫行，测试站没有留下探针数据。

**A. 迁移与建表**

| # | 验证点 | 操作 | 结果 |
|---:|---|---|---|
| 1 | 升级前状态 | 查表与 schema option | `t_sio_s3_upload_events` 不存在；`sakura_imgopt_s3_upload_schema` 为空 |
| 2 | 惰性建表 | 调用 `(new Upload_Metrics())->snapshot()` | 表自动创建；`sakura_imgopt_s3_upload_schema=2.0.0` |
| 3 | 表结构 | `SHOW CREATE TABLE t_sio_s3_upload_events` | 12 个字段与 §6.9.2 一致；`day_result`、`created_at`、`attachment_id`、`profile_day` 四个索引；InnoDB、`utf8mb4_unicode_520_ci` |
| 4 | 旧基线保留 | 建表后立即读 `snapshot()` | `totals.ok=30`、`fail=0`、`attempts=30`、`bytes=1279424`——升级前的 option 没有被迁移、清零或重复写入 |

**B. 失败采集**

用 `Storage_Adapter::upload_file()` 与 `upload_many()` 直接制造失败（不经过真实网络），共写入 4 行：

| # | 场景 | 写入的 `attachment_id` | `object_key` | `error_code` |
|---:|---|---:|---|---|
| 5 | 空 key | 4242 | 空串 | `sakura_s3_invalid_file` |
| 6 | `upload_file()` 文件不存在 | 4343 | `probe/missing-two.txt` | `sakura_s3_invalid_file` |
| 7 | `upload_many()` 非法 key | 4444 | 空串 | `sakura_s3_invalid_file` |
| 8 | 合法 key + 缺失文件（先经守卫） | 4444 | `probe/missing-two.txt` | `sio_remote_uncertain` |

四行都带 `profile_id=prof_1d60d87b0606`。第 8 条同时说明：`upload_many()` 的合法 key 会先进入 `Remote_Operation_Guard`，守卫返回 `sio_remote_uncertain` 并留下 `sio_sync_operations` 的 uncertain 行（该行已人工删除）；这是守卫的既有语义（§7.7），指标只是把同一失败记录到事件表，两者不互相替代。

**C. 并发写入**

先 `DELETE FROM t_sio_s3_upload_events`，再用 4 个独立的 WP-CLI 进程各调用 200 次 `Upload_Metrics::record_batch( array( 'ok' => false, 'attachment' => ..., 'key' => ... ) )`，然后直接数表：

```
$ ./e2e.sh wp db query "SELECT COUNT(*) AS rows, COUNT(DISTINCT attachment_id) AS attachments FROM t_sio_s3_upload_events;"
rows  attachments
800   4
```

| # | 验证点 | 结果 |
|---:|---|---|
| 9 | 事件行数 | **800 / 800**，零丢失；4 个进程各自写入的附件 ID 都出现 |
| 10 | 旧实现对照 | 同一台机器、同一并发模型改为读改写 option 时只落 **202 / 800**（丢 598 条，74.75%） |

**D. 聚合与消费方**

800 条失败加上旧 option 的 30 条成功基线后，快照为：

| # | 验证点 | 结果 |
|---:|---|---|
| 11 | `snapshot().totals` | `ok=30`、`fail=800`、`dead=0`、`attempts=830`、`rate=96.3855`、`rate_text=96.4%`、`bytes=1279424` |
| 12 | today / week | 均为 `attempts=830`、`fail=800`；说明当天日桶与 14 天窗口都包含事件行 |
| 13 | `last_error` | `has=true`、`attachment=900004`、`key=probe/conc/4-199`、`message=probe concurrent 4/199` |
| 14 | `wp sakura-sync stats --format=table` | `Today: 30 ok, 800 failed, rate 96.4%, avg 0 ms, 1.22 MB`；`Last 14 days: 830 attempts, 800 failed, rate 96.4%` |
| 15 | Prometheus | `Prometheus::format()` 输出 1762 字节；`attempts{result="ok"}=30`、`fail=800`、`dead=0`；`failure_ratio=96.385542` |
| 16 | 后台卡片 | `Sync_Center_Panel::render()` 输出 1698 字节，含 `sakura-s3-metric-error` 节点并显示 `last_error` 文本；没有因返回结构变化报错 |

**E. 清理与最终状态**

| # | 验证点 | 结果 |
|---:|---|---|
| 17 | 清事件行 | `t_sio_s3_upload_events` 行数回到 0 |
| 18 | 清守卫行 | `t_sio_sync_operations` 的探针 uncertain 行删除，行数 0 |
| 19 | 快照回归 | `totals` 回到 `ok=30`、`fail=0`、`attempts=30`；`last_error.has=false` |
| 20 | 表与 schema 保留 | 空表仍在，`sakura_imgopt_s3_upload_schema=2.0.0`；测试站下次上传可直接写入 |

**边界**：本节验证的是数据层并发、失败采集与三个消费方的兼容性，没有用真实上传把 800 条失败灌进队列；真实上传链路的端到端验证仍是 §10.6 的 5 张成功样本。生产环境没有执行建表、写事件或 `reset()`，生产升级后的建表行为由 A 组与单元测试覆盖。

### 10.12 退款重试原子占位的运行期验证（2026-11-08）

环境同 §10.7：测试站 `/root/sio-e2e`（PHP 8.4.27，MariaDB 13316，表前缀 `t_`），插件软链到 `/root/work/sakura-tools`。验证只写测试站，结束后清理临时用户、订单、授权与事件行；生产只读。

**A. 探针构造**

临时用户余额 100；插入两条 `t_zibpay_order`（`order_type=21`、`status=1`、`pay_type=balance`、`order_price=30`）与两条 `t_sufm_space_grants`（`state=refund_pending`、`price=30`、`attempts=0`）。一条 `updated_at` 为当前时间（fresh），一条为 180 秒前（stale）。随后调用 `retry_refunds(20)`。探针脚本用完整类名 `\Nboke\UserFiles\Services\Space_Rules::now()`；首次运行因脚本内漏写命名空间报 `Class "Space_Rules" not found`，属于探针脚本错误，修正后重跑，不影响插件代码。

**B. 结果**

| # | 验证点 | 结果 |
|---:|---|---|
| 1 | fresh 不被抢占 | `fresh_retry=0`；`state=refund_pending`；余额仍 100 |
| 2 | stale 被补偿 | `stale_retry=1`；`state=refunded`；`attempts=1`；余额 100→130 |
| 3 | 二次重试不重复加钱 | `second_retry=0`；余额仍 130 |
| 4 | 钱包记录命中 | 该订单的 `balance_record` 中 `type=space_expand_refund` 记录 1 条 |
| 5 | 清理 | 授权 0 行、订单 0 行、临时用户已删；`t_sufm_events` 最大 id 仍为 72 |

**C. 机制**

`retry_refunds()`（`src/UserFiles/Services/Space_Store.php:1652`）先按 `state='refund_pending' AND attempts<5 AND updated_at<=now-60` 选出订单号，再对每个订单号执行条件更新（`:1690-1703`）：

```sql
UPDATE {grants} SET updated_at = %s
WHERE order_num = %s AND state = 'refund_pending' AND updated_at <= %s
```

两个 cron worker 同时选中同一订单时，只有一个条件更新能命中；未命中的 worker 直接 `continue`，不会进入 `settle_refund()` 给钱包加钱。占位成功后 `updated_at` 被推到当前时间，下一次重试要再等 60 秒；这也是崩溃后恢复的节流窗口。

**D. 边界**

- 本节验证的是单进程下的 fresh/stale 分支与二次调用；**没有真正启动两个并发 WP-CLI 进程同时跑 `retry_refunds()`**。因此“双 worker 只加一次钱”是条件更新语义加单进程占位证据的结论，不是并发压测结论。
- 占位依赖 `updated_at` 条件更新，没有数据库唯一约束；系统时钟回拨、手工改时间或同一秒内多次执行都可能影响节流。
- 钱包查重仍受主题 `balance_record`/`points_record` 最多 50 条的限制（§12.3），强一致退款需要独立账本表。

### 10.13 编辑页文件面板交互的运行期验证（2026-11-09）

**A. 环境与准入**

测试站 `/root/sio-e2e`（PHP 8.4.27，MariaDB 13316，站点 `http://127.0.0.1:18080`），后台 `admin`；插件当时是 1.22.9、提交 `2fec6a3`，`sufm_settings` 的 `enabled` 与 `editor_enabled` 临时置为 true。测试站原 `siteurl` 是 `https://demo.77il.cn`，本地登录会被 302 到外域，因此测试期间临时把 `siteurl`/`home` 改成 `http://127.0.0.1:18080`，结束后已恢复。`router.php` 另加了一条软链目录放行规则，否则插件软链到 `/root/work/sakura-tools` 后静态资源会被 WordPress 404；这是测试基础设施，不属于插件改动。

编辑页 `/newposts` 加载后，`window.SUFMPicker` 存在，manager 的 `started` 为 1；面板列出 8 个文件，资源版本为 `?ver=1.22.9.1794188421.93173`。

**B. 右键菜单与光标插入**

| # | 验证点 | 结果 |
|---:|---|---|
| 1 | 文件菜单项 | 6 项，顺序为 插入 / 打开 / 重命名 / 移动到 / 复制链接 / 下载；首项带 `data-sufm-act="insert"` |
| 2 | 插入位置 | 光标在正文 `BBB` 之后时，右键插入附件 229，内容出现在 `BBB` 之后 |
| 3 | 插入内容 | 生成 `alt="conc-d"` 与 `data-edit-file-id="229"`；`data-sufm-file="424"` 是用户文件行 id，229 是 REST 返回的附件 id，既有插入逻辑一直如此 |
| 4 | 菜单动作 | `runMenuAction( 'insert' )` 调用 `window.SUFMPicker.insert( [ id ] )`，没有走文件打开或移动分支 |

**C. 拖拽反馈与落点**

无头 Chrome 的鼠标事件无法触发真实 HTML5 `dragstart`，所以这一组用合成 `DragEvent` 驱动 manager 的拖拽路径，再用 CDP `Input.dispatchDragEvent` 补一次原生投放；真实鼠标拖拽由用户在测试站手动确认（m05324）。

| # | 验证点 | 结果 |
|---:|---|---|
| 5 | 跟手标签 | `.sufm-drag-badge` 存在，文案为「拖到正文插入」；源行有 `.is-dragging`，面板容器同时有 `is-drag-moving` 与 `is-drag-file` |
| 6 | 标签定位 | 派发一次 document `drag` 后，badge 的 `left/top` 为 662/448，与 `clientX+16` / `clientY+18` 一致 |
| 7 | 投放层尺寸 | `.sufm-editor-drop` 的矩形为 x=407、y=360、w=676、h=504，与编辑器 iframe 完全一致 |
| 8 | 投放态 | 投放层 `dragover` 后 class 为 `sufm-editor-drop is-visible is-over` |
| 9 | 单文件投放 | `drop` 后投放层回到 `hidden`，正文插入附件 229（`conc-d`），控制台无异常 |
| 10 | 多选投放 | 选中 424+423 时 badge 文案为「拖到正文插入 · 2 个」，投放后插入 228（`conc-c`）与 229（`conc-d`） |
| 11 | 原生投放 | CDP `Input.dispatchDragEvent` 的 drop 同样插入 229，说明不是只有合成事件能工作 |
| 12 | 文件移动兼容 | 文件拖拽的 `effectAllowed` 为 `copyMove`，投放层 `dropEffect` 为 `copy`；原先只写 `move` 时两者不兼容，真实浏览器可能不触发 `drop`，本轮已修正 |

**D. 用户中心隔离**

| # | 验证点 | 结果 |
|---:|---|---|
| 13 | 菜单 | `/user/files` 的文件右键菜单没有「插入」 |
| 14 | 投放层 | 页面上没有 `.sufm-editor-drop`，拖拽不会出现正文投放层 |
| 15 | UI | 用户中心仍走 `sufm-front`，面板布局、菜单项与改动前一致 |

**E. 自动化检查与清理**

| # | 验证点 | 结果 |
|---:|---|---|
| 16 | PHPUnit | 406 tests / 1185 assertions / 5 skipped，全绿 |
| 17 | PHPStan | `[OK] No errors` |
| 18 | PHPCS | 全量 177 files，0 errors、0 warnings |
| 19 | 语法 | 两个 JS 文件 `node --check` 通过；`Assets.php`、`editor-picker-app.php`、`Tab.php` 的 `php -l` 通过 |
| 20 | 空白检查 | `git diff --check` 无输出 |
| 21 | 测试站还原 | `siteurl`/`home` 恢复 `https://demo.77il.cn`；临时 `sufm_settings` 也按原值处理 |

首轮复测时，浏览器控制台曾抓到旧版本 URL 下的 `insertBefore` 堆栈；核对响应源码与网络版本后，用新的 `?ver` 重新加载再测，批量条归位与插入均通过。这说明当时是页面保留了上一版脚本的异常事件，不是当前文件仍在执行旧代码。

**F. 边界**

- 只覆盖 TinyMCE iframe。块编辑器 `/wp-admin/post-new.php` 不加载面板（不是「只 toast」）；纯 textarea 会加载面板，但插入只 toast「找不到编辑器，请点一下正文再试」，也不会出现投放层。详见 §8.10.7。
- 投放层只覆盖 iframe。拖到页面其他区域不会插入，也不会移动文件；移动文件仍要拖到文件夹行或面包屑的 `.is-drop-target` 上。
- 多选插入不是屏幕视觉顺序。选择模型保留勾选顺序，但 `insert()` 先把图片按勾选顺序放前面，再把其余按勾选顺序放后面；因此「非图片先勾、图片后勾」时，最终正文顺序会与勾选顺序不同。这是既有 `insert()` 的排序规则，本轮没有改。
- 本节没有执行真实上传、删除、REST 写或数据库写；所有验证只发生在测试站的前端 DOM 与编辑器内容里。
- 批量条 `insertBefore` 异常是编辑页 fork 的既有 bug，不是 1.23.0 引入；它只在取消选择或插入后清空勾选时触发，修复见 §8.10.5。

---

### 10.14 对账退避与历史授权回填的运行期验证（2026-11-09）

**A. 对账状态迁移与退避**

测试站 `/root/sio-e2e`（PHP 8.4.27，MariaDB 13316，站点 `http://127.0.0.1:18080`），插件软链到 `/root/work/sakura-tools`。测试前 `sufm_space_reconcile_last` 是旧版标量 `"1794152631"`；测试后已恢复原值。

| # | 步骤 | 结果 |
|---:|---|---|
| 1 | 读取旧标量 | `wp sufm status` 输出 `recon_last=0`、`recon_next=1794239031`，等于旧值 + 86400；没有立刻重放对账 |
| 2 | 把 `next_attempt` 置 0 后跑一次 `tick()` | 成功：`last_attempt=1794206878`、`last_success=1794206878`、`next_attempt=1794293278`（+86400）、`failures=0`、`last_alert=0` |
| 3 | 在同一 PHP 进程内把 `t_sufm_space_grants` 临时改名，再跑 `tick()` | 失败：`last_success` 保持 1794206878、`failures=1`、`next_attempt=1794207878`（+900）、`last_alert=1794206978` |
| 4 | 立即再跑一次 `tick()` | 租约生效：状态完全不变（`last_attempt=1794206978`、`next_attempt=1794207878`、`failures=1`） |
| 5 | 把 `next_attempt` 再置 0 后制造第二次失败 | `failures=2`、`next_attempt=1794208800`（+1800）；`last_alert` 不变 |
| 6 | 检查事件 | 只写一条 `space.reconcile.alert`（事件 id 116，`result=error`，`safe_detail={"missing":0,"healed":0,"more":false,"error":"","failures":1,"next_attempt":1794207878,"auto":true}`）；第二次失败没有第二条告警 |

失败模拟必须在**同一个 PHP 进程**里先改名再调 `tick()`：跨进程改名会被插件的启动建表逻辑自动补回，模拟不出失败。表格第 3 步的 `error` 为空不是缺陷——失败来自表被改名后的查询异常，`reconcile()` 的 `ready=false` 分支负责给出 `error`；这里记录的是告警分支实际写入的字段。第 6 步的事件 id 是测试站自增序列的观测值，不作为稳定契约。

**B. 历史 `order:` 授权审计与回填**

测试站原有 1 行 `points:` 授权（`grant_key=points:1db6…`，`order_id=20`、`order_num=2611090558493023960`、`source=points/channel=points/points=900`），它不在审计范围内。另插入 1 行合成的 `order:` 授权和 1 行对应订单：

| 对象 | 值 |
|---|---|
| 订单 | `id=21`、`order_num=9900000000000000001`、`order_type=21`、`status=1`、`user_id=1`、`order_price=30`、`pay_type=balance`、`product_id=sufm_space_1` |
| 授权 | `id=18`、`grant_key=order:9900000000000000001`、`owner_id=1`、`bytes=1073741824`、`state=active`、`label=legacy probe`、`order_id=0`、`order_num=''`、`price=0`、`points=0` |

| # | 命令 | 结果 |
|---:|---|---|
| 1 | `wp sufm grants --limit=10` | `#18 … status=pending`；汇总 `candidates=1 pending=1 filled=0 skipped=0 (dry run)`；数据库未变 |
| 2 | `wp sufm grants --apply --yes` | `#18 … status=filled`；库中 `source=purchase`、`channel=balance`、`order_id=21`、`order_num=9900000000000000001`、`price=30.00`、`points=0`；`bytes=1073741824`、`state=active`、`label=legacy probe` 均未变 |
| 3 | 再跑一次 `wp sufm grants --limit=10` | `candidates=0`，没有重复写入 |
| 4 | 检查事件 | 新增 `space.grant.backfilled`（事件 id 117），带 `grant_id=18`、`order_id=21`、`source=purchase`、`channel=balance` |

**C. 自动化检查与清理**

| # | 检查 | 结果 |
|---:|---|---|
| 1 | PHPUnit | 423 tests / 1257 assertions / 5 skipped，全绿 |
| 2 | PHPStan | `[OK] No errors` |
| 3 | PHPCS | 177 files，0 errors、0 warnings |
| 4 | 语法 | `php -l` 两个改动文件通过；`git diff --check` 无输出 |
| 5 | 新测试 | `tests/Services/SpaceStoreReconcileTest.php` 10 tests / 59 assertions，覆盖退避序列、旧标量迁移、租约、`more`、回填字段白名单与跳过原因 |

清理结果：删除合成授权 18、订单 21 与事件 116/117；`t_sufm_space_grants` 恢复 1 行、`t_zibpay_order` 恢复 1 行、事件表最大 id 回到 115；`sufm_space_reconcile_last` 恢复为 `"1794152631"`，`wp sufm status` 读回 `recon_next=1794239031`；`wp sufm grants` 最终 `candidates=0`；`flush(1)` 清配额缓存。

**D. 边界**

- 本节只写测试站；生产没有执行 `--apply`，也没有改动任何历史 grant。
- 回填只处理 `grant_key LIKE 'order:%'` 且 `order_id=0` 或 `order_num=''` 的行；`points:` 直兑回退行没有主题订单，保持原样。
- 对账退避只改变「下一次自动对账什么时候跑」，不重放退款、不修复坏订单；告警仍是日志，不是自动修复。
- `refund_failed` 没有在本节测试自动复活；1.24.0 明确保留人工处理。

### 10.15 文件面板快捷多选（1.24.1）

验证环境：测试站 `/root/sio-e2e`（站点 `https://demo.77il.cn`，后台 `admin`），插件以软链挂载 `/root/work/sakura-tools`，工作树在提交 `427101c`；浏览器用 Chrome CDP（127.0.0.1:9222，profile `/tmp/chrome-sio`）。生产环境只做只读核对，没有写任何文件或选项。

**A. 用户中心（17/17）**

| 场景 | 结果 |
|---|---|
| 普通点复选框 | 选中/取消正常，anchor 设为该文件 |
| Shift 向下/向上连选 | 区间按 DOM 顺序连续选中；只加不减；anchor 不动 |
| Shift 跨过锁定文件 | 锁定项保持未选，区间其余项正常选中 |
| Ctrl/⌘ 点行或卡片 | 单项反转，anchor 移到该项 |
| Ctrl/⌘+A | 选中当前 DOM 内全部非锁定文件；再按一次不反选 |
| Esc | 先关闭已打开的菜单；没有菜单时清空选择 |
| 网格「全选/取消全选」 | 文案随状态切换，选中的卡片显示勾选态 |
| 表头复选框 | 与选择状态同步；全选/取消全选生效 |
| 普通点空白卡 | 不选中，原有打开/预览语义不变 |
| 刷新/切换文件夹/搜索 | 选择按新 DOM 重建，不残留旧 ID |

**B. 编辑页（24/24）**

| 场景 | 结果 |
|---|---|
| Shift 点复选框/行/卡片 | 连选；行与卡片走 manager 的行点击分支，复选框走捕获分支 |
| Shift/Ctrl 点文件名 | 事件从 `editor-picker.js` 的文件名捕获监听让回 manager，连选/单项反转生效；普通单击仍只 pick |
| 复选框 pre-click 恢复 | 捕获分支 `preventDefault()` 后，`setTimeout(updateBatch,0)` 把勾选态拉回选择模型；没有出现「刚选中又弹回」 |
| Ctrl/⌘+A | 选中当前可见文件；输入框/文本域/contenteditable 内不拦截 |
| Esc 第一次 | 清空选择并留在面板；菜单打开时只关菜单 |
| Esc 第二次 | 走原 `userClose()` 关闭面板 |
| 网格全选按钮 | 与列表选择一致；批量条数字同步 |
| 选择同步到插入条 | manager 的 `sufm:selection-changed` 触发 editor-picker 的 `updatePicked()`，插入条数量/顺序与勾选一致 |
| 批量条归位 | 取消选择后批量条回到 `.sufm-head`，没有 `NotFoundError` |
| 菜单打开时选择 | 先 `closeAllMenus()`，不会把菜单点击误判为连选 |

**C. 响应式与样式**

| 检查 | 结果 |
|---|---|
| 用户中心 1440/1024/782/390 | 路径栏 `scrollWidth <= clientWidth`，无横向溢出；快捷提示在 ≤1199 隐藏，全选按钮仍可用 |
| 编辑页面板准入 | 只在 ≥1360px 挂载（`src/UserFiles/Editor/Assets.php:64`、`assets/editor-picker.js:37`）；1024/782 不显示面板，不涉及本功能 |
| 列表复选框 | 用户中心与编辑页均 14×14、border-radius 3px |
| 网格复选框 | 用户中心与编辑页均 19×19、border-radius 50% |
| 样式护栏 | 人为给列表行加 `.sufm-grid-check` 后仍为 14×14/3px；网格仍 19×19/50% |
| 控制台 | 选择、拖拽、菜单、保存路径无 JS 异常 |

**D. 静态与单测**：PHPUnit 423 tests / 1257 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS 177 files 0 errors/0 warnings；`node --check` 三个 JS 通过；`git diff --check` 无输出。PHPCS 只扫 `sakura-tools.php`、`uninstall.php`、`src/`、`includes/`，不含 `assets/` 与 `tests/`，所以 JS 用 `node --check` 单独把关。

**E. 清理**：为复现圆形复选框，曾用 `wp media import` 导入 5 张 64×64 PNG（附件 230–234），验证后已用 `wp post delete 230 231 232 233 234 --force --user=admin` 删除；测试文件夹原有文件未动；没有修改任何 option，没有触碰生产数据。

### 10.16 S3/Sync 运维观测（1.24.2，2026-11-09）

验证环境：测试站 `/root/sio-e2e`（站点 `https://demo.77il.cn`，插件软链 `/root/work/sakura-tools`，版本 1.24.2）。只使用只读查询和临时探针；生产未写任何数据。

**A. `wp sakura-s3 health`**：`--format=json` 返回 16 项指标。测试站当前值：`upload_today_*` 全 0；`upload_14d_attempts=30`、`upload_14d_fail_rate=0.0%`；`upload_total_attempts=30`（旧 option 冻结基线）；`delete_pending/dead/stale=0`；`remote_inflight/uncertain=0`；`sync_jobs_dead=0`、`sync_actions_failed=0`；`upload_metrics_updated_at=2026-11-08 09:22:59`、`sync_metrics_updated_at=2026-11-08 10:52:15`。命令 exit 0，无 PHP 警告。

**B. 守卫分页、计数与释放**：向 `t_sio_sync_operations` 临时插入 2 行 `state=uncertain`（`probe-a.jpg`/`probe-b.jpg`）。`remote-operations count` 输出 inflight=0、uncertain=2、total=2；`remote-operations list --limit=1 --offset=1` 只返回第二行，并打印 `Showing 1 of 2 held operations (offset 1)`；`release` 带正确 resource/token、`--remote-settled --yes` 后返回成功，再次 `count` 为 1；剩余 1 行用 SQL 删除，最终 `operations=0`。

**C. Prometheus**：用 `wp eval` 调 `Prometheus::format()` 并传入 `delete_dead=2/delete_stale=1/remote_uncertain=3/sync_jobs_dead=4`，文本分别出现 `sakura_imgopt_delete_dead 2`、`sakura_imgopt_delete_stale 1`、`sakura_imgopt_remote_uncertain 3`、`sakura_imgopt_sync_jobs_dead 4`；不传 health 时为 0。测试站真实端点 `/?sakura_imgopt_metrics=<token>` 返回 200，四个 gauge 均为 0，`sakura_imgopt_info{version="1.24.2"}` 正常。

**D. 静态与单测**：PHPUnit 426 tests / 1,279 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS 全量 177 files 0 errors/0 warnings；`git diff --check` 无输出。新增/调整的测试：`tests/RemoteOperationGuardTest.php`（分页、状态计数、`count_held` 只读连接）、`tests/PrometheusTest.php`（四个 gauge 与缺省 0）、`tests/UploadMetricsTest.php`（旧 option 不被改写、回滚加法 38=30+5+3）。

**E. 清理与边界**：守卫探针行已删除，上传事件表保持 0 行；没有修改任何 option；测试站 Web 进程仅用于本次 HTTP 验证。验证时生产 `/www/wwwroot/www.nboke.com/.../sakura-tools` 仍停在 1.24.1 部署提交，未执行本轮代码；1.24.2 发布会把本节代码与版本号一并推送并部署，运行行为与本节记录的代码基线 `dfb3cf4` 一致。

### 10.17 三条验证空白的运行期补测（1.24.2，2026-11-09）

验证环境：测试站 `/root/sio-e2e`（站点 `https://demo.77il.cn`，插件软链 `/root/work/sakura-tools`，版本 1.24.2，管理员 ID 1，表前缀 `t_`）。生产站只读，未执行任何写操作。临时用户、附件、文件行、删除请求/批次/任务、扫描行、临时目录与 mu-plugin 均已在结束时清理；测试站文件面板原有文件保留。卸载轨道 A 在测试站执行后已恢复数据（15 张 `t_sufm_*`、`t_sufm_files` 222 行、`t_sufm_file_tokens` 3,107 行），主页、`/newposts`、`/user/files` 均返回 200。

**A. `Quota::reserve` / `commit` / `release`**

测试用户 ID 34，初始 `Quota::snapshot(1)` 为 used 0 / reserved 0 / revision 0 / quota 536,870,912。

| 步骤 | 调用 | 结果 |
|---:|---|---|
| 1 预留 1 MiB | `reserve()` | 返回 `id=67`、`fence=1`、`bytes=1,048,576`；`reserved=1,048,576`、`available=535,822,336` |
| 2 部分提交 | `commit(67, 800000)` | `true`；`used=800,000`、`reserved=0`、`revision=2`、`available=536,070,912`；reservation 为 `committed`、`bytes=800000` |
| 3 重复提交 | 同 ID 再 `commit()` | `false`；ledger 仍是 `used=800000`、`revision=2`，没有二次扣减 |
| 4 超配额提交 | 再预留 1 MiB，`commit(id, quota+1)` | `false`；`used=800000`、`reserved=1,048,576`、`revision=3`；reservation 仍为 `reserved`，整笔事务回滚 |
| 5 释放 | `release()` | `reserved=0`、state `released`、`revision=4` |
| 6 清理 | 删除 reservations / 重置 usage / 删用户 | 全部为 0 |

结论：成功提交、部分计费、重复提交拒绝、超配额回滚与释放均与 `src/UserFiles/Services/Quota.php:168`、`:336`、`:418` 的静态行为一致。

**B. `Requests::approve` → `Executor::preview/confirm/tick`**

首次探针在 `wp_insert_attachment()` 后手工 `Files::create()` 触发 UNIQUE：`Media_Hooks::assign_uploaded()` 已自动 adopt 同一附件（`src/UserFiles/Media/Media_Hooks.php:933`）。给探针加 `sufm_skip_attachment_adoption` 过滤器后通过；这是探针与既有钩子的配合问题，不是产品缺陷。

测试数据：临时用户 36、1×1 PNG 附件 236、`t_sufm_files` 行 433、`source=media`、`charged_bytes=123456`；手工 `Quota::adjust_used(36,123456)` 后 usage 为 `used=123456/revision=1`。

| 步骤 | 结果 |
|---|---|
| `Requests::create(owner=36, requester=36, file 433, notice_version=2026-10-31, confirmed=true)` | request 1、`pending_review`；item 1；file 的 `pending_request_id=1` |
| `Requests::approve(request, 1)` | `true`；request `approved`、`reviewer_id=1`、`approval_revision=1`；item 仍是 `pending_review`（审批只冻结 manifest，`confirm` 才 queued） |
| `Executor::preview([1])` | hash `d6b5915c46057bcb005f46f6433df65609dd3a7a57af75ac1a5a4a7c299ebffb`；items 1、bytes 123456 |
| `Executor::confirm([1],1,'删除',hash)` | batch 1、job 1；request `queued` |
| `Executor::tick(10)` | processed 1；附件不存在；request `completed`；item `done/result=purged`；file `purged/deleted`；usage `used=0/reserved=0/revision=2`；batch `completed`；job `succeeded`；events 2 |
| 清理 | request / file / attachment / 用户 36 全删；测试站 `t_sufm_files` 回到基线 |

结论：审核 → 冻结 → 预览 hash → 确认 → 删除执行 → 配额回冲 → 请求/批次/任务收尾的整条链路在测试站跑通，与 `Requests.php:738`、`Executor.php:154`、`:441`、`:782` 的静态阅读一致。

**C. `Uninstaller` 两轨**

修复前 `src/Uninstaller.php:146-153` 的 `drop_sufm_tables()` 硬编码 14 张表：folders、files、usage、reservations、artifacts、delete_requests、delete_request_items、delete_batches、jobs、events、notifications、rate_limits、media_operations、space_grants；`src/UserFiles/Schema.php:41-60` 的 `tables()` 是 15 张，多出 `file_tokens`（定义在 `Schema.php:256-261`，字段只有 `file_id` 与文件名 bigram `token`）。

| 轨道 | 预置 | 执行后 |
|---|---|---|
| A 显式删除数据（修复前） | `sakura_imgopt_delete_data_on_uninstall=1`；15 张 sufm 表，`t_sufm_file_tokens` 3,117 行 | `t_sio_*` 0；其余 14 张 sufm 表删除；**只剩 `t_sufm_file_tokens`，仍 3,117 行**；s3_options / version / sufm_settings / sufm_space_settings / sufm_schema_version 等删除；upload_url_path/upload_path 恢复；postmeta/usermeta/备份目录清空 |
| B 默认保留数据 | 同一份 15 张表；`delete_data` 为 0 | 15 张 sufm 表保留；`t_sio_*` 0；持久 option、postmeta、usermeta、备份目录保留；cache/transient 清除；能力撤销；cron 清空 |

轨道 A 的结果与静态清单完全对应：修复前**卸载的显式删除路径漏删 `file_tokens`**，3,117 条文件名搜索 token 留在库里。根因是删表清单与 `Schema::tables()` 各自维护、已经漂移。该缺陷登记在 §12.7，随后由 1.24.3 修复：`drop_sufm_tables()` 改为直接遍历 `Schema::tables()`（`src/Uninstaller.php:150-154`），并补 `tests/UninstallerTest.php:55-95` 的 fake-wpdb 回归测试。隔离站复跑结果：15 张 `t_sufm_*`（`t_sufm_file_tokens` 3,117 行）→ 0 张，`t_sio_*` 也 0；随后从 dump 恢复样本库。两轨最初都在测试站执行，生产未执行卸载。

**D. `Scan_Service` 的损坏游标**

临时扫描根 `/tmp/scan-probe-local` 有 4 个文件 26 字节：`a.txt`（3 B）、`b.jpg`（5 B）、`sub/c.png`（7 B）、`sub/d.txt`（11 B）。用 Reflection 直接调用私有 `local_phase()`，避免进入远端阶段；每个用例先插一条 `t_sio_sync_scans`（phase=local、state=running），跑完清理。

| 用例 | 预置 scan_cursor | 结果 |
|---:|---|---|
| 1 JSON 损坏 | `{not-json`，processed=7、local_bytes=999 | 无 `WP_Error`；done=true、files=4、bytes=26；processed=4、local_bytes=26；cursor 变为 `{"d":["sub"],"a":"d.txt"}`；index 4 行合计 26 B |
| 2 顶层目录被删 | `{"d":["gone"],"a":"x.txt"}`，processed=99、local_bytes=1234 | 同样 done=true/4/26，processed=4，cursor 正常 |
| 3 嵌套目录被删 | `{"d":["sub","missing"],"a":"x.txt"}`，processed=55、local_bytes=777 | 同样 done=true/4/26，processed=4 |

三种损坏都被 `local_phase()` 的第一次 `cursor_missing` 分支重置为空游标并从根重扫，不会把旧的 processed/local_bytes 叠加到新结果。**未构造出双失败分支**：如果重置后 `walk_local()` 仍失败，代码会返回 `WP_Error('sio_local_cursor')`；当前 root 环境无法让 `scandir` 失败，这一段仍只有静态阅读。

**E. 编辑页覆盖与插入顺序**

详细操作与 DOM 结果见 §8.10.7，摘要：TinyMCE 下按勾选 419→424 插入附件 224→229；勾选图片 419、ZIP 418、图片 424 时，正文为 224→229→ZIP 222，证明图片优先且组内保留勾选顺序。纯 textarea（临时 mu-plugin 设 `tinymce=false`）会挂载面板，但插入只 toast「找不到编辑器，请点一下正文再试」，textarea 不变，派发 `sufm:drag-files` 也没有投放层。块编辑器 `/wp-admin/post-new.php` 根本没有加载 editor-picker 的 script/link，`#sufm-picker` 不存在，属于不可达而非只 toast。临时 mu-plugin 已删除，页面恢复 TinyMCE iframe；没有保存文章或写库。

**F. 边界**

- 三条生产写路径（Quota 提交、删除审核执行、卸载）仍只在测试站运行；生产保持只读，不能把本节当作生产演练记录。
- 扫描游标的“重置后再次失败”分支仍只有静态阅读；其余三种损坏路径已有运行证据。
- 卸载漏删 `file_tokens` 是实测缺陷，已由 1.24.3 修复并在隔离环境复跑 15 张表→0；生产仍未执行卸载，不能把本节当作生产演练记录。
- 1.24.2 补测轮没有改插件代码；1.24.3 只改卸载删表循环与测试，没有新增类、方法、钩子、REST 路由或数据表。

### 10.18 覆盖升级自愈的运行验证（1.24.4，2026-11-10）

验证环境：测试站 `/root/sio-e2e`（站点 `https://demo.77il.cn`，表前缀 `t_`），插件目录临时软链到 1.24.4 工作树。验证只写测试站数据库，生产未执行。被测路径是 `src/Plugin.php:81-86` 的 `Upgrade_Guard::maybe_upgrade(SAKURA_TOOLS_VERSION, fn() => Activation::run())`：版本 option 落后时先抢 `sakura_imgopt_upgrading` 原子锁，再补跑 Activation（建表、排程、回填），最后才在 `src/Activation.php:123-129` 写入新版本号。

**A. 单请求**

| 步骤 | 操作 | 结果 |
|---|---|---|
| 1 预置 | `update_option('sakura_imgopt_version','1.24.3')`；`wp_clear_scheduled_hook('sakura_imgopt_daily_backup_cleanup')`；删除 lock/error | `before=1.24.3`、`cron=no` |
| 2 触发 | `curl -sL http://127.0.0.1:18080/wp-login.php` | HTTP 200，9,197 字节 |
| 3 读回 | `wp --skip-plugins eval` | `after=1.24.4`、`cron=yes`、`lock=absent`、`error=absent` |

**B. 并发**

再次预置为 1.24.3、清 cron、删锁，然后 `seq 1 10 | xargs -P10 -I{} curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:18080/wp-login.php`。10 个请求全部返回 302（WordPress 登录重定向，但都已进入 WordPress 并完成插件 boot）；读回 `after=1.24.4`、`cleanup_events=1`、`lock=absent`、`error=absent`。只有一个请求抢到锁并执行 Activation，其余请求没有重复排程，也没有留下锁或错误 transient。

**C. 边界与恢复**

- 版本 option 是人为回退的，不是从真实旧版升级；它验证的是「前台请求发现版本落后 → 补跑 Activation → 写入版本」这条路径，不覆盖旧数据迁移本身。
- 测试站随后恢复软链到 `/root/work/sakura-tools`，并把 option 写回 1.24.3；恢复后 plugin version=1.24.3、option=1.24.3、cron 存在。生产部署后首次请求会再次触发同一条路径。
- 单测侧由 `tests/UpgradeGuardTest.php` 覆盖空版本、版本相等/落后、锁已被占用、回调异常、失败 transient 与不阻塞前台；全量 PHPUnit 437 tests / 1,315 assertions / 5 skipped。

**D. 静态检查（2026-11-10 复跑）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPStan | `vendor/bin/phpstan analyse --no-progress -c phpstan.neon` | `[OK] No errors`（level 5，exit 0） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1` | **0 errors / 36 warnings / 21 files**（exit 0） |

PHPCS 的 36 条 warning 全部落在本轮未改动的既有文件，集中在三类规则：保留字参数名（`$parent` / `$new` / `$resource`）、`$wpdb->prepare()` 占位符计数（`includes/class-s3-cli.php:469`、`src/UserFiles/Services/Space_Store.php:2025`、`:2032`）、低于 15 分钟的 cron 间隔（`src/Sync/Verify_Worker.php:99`、`src/S3/Delete_Worker.php:69`），另有 `src/Image/Crop_Config_Scanner.php:1248` 的注释代码告警。`src/Upgrade_Guard.php` 与 `tests/UpgradeGuardTest.php` 均不在警告清单内；本轮没有新增 PHPCS 错误。

### 10.19 后台命令面板卸载的运行期验证（1.25.1，2026-11-12）

验证环境：测试站 `/root/sio-e2e`（站点 `https://demo.77il.cn`，表前缀 `t_`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.1 工作树。测量方式为真实浏览器（无 CPU / 网络节流）加载后台页、真实点击插件页签，共 31 次页面加载 + 15 次点标签切页；服务端同时用 mu-plugin 探针记录 `init` / `admin_init` / `admin_footer` / `shutdown` 四段耗时。验证只写测试站数据库，生产未执行。

**A. 根因定位（先排除插件自身）**

| 检查 | 命令 / 方法 | 结果 |
|---|---|---|
| 服务端基线 | `curl -w '%{time_starttransfer} %{time_total}'` × 15 页 | TTFB 0.20–0.22 s、total 0.30–0.35 s、HTML 186–195 KB、SQL 51–66 条、内存峰值 28–32 MB |
| 插件自身成本 | 探针按 hook 归类 31 次请求 | sakura 相关 hook 合计 **21.8 ms**，且几乎全是 WordPress 自己的 `gettext_sakura-imgopt` 翻译加载 |
| 插件启用 / 停用 A/B | 同一批页面各测一轮 | TTFB 差 **< 5 ms**，SQL 条数与原生页无差异（51–66） |
| 原生 `plugins.php` 对比 | 有无插件资源 | 只多 1 个 CSS 与约 2 KB HTML |
| 逐项阻断 | 分别阻断 `/wp-includes/js/dist/`、`layout.js`、命令面板 | 只有阻断 `js/dist/` 能把 TBT 打到 0（load −30%、传输 1,064 KB → 226 KB） |

静态溯源：WordPress 6.9 起在 `wp-includes/default-filters.php:619` 注册 `add_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )`；该函数（`wp-includes/script-loader.php:3498`）在 `…:3609-3611` 无条件入队 `wp-commands`、`wp-commands` 样式与 `wp-core-commands`。`wp-commands` 依赖 react / react-dom / react-jsx-runtime / wp-components / wp-data / wp-element / wp-i18n / wp-keyboard-shortcuts / wp-preferences / wp-primitives / wp-private-apis，`wp-core-commands` 再加 wp-compose / wp-core-data / wp-html-entities / wp-router / wp-url。核心没有提供关闭命令面板的 filter，只能 `remove_action` 或按句柄 dequeue。

**B. 1.25.1 的效果（`sakura-tools.php:121-137`）**

| 指标 | 卸载前 | 卸载后 |
|---|---|---|
| 单页传输 | 1,061 – 1,064 KB | **76 – 85 KB** |
| `/wp-includes/js/dist/` 脚本数 | 45 | **3** |
| DOMContentLoaded | 2,925 – 3,076 ms | **651 – 827 ms** |
| 点击页签切页 | 2,987 – 3,566 ms | **709 – 1,443 ms** |
| 长任务（>50 ms） | 4 – 14 个 | **0 – 2 个** |

**C. 范围与回归**

| 页面 | `wp-commands` 引用 | ⌘K 入口 | 插件资源 |
|---|---:|---|---|
| `sakura-tools` 与 `sakura-tools-{image,storage,files}` 全部页签（5 页实测） | **0** | 不输出 | `sakura-admin-tokens` / `base` / `core`、`sakuraPage`、`sakura-layout` 全部正常，`.sakura-layout-enabled` / `data-label` / `nav.sakura-tabs` / `window.SakuraAdmin` 均存在，无 JS 报错 |
| `dashboard` / `plugins.php` / 文章编辑页 | 5（原样） | 正常 | 不受影响 |

**D. 为什么不能整站卸载**：`wp-includes/script-loader.php:1775/1819/1888` 的 `wp-edit-post` 把 `wp-commands` 声明为依赖，全站 dequeue 会让区块编辑器失效。工具栏 ⌘K 入口由 `wp-includes/admin-bar.php:967` 的 `wp_script_is( 'wp-core-commands', 'enqueued' )` 判定，脚本卸载后入口自然不输出，不需要另改菜单。

**E. 静态检查（1.25.1 复跑）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **441 tests / 1,327 assertions / 5 skipped**，OK |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同，新增文件无告警） |
| 语法 | `php -l sakura-tools.php` | No syntax errors |

> 关于 Query Monitor：测量期间曾安装 4.1.1 做交叉核对，它会给每个后台页注入约 293 KB 内联脚本（HTML 207 KB → 500 KB），本身足以改变结论，因此正式测量前已停用。上表的数字全部来自停用 Query Monitor 后的采集。

### 10.20 卡片重排修复的运行期验证（1.25.2，2026-11-12）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.2 工作树（改动的两个文件用 `cp` 同步进软链目录，服务端返回的 `storage-sync.js` md5 为 `8eaf49042b369a9ccac02ff81bf6157e`，与工作区一致）。测量用真实浏览器真实点击，页面为 `admin.php?page=sakura-tools-storage&t=sync`。验证只写测试站，生产未执行。

**A. 缺陷复现（修复前，1403×900 视口）**

| 观察点 | 初始（7 行） | 点「待上传」后（1 行） | 说明 |
|---|---|---|---|
| `.recon-toolbar` 高度 | 38 px | **176 px** | 工具栏本身没变高，是被 grid 拉伸 |
| `.table-scroll` 高度 | 574 px | 297 px | 少 6 行只回收一半 |
| `.pagination` 高度 | 30 px | **168 px** | 分页按钮没有变多，是被拉伸 |
| 卡片高度 / `min-height` | 824 / 824 px | **824 / 824 px** | 高度固化成旧值 |

反向操作（1 行 → 7 行）危害更大：卡片不重新测量，`#reconcile` 直接压住下面的 `#logs`，实测重叠 **459 px**。六个筛选逐一验证时还发现日志卡的级别筛选（全部 / 错误 / 警告 / 信息）同样会让卡片在 245 / 273 px 之间跳动。

**B. 修复后的实测（真实点击，等待 AJAX 返回）**

| 视口 | 筛选 | `min-height` | `.recon-toolbar` | `.table-scroll` | 行数 | 与 `#logs` 间距 |
|---|---|---:|---:|---:|---:|---:|
| 1403 | 全部 6 | 886 px | 38 px | 636 px | 7 | 18 px |
| 1403 | 待上传 0 | 409 px | 38 px | 159 px | 1 | 18 px |
| 1403 | 失败 0 | 409 px | 38 px | 159 px | 1 | 18 px |
| 1403 | 仅云端 0 | 409 px | 38 px | 159 px | 1 | 18 px |
| 1403 | 内容不一致 0 | 409 px | 38 px | 159 px | 1 | 18 px |
| 1403 | 已同步 6 | 886 px | 38 px | 636 px | 7 | 18 px |
| 1600 | 待上传 / 已同步 / 全部 | 409 / 886 / 886 px | 38 px | — | — | 18 px |
| 900（单列） | 初始 / 待上传 / 已同步 | 无 `min-height`（`is-free=false`） | 78 px | — | — | 卡片 978 / 460 / 1061 px 随内容伸缩 |

**C. 日志卡与回归**

| 检查 | 结果 |
|---|---|
| 日志级别筛选 | 全部 245 px → 错误 273 px → 警告 273 px → 信息 245 px；分组高度 2853 ↔ 2881 跟随变化，卡片底边始终在画布内（修复前「错误」时超出画布 10 px 且分组高度不更新） |
| 搜索 `webp` | 886 → 409 px，清空后 409 → 886 px |
| 每页条数 25 / 50 / 100 | 切换正常，卡片随行数重排 |
| 同页其余三张卡（`#sakura-s3-connection` 1365 px、`#sakura-s3-behaviour` 448 px、`#sakura-s3-test` 182 px） | 加 `align-content: start` 后高度与溢出量**完全不变**，无回归 |
| 其他页面 | `files-log` / `files-users` 早已调用 `layout-refresh`（`files-log.js:301-307`、`files-users.js:353-368`），本次只补 `storage-sync.js` 的缺口 |

**D. 静态检查（1.25.2 复跑）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **441 tests / 1,327 assertions / 5 skipped**，OK |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过 |
| PHP 语法 | `php -l`（全部 PHP 文件） | No syntax errors |

### 10.21 对象存储布局画布合并的运行期验证（1.25.3，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.3 工作树（改动的 6 个文件用 `cp` 同步进软链目录后逐个核对 md5 与工作区一致）。测量用真实浏览器真实鼠标拖拽，视口 1403×900；布局写入的是**测试站**用户 1 的 `_sakura_imgopt_layout`，验证后已 `delete_user_meta` 清空恢复初始状态。生产未执行。

**A. 三屏卡片与布局组归属（修复前后对比）**

| 页面 | 卡片 | 修复前 | 修复后 |
|---|---|---|---|
| `t=connection` | profiles / upload-metrics / monitoring | 无拖动头、0 grip、拖不动（落在 `[data-sakura-layout="s3"]` 之外） | 六张卡同组，均有 `:scope > .card__head.sakura-layout-drag` 与 3 个 `.sakura-layout-grip`（x/y/xy） |
| `t=connection` | behaviour / connection / test | 组内可拖 | 不变（仍是组内） |
| `t=sync` | ignored / tasks / health / storage / notes / reconcile / logs | 7 张全在 `data-sakura-layout="sync"` 组内 | 不变（本轮未改） |
| `t=deletes` | queue | **没有布局组**，`LAYOUT_PAGES` 无该项，`layout.js` / `layout.css` 都不加载 | `[data-sakura-layout="deletes"]` 存在，队列卡有拖动头与 3 个 grip，工具栏与脚本均已加载 |

**B. 拖拽与持久化（1403px，真实鼠标）**

| 操作 | 结果 |
|---|---|
| 打开「布局修改」（`.sakura-layout-tools-toggle`） | `aria-expanded=true`，"恢复默认"按钮出现；组 class = `card-grid sakura-layout-grid is-free is-laid` |
| 拖 `connection` 卡头部到左列 | `data-sakura-col` 7 → 2、`left` = 96.08px，排列变为 profiles / connection / behaviour / test / upload-metrics / monitoring |
| 刷新页面 | 上述排列与 col=2 保留（服务端写入用户元数据 `_sakura_imgopt_layout`） |
| 拖 `test` 卡侧边 grip | span 6 → 11，宽度 1041px |
| 点「恢复默认」 | 回到 profiles / behaviour / connection / test / upload-metrics / monitoring，与首次访问（fresh 无元数据）完全一致 |
| `t=deletes` 单卡拖动 | 无位移（组内仅一张卡，符合预期），无报错 |

**C. 断点与控制台**

| 项 | 结果 |
|---|---|
| 1403px（≥1101px） | `is-free is-laid`，六张卡 position 为 absolute，组高由 `freeLayout()` 计算 |
| 900px（<1101px） | 组 class 退化为 `card-grid sakura-layout-grid`（无 `is-free`/`is-laid`），六张卡 position 全为 `static` 单列堆叠，"布局修改"关闭；回到 1403px 自动恢复 |
| 控制台 | `t=deletes` 3 条消息、0 error 0 warning（仅 JQMIGRATE / 查询统计 / Zibll 主题横幅）；`t=connection` 拖动与刷新全程无 error |

**D. 静态检查（1.25.3 复跑）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **441 tests / 1,332 assertions / 5 skipped**，OK（较 1.25.2 增加 5 条布局断言） |
| LayoutTest | `vendor/bin/phpunit --filter LayoutTest` | OK（8 tests / 45 assertions） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1` | **0 errors / 36 warnings / 21 files**（与基线相同） |
| JS 语法 | `node --check assets/ui/js/layout.js` | 通过 |
| PHP 语法 | `php -l`（6 个改动文件） | No syntax errors |

> 说明：本轮的 5 条新断言落在 `tests/LayoutTest.php`——`deletes` 页进入 `test_default_layout_covers_every_layout_page` 的页面清单，并新增对 `s3` order（6 张卡顺序）、`s3` 的 `profiles` / `monitoring` span 12、`deletes` order 与 span 的校验

### 10.22 「云端体积分布」空态的运行期验证（1.25.4，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.4 工作树（改动的三个文件用 `cp` 同步进软链目录后核对 md5 与工作区一致：`includes/views/s3-sync-center.php` `027fb09c6f173b2afcdb71f1f34eb6a6`、`src/S3/Sync_Center_Module.php` `4d8f20efab737b0a62a3fe894ce47482`、`assets/ui/pages/storage.css` `05b39d153a826322a39656cfc5429eba`）。状态用直接改测试站 `{prefix}sio_sync_scans` 行为准，视口 1280×720 与 1403×900。

**A. 六种状态与渲染结果**

| 判定 | 造法 | 根节点 class | 环图 | 标题 / 文案 | 卡片高 |
|---|---|---|---:|---|---:|
| `unscanned` | 清空 `sio_sync_scans` | `storage-mix--unscanned` | 无 | 「还没有核对记录」+ 说明 + 「开始完整核对」/「查看任务中心」 | 347px（empty 块 193px、页脚 36px、按钮各 99px） |
| `scanning` | `status=running`、`total_remote=0`，并用 `page.route('**/admin-ajax.php', r => r.abort())` 冻结轮询 | `storage-mix--scanning` | 无 | 「正在核对」+ 「核对跑完之后，这里会自动显示云端对象的类型占比，无需手动刷新。」，无按钮 | 280px |
| `interrupted` | `status=failed`、`phase=remote` | `storage-mix--interrupted` | 无 | 「上次核对没有跑完」+ 「继续核对」/「查看任务中心」 | 326px |
| `empty` | `status=done`、`total_remote=0` | `storage-mix--empty` | 有（纯灰环） | 环图 + 说明「完整核对已经跑过，对象存储里目前没有可统计的文件。上传后需要再核对一次，占比才会更新。」 | 386px |
| `ready`（有扫描记录） | `status=done`、`total_remote=6` | `storage-mix--ready` | 有 | 环图 + 「数据来自 11-13 00:27 的完整核对；之后单张上传不会改变这里的占比，重新核对才会更新。」 | 386px |
| `compat` | 关掉索引开关 | `storage-mix--compat` | 有 | 环图 + 「兼容模式下实时读取云端清单，当前没有可统计的对象。」 | 386px |

**B. 卡内按钮端到端**

| 操作 | 结果 |
|---|---|
| `unscanned` 下点卡内「开始完整核对」 | `admin-ajax.php` 200 → 页面自动 reload → 扫描行新建（`done`、`total_remote=6`）→ 卡变 `storage-mix--ready` |
| `interrupted` 下点卡内「继续核对」 | `admin-ajax.php` 200 → 页面 reload → 卡变 `storage-mix--ready` |
| `scanning` 下 | 无按钮，只剩文案；解除 `page.route` 后轮询恢复，跑完自动转 `ready` |

**C. 断点与回归**

| 项 | 结果 |
|---|---|
| 900px 视口（`unscanned`） | 卡片 337px、`min-height: auto`、无横向溢出，文案自动换行 |
| 1403px 视口（`unscanned`） | 卡片 347px，与右侧「连接与队列健康」逐行对齐 |
| `.storage-mix__foot` | 六种状态下都存在，本地 / 云端 / 差三个数字不随状态消失 |
| 同页其它卡片 | `storage-mix` 之外的 ignored / tasks / health / notes / reconcile / logs 六张卡高度、溢出量均与 1.25.3 一致 |
| 控制台 | 0 error（只有 JQMIGRATE / Zibll 主题横幅这两条既有消息） |

**D. 静态检查（1.25.4 复跑）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **441 tests / 1,332 assertions / 5 skipped**，OK |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| PHP 语法 | `php -l includes/views/s3-sync-center.php`、`php -l src/S3/Sync_Center_Module.php` | No syntax errors |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过（本轮未改 JS） |

> 说明：本轮**没有新增 PHPUnit 用例**——状态机是 `includes/views/` 里的纯模板逻辑，测试站用真实浏览器逐态走查已覆盖；`phpstan` 曾报 `includes/views/s3-sync-center.php:570 Negated boolean expression is always false. (booleanNot.alwaysFalse)`，因为 `unscanned` 分支只在 `$index_enabled` 为真时可达，该 `disabled()` 调用已删除、`$index_enabled` 也从闭包 `use` 列表移除。，把「恢复默认 = 首次访问」这条不变式固化下来。删除 `migrate` 后该页 order 长度由 7 变 6，断言同时覆盖了这次清理。

### 10.23 同步中心每月校准与引导弹窗的运行期验证（1.25.5，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.5 工作树（改动的 6 个文件用 `cp` 同步进软链目录）。测量用真实浏览器，视口 1280×720 / 390×844 / 320×720 / 900×700。**只读结论均来自测试站**；生产在部署后单独验证（见 §13）。

**A. 定时任务与自定义周期**

| 检查 | 命令 | 结果 |
|---|---|---|
| 自定义周期注册 | `wp eval 'wp_get_schedules()["sakura_imgopt_monthly"]'` | `interval` = **2592000**（30 天）、`display` = 「每月（Sakura 云端校准）」 |
| 常量一致性 | `wp eval 'var_dump( Sync_Calibrator::STALE_SECONDS )'` | `int(2592000)`，与周期 interval 相等 |
| 首次排程 | `Sync_Calibrator::schedule()` 后 `wp_next_scheduled( 'sakura_imgopt_sync_calibrate' )` | 返回 `1794624344`，`wp cron event list` 显示 **23 小时 59 分**（即 `FIRST_RUN_DELAY = DAY_IN_SECONDS`，首次不立刻跑，避开启用当天的请求高峰） |
| 重复排程 | 连续调用 `schedule()` 两次 | 只有一条事件，`wp_next_scheduled()` 判重生效 |
| 惰性兜底 | 把扫描行 `finished_at` 改到 31 天前，刷新同步中心 | `wp cron event list` 立刻出现 `sakura_imgopt_sync_calibrate_now`（53 秒后），周期事件仍在 23 小时 55 分 |
| 钩子独立性 | 同上，检查两个钩子 | `sakura_imgopt_sync_calibrate` 与 `sakura_imgopt_sync_calibrate_now` 互不覆盖；**若共用钩子，`wp_next_scheduled()` 会把 kick 误判为已排而吞掉**（这是独立命名的唯一理由） |

**B. 弹窗门控（PHP 侧，`wp eval` + `wp_set_current_user(1)`）**

| 入参 | 输出的字节数 | 结论 |
|---|---:|---|
| `reconcile_html( $o, true, false, false )` | 1,925 | 已配置 + 无扫描 + 不忙 → 输出，含 `#sakura-s3-reconcile-modal`、`RECONCILIATION / REQUIRED`、`data-sakura-action="scan"`，**无 checkbox** |
| `reconcile_html( $o, true, true, false )` | 0 | 已有扫描行 → 不输出 |
| `reconcile_html( $o, true, false, true )` | 0 | 扫描进行中 → 不输出 |
| `reconcile_html( $o, false, false, false )` | 0 | 未配置 → 交给配置弹窗，不输出核对弹窗 |
| `modal_html( $o, 'sync' )`（配置完整） | 0 | 配置完整且未点过提醒 → 不输出 |
| `modal_html( $o, 'sync' )`（清空 `bucket`） | 有 | 输出，chips 只列「存储桶名称」 |

> 注意：`wp eval` 里测这两个方法**必须先 `wp_set_current_user( 1 )`**，否则 `current_user_can()` 为假，永远返回空字符串。

**C. 未核对引导弹窗（1280×720）**

| 项 | 实测值 |
|---|---|
| 触发条件 | 已配置 + `sio_sync_scans` 无记录 |
| 根节点 | `#sakura-s3-reconcile-modal`，`hidden=false`、`aria-hidden=false` |
| 定位 | `position: fixed`、`z-index: 100000`、`display: grid`、`place-items: center` |
| 卡片 | **560 × 291**，圆角 22px（`--g-radius-lg`），白底、1px 边线 |
| 图标 | 46×46，底色 `rgb(251,250,247)`（`--g-accent-soft`）、字色 `rgb(95,97,101)`（`--g-ink-soft`），tone 为 neutral |
| 标题 | 「还需要一次完整核对」，`activeElement` 落在 `#sakura-s3-reconcile-title`，`outline: none`（不留黑框） |
| 滚动锁 | `body` 带 `sakura-modal-open`、`overflow: hidden` |
| 关闭 | 点「稍后处理」→ `hidden=true`、`aria-hidden=true`、`body` 去掉 class、`overflow` 回 `visible`、焦点回 `BODY` |
| 主按钮端到端 | 点「开始核对」→ `admin-ajax.php` 200 → 页面 reload → 新建扫描行、`.storage-mix` 变 `--ready`、弹窗消失 |

**D. 未配置弹窗在同步页（临时清空 `s3_options` 的 `bucket` / `key` / `secret`）**

| 项 | 实测值 |
|---|---|
| 根节点 | `#sakura-s3-config-modal`，kicker `OBJECT STORAGE / SETUP` |
| 标题 | 「还差几步就能启用对象存储」，`activeElement = #sakura-s3-config-title` |
| 缺失项 | chips「存储桶名称 / Access Key / Secret Key」 |
| 动作 | 「稍后处理」+「去补全配置」，`hasCheckbox = true`（多一个「不再提醒我」） |
| 卡片 | **560 × 307**；icon 底色 `rgb(239,238,233)`（暖灰，不是红——配置缺失不是错误） |
| 主按钮 href | `Registry::url( 'storage', 'connection' )`；因 connection 是该模块默认 tab，**URL 里不带 `t=connection`**（正常，不是 bug） |
| 回收 | 测完用 `wp option update s3_options "$(cat /tmp/s3opt_backup.json)" --format=json` 还原，`is_configured()` 回到 true |

**E. 窄屏与键盘（UI 规则 §5.1 逐条）**

| 项 | 390×844 | 320×720 |
|---|---|---|
| 卡片宽度 | 354px | 284px |
| 横向溢出 | `document.scrollWidth === innerWidth`，无 | 同左，无 |
| 按钮 | 整宽 | 各 **248px**，整宽 |
| 动作区顺序 | 「不再提醒我」→「稍后处理」→「去补全配置」 | 同左 |
|
| 键盘 / 指针 | 结果 |
|---|---|
| Tab 循环 | 4 个可聚焦元素（checkbox / 稍后处理 / 主按钮 / 关闭）依次轮转，第 7 次仍不逃出弹窗 |
| Escape | 关闭，滚动锁复原 |
| 点背景 | `dispatchEvent( click )` 到 `.sakura-modal__backdrop` → `hidden=true`、`aria-hidden=true` |

> 动作区在 `@media (max-width: 520px)` 下必须 `flex-direction: column`，**不能写 `column-reverse`**——DOM 顺序是 checkbox 在前、按钮组在后，反向会把复选框顶到最下面、主按钮跑到最上面。改 `column` 后主按钮正好落在最后，符合规则里「主按钮在最后」的要求。

**F. 回归与断点**

| 项 | 结果 |
|---|---|
| `t=connection` 页配置弹窗 | 仍正常：kicker 同、primary href `#sakura-s3-connection`、文案「填写连接信息」 |
| `t=connection` 六张卡 | profiles / behaviour / connection / upload-metrics / test / monitoring 全在 `[data-sakura-layout="s3"]` 组内，无 reconcile 弹窗 |
| 900px 同步页 | 组 class 退化为 `card-grid sakura-layout-grid`（无 `is-free`），无横向溢出 |
| 控制台 | 3 条消息、0 error 0 warning（JQMIGRATE / 数据库查询 66 次 310ms / Zibll 主题横幅） |
| 数据回收 | 扫描行 id=1 重建为 `prof_dc340e753a3f` / incremental / done / total_local 402 / total_remote 6 / 402 processed；counts 仍 pending 396 + synced 6；用户 meta 的 snooze / dismissed 已删 |

**G. 静态检查（1.25.5）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **445 tests / 1,344 assertions / 5 skipped**，OK（新增 `tests/SyncCalibratorTest.php` 4 例） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1 --report=summary` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| PHP 语法 | `php -l` 全部 PHP 文件 | No syntax errors |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过 |

> `tests/SyncCalibratorTest.php` 与 `tests/ModuleRegistrationTest.php` 都要在 `setUp` 里把 `$GLOBALS['wpdb']` 换成带 `public $prefix` 的匿名类，否则 `src/Sync/Schema.php:55` 会报 `Attempt to read property "prefix" on null`——构造 `Index_Repository` 时就要拼表名。

### 10.24 停用态提示与三值状态的运行期验证（1.25.6，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.6 工作树（改动的文件用 `cp` 同步进软链目录并核 md5）。测量用真实浏览器，视口 1403×900 / 1280×720 / 390×844 / 320×720。**只读结论均来自测试站**；生产在部署后单独验证（见 §13）。

**A. 四种状态的弹窗门控**

测试站与生产状态相同：`enabled=false`、`is_configured=true`、`configuration_errors=[]`（provider `bitiful`、bucket `text-demo`）。按顺序切换四种状态，统计页面 HTML 里三个弹窗根节点的出现次数：

| 状态 | 构造方式 | setup 弹窗 | paused 弹窗 | reconcile 弹窗 | 状态卡 | 副标题 |
|---|---|---:|---:|---:|---|---|
| 缺字段 | 清空 bucket / key / secret | 1 | 0 | 0 | 配置不完整（bad） | 缤纷云 · 待补全配置 |
| 已配置未启用 | `enabled=false`（默认） | 0 | **1** | 0 | 已停用（warn） | 缤纷云 · 连接信息完整，开关关闭 |
| 已配置已启用 | `enabled=true` | 0 | 0 | 0 | 连接正常（ok） | 缤纷云 · 覆盖率 1.5% |
| 已启用但从未核对 | 删扫描行 + `enabled=true` | 0 | 0 | 1 | 连接正常（ok） | 缤纷云 · 覆盖率 0.0% |

删除队列页（`t=deletes`）在任何状态下都不输出 paused 弹窗（`paused_modal=0`、`fatal=0`）；连接配置页只输出 setup 弹窗，不输出 paused（开关本身就在该页，再弹模态会挡住要点的控件）。

**B. 停用态弹窗的尺寸与键盘**

| 项目 | 实测值 |
|---|---|
| 卡片尺寸 | 560 × 291，圆角 22px，白底 `rgb(255,255,255)` |
| 遮罩与层级 | `display: grid`、`position: fixed`、`z-index: 100000` |
| 图标 | 46 × 46，底色 `rgb(251,250,247)`（暖灰，非红）、字色 `rgb(95,97,101)` |
| 初始焦点 | `document.activeElement.id === 'sakura-s3-paused-title'` |
| 标题描边 | `outline: none`（自动聚焦不画黑框） |
| 滚动锁 | `body.sakura-modal-open` 存在、`overflow: hidden` |
| Tab 循环 | `INPUT → btn btn--ghost → btn btn--primary → sakura-modal__close → INPUT`（4 个元素内循环，不逃出） |
| Escape | `hidden=true`、`aria-hidden=true`、body 解锁、`overflow` 复原 `visible`、焦点回 `BODY` |
| 关闭按钮 | 同上；关闭同时写 12 小时 snooze |
| 点背景 | `hidden=true`、`aria-hidden=true` |
| 勾「不再提醒我」 | `sakura_imgopt_s3_config_notice_dismissed=1`，刷新后不再出现 |
| 主按钮 | 「去启用 S3」→ `admin.php?page=sakura-tools-storage`；目标页无 paused / setup 弹窗，`input[name="enabled"]` 存在且 `checked=false` |

**C. 窄屏与溢出**

| 视口 | 卡片宽 | 动作区 `flex-direction` | 两个按钮宽 | `document.scrollWidth === innerWidth` |
|---|---:|---|---:|---|
| 390 × 844 | 354 | `column` | 各 318（整宽） | 390 = 390 |
| 320 × 720 | 284 | `column` | 各 248（整宽） | 320 = 320 |
| 1403 × 900 | 560 | `row` | 自然宽 | 1403 = 1403 |

> 动作区竖直排布必须用 `flex-direction: column`，不能写 `column-reverse`：DOM 里复选框在按钮组之前，反向会把复选框顶到最下面。改 `column` 后主按钮正好落在最末。

**D. 本轮修掉的三个真实缺陷**

| 缺陷 | 位置 | 现象 | 修法 |
|---|---|---|---|
| 变量名不一致 | `includes/views/s3-sync-center.php:79` | 守卫写 `isset( $is_enabled )`，但该变量从未赋值（`page_data` 的键是 `is_enabled`，绑定的局部变量是 `$storage_enabled`），`isset` 恒 false → **连 S3 正常启用也被判成停用**（修复前：启用态页面仍渲染「已停用」两次、paused 弹窗出现一次） | 改为 `isset( $storage_enabled )`，docblock 改 `@var bool $storage_enabled`；修复后启用态 `已停用=0`、`连接正常=1`、`paused_modal=0` |
| 死代码分支 | `src/S3/Config_Notice.php` 的 `paused_html()` | 带 `$context` 形参，`connection` 上下文把主按钮指向 `#sakura-s3-connection`；但 `src/S3/S3_Storage_Page.php:509` 只挂 `modal_html( $this->options, 'connection' )`，从不挂 paused 变体，该分支永远走不到 | 签名简化为 `paused_html( Options $options ): string`，删掉分支，主按钮固定 `Registry::url( 'storage', 'connection' )` |
| 调用点未跟着改 | `includes/views/s3-sync-center.php:959` | 改签名后仍传 2 参 → PHPStan `Static method Sakura\ImageOptimizer\S3\Config_Notice::paused_html() invoked with 2 parameters, 1 required.` | 改为 `Config_Notice::paused_html( $options )` |

**E. 状态卡三值化的取值链**

| 分支 | `$status_text` | `$status_tone` | `$health_text` | `$health_warn` | `$page_status_sub` |
|---|---|---|---|---|---|
| `! $is_configured` | 配置不完整 | `bad` | 配置不完整 | true | `%s · 待补全配置` |
| `! $storage_enabled` | 已停用 | `warn` | 已停用 | true | `%s · 连接信息完整，开关关闭` |
| 其余 | 连接正常 | `ok` | 配置可用 | false | `%1$s · 覆盖率 %2$s%%` |

之后仍有一条覆盖规则：`if ( $failed_total > 0 || ! $index_enabled ) { $status_tone = 'warn'; }`（失败任务或索引关闭优先降级为 warn）。

**F. 静态检查（1.25.6）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **455 tests / 1,369 assertions / 5 skipped**，OK（新增 `tests/ConfigNoticePausedTest.php` 10 例；比 1.25.5 的 445/1,344 多 10 例 / 25 断言，同时删掉了 1 个针对已删分支的用例） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1 --report=summary` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| PHP 语法 | `php -l` 全部改动文件 | No syntax errors |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过 |

> 本轮不新增类、方法、钩子、REST 路由与数据表：图谱仍 **192 节点 / 873 连线**（648 `ref` + 50 `modref` + 175 `contains`），模块 17 个、`modref` 50 条、钩子 209 条全部不变。`tests/bootstrap.php` 新增的 `wp_schedule_single_event` / `wp_unschedule_hook` / `get_user_meta` / `update_user_meta` / `current_user_can` 等存根只服务测试，不进入生产图谱。

### 10.25 弹窗关闭规则的运行期验证（1.25.7，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.7 工作树（改动文件用 `cp` 同步并核 md5：`Config_Notice.php` `9869781fd81bc2a12f0237e999b5dc0a`、`Sync_Center_Module.php` `34c0e45993cdc7c8c5994c2df67de3f5`、`storage-sync.js` `20320d2568e593140c4b94bda42e1201`、`storage-connection.js` `141843b4dd154239c42b2cb29706fe68`）。测量用真实浏览器 1403×900，每个用例前清空 `sakura_imgopt_s3_config_notice_dismissed` / `..._snoozed_until` 两个 user meta 与 `sessionStorage['sakuraS3ConfigSkipOnce']`（不清会被前一个用例的静默挡住）。

**A. 五种关闭方式对「下次进页面」的影响**

停用态（`enabled=false`、`is_configured=true`、`errors=[]`）下首次进入 `admin.php?page=sakura-tools-storage&t=sync`，弹窗可见，然后分别用五种方式关闭并 `reload`：

| 关闭方式 | 修复前（1.25.6） | 修复后（1.25.7） | 写入的 user meta |
|---|---|---|---|
| 点 X（`.sakura-modal__close`） | 刷新后**不出现** | 刷新后**仍出现** | 无 |
| 点次按钮「暂不处理」（1.25.6 叫「稍后处理」） | 刷新后不出现 | 刷新后**仍出现** | 无 |
| 按 Esc | 刷新后不出现 | 刷新后**仍出现** | 无 |
| 点遮罩 `.sakura-modal__backdrop` | 刷新后不出现 | 刷新后**仍出现** | 无 |
| 勾「不再提醒我」再关（任意方式） | 刷新后不出现 | 刷新后不出现 | `dismissed=1` |

实测结果（`hidden` 属性）：`fresh=VISIBLE` → X 后 `VISIBLE` → 次按钮后 `VISIBLE` → Esc 后 `VISIBLE` → 勾选后 `absent`。勾选用例结束后 `wp user meta` 为 `dismissed=[1]`、`snoozed_until=[]`，与预期一致。

**B. 兼容旧版本残留**

1.25.6 及更早版本会把普通关闭写成 `sakura_imgopt_s3_config_notice_snoozed_until = time() + 43200`。升级到 1.25.7 后这些残留既不能压制弹窗、也不能留在库里：

| 步骤 | 结果 |
|---|---|
| 手工写入 `snoozed_until=9999999999`（远未来） | `seeded=[9999999999]` |
| 调用 `Config_Notice::should_prompt_paused( $options )` | `yes`（不再被压制） |
| 同一次请求后再次读取该 meta | `after=[]`（已被 `delete_user_meta()` 清掉） |

接口侧同样收窄：`wp_ajax_sakura_s3_config_notice` 只认 `dismiss=1`；`snooze=1` 仍被接受（避免旧缓存脚本报 400），但只做 `delete_user_meta()` 清理并返回 `{dismissed:false}`，不再写延期。

**C. 弹窗结构与文案**

| 项目 | 1.25.7 实测值 |
|---|---|
| 停用态按钮 | `['暂不处理', '去启用 S3']`（次按钮文案由「稍后处理」改名） |
| 复选框 | `hasCheckbox=true`（核对引导弹窗此前 `actions_markup( ..., false )` 不渲染，现已补上） |
| 关闭锚点数量 | `[data-config-close]` 3 处：X、遮罩、次按钮 |
| kicker | `OBJECT STORAGE / PAUSED` |
| 连接配置页 | 无 paused 弹窗；未配置态只出 setup 弹窗，两处互斥不变 |

**D. 静态检查（1.25.7）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **455 tests / 1,370 assertions / 5 skipped**，OK（`ConfigNoticePausedTest` 的 snooze 两例改写为「关掉不静默」与「旧版残留被清理」，断言数 26） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1 --report=summary` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| PHP 语法 | `php -l` 全部改动文件 | No syntax errors |
| JS 语法 | `node --check`（`storage-sync.js`、`storage-connection.js`） | 通过 |

> 本轮不新增类、方法、钩子、REST 路由与数据表：图谱仍 **192 节点 / 873 连线**（648 `ref` + 50 `modref` + 175 `contains`），模块 17 个、`modref` 50 条、钩子 209 条全部不变。生产 PHP 由 65,789 行增至 65,792 行（190 文件不变），`src/S3` 12,144 → 12,147；`assets/` 34,750 → 34,759（`assets/ui/` 15,260 → 15,269，两个 JS 各 +4/−2 与 +6/−2）；tests 仍 78 文件、10,636 → 10,639 行。

### 10.26 同步中心与存储配置一一对应的运行期验证（1.25.8，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.8 工作树（11 个改动文件用 `cp` 同步并核 md5：`Settings_Saver.php` / `S3_Storage_Page.php` / `Sync_Center_Module.php` / `Index_Repository.php` / `CLI.php` / `Card.php` / `class-s3-storage-module.php` / `s3-sync-center.php` / `storage-sync.js` / `storage.css` / `SettingsSaverTest.php`）。测量用真实浏览器 1280×720 与 1403×900，配置状态在测试前后都用 `wp option get s3_options` 存档并按 key 逐项比对（`diffs=[]` 才算还原）。

**A. 三种存储状态的抬头与冻结**

| 状态 | `data-frozen` | 右上角状态卡 | 冻结提示条 | 弹窗 |
|---|---|---|---|---|
| 停用（`is_configured=true, is_enabled=false`） | `1` | 「当前存储桶 / text-demo」+「缤纷云 · 已停用」 | 有：`notice-inline--warn`「对象存储已停用：核对、上传、下载与删除都暂时停下…」 | `#sakura-s3-paused-modal` |
| 启用 | `0` | 「当前存储桶 / text-demo」+「缤纷云 · 连接正常」 | 无 | 无（已有核对记录） |
| 未配置（`errors=['bucket']`） | `0` | 「当前存储桶 / 未配置」+「缤纷云 · 配置不完整」 | 无 | `#sakura-s3-config-modal` |

未配置态**不冻结**是有意的：`$frozen = $is_configured && ! $storage_enabled` 只把「配置完整但开关关闭」当作暂停；从未配置的站点走未配置弹窗，把它的按钮也禁掉只会让人无处可去。

**B. 冻结态的可用性边界**

| 分组 | 控件 | 停用态 |
|---|---|---|
| 写操作 | 开始完整核对 / 刷新差异 / 停止核对 / 继续核对 / 重试失败 / 测试连接 / 停用同步记录 / 忽略规则「保存规则」 | 全部 `disabled` |
| 写操作（行内） | 上传 / 重新上传 / 下载 / 删除远端 | 全部 `disabled`（筛选后 AJAX 重画的 26 行里 25 个行内按钮同样禁用） |
| 写操作（批量） | 批量上传 / 批量下载 / 批量删除远端 | 全部 `disabled`（勾选行后批量栏可见，三个按钮仍禁用） |
| 只读 | 六个筛选 chip / 搜索 / 类型 / 时间 / 每页条数 / 详情预览 / 日志级别 / 导出 CSV / 清除选择 | 全部可用 |
| 出口 | 「连接设置」链接（页面头部与健康卡各一处） | 可用，`href=...page=sakura-tools-storage` |

`清除选择` 被特意排除在冻结之外（`setBatchButtonsDisabled()` 里强制 `batchClear.disabled = false`）：它是纯本地状态，禁掉只会让人卡在一个选不掉的勾选里。

**C. 前端守卫生效的证据**

把 `[data-sakura-action="scan"]` 按钮的 `disabled` 手改回 `false` 再 `dispatchEvent` 点击：`event.defaultPrevented = true`、Network 面板没有 `admin-ajax.php` 请求、`#sakura-sync-state` 的 `data-scan-id` 与 `data-scan-state` 保持不变（`6` / `done`）。这条兜底针对的是「服务端渲染的 disabled 在 AJAX 重画后丢失」以及「弹窗里的按钮不经由服务端渲染」两种路径。

**D. 配置一一对应（改桶名 = 新配置）**

| 操作 | `saved_profiles()` | 同步中心表现 |
|---|---|---|
| 初始（`text-demo`，1 条配置） | 1（`prof_dc340e753a3f`） | 402 本地 / 6 云端 / 6 已同步 / 396 待上传 / 环图 ready |
| 把 bucket 改成 `text-demo-renamed` 并保存 | 2（旧 `prof_dc340e753a3f` 保留且 `active=0`，新 `prof_9e328701db05` `active=1`） | `storage-mix--unscanned`「还没有核对记录」，抬头「text-demo-renamed」 |
| 再改成 `text-demo-renamed2` | 3 | URL 带 `created=1`，提示「已保存为新的存储配置：切换了服务商、存储桶或服务地址，原配置与它的核对记录保持不变。」 |
| 不改目标直接保存 | 不变 | 提示仍是「云存储设置已保存。」 |

保存会重写 `key` / `secret` 的密文与 `saved_at`（`Credential_Cipher::decrypt()` 校验明文相同），这是既有的加密刷新行为，不是配置被篡改。

**E. 删除配置连带清数据**

临时插入 `prof_e2etest00002`（bucket `text-demo-two`）并在五张表各写一行测试数据，切到它后同步中心显示 1 本地 / 7 云端 / 100% 覆盖（证明数据确实按 profile 隔离）。经 UI 删除（必须先过 `data-sakura-confirm` 的原生 `<dialog>`，确认按钮 `.btn--danger`）后：

| 表 | 删除前该 profile 行数 | 删除后 |
|---|---:|---:|
| `sio_sync_index` | 1 | 0 |
| `sio_sync_counts` | 1 | 0 |
| `sio_sync_jobs` | 1 | 0 |
| `sio_sync_scans` | 1 | 0 |
| `sio_sync_events` | 1 | 0 |

删除确认文案与 CLI `sync purge` 的提示都已写明这五类数据会被一起清空（1.25.7 及更早只清前三张）。

**F. 静态检查（1.25.8）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **460 tests / 1,377 assertions / 5 skipped**，OK（新增 `tests/SettingsSaverTest.php` 5 例，覆盖「同目标沿用 id / 换桶新建 / 换服务商或服务地址新建 / 另存为新配置 / 未知 id 新建」） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1 --report=summary` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| PHP 语法 | `php -l` 全部改动文件 | No syntax errors |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过 |

> 本轮不新增类、钩子、REST 路由与数据表：图谱仍 **192 节点 / 873 连线**（648 `ref` + 50 `modref` + 175 `contains`），模块 17 个、`modref` 50 条、钩子 209 条全部不变。生产 PHP 由 65,792 行增至 65,847 行（190 文件不变），`src/S3` 12,147 → 12,183、`src/Admin` 3,292 → 3,297、`includes`（根）6,192 → 6,198、`src/Sync` 6,048 → 6,057、`includes/views` 5,542 → 5,541；`assets/` 34,759 → 34,805（`assets/ui/` 15,269 → 15,315）；tests 仍 78 文件、10,639 → 10,730 行。

### 10.27 提示弹窗排版的运行期验证（1.25.9，2026-11-13）

验证环境同 §10.19：测试站 `/root/sio-e2e`（`https://demo.77il.cn`，PHP 8.4.27，WordPress 7.1.4），插件目录软链到 1.25.9 工作树。本轮只有 1 个 CSS 文件与 1 个文档改动，`assets/ui/pages/storage.css` 用 `cp` 同步进 worktree 后两侧 md5 相同（`721efc66ce0e3e49c5e38c39432a3973`）。

**A. 三种弹窗的居中核对（1403×900）**

三张弹窗共用同一套 `.sakura-modal__*` 规则，逐一触发后量取各元素中心点：

| 弹窗 | 触发状态 | 卡片尺寸 | 卡片中心 | 图标中心 | 眉标中心 | 标题中心 | 正文字号对齐 | 按钮 |
|---|---|---:|---:|---:|---:|---:|---|---|
| 停用 | `is_enabled=false` + 配置完整 | 560×291 | 702 | 702 | 702 | 702 | `start` / 512×69 | `['暂不处理', '去启用 S3']`，`space-between` |
| 需要核对 | 配置完整 + 启用 + 无 scan 行 | 560×268 | 702 | 702 | 702 | 702 | `start` / 512×44 | `['暂不处理', '开始核对']` |
| 未配置 | `bucket` 置空 | 560×307 | 702 | 702 | 702 | 702 | `start` | `['稍后处理', '去补全配置']` + chips「还需要补全 / 存储桶名称」 |

三个弹窗的图标、眉标、标题中心与卡片中心**完全重合**（均为 702px，等于 1403 视口的正中），说明 `margin: 0 auto` + `text-align: center` 生效；正文 `text-align` 计算值为 `start`、宽 512px（等于卡片内容宽），说明 `max-width: none` 生效且没有再退回 368px。

**B. 行宽上限的对照（为什么要改 46ch）**

| 版本 | 正文宽 | 折行 | 卡片高 | 结果 |
|---|---:|---:|---:|---|
| `max-width: 46ch`（1.25.8） | 368px | 3 行 | 291px | 右侧空 144px，标题居中后更显失衡 |
| `max-width: none`（1.25.9） | 512px | 3 行 | 291px | 正文铺满、右侧无空档，卡片高度不变 |

「全部居中」的备选版也量过：卡片从 291px 涨到 314px（正文居中后折行位置变化），因此没有采用；最终定稿是「头部居中、正文与按钮保持原样」。

**C. 窄屏与底部操作区**

| 视口 | 卡片宽 | 卡片中心 | 图标中心 | 正文宽 | 按钮 | 底部方向 | 横向溢出 |
|---|---:|---:|---:|---:|---|---|---|
| 1403×900 | 560 | 702 | 702 | 512 | 各 88 / 88 | row（`space-between`） | 无（`scrollWidth` = `innerWidth` = 1403） |
| 520×800 | 484 | 260 | 260 | 436 | — | `column` | 无（520 = 520） |
| 390×844 | 354 | 195 | 195 | 318 | 各 318 | `column` | 无（390 = 390） |
| 320×720 | 284 | 160 | 160 | 248 | 各 248 | `column` | 无（320 = 320） |

**D. 回归**

| 检查 | 结果 |
|---|---|
| 连接配置页六张卡 | `profiles / behaviour / connection / upload-metrics / test / monitoring` 全在 `[data-sakura-layout="s3"]` 内，组 class 仍为 `card-grid sakura-layout-grid is-free is-laid` |
| 连接配置页弹窗 | 无 paused、无 config（该页配置完整且开关关闭，弹窗只在同步中心挂载） |
| 横向滚动 | 四档视口 `document.documentElement.scrollWidth` 均等于 `window.innerWidth` |

**E. 静态检查（1.25.9）**

| 检查 | 命令 | 结果 |
|---|---|---|
| PHPUnit | `vendor/bin/phpunit` | **460 tests / 1,377 assertions / 5 skipped**，OK（本轮未新增测试） |
| PHPStan | `vendor/bin/phpstan analyse --no-progress` | `[OK] No errors`（level 5） |
| PHPCS | `vendor/bin/phpcs --runtime-set ignore_warnings_on_exit 1 --report=summary` | **0 errors / 36 warnings / 21 files**（与 1.24.4 基线相同） |
| JS 语法 | `node --check assets/ui/js/pages/storage-sync.js` | 通过 |

> 本轮（1.25.9）不改任何 PHP：生产 PHP 仍 **190 文件 / 65,847 行**，类 175、节点 192、连线 873、钩子 209、模块 17、`modref` 50 全部不变。变化的只有后台资源行数：34,805 → **34,815 行**（`assets/ui/` 15,315 → 15,325，差异来自 `storage.css` 的 10 行净增与注释）。当时的约定写回 `docs/后台UI规则.md:160`，该文件现为 `docs/开发规则/03-后台UI.md`（1.26.0 起重写）。


## 11. 依赖关系与图谱口径

### 11.1 模块依赖矩阵（类级引用边计数）

| 从 / 到 | UserFiles | S3 | includes | Sync | includes/views | Thumbnail | Admin | Image | src | Oss | Backup | 根文件 | Contracts | Settings | Support | Filesystem | Stats |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| UserFiles |  |  |  |  |  |  | 18 |  |  |  |  |  |  |  | 14 |  |  |
| S3 |  |  | 8 | 39 |  |  | 34 |  |  |  |  |  | 6 | 6 | 9 |  |  |
| includes |  | 43 |  | 7 |  | 10 | 22 | 22 |  | 1 | 7 |  | 17 | 9 | 6 | 3 | 5 |
| Sync |  | 38 |  |  |  |  |  |  |  |  |  |  | 5 |  | 5 |  |  |
| includes/views |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Thumbnail |  | 1 |  |  |  |  |  |  |  | 2 |  |  |  |  |  |  |  |
| Admin | 9 |  |  |  |  |  |  |  |  |  |  |  | 2 |  | 2 |  |  |
| Image |  |  | 9 |  |  |  |  |  |  |  |  |  | 22 | 9 |  | 2 |  |
| src | 9 | 25 | 10 | 24 |  | 1 | 6 |  |  |  | 1 |  |  | 2 | 3 | 1 | 1 |
| Oss |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Backup |  |  | 4 |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |
| 根文件 |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Contracts |  |  |  |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |
| Settings |  |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |
| Support |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Filesystem |  |  | 2 |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |
| Stats |  |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |

**三个可直接用于判断改动风险的读法**：

1. **`includes/` 是"最外向"的一层**：152 条出边散向 12 个模块，其中 43 条指向 `src/S3`、22 条指向 `src/Admin`、22 条指向 `src/Image`、17 条指向 `src/Contracts`。改 `src/S3` 的公开方法签名时，**必须**同步检查 `includes/` 的调用点。
2. **`src/UserFiles` 高度自包含**：只有 18 条边指向 `src/Admin`、14 条指向 `src/Support`，没有指向 S3/Sync/Thumbnail 的边——它与其它子系统的接缝全在运行时（postmeta、钩子、REST），不在静态类型上。这也解释了它为什么能独立演进。
3. **`src/S3 → src/Sync` 39 条边是全图最粗的一条**：同步中心复用 `Index_Repository` 做队列与分页；而 `src/Sync` 反向依赖 `src/S3` 38 条，主要是 `Remote_Operation_Guard`。**这两个模块事实上是一个整体**，改动时按一个单元评估。

### 11.2 图谱节点的定义

| 节点类型 | 数量 | id 规则 | 大小 | 颜色 |
|---|---:|---|---|---|
| 模块 | 17 | `mod:<模块路径>` | `max(4, 文件数×0.8)` | 固定色板 17 色 |
| 类/接口 | 176 | `cls:<FQCN>` | `sqrt(公开方法数)×1.6`（至少 1.2） | 跟随所属模块 |

| 边类型 | 权重字段 | 含义 |
|---|---|---|
| `contains` | 固定 1 | 模块 → 类（归属） |
| `ref` | 引用次数 | 类 → 类（静态引用） |
| `modref` | 跨模块边数 | 模块 → 模块（矩阵的连线形态） |

其余口径：

- **入度/出度**：只统计 **token 级静态引用**（`use` / `new` / 静态调用 / 类型声明），不是运行时调用次数；"钩子"列单独统计 `add_action` / `add_filter`。
- **布局是确定性的**：模块中心按斐波那契球面分布在半径 1450 的球面，模块内类节点以 `spread = 70 + min(190, 文件数 × 4.2)` 散布，整体 `radius = 1710`。同一份扫描数据每次生成的坐标完全一致（无随机种子），便于 diff。
- **不进入图谱的对象**：生产代码只有 1 个全局函数（`fast-search.php:27` 的 `sakura_fast_search_fail()`）；扫描器统计的 64 个 function 节点含 tests/stubs，不能当成生产全局函数数。另有 12 个视图模板、`fast-search.php` / `uninstall.php` 等脚本型文件，它们只在附录 C（文件索引）与正文中出现。
- **悬空边检查**：生成器校验 `links` 两端 id 都存在，当前 **0 条悬空边**（早期一版因用了扫描原始 id 产生过 762 条悬空边，已修复）。

### 11.3 全图最粗的 10 条类级边

| from | to | 类型 | 次数 | 证据行 |
|---|---|---|---:|---|
| `Scan_Service` | `Index_Repository` | 调用 | 45 | src/Sync/Scan_Service.php:137,151 |
| `S3_Storage_Page` | `UI` | 静态调用 | 25 | src/S3/S3_Storage_Page.php:50,85 |
| `S3_Storage_Page` | `UI` | 调用 | 25 | src/S3/S3_Storage_Page.php:50,85 |
| `Storage` | `Options` | 调用 | 25 | src/S3/Storage.php:108,110 |
| `Sync_Center_Module` | `Index_Repository` | 调用 | 25 | src/S3/Sync_Center_Module.php:494,497 |
| `Controller` | `Requests` | 调用 | 25 | src/UserFiles/Rest/Controller.php:1230,1268 |
| `Executor` | `Time` | 静态调用 | 25 | src/UserFiles/Services/Executor.php:174,242 |
| `Executor` | `Time` | 调用 | 25 | src/UserFiles/Services/Executor.php:174,242 |
| `Media_Hooks` | `Requests` | 静态调用 | 24 | src/UserFiles/Media/Media_Hooks.php:290,291 |
| `Requests` | `Schema` | 静态调用 | 24 | src/UserFiles/Repositories/Requests.php:86,123 |

---

## 12. 待确认项与已确认缺陷

截至 2026-11-09（1.24.3），正文没有「只有结论、没有代码位置或运行证据」的待确认项，也没有未修复的高/中优先代码缺陷；1.24.2 补测发现的卸载漏删 `{prefix}sufm_file_tokens` 已在 1.24.3 修复（§12.7）。1.24.0 关闭了三件事：支付方式改为后台勾选、积分入口有独立开关；每日对账改为租约 + 指数退避；历史 `order:` 授权提供手动审计/回填命令。1.24.1 完成文件面板快捷多选与列表复选框护栏，1.24.2 补充 S3/Sync 运维观测（health、守卫分页/计数、四个只读 Prometheus gauge），并补测了三条验证空白（§12.6）；这些批次没有新增其它待确认项，也没有改变下面已接受的边界。`refund_failed` 仍坚持人工处理，这是有意保留的资金安全边界，不是遗漏。下面保留的是有意接受的边界。

### 12.1 1.24.0 关闭与核对

| 问题 | 修复前 | 修复后 | 验证 |
|---|---|---|---|
| 订单发放把余额/现金单写成积分单，订单字段清零 | `grant_from_order()` 的 INSERT 固定写 `'points','points',0,'',0,0`；`source='points'` 会把现金购买计入 `points_used_bytes()` 日限额 | `normalise_order()` 解析 `points/pay_detail`；新增 `grant_ledger_fields()`；INSERT 参数化 `source/channel/order_id/order_num/price/points`（`src/UserFiles/Services/Space_Store.php:846`、`:881`、`:948-966`） | 余额单写 `purchase/balance` 且订单字段齐全；积分单写 `points/points` 且积分保留；`SpaceStorePaymentTest` 覆盖两类渠道与缺失积分回退 |
| 空间面板暴露主题的全部支付方式，等于可生成外部扫码单 | `payment_methods()` 原样返回主题方法 | 默认值在 `Space_Settings::defaults()` 的 `allowed_methods=['balance']`；后台「支付方式」卡片勾选；`filter_payment_methods()` 以该设置为唯一来源，过滤器只能收窄；`purchase()` 用过滤后的 map 硬校验（`Space_Store.php:194`、`:249`、`:311`；卡片 `includes/views/userfiles-space-admin.php:233-309`） | 测试站只勾 `balance` 时现金通道只有余额；全不勾选时现金收银台关闭；过滤器无法重新启用未勾选通道 |
| 积分没有独立开关 | `points_state()` 的 `enabled` 只等于 `per_gb > 0`，后台 `points[enabled]` 无读取点 | `Space_Settings::points_enabled()`（`src/UserFiles/Space_Settings.php:280-284`）成为独立开关；`points_state()`（`Space_Store.php:3050`）同时要求开关打开且单价 > 0；`redeem_points()`（`:3221`）关闭时返回 `sufm_points_off` | 四态实测：现金+积分、仅积分、仅现金、都不可用；关闭积分只隐藏入口并拒绝新兑换，已售出的授权不受影响 |
| 退款只把 grant 置 `refunded`，不退余额/积分 | `on_order_refunded()` 只 UPDATE 状态，不区分 `pay_type` | 先原子占位 `refund_pending`，再释放配额，按渠道 `settle_refund()`；余额加 `order_price`、积分加 `points`，外部渠道只释放；失败重试 5 次（`Space_Store.php:1268`、`:1407`、`:1522`、`:1672`） | 测试站余额 100→130、积分 520→610；重复回调与崩溃窗口不重复加钱；`user_missing` 重试后 `refunded/attempts=2`；第 5 次置 `refund_failed` |
| paid-no-grant 只能手工对账，失败要等 24 小时 | `maybe_reconcile()` 先写单个时间戳，失败也把水位推到 24 小时后 | 状态改为 `{last_attempt,last_success,next_attempt,failures,last_alert}`；先写 15 分钟租约，再执行 `reconcile(true,100)`；失败按 900/1800/3600/7200/14400/21600 秒退避；成功才写每日水位（`Space_Store.php:1838`、`:1932`、`:2173`） | 成功 +86400；失败 +900、第二次 +1800；租约期间重复 tick 不改状态；告警每天最多一条（§10.14-A） |
| 历史 `order:` 授权字段为 0，退款按 `order_num` 找不到 | 修复只影响新写入；旧行没有回填入口 | 新增 `wp sufm grants`（`src/UserFiles/CLI.php:174`），默认 dry-run，`--apply` 才写；只处理 `order:` 且 `order_id=0` 或 `order_num=''` 的行；校验订单存在/唯一/已支付/同一用户/空间商品（`Space_Store.php:2326`、`:2414`） | 合成行 dry-run 为 `pending`，`--apply` 后写回 `purchase/balance/order_id=21/order_num=…/price=30.00/points=0`；`bytes/state/label` 不变；第二次 `candidates=0`（§10.14-B） |
| 现金或积分任一不可用时面板空白或无法提交 | `build_data()` 在 `plans` 或 `methods` 为空时直接返回 `null`；无现金 plan 时积分 radio 不默认选中 | `cash_ready` / `points_ready` 分别判断（`src/UserFiles/Space_Panel.php:137`、`:141`）；模板按标志隐藏/显示；无现金时默认积分；`confirmPurchase()` 在积分模式直接返回 | 测试站四态：现金+积分 8251 字节、仅积分 6408、仅现金 6195、都不可用 `null`；临时 `sufm_space_settings` 已恢复 |

代码位置与完整运行记录见 §8.9、§10.12、§10.14；决策理由见 `DECISIONS.md` 的「空间扩容支付收口与对账（2026-11-08，2026-11-09 补齐）」。

### 12.2 原 §12 三项核实结果

| 原 # | 事项 | 结论 | 关键证据 | 正文 |
|---:|---|---|---|---|
| 1 | `fast-search.php` 是否另有 nginx/.htaccess 限制 | **无缺陷**：生产环境没有额外封禁；入口自身按 HMAC + 管理员能力鉴权 | 生产无 token/错签名 403，有效管理员签名 200（438 字节，`total=132`）；nginx 配置无 `fast-search` 专属 `location` | §10.10-A |
| 2 | `grant_from_order()` 的 `source` 与订单字段 | **确认缺陷，已修复**：原 INSERT 固定 `points` 并清零订单字段；现按渠道参数化写入 | 测试站余额单 `source=purchase/channel=balance/order_id≠0/price=30`；积分单 `source=points/channel=points/points=90`；`SpaceStorePaymentTest` 覆盖两类渠道 | §8.9、§12.1 |
| 3 | S3 上传失败是否进入指标 | **确认缺陷，已修复**：原只记录成功样本；现追加事件表，成功/失败双钩子采集 | 生产快照 `attempts=91`、`ok=91`、`fail=0`（修复前）；测试站 4×200 并发写入 800/800 行，快照 `attempts=830`、`fail=800` | §6.9、§10.11 |

### 12.3 保留边界（不是未修复缺陷）

| 边界 | 影响 | 处理 |
|---|---|---|
| 预充值余额仍由主题的支付宝/微信充值产生 | 站点仍是收款方，退款、发票、消费者权益与资金合规责任仍在 | 本方案只收口「空间订单的直接外部支付入口」，不是零风险；运营侧需有充值退款与对账规则 |
| 外部渠道退款不代付 | 若管理员勾选 `alipay`/`wechat`，插件只释放授权，钱包不加钱 | 外部退款必须在主题/支付平台完成；文档与代码注释已标注 |
| 主题 `balance_record`/`points_record` 最多 50 条且无唯一键 | `wallet_refund_recorded()` 的崩溃窗口查重可能因记录被挤出而失效 | 这是弱幂等，不作为强一致保证；需要强幂等时应新增独立退款账本表 |
| `refund_failed` 不会自动复活 | 满 5 次失败后需要人工核对订单/用户并手工处理；主题订单已 `-2`，不会重发 `order_refunded` | 保留 `refund_failed` 状态与事件日志；不自动重试、不提供后台一键重开；处理时先查主题钱包流水，命中 `space_expand_refund` 只能修正状态，不能再次加钱 |
| 历史授权回填必须人工触发 | 旧 `order:` 行不会自动补齐；`points:` 直兑行没有主题订单，不能按订单回填 | 先 `wp sufm grants` dry-run，核对后再 `--apply`；只写订单号/渠道/金额/积分，不改容量、有效期、状态、标签、`grant_key` 或 `meta` |
| 对账退避封顶 6 小时 | 连续失败时，下一次自动对账最晚约 6 小时后；它不会立即重试 | 需要立即重试时走手动 `POST /admin/space/reconcile`；`space.reconcile.alert` 只是告警，不自动修复订单 |
| 对账告警每个失败序列每天最多一条 | 持续失败时不会每次 tick 都刷日志 | 看 `sufm_space_reconcile_last` 的 `failures/next_attempt/last_alert`；必要时人工介入 |
| 旧版标量水位会迁移为「旧值 + 24 小时」 | 升级后不会立刻重放一次对账 | 这是防止升级瞬间重复对账的保守选择；要立即对账用手动 REST 或把 `next_attempt` 置 0（仅测试/运维） |
| 上传事件表不自动裁剪 | 事件行会随上传量长期增长；`totals` 是自安装以来的全量，直接 `DELETE` 会让 Prometheus counter 回退 | 当前不自动裁剪；需要控制体积时另建归档表 + `archived_through_id` + 只读 prune CLI，不先挂 cron（§6.9.5） |
| 守卫的 `uncertain` 行没有 TTL/自动回收 | 进程崩溃或网络结果不明时会留下占位，旧请求是否停止只能由人判断 | 用 `wp sakura-s3 remote-operations count/list --limit/--offset` 核对，`release` 必须带 `--remote-settled`；不按 Age/HEAD 自动释放（§7.7.1） |
| `Sync\Metrics` 是 option 读改写 | 并发下可能丢计数，不能当精确告警源 | `health` 明确标注来源；精确告警用 `sio_sync_jobs` 表 COUNT 与 `sync_jobs_dead` gauge，`sync_actions_failed` 只作趋势 |

### 12.4 此前关闭的上传指标缺陷

| 原问题 | 修复前 | 修复后 | 验证 |
|---|---|---|---|
| 上传失败不进入指标，失败率与告警恒 0 | `Upload_Observer` 只挂 `sakura_imgopt_s3_uploaded`，样本固定 `ok=true`；`Object_Store` 失败分支不发事件 | 每条结果独立 INSERT 到 `sio_s3_upload_events`；`Storage_Adapter::report_failure()` 发 `sakura_imgopt_s3_upload_failed`，`Upload_Observer::record_failed()` 采集 | `phpunit` 393 tests / 1138 assertions / 5 skipped；4×200 并发 800/800；CLI、Prometheus、后台卡片均正常（§10.11） |
| 并发写指标互相覆盖 | 读改写 option，4×200 只落 202 行 | 原子 INSERT + `snapshot()` SQL 聚合；旧 option 保留为冻结基线 | 测试站 800/800；旧实现对照 202/800 |

### 12.5 图谱口径说明

《图谱同步说明》§10.3 记录的「181 vs 179 文件数」是旧导出排除规则未留档造成的**图谱口径差异**，与当前插件代码行为无关；当前图谱数字以 §13 的最新扫描为准。它保留在图谱文档的已知局限中，不计入本节。

### 12.6 三条验证空白已关闭（2026-11-09）

| 原空白 | 补测方式 | 结果 |
|---|---|---|
| 生产写路径只有静态阅读：`Quota::commit`、`Requests::approve` → `Executor::run`、`Uninstaller` | 测试站执行；生产仍只读 | Quota 完成预留/部分提交/重复拒绝/超配额回滚/释放；删除审核→预览→确认→tick 全链路完成，附件删除、配额回冲、request/batch/job 收尾；卸载两轨（显式删数据、默认保留）均执行；显式删除轨道发现的 `file_tokens` 残留已在 1.24.3 修复 |
| `Scan_Service.php:376-383` 的 cursor 损坏/目录被删分支未构造 | 临时扫描根 4 文件 26 B，三种损坏游标 | 损坏 JSON、顶层目录被删、嵌套目录被删都自动重置为空游标并从根重扫，结果 4 文件 26 B；重置后再次失败的分支仍只有静态阅读 |
| 编辑页只覆盖 TinyMCE iframe；多选顺序不等于视觉顺序 | `/newposts` 真实附件 + 临时 textarea mu-plugin + 块编辑器 | TinyMCE 正常；纯 textarea 挂载面板但只 toast、无投放层；块编辑器不加载面板（不可达）；插入是「图片优先，组内保留勾选顺序」 |

详细命令、数据与清理记录见 §10.17；编辑器 DOM 证据见 §8.10.7。三条空白不再是“只有静态阅读”，但生产环境的写路径仍未执行。

### 12.7 已修复缺陷：卸载漏删 `file_tokens`（1.24.3）

| 项 | 内容 |
|---|---|
| 现象（修复前） | `sakura_imgopt_delete_data_on_uninstall=1` 时，`Uninstaller::drop_sufm_tables()` 只删除 14 张 `{prefix}sufm_*`，`{prefix}sufm_file_tokens` 保留 |
| 证据（修复前） | `src/Uninstaller.php:146-153` 的硬编码清单没有 `file_tokens`；`src/UserFiles/Schema.php:41-60` 的 `tables()` 有 15 张，其中 `file_tokens` 在 `:46`、建表语句在 `:256-261`。测试站轨道 A 执行前 15 张表、`t_sufm_file_tokens` 3,117 行；执行后只剩这一张表，仍 3,117 行，`t_sio_*` 为 0，其余 14 张已删 |
| 影响 | 修复前，用户显式选择“卸载时删除数据”后，文件名 bigram 搜索 token 仍留在数据库；它不含附件正文，但属于未按预期删除的派生数据，可能泄露文件名片段。附件本体是否删除由 WordPress 媒体库流程决定，与本表无关 |
| 根因 | 删表清单与 `Schema::tables()` 各自维护，新增 `file_tokens` 时只更新了 Schema，没有同步 Uninstaller |
| 修复 | `drop_sufm_tables()` 改为遍历 `Nboke\UserFiles\Schema::tables()`（`src/Uninstaller.php:150-154`），不再维护第二份清单；新增 `tests/UninstallerTest.php:55-95`，用 fake `$wpdb` 断言 DROP 数量等于 `Schema::tables()` 并显式包含 `t_sufm_file_tokens`；代码提交 `20be449`（2 files +48/-1） |
| 验证 | 全量 `427 tests / 1,296 assertions / 5 skipped`、PHPStan 无错误、PHPCS 通过；`UninstallerTest` 5 tests / 23 assertions；隔离站 `/tmp/sio-uninstall-site` + 库 `sio_uninstall_probe` 复跑轨道 A：15 张 `t_sufm_*`（3,117 token）→ 0 张，`t_sio_*` 也 0，随后从 dump 恢复样本库（§10.17-C） |
| 当前状态 | **已修复并提交 `20be449`**，并入 1.24.3 发布；生产仍未执行卸载，因此没有已发生的生产残留。以后再新增 `sufm_*` 表时只需更新 `Schema::tables()`，卸载会跟随 |

---

## 13. 3D 图谱同步说明

图谱文件：`docs/graph-preview/index.html`（单文件，数据内嵌）+ `docs/graph-preview/graph.json`（同数据美化版）。不保存渲染截图。

| 项 | 本次结果 |
|---|---|
| 节点 | 193 = 176 类 + 17 模块 |
| 连线 | **881** = 655 `ref` + 50 `modref` + 176 `contains` |
| 钩子 | 207（面板显示，不建节点） |
| 悬空边 | 0 |
| 上一轮变更（1.25.0） | 1.25.0 的后台 UI 重构**净增 10 个类节点**：新增 11 个（`Admin\Appearance`、`Admin\Card_Form`、`Admin\Page_Assets`、`Admin\Route`、`Admin\UI`、`Admin\UI\Card`、`Admin\UI\Feedback`、`Admin\UI\Field`、`Admin\UI\Page`、`Admin\System_Settings_Module`、`S3\Config_Notice`），删除 1 个（`Admin\Basic_Settings_Module`，其视图 `includes/views/basic-settings.php` 同时移除，能力并入新的系统设置页）；`ref` 612→637、`contains` 164→174、节点 181→191、连线 826→861；`modref` 仍 50（模块集合未变）。钩子 199→203（新增 `admin_post`/`ajax` 处理器与 `admin_print_footer_scripts`） |
| 上一轮变更（1.25.1） | 只新增 1 个 `admin_enqueue_scripts` 闭包（`sakura-tools.php:121-137`，卸载 `wp-commands` / `wp-core-commands` 与对应样式）：钩子 203→204、`add_action` 147→148；节点仍 191、连线仍 861（637 `ref` + 50 `modref` + 174 `contains`），`modref` 仍 50 |
| 上一轮变更（1.25.2） | 只改 `assets/ui/pages/storage.css`（`align-content: start`）与 `assets/ui/js/pages/storage-sync.js`（`notifyLayout()` 三处调用），**不改 PHP**：节点 191、连线 861（637 `ref` + 50 `modref` + 174 `contains`）、钩子 204、模块 17 全部不变；`assets/ui/` 由 14,932 行增至 14,958 行 |
| 上一轮变更（1.25.3） | 只改 5 个 PHP 文件（`Layout.php`、`Page_Assets.php`、`S3_Storage_Page.php`、`Delete_Queue_Panel.php`、`Config_Notice.php`）与 `assets/ui/js/layout.js`，**类、钩子、模块集合都不变**：节点 191、连线 861（637 `ref` + 50 `modref` + 174 `contains`）、钩子 204、模块 17 全部不变；生产 PHP 由 65,137 行增至 65,165 行，`assets/ui/` 由 14,958 行减至 14,957 行（`layout.js` 净减 1 行） |
| 上一轮变更（1.25.4） | 只改 `includes/views/s3-sync-center.php` 与 `assets/ui/pages/storage.css`，外加 `src/S3/Sync_Center_Module.php` 的一条日志文案，**类、方法、钩子、模块集合都不变**：节点 191、连线 861（637 `ref` + 50 `modref` + 174 `contains`）、钩子 204、模块 17 全部不变；生产 PHP 由 65,165 行增至 65,233 行（`includes/views` 5,391 → 5,459、`src/S3` 11,934 行不变），`assets/ui/` 由 14,957 行增至 14,994 行 |
| 上一轮变更（1.25.5） | 新增 1 个类 `Sync\Sync_Calibrator`（每月自动校准），重写 `S3\Config_Notice`（新增 `should_prompt_reconcile()` / `reconcile_html()` 与三点共用的 `render_modal()`），因此节点 191→192、连线 861→873（`ref` 637→648、`contains` 174→175）、**模块仍 17、`modref` 仍 50**。钩子 204→209（`cron_schedules` 与 `init`/`admin_init` 各 1 条 + `sakura_imgopt_sync_calibrate` + `sakura_imgopt_sync_calibrate_now`），`add_action` 148→152、`add_filter` 56→57。生产 PHP 由 65,233 行增至 65,667 行（190 文件），`src/Sync` 5,792 → 6,048、`src/S3` 11,934 → 12,078、`includes/views` 5,459 → 5,486；`assets/ui/` 由 14,994 行增至 15,260 行 |
| 上一轮变更（1.25.6） | 只改 `includes/views/s3-sync-center.php`、`src/S3/Config_Notice.php`（新增 `should_prompt_paused()` / `paused_html()`，两者是**方法**不是类，不产生节点）+ `src/S3/Sync_Center_Module.php` + 前端 `assets/ui/js/pages/storage-sync.js` + 测试，**类、方法节点、钩子、模块集合都不变**：节点 192、连线 873（648 `ref` + 50 `modref` + 175 `contains`）、钩子 209、模块 17 全部不变；生产 PHP 由 65,667 行增至 65,789 行（190 文件不变），`src/S3` 12,078 → 12,144、`includes/views` 5,486 → 5,542；`assets/` 与 `assets/ui/` 行数不变（storage-sync.js +2/−2）。tests 77 → 78 文件 / 10,320 → 10,636 行 |
| 上一轮变更（1.25.7） | 只改 `src/S3/Config_Notice.php`（`is_quiet_window_over()` → `is_prompt_allowed()`、删 `SNOOZE_SECONDS`）、`src/S3/Sync_Center_Module.php`（`ajax_dismiss_config_notice()` 收窄）、`assets/ui/js/pages/storage-sync.js` 与 `storage-connection.js`（未勾选就不发请求）与测试，**类、方法节点、钩子、模块集合仍全部不变**：节点 192、连线 873（648 `ref` + 50 `modref` + 175 `contains`）、钩子 209、模块 17 不变；生产 PHP 65,789 → 65,792 行（190 文件），`src/S3` 12,144 → 12,147；`assets/` 34,750 → 34,759 行、`assets/ui/` 15,260 → 15,269；tests 仍 78 文件、10,636 → 10,639 行 |
| 上一轮变更（1.25.8） | 只改 9 个 PHP 文件（`Settings_Saver.php` 新增静态方法 `profile_id_for_save()`、`S3_Storage_Page.php` 的两处文案、`Sync_Center_Module.php` 的一条守卫、`Index_Repository.php` 的 `purge_index()` 加两张表、`CLI.php` 的三处文案、`Admin/UI/Card.php` 的 `save_disabled` 参数、`class-s3-storage-module.php` 的清理调用、`s3-sync-center.php` 的 `$frozen` 与抬头）与两个前端文件 + 测试，**全是方法级改动，不新增类、钩子、模块或数据表**：节点 192、连线 873（648 `ref` + 50 `modref` + 175 `contains`）、钩子 209、模块 17 全部不变；生产 PHP 65,792 → 65,847 行（190 文件不变），`src/S3` 12,147 → 12,183、`src/Admin` 3,292 → 3,297、`includes`（根）6,192 → 6,198、`src/Sync` 6,048 → 6,057、`includes/views` 5,542 → 5,541；`assets/` 34,759 → 34,805、`assets/ui/` 15,269 → 15,315；tests 仍 78 文件、10,639 → 10,730 行 |
| 本轮变更（1.25.9） | 只改 `assets/ui/pages/storage.css`（弹窗图标、眉标、标题居中，正文去掉 `max-width: 46ch`）与 `docs/后台UI规则.md`（§5.1 补一条对齐约定），**不改任何 PHP**：生产 PHP 仍 190 文件 / 65,847 行，类 175、节点 192、连线 873、钩子 209、模块 17、`modref` 50 全部不变；后台资源 34,805 → **34,815 行**（`assets/ui/` 15,315 → 15,325）。详见 §10.27 |
| 本轮变更（1.26.0–1.27.5） | 后台 UI 三次结构性调整 + 一次卸载修复，**类节点净增 1**：新增 `Admin\UI\Modal`（1.26.0 统一提示弹窗），删除拖拽布局引擎相关的无类代码（`layout.js`/`layout.css` 本来就不在图谱里）。节点 192→**193**、连线 873→**881**（`ref` 648→655、`contains` 175→176）、**模块仍 17、`modref` 仍 50**。钩子 209→**207**（`src/Admin` 7→5，`Sync_Calibrator` 与停用清单的两条 cron 归属调整）；生产 PHP 191 文件 / 65,780 行。详见 §1 的「本轮更新」段 |
| 数据来源 | 2026-11-14 重新扫描的 1.27.5 工作树事实表（codegraph `scanner.php` 输出）——由 `docs/tools/collect-comments.php` 与 `docs/tools/collect-hooks.php` 生成，与本文所有表格同源 |

1.24.3 的卸载修复与 1.24.4 的覆盖升级自愈都没有改变模块划分；1.25.0 的后台 UI 重构在原有两个后台模块（`system`/`image`）之外**没有新增模块节点**——它把 13 个页签的呈现收敛到一套 UI 内核，因此模块仍 17 个、`modref` 仍 50 条，增量全在类级；1.25.5 新增的每月校准同样只是一个类，模块集合不变。节点 193（176 类 + 17 模块）、连线 881（655 `ref` + 50 `modref` + 176 `contains`）、钩子 207。生产 PHP 文件 191 个 / 65,780 行，其中 `src` 5 文件 / 901 行、`src/UserFiles` 22,305 行、`includes`（根）6,198 行、`src/S3` 12,183 行、`src/Sync` 18 文件 6,057 行、`src/Admin` 3,297 行、`includes/views` 5,541 行。编辑页 JS/CSS 不是图谱对象，`assets/ui/` 的 15,325 行不进入图谱。1.25.9 只改该目录下的 `pages/storage.css`，图谱口径里的所有计数一律不变。

`fast-search.php` 是脚本型文件，本来就不进入图谱；`Space_Store` 的字段缺陷已在 §8.9 修复，不再是未修复缺陷。

**2026-11-09 上一轮验证回写（1.24.2，无代码变化）**：那一轮只修改 §8.10.7、§10.17、§12.6–§12.7 与《图谱同步说明》，没有改插件代码、类、方法、钩子或数据表；图谱当时仍是 180 节点 / 822 连线（609 `ref` + 50 `modref` + 163 `contains`）/ 191 钩子 / 0 悬空边。

**生成与注入脚本**（都在 `docs/tools/`，可重复执行）：`build-code-structure.mjs`（事实表 → 表格 + graph.json）、`graph-inject.mjs`（graph.json → index.html 内嵌 DATA，含悬空边校验）、`assemble-doc.py`（分章 + 表格 → 本文）。完整命令见《图谱同步说明》§7。

**与文档的对应关系**：每个类节点在正文里都有一条同名记录——附录 A 给 `文件:行`，所属章节的表给职责与方法数；模块节点对应 §2.2 的模块总览。**改代码后重跑一次生成器，表格与图谱同时更新**，这是两者不脱节的机制保证。

详细的操作步骤、本次新增/修改的节点与连线清单、一致性核对项，见配套文档 **`docs/history/sakura-tools-图谱同步-1.25.9.md`**（已归档，生成方法仍适用；其中的数字属 1.25.9）。

---

## 附录 A：类索引（176 类）

| # | 类 | 文件:行 | 方法数 |
|---:|---|---|---:|
| 1 | `Sakura\ImageOptimizer\Animation_Detector` | includes/class-animation-detector.php:20 | 5 |
| 2 | `Sakura\ImageOptimizer\Backup` | includes/class-backup.php:27 | 14 |
| 3 | `Sakura\ImageOptimizer\File_System` | includes/class-file-system.php:14 | 11 |
| 4 | `Sakura\ImageOptimizer\GD_Handler` | includes/class-gd-handler.php:16 | 5 |
| 5 | `Sakura\ImageOptimizer\Image_Optimizer_Module` | includes/class-image-optimizer-module.php:25 | 45 |
| 6 | `Sakura\ImageOptimizer\Image_Processor` | includes/class-image-processor.php:39 | 17 |
| 7 | `Sakura\ImageOptimizer\Imagick_Handler` | includes/class-imagick-handler.php:16 | 5 |
| 8 | `Sakura\ImageOptimizer\Media_Library_Module` | includes/class-media-library-module.php:30 | 28 |
| 9 | `Sakura\ImageOptimizer\S3_CLI` | includes/class-s3-cli.php:14 | 14 |
| 10 | `Sakura\ImageOptimizer\S3_Doge_Cloud` | includes/class-s3-doge-cloud.php:16 | 7 |
| 11 | `Sakura\ImageOptimizer\S3_Storage_Module` | includes/class-s3-storage-module.php:25 | 20 |
| 12 | `Sakura\ImageOptimizer\Stats` | includes/class-stats.php:15 | 9 |
| 13 | `Sakura\ImageOptimizer\Thumbnail_Manager` | includes/class-thumbnail-manager.php:20 | 6 |
| 14 | `Sakura\ImageOptimizer\Thumbnail_Optimizer_Module` | includes/class-thumbnail-optimizer-module.php:32 | 18 |
| 15 | `Sakura\ImageOptimizer\Admin\Appearance` | src/Admin/Appearance.php:15 | 5 |
| 16 | `Sakura\ImageOptimizer\Admin\Assets` | src/Admin/Assets.php:17 | 1 |
| 17 | `Sakura\ImageOptimizer\Admin\UI\Card` | src/Admin/UI/Card.php:23 | 5 |
| 18 | `Sakura\ImageOptimizer\Admin\Card_Form` | src/Admin/Card_Form.php:23 | 5 |
| 19 | `Sakura\ImageOptimizer\Admin\Environment_Report` | src/Admin/Environment_Report.php:21 | 11 |
| 20 | `Sakura\ImageOptimizer\Admin\UI\Feedback` | src/Admin/UI/Feedback.php:17 | 6 |
| 21 | `Sakura\ImageOptimizer\Admin\UI\Field` | src/Admin/UI/Field.php:21 | 11 |
| 22 | `Sakura\ImageOptimizer\Admin\Layout` | src/Admin/Layout.php:30 | 5 |
| 23 | `Sakura\ImageOptimizer\Admin\Menu` | src/Admin/Menu.php:23 | 6 |
| 24 | `Sakura\ImageOptimizer\Admin\UI\Modal` | src/Admin/UI/Modal.php:27 | 3 |
| 25 | `Sakura\ImageOptimizer\Admin\UI\Page` | src/Admin/UI/Page.php:20 | 5 |
| 26 | `Sakura\ImageOptimizer\Admin\Page_Assets` | src/Admin/Page_Assets.php:17 | 4 |
| 27 | `Sakura\ImageOptimizer\Admin\Registry` | src/Admin/Registry.php:21 | 13 |
| 28 | `Sakura\ImageOptimizer\Admin\Route` | src/Admin/Route.php:21 | 3 |
| 29 | `Sakura\ImageOptimizer\Admin\Shell` | src/Admin/Shell.php:19 | 2 |
| 30 | `Sakura\ImageOptimizer\Admin\System_Settings_Module` | src/Admin/System_Settings_Module.php:24 | 4 |
| 31 | `Sakura\ImageOptimizer\Admin\UI` | src/Admin/UI.php:27 | 9 |
| 32 | `Sakura\ImageOptimizer\Backup\Backup_Repository` | src/Backup/Backup_Repository.php:24 | 9 |
| 33 | `Sakura\ImageOptimizer\Backup\Exposure_Probe` | src/Backup/Exposure_Probe.php:23 | 10 |
| 34 | `Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface` | src/Contracts/Backup_Repository_Interface.php:20 | 9 |
| 35 | `Sakura\ImageOptimizer\Contracts\File_System_Interface` | src/Contracts/File_System_Interface.php:17 | 7 |
| 36 | `Sakura\ImageOptimizer\Contracts\Module` | src/Contracts/Module.php:23 | 1 |
| 37 | `Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface` | src/Contracts/Settings_Repository_Interface.php:17 | 18 |
| 38 | `Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface` | src/Contracts/Stats_Repository_Interface.php:17 | 5 |
| 39 | `Sakura\ImageOptimizer\Filesystem\File_System_Repository` | src/Filesystem/File_System_Repository.php:20 | 7 |
| 40 | `Sakura\ImageOptimizer\Image\Crop_Config_Scanner` | src/Image/Crop_Config_Scanner.php:34 | 25 |
| 41 | `Sakura\ImageOptimizer\Image\Engine_Manager` | src/Image/Engine_Manager.php:23 | 1 |
| 42 | `Sakura\ImageOptimizer\Image\File_Processor` | src/Image/File_Processor.php:21 | 11 |
| 43 | `Sakura\ImageOptimizer\Image\Metadata_Processor` | src/Image/Metadata_Processor.php:25 | 9 |
| 44 | `Sakura\ImageOptimizer\Image\No_Gain_Guard` | src/Image/No_Gain_Guard.php:20 | 2 |
| 45 | `Sakura\ImageOptimizer\Image\Restore_Service` | src/Image/Restore_Service.php:27 | 6 |
| 46 | `Sakura\ImageOptimizer\Image\Size_Catalog` | src/Image/Size_Catalog.php:22 | 5 |
| 47 | `Sakura\ImageOptimizer\Image\Upload_Backup` | src/Image/Upload_Backup.php:22 | 4 |
| 48 | `Sakura\ImageOptimizer\Image\Upload_Handler` | src/Image/Upload_Handler.php:23 | 7 |
| 49 | `Sakura\ImageOptimizer\Image\Upload_Renamer` | src/Image/Upload_Renamer.php:21 | 7 |
| 50 | `Sakura\ImageOptimizer\Oss\Html_Filter` | src/Oss/Html_Filter.php:18 | 7 |
| 51 | `Sakura\ImageOptimizer\Oss\Url_Rewriter` | src/Oss/Url_Rewriter.php:17 | 8 |
| 52 | `Sakura\ImageOptimizer\S3\Alerting` | src/S3/Alerting.php:24 | 17 |
| 53 | `Sakura\ImageOptimizer\S3\Client_Factory` | src/S3/Client_Factory.php:20 | 2 |
| 54 | `Sakura\ImageOptimizer\S3\Config_Notice` | src/S3/Config_Notice.php:35 | 10 |
| 55 | `Sakura\ImageOptimizer\S3\Connection_Tester` | src/S3/Connection_Tester.php:11 | 3 |
| 56 | `Sakura\ImageOptimizer\S3\Credential_Cipher` | src/S3/Credential_Cipher.php:21 | 7 |
| 57 | `Sakura\ImageOptimizer\S3\Delete_Jobs` | src/S3/Delete_Jobs.php:22 | 22 |
| 58 | `Sakura\ImageOptimizer\S3\Delete_Queue_Panel` | src/S3/Delete_Queue_Panel.php:23 | 12 |
| 59 | `Sakura\ImageOptimizer\S3\Delete_Worker` | src/S3/Delete_Worker.php:20 | 15 |
| 60 | `Sakura\ImageOptimizer\S3\Error_Mapper` | src/S3/Error_Mapper.php:15 | 3 |
| 61 | `Sakura\ImageOptimizer\S3\History_Migrator` | src/S3/History_Migrator.php:19 | 15 |
| 62 | `Sakura\ImageOptimizer\S3\Ignored_Paths` | src/S3/Ignored_Paths.php:20 | 4 |
| 63 | `Sakura\ImageOptimizer\S3\Inventory` | src/S3/Inventory.php:22 | 1 |
| 64 | `Sakura\ImageOptimizer\S3\Legacy_Snapshot` | src/S3/Legacy_Snapshot.php:24 | 5 |
| 65 | `Sakura\ImageOptimizer\S3\Log` | src/S3/Log.php:17 | 1 |
| 66 | `Sakura\ImageOptimizer\S3\Migration_Service` | src/S3/Migration_Service.php:17 | 2 |
| 67 | `Sakura\ImageOptimizer\S3\Monitoring_Panel` | src/S3/Monitoring_Panel.php:21 | 8 |
| 68 | `Sakura\ImageOptimizer\S3\Object_Store` | src/S3/Object_Store.php:19 | 11 |
| 69 | `Sakura\ImageOptimizer\S3\Options` | src/S3/Options.php:19 | 25 |
| 70 | `Sakura\ImageOptimizer\S3\Profile_Manager` | src/S3/Profile_Manager.php:23 | 3 |
| 71 | `Sakura\ImageOptimizer\S3\Prometheus` | src/S3/Prometheus.php:25 | 12 |
| 72 | `Sakura\ImageOptimizer\S3\Providers` | src/S3/Providers.php:9 | 2 |
| 73 | `Sakura\ImageOptimizer\S3\Remote_Operation_Guard` | src/S3/Remote_Operation_Guard.php:14 | 11 |
| 74 | `Sakura\ImageOptimizer\S3\Restore_Plan` | src/S3/Restore_Plan.php:27 | 3 |
| 75 | `Sakura\ImageOptimizer\S3\S3_Storage_Page` | src/S3/S3_Storage_Page.php:12 | 4 |
| 76 | `Sakura\ImageOptimizer\S3\Settings_Saver` | src/S3/Settings_Saver.php:12 | 8 |
| 77 | `Sakura\ImageOptimizer\S3\Storage` | src/S3/Storage.php:22 | 24 |
| 78 | `Sakura\ImageOptimizer\S3\Storage_Adapter` | src/S3/Storage_Adapter.php:20 | 18 |
| 79 | `Sakura\ImageOptimizer\S3\Sync_Actions` | src/S3/Sync_Actions.php:20 | 5 |
| 80 | `Sakura\ImageOptimizer\S3\Sync_Center_Module` | src/S3/Sync_Center_Module.php:29 | 38 |
| 81 | `Sakura\ImageOptimizer\S3\Sync_Center_Panel` | src/S3/Sync_Center_Panel.php:20 | 1 |
| 82 | `Sakura\ImageOptimizer\S3\Sync_Status` | src/S3/Sync_Status.php:23 | 23 |
| 83 | `Sakura\ImageOptimizer\S3\Synchronous_Uploader` | src/S3/Synchronous_Uploader.php:26 | 13 |
| 84 | `Sakura\ImageOptimizer\S3\Upload_Capabilities` | src/S3/Upload_Capabilities.php:23 | 3 |
| 85 | `Sakura\ImageOptimizer\S3\Upload_Metrics` | src/S3/Upload_Metrics.php:27 | 18 |
| 86 | `Sakura\ImageOptimizer\S3\Upload_Observer` | src/S3/Upload_Observer.php:20 | 4 |
| 87 | `Sakura\ImageOptimizer\S3\Upload_URL` | src/S3/Upload_URL.php:9 | 2 |
| 88 | `Sakura\ImageOptimizer\S3\Upload_Verify` | src/S3/Upload_Verify.php:20 | 3 |
| 89 | `Sakura\ImageOptimizer\Settings\Settings_Repository` | src/Settings/Settings_Repository.php:23 | 22 |
| 90 | `Sakura\ImageOptimizer\Stats\Stats_Repository` | src/Stats/Stats_Repository.php:20 | 5 |
| 91 | `Sakura\ImageOptimizer\Support\Hook_Loader` | src/Support/Hook_Loader.php:20 | 5 |
| 92 | `Sakura\ImageOptimizer\Support\Text` | src/Support/Text.php:21 | 1 |
| 93 | `Sakura\ImageOptimizer\Sync\Action_Worker` | src/Sync/Action_Worker.php:28 | 7 |
| 94 | `Sakura\ImageOptimizer\Sync\Attachment_Adopter` | src/Sync/Attachment_Adopter.php:26 | 9 |
| 95 | `Sakura\ImageOptimizer\Sync\Attachment_Indexer` | src/Sync/Attachment_Indexer.php:28 | 9 |
| 96 | `Sakura\ImageOptimizer\Sync\Checksums` | src/Sync/Checksums.php:17 | 3 |
| 97 | `Sakura\ImageOptimizer\Sync\CLI` | src/Sync/CLI.php:25 | 9 |
| 98 | `Sakura\ImageOptimizer\Sync\Fast_Search_Query` | src/Sync/Fast_Search_Query.php:24 | 2 |
| 99 | `Sakura\ImageOptimizer\Sync\Feature` | src/Sync/Feature.php:20 | 2 |
| 100 | `Sakura\ImageOptimizer\Sync\Index_Repository` | src/Sync/Index_Repository.php:24 | 69 |
| 101 | `Sakura\ImageOptimizer\Sync\Keyset_Cursor` | src/Sync/Keyset_Cursor.php:21 | 6 |
| 102 | `Sakura\ImageOptimizer\Sync\Kind` | src/Sync/Kind.php:20 | 2 |
| 103 | `Sakura\ImageOptimizer\Sync\Metrics` | src/Sync/Metrics.php:20 | 3 |
| 104 | `Sakura\ImageOptimizer\Sync\Scan_Service` | src/Sync/Scan_Service.php:30 | 25 |
| 105 | `Sakura\ImageOptimizer\Sync\Scan_Worker` | src/Sync/Scan_Worker.php:27 | 10 |
| 106 | `Sakura\ImageOptimizer\Sync\Schema` | src/Sync/Schema.php:21 | 5 |
| 107 | `Sakura\ImageOptimizer\Sync\Search_Token` | src/Sync/Search_Token.php:22 | 4 |
| 108 | `Sakura\ImageOptimizer\Sync\Sync_Calibrator` | src/Sync/Sync_Calibrator.php:32 | 9 |
| 109 | `Sakura\ImageOptimizer\Sync\Verify_Service` | src/Sync/Verify_Service.php:24 | 7 |
| 110 | `Sakura\ImageOptimizer\Sync\Verify_Worker` | src/Sync/Verify_Worker.php:25 | 6 |
| 111 | `Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful` | src/Thumbnail/Providers/Bitiful.php:24 | 7 |
| 112 | `Sakura\ImageOptimizer\Thumbnail\CLI` | src/Thumbnail/CLI.php:17 | 5 |
| 113 | `Sakura\ImageOptimizer\Thumbnail\Context` | src/Thumbnail/Context.php:18 | 7 |
| 114 | `Sakura\ImageOptimizer\Thumbnail\Decorator` | src/Thumbnail/Decorator.php:23 | 18 |
| 115 | `Sakura\ImageOptimizer\Thumbnail\Providers\Generic` | src/Thumbnail/Providers/Generic.php:24 | 5 |
| 116 | `Sakura\ImageOptimizer\Thumbnail\Policy` | src/Thumbnail/Policy.php:18 | 5 |
| 117 | `Sakura\ImageOptimizer\Thumbnail\Probe` | src/Thumbnail/Probe.php:24 | 11 |
| 118 | `Sakura\ImageOptimizer\Thumbnail\Provider` | src/Thumbnail/Provider.php:21 | 4 |
| 119 | `Sakura\ImageOptimizer\Thumbnail\Provider_Status` | src/Thumbnail/Provider_Status.php:17 | 5 |
| 120 | `Sakura\ImageOptimizer\Thumbnail\Rendition` | src/Thumbnail/Rendition.php:21 | 8 |
| 121 | `Sakura\ImageOptimizer\Thumbnail\Settings` | src/Thumbnail/Settings.php:22 | 31 |
| 122 | `Sakura\ImageOptimizer\Thumbnail\Size_Catalog` | src/Thumbnail/Size_Catalog.php:17 | 3 |
| 123 | `Sakura\ImageOptimizer\Thumbnail\Stats` | src/Thumbnail/Stats.php:17 | 5 |
| 124 | `Sakura\ImageOptimizer\Thumbnail\Theme_Bridge` | src/Thumbnail/Theme_Bridge.php:21 | 2 |
| 125 | `Sakura\ImageOptimizer\Thumbnail\Theme_Cache` | src/Thumbnail/Theme_Cache.php:23 | 5 |
| 126 | `Nboke\UserFiles\Services\Adoption_Manifest` | src/UserFiles/Services/Adoption_Manifest.php:7 | 2 |
| 127 | `Nboke\UserFiles\Editor\Assets` | src/UserFiles/Editor/Assets.php:20 | 1 |
| 128 | `Nboke\UserFiles\Support\Assets` | src/UserFiles/Support/Assets.php:21 | 1 |
| 129 | `Nboke\UserFiles\Capabilities` | src/UserFiles/Capabilities.php:20 | 8 |
| 130 | `Nboke\UserFiles\CLI` | src/UserFiles/CLI.php:23 | 8 |
| 131 | `Nboke\UserFiles\Editor\Context` | src/UserFiles/Editor/Context.php:20 | 3 |
| 132 | `Nboke\UserFiles\Rest\Controller` | src/UserFiles/Rest/Controller.php:34 | 71 |
| 133 | `Nboke\UserFiles\Support\Db_Transaction` | src/UserFiles/Support/Db_Transaction.php:22 | 5 |
| 134 | `Nboke\UserFiles\Frontend\Entry` | src/UserFiles/Frontend/Entry.php:24 | 7 |
| 135 | `Nboke\UserFiles\Repositories\Events` | src/UserFiles/Repositories/Events.php:22 | 2 |
| 136 | `Nboke\UserFiles\Services\Executor` | src/UserFiles/Services/Executor.php:30 | 21 |
| 137 | `Nboke\UserFiles\Repositories\Files` | src/UserFiles/Repositories/Files.php:24 | 20 |
| 138 | `Nboke\UserFiles\Repositories\Folders` | src/UserFiles/Repositories/Folders.php:28 | 38 |
| 139 | `Nboke\UserFiles\Frontend\Providers\Generic` | src/UserFiles/Frontend/Providers/Generic.php:24 | 12 |
| 140 | `Nboke\UserFiles\Media\Library_Filter` | src/UserFiles/Media/Library_Filter.php:15 | 1 |
| 141 | `Nboke\UserFiles\Media\Media_Hooks` | src/UserFiles/Media/Media_Hooks.php:37 | 52 |
| 142 | `Nboke\UserFiles\Services\Media_Move_Failure` | src/UserFiles/Services/Media_Move_Failure.php:21 | 1 |
| 143 | `Nboke\UserFiles\Services\Media_Mover` | src/UserFiles/Services/Media_Mover.php:14 | 15 |
| 144 | `Nboke\UserFiles\Services\Media_Notifications` | src/UserFiles/Services/Media_Notifications.php:8 | 1 |
| 145 | `Nboke\UserFiles\Editor\Module` | src/UserFiles/Editor/Module.php:21 | 3 |
| 146 | `Nboke\UserFiles\Module` | src/UserFiles/Module.php:33 | 11 |
| 147 | `Nboke\UserFiles\Repositories\Notifications` | src/UserFiles/Repositories/Notifications.php:23 | 7 |
| 148 | `Nboke\UserFiles\Services\Notifier` | src/UserFiles/Services/Notifier.php:20 | 7 |
| 149 | `Nboke\UserFiles\Admin\Page` | src/UserFiles/Admin/Page.php:28 | 9 |
| 150 | `Nboke\UserFiles\Editor\Picker` | src/UserFiles/Editor/Picker.php:32 | 1 |
| 151 | `Nboke\UserFiles\Rest\Picker_Controller` | src/UserFiles/Rest/Picker_Controller.php:30 | 8 |
| 152 | `Nboke\UserFiles\Frontend\Provider` | src/UserFiles/Frontend/Provider.php:24 | 6 |
| 153 | `Nboke\UserFiles\Services\Quota` | src/UserFiles/Services/Quota.php:28 | 8 |
| 154 | `Nboke\UserFiles\Services\Rate_Limiter` | src/UserFiles/Services/Rate_Limiter.php:23 | 4 |
| 155 | `Nboke\UserFiles\Repositories\Requests` | src/UserFiles/Repositories/Requests.php:28 | 28 |
| 156 | `Nboke\UserFiles\Schema` | src/UserFiles/Schema.php:24 | 7 |
| 157 | `Nboke\UserFiles\Settings` | src/UserFiles/Settings.php:21 | 16 |
| 158 | `Nboke\UserFiles\Admin\Space_Page` | src/UserFiles/Admin/Space_Page.php:28 | 7 |
| 159 | `Nboke\UserFiles\Space_Panel` | src/UserFiles/Space_Panel.php:23 | 11 |
| 160 | `Nboke\UserFiles\Services\Space_Rules` | src/UserFiles/Services/Space_Rules.php:24 | 13 |
| 161 | `Nboke\UserFiles\Space_Settings` | src/UserFiles/Space_Settings.php:20 | 33 |
| 162 | `Nboke\UserFiles\Services\Space_Store` | src/UserFiles/Services/Space_Store.php:22 | 64 |
| 163 | `Nboke\UserFiles\Services\Sync_Service` | src/UserFiles/Services/Sync_Service.php:26 | 9 |
| 164 | `Nboke\UserFiles\Frontend\Tab` | src/UserFiles/Frontend/Tab.php:22 | 16 |
| 165 | `Nboke\UserFiles\Support\Theme_Levels` | src/UserFiles/Support/Theme_Levels.php:22 | 4 |
| 166 | `Nboke\UserFiles\Services\Theme_Limits` | src/UserFiles/Services/Theme_Limits.php:22 | 2 |
| 167 | `Nboke\UserFiles\Support\Time` | src/UserFiles/Support/Time.php:18 | 4 |
| 168 | `Nboke\UserFiles\Services\Uploader` | src/UserFiles/Services/Uploader.php:27 | 9 |
| 169 | `Nboke\UserFiles\Frontend\Providers\Zibll` | src/UserFiles/Frontend/Providers/Zibll.php:21 | 7 |
| 170 | `Nboke\UserFiles\Services\Zibll_Bridge` | src/UserFiles/Services/Zibll_Bridge.php:23 | 16 |
| 171 | `Nboke\UserFiles\Space\Zibll_Order_Card` | src/UserFiles/Space/Zibll_Order_Card.php:26 | 12 |
| 172 | `Sakura\ImageOptimizer\Activation` | src/Activation.php:17 | 1 |
| 173 | `Sakura\ImageOptimizer\Deactivation` | src/Deactivation.php:17 | 1 |
| 174 | `Sakura\ImageOptimizer\Plugin` | src/Plugin.php:43 | 3 |
| 175 | `Sakura\ImageOptimizer\Uninstaller` | src/Uninstaller.php:26 | 10 |
| 176 | `Sakura\ImageOptimizer\Upgrade_Guard` | src/Upgrade_Guard.php:23 | 3 |

## 附录 B：钩子索引（207 条，按模块分组）

### includes（67 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `admin_enqueue_scripts` | action | enqueue_progress_assets | includes/class-media-library-module.php:132 |
| `admin_enqueue_scripts` | action | enqueue_admin_assets | includes/class-thumbnail-optimizer-module.php:57 |
| `admin_enqueue_scripts` | action | enqueue_admin_assets | includes/class-image-optimizer-module.php:73 |
| `admin_enqueue_scripts` | action | enqueue_assets | includes/class-s3-storage-module.php:76 |
| `admin_init` | action | register_settings | includes/class-thumbnail-optimizer-module.php:56 |
| `admin_init` | action | register_settings | includes/class-image-optimizer-module.php:72 |
| `admin_menu` | action | add_progress_page | includes/class-media-library-module.php:131 |
| `admin_notices` | action | render_restore_blocked_notice | includes/class-media-library-module.php:127 |
| `admin_notices` | action | render_bulk_blocked_notice | includes/class-media-library-module.php:128 |
| `admin_notices` | action | render_conflict_notice | includes/class-thumbnail-optimizer-module.php:60 |
| `admin_post_sakura_imgopt_reset_stats` | action | handle_reset_stats | includes/class-image-optimizer-module.php:74 |
| `admin_post_sakura_s3_alerts_save` | action | save_alerts | includes/class-s3-storage-module.php:71 |
| `admin_post_sakura_s3_alerts_test` | action | test_alert | includes/class-s3-storage-module.php:72 |
| `admin_post_sakura_s3_delete` | action | delete_profile | includes/class-s3-storage-module.php:69 |
| `admin_post_sakura_s3_prometheus_rotate` | action | rotate_prometheus | includes/class-s3-storage-module.php:73 |
| `admin_post_sakura_s3_save` | action | save | includes/class-s3-storage-module.php:67 |
| `admin_post_sakura_s3_switch` | action | switch_profile | includes/class-s3-storage-module.php:68 |
| `admin_post_sakura_s3_test` | action | test_connection | includes/class-s3-storage-module.php:70 |
| `admin_post_sakura_thumb_probe` | action | handle_probe | includes/class-thumbnail-optimizer-module.php:58 |
| `admin_title` | filter | filter_progress_admin_title | includes/class-media-library-module.php:133 |
| `ajax_query_attachments_args` | filter | apply_grid_format_filter | includes/class-media-library-module.php:122 |
| `attachment_fields_to_edit` | filter | add_restore_field | includes/class-media-library-module.php:140 |
| `big_image_size_threshold` | filter | __return_false | includes/class-thumbnail-manager.php:46 |
| `bulk_actions-upload` | filter | register_bulk_action | includes/class-media-library-module.php:125 |
| `delete_attachment` | action | on_delete_attachment | includes/class-image-processor.php:158 |
| `dynamic_sidebar_after` | action | pop | includes/class-thumbnail-optimizer-module.php:67 |
| `dynamic_sidebar_before` | action | push_sidebar | includes/class-thumbnail-optimizer-module.php:66 |
| `handle_bulk_actions-upload` | filter | handle_bulk_action | includes/class-media-library-module.php:126 |
| `init` | action | apply_size_filters | includes/class-thumbnail-manager.php:28 |
| `intermediate_image_sizes` | filter | filter_sizes | includes/class-thumbnail-manager.php:42 |
| `loop_end` | action | pop | includes/class-thumbnail-optimizer-module.php:65 |
| `loop_start` | action | handle_loop_start | includes/class-thumbnail-optimizer-module.php:63 |
| `manage_media_columns` | filter | add_s3_sync_column | includes/class-media-library-module.php:153 |
| `manage_media_custom_column` | action | render_s3_sync_column | includes/class-media-library-module.php:154 |
| `option_zibll_options` | filter | filter_options | includes/class-thumbnail-optimizer-module.php:72 |
| `pre_get_posts` | action | apply_format_filter | includes/class-media-library-module.php:121 |
| `restrict_manage_posts` | action | add_format_dropdown | includes/class-media-library-module.php:120 |
| `sakura_imgopt_spare_thumb` | filter | filter_spare_thumb | includes/class-thumbnail-optimizer-module.php:71 |
| `shutdown` | action | array( __CLASS__, 'flush_pending' ) | includes/class-stats.php:156 |
| `template_redirect` | action | start_scoped_buffer | includes/class-thumbnail-optimizer-module.php:52 |
| `the_post` | action | guard | includes/class-thumbnail-optimizer-module.php:64 |
| `update_option_s3_options` | action | on_s3_options_changed | includes/class-thumbnail-optimizer-module.php:59 |
| `update_option_sakura_imgopt_oss_thumb` | action | after_option_update | includes/class-thumbnail-optimizer-module.php:73 |
| `wp_ajax_ajax_widget_ui` | action | start_sidebar_ajax_buffer | includes/class-thumbnail-optimizer-module.php:53 |
| `wp_ajax_nopriv_ajax_widget_ui` | action | start_sidebar_ajax_buffer | includes/class-thumbnail-optimizer-module.php:54 |
| `wp_ajax_sakura_clean_expired_backup` | action | ajax_clean_expired_backup | includes/class-media-library-module.php:148 |
| `wp_ajax_sakura_get_progress` | action | ajax_get_progress | includes/class-media-library-module.php:137 |
| `wp_ajax_sakura_imgopt_scan_crop` | action | handle_scan_crop_ajax | includes/class-image-optimizer-module.php:75 |
| `wp_ajax_sakura_imgopt_stats_page` | action | ajax_stats_page | includes/class-image-optimizer-module.php:76 |
| `wp_ajax_sakura_probe_backup` | action | ajax_probe_backup | includes/class-media-library-module.php:150 |
| `wp_ajax_sakura_process_batch` | action | ajax_process_batch | includes/class-media-library-module.php:136 |
| `wp_ajax_sakura_purge_backup` | action | ajax_purge_backup | includes/class-media-library-module.php:144 |
| `wp_ajax_sakura_refresh_backup` | action | ajax_refresh_backup | includes/class-media-library-module.php:146 |
| `wp_ajax_sakura_restore_single` | action | ajax_restore_single | includes/class-media-library-module.php:141 |
| `wp_ajax_sakura_s3_retry` | action | retry_failed | includes/class-s3-storage-module.php:74 |
| `wp_ajax_sakura_s3_upload_metrics` | action | upload_metrics | includes/class-s3-storage-module.php:75 |
| `wp_content_img_tag` | filter | filter_content_image | includes/class-thumbnail-optimizer-module.php:70 |
| `wp_generate_attachment_metadata` | filter | process_metadata | includes/class-image-processor.php:156 |
| `wp_generate_attachment_metadata` | filter | strip_disabled_sizes | includes/class-thumbnail-manager.php:50 |
| `wp_get_attachment_image_src` | filter | filter | includes/class-thumbnail-optimizer-module.php:69 |
| `wp_handle_sideload_prefilter` | filter | pre_upload_rename | includes/class-image-processor.php:140 |
| `wp_handle_sideload` | filter | handle_upload | includes/class-image-processor.php:150 |
| `wp_handle_upload_prefilter` | filter | pre_upload_rename | includes/class-image-processor.php:139 |
| `wp_handle_upload` | filter | handle_upload | includes/class-image-processor.php:149 |
| `wp_image_make_intermediate_size` | filter | block_intermediate | includes/class-thumbnail-manager.php:58 |
| `wp_insert_attachment_data` | filter | sync_attachment_title | includes/class-image-processor.php:142 |
| `wp` | action | reset | includes/class-thumbnail-optimizer-module.php:62 |

### src（1 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `sakura_imgopt_daily_backup_cleanup` | action | clean_expired | src/Plugin.php:113 |

### src/Admin（5 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `admin_enqueue_scripts` | action | enqueue_admin_assets | src/Admin/System_Settings_Module.php:38 |
| `admin_init` | action | register_settings | src/Admin/System_Settings_Module.php:37 |
| `admin_menu` | action | register_menu | src/Admin/Menu.php:32 |
| `admin_page_access_denied` | action | redirect_legacy | src/Admin/Menu.php:37 |
| `init` | action | redirect_legacy | src/Admin/Menu.php:36 |

### src/S3（47 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | queue_attachment | src/S3/Storage.php:138 |
| `admin_enqueue_scripts` | action | enqueue_assets | src/S3/Sync_Center_Module.php:110 |
| `admin_enqueue_scripts` | action | enqueue | src/S3/Delete_Queue_Panel.php:43 |
| `admin_init` | action | maybe_migrate | src/S3/Storage.php:146 |
| `admin_init` | action | resume_pending_restores | src/S3/Storage.php:152 |
| `admin_init` | action | array( __CLASS__, 'schedule' ) | src/S3/Alerting.php:377 |
| `admin_init` | action | ensure_scheduled | src/S3/Delete_Worker.php:49 |
| `admin_notices` | action | render_incomplete_config_notice | src/S3/Storage.php:119 |
| `admin_notices` | action | admin_notices | src/S3/Sync_Center_Module.php:121 |
| `admin_notices` | action | render_failed_notice | src/S3/Storage.php:145 |
| `admin_post_nopriv_sakura_imgopt_metrics` | action | array( __CLASS__, 'output' ) | src/S3/Prometheus.php:77 |
| `admin_post_sakura_imgopt_metrics` | action | array( __CLASS__, 'output' ) | src/S3/Prometheus.php:76 |
| `admin_post_sakura_s3_delete_queue` | action | handle_action | src/S3/Delete_Queue_Panel.php:42 |
| `admin_post_sakura_s3_ignored_save` | action | save_ignored_paths | src/S3/Sync_Center_Module.php:123 |
| `admin_post_sakura_s3_media_download` | action | download_media | src/S3/Sync_Center_Module.php:124 |
| `admin_post_sakura_s3_sync_export` | action | export_csv | src/S3/Sync_Center_Module.php:122 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/S3/Alerting.php:365 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/S3/Alerting.php:374 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/S3/Delete_Worker.php:47 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/S3/Delete_Worker.php:69 |
| `delete_attachment` | action | delete_remote_attachment | src/S3/Storage.php:144 |
| `init` | action | array( __CLASS__, 'schedule' ) | src/S3/Alerting.php:376 |
| `init` | action | ensure_scheduled | src/S3/Delete_Worker.php:48 |
| `init` | action | array( __CLASS__, 'maybe_serve' ) | src/S3/Prometheus.php:75 |
| `posts_where` | filter | $where_filter = static function ( $where ) use ( $cursor ) {
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor ); | src/S3/History_Migrator.php:263 |
| `sakura_imgopt_restore_started` | action | on_restore_started | src/S3/Storage.php:150 |
| `sakura_imgopt_restored` | action | reconcile_attachment | src/S3/Storage.php:151 |
| `sakura_imgopt_s3_alert_check` | action | array( __CLASS__, 'check' ) | src/S3/Alerting.php:375 |
| `sakura_imgopt_s3_delete_tick` | action | run | src/S3/Delete_Worker.php:50 |
| `sakura_imgopt_s3_upload_failed` | action | record_failed | src/S3/Upload_Observer.php:31 |
| `sakura_imgopt_s3_uploaded` | action | record_uploaded | src/S3/Upload_Observer.php:30 |
| `wp_ajax_sakura_s3_config_notice` | action | ajax_dismiss_config_notice | src/S3/Sync_Center_Module.php:120 |
| `wp_ajax_sakura_s3_sync_action` | action | ajax_action | src/S3/Sync_Center_Module.php:117 |
| `wp_ajax_sakura_s3_sync_cancel` | action | ajax_cancel | src/S3/Sync_Center_Module.php:113 |
| `wp_ajax_sakura_s3_sync_page` | action | ajax_page | src/S3/Sync_Center_Module.php:115 |
| `wp_ajax_sakura_s3_sync_refresh` | action | ajax_refresh | src/S3/Sync_Center_Module.php:118 |
| `wp_ajax_sakura_s3_sync_resume` | action | ajax_resume | src/S3/Sync_Center_Module.php:114 |
| `wp_ajax_sakura_s3_sync_scan_step` | action | ajax_scan_step | src/S3/Sync_Center_Module.php:112 |
| `wp_ajax_sakura_s3_sync_scan` | action | ajax_scan | src/S3/Sync_Center_Module.php:111 |
| `wp_ajax_sakura_s3_sync_test` | action | ajax_test | src/S3/Sync_Center_Module.php:116 |
| `wp_ajax_sakura_s3_sync_toggle_index` | action | ajax_toggle_index | src/S3/Sync_Center_Module.php:119 |
| `wp_generate_attachment_metadata` | filter | array( $this->uploader, 'note_generation_done' ) | src/S3/Storage.php:143 |
| `wp_get_attachment_url` | filter | revert_unsynced_url | src/S3/Storage.php:133 |
| `wp_get_attachment_url` | filter | normalize_root_url | src/S3/Storage.php:155 |
| `wp_handle_sideload_prefilter` | filter | block_incomplete_upload | src/S3/Storage.php:121 |
| `wp_handle_upload_prefilter` | filter | block_incomplete_upload | src/S3/Storage.php:120 |
| `wp_update_attachment_metadata` | filter | upload_attachment_metadata | src/S3/Storage.php:140 |

### src/Sync（20 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | index_attachment | src/Sync/Attachment_Indexer.php:53 |
| `admin_init` | action | ensure_scheduled | src/Sync/Sync_Calibrator.php:112 |
| `admin_init` | action | ensure_scheduled | src/Sync/Verify_Worker.php:75 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/Sync/Sync_Calibrator.php:110 |
| `cron_schedules` | filter | array( __CLASS__, 'add_schedule' ) | src/Sync/Verify_Worker.php:73 |
| `delete_attachment` | action | forget_attachment | src/Sync/Attachment_Indexer.php:55 |
| `init` | action | ensure_scheduled | src/Sync/Sync_Calibrator.php:111 |
| `init` | action | ensure_scheduled | src/Sync/Verify_Worker.php:74 |
| `sakura_imgopt_s3_skip_finalize` | filter | __return_true | src/Sync/Attachment_Adopter.php:157 |
| `sakura_imgopt_sync_calibrate_now` | action | calibrate | src/Sync/Sync_Calibrator.php:114 |
| `sakura_imgopt_sync_calibrate` | action | calibrate | src/Sync/Sync_Calibrator.php:113 |
| `sakura_imgopt_sync_cleanup` | action | cleanup | src/Sync/Scan_Worker.php:79 |
| `sakura_imgopt_sync_daily_reconcile` | action | daily_reconcile | src/Sync/Scan_Worker.php:80 |
| `sakura_imgopt_sync_job_tick` | action | run | src/Sync/Action_Worker.php:63 |
| `sakura_imgopt_sync_kind_backfill` | action | kind_backfill | src/Sync/Scan_Worker.php:81 |
| `sakura_imgopt_sync_recent_backfill` | action | recent_backfill | src/Sync/Scan_Worker.php:82 |
| `sakura_imgopt_sync_scan_tick` | action | run | src/Sync/Scan_Worker.php:78 |
| `sakura_imgopt_sync_verify_tick` | action | run | src/Sync/Verify_Worker.php:76 |
| `sufm_skip_attachment_adoption` | filter | __return_true | src/Sync/Attachment_Adopter.php:154 |
| `wp_update_attachment_metadata` | action | on_metadata | src/Sync/Attachment_Indexer.php:54 |

### src/Thumbnail（1 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `shutdown` | action | flush | src/Thumbnail/Stats.php:58 |

### src/UserFiles（62 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | assign_uploaded | src/UserFiles/Media/Media_Hooks.php:127 |
| `add_option_sufm_space_settings` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:51 |
| `admin_body_class` | filter | body_class | src/UserFiles/Media/Media_Hooks.php:145 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Media/Media_Hooks.php:142 |
| `admin_enqueue_scripts` | action | enqueue_folder_panel | src/UserFiles/Media/Media_Hooks.php:146 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Admin/Space_Page.php:48 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Admin/Page.php:65 |
| `admin_init` | action | register_settings | src/UserFiles/Admin/Space_Page.php:47 |
| `admin_init` | action | register_settings | src/UserFiles/Admin/Page.php:64 |
| `admin_init` | action | maybe_install | src/UserFiles/Module.php:67 |
| `admin_init` | action | maybe_import_theme_limits | src/UserFiles/Module.php:68 |
| `admin_notices` | action | render_bulk_notice | src/UserFiles/Media/Media_Hooks.php:141 |
| `admin_notices` | action | render_folder_panel | src/UserFiles/Media/Media_Hooks.php:144 |
| `admin_post_sakura_sufm_download` | action | download_request_file | src/UserFiles/Admin/Page.php:67 |
| `after_setup_theme` | action | register_frontend | src/UserFiles/Module.php:106 |
| `ajax_query_attachments_args` | filter | filter_grid_query | src/UserFiles/Media/Media_Hooks.php:132 |
| `attachment_fields_to_edit` | filter | add_summary_field | src/UserFiles/Media/Media_Hooks.php:134 |
| `attachment_fields_to_save` | filter | save_summary_field | src/UserFiles/Media/Media_Hooks.php:135 |
| `bulk_actions-upload` | filter | add_bulk_action | src/UserFiles/Media/Media_Hooks.php:139 |
| `cron_schedules` | filter | cron_schedules | src/UserFiles/Module.php:70 |
| `delete_option_sufm_space_settings` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:53 |
| `deleted_post` | action | finalize_deleted_attachment | src/UserFiles/Media/Media_Hooks.php:137 |
| `handle_bulk_actions-upload` | filter | handle_bulk_action | src/UserFiles/Media/Media_Hooks.php:140 |
| `init` | action | schedule | src/UserFiles/Module.php:71 |
| `init` | action | register_renderers | src/UserFiles/Admin/Page.php:77 |
| `init` | action | register_shortcode | src/UserFiles/Frontend/Providers/Generic.php:82 |
| `load-upload.php` | action | preflight_bulk_delete | src/UserFiles/Media/Media_Hooks.php:143 |
| `main_user_tab_content_files` | filter | array( __CLASS__, 'enqueue_late' ) | src/UserFiles/Space_Panel.php:37 |
| `main_user_tab_content_files` | filter | content | src/UserFiles/Frontend/Tab.php:46 |
| `manage_media_columns` | filter | add_columns | src/UserFiles/Media/Media_Hooks.php:128 |
| `manage_media_custom_column` | action | render_column | src/UserFiles/Media/Media_Hooks.php:129 |
| `order_refunded` | action | array( __CLASS__, 'handle_order_refunded' ) | src/UserFiles/Services/Space_Store.php:3016 |
| `payment_order_success` | action | array( __CLASS__, 'on_payment_success' ) | src/UserFiles/Space_Panel.php:40 |
| `posts_where` | filter | filter_media_where | src/UserFiles/Media/Media_Hooks.php:133 |
| `pre_delete_attachment` | filter | guard_delete | src/UserFiles/Media/Media_Hooks.php:136 |
| `pre_get_posts` | action | filter_list_query | src/UserFiles/Media/Media_Hooks.php:131 |
| `rest_api_init` | action | register_rest | src/UserFiles/Editor/Module.php:31 |
| `rest_api_init` | action | array( __CLASS__, 'register_rest' ) | src/UserFiles/Space_Panel.php:39 |
| `rest_api_init` | action | register_rest | src/UserFiles/Module.php:99 |
| `rest_pre_dispatch` | filter | guard_rest_delete | src/UserFiles/Media/Media_Hooks.php:138 |
| `restrict_manage_posts` | action | render_filters | src/UserFiles/Media/Media_Hooks.php:130 |
| `sufm_allow_attachment_delete` | filter | $allow | src/UserFiles/Services/Uploader.php:434 |
| `sufm_allow_attachment_delete` | filter | $allow | src/UserFiles/Services/Executor.php:676 |
| `sufm_allow_attachment_delete` | filter | $allow | src/UserFiles/Services/Executor.php:758 |
| `sufm_delete_tick` | action | delete_tick | src/UserFiles/Module.php:75 |
| `sufm_tick` | action | array( __CLASS__, 'handle_tick' ) | src/UserFiles/Services/Space_Store.php:3019 |
| `sufm_tick` | action | tick | src/UserFiles/Module.php:72 |
| `update_option_sufm_settings` | action | flush_entry_cache | src/UserFiles/Admin/Page.php:66 |
| `update_option_sufm_settings` | action | sync_theme_limits | src/UserFiles/Module.php:69 |
| `update_option_sufm_space_settings` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:52 |
| `user_ctnter_main_tabs_array` | filter | tabs | src/UserFiles/Frontend/Tab.php:44 |
| `user_order_details_modal` | filter | array( __CLASS__, 'details_modal' ) | src/UserFiles/Space/Zibll_Order_Card.php:42 |
| `user_order_list_card` | filter | array( __CLASS__, 'list_card' ) | src/UserFiles/Space/Zibll_Order_Card.php:40 |
| `wp_enqueue_scripts` | action | array( __CLASS__, 'enqueue' ) | src/UserFiles/Space_Panel.php:38 |
| `wp_enqueue_scripts` | action | enqueue | src/UserFiles/Frontend/Tab.php:47 |
| `wp_enqueue_scripts` | action | enqueue | src/UserFiles/Editor/Module.php:60 |
| `wp_enqueue_scripts` | action | maybe_enqueue | src/UserFiles/Frontend/Providers/Generic.php:83 |
| `wp_footer` | action | render | src/UserFiles/Editor/Module.php:63 |
| `wp_handle_upload_prefilter` | filter | guard_media_upload | src/UserFiles/Media/Media_Hooks.php:126 |
| `wp` | action | start | src/UserFiles/Editor/Module.php:35 |
| `zib_user_center_page_sidebar_button_2_args` | filter | sidebar | src/UserFiles/Frontend/Tab.php:45 |
| `zibpay_order_title` | filter | array( __CLASS__, 'order_title' ) | src/UserFiles/Space/Zibll_Order_Card.php:41 |

### 未归属（4 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `admin_enqueue_scripts` | action | static function (): void {
		$sakura_page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';

		if ( 'sakura-imgopt-progress' !== $sakura_page && '' === \Sakura\ImageOptimizer\Admin\Route::current_page() ) {
			return;
		}

		wp_dequeue_script( 'wp-core-commands' );
		wp_dequeue_script( 'wp-commands' );
		wp_dequeue_style( 'wp-commands' );
	} | sakura-tools.php:121 |
| `init` | action | static function () {
		load_plugin_textdomain( 'sakura-imgopt', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	} | sakura-tools.php:96 |
| `plugins_loaded` | action | static function () {
		( new \Sakura\ImageOptimizer\Plugin() )->boot();
	} | sakura-tools.php:103 |
| `plugins_loaded` | action | static function (): void {
		if ( class_exists( 'Nboke\\UserFiles\\Space_Panel' ) ) {
			\Nboke\UserFiles\Space_Panel::register();
		}
	} | sakura-tools.php:25 |

合计 207 条具名钩子（add_action 150 + add_filter 57；已排除 tests/stubs、add_submenu_page 与 Hook_Loader 通用转发）。

## 附录 C：文件索引（191 个生产 PHP 文件）

| 文件 | 模块 | 行数 | 声明类 |
|---|---|---:|---|
| fast-search.php | 根文件 | 248 | — |
| includes/class-animation-detector.php | includes | 271 | `Animation_Detector` |
| includes/class-backup.php | includes | 539 | `Backup` |
| includes/class-file-system.php | includes | 249 | `File_System` |
| includes/class-gd-handler.php | includes | 253 | `GD_Handler` |
| includes/class-image-optimizer-module.php | includes | 1373 | `Image_Optimizer_Module` |
| includes/class-image-processor.php | includes | 314 | `Image_Processor` |
| includes/class-imagick-handler.php | includes | 313 | `Imagick_Handler` |
| includes/class-media-library-module.php | includes | 1030 | `Media_Library_Module` |
| includes/class-s3-cli.php | includes | 600 | `S3_CLI` |
| includes/class-s3-doge-cloud.php | includes | 141 | `S3_Doge_Cloud` |
| includes/class-s3-storage-module.php | includes | 299 | `S3_Storage_Module` |
| includes/class-stats.php | includes | 304 | `Stats` |
| includes/class-thumbnail-manager.php | includes | 114 | `Thumbnail_Manager` |
| includes/class-thumbnail-optimizer-module.php | includes | 399 | `Thumbnail_Optimizer_Module` |
| includes/views/editor-picker-app.php | includes/views | 311 | — |
| includes/views/media-progress.php | includes/views | 241 | — |
| includes/views/s3-sync-center.php | includes/views | 1017 | — |
| includes/views/settings-backup.php | includes/views | 214 | — |
| includes/views/settings-main.php | includes/views | 159 | — |
| includes/views/settings-stats.php | includes/views | 199 | — |
| includes/views/system-settings.php | includes/views | 612 | — |
| includes/views/thumb-settings.php | includes/views | 499 | — |
| includes/views/userfiles-admin.php | includes/views | 731 | — |
| includes/views/userfiles-space-admin.php | includes/views | 1024 | — |
| includes/views/userfiles-space-panel.php | includes/views | 276 | — |
| includes/views/userfiles-tab.php | includes/views | 308 | — |
| sakura-tools.php | 根文件 | 138 | — |
| src/Activation.php | src | 136 | `Activation` |
| src/Admin/Appearance.php | src/Admin | 139 | `Appearance` |
| src/Admin/Assets.php | src/Admin | 28 | `Assets` |
| src/Admin/Card_Form.php | src/Admin | 148 | `Card_Form` |
| src/Admin/Environment_Report.php | src/Admin | 343 | `Environment_Report` |
| src/Admin/Layout.php | src/Admin | 244 | `Layout` |
| src/Admin/Menu.php | src/Admin | 245 | `Menu` |
| src/Admin/Page_Assets.php | src/Admin | 190 | `Page_Assets` |
| src/Admin/Registry.php | src/Admin | 354 | `Registry` |
| src/Admin/Route.php | src/Admin | 55 | `Route` |
| src/Admin/Shell.php | src/Admin | 123 | `Shell` |
| src/Admin/System_Settings_Module.php | src/Admin | 120 | `System_Settings_Module` |
| src/Admin/UI.php | src/Admin | 135 | `UI` |
| src/Admin/UI/Card.php | src/Admin | 266 | `Card` |
| src/Admin/UI/Feedback.php | src/Admin | 126 | `Feedback` |
| src/Admin/UI/Field.php | src/Admin | 329 | `Field` |
| src/Admin/UI/Modal.php | src/Admin | 168 | `Modal` |
| src/Admin/UI/Page.php | src/Admin | 250 | `Page` |
| src/Backup/Backup_Repository.php | src/Backup | 92 | `Backup_Repository` |
| src/Backup/Exposure_Probe.php | src/Backup | 367 | `Exposure_Probe` |
| src/Contracts/Backup_Repository_Interface.php | src/Contracts | 94 | `Backup_Repository_Interface` |
| src/Contracts/File_System_Interface.php | src/Contracts | 80 | `File_System_Interface` |
| src/Contracts/Module.php | src/Contracts | 32 | `Module` |
| src/Contracts/Settings_Repository_Interface.php | src/Contracts | 126 | `Settings_Repository_Interface` |
| src/Contracts/Stats_Repository_Interface.php | src/Contracts | 60 | `Stats_Repository_Interface` |
| src/Deactivation.php | src | 64 | `Deactivation` |
| src/Filesystem/File_System_Repository.php | src/Filesystem | 97 | `File_System_Repository` |
| src/Image/Crop_Config_Scanner.php | src/Image | 1421 | `Crop_Config_Scanner` |
| src/Image/Engine_Manager.php | src/Image | 79 | `Engine_Manager` |
| src/Image/File_Processor.php | src/Image | 285 | `File_Processor` |
| src/Image/Metadata_Processor.php | src/Image | 297 | `Metadata_Processor` |
| src/Image/No_Gain_Guard.php | src/Image | 87 | `No_Gain_Guard` |
| src/Image/Restore_Service.php | src/Image | 274 | `Restore_Service` |
| src/Image/Size_Catalog.php | src/Image | 196 | `Size_Catalog` |
| src/Image/Upload_Backup.php | src/Image | 105 | `Upload_Backup` |
| src/Image/Upload_Handler.php | src/Image | 207 | `Upload_Handler` |
| src/Image/Upload_Renamer.php | src/Image | 226 | `Upload_Renamer` |
| src/Oss/Html_Filter.php | src/Oss | 260 | `Html_Filter` |
| src/Oss/Url_Rewriter.php | src/Oss | 245 | `Url_Rewriter` |
| src/Plugin.php | src | 208 | `Plugin` |
| src/S3/Alerting.php | src/S3 | 396 | `Alerting` |
| src/S3/Client_Factory.php | src/S3 | 115 | `Client_Factory` |
| src/S3/Config_Notice.php | src/S3 | 338 | `Config_Notice` |
| src/S3/Connection_Tester.php | src/S3 | 53 | `Connection_Tester` |
| src/S3/Credential_Cipher.php | src/S3 | 227 | `Credential_Cipher` |
| src/S3/Delete_Jobs.php | src/S3 | 585 | `Delete_Jobs` |
| src/S3/Delete_Queue_Panel.php | src/S3 | 516 | `Delete_Queue_Panel` |
| src/S3/Delete_Worker.php | src/S3 | 308 | `Delete_Worker` |
| src/S3/Error_Mapper.php | src/S3 | 114 | `Error_Mapper` |
| src/S3/History_Migrator.php | src/S3 | 406 | `History_Migrator` |
| src/S3/Ignored_Paths.php | src/S3 | 122 | `Ignored_Paths` |
| src/S3/Inventory.php | src/S3 | 42 | `Inventory` |
| src/S3/Legacy_Snapshot.php | src/S3 | 220 | `Legacy_Snapshot` |
| src/S3/Log.php | src/S3 | 31 | `Log` |
| src/S3/Migration_Service.php | src/S3 | 161 | `Migration_Service` |
| src/S3/Monitoring_Panel.php | src/S3 | 282 | `Monitoring_Panel` |
| src/S3/Object_Store.php | src/S3 | 809 | `Object_Store` |
| src/S3/Options.php | src/S3 | 582 | `Options` |
| src/S3/Profile_Manager.php | src/S3 | 143 | `Profile_Manager` |
| src/S3/Prometheus.php | src/S3 | 257 | `Prometheus` |
| src/S3/Providers.php | src/S3 | 52 | `Providers` |
| src/S3/Remote_Operation_Guard.php | src/S3 | 214 | `Remote_Operation_Guard` |
| src/S3/Restore_Plan.php | src/S3 | 87 | `Restore_Plan` |
| src/S3/S3_Storage_Page.php | src/S3 | 619 | `S3_Storage_Page` |
| src/S3/Settings_Saver.php | src/S3 | 300 | `Settings_Saver` |
| src/S3/Storage_Adapter.php | src/S3 | 477 | `Storage_Adapter` |
| src/S3/Storage.php | src/S3 | 780 | `Storage` |
| src/S3/Sync_Actions.php | src/S3 | 161 | `Sync_Actions` |
| src/S3/Sync_Center_Module.php | src/S3 | 1938 | `Sync_Center_Module` |
| src/S3/Sync_Center_Panel.php | src/S3 | 91 | `Sync_Center_Panel` |
| src/S3/Sync_Status.php | src/S3 | 462 | `Sync_Status` |
| src/S3/Synchronous_Uploader.php | src/S3 | 525 | `Synchronous_Uploader` |
| src/S3/Upload_Capabilities.php | src/S3 | 76 | `Upload_Capabilities` |
| src/S3/Upload_Metrics.php | src/S3 | 512 | `Upload_Metrics` |
| src/S3/Upload_Observer.php | src/S3 | 108 | `Upload_Observer` |
| src/S3/Upload_URL.php | src/S3 | 44 | `Upload_URL` |
| src/S3/Upload_Verify.php | src/S3 | 95 | `Upload_Verify` |
| src/Settings/Settings_Repository.php | src/Settings | 285 | `Settings_Repository` |
| src/Stats/Stats_Repository.php | src/Stats | 73 | `Stats_Repository` |
| src/Support/Hook_Loader.php | src/Support | 128 | `Hook_Loader` |
| src/Support/Text.php | src/Support | 50 | `Text` |
| src/Sync/Action_Worker.php | src/Sync | 238 | `Action_Worker` |
| src/Sync/Attachment_Adopter.php | src/Sync | 261 | `Attachment_Adopter` |
| src/Sync/Attachment_Indexer.php | src/Sync | 186 | `Attachment_Indexer` |
| src/Sync/Checksums.php | src/Sync | 77 | `Checksums` |
| src/Sync/CLI.php | src/Sync | 444 | `CLI` |
| src/Sync/Fast_Search_Query.php | src/Sync | 81 | `Fast_Search_Query` |
| src/Sync/Feature.php | src/Sync | 48 | `Feature` |
| src/Sync/Index_Repository.php | src/Sync | 2114 | `Index_Repository` |
| src/Sync/Keyset_Cursor.php | src/Sync | 135 | `Keyset_Cursor` |
| src/Sync/Kind.php | src/Sync | 88 | `Kind` |
| src/Sync/Metrics.php | src/Sync | 88 | `Metrics` |
| src/Sync/Scan_Service.php | src/Sync | 1011 | `Scan_Service` |
| src/Sync/Scan_Worker.php | src/Sync | 228 | `Scan_Worker` |
| src/Sync/Schema.php | src/Sync | 291 | `Schema` |
| src/Sync/Search_Token.php | src/Sync | 94 | `Search_Token` |
| src/Sync/Sync_Calibrator.php | src/Sync | 256 | `Sync_Calibrator` |
| src/Sync/Verify_Service.php | src/Sync | 282 | `Verify_Service` |
| src/Sync/Verify_Worker.php | src/Sync | 135 | `Verify_Worker` |
| src/Thumbnail/CLI.php | src/Thumbnail | 145 | `CLI` |
| src/Thumbnail/Context.php | src/Thumbnail | 121 | `Context` |
| src/Thumbnail/Decorator.php | src/Thumbnail | 543 | `Decorator` |
| src/Thumbnail/Policy.php | src/Thumbnail | 226 | `Policy` |
| src/Thumbnail/Probe.php | src/Thumbnail | 454 | `Probe` |
| src/Thumbnail/Provider_Status.php | src/Thumbnail | 125 | `Provider_Status` |
| src/Thumbnail/Provider.php | src/Thumbnail | 50 | `Provider` |
| src/Thumbnail/Providers/Bitiful.php | src/Thumbnail | 171 | `Bitiful` |
| src/Thumbnail/Providers/Generic.php | src/Thumbnail | 133 | `Generic` |
| src/Thumbnail/Rendition.php | src/Thumbnail | 178 | `Rendition` |
| src/Thumbnail/Settings.php | src/Thumbnail | 1061 | `Settings` |
| src/Thumbnail/Size_Catalog.php | src/Thumbnail | 80 | `Size_Catalog` |
| src/Thumbnail/Stats.php | src/Thumbnail | 192 | `Stats` |
| src/Thumbnail/Theme_Bridge.php | src/Thumbnail | 79 | `Theme_Bridge` |
| src/Thumbnail/Theme_Cache.php | src/Thumbnail | 229 | `Theme_Cache` |
| src/Uninstaller.php | src | 404 | `Uninstaller` |
| src/Upgrade_Guard.php | src | 128 | `Upgrade_Guard` |
| src/UserFiles/Admin/Page.php | src/UserFiles | 403 | `Page` |
| src/UserFiles/Admin/Space_Page.php | src/UserFiles | 298 | `Space_Page` |
| src/UserFiles/Capabilities.php | src/UserFiles | 155 | `Capabilities` |
| src/UserFiles/CLI.php | src/UserFiles | 487 | `CLI` |
| src/UserFiles/Editor/Assets.php | src/UserFiles | 111 | `Assets` |
| src/UserFiles/Editor/Context.php | src/UserFiles | 66 | `Context` |
| src/UserFiles/Editor/Module.php | src/UserFiles | 66 | `Module` |
| src/UserFiles/Editor/Picker.php | src/UserFiles | 100 | `Picker` |
| src/UserFiles/Frontend/Entry.php | src/UserFiles | 128 | `Entry` |
| src/UserFiles/Frontend/Provider.php | src/UserFiles | 60 | `Provider` |
| src/UserFiles/Frontend/Providers/Generic.php | src/UserFiles | 186 | `Generic` |
| src/UserFiles/Frontend/Providers/Zibll.php | src/UserFiles | 96 | `Zibll` |
| src/UserFiles/Frontend/Tab.php | src/UserFiles | 554 | `Tab` |
| src/UserFiles/Media/Library_Filter.php | src/UserFiles | 75 | `Library_Filter` |
| src/UserFiles/Media/Media_Hooks.php | src/UserFiles | 2186 | `Media_Hooks` |
| src/UserFiles/Module.php | src/UserFiles | 362 | `Module` |
| src/UserFiles/Repositories/Events.php | src/UserFiles | 86 | `Events` |
| src/UserFiles/Repositories/Files.php | src/UserFiles | 728 | `Files` |
| src/UserFiles/Repositories/Folders.php | src/UserFiles | 871 | `Folders` |
| src/UserFiles/Repositories/Notifications.php | src/UserFiles | 226 | `Notifications` |
| src/UserFiles/Repositories/Requests.php | src/UserFiles | 1202 | `Requests` |
| src/UserFiles/Rest/Controller.php | src/UserFiles | 2411 | `Controller` |
| src/UserFiles/Rest/Picker_Controller.php | src/UserFiles | 415 | `Picker_Controller` |
| src/UserFiles/Schema.php | src/UserFiles | 477 | `Schema` |
| src/UserFiles/Services/Adoption_Manifest.php | src/UserFiles | 61 | `Adoption_Manifest` |
| src/UserFiles/Services/Executor.php | src/UserFiles | 1299 | `Executor` |
| src/UserFiles/Services/Media_Move_Failure.php | src/UserFiles | 34 | `Media_Move_Failure` |
| src/UserFiles/Services/Media_Mover.php | src/UserFiles | 280 | `Media_Mover` |
| src/UserFiles/Services/Media_Notifications.php | src/UserFiles | 58 | `Media_Notifications` |
| src/UserFiles/Services/Notifier.php | src/UserFiles | 248 | `Notifier` |
| src/UserFiles/Services/Quota.php | src/UserFiles | 534 | `Quota` |
| src/UserFiles/Services/Rate_Limiter.php | src/UserFiles | 174 | `Rate_Limiter` |
| src/UserFiles/Services/Space_Rules.php | src/UserFiles | 418 | `Space_Rules` |
| src/UserFiles/Services/Space_Store.php | src/UserFiles | 3624 | `Space_Store` |
| src/UserFiles/Services/Sync_Service.php | src/UserFiles | 411 | `Sync_Service` |
| src/UserFiles/Services/Theme_Limits.php | src/UserFiles | 114 | `Theme_Limits` |
| src/UserFiles/Services/Uploader.php | src/UserFiles | 620 | `Uploader` |
| src/UserFiles/Services/Zibll_Bridge.php | src/UserFiles | 383 | `Zibll_Bridge` |
| src/UserFiles/Settings.php | src/UserFiles | 387 | `Settings` |
| src/UserFiles/Space_Panel.php | src/UserFiles | 349 | `Space_Panel` |
| src/UserFiles/Space_Settings.php | src/UserFiles | 936 | `Space_Settings` |
| src/UserFiles/Space/Zibll_Order_Card.php | src/UserFiles | 339 | `Zibll_Order_Card` |
| src/UserFiles/Support/Assets.php | src/UserFiles | 49 | `Assets` |
| src/UserFiles/Support/Db_Transaction.php | src/UserFiles | 93 | `Db_Transaction` |
| src/UserFiles/Support/Theme_Levels.php | src/UserFiles | 90 | `Theme_Levels` |
| src/UserFiles/Support/Time.php | src/UserFiles | 58 | `Time` |
| uninstall.php | 根文件 | 26 | — |

## 附录 D：`includes/` 旧层类表（14 类）

| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Animation_Detector` | includes/class-animation-detector.php:20 |  | 1 / 5 | 4 / 0 |  | 轻量探测上传图片是否为动图 |
| `Backup` | includes/class-backup.php:27 |  | 12 / 14 | 11 / 4 |  | 备份原图并支持原地一键还原 |
| `File_System` | includes/class-file-system.php:14 |  | 9 / 11 | 14 / 0 |  | 提供受控的本地文件操作 |
| `GD_Handler` | includes/class-gd-handler.php:16 |  | 2 / 5 | 4 / 4 |  | 用 GD 压缩图片并转换 WebP 与 AVIF |
| `Image_Optimizer_Module` | includes/class-image-optimizer-module.php:25 | implements Module | 40 / 45 | 4 / 26 | 5 | 渲染图像优化后台设置页面 |
| `Image_Processor` | includes/class-image-processor.php:39 | implements Module | 17 / 17 | 7 / 24 | 7 | 以钩子适配上传转换、子尺寸优化与还原 |
| `Imagick_Handler` | includes/class-imagick-handler.php:16 |  | 3 / 5 | 4 / 4 |  | 用 Imagick 压缩图片并转换格式 |
| `Media_Library_Module` | includes/class-media-library-module.php:30 | implements Module | 23 / 28 | 3 / 24 | 20 | 增强媒体库格式筛选与异步批量优化 |
| `S3_CLI` | includes/class-s3-cli.php:14 |  | 6 / 14 | 0 / 20 |  | 提供 S3 历史文件迁移的 WP-CLI 命令 |
| `S3_Doge_Cloud` | includes/class-s3-doge-cloud.php:16 |  | 2 / 7 | 1 / 4 |  | 获取并缓存多吉云短期 S3 凭据 |
| `S3_Storage_Module` | includes/class-s3-storage-module.php:25 | implements Module | 12 / 20 | 7 / 35 | 10 | 装配 S3 存储后台设置及其职责服务 |
| `Stats` | includes/class-stats.php:15 |  | 7 / 9 | 2 / 0 | 1 | 双层记录优化汇总与单附件处理历史 |
| `Thumbnail_Manager` | includes/class-thumbnail-manager.php:20 | implements Module | 6 / 6 | 5 / 3 | 5 | 按尺寸控制裁剪并移除被禁子尺寸 |
| `Thumbnail_Optimizer_Module` | includes/class-thumbnail-optimizer-module.php:32 | implements Module | 14 / 18 | 5 / 19 | 19 | 按 v2 策略重写附件图片 URL |
