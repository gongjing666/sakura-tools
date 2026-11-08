# 樱花工具箱（sakura-tools）代码结构分析报告

> **版本** 1.22.8 ｜ **代码基线** commit `55a9938`（在 `96622ab` 基础上合入 §6.8 清理与 §6.9 上传指标并发修复）｜ **分析日期** 2026-11-08
> **分析对象** `/www/wwwroot/www.nboke.com/wp-content/plugins/sakura-tools`（生产副本；§6.8/§6.9 的代码变更已包含在基线提交中，其余阅读与运行期验证均为只读）
> **测试环境** `/root/sio-e2e`（独立 WordPress + MariaDB 127.0.0.1:13316 + MinIO 127.0.0.1:19010），插件以软链方式挂载，行为与生产一致
> **配套产物** 3D 图谱 `docs/graph-preview/index.html`（同源数据 `graph.json`）、生成器 `docs/tools/build-code-structure.mjs`、图谱说明 `docs/sakura-tools-图谱同步-1.22.8.md`

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
| **未验证** | 只有静态阅读，未在运行时复现 | 配额扣减（只读边界） |
| **待确认** | 代码里存在歧义或超出本次范围，不下结论 | §12.2（本轮为空） |
| **口径** | 统计方式说明，避免读者误读数字 | 入度/出度的计算方式 |

### 0.3 数字口径（先对齐，避免误读）

| 指标 | 数值 | 口径 |
|---|---:|---|
| 生产 PHP 文件 | **178** | 不含 `tests/`(71 文件) `stubs/`(2) `vendor/` `assets/`(JS/CSS) |
| 生产 PHP 行数 | **60,460** | 同上，含空行与注释 |
| 类 / 接口 | **163** | 含 7 个接口；另有 64 个全局函数未计入图谱节点 |
| 钩子注册 | **191** | 仅生产代码中的 `add_action`/`add_filter`，不含 `do_action` 触发点 |
| 自定义数据表 | **23** | 15 张 `{prefix}sufm_*` + 6 张 `{prefix}sio_sync_*` + 2 张 `{prefix}sio_s3_*`（`sio_s3_deletes`、`sio_s3_upload_events`） |
| 后台页面 | **11** | 4 个顶级模块 + 13 个 tab 中实际可访问的 11 个（zibll 依赖主题，log 归入 files） |

> 说明：`includes/views/` 的 12 个模板文件不含类与函数声明，因此不出现在类索引中，但它们承担全部旧版页面渲染（见 §2.3）。

---

## 1. 结论摘要

**先看这十条，能省掉 80% 的翻文件时间。**

1. **双轨架构，且短期内不会合并。** `includes/` 是 1.3–1.9 的旧层（14 文件 / 5,999 行 / 14 类），`src/` 是 1.10 之后的新层（149 类，含 7 个接口）。两层的通信方式是**构造函数注入 + 类直接调用**，没有中间容器；唯一共享的抽象是 `src/Contracts/` 的 5 个接口。旧层没有被淘汰，反而承载了图片优化的核心链路（`includes/class-image-processor.php`）。
2. **全插件的装配只有一个入口。** `src/Plugin.php:70-197` 的 `boot()` + `build_modules()` 能看到**所有**依赖关系：先建 5 个仓储，再按固定顺序 new 出 20 多个模块/服务，最后 `Admin\Menu` 必须最后注册（`src/Plugin.php:193`）。读代码从这里开始，比按目录读快得多。
3. **模块契约极窄。** `src/Contracts/Module.php:23-31` 只有一个 `register_hooks(Hook_Loader): void`，且明确禁止在构造函数里挂 WP 钩子。所有钩子集中到 `src/Support/Hook_Loader.php`，先入队、`register()` 时一次性交付，**注册后新加的钩子立即生效**（`register_frontend` 依赖这个行为）。
4. **后台信息架构是静态声明的。** `src/Admin/Registry.php:41-101` 用一张常量数组声明「模块 → tab → 权限门 → renderer key」；`Menu` 只做建屏与分发，`Shell` 只画外壳。**加一个 tab = 改 Registry + 注册 renderer 两处**，不需要动菜单代码。
5. **功能面是 5 个子系统 + 3 条横切。** 子系统：图片优化管线（§4）、缩略图 URL 重写（§5）、对象存储 S3（§6）、同步索引 Sync（§7）、用户文件空间 UserFiles（§8）。横切：数据落点（§9）、钩子索引（§9.2）、REST/CLI/Cron（§9.3）。
6. **代码量集中在两处。** UserFiles（46 文件 / 21,167 行）与 S3（36 文件 / 10,803 行）合计占生产代码的 52.9%；两者也是自定义表、加密凭据与补偿队列最集中的部分。
7. **运行时可访问性已验证。** 11 条后台路由全部 200 且标题正确、45 条 `/sufm/v1` REST 路由（51 个端点）全部注册、7 个插件 cron 事件已排程、12 项函数级探针结果符合预期（§10）。**未验证**的是生产环境的写路径（配额扣减、删除审核、卸载清理）——生产保持只读。测试站上补做的写验证有两次：5 张并发上传（§10.6）跑通「上传 → 压缩 → S3 PUT → 索引落库」，4×200 条并发指标写入（§10.11）验证事件表零丢失。
8. **两张"文档"已经过期，不要当作事实源。** `DEVELOPMENT.md` 第 1 节的"当前文件结构"停留在 1.3.3 的 6 个文件；`/root/WordPress/docs/sakura-image-optimizer-代码分析报告.md`（本机历史报告，未随仓库分发） 停在 1.9.0 之前的命名。本报告与 3D 图谱是当前唯一同步的产物；`DECISIONS.md` 仍然有效（记录的是"为什么"，不是"现在有什么"）。
9. **文档与图谱同源，不会再脱节。** 两者都由同一份扫描事实表（来自 `dsh-codegraph` 的 `lib/scanner.php`）经 `docs/tools/build-code-structure.mjs` 生成。改代码后重跑一次命令即可同时刷新表格与图谱（§13.6）。
10. **上传指标已从单 option 改为事件表。** 旧实现每次 `record_batch()` 读改写同一个 option，4 进程各写 200 条只留下 202 条（丢 75%）；现在每条结果独立 INSERT 到 `{prefix}sio_s3_upload_events`（`src/S3/Upload_Metrics.php:79`、`:149`），`snapshot()` 用 SQL 聚合（`:204`），成功与失败分别由 `sakura_imgopt_s3_uploaded` / `sakura_imgopt_s3_upload_failed` 采集（`src/S3/Upload_Observer.php:30-31`）。返回结构不变，旧 option 只作为冻结基线读取。详见 §6.9 与 §10.11。

**本轮更新（2026-11-08）**：§12 原有 3 项已全部核实并处理：① `fast-search.php` **无缺陷**，生产环境没有额外封禁，入口按 HMAC + 管理员能力鉴权；② `Space_Store::grant_from_order()` **缺陷已修复**，订单按支付渠道写 `source/channel` 与订单字段，并默认只开放余额支付、补退款补偿与每日对账（§8.9、§10.12）；③ 上传失败不可见**已修复**，旧 `Upload_Observer` 只记录成功样本，本轮改为追加事件表 + 失败钩子，并发压测 800/800 条落库（§6.9、§10.11）。

---

## 2. 文件结构

### 2.1 目录树（生产代码）

```
sakura-tools/
├── sakura-tools.php            主文件：常量、自动加载、Plugin::boot 调用
├── uninstall.php               卸载入口（守卫 + 手动加载器 + Uninstaller）
├── fast-search.php             SHORTINIT 直连的只读索引查询端点
├── includes/                   14 文件 / 5,999 行 / 14 类    ← 旧层（1.3–1.9）
│   ├── class-image-processor.php       钩子门面，把 7 个 WP 钩子转给 src/Image
│   ├── class-imagick-handler.php       Imagick 编码与原子替换
│   ├── class-gd-handler.php            GD 编码与原子替换
│   ├── class-file-system.php           受控 copy/move/占位/删除
│   ├── class-backup.php                原图备份、还原、占用统计
│   ├── class-stats.php                 统计聚合（option + postmeta 明细）
│   ├── class-animation-detector.php    文件头识别动图
│   ├── class-s3-storage.php            S3 上传/删除的旧实现（Storage 的前身）
│   ├── class-s3-cli.php                WP-CLI 命令注册（仅 WP_CLI 时 require）
│   ├── class-media-library-module.php  媒体库批量处理与还原按钮
│   ├── class-image-optimizer-module.php 图片设置页 + 裁剪扫描 AJAX
│   ├── class-thumbnail-optimizer-module.php 缩略图子系统唯一装配点
│   ├── class-thumbnail-manager.php     按尺寸禁用缩略图生成
│   └── class-s3-storage-module.php     对象存储设置页
├── includes/views/             12 文件 / 4,732 行               ← 旧版页面模板
├── src/                        4 文件 / 759 行                  ← 组合根
│   ├── Plugin.php              启动、装配、注册 WP-CLI 命令
│   ├── Activation.php          默认值、迁移、建表、排程
│   ├── Deactivation.php        清排程与能力，保留数据
│   └── Uninstaller.php         两轨卸载（临时数据 / 持久数据 opt-in）
├── src/Admin/                  7 文件 / 1,895 行    后台 IA：Registry/Menu/Shell/Layout/Assets/环境自检
├── src/Image/                  10 文件 / 3,177 行   上传链路、引擎回退、元数据、还原、尺寸目录
├── src/Oss/                    2 文件 / 505 行      HTML 过滤器与 URL 重写纯函数
├── src/Thumbnail/              15 文件 / 3,764 行   缩略图策略、供应商、探测、统计、主题桥
├── src/S3/                     36 文件 / 10,803 行  凭据加密、上传、删除队列、同步中心、监控
├── src/Sync/                   17 文件 / 5,792 行   索引表、扫描状态机、动作队列、校验、CLI
├── src/UserFiles/              46 文件 / 21,167 行  上传/配额/审核/执行/REST/空间套餐/Zibll 桥
├── src/Backup/                 2 文件 / 459 行
├── src/Contracts/              5 文件 / 392 行      5 个接口
├── src/Filesystem/ src/Settings/ src/Stats/ src/Support/   5 文件 / 633 行
├── assets/                     28 文件 / 28,845 行  后台 JS/CSS（admin.css、各模块脚本）
├── languages/                  翻译文件
├── tests/                      71 文件 / 8,806 行   PHPUnit（不计入生产规模）
├── stubs/                      2 文件 / 136 行      PHPStan 引导
├── vendor/                     第三方依赖（AWS SDK 等，不计入）
└── DEVELOPMENT.md / DECISIONS.md / readme.txt   历史文档与决策记录
```

> **非插件产物说明**：插件根目录曾出现 `.dsh-ops/panel/`，是 dsh-ops 面板按「当前工作目录」写入的捕获文件，不属于插件。该目录由面板代码写死（`dsh-ops/lib/panel/config.js:8` 的 `PANEL_DIR`，`lib/cli/panel.js:42` 取 `cwd`），本机 DSH 的工作目录正好是插件根，因此每开一次会话就可能多一份快照。2026-11-08 已清理归档（当时 11 个文件 / 3.3 MB）并把 `.dsh-ops/` 加入 `.gitignore`；只要 DSH 仍以本目录为工作目录，它仍会重新生成。

### 2.2 模块总览

| 模块 | 文件数 | 代码行 | 类(含接口) | 说明 |
|---|---:|---:|---:|---|
| src/UserFiles | 46 | 21167 | 46 | 用户文件空间：上传、配额、审核执行、REST、Zibll 桥接 |
| src/S3 | 36 | 10803 | 36 | 对象存储：配置/加密、上传同步、删除队列、同步中心、监控告警 |
| includes | 14 | 5999 | 14 | 1.3–1.9 时代的旧类，仍在承重（媒体库、优化器、缩略图、S3、处理器） |
| src/Sync | 17 | 5792 | 17 | 持久化同步索引：扫描、任务、校验、CLI、快速检索查询 |
| includes/views | 12 | 4732 | 0 | 旧式页面模板（由 includes 类 require 渲染） |
| src/Thumbnail | 15 | 3764 | 15 | 缩略图 URL 重写策略：上下文、尺寸、供应商、探测、缓存 |
| src/Image | 10 | 3177 | 10 | 图片处理管线：改名、备份、引擎选择、元数据、还原、尺寸目录 |
| src/Admin | 7 | 1895 | 7 | 后台信息架构：Registry 路由表、Menu、Layout、Shell、Assets、环境自检 |
| src | 4 | 759 | 4 | 组合根与生命周期：Plugin、Activation、Deactivation、Uninstaller |
| src/Oss | 2 | 505 | 2 | HTML 过滤器与 URL 重写工具（缩略图策略复用的纯函数） |
| src/Backup | 2 | 459 | 2 | 原图备份仓储与公网暴露探测 |
| src/Contracts | 5 | 392 | 5 | 五个接口：Module / Backup / File_System / Settings / Stats |
| 根文件 | 3 | 383 | 0 | 插件入口、卸载入口、前台快速检索入口 |
| src/Settings | 1 | 285 | 1 | 插件设置读写边界（选项读写、默认值与清洗） |
| src/Support | 2 | 178 | 2 | Hook_Loader（延迟注册）与 UTF-8 截断工具 |
| src/Filesystem | 1 | 97 | 1 | 文件系统仓储适配器 |
| src/Stats | 1 | 73 | 1 | 统计仓储适配器 |
| 合计 | 178 | 60460 | 163 | 生产代码；另有 tests 72 文件/8800 行、stubs 2 文件另计 |

**怎么读这张表**：行数不等于复杂度——`src/UserFiles` 20k 行里有 40% 是 REST 控制器与视图模板（重复度高、改动风险低）；`src/Sync` 只有 5.8k 行却包含状态机与 SQL 层，是改动风险最高的模块。

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

- `includes` → `src`：138 条边（S3 40、Image 22、Contracts 17、Admin 14、Thumbnail 10、Support 9、Backup 7、Settings 6、Stats 5、Sync 4、Filesystem 3、Oss 1），说明旧层大量调用新层的服务；
- `src` → `includes`：仅 27 条边（src 10、Image 9、S3 8），集中在 `src/S3/Storage.php` 与 `src/Image/` 对 `Image_Processor`、`Backup`、`File_System`、`Stats` 的复用；
- 结论：**新层不依赖旧层的业务逻辑，只复用它的基础设施**（备份、统计、文件系统、处理器门面）。这条边界是安全的，重构时优先动 `includes` 的 `*_Module` 类。

> 注：上表两处"以矩阵为准"的数字在 §11.1 的矩阵里可直接核对；若矩阵与本段不一致，以矩阵为准（矩阵由脚本生成，本段为人工概述）。

---

## 3. 启动、装配与后台路由

### 3.1 入口文件

`sakura-tools.php` 只做四件事：定义常量（版本、路径、URL）、注册 PSR-4 自动加载、加载 `includes/` 旧类、调用 `Sakura\ImageOptimizer\Plugin::boot()`。**没有业务逻辑**，改动入口的风险极低。

### 3.2 `Plugin::boot()` 的六步（`src/Plugin.php:70-108`）

| 步骤 | 位置 | 做什么 | 为什么重要 |
|---:|---|---|---|
| 1 | `src/Plugin.php:71-74` | 防重入：`booted` 标志 | 防止 `plugins_loaded` 被多次触发时重复挂载 |
| 2 | `src/Plugin.php:76` | `Credential_Cipher::migrate_legacy_option()` | 把明文凭据迁移为 `sio1s:` 前缀密文 |
| 3 | `src/Plugin.php:77` | `Options::migrate_legacy_profile()` | 单配置 → 多配置档（profile）迁移 |
| 4 | `src/Plugin.php:80` | `Sync\Schema::maybe_install()`（仅后台或 WP-CLI） | 6 张 `sio_sync_*` 表的版本化建表 |
| 5 | `src/Plugin.php:83-86` | `build_modules()` 后逐个 `register_hooks($hooks)` | **唯一的装配点**，顺序即依赖 |
| 6 | `src/Plugin.php:107` | `$hooks->register()` | 把排队的钩子一次性交给 WordPress |

另外三处旁挂（同函数内）：`admin_init` 补挂扫描/动作的排程（`:88-94`）、`WP_CLI` 时 require `class-s3-cli.php` 并注册 `wp sakura-sync` / `wp sakura-thumb`（`:96-102`）、每日备份清理 `sakura_imgopt_daily_backup_cleanup`（`:106`）。

### 3.3 `build_modules()` 的装配顺序（`src/Plugin.php:117-197`）

顺序不是随意的，注释与代码共同说明了两条硬约束：**仓储先于消费者**、**菜单最后注册**。

```
① 5 个仓储（无钩子）
   Backup_Repository · Stats_Repository · File_System_Repository · Settings_Repository
   Image_Processor(backup, null, null, stats, files, settings)        src/Plugin.php:123
② 后台基础
   Basic_Settings_Module · Image_Optimizer_Module                     :129-132
   renderer: system:overview · image:rules · image:backup · image:stats  :136-139
③ 缩略图与处理器
   Thumbnail_Manager · $processor · Media_Library_Module · Admin\Layout  :141-152
④ 缩略图子系统（is_admin 时注册 image:thumb 渲染器）                    :155-165
⑤ S3 旁支（12 个服务，按 配置→仓储→扫描→队列→监控→索引 顺序）
   Options → Storage_Adapter → Index_Repository → Scan_Service → Scan_Worker
   → Action_Worker → Alerting → Prometheus → Attachment_Indexer → Verify_Worker  :167-186
⑥ S3 后台（is_admin）：S3_Storage_Module · Sync_Center_Module · Delete_Queue_Panel
   renderer: storage:connection · storage:sync · storage:deletes          :188-192
⑦ Admin\Menu（必须最后）                                              :193
⑧ 后台/前台共用：Delete_Worker · Upload_Observer · Storage
   · \Nboke\UserFiles\Module                                       :195-197
```

> 依据：`src/Plugin.php:117-197` 的实际代码顺序；`:193` 上方注释写明"菜单最后注册，保证 WordPress 取屏时渲染器都已存在"。

### 3.4 Hook_Loader 的延迟注册语义（`src/Support/Hook_Loader.php:55-108`）

```
add_action/add_filter  → 入队（未注册前）
register()             → 置 registered=true，把队列一次性交给 WP
register() 之后调用     → 直接 add_action/add_filter 到 WP（不再入队）
```

这个"注册后立即生效"的行为被前台缩略图装配依赖（`register_frontend` 在 `after_setup_theme` 才执行，此时 loader 早已 register）。**改动 Hook_Loader 前必须确认这一点**，否则前台缩略图会静默失效。

### 3.5 后台路由：Registry → Menu → Shell → renderer

| 环节 | 位置 | 职责 |
|---|---|---|
| 声明 | `src/Admin/Registry.php:41-101` | 4 个顶级模块、13 个 tab、权限门、renderer key |
| 建屏 | `src/Admin/Menu.php:32-54` | `admin_menu` 建菜单与子菜单，旧 slug 跳转 |
| 分发 | `src/Admin/Menu.php:94` | `Route → 模块 caps → tab_gate → renderer`，缺 renderer 时渲染占位 |
| 外壳 | `src/Admin/Shell.php` | 页头、模块切换、tab 条 |
| 渲染 | 各模块 `register_renderer(key, callable)` | 真正的页面内容 |

**四个模块与 13 个 tab**（`src/Admin/Registry.php:41-101`）：

| 模块（slug） | 能力要求 | tab |
|---|---|---|
| 系统 `sakura-tools` | `manage_options` | overview（环境自检） |
| 图片优化 `sakura-tools-image` | `manage_options` | rules · thumb · backup · stats |
| 对象存储 `sakura-tools-storage` | `manage_options` | connection · sync · deletes |
| 文件空间 `sakura-tools-files` | `review` | settings · users(gate:execute) · queue(execute) · log(execute) · zibll(gate:zibll+manage) |

权限门有三种：`manage_options` 直接放行、`review`→`UserFiles\Capabilities::can_review()`、`execute`→`can_execute()`（`src/Admin/Registry.php:76-99`）。`legacy()` 把 6 个旧 slug（如 `sakura-tools-optimize`）重定向到新路由，**验证 §10.3 里出现的那 1 次 `page=sakura-tools-optimize` 就是这条跳转链**。

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

> 为什么要抢在 S3 之前：S3 上传钩子也挂在 `wp_handle_upload` 上，若图片压缩排在后面，推到对象存储的会是未压缩版本（`includes/class-image-processor.php:149` 显式使用 `PHP_INT_MAX` + 2 参数）。

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
- **目录 `uploads/sakura-imgopt-backup/{年}/{月}/`**：含 `index.php` 与 `.htaccess` 拒绝规则（`includes/class-backup.php:30`、`:125-153`）。
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
3. **统计与备份 meta 依赖"待写记录"匹配**：待写记录以最终文件路径为键（`src/Image/Metadata_Processor.php:99-101`），flush 时先精确匹配、再匹配 `-scaled` 变体，只有在"恰好剩 1 条记录且文件名同干"时才走启发式兜底（`:222-251`）。**并发上传不会命中这个兜底分支**：`pending_records` 是实例属性（`:32`），每个 HTTP 请求都会重新构造一份 `Metadata_Processor`（`includes/class-image-processor.php:123`），进程之间不共享；`async-upload.php` 一次也只处理一个文件。2026-11-08 在测试站用本地 MinIO 做 5 张并发上传，5/5 的 `_sakura_imgopt_backup`、`sakura_imgopt_log`、S3 同步记录齐全（§10.6）。真正会丢 meta 的是"同一进程一次处理多张、路径又都对不上"的构造场景，标准媒体库上传路径不可达。
4. 备份写入失败会中止整条链路（`src/Image/Upload_Handler.php:141-145`），这是刻意的"宁可不压，也不丢原图"。

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
| 实际门槛 | `:177-181` + `Settings::legacy_options()` `:986-1035` | `legacy_options()` 强制 `enabled ← v2 enabled`、`oss_host ← media_host()`、`sidebar_enabled ← scopes.sidebar`（`:1022-1025`），旧 `legacy.enabled/oss_host` 存什么都不生效；真正条件是 v2 `enabled=1` 且 `s3 upload_url_path` 能解析出 host |
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

**一句话**：把媒体库附件在**产生它的同一请求内**同步到 S3 兼容对象存储（`src/S3/Synchronous_Uploader.php:180`），用 postmeta 记附件状态（`src/S3/Sync_Status.php:25-49`）、用自定义表做远端删除补偿（`src/S3/Delete_Jobs.php:109`），并提供只读同步中心、Prometheus 指标与 webhook 告警（`src/S3/Prometheus.php:107`、`src/S3/Alerting.php:267`）。

这也是全插件**唯一接触凭据**的子系统：`s3_options` 里的 key/secret 以 `sio1s:` 前缀密文存储（`src/S3/Credential_Cipher.php:21`，实测 `Credential_Cipher` roundtrip 通过，见 §10.5）。

### 6.2 类清单（`src/S3/`，36 个）

`| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Alerting` | src/S3/Alerting.php:24 | implements Module | 16 / 17 | 8 / 5 | 4 | 评估上传队列健康度并按需推送 JSON webhook |
| `Client_Factory` | src/S3/Client_Factory.php:20 |  | 2 / 2 | 8 / 5 |  | 按选项构建并缓存 AWS SDK 客户端 |
| `Connection_Tester` | src/S3/Connection_Tester.php:11 |  | 2 / 3 | 2 / 7 |  | 处理后台提交的 S3 连接测试 |
| `Credential_Cipher` | src/S3/Credential_Cipher.php:21 |  | 5 / 7 | 12 / 1 |  | 加密写入 wp_options 的存储凭据 |
| `Delete_Jobs` | src/S3/Delete_Jobs.php:22 |  | 22 / 22 | 19 / 0 |  | 为首次删除失败的对象保存持久删除任务 |
| `Delete_Queue_Panel` | src/S3/Delete_Queue_Panel.php:23 |  | 5 / 11 | 2 / 9 | 2 | 渲染删除队列页面并处理行内操作 |
| `Delete_Worker` | src/S3/Delete_Worker.php:20 | implements Module | 8 / 15 | 9 / 19 | 4 | 只排空删除队列中的远端删除任务 |
| `Error_Mapper` | src/S3/Error_Mapper.php:15 |  | 3 / 3 | 4 / 2 |  | 把 SDK 失败映射为稳定的 WordPress 错误码 |
| `History_Migrator` | src/S3/History_Migrator.php:19 |  | 8 / 15 | 5 / 11 | 1 | 按 ID 升序分批扫描并上传历史附件 |
| `Ignored_Paths` | src/S3/Ignored_Paths.php:20 |  | 4 / 4 | 4 / 0 |  | 保存同步扫描需跳过的额外路径前缀 |
| `Inventory` | src/S3/Inventory.php:22 |  | 1 / 1 | 0 / 0 |  | 把字节宽度换算为可读格式 |
| `Legacy_Snapshot` | src/S3/Legacy_Snapshot.php:24 |  | 3 / 5 | 3 / 7 |  | 无同步索引时构建缓存的本地远端快照 |
| `Log` | src/S3/Log.php:17 |  | 1 / 1 | 10 / 0 |  | 仅在 WP_DEBUG 开启时输出 S3 日志 |
| `Migration_Service` | src/S3/Migration_Service.php:17 |  | 2 / 2 | 2 / 0 |  | 以注入回调执行纯迁移循环 |
| `Monitoring_Panel` | src/S3/Monitoring_Panel.php:21 |  | 4 / 8 | 4 / 6 |  | 渲染监控与 webhook 表单并处理提交 |
| `Object_Store` | src/S3/Object_Store.php:19 |  | 7 / 10 | 8 / 11 |  | 以注入客户端执行无状态对象存储操作 |
| `Options` | src/S3/Options.php:19 |  | 23 / 25 | 75 / 4 |  | 规范化 s3_options 并处理旧版迁移与常量覆盖 |
| `Profile_Manager` | src/S3/Profile_Manager.php:23 |  | 2 / 3 | 3 / 5 |  | 按保存的配置档切换当前生效配置 |
| `Prometheus` | src/S3/Prometheus.php:24 | implements Module | 8 / 11 | 7 / 4 | 3 | 输出受令牌保护的 Prometheus 指标文档 |
| `Providers` | src/S3/Providers.php:9 |  | 2 / 2 | 8 / 0 |  | 列出受支持的 S3 兼容服务商 |
| `Remote_Operation_Guard` | src/S3/Remote_Operation_Guard.php:14 |  | 5 / 9 | 8 / 2 |  | 以无 TTL 预约守卫远端操作并要求显式对账 |
| `Restore_Plan` | src/S3/Restore_Plan.php:27 |  | 3 / 3 | 8 / 0 |  | 比对附件新旧对象键以生成还原计划 |
| `S3_Storage_Page` | src/S3/S3_Storage_Page.php:9 |  | 2 / 3 | 2 / 18 |  | 渲染 S3 设置页与只读跳转提示 |
| `Settings_Saver` | src/S3/Settings_Saver.php:11 |  | 4 / 6 | 4 / 14 |  | 处理 S3 设置的后台提交保存 |
| `Storage` | src/S3/Storage.php:22 | implements Module | 20 / 24 | 6 / 31 | 14 | 注册 S3 钩子并协调上传入队 |
| `Storage_Adapter` | src/S3/Storage_Adapter.php:20 |  | 12 / 17 | 33 / 13 |  | 把本地路径映射为对象键并经对象存储上传 |
| `Sync_Actions` | src/S3/Sync_Actions.php:20 |  | 5 / 5 | 4 / 10 |  | 对清单选定对象执行上传、下载或删除 |
| `Sync_Center_Module` | src/S3/Sync_Center_Module.php:27 | implements Module | 16 / 25 | 2 / 52 | 13 | 添加同步中心页面及只读扫描与导出端点 |
| `Sync_Center_Panel` | src/S3/Sync_Center_Panel.php:20 |  | 1 / 1 | 2 / 4 |  | 展示上传队列吞吐指标面板 |
| `Sync_Status` | src/S3/Sync_Status.php:23 |  | 23 / 23 | 30 / 5 |  | 用附件元数据保存同步状态、对象键与重试任务 |
| `Synchronous_Uploader` | src/S3/Synchronous_Uploader.php:26 |  | 7 / 13 | 16 / 12 |  | 在创建附件的请求内完成其全部文件上传 |
| `Upload_Capabilities` | src/S3/Upload_Capabilities.php:23 |  | 3 / 3 | 8 / 2 |  | 判定打包 SDK 能否并发多个 HTTP 请求 |
| `Upload_Metrics` | src/S3/Upload_Metrics.php:27 |  | 10 / 18 | 13 / 2 |  | 把上传结果逐条追加到事件表并按 SQL 聚合快照 |
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
| `Profile_Manager::switch_profile` | `src/S3/Profile_Manager.php:31` | 复制已存配置（密文原样）并接管 `upload_url_path` | ⇐ `admin_post_sakura_s3_switch` |
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
| `sakura_imgopt_metrics` | init / query var | `src/S3/Prometheus.php:26-30`、`:73-77` |
| `wp sakura-s3` | WP-CLI | `includes/class-s3-cli.php:501` |

### 6.5 数据落点

- **option `s3_options`**：`enabled`、`provider`、`bucket`、`region`、`endpoint`、key/secret（密文）、`upload_path`、`upload_url_path`、`profiles`、`retired_targets`、`active_profile_id`（`src/S3/Options.php:24`、`:97-114`；写入 `Settings_Saver.php:165-189`）。实测 14 键、`enabled=true`、`provider=minio`（§10.4）。
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
- 处置：删除 `src/S3/Upload_Queue.php` 与 `tests/UploadQueueTest.php`，并移除 `tests/bootstrap.php:464` 的 `require_once`。S3 模块类数 37 → 36，图谱节点 181 → 180。

**② `Upload_Metrics::snapshot()` 的 `counts` / `backlog`：生产者已不存在，已移除。**

- 事实：清理前的 `snapshot( ?array $counts = null )` 的 `$counts` 只用于合并 `queued/running/retrying/dead/due/backlog/oldest`（清理前 `src/S3/Upload_Metrics.php:162-175`），而生产调用方**全部无参**（清理前位置）：`src/S3/Prometheus.php:124`、`src/S3/Alerting.php:254/:331`、`src/S3/Sync_Center_Panel.php:29`、`src/Sync/CLI.php:155`。
- 历史：`DEVELOPMENT.md` §14.35 说明该参数是 1.6.4 为 `Upload_Jobs::counts()` 预留的注入口；1.12.0 删除异步队列后 `Upload_Jobs` 已不存在，文档原话是「后台卡片的积压现在恒为 0，不再代表上传队列」。所以不是「忘记传参」，而是**生产者被删后接口没跟着收口**。
- 处置：`snapshot()` 改为无参（`src/S3/Upload_Metrics.php:143`），删除 `$counts` 合并块与返回值里的 `backlog`；连带清掉下游恒零消费端——`Prometheus::format` 去掉 `queue_depth{state=…}`（6 个 state）与 `queue_oldest_seconds`（清理前 `src/S3/Prometheus.php:135`）；`Alerting` 去掉 `max_dead` 配置、`dead` 告警分支与 payload 里的队列字段（清理前 `src/S3/Alerting.php:152-215`，现 `evaluate()` 位于 `:152-169`）；`Monitoring_Panel` 去掉「死信阈值（0 关闭）」输入与 POST 处理（清理前 `src/S3/Monitoring_Panel.php:81-128`）；`Sync\CLI::stats` 去掉 Backlog 行（清理前 `src/Sync/CLI.php:154-184`）。相应测试改为 `snapshot()` 无参并断言不存在 `backlog` 键。
- 结果：`vendor/bin/phpunit` 391 tests / 1120 assertions / 5 skipped 全绿（清理前基线 395/1124/5 skipped）；PHPStan `[OK] No errors`；phpcs 改动文件 exit 0。测试站 `wp sakura-sync stats --format=table` 已不再输出 Backlog 行（§10.8）。

**③ 两套 `target_hash`：语义不同，不需要一致，已互相注明。**

- 上传侧 `Synchronous_Uploader::target_hash_for()`（`src/S3/Synchronous_Uploader.php:488`）= `sha256(active_profile_id|provider|bucket|endpoint|upload_path)`，写入 postmeta `_sakura_imgopt_s3_target`，回答的是「这个附件/动作属于哪个**上传目标**」。
- 删除侧 `Delete_Jobs::target_hash()`（`src/S3/Delete_Jobs.php:61`）= `sha256(wp_json_encode(provider, bucket, endpoint, region))`，写入 `sio_s3_deletes.target_hash`，回答的是「这条删除任务属于哪个**存储桶身份**」。profile 改桶后旧任务仍要能删，所以归档配置放在 `s3_options.retired_targets`（`src/S3/Settings_Saver.php:143-163`），删除侧必须带 `region` 才能在归档目标上定位对象。
- 关系：全仓**没有任何地方把两者交叉比较**。上传 hash 只与 `target_hash_for()` 的新结果比（`src/S3/Storage.php:672-674`、`src/S3/Sync_Center_Module.php:744/788/866`、`src/S3/Action_Worker.php:91/102/230`）；删除 hash 只与 `Delete_Jobs::target_hash()` 比（`src/S3/Delete_Worker.php:191-206`）。两者字段集合与归一化方式都不同（上传含 profile/路径、不含 region；删除含 region、不含 profile/路径），**统一会强制引入对方不需要的字段**，并使已存的 postmeta、`sio_s3_deletes.target_hash`、`sio_sync_jobs.target_hash` 全部失配（上传侧被判目标变更、删除侧全部 drift）。
- 处置：不改算法，只在两处 docblock 互相注明差异与「不得统一」的原因；测试站探针实测两条 hash 不相等（`equal=no`，字段与数值见 §10.8）。

**④ `Sync_Status` 三个遗留方法与 `PREVIOUS_PATH_OPTION`：保留或接线，不再算死代码。**

- `Sync_Status::enqueue()` / `jobs()` / `fail_job()`（`src/S3/Sync_Status.php:299`、`:328`、`:342`）是 1.12.0 之前异步上传的重试簿记：写 `JOBS_META=_sakura_imgopt_s3_jobs`，退避 `min(3600, 60*2^min(5,attempts))`，8 次置 `dead`。`src/` 内除定义外**无调用方**，但 `Storage::maybe_migrate`（`src/S3/Storage.php:464-516`）仍直接读同一份 postmeta，把老站遗留任务迁移成 `record_failures` 后删除，所以 `JOBS_META` 常量不能删。
- 处置：三个方法**保留**并加 `@deprecated 1.12.0`，docblock 写明只服务于遗留数据迁移、新代码不要调用（公开 API，测试仍覆盖）；`Storage::maybe_migrate` 保留。
- `Upload_URL::PREVIOUS_PATH_OPTION`（`src/S3/Upload_URL.php:12`，值 `sakura_imgopt_previous_upload_path`）原先「声明未使用」，但清理前 `Uninstaller::cleanup_persistent_data()` 一直用**字面量**读/删这个 option 来还原老站的 `upload_path`。处置：`Uninstaller` 引入 `use Sakura\ImageOptimizer\S3\Upload_URL;`，把读/还原/删除 `upload_url_path` 与 `upload_path` 旧值的三处逻辑改用 `Upload_URL::PREVIOUS_URL_OPTION` / `PREVIOUS_PATH_OPTION`（现 `src/Uninstaller.php:209`、`:215`、`:217`、`:226`）。常量由此获得真实引用，字面量消失；行为不变（探针里 `previous_upload_path=NULL`、`previous_upload_url_path` 为空，说明本站没有可还原的旧值）。

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
- **卸载**：`src/Uninstaller.php:111` 的删表清单已加入 `sio_s3_upload_events`，`:121` 删除 `sakura_imgopt_s3_upload_schema`；旧 option 仍在 `monitoring_options()`（`:161`）里，按原有监控数据策略清理。
- **已知边界**：① 事件表不做 TTL 裁剪，`totals` 是自安装以来的全量，表会随上传量增长；② 若站点先回滚到旧版（旧版只写 option）再升级，冻结基线会包含回滚期间写入的值，而事件表也保留了同一时期的行，可能重复计数——本设计不做去重；③ `upload_many()` 的合法 key + 缺失文件会在进入存储前被 `Remote_Operation_Guard` 拦截，留下 `sio_sync_operations` 的 uncertain 行，同时仍写一条 `sio_remote_uncertain` 失败事件；前者需要操作员处理，后者只是观测记录。

#### 6.9.4 验证结果（摘要）

测试站上先删空事件表，再执行 4 进程 × 200 条失败写入：`t_sio_s3_upload_events` 得到 800 行、4 个不同 `attachment_id`，快照 `totals.fail=800`；失败采集探针写入 4 行（`attachment_id` 4242/4343/4444，空 key 与缺失文件都有记录），`error_code=sakura_s3_invalid_file`。加上旧 option 的 30 条成功基线后，快照为 `attempts=830`、`fail=800`、`rate=96.3855`；CLI、Prometheus 与后台卡片三种消费方均正常输出。验证命令与输出见 §10.11，执行后已清空事件行与守卫行，测试站快照回到 30 成功 / 0 失败。

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
| `Action_Worker` | src/Sync/Action_Worker.php:28 | implements Module | 5 / 7 | 10 / 21 |  | 以指数退避执行队列中的上传下载删除任务 |
| `Attachment_Adopter` | src/Sync/Attachment_Adopter.php:26 |  | 8 / 9 | 4 / 2 | 2 | 把已有上传文件接纳进媒体库 |
| `Attachment_Indexer` | src/Sync/Attachment_Indexer.php:28 | implements Module | 5 / 9 | 1 / 10 | 3 | 把媒体写入实时镜像进同步索引 |
| `Checksums` | src/Sync/Checksums.php:17 |  | 3 / 3 | 8 / 0 |  | 规范化 S3 ETag 并判断能否与 MD5 比较 |
| `CLI` | src/Sync/CLI.php:25 |  | 8 / 9 | 1 / 16 |  | 用 WP-CLI 扫描、检查并维护同步索引 |
| `Fast_Search_Query` | src/Sync/Fast_Search_Query.php:24 |  | 2 / 2 | 3 / 2 |  | 构建快速搜索使用的对象列表查询 |
| `Feature` | src/Sync/Feature.php:20 |  | 2 / 2 | 6 / 0 |  | 控制持久化同步索引的运行时开关 |
| `Index_Repository` | src/Sync/Index_Repository.php:24 |  | 61 / 69 | 29 / 11 |  | 为同步索引提供批量写入与键集分页查询 |
| `Keyset_Cursor` | src/Sync/Keyset_Cursor.php:21 |  | 6 / 6 | 8 / 0 |  | 编码键集分页使用的对象键哈希边界 |
| `Kind` | src/Sync/Kind.php:20 |  | 2 / 2 | 8 / 0 |  | 把对象键归类为索引中的 kind 值 |
| `Metrics` | src/Sync/Metrics.php:20 |  | 3 / 3 | 6 / 0 |  | 维护扫描与队列动作的滚动计数器 |
| `Scan_Service` | src/Sync/Scan_Service.php:30 |  | 8 / 25 | 9 / 14 |  | 把本地文件与 S3 对象流式写入同步索引 |
| `Scan_Worker` | src/Sync/Scan_Worker.php:27 | implements Module | 9 / 10 | 8 / 8 | 1 | 经 WP-Cron 分片推进扫描进度 |
| `Schema` | src/Sync/Schema.php:21 |  | 3 / 5 | 9 / 2 |  | 创建并升级同步索引数据表 |
| `Search_Token` | src/Sync/Search_Token.php:22 |  | 4 / 4 | 4 / 0 |  | 签发并校验搜索用签名令牌 |
| `Verify_Service` | src/Sync/Verify_Service.php:24 |  | 2 / 7 | 4 / 6 |  | 逐行比对尚未校验的索引内容 |
| `Verify_Worker` | src/Sync/Verify_Worker.php:25 | implements Module | 6 / 6 | 3 / 8 | 3 | 分批比对本地 MD5 与远端 ETag |`

> `fast-search.php`（非类，`fast-search.php:1-12`）：绕过 admin-ajax 的只读索引查询端点，`SHORTINIT` 启动（`:42-43`），只查 `sio_sync_index`，不加载插件其余部分。

### 7.3 关键方法

| 方法 | 文件:行 | 职责 | 调用关系 |
|---|---|---|---|
| `Schema::maybe_install` | `src/Sync/Schema.php:61` | 比对 option 与 `VERSION='1.8.2'`（`:26`），必要时建表 + 迁移 | ⇐ `Activation.php:96`、`Plugin.php:80` |
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
| 清理入口 | `pending()` 只读列出最多 100 行（含崩溃遗留的 inflight）；`acknowledge_settled(resource,token)` 校验两个 64 位 hex 后无条件删除；**没有 TTL、没有自动回收** | `:141-158` |
| 调用面 | `Storage_Adapter::upload_file` / `upload_many` / `delete_keys` 都经 `guard()->execute(...)` | `src/S3/Storage_Adapter.php:136`、`:179`、`:346` |
| 消费面 | 没有后台页面、CLI 或 cron 调用 `pending()` / `acknowledge_settled`；除类自身外仅测试引用 | 全仓 grep + `tests/RemoteOperationGuardTest.php` |

**执行顺序**（`:61-106`）：先对全部 key 逐个 `acquire`；随后才执行可选的 `check()`（队列所有权复核）。`check` 失败或抛异常时，所有已取得的 reservation 会被释放，**尚未发送任何远端写入**。只有 `check` 通过后才调用 `dispatch`。这保证了「先占位、再确认、最后发送」的顺序。

**为什么没有 TTL**：类注释（`:9-13`）写明——死掉的 PHP 进程或过期的队列租约都**不能证明** S3 请求已经停止，HEAD 200/404 也不是静默证明。因此 `uncertain` 必须保留，靠操作员查 `{prefix}sio_sync_operations` 后手工解除。构造函数默认新建独立 `wpdb` 并强制 autocommit（`:20-42`），避免调用方事务回滚把「已经发出的请求」记录擦掉；检测到 `wp-content/db.php` 会直接抛异常，因为无法确认共享账本。

运行期探针（§10.9-A）：正常 dispatch 后无残留；dispatch 返回 `WP_Error` 后写入 `state=uncertain`，同一 scope/key 再 `execute` 返回 `sio_remote_busy`；错误 token 解除返回 false、正确 token 返回 true、第二次再解除返回 false。

#### 7.7.2 同步中心仍走 admin-ajax，没有 REST

**结论**：Sync 中心的服务端入口全部是 `admin-ajax.php` / `admin-post.php`；全插件 REST 只属于 UserFiles 的 `sufm/v1`。这不是遗漏，是当前实现方式。

| 观察点 | 结论 | 代码依据 |
|---|---|---|
| 注册面 | 9 个 `wp_ajax_sakura_s3_sync_*` + 2 个 `admin_post_*`；没有 `register_rest_route` | `src/S3/Sync_Center_Module.php:101-113` |
| 资源加载 | 仅在 `Registry::on('storage','sync')` 时加载 `assets/admin.js` | `:138-147` |
| 页面配置 | `wp_localize_script` 的 `sakuraSyncCenter` 提供 `ajaxUrl`（`admin-ajax.php`）与 `searchUrl`（`fast-search.php`）等；**没有 `restUrl`** | `:152-168` |
| 普通请求 | `URLSearchParams` 组 `action + nonce + 参数`，`fetch(config.ajaxUrl, {method:'POST'})` | `assets/admin.js:43-67` |
| 快速翻页 | `GET config.searchUrl + '?' + params`，带 `token` | `assets/admin.js:69-105` |
| REST 反证 | `register_rest_route` 只出现在 `src/UserFiles/Rest/*`（如 `Controller.php:49`、`Picker_Controller.php:73/87`），命名空间为 `sufm/v1` | 全仓 grep |

实际注册的 11 个入口：

```
wp_ajax_sakura_s3_sync_scan          wp_ajax_sakura_s3_sync_scan_step
wp_ajax_sakura_s3_sync_cancel        wp_ajax_sakura_s3_sync_resume
wp_ajax_sakura_s3_sync_page          wp_ajax_sakura_s3_sync_test
wp_ajax_sakura_s3_sync_action        wp_ajax_sakura_s3_sync_refresh
wp_ajax_sakura_s3_sync_toggle_index
admin_post_sakura_s3_sync_export     admin_post_sakura_s3_ignored_save
```

`assets/admin.js` 里另有 `sakura_s3_retry`，它注册在 `includes/class-s3-storage-module.php:74`，属于对象存储页的旧失败重试入口，不在上表 9 个 Sync 动作内，不影响「Sync 无 REST」的结论。

运行期（§10.9-B）：用后台 Cookie 请求 `/wp-admin/admin.php?page=sakura-tools-storage&t=sync` 得到 240,612 字节页面，含 `<div class="sakura-sync-app" ...>` 且加载 `assets/admin.js`；localize 配置只有 `ajaxUrl` / `searchUrl`，无 `restUrl`；同一 Cookie 会话 POST `action=sakura_s3_sync_page&nonce=...&status=all&limit=2` 返回 `success=true` 与 2 行数据。

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

## 8. 子系统 E：用户文件空间（src/UserFiles/，46 文件 / 21,167 行）

### 8.1 职责与边界

**一句话**：给登录用户提供**私有文件/文件夹 + 上传配额 + 「申请—审核—执行」删除**的能力，并把 WordPress 媒体库、Zibll 主题用户中心、订单/积分扩容接进来；状态落 15 张自定义表，异步动作由 `sufm_tick` / `sufm_delete_tick` 驱动。

这是全插件最大、也是唯一带**五层权限门**（`can_use_files` / `can_review` / `can_execute` / `can_manage_media` / `space_can_*`）的子系统。改它之前必须先读 `src/UserFiles/Capabilities.php` 与 `src/UserFiles/Schema.php`。

### 8.2 类清单

`| 类 | 文件:行 | 继承 / 实现 | 方法(公开/总) | 入度/出度 | 钩子 | 职责（据源码类注释译述） |
|---|---|---|---:|---:|---:|---|
| `Adoption_Manifest` | src/UserFiles/Services/Adoption_Manifest.php:7 |  | 1 / 2 | 2 / 2 |  | 为首次接纳构建只读的可信文件清单 |
| `Assets` | src/UserFiles/Editor/Assets.php:20 |  | 1 / 1 | 3 / 2 |  | 注册编辑器样式、脚本与配置对象 |
| `Assets` | src/UserFiles/Support/Assets.php:21 |  | 1 / 1 | 10 / 0 |  | 统一为打包资源生成防缓存版本号 |
| `Capabilities` | src/UserFiles/Capabilities.php:20 |  | 8 / 8 | 20 / 0 |  | 定义四个文件操作能力，删除另需 delete_post |
| `CLI` | src/UserFiles/CLI.php:23 |  | 6 / 7 | 0 / 11 |  | 用 WP-CLI 检查并驱动文件空间任务 |
| `Context` | src/UserFiles/Editor/Context.php:20 |  | 3 / 3 | 3 / 3 |  | 为 Zibll 两个编辑屏幕提供统一编辑器标识 |
| `Controller` | src/UserFiles/Rest/Controller.php:34 |  | 58 / 64 | 1 / 40 |  | 从会话取所有者，提供 sufm/v1 REST 接口 |
| `Db_Transaction` | src/UserFiles/Support/Db_Transaction.php:22 |  | 5 / 5 | 16 / 0 |  | 统计插件自身的事务语句以避免嵌套 |
| `Entry` | src/UserFiles/Frontend/Entry.php:24 |  | 7 / 7 | 4 / 7 |  | 选取首个可用前端提供者并上报其状态 |
| `Events` | src/UserFiles/Repositories/Events.php:22 |  | 2 / 2 | 12 / 6 |  | 记录不含路径与凭据的审计事件 |
| `Executor` | src/UserFiles/Services/Executor.php:30 |  | 6 / 21 | 4 / 22 | 2 | 执行不可逆步骤前重读审批与停止标记 |
| `Files` | src/UserFiles/Repositories/Files.php:24 |  | 15 / 20 | 25 / 6 |  | 维护受管文件记录，区分上传与媒体库接纳 |
| `Folders` | src/UserFiles/Repositories/Folders.php:28 |  | 18 / 38 | 18 / 9 |  | 维护虚拟文件夹树，不移动磁盘文件 |
| `Generic` | src/UserFiles/Frontend/Providers/Generic.php:24 | implements Provider | 11 / 12 | 3 / 4 | 2 | 通过 [sufm_files] 短代码挂载文件空间 |
| `Library_Filter` | src/UserFiles/Media/Library_Filter.php:15 |  | 1 / 1 | 2 / 2 |  | 为原生媒体库提供 SQL 过滤条件 |
| `Media_Hooks` | src/UserFiles/Media/Media_Hooks.php:37 |  | 24 / 52 | 1 / 32 | 21 | 衔接用户文件空间与 WordPress 媒体库 |
| `Media_Move_Failure` | src/UserFiles/Services/Media_Move_Failure.php:21 | extends RuntimeException | 1 / 1 | 2 / 1 |  | 表示媒体库移动失败并携带原始 WP_Error |
| `Media_Mover` | src/UserFiles/Services/Media_Mover.php:14 |  | 4 / 15 | 1 / 14 |  | 原子执行虚拟文件夹移动、转移与首次接纳 |
| `Media_Notifications` | src/UserFiles/Services/Media_Notifications.php:8 |  | 1 / 1 | 1 / 4 |  | 持久投递转移与接纳通知，失败不回滚业务 |
| `Module` | src/UserFiles/Editor/Module.php:21 |  | 3 / 3 | 1 / 10 | 4 | 仅在开关开启时接入两个写作屏幕 |
| `Module` | src/UserFiles/Module.php:33 |  | 11 / 11 | 3 / 44 | 7 | 装配文件空间的服务图并向各方注入依赖 |
| `Notifications` | src/UserFiles/Repositories/Notifications.php:23 |  | 7 / 7 | 4 / 4 |  | 按请求与状态合并至少一次通知投递 |
| `Notifier` | src/UserFiles/Services/Notifier.php:20 |  | 6 / 7 | 6 / 2 |  | 尽力投递站内通知，失败时保留待发记录 |
| `Page` | src/UserFiles/Admin/Page.php:25 |  | 7 / 7 | 1 / 16 | 4 | 服务端渲染文件管理页外壳，表格由 sufm/v1 脚本水合 |
| `Picker` | src/UserFiles/Editor/Picker.php:32 |  | 1 / 1 | 1 / 3 |  | 在页脚一次性输出文件选择面板 |
| `Picker_Controller` | src/UserFiles/Rest/Picker_Controller.php:30 |  | 5 / 8 | 1 / 11 |  | 提供按文件夹过滤的附件选择 REST 接口 |
| `Provider` | src/UserFiles/Frontend/Provider.php:24 |  | 6 / 6 | 4 / 2 |  | 定义前端挂载用户文件空间的方式 |
| `Quota` | src/UserFiles/Services/Quota.php:28 |  | 8 / 8 | 15 / 9 |  | 以租约与短事务维护文件空间配额 |
| `Rate_Limiter` | src/UserFiles/Services/Rate_Limiter.php:23 |  | 4 / 4 | 4 / 6 |  | 用独立数据表限制每日与每分钟请求 |
| `Requests` | src/UserFiles/Repositories/Requests.php:28 |  | 20 / 28 | 13 / 15 |  | 驱动文件请求状态机并冻结请求项 |
| `Schema` | src/UserFiles/Schema.php:24 |  | 5 / 7 | 38 / 2 |  | 创建并升级 sufm 数据表结构与版本标记 |
| `Settings` | src/UserFiles/Settings.php:21 |  | 14 / 16 | 33 / 4 |  | 读取并收敛 sufm_settings 与用户配额覆盖 |
| `Space_Page` | src/UserFiles/Admin/Space_Page.php:27 |  | 5 / 6 | 1 / 22 | 5 | 注册 Zibll 标签页的设置组、资源与视图 |
| `Space_Panel` | src/UserFiles/Space_Panel.php:23 |  | 10 / 11 | 2 / 10 | 3 | 服务端渲染前端空间面板并加载资源 |
| `Space_Rules` | src/UserFiles/Services/Space_Rules.php:24 |  | 8 / 13 | 12 / 6 |  | 汇总配额公式、发放聚合与请求级缓存 |
| `Space_Settings` | src/UserFiles/Space_Settings.php:20 |  | 14 / 30 | 15 / 0 |  | 为扩容选项提供默认值、读取与清洗 |
| `Space_Store` | src/UserFiles/Services/Space_Store.php:22 |  | 40 / 59 | 11 / 9 | 2 | 管理空间套餐、购买与发放记录 |
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
| `Uploader::handle` | `src/UserFiles/Services/Uploader.php:109` | 上传全链路：校验→预留→落盘→记账→提交 | ⇐ `Controller::uploads`（`:1123`）；依赖 Quota/Files/Rate_Limiter |
| `Quota::reserve` | `src/UserFiles/Services/Quota.php:168` | 锁 usage 行 + 全站预算锁，写 reservations | ⇐ `Uploader.php:181` |
| `Quota::commit` | `src/UserFiles/Services/Quota.php:336` | reserved→used，条件 UPDATE 防超卖 | ⇐ `Uploader.php:370` |
| `Requests::create` | `src/UserFiles/Repositories/Requests.php:222` | 建申请、写 items、CAS 锁 files/folders | ⇐ `POST /delete-requests` |
| `Requests::approve` / `revoke` / `stop` | `src/UserFiles/Repositories/Requests.php:738` / `:840` / `:887` | 状态 CAS 迁移、解冻/解锁 | ⇐ review/revoke 路由、`Executor::stop`（`:332`） |
| `Executor::confirm` | `src/UserFiles/Services/Executor.php:154` | 校验口令与预览哈希，建 batch+job | ⇐ `POST /admin/delete-batches` |
| `Executor::run` | `src/UserFiles/Services/Executor.php:441` | 逐项 claim/删除/回写 items 与批次计数 | ⇐ tick→claim（`:389`） |
| `Files::create` | `src/UserFiles/Repositories/Files.php:348` | 写 files 账本并同步 token 索引 | ⇐ `Uploader.php:322`、`Sync_Service::adopt`（`:267`） |
| `Folders::write` | `src/UserFiles/Repositories/Folders.php:756` | usage 行锁 + 死锁重试的树写事务入口 | ⇐ create/rename/move/delete/reorder（`:740-744`） |
| `Space_Store::grant_from_order` | `src/UserFiles/Services/Space_Store.php:861` | 订单→space_grants 幂等发放；按渠道写 `source/channel` 与订单字段 | ⇐ `on_payment_success`（`:800`）、`reconcile`（`:2017`） |
| `Space_Store::filter_payment_methods` | `src/UserFiles/Services/Space_Store.php:248` | 按 `sufm_space_allowed_payment_methods` 白名单过滤主题返回的支付方式 | ⇐ `payment_methods`（`:195`）；空结果关闭现金收银台 |
| `Space_Store::on_order_refunded` | `src/UserFiles/Services/Space_Store.php:1248` | 退款：先占位 `refund_pending`，再按渠道补偿钱包 | ⇐ `order_refunded`（`:2541`） |
| `Space_Store::retry_refunds` | `src/UserFiles/Services/Space_Store.php:1652` | 重试 `refund_pending`，条件 UPDATE 抢占订单 | ⇐ `tick`（`:2394`） |
| `Space_Store::reconcile` | `src/UserFiles/Services/Space_Store.php:2017` | 补发 paid-no-grant 订单；每日自动或手动 REST | ⇐ `maybe_reconcile`（`:1811`）、`POST /admin/space/reconcile` |
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
| `payment_order_success` / `order_refunded` | action | `src/UserFiles/Space_Panel.php:40`、`Space_Store.php:2541` |

### 8.5 REST（`sufm/v1`，45 条路由 / 51 个端点）

命名空间注册见 `src/UserFiles/Rest/Controller.php:39`，路径集中在 `:550-607`，空间套餐由 `register_space_routes` 单独注册（`:48-148`）。按权限门分五组：

| 权限门 | 端点（节选） | 判定函数 |
|---|---|---|
| `can_use_files`（自助） | GET `/summary` `/capacity` `/items` `/folders` `/upload-policy` `/delete-requests` `/operations/{id}`；POST `/uploads` `/folders` `/delete-requests` `/batch`；PATCH `/files/{id}` `/folders/{id}` | `Controller.php:614-621` |
| `can_review`（审核） | GET `/admin/delete-requests`；POST `/admin/delete-requests/{id}/review`、`/{id}/revoke` | `:628-630` |
| `can_execute`（执行） | POST `/admin/delete-batches/preview`、`/admin/delete-batches`、`/{id}/stop`、`/admin/reconcile`、`/admin/reset-quotas`、`/admin/sync`；GET `/admin/delete-batches/{id}`、`/admin/users`、`/admin/events`；PATCH `/admin/users/{id}/quota` | `:637-639` |
| `can_manage_media` | GET/POST `/admin/folders`；PATCH/DELETE `/admin/folders/{id}`；POST `/admin/folders/move`、`/reorder`、`/admin/transfers/preview`、`/admin/transfers`；GET `/admin/transfers/{id}` | `:1566` |
| 空间（`space_can_use` / `space_can_review` / `space_can_manage`） | GET `/space/plans` `/space/grants`；POST `/space/orders` `/space/redeem`；GET `/space/orders/{num}/status`；GET/POST `/admin/space/grants`、POST `/{id}/revoke` `/extend` `/admin/space/reconcile` | `:158`、`:166`、`:186` |
| Picker（`can_pick`） | GET `/picker/items` `/picker/item` | `src/UserFiles/Rest/Picker_Controller.php:72-114` |

> Picker 的判定是「登录 && MANAGE_OWN && editor_enabled」，**不查 `entry_enabled`**（`Picker_Controller.php:107-108`）——这是它与自助组的唯一差异。实测 REST 共注册 45 条路由、51 个端点（§10.4）。

### 8.6 数据落点

- **option**：`sufm_settings`（`Settings.php:26`）、`sufm_schema_version`（`Schema.php:34`，当前 `1.6.0`）、`sufm_space_settings`（`Space_Settings.php:25`）、`sufm_sync_state`（`Sync_Service.php:31`）、`sufm_backfill_pending` / `sufm_tokens_pending`（`Schema.php:107` / `:117`）、`sufm_media_caps_version`（`Capabilities.php:120`）、`sufm_space_reconcile_last`（`Space_Store.php:49`，每日对账水位）；主题侧读 `zibll_options.brush_limit_upload`（`Theme_Limits.php:27`）。
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

### 8.9 空间扩容支付收口（2026-11-08 修复）

**结论：原 §8.9 的高优先缺陷已修复，并按「只走主题余额/积分」的口径收口支付入口。** 订单发放现在按实际支付渠道写 `source/channel` 与订单字段；余额是唯一默认开放的支付方式；退款按渠道补偿钱包；每日对账补发 paid-no-grant；面板在现金或积分任一不可用时仍能给出可用入口。

> 历史结论（2026-11-08 修复前）：`grant_from_order()` 把 `source`/`channel` 固定写 `'points'`，`order_id`/`order_num`/`price`/`points` 清零，现金购买会被记成积分兑换并占用 `points_used_bytes()` 日限额。该缺陷已在本次修复中关闭；下面的「修复前」引用只用于追溯。

#### 8.9.1 支付入口为什么收口

主题 Zibll 的所有支付方式（余额、支付宝、微信、积分）最终都走同一个 `ZibPay::payment_order()`，成功后统一 `do_action('payment_order_success', $order)`（`wp-content/themes/zibll/zibpay/class/order-class.php:916`）。插件无法只靠钩子区分「余额」与「外部扫码」；如果继续把主题返回的全部方式暴露在空间面板，就等于让空间订单直接生成支付宝/微信订单。

本次按用户确认的折中方案落地：**空间扩容只默认开放余额；用户先通过主题充值余额，再用余额购买空间。** 外部扫码不是被删除，而是变成显式 opt-in：站点所有者必须用过滤器主动放开，并自行承担该渠道的退款与对账责任。

#### 8.9.2 支付入口白名单

| 位置 | 行为 |
|---|---|
| `Space_Store::ALLOWED_METHODS`（`:44`） | 默认 `array( 'balance' )`；余额是同步扣款，空间面板不会生成外部支付二维码 |
| `Space_Store::payment_methods()`（`:195-236`） | 调用主题 `zibpay_get_payment_methods(21)` 后归一为 `id => [name]`，末尾交给 `filter_payment_methods()` |
| `Space_Store::filter_payment_methods()`（`:248`） | 读取 `apply_filters( 'sufm_space_allowed_payment_methods', self::ALLOWED_METHODS )`；非数组回退默认值；逐项 `sanitize_key` 后与主题返回的 map 取交集，保留主题的名称 |
| `Space_Store::purchase()`（`:291`） | 用过滤后的 map 做服务端硬校验；不在 map 内的 method 返回 `sufm_space_method_missing`，不会到达 `zibpay_initiate_pay()` |

**放开外部渠道的方式**（明确写在代码注释与 ADR 中）：

```php
add_filter( 'sufm_space_allowed_payment_methods', function ( $methods ) {
    $methods[] = 'alipay';
    return $methods;
} );
```

过滤器返回空数组等于关闭现金收银台；面板会按 §8.9.6 的空态规则退回积分兑换。测试站实测：主题对 `order_type=21` 返回 `balance`；过滤后仍只有 `balance`，未出现扫码入口。

#### 8.9.3 订单发放记账

`normalise_order()`（`:979`）现在除 `order_id/order_num/user_id/product_id/price/channel/plan_id/label/bytes/days` 外，还解析 `points` 与 `pay_detail`：`points` 优先取 `other.points`，其次取 `pay_detail.points`；两者都没有且渠道是 `points` 时，用 `round(order_price)` 回退。`channel` 仍来自主题订单的 `pay_type`。

`grant_ledger_fields()`（`:826`）把归一化结果压成 6 个账本字段，`grant_from_order()`（`:861`）用它们参数化 INSERT（`:928-946`），不再写死字面量：

| 订单渠道 | `source` | `channel` | `order_id` / `order_num` | `price` | `points` |
|---|---|---|---|---|---|
| `balance` | `purchase` | `balance` | 保留 | `order_price` | 0 |
| `points`（直兑/主题积分单） | `points` | `points` | 保留 | 0 | 实际扣减积分 |
| 其他（仅 filter 放开时） | `purchase` | `sanitize_key(pay_type)`，截 24 | 保留 | `order_price` | 0 |

`meta`（`:907-916`）同步保留 `plan_id`、`order_num`、`order_id`、`channel`、`source`、`points`；`grant_key` 仍是 `order:` + `order_num`，`INSERT IGNORE` + 唯一键保证同一订单只发一次。

**防重复发放保护仍然有效**：积分直兑会在 `redeem_points()` 里创建 `product_id='sufm_space_points'` 的订单并走主题支付，因而也会触发 `payment_order_success`；`grant_from_order()` 对 `plan('points')` 不存在且 `bytes=0` 的订单返回 `false`，不会重复发放。改动这段时必须保留该断言。

**历史数据不回填**：修复只影响新写入。历史 `order:` 行若已是 `purchase/balance` 且字段齐全则无需处理；历史 `points:` 行中订单字段为 0 的记录仍保持原样。若要做批量回填，应以 `meta.order_num` 反查订单后逐行核对，不能只按 `source='points'` 改。

#### 8.9.4 退款补偿

退款入口是主题 `ZibPay::refund_order()` 发出的 `order_refunded`（参数是订单表主键，不是订单号）。`Space_Store::register_hooks()`（`:2539`）以优先级 12 挂 `handle_order_refunded()`，转 `on_order_refunded()`（`:1248`）。

```text
order_refunded(order_id)
  → SELECT id/order_num/order_type/user_id/order_price/pay_type/product_id/other/pay_detail
  → 只认 order_type=21
  → UPDATE state='refund_pending' WHERE order_num AND state IN ('active','pending')   ← 原子占位
  → 0 行：直接返回（重复回调/已退款/已撤销）
  → 取 owners + SUM(bytes)，先 Space_Rules::flush()（配额立即下降）
  → settle_refund()
       balance → zibpay_update_user_balance(+order_price, type=space_expand_refund)
       points  → zibpay_update_user_points(+points，缺失时 round(price))
       其他    → 只释放授权，不代付
  → 成功：state='refunded'；失败：fail_refund() attempts+1，第 5 次 state='refund_failed'
```

| 位置 | 作用 |
|---|---|
| `on_order_refunded()`（`:1248`） | 订单读取 + `refund_pending` 占位；先 flush 配额，再尝试钱包补偿 |
| `settle_refund()`（`:1387`） | 按 `wallet_refund_plan()` 补偿；成功把 `refund_pending` 行置 `refunded` |
| `wallet_refund_plan()`（`:1576`） | `balance` → `order_price`；`points` → `points`（缺失时 `round(price)`）；其他 → `external/0` |
| `wallet_refund_recorded()`（`:1612`） | 读主题 `balance_record` / `points_record`，同 `order_num` + `type='space_expand_refund'` + `value>0` 则视为已退，防崩溃窗口重复加钱 |
| `fail_refund()`（`:1502`） | 逐行 `attempts+1`；满 5 次置 `refund_failed` 并记 `space.grant.refund_failed` |
| `retry_refunds()`（`:1652`） | 每 60 秒挑 `refund_pending` 且 `attempts<5`；先用条件 UPDATE 抢占订单，再调 `settle_refund()` |
| `state_label()`（`:1157`） | `refund_pending` → 退款处理中；`refund_failed` → 退款失败 |

`wallet_refund_recorded()` 必须优先用主题的 `zib_get_user_meta()`，不能用普通 `get_user_meta()`：Zibll 把这些记录存在主题自己的 meta 路由里（`wp-content/themes/zibll/inc/dependent.php:328-346`），普通读取在真实站点返回空，崩溃窗口保护会失效。这是本轮运行验证中发现并修正的问题。

**重试的并发边界**：`retry_refunds()` 先执行 `UPDATE ... SET updated_at=now WHERE order_num=... AND state='refund_pending' AND updated_at <= threshold`，只有影响行数大于 0 才继续；两个 cron worker 同时选中同一订单时，后到者拿到 0 行并跳过。崩溃后 `updated_at` 已前移，最多等 60 秒再试。

#### 8.9.5 每日对账

`maybe_reconcile()`（`:1811`）由 `tick()` 调用，用 option `sufm_space_reconcile_last`（`:49`）做 24 小时水位；到期后调用 `reconcile( true, 100 )`（`:2017`）补发「订单已支付、但没有 grant」的订单。`reconcile()` 的 SQL 仍是 `LEFT JOIN` + `order_type=21 AND status=1 AND g.id IS NULL`，`ready=false` 或 `missing > healed` 时记 `space.reconcile.alert`（带 missing/healed/error），正常补发记 `space.reconcile.healed`。

手动入口保留：`POST /admin/space/reconcile` 仍可按需 dry-run 或 apply。自动对账不新增 cron 钩子，只挂在原有 `sufm_tick` 上。`sufm_space_reconcile_last` 已加入 `Uninstaller::derived_options()`（`src/Uninstaller.php:186`），卸载时会清理。

边界：`maybe_reconcile()` 先写时间戳再对账；如果对账本身失败，要等 24 小时才会自动重试。需要立即重试时走手动 REST。

#### 8.9.6 面板空态

`Space_Panel::build_data()`（`:112`）先取 `$plans/$methods/$points_state/$points_plans`，再计算：

| 标志 | 计算 | 位置 |
|---|---|---|
| `cash_ready` | 现金套餐非空 **且** 过滤后的支付方式非空 | `:137` |
| `points_ready` | 积分规则开启（`points_state['enabled']`）**且** 至少一个可售积分套餐 | `:141` |

两者都 false 才返回 `null`（整个面板不渲染）；否则把两个标志交给模板。模板与脚本的行为：

- 现金套餐、余额支付方式、余额钱包只在 `cash_ready` 时显示；没有现金方式但有积分时显示提示「余额支付暂未开启，可使用积分兑换。」。
- 积分套餐、积分面板、兑换按钮只在 `points_ready` 时显示；没有现金套餐或没有支付方式时，默认选中积分 radio。
- `assets/userfiles-space.js` 的 `openDialog()` 焦点回退顺序改为现金套餐 → 积分套餐 → 支付方式 → `data-sufm-space-redeem-gb`；`confirmPurchase()` 在 `pointsMode()` 为真时直接返回，积分模式不会误建现金订单。

测试站四态实测（`Space_Panel::data()`/`markup()`，临时写 `sufm_space_settings` 后已恢复）：

| 场景 | `cash_ready` | `points_ready` | 渲染结果 |
|---|---|---|---|
| 现金 + 积分 | true | true | 8251 字节；2 个支付 radio；默认 balance；现金与积分套餐都在 |
| 仅积分 | false | true | 6408 字节；1 个积分 radio 且默认选中；现金区隐藏；提示出现 |
| 仅现金 | true | false | 6195 字节；1 个余额 radio；积分区隐藏 |
| 都不可用 | false | false | `data() === null`，`markup()` 空串 |

**积分开关的边界**：`points_state()`（`:2575`）把 `enabled` 定义为 `per_gb > 0`，并不读取 `Space_Settings::points()['enabled']`；后台也没有独立关闭积分的 UI。因此 `points_ready` 实际由「后台积分单价 > 0 + 至少一个可售积分套餐」决定。要关闭积分入口，应清空积分套餐或把单价设为 0；不要只改 `points.enabled`。

#### 8.9.7 运行验证

- **余额退款**：测试站创建余额订单 + grant，触发 `ZibPay::refund_order()`；`balance` 100→130（+30），`state=refunded`；重复回调余额不再变化，`balance_record` 命中 1 条。
- **积分退款**：积分订单 90 分；退款后 `points` 520→610（+90），`balance` 不变，`points_record` 命中 1 条。
- **崩溃窗口**：先手工写入同 `order_num` 的 `space_expand_refund` 余额记录，再触发回调；`state=refunded`，余额不再增加，证明 `wallet_refund_recorded()` 生效。
- **外部渠道**：`alipay` 订单只把 grant 置 `refunded`，`balance`/`points` 均无变化。
- **失败重试**：`user_id=0` 首退得到 `refund_pending/attempts=1/error=user_missing`；恢复用户并把 `updated_at` 调旧后 `retry_refunds(20)` 返回 1，最终 `refunded/attempts=2`，余额 +30。
- **连续失败**：`attempts` 2→3→4 保持 `refund_pending`，第 5 次置 `refund_failed`，`error=user_missing`。
- **退款重试占位**：fresh `refund_pending`（`updated_at=now`）不被抢占，`retry_refunds` 返回 0、余额不变；180 秒前的 stale 记录返回 1、余额 +30、`state=refunded/attempts=1`；第二次重试返回 0、余额不再增加。完整探针见 §10.12。
- **每日对账**：有效 paid 单但没有 grant + 无效 `sufm_space_zzz/bytes=0` 单；删 option 后 `tick()` 给有效单补发、无效单不补，写入时间戳，并产生 `space.reconcile.alert` 1 条。
- **单测**：`phpunit --filter 'SpaceStoreRefundTest|SpaceStorePaymentTest|SpaceRulesTest'` = OK（20 tests / 69 assertions）；`phpstan` 对 `Space_Store.php` 无错误。

测试后清理：`t_sufm_space_grants` 0 行、`t_zibpay_order` 0 行、临时用户删除、`sufm_space_settings` 与 `sufm_space_reconcile_last` 均恢复为测试前状态。

#### 8.9.8 边界与维护要求

1. **预充值余额仍承担资金责任**：充值入口仍是主题的支付宝/微信，站点仍是收款方；退款、发票、消费者权益与资金合规不因「空间只收余额」而消失。本改动只把「空间订单的直接外部支付入口」收口，不等于零风险。
2. **外部渠道不代付退款**：若站点用 filter 放开 `alipay`/`wechat`，插件退款只释放授权，钱包不会加钱；外部退款必须在主题/支付平台完成。
3. **主题钱包历史只有 50 条且无唯一键**：`wallet_refund_recorded()` 的查重可能因记录被挤出而失效；这是主题数据结构的硬边界，不能当成强幂等。
4. **`refund_failed` 不会自动复活**：满 5 次后停在 `refund_failed`，需要人工核对后修正订单/用户并手工处理；没有后台一键重开。
5. **历史 grant 未回填**：修复只影响新订单；历史 `points/0` 行仍是旧形态。
6. **积分没有独立开关**：见 §8.9.6；关闭积分入口要清空积分套餐或把单价设为 0。
7. **改这段代码的固定动作**：更新 `tests/Services/SpaceStorePaymentTest.php` 与 `SpaceStoreRefundTest.php`；跑 `phpunit`、`phpstan`、`phpcs`；按《图谱同步说明》重扫图谱；同步更新本节的代码行号与 `DECISIONS.md` 的 ADR。

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
| `sio_sync_operations` | S3 / Sync | 远端变更准入记录 | `src/S3/Remote_Operation_Guard.php:26-39` |
| `sio_s3_deletes` | S3 | 远端删除补偿队列 | `src/S3/Delete_Jobs.php:109` |
| `sio_s3_upload_events` | S3 | 上传结果事件（成功/失败逐条追加，快照按 SQL 聚合） | `src/S3/Upload_Metrics.php:79-115` |

> 表名在源码里以 `$wpdb->prefix . 'sufm_files'` 形式拼接（`src/UserFiles/Schema.php:41`），前缀随站点变化；测试站前缀为 `t_`。

**非表落点**（分章详见 §4.5 / §5.5 / §6.5 / §7.5 / §8.6）：option 约 60 个、postmeta 约 13 个（8 个 `_sakura_imgopt_s3_*` + `_sakura_imgopt_backup` / `sakura_imgopt_log` / `_sakura_imgopt_orig` + `sufm_quota_bytes` + 只读 `_wp_attached_file`）、transient 7 个、上传目录 1 个子目录（`sakura-imgopt-backup/`）。

### 9.2 钩子分布（191 条）

| 模块 | 钩子数 | 说明 |
|---|---:|---|
| `includes/` | 68 | 旧层：图片门面 7 + 缩略图装配 8 + S3 设置页 9 + 媒体库/设置页 AJAX 等 |
| `src/UserFiles` | 58 | 最大；REST 只注册 1 个 `rest_api_init`，其余是 cron、媒体库、Zibll、支付 |
| `src/S3` | 43 | admin-post 7 + ajax 11 + 附件 3 + cron 2 + 同步中心 9 + 其余（含新增失败观测） |
| `src/Sync` | 9 | 3 个附件钩子 + 4 个 cron + `cron_schedules` + `init` |
| `src/Admin` | 6 | `admin_menu` / `admin_enqueue_scripts` / `admin_init` 等 |
| `src/Support` | 2 | 工具类注册 |
| `src/Thumbnail` | 1 | 只有主题缓存桥（`the_post`） |
| `src/`（根） | 1 | `sakura_imgopt_daily_backup_cleanup`（`src/Plugin.php:106`） |
| 未归属（模板/函数） | 3 | `includes/views/` 或全局函数内注册 |

完整 191 条见**附录 B**（按模块分组，含文件:行、优先级、回调）。

### 9.3 REST、CLI 与 Cron

**REST**：只有 `sufm/v1` 一个命名空间，实测 **45 条路由 / 51 个端点**（§10.4）；分组见 §8.5。S3 与 Sync **不提供 REST**，全部走 admin-ajax / admin-post。

**WP-CLI**（仅 `WP_CLI` 时加载）：

| 命令 | 注册位置 | 用途 |
|---|---|---|
| `wp sakura-sync …` | `src/Plugin.php:96-102` → `includes/class-s3-cli.php` | 扫描/索引/校验/对账 |
| `wp sakura-thumb …` | 同上 | 缩略图探测与批量 |
| `wp sakura-s3 …` | `includes/class-s3-cli.php:501` | 连接测试、迁移、删除队列 |
| `wp sufm …` | `src/UserFiles/CLI.php:23` | 诊断、对账、导入 |

**Cron（实测 7 条插件事件，§10.3）**：

| 事件 | 周期 | 调度位置 |
|---|---|---|
| `sufm_tick` | 1 分钟 | `src/UserFiles/Module.php:293-300` |
| `sufm_delete_tick` | 单次（按需） | `src/UserFiles/Services/Executor.php:269` |
| `sakura_imgopt_s3_delete_tick` | 1 分钟 | `src/S3/Delete_Worker.php:46-51` |
| `sakura_imgopt_sync_verify_tick` | 5 分钟 | `src/Sync/Verify_Worker.php:76` |
| `sakura_imgopt_s3_alert_check` | 15 分钟 | `src/S3/Alerting.php:364-370` |
| `sakura_imgopt_sync_cleanup` / `_daily_reconcile` | 1 天 | `src/Sync/Scan_Worker.php:79-80` |
| `sakura_imgopt_daily_backup_cleanup` | 1 天 | `src/Plugin.php:106` |

> `sufm_delete_tick` 在实测里显示为 `now`：它是"确认执行时调度一次"的单次事件（`Executor::confirm` → `:269`），不是周期任务。

---

## 10. 运行时验证记录

**边界声明**：生产环境为**只读验证**——只执行查询、纯函数调用与 GET 请求，没有触发任何写路径。日常命令都在测试站 `/root/sio-e2e` 执行，插件以软链挂载到 `/root/work/sakura-tools`（同一份代码）。测试站上另补做了一次写验证：2026-11-08 的 5 张并发上传（§10.6），数据只落在测试站自己的 MariaDB 与 MinIO，未触及生产。

### 10.1 环境与激活状态

```
$ cd /root/sio-e2e && ./e2e.sh wp plugin get sakura-tools --fields=name,status,version
name:      sakura-tools
status:    active
version:   1.22.8
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

后台 11 条路由（GET，带管理员 cookie）：系统 overview、图片 rules/thumb/backup/stats、存储 connection/sync/deletes、文件空间 settings/users/queue —— **全部 HTTP 200、标题与 tab 名一致、无 PHP fatal/warning**。其中一次请求命中了旧 slug 跳转（`page=sakura-tools-optimize` → 新路由），与 §3.5 的 `legacy()` 映射一致。

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

**未验证（受只读边界所限）**：配额扣减（`Quota::commit`）、删除审核（`Requests::approve` → `Executor::run`）、卸载清理（`Uninstaller`）。正文中凡涉及这三条路径的描述均为**静态阅读**。上传 → S3 PUT 已在测试站跑通（§10.6），但生产环境从未执行。

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
| 5 | 同步中心页面 | GET `/wp-admin/admin.php?page=sakura-tools-storage&t=sync` | HTTP 200；240,612 字节；标题 `对象存储 ‹ SIO E2E (manual)`；含 `<div class="sakura-sync-app" data-scan-id="0" data-scan-state="" data-index-enabled="1">`；加载 `assets/admin.js?ver=1.22.8` |
| 6 | 前端配置 | 读取页面内联 `sakuraSyncCenter` | `ajaxUrl=http://127.0.0.1:18080/wp-admin/admin-ajax.php`、`searchUrl=.../sakura-tools/fast-search.php`；**没有 `restUrl`** |
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

---

## 11. 依赖关系与图谱口径

### 11.1 模块依赖矩阵（类级引用边计数）

| 从 / 到 | UserFiles | S3 | includes | Sync | includes/views | Thumbnail | Image | Admin | src | Oss | Backup | Contracts | 根文件 | Settings | Support | Filesystem | Stats |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| UserFiles |  |  |  |  |  |  |  | 10 |  |  |  |  |  |  | 14 |  |  |
| S3 |  |  | 8 | 33 |  |  |  | 20 |  |  |  | 6 |  | 6 | 9 |  |  |
| includes |  | 40 |  | 4 |  | 10 | 22 | 14 |  | 1 | 7 | 17 |  | 9 | 6 | 3 | 5 |
| Sync |  | 34 |  |  |  |  |  |  |  |  |  | 4 |  |  | 4 |  |  |
| includes/views |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Thumbnail |  | 1 |  |  |  |  |  |  |  | 2 |  |  |  |  |  |  |  |
| Image |  |  | 9 |  |  |  |  |  |  |  |  | 22 |  | 9 |  | 2 |  |
| Admin | 9 |  |  |  |  |  |  |  |  |  |  | 3 |  |  | 3 |  |  |
| src | 7 | 24 | 10 | 20 |  | 1 |  | 7 |  |  | 1 |  |  | 2 | 3 | 1 | 1 |
| Oss |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Backup |  |  | 4 |  |  |  |  |  |  |  |  | 1 |  |  |  |  |  |
| Contracts |  |  |  |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |
| 根文件 |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Settings |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |  |
| Support |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| Filesystem |  |  | 2 |  |  |  |  |  |  |  |  | 1 |  |  |  |  |  |
| Stats |  |  |  |  |  |  |  |  |  |  |  | 1 |  |  |  |  |  |

**三个可直接用于判断改动风险的读法**：

1. **`includes/` 是"最外向"的一层**：138 条出边散向 12 个模块，其中 40 条指向 `src/S3`、22 条指向 `src/Image`。改 `src/S3` 的公开方法签名时，**必须**同步检查 `includes/` 的调用点。
2. **`src/UserFiles` 高度自包含**：只有 10 条边指向 `src/Admin`、14 条指向 `src/Support`，没有指向 S3/Sync/Thumbnail 的边——它与其它子系统的接缝全在运行时（postmeta、钩子、REST），不在静态类型上。这也解释了它为什么能独立演进。
3. **`src/S3 → src/Sync` 33 条边是全图最粗的一条**：同步中心复用 `Index_Repository` 做队列与分页；而 `src/Sync` 反向依赖 `src/S3` 34 条，主要是 `Remote_Operation_Guard`。**这两个模块事实上是一个整体**，改动时按一个单元评估。

### 11.2 图谱节点的定义

| 节点类型 | 数量 | id 规则 | 大小 | 颜色 |
|---|---:|---|---|---|
| 模块 | 17 | `mod:<模块路径>` | 按文件数线性缩放 | 固定色板 17 色 |
| 类/接口 | 163 | `cls:<FQCN>` | 按方法总数缩放 | 跟随所属模块 |

| 边类型 | 权重字段 | 含义 |
|---|---|---|
| `contains` | 固定 1 | 模块 → 类（归属） |
| `ref` | 引用次数 | 类 → 类（静态引用） |
| `modref` | 跨模块边数 | 模块 → 模块（矩阵的连线形态） |

其余口径：

- **入度/出度**：只统计 **token 级静态引用**（`use` / `new` / 静态调用 / 类型声明），不是运行时调用次数；"钩子"列单独统计 `add_action` / `add_filter`。
- **布局是确定性的**：模块中心按斐波那契球面分布在半径 1450 的球面，模块内类节点以 `spread = 70 + min(190, 文件数 × 4.2)` 散布，整体 `radius = 1710`。同一份扫描数据每次生成的坐标完全一致（无随机种子），便于 diff。
- **不进入图谱的对象**：64 个全局函数、12 个视图模板、`fast-search.php` / `uninstall.php` 等脚本型文件。它们只在附录 C（文件索引）与正文中出现。
- **悬空边检查**：生成器校验 `links` 两端 id 都存在，当前 **0 条悬空边**（早期一版因用了扫描原始 id 产生过 762 条悬空边，已修复）。

### 11.3 全图最粗的 10 条类级边

| from | to | 类型 | 次数 | 证据行 |
|---|---|---|---:|---|
| `Scan_Service` | `Index_Repository` | 调用 | 45 | src/Sync/Scan_Service.php:137,151 |
| `Storage` | `Options` | 调用 | 25 | src/S3/Storage.php:108,110 |
| `Sync_Center_Module` | `Index_Repository` | 调用 | 25 | src/S3/Sync_Center_Module.php:150,397 |
| `Executor` | `Time` | 静态调用 | 25 | src/UserFiles/Services/Executor.php:174,242 |
| `Executor` | `Time` | 调用 | 25 | src/UserFiles/Services/Executor.php:174,242 |
| `Media_Hooks` | `Requests` | 静态调用 | 24 | src/UserFiles/Media/Media_Hooks.php:290,291 |
| `Requests` | `Schema` | 静态调用 | 24 | src/UserFiles/Repositories/Requests.php:86,123 |
| `Requests` | `Schema` | 调用 | 24 | src/UserFiles/Repositories/Requests.php:86,123 |
| `Folders` | `Schema` | 静态调用 | 23 | src/UserFiles/Repositories/Folders.php:49,71 |
| `Folders` | `Schema` | 调用 | 23 | src/UserFiles/Repositories/Folders.php:49,71 |

---

## 12. 待确认项与已确认缺陷

截至 2026-11-08，正文没有「只有结论、没有代码位置或运行证据」的待确认项，也没有未修复的高/中优先代码缺陷。原 §12 的三项均已核实；其中 `grant_from_order()` 的高优先缺陷已在本轮按「支付入口收口」方案修复并验证，S3 上传指标缺陷也已在此前修复。下面保留的是有意接受的边界，不是待确认项。

### 12.1 本轮关闭：空间扩容支付收口

| 问题 | 修复前 | 修复后 | 验证 |
|---|---|---|---|
| 订单发放把余额/现金单写成积分单，订单字段清零 | `grant_from_order()` 的 INSERT 固定写 `'points','points',0,'',0,0`；`source='points'` 会把现金购买计入 `points_used_bytes()` 日限额 | `normalise_order()` 解析 `points/pay_detail`；新增 `grant_ledger_fields()`；INSERT 参数化 `source/channel/order_id/order_num/price/points`（`src/UserFiles/Services/Space_Store.php:826`、`:861`、`:928-946`） | 余额单写 `purchase/balance` 且订单字段齐全；积分单写 `points/points` 且积分保留；`SpaceStorePaymentTest` 7 tests 覆盖两类渠道与缺失积分回退 |
| 空间面板暴露主题的全部支付方式，等于可生成外部扫码单 | `payment_methods()` 原样返回主题方法 | 默认 `ALLOWED_METHODS = ['balance']`；`filter_payment_methods()` 按 `sufm_space_allowed_payment_methods` 过滤；`purchase()` 用过滤后的 map 硬校验（`Space_Store.php:44`、`:195`、`:248`、`:291`） | 测试站过滤后只有 `balance`；过滤器返回空数组时现金收银台关闭，面板退回积分 |
| 退款只把 grant 置 `refunded`，不退余额/积分 | `on_order_refunded()` 只 UPDATE 状态，不区分 `pay_type` | 先原子占位 `refund_pending`，再释放配额，按渠道 `settle_refund()`；余额加 `order_price`、积分加 `points`，外部渠道只释放；失败重试 5 次（`Space_Store.php:1248`、`:1387`、`:1502`、`:1652`） | 测试站余额 100→130、积分 520→610；重复回调与崩溃窗口不重复加钱；`user_missing` 重试后 `refunded/attempts=2`；第 5 次置 `refund_failed` |
| paid-no-grant 只能手工对账 | `tick()` 注释明确「Reconcile deliberately stays manual」 | `maybe_reconcile()` 用 `sufm_space_reconcile_last` 做 24 小时水位，自动 `reconcile(true,100)`；`ready=false` 或 `missing>healed` 记 `space.reconcile.alert`（`:1811`、`:2017`） | 测试站有效 paid 单补发、无效单不补，水位写入，`space.reconcile.alert` 1 条；手动 REST 保留 |
| 现金或积分任一不可用时面板空白或无法提交 | `build_data()` 在 `plans` 或 `methods` 为空时直接返回 `null`；无现金 plan 时积分 radio 不默认选中 | `cash_ready` / `points_ready` 分别判断（`src/UserFiles/Space_Panel.php:137`、`:141`）；模板按标志隐藏/显示；无现金时默认积分；`confirmPurchase()` 在积分模式直接返回 | 测试站四态：现金+积分 8251 字节、仅积分 6408、仅现金 6195、都不可用 `null`；临时 `sufm_space_settings` 已恢复 |

代码位置与完整运行记录见 §8.9 与 §10.12；决策理由见 `DECISIONS.md` 的「空间扩容支付收口（2026-11-08）」。

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
| 外部渠道退款不代付 | 若用 filter 放开 `alipay`/`wechat`，插件只释放授权，钱包不加钱 | 外部退款必须在主题/支付平台完成；文档与代码注释已标注 |
| 主题 `balance_record`/`points_record` 最多 50 条且无唯一键 | `wallet_refund_recorded()` 的崩溃窗口查重可能因记录被挤出而失效 | 这是弱幂等，不作为强一致保证；需要强幂等时应新增独立退款账本表 |
| `refund_failed` 不会自动复活 | 满 5 次失败后需要人工核对订单/用户并手工处理 | 保留 `refund_failed` 状态与事件日志；没有后台一键重开 |
| `maybe_reconcile()` 先写水位再对账 | 对账失败要等 24 小时才会自动重试 | 需要立即重试时走手动 `POST /admin/space/reconcile` |
| 历史 grant 未回填 | 旧 `points/0` 行仍是旧形态，新订单才按渠道记账 | 修复只影响新写入；批量回填应以 `meta.order_num` 反查订单后逐行核对 |
| 积分没有独立开关 | `points_state()` 的 `enabled` 实际是 `per_gb > 0`，后台没有独立关闭 UI | 关闭积分入口应清空积分套餐或把单价设为 0；见 §8.9.6 |

### 12.4 此前关闭的上传指标缺陷

| 原问题 | 修复前 | 修复后 | 验证 |
|---|---|---|---|
| 上传失败不进入指标，失败率与告警恒 0 | `Upload_Observer` 只挂 `sakura_imgopt_s3_uploaded`，样本固定 `ok=true`；`Object_Store` 失败分支不发事件 | 每条结果独立 INSERT 到 `sio_s3_upload_events`；`Storage_Adapter::report_failure()` 发 `sakura_imgopt_s3_upload_failed`，`Upload_Observer::record_failed()` 采集 | `phpunit` 393 tests / 1138 assertions / 5 skipped；4×200 并发 800/800；CLI、Prometheus、后台卡片均正常（§10.11） |
| 并发写指标互相覆盖 | 读改写 option，4×200 只落 202 行 | 原子 INSERT + `snapshot()` SQL 聚合；旧 option 保留为冻结基线 | 测试站 800/800；旧实现对照 202/800 |

### 12.5 图谱口径说明

《图谱同步说明》§10.3 记录的「181 vs 178 文件数」是旧导出排除规则未留档造成的**图谱口径差异**，与当前插件代码行为无关；当前图谱数字以 §13 的最新扫描为准。它保留在图谱文档的已知局限中，不计入本节。

---


## 13. 3D 图谱同步说明

图谱文件：`docs/graph-preview/index.html`（单文件，数据内嵌）+ `docs/graph-preview/graph.json`（同数据美化版）。不保存渲染截图。

| 项 | 本次结果 |
|---|---|
| 节点 | 180 = 163 类 + 17 模块（类集合未变） |
| 连线 | 814 = 601 `ref` + 50 `modref` + 163 `contains` |
| 钩子 | 191（面板显示，不建节点） |
| 悬空边 | 0 |
| 本轮变更 | 无节点/连线增删；`Space_Store` 的 `val` 9.7324→10.1193（方法数增加），`Space_Store → Space_Rules` 的 `ref` 权重 34→44，`Space_Panel → Space_Store` 的 `ref` 权重 12→10。已重扫并重建 `docs/graph-preview/` |
| 数据来源 | 本轮重新扫描的事实表（codegraph `scanner.php` 输出）——由 `docs/tools/collect-comments.php` 生成类注释，与本文所有表格同源 |

本轮没有新增/删除类，节点数仍是 180，连线仍是 814（601 `ref` + 50 `modref` + 163 `contains`），钩子仍是 191；`Space_Store` 新增支付白名单、退款补偿、退款重试与每日对账方法，方法总数上升，故节点的 `val` 从 9.7324 变为 10.1193；退款与重试路径对 `Space_Rules` 的静态引用从 34 增至 44；`Space_Panel` 改为共享 `points_state`/`points_plans` 后，直接引用 `Space_Store` 的次数从 12 降至 10。计数没有变化，但**不能沿用上一轮导出的节点大小与边权**，已重新生成。

`fast-search.php` 是脚本型文件，本来就不进入图谱；`Space_Store` 的字段缺陷已在 §8.9 修复，不再是未修复缺陷，本轮只更新该已有节点的大小与两条边权。

**生成与注入脚本**（都在 `docs/tools/`，可重复执行）：`build-code-structure.mjs`（事实表 → 表格 + graph.json）、`graph-inject.mjs`（graph.json → index.html 内嵌 DATA，含悬空边校验）、`assemble-doc.py`（分章 + 表格 → 本文）。完整命令见《图谱同步说明》§7。

**与文档的对应关系**：每个类节点在正文里都有一条同名记录——附录 A 给 `文件:行`，所属章节的表给职责与方法数；模块节点对应 §2.2 的模块总览。**改代码后重跑一次生成器，表格与图谱同时更新**，这是两者不脱节的机制保证。

详细的操作步骤、本次新增/修改的节点与连线清单、一致性核对项，见配套文档 **`docs/sakura-tools-图谱同步-1.22.8.md`**。

---

## 附录 A：类索引（163 类）

| # | 类 | 文件:行 | 方法数 |
|---:|---|---|---:|
| 1 | `Sakura\ImageOptimizer\Animation_Detector` | includes/class-animation-detector.php:20 | 5 |
| 2 | `Sakura\ImageOptimizer\Backup` | includes/class-backup.php:27 | 14 |
| 3 | `Sakura\ImageOptimizer\File_System` | includes/class-file-system.php:14 | 11 |
| 4 | `Sakura\ImageOptimizer\GD_Handler` | includes/class-gd-handler.php:16 | 5 |
| 5 | `Sakura\ImageOptimizer\Image_Optimizer_Module` | includes/class-image-optimizer-module.php:23 | 45 |
| 6 | `Sakura\ImageOptimizer\Image_Processor` | includes/class-image-processor.php:39 | 17 |
| 7 | `Sakura\ImageOptimizer\Imagick_Handler` | includes/class-imagick-handler.php:16 | 5 |
| 8 | `Sakura\ImageOptimizer\Media_Library_Module` | includes/class-media-library-module.php:30 | 28 |
| 9 | `Sakura\ImageOptimizer\S3_CLI` | includes/class-s3-cli.php:14 | 13 |
| 10 | `Sakura\ImageOptimizer\S3_Doge_Cloud` | includes/class-s3-doge-cloud.php:16 | 7 |
| 11 | `Sakura\ImageOptimizer\S3_Storage_Module` | includes/class-s3-storage-module.php:25 | 20 |
| 12 | `Sakura\ImageOptimizer\Stats` | includes/class-stats.php:15 | 9 |
| 13 | `Sakura\ImageOptimizer\Thumbnail_Manager` | includes/class-thumbnail-manager.php:20 | 6 |
| 14 | `Sakura\ImageOptimizer\Thumbnail_Optimizer_Module` | includes/class-thumbnail-optimizer-module.php:32 | 18 |
| 15 | `Sakura\ImageOptimizer\Admin\Assets` | src/Admin/Assets.php:17 | 10 |
| 16 | `Sakura\ImageOptimizer\Admin\Basic_Settings_Module` | src/Admin/Basic_Settings_Module.php:24 | 4 |
| 17 | `Sakura\ImageOptimizer\Admin\Environment_Report` | src/Admin/Environment_Report.php:21 | 11 |
| 18 | `Sakura\ImageOptimizer\Admin\Layout` | src/Admin/Layout.php:25 | 9 |
| 19 | `Sakura\ImageOptimizer\Admin\Menu` | src/Admin/Menu.php:23 | 6 |
| 20 | `Sakura\ImageOptimizer\Admin\Registry` | src/Admin/Registry.php:21 | 13 |
| 21 | `Sakura\ImageOptimizer\Admin\Shell` | src/Admin/Shell.php:20 | 3 |
| 22 | `Sakura\ImageOptimizer\Backup\Backup_Repository` | src/Backup/Backup_Repository.php:24 | 9 |
| 23 | `Sakura\ImageOptimizer\Backup\Exposure_Probe` | src/Backup/Exposure_Probe.php:23 | 10 |
| 24 | `Sakura\ImageOptimizer\Contracts\Backup_Repository_Interface` | src/Contracts/Backup_Repository_Interface.php:20 | 9 |
| 25 | `Sakura\ImageOptimizer\Contracts\File_System_Interface` | src/Contracts/File_System_Interface.php:17 | 7 |
| 26 | `Sakura\ImageOptimizer\Contracts\Module` | src/Contracts/Module.php:23 | 1 |
| 27 | `Sakura\ImageOptimizer\Contracts\Settings_Repository_Interface` | src/Contracts/Settings_Repository_Interface.php:17 | 18 |
| 28 | `Sakura\ImageOptimizer\Contracts\Stats_Repository_Interface` | src/Contracts/Stats_Repository_Interface.php:17 | 5 |
| 29 | `Sakura\ImageOptimizer\Filesystem\File_System_Repository` | src/Filesystem/File_System_Repository.php:20 | 7 |
| 30 | `Sakura\ImageOptimizer\Image\Crop_Config_Scanner` | src/Image/Crop_Config_Scanner.php:34 | 25 |
| 31 | `Sakura\ImageOptimizer\Image\Engine_Manager` | src/Image/Engine_Manager.php:23 | 1 |
| 32 | `Sakura\ImageOptimizer\Image\File_Processor` | src/Image/File_Processor.php:21 | 11 |
| 33 | `Sakura\ImageOptimizer\Image\Metadata_Processor` | src/Image/Metadata_Processor.php:25 | 9 |
| 34 | `Sakura\ImageOptimizer\Image\No_Gain_Guard` | src/Image/No_Gain_Guard.php:20 | 2 |
| 35 | `Sakura\ImageOptimizer\Image\Restore_Service` | src/Image/Restore_Service.php:27 | 6 |
| 36 | `Sakura\ImageOptimizer\Image\Size_Catalog` | src/Image/Size_Catalog.php:22 | 5 |
| 37 | `Sakura\ImageOptimizer\Image\Upload_Backup` | src/Image/Upload_Backup.php:22 | 4 |
| 38 | `Sakura\ImageOptimizer\Image\Upload_Handler` | src/Image/Upload_Handler.php:23 | 7 |
| 39 | `Sakura\ImageOptimizer\Image\Upload_Renamer` | src/Image/Upload_Renamer.php:21 | 7 |
| 40 | `Sakura\ImageOptimizer\Oss\Html_Filter` | src/Oss/Html_Filter.php:18 | 7 |
| 41 | `Sakura\ImageOptimizer\Oss\Url_Rewriter` | src/Oss/Url_Rewriter.php:17 | 8 |
| 42 | `Sakura\ImageOptimizer\S3\Alerting` | src/S3/Alerting.php:24 | 17 |
| 43 | `Sakura\ImageOptimizer\S3\Client_Factory` | src/S3/Client_Factory.php:20 | 2 |
| 44 | `Sakura\ImageOptimizer\S3\Connection_Tester` | src/S3/Connection_Tester.php:11 | 3 |
| 45 | `Sakura\ImageOptimizer\S3\Credential_Cipher` | src/S3/Credential_Cipher.php:21 | 7 |
| 46 | `Sakura\ImageOptimizer\S3\Delete_Jobs` | src/S3/Delete_Jobs.php:22 | 22 |
| 47 | `Sakura\ImageOptimizer\S3\Delete_Queue_Panel` | src/S3/Delete_Queue_Panel.php:23 | 11 |
| 48 | `Sakura\ImageOptimizer\S3\Delete_Worker` | src/S3/Delete_Worker.php:20 | 15 |
| 49 | `Sakura\ImageOptimizer\S3\Error_Mapper` | src/S3/Error_Mapper.php:15 | 3 |
| 50 | `Sakura\ImageOptimizer\S3\History_Migrator` | src/S3/History_Migrator.php:19 | 15 |
| 51 | `Sakura\ImageOptimizer\S3\Ignored_Paths` | src/S3/Ignored_Paths.php:20 | 4 |
| 52 | `Sakura\ImageOptimizer\S3\Inventory` | src/S3/Inventory.php:22 | 1 |
| 53 | `Sakura\ImageOptimizer\S3\Legacy_Snapshot` | src/S3/Legacy_Snapshot.php:24 | 5 |
| 54 | `Sakura\ImageOptimizer\S3\Log` | src/S3/Log.php:17 | 1 |
| 55 | `Sakura\ImageOptimizer\S3\Migration_Service` | src/S3/Migration_Service.php:17 | 2 |
| 56 | `Sakura\ImageOptimizer\S3\Monitoring_Panel` | src/S3/Monitoring_Panel.php:21 | 8 |
| 57 | `Sakura\ImageOptimizer\S3\Object_Store` | src/S3/Object_Store.php:19 | 10 |
| 58 | `Sakura\ImageOptimizer\S3\Options` | src/S3/Options.php:19 | 25 |
| 59 | `Sakura\ImageOptimizer\S3\Profile_Manager` | src/S3/Profile_Manager.php:23 | 3 |
| 60 | `Sakura\ImageOptimizer\S3\Prometheus` | src/S3/Prometheus.php:24 | 11 |
| 61 | `Sakura\ImageOptimizer\S3\Providers` | src/S3/Providers.php:9 | 2 |
| 62 | `Sakura\ImageOptimizer\S3\Remote_Operation_Guard` | src/S3/Remote_Operation_Guard.php:14 | 9 |
| 63 | `Sakura\ImageOptimizer\S3\Restore_Plan` | src/S3/Restore_Plan.php:27 | 3 |
| 64 | `Sakura\ImageOptimizer\S3\S3_Storage_Page` | src/S3/S3_Storage_Page.php:9 | 3 |
| 65 | `Sakura\ImageOptimizer\S3\Settings_Saver` | src/S3/Settings_Saver.php:11 | 6 |
| 66 | `Sakura\ImageOptimizer\S3\Storage` | src/S3/Storage.php:22 | 24 |
| 67 | `Sakura\ImageOptimizer\S3\Storage_Adapter` | src/S3/Storage_Adapter.php:20 | 17 |
| 68 | `Sakura\ImageOptimizer\S3\Sync_Actions` | src/S3/Sync_Actions.php:20 | 5 |
| 69 | `Sakura\ImageOptimizer\S3\Sync_Center_Module` | src/S3/Sync_Center_Module.php:27 | 25 |
| 70 | `Sakura\ImageOptimizer\S3\Sync_Center_Panel` | src/S3/Sync_Center_Panel.php:20 | 1 |
| 71 | `Sakura\ImageOptimizer\S3\Sync_Status` | src/S3/Sync_Status.php:23 | 23 |
| 72 | `Sakura\ImageOptimizer\S3\Synchronous_Uploader` | src/S3/Synchronous_Uploader.php:26 | 13 |
| 73 | `Sakura\ImageOptimizer\S3\Upload_Capabilities` | src/S3/Upload_Capabilities.php:23 | 3 |
| 74 | `Sakura\ImageOptimizer\S3\Upload_Metrics` | src/S3/Upload_Metrics.php:27 | 18 |
| 75 | `Sakura\ImageOptimizer\S3\Upload_Observer` | src/S3/Upload_Observer.php:20 | 4 |
| 76 | `Sakura\ImageOptimizer\S3\Upload_URL` | src/S3/Upload_URL.php:9 | 2 |
| 77 | `Sakura\ImageOptimizer\S3\Upload_Verify` | src/S3/Upload_Verify.php:20 | 3 |
| 78 | `Sakura\ImageOptimizer\Settings\Settings_Repository` | src/Settings/Settings_Repository.php:23 | 22 |
| 79 | `Sakura\ImageOptimizer\Stats\Stats_Repository` | src/Stats/Stats_Repository.php:20 | 5 |
| 80 | `Sakura\ImageOptimizer\Support\Hook_Loader` | src/Support/Hook_Loader.php:20 | 5 |
| 81 | `Sakura\ImageOptimizer\Support\Text` | src/Support/Text.php:21 | 1 |
| 82 | `Sakura\ImageOptimizer\Sync\Action_Worker` | src/Sync/Action_Worker.php:28 | 7 |
| 83 | `Sakura\ImageOptimizer\Sync\Attachment_Adopter` | src/Sync/Attachment_Adopter.php:26 | 9 |
| 84 | `Sakura\ImageOptimizer\Sync\Attachment_Indexer` | src/Sync/Attachment_Indexer.php:28 | 9 |
| 85 | `Sakura\ImageOptimizer\Sync\Checksums` | src/Sync/Checksums.php:17 | 3 |
| 86 | `Sakura\ImageOptimizer\Sync\CLI` | src/Sync/CLI.php:25 | 9 |
| 87 | `Sakura\ImageOptimizer\Sync\Fast_Search_Query` | src/Sync/Fast_Search_Query.php:24 | 2 |
| 88 | `Sakura\ImageOptimizer\Sync\Feature` | src/Sync/Feature.php:20 | 2 |
| 89 | `Sakura\ImageOptimizer\Sync\Index_Repository` | src/Sync/Index_Repository.php:24 | 69 |
| 90 | `Sakura\ImageOptimizer\Sync\Keyset_Cursor` | src/Sync/Keyset_Cursor.php:21 | 6 |
| 91 | `Sakura\ImageOptimizer\Sync\Kind` | src/Sync/Kind.php:20 | 2 |
| 92 | `Sakura\ImageOptimizer\Sync\Metrics` | src/Sync/Metrics.php:20 | 3 |
| 93 | `Sakura\ImageOptimizer\Sync\Scan_Service` | src/Sync/Scan_Service.php:30 | 25 |
| 94 | `Sakura\ImageOptimizer\Sync\Scan_Worker` | src/Sync/Scan_Worker.php:27 | 10 |
| 95 | `Sakura\ImageOptimizer\Sync\Schema` | src/Sync/Schema.php:21 | 5 |
| 96 | `Sakura\ImageOptimizer\Sync\Search_Token` | src/Sync/Search_Token.php:22 | 4 |
| 97 | `Sakura\ImageOptimizer\Sync\Verify_Service` | src/Sync/Verify_Service.php:24 | 7 |
| 98 | `Sakura\ImageOptimizer\Sync\Verify_Worker` | src/Sync/Verify_Worker.php:25 | 6 |
| 99 | `Sakura\ImageOptimizer\Thumbnail\Providers\Bitiful` | src/Thumbnail/Providers/Bitiful.php:24 | 7 |
| 100 | `Sakura\ImageOptimizer\Thumbnail\CLI` | src/Thumbnail/CLI.php:17 | 5 |
| 101 | `Sakura\ImageOptimizer\Thumbnail\Context` | src/Thumbnail/Context.php:18 | 7 |
| 102 | `Sakura\ImageOptimizer\Thumbnail\Decorator` | src/Thumbnail/Decorator.php:23 | 18 |
| 103 | `Sakura\ImageOptimizer\Thumbnail\Providers\Generic` | src/Thumbnail/Providers/Generic.php:24 | 5 |
| 104 | `Sakura\ImageOptimizer\Thumbnail\Policy` | src/Thumbnail/Policy.php:18 | 5 |
| 105 | `Sakura\ImageOptimizer\Thumbnail\Probe` | src/Thumbnail/Probe.php:24 | 11 |
| 106 | `Sakura\ImageOptimizer\Thumbnail\Provider` | src/Thumbnail/Provider.php:21 | 4 |
| 107 | `Sakura\ImageOptimizer\Thumbnail\Provider_Status` | src/Thumbnail/Provider_Status.php:17 | 5 |
| 108 | `Sakura\ImageOptimizer\Thumbnail\Rendition` | src/Thumbnail/Rendition.php:21 | 8 |
| 109 | `Sakura\ImageOptimizer\Thumbnail\Settings` | src/Thumbnail/Settings.php:22 | 31 |
| 110 | `Sakura\ImageOptimizer\Thumbnail\Size_Catalog` | src/Thumbnail/Size_Catalog.php:17 | 3 |
| 111 | `Sakura\ImageOptimizer\Thumbnail\Stats` | src/Thumbnail/Stats.php:17 | 5 |
| 112 | `Sakura\ImageOptimizer\Thumbnail\Theme_Bridge` | src/Thumbnail/Theme_Bridge.php:21 | 2 |
| 113 | `Sakura\ImageOptimizer\Thumbnail\Theme_Cache` | src/Thumbnail/Theme_Cache.php:23 | 5 |
| 114 | `Nboke\UserFiles\Services\Adoption_Manifest` | src/UserFiles/Services/Adoption_Manifest.php:7 | 2 |
| 115 | `Nboke\UserFiles\Editor\Assets` | src/UserFiles/Editor/Assets.php:20 | 1 |
| 116 | `Nboke\UserFiles\Support\Assets` | src/UserFiles/Support/Assets.php:21 | 1 |
| 117 | `Nboke\UserFiles\Capabilities` | src/UserFiles/Capabilities.php:20 | 8 |
| 118 | `Nboke\UserFiles\CLI` | src/UserFiles/CLI.php:23 | 7 |
| 119 | `Nboke\UserFiles\Editor\Context` | src/UserFiles/Editor/Context.php:20 | 3 |
| 120 | `Nboke\UserFiles\Rest\Controller` | src/UserFiles/Rest/Controller.php:34 | 64 |
| 121 | `Nboke\UserFiles\Support\Db_Transaction` | src/UserFiles/Support/Db_Transaction.php:22 | 5 |
| 122 | `Nboke\UserFiles\Frontend\Entry` | src/UserFiles/Frontend/Entry.php:24 | 7 |
| 123 | `Nboke\UserFiles\Repositories\Events` | src/UserFiles/Repositories/Events.php:22 | 2 |
| 124 | `Nboke\UserFiles\Services\Executor` | src/UserFiles/Services/Executor.php:30 | 21 |
| 125 | `Nboke\UserFiles\Repositories\Files` | src/UserFiles/Repositories/Files.php:24 | 20 |
| 126 | `Nboke\UserFiles\Repositories\Folders` | src/UserFiles/Repositories/Folders.php:28 | 38 |
| 127 | `Nboke\UserFiles\Frontend\Providers\Generic` | src/UserFiles/Frontend/Providers/Generic.php:24 | 12 |
| 128 | `Nboke\UserFiles\Media\Library_Filter` | src/UserFiles/Media/Library_Filter.php:15 | 1 |
| 129 | `Nboke\UserFiles\Media\Media_Hooks` | src/UserFiles/Media/Media_Hooks.php:37 | 52 |
| 130 | `Nboke\UserFiles\Services\Media_Move_Failure` | src/UserFiles/Services/Media_Move_Failure.php:21 | 1 |
| 131 | `Nboke\UserFiles\Services\Media_Mover` | src/UserFiles/Services/Media_Mover.php:14 | 15 |
| 132 | `Nboke\UserFiles\Services\Media_Notifications` | src/UserFiles/Services/Media_Notifications.php:8 | 1 |
| 133 | `Nboke\UserFiles\Editor\Module` | src/UserFiles/Editor/Module.php:21 | 3 |
| 134 | `Nboke\UserFiles\Module` | src/UserFiles/Module.php:33 | 11 |
| 135 | `Nboke\UserFiles\Repositories\Notifications` | src/UserFiles/Repositories/Notifications.php:23 | 7 |
| 136 | `Nboke\UserFiles\Services\Notifier` | src/UserFiles/Services/Notifier.php:20 | 7 |
| 137 | `Nboke\UserFiles\Admin\Page` | src/UserFiles/Admin/Page.php:25 | 7 |
| 138 | `Nboke\UserFiles\Editor\Picker` | src/UserFiles/Editor/Picker.php:32 | 1 |
| 139 | `Nboke\UserFiles\Rest\Picker_Controller` | src/UserFiles/Rest/Picker_Controller.php:30 | 8 |
| 140 | `Nboke\UserFiles\Frontend\Provider` | src/UserFiles/Frontend/Provider.php:24 | 6 |
| 141 | `Nboke\UserFiles\Services\Quota` | src/UserFiles/Services/Quota.php:28 | 8 |
| 142 | `Nboke\UserFiles\Services\Rate_Limiter` | src/UserFiles/Services/Rate_Limiter.php:23 | 4 |
| 143 | `Nboke\UserFiles\Repositories\Requests` | src/UserFiles/Repositories/Requests.php:28 | 28 |
| 144 | `Nboke\UserFiles\Schema` | src/UserFiles/Schema.php:24 | 7 |
| 145 | `Nboke\UserFiles\Settings` | src/UserFiles/Settings.php:21 | 16 |
| 146 | `Nboke\UserFiles\Admin\Space_Page` | src/UserFiles/Admin/Space_Page.php:27 | 6 |
| 147 | `Nboke\UserFiles\Space_Panel` | src/UserFiles/Space_Panel.php:23 | 11 |
| 148 | `Nboke\UserFiles\Services\Space_Rules` | src/UserFiles/Services/Space_Rules.php:24 | 13 |
| 149 | `Nboke\UserFiles\Space_Settings` | src/UserFiles/Space_Settings.php:20 | 30 |
| 150 | `Nboke\UserFiles\Services\Space_Store` | src/UserFiles/Services/Space_Store.php:22 | 59 |
| 151 | `Nboke\UserFiles\Services\Sync_Service` | src/UserFiles/Services/Sync_Service.php:26 | 9 |
| 152 | `Nboke\UserFiles\Frontend\Tab` | src/UserFiles/Frontend/Tab.php:22 | 16 |
| 153 | `Nboke\UserFiles\Support\Theme_Levels` | src/UserFiles/Support/Theme_Levels.php:22 | 4 |
| 154 | `Nboke\UserFiles\Services\Theme_Limits` | src/UserFiles/Services/Theme_Limits.php:22 | 2 |
| 155 | `Nboke\UserFiles\Support\Time` | src/UserFiles/Support/Time.php:18 | 4 |
| 156 | `Nboke\UserFiles\Services\Uploader` | src/UserFiles/Services/Uploader.php:27 | 9 |
| 157 | `Nboke\UserFiles\Frontend\Providers\Zibll` | src/UserFiles/Frontend/Providers/Zibll.php:21 | 7 |
| 158 | `Nboke\UserFiles\Services\Zibll_Bridge` | src/UserFiles/Services/Zibll_Bridge.php:23 | 16 |
| 159 | `Nboke\UserFiles\Space\Zibll_Order_Card` | src/UserFiles/Space/Zibll_Order_Card.php:26 | 12 |
| 160 | `Sakura\ImageOptimizer\Activation` | src/Activation.php:17 | 1 |
| 161 | `Sakura\ImageOptimizer\Deactivation` | src/Deactivation.php:17 | 1 |
| 162 | `Sakura\ImageOptimizer\Plugin` | src/Plugin.php:42 | 3 |
| 163 | `Sakura\ImageOptimizer\Uninstaller` | src/Uninstaller.php:23 | 10 |

## 附录 B：钩子索引（191 条，按模块分组）

### includes（68 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `admin_enqueue_scripts` | action | enqueue_progress_assets | includes/class-media-library-module.php:132 |
| `admin_enqueue_scripts` | action | enqueue_admin_assets | includes/class-thumbnail-optimizer-module.php:57 |
| `admin_enqueue_scripts` | action | enqueue_admin_assets | includes/class-image-optimizer-module.php:71 |
| `admin_enqueue_scripts` | action | enqueue_assets | includes/class-s3-storage-module.php:76 |
| `admin_init` | action | register_settings | includes/class-thumbnail-optimizer-module.php:56 |
| `admin_init` | action | register_settings | includes/class-image-optimizer-module.php:70 |
| `admin_menu` | action | add_progress_page | includes/class-media-library-module.php:131 |
| `admin_notices` | action | render_restore_blocked_notice | includes/class-media-library-module.php:127 |
| `admin_notices` | action | render_bulk_blocked_notice | includes/class-media-library-module.php:128 |
| `admin_notices` | action | render_conflict_notice | includes/class-thumbnail-optimizer-module.php:60 |
| `admin_post_sakura_imgopt_reset_stats` | action | handle_reset_stats | includes/class-image-optimizer-module.php:72 |
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
| `big_image_size_threshold` | filter | Thumbnail_Manager::apply_size_filters | includes/class-thumbnail-manager.php:46 |
| `bulk_actions-upload` | filter | register_bulk_action | includes/class-media-library-module.php:125 |
| `delete_attachment` | action | on_delete_attachment | includes/class-image-processor.php:158 |
| `dynamic_sidebar_after` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:67 |
| `dynamic_sidebar_before` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:66 |
| `handle_bulk_actions-upload` | filter | handle_bulk_action | includes/class-media-library-module.php:126 |
| `init` | action | apply_size_filters | includes/class-thumbnail-manager.php:28 |
| `intermediate_image_sizes` | filter | filter_sizes | includes/class-thumbnail-manager.php:42 |
| `loop_end` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:65 |
| `loop_start` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:63 |
| `manage_media_columns` | filter | add_s3_sync_column | includes/class-media-library-module.php:153 |
| `manage_media_custom_column` | action | render_s3_sync_column | includes/class-media-library-module.php:154 |
| `option_zibll_options` | filter | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:72 |
| `options.php` | submenu_page | render_progress_page | includes/class-media-library-module.php:408 |
| `pre_get_posts` | action | apply_format_filter | includes/class-media-library-module.php:121 |
| `restrict_manage_posts` | action | add_format_dropdown | includes/class-media-library-module.php:120 |
| `sakura_imgopt_spare_thumb` | filter | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:71 |
| `shutdown` | action | flush_pending | includes/class-stats.php:156 |
| `template_redirect` | action | start_scoped_buffer | includes/class-thumbnail-optimizer-module.php:52 |
| `the_post` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:64 |
| `update_option_` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:73 |
| `update_option_s3_options` | action | on_s3_options_changed | includes/class-thumbnail-optimizer-module.php:59 |
| `wp_ajax_ajax_widget_ui` | action | start_sidebar_ajax_buffer | includes/class-thumbnail-optimizer-module.php:53 |
| `wp_ajax_nopriv_ajax_widget_ui` | action | start_sidebar_ajax_buffer | includes/class-thumbnail-optimizer-module.php:54 |
| `wp_ajax_sakura_clean_expired_backup` | action | ajax_clean_expired_backup | includes/class-media-library-module.php:148 |
| `wp_ajax_sakura_get_progress` | action | ajax_get_progress | includes/class-media-library-module.php:137 |
| `wp_ajax_sakura_imgopt_scan_crop` | action | handle_scan_crop_ajax | includes/class-image-optimizer-module.php:73 |
| `wp_ajax_sakura_imgopt_stats_page` | action | ajax_stats_page | includes/class-image-optimizer-module.php:74 |
| `wp_ajax_sakura_probe_backup` | action | ajax_probe_backup | includes/class-media-library-module.php:150 |
| `wp_ajax_sakura_process_batch` | action | ajax_process_batch | includes/class-media-library-module.php:136 |
| `wp_ajax_sakura_purge_backup` | action | ajax_purge_backup | includes/class-media-library-module.php:144 |
| `wp_ajax_sakura_refresh_backup` | action | ajax_refresh_backup | includes/class-media-library-module.php:146 |
| `wp_ajax_sakura_restore_single` | action | ajax_restore_single | includes/class-media-library-module.php:141 |
| `wp_ajax_sakura_s3_retry` | action | retry_failed | includes/class-s3-storage-module.php:74 |
| `wp_ajax_sakura_s3_upload_metrics` | action | upload_metrics | includes/class-s3-storage-module.php:75 |
| `wp_content_img_tag` | filter | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:70 |
| `wp_generate_attachment_metadata` | filter | process_metadata | includes/class-image-processor.php:156 |
| `wp_generate_attachment_metadata` | filter | strip_disabled_sizes | includes/class-thumbnail-manager.php:50 |
| `wp_get_attachment_image_src` | filter | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:69 |
| `wp_handle_sideload_prefilter` | filter | pre_upload_rename | includes/class-image-processor.php:140 |
| `wp_handle_sideload` | filter | handle_upload | includes/class-image-processor.php:150 |
| `wp_handle_upload_prefilter` | filter | pre_upload_rename | includes/class-image-processor.php:139 |
| `wp_handle_upload` | filter | handle_upload | includes/class-image-processor.php:149 |
| `wp_image_make_intermediate_size` | filter | block_intermediate | includes/class-thumbnail-manager.php:58 |
| `wp_insert_attachment_data` | filter | sync_attachment_title | includes/class-image-processor.php:142 |
| `wp` | action | Thumbnail_Optimizer_Module::register_hooks | includes/class-thumbnail-optimizer-module.php:62 |

### src（1 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `sakura_imgopt_daily_backup_cleanup` | action | Plugin::boot | src/Plugin.php:106 |

### src/Admin（6 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `admin_enqueue_scripts` | action | enqueue_admin_assets | src/Admin/Basic_Settings_Module.php:38 |
| `admin_init` | action | register_settings | src/Admin/Basic_Settings_Module.php:37 |
| `admin_menu` | action | register_menu | src/Admin/Menu.php:32 |
| `admin_page_access_denied` | action | redirect_legacy | src/Admin/Menu.php:37 |
| `init` | action | redirect_legacy | src/Admin/Menu.php:36 |
| `wp_ajax_sakura_save_layout` | action | ajax_save | src/Admin/Layout.php:236 |

### src/S3（43 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | queue_attachment | src/S3/Storage.php:138 |
| `admin_enqueue_scripts` | action | enqueue_assets | src/S3/Sync_Center_Module.php:101 |
| `admin_enqueue_scripts` | action | enqueue | src/S3/Delete_Queue_Panel.php:43 |
| `admin_init` | action | maybe_migrate | src/S3/Storage.php:146 |
| `admin_init` | action | resume_pending_restores | src/S3/Storage.php:152 |
| `admin_init` | action | schedule | src/S3/Alerting.php:377 |
| `admin_init` | action | ensure_scheduled | src/S3/Delete_Worker.php:49 |
| `admin_notices` | action | admin_notices | src/S3/Sync_Center_Module.php:111 |
| `admin_notices` | action | render_incomplete_config_notice | src/S3/Storage.php:119 |
| `admin_notices` | action | render_failed_notice | src/S3/Storage.php:145 |
| `admin_post_` | action | output | src/S3/Prometheus.php:75 |
| `admin_post_nopriv_` | action | output | src/S3/Prometheus.php:76 |
| `admin_post_sakura_s3_delete_queue` | action | handle_action | src/S3/Delete_Queue_Panel.php:42 |
| `admin_post_sakura_s3_ignored_save` | action | save_ignored_paths | src/S3/Sync_Center_Module.php:113 |
| `admin_post_sakura_s3_sync_export` | action | export_csv | src/S3/Sync_Center_Module.php:112 |
| `cron_schedules` | filter | add_schedule | src/S3/Alerting.php:365 |
| `cron_schedules` | filter | add_schedule | src/S3/Alerting.php:374 |
| `cron_schedules` | filter | add_schedule | src/S3/Delete_Worker.php:47 |
| `cron_schedules` | filter | add_schedule | src/S3/Delete_Worker.php:69 |
| `delete_attachment` | action | delete_remote_attachment | src/S3/Storage.php:144 |
| `init` | action | schedule | src/S3/Alerting.php:376 |
| `init` | action | ensure_scheduled | src/S3/Delete_Worker.php:48 |
| `init` | action | maybe_serve | src/S3/Prometheus.php:74 |
| `posts_where` | filter | History_Migrator::batch_ids | src/S3/History_Migrator.php:263 |
| `sakura_imgopt_restore_started` | action | on_restore_started | src/S3/Storage.php:150 |
| `sakura_imgopt_restored` | action | reconcile_attachment | src/S3/Storage.php:151 |
| `sakura_imgopt_s3_upload_failed` | action | record_failed | src/S3/Upload_Observer.php:31 |
| `sakura_imgopt_s3_uploaded` | action | record_uploaded | src/S3/Upload_Observer.php:30 |
| `wp_ajax_sakura_s3_sync_action` | action | ajax_action | src/S3/Sync_Center_Module.php:108 |
| `wp_ajax_sakura_s3_sync_cancel` | action | ajax_cancel | src/S3/Sync_Center_Module.php:104 |
| `wp_ajax_sakura_s3_sync_page` | action | ajax_page | src/S3/Sync_Center_Module.php:106 |
| `wp_ajax_sakura_s3_sync_refresh` | action | ajax_refresh | src/S3/Sync_Center_Module.php:109 |
| `wp_ajax_sakura_s3_sync_resume` | action | ajax_resume | src/S3/Sync_Center_Module.php:105 |
| `wp_ajax_sakura_s3_sync_scan_step` | action | ajax_scan_step | src/S3/Sync_Center_Module.php:103 |
| `wp_ajax_sakura_s3_sync_scan` | action | ajax_scan | src/S3/Sync_Center_Module.php:102 |
| `wp_ajax_sakura_s3_sync_test` | action | ajax_test | src/S3/Sync_Center_Module.php:107 |
| `wp_ajax_sakura_s3_sync_toggle_index` | action | ajax_toggle_index | src/S3/Sync_Center_Module.php:110 |
| `wp_generate_attachment_metadata` | filter | Storage::register_hooks | src/S3/Storage.php:143 |
| `wp_get_attachment_url` | filter | revert_unsynced_url | src/S3/Storage.php:133 |
| `wp_get_attachment_url` | filter | normalize_root_url | src/S3/Storage.php:155 |
| `wp_handle_sideload_prefilter` | filter | block_incomplete_upload | src/S3/Storage.php:121 |
| `wp_handle_upload_prefilter` | filter | block_incomplete_upload | src/S3/Storage.php:120 |
| `wp_update_attachment_metadata` | filter | upload_attachment_metadata | src/S3/Storage.php:140 |

### src/Support（2 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `hook` | action | Hook_Loader::register | src/Support/Hook_Loader.php:102 |
| `hook` | filter | Hook_Loader::register | src/Support/Hook_Loader.php:106 |

### src/Sync（9 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | index_attachment | src/Sync/Attachment_Indexer.php:53 |
| `admin_init` | action | ensure_scheduled | src/Sync/Verify_Worker.php:75 |
| `cron_schedules` | filter | add_schedule | src/Sync/Verify_Worker.php:73 |
| `delete_attachment` | action | forget_attachment | src/Sync/Attachment_Indexer.php:55 |
| `init` | action | ensure_scheduled | src/Sync/Verify_Worker.php:74 |
| `sakura_imgopt_s3_skip_finalize` | filter | Attachment_Adopter::adopt | src/Sync/Attachment_Adopter.php:157 |
| `sakura_imgopt_sync_scan_tick` | action | run | src/Sync/Scan_Worker.php:78 |
| `sufm_skip_attachment_adoption` | filter | Attachment_Adopter::adopt | src/Sync/Attachment_Adopter.php:154 |
| `wp_update_attachment_metadata` | action | on_metadata | src/Sync/Attachment_Indexer.php:54 |

### src/Thumbnail（1 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `shutdown` | action | flush | src/Thumbnail/Stats.php:58 |

### src/UserFiles（58 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `add_attachment` | action | assign_uploaded | src/UserFiles/Media/Media_Hooks.php:127 |
| `add_option_` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:50 |
| `admin_body_class` | filter | body_class | src/UserFiles/Media/Media_Hooks.php:145 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Media/Media_Hooks.php:142 |
| `admin_enqueue_scripts` | action | enqueue_folder_panel | src/UserFiles/Media/Media_Hooks.php:146 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Admin/Space_Page.php:47 |
| `admin_enqueue_scripts` | action | enqueue | src/UserFiles/Admin/Page.php:51 |
| `admin_init` | action | register_settings | src/UserFiles/Admin/Space_Page.php:46 |
| `admin_init` | action | register_settings | src/UserFiles/Admin/Page.php:50 |
| `admin_init` | action | maybe_install | src/UserFiles/Module.php:67 |
| `admin_init` | action | maybe_import_theme_limits | src/UserFiles/Module.php:68 |
| `admin_notices` | action | render_bulk_notice | src/UserFiles/Media/Media_Hooks.php:141 |
| `admin_notices` | action | render_folder_panel | src/UserFiles/Media/Media_Hooks.php:144 |
| `after_setup_theme` | action | register_frontend | src/UserFiles/Module.php:106 |
| `ajax_query_attachments_args` | filter | filter_grid_query | src/UserFiles/Media/Media_Hooks.php:132 |
| `attachment_fields_to_edit` | filter | add_summary_field | src/UserFiles/Media/Media_Hooks.php:134 |
| `attachment_fields_to_save` | filter | save_summary_field | src/UserFiles/Media/Media_Hooks.php:135 |
| `bulk_actions-upload` | filter | add_bulk_action | src/UserFiles/Media/Media_Hooks.php:139 |
| `cron_schedules` | filter | cron_schedules | src/UserFiles/Module.php:70 |
| `delete_option_` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:52 |
| `deleted_post` | action | finalize_deleted_attachment | src/UserFiles/Media/Media_Hooks.php:137 |
| `handle_bulk_actions-upload` | filter | handle_bulk_action | src/UserFiles/Media/Media_Hooks.php:140 |
| `init` | action | register_renderers | src/UserFiles/Admin/Page.php:62 |
| `init` | action | schedule | src/UserFiles/Module.php:71 |
| `init` | action | register_shortcode | src/UserFiles/Frontend/Providers/Generic.php:82 |
| `load-upload.php` | action | preflight_bulk_delete | src/UserFiles/Media/Media_Hooks.php:143 |
| `main_user_tab_content_files` | filter | content | src/UserFiles/Frontend/Tab.php:46 |
| `manage_media_columns` | filter | add_columns | src/UserFiles/Media/Media_Hooks.php:128 |
| `manage_media_custom_column` | action | render_column | src/UserFiles/Media/Media_Hooks.php:129 |
| `order_refunded` | action | handle_order_refunded | src/UserFiles/Services/Space_Store.php:2541 |
| `payment_order_success` | action | on_payment_success | src/UserFiles/Space_Panel.php:40 |
| `posts_where` | filter | filter_media_where | src/UserFiles/Media/Media_Hooks.php:133 |
| `pre_delete_attachment` | filter | guard_delete | src/UserFiles/Media/Media_Hooks.php:136 |
| `pre_get_posts` | action | filter_list_query | src/UserFiles/Media/Media_Hooks.php:131 |
| `rest_api_init` | action | register_rest | src/UserFiles/Editor/Module.php:31 |
| `rest_api_init` | action | register_rest | src/UserFiles/Space_Panel.php:39 |
| `rest_api_init` | action | register_rest | src/UserFiles/Module.php:99 |
| `rest_pre_dispatch` | filter | guard_rest_delete | src/UserFiles/Media/Media_Hooks.php:138 |
| `restrict_manage_posts` | action | render_filters | src/UserFiles/Media/Media_Hooks.php:130 |
| `sufm_allow_attachment_delete` | filter | Uploader::discard_upload | src/UserFiles/Services/Uploader.php:434 |
| `sufm_allow_attachment_delete` | filter | Executor::purge_file | src/UserFiles/Services/Executor.php:676 |
| `sufm_allow_attachment_delete` | filter | Executor::purge_file | src/UserFiles/Services/Executor.php:758 |
| `sufm_tick` | action | handle_tick | src/UserFiles/Services/Space_Store.php:2544 |
| `update_option_` | action | flush_caches | src/UserFiles/Admin/Space_Page.php:51 |
| `update_option_` | action | flush_entry_cache | src/UserFiles/Admin/Page.php:52 |
| `update_option_` | action | sync_theme_limits | src/UserFiles/Module.php:69 |
| `user_ctnter_main_tabs_array` | filter | tabs | src/UserFiles/Frontend/Tab.php:44 |
| `user_order_details_modal` | filter | details_modal | src/UserFiles/Space/Zibll_Order_Card.php:42 |
| `user_order_list_card` | filter | list_card | src/UserFiles/Space/Zibll_Order_Card.php:40 |
| `wp_enqueue_scripts` | action | enqueue | src/UserFiles/Space_Panel.php:38 |
| `wp_enqueue_scripts` | action | enqueue | src/UserFiles/Frontend/Tab.php:47 |
| `wp_enqueue_scripts` | action | Module::start | src/UserFiles/Editor/Module.php:60 |
| `wp_enqueue_scripts` | action | maybe_enqueue | src/UserFiles/Frontend/Providers/Generic.php:83 |
| `wp_footer` | action | Module::start | src/UserFiles/Editor/Module.php:63 |
| `wp_handle_upload_prefilter` | filter | guard_media_upload | src/UserFiles/Media/Media_Hooks.php:126 |
| `wp` | action | start | src/UserFiles/Editor/Module.php:35 |
| `zib_user_center_page_sidebar_button_2_args` | filter | sidebar | src/UserFiles/Frontend/Tab.php:45 |
| `zibpay_order_title` | filter | order_title | src/UserFiles/Space/Zibll_Order_Card.php:41 |

### 未归属（3 个）

| 钩子 | 类型 | 回调 | 注册位置 |
|---|---|---|---|
| `init` | action |  | sakura-tools.php:96 |
| `plugins_loaded` | action |  | sakura-tools.php:103 |
| `plugins_loaded` | action |  | sakura-tools.php:25 |

合计 191 条钩子（已排除 tests/stubs 中的注册）。

## 附录 C：文件索引（178 个生产 PHP 文件）

| 文件 | 模块 | 行数 | 声明类 |
|---|---|---:|---|
| fast-search.php | 根文件 | 248 | — |
| includes/class-animation-detector.php | includes | 271 | `Animation_Detector` |
| includes/class-backup.php | includes | 539 | `Backup` |
| includes/class-file-system.php | includes | 249 | `File_System` |
| includes/class-gd-handler.php | includes | 253 | `GD_Handler` |
| includes/class-image-optimizer-module.php | includes | 1314 | `Image_Optimizer_Module` |
| includes/class-image-processor.php | includes | 314 | `Image_Processor` |
| includes/class-imagick-handler.php | includes | 313 | `Imagick_Handler` |
| includes/class-media-library-module.php | includes | 993 | `Media_Library_Module` |
| includes/class-s3-cli.php | includes | 502 | `S3_CLI` |
| includes/class-s3-doge-cloud.php | includes | 141 | `S3_Doge_Cloud` |
| includes/class-s3-storage-module.php | includes | 293 | `S3_Storage_Module` |
| includes/class-stats.php | includes | 304 | `Stats` |
| includes/class-thumbnail-manager.php | includes | 114 | `Thumbnail_Manager` |
| includes/class-thumbnail-optimizer-module.php | includes | 399 | `Thumbnail_Optimizer_Module` |
| includes/views/basic-settings.php | includes/views | 357 | — |
| includes/views/editor-picker-app.php | includes/views | 307 | — |
| includes/views/media-progress.php | includes/views | 231 | — |
| includes/views/s3-sync-center.php | includes/views | 756 | — |
| includes/views/settings-backup.php | includes/views | 191 | — |
| includes/views/settings-main.php | includes/views | 120 | — |
| includes/views/settings-stats.php | includes/views | 115 | — |
| includes/views/thumb-settings.php | includes/views | 555 | — |
| includes/views/userfiles-admin.php | includes/views | 581 | — |
| includes/views/userfiles-space-admin.php | includes/views | 939 | — |
| includes/views/userfiles-space-panel.php | includes/views | 276 | — |
| includes/views/userfiles-tab.php | includes/views | 304 | — |
| sakura-tools.php | 根文件 | 109 | — |
| src/Activation.php | src | 133 | `Activation` |
| src/Admin/Assets.php | src/Admin | 223 | `Assets` |
| src/Admin/Basic_Settings_Module.php | src/Admin | 94 | `Basic_Settings_Module` |
| src/Admin/Environment_Report.php | src/Admin | 343 | `Environment_Report` |
| src/Admin/Layout.php | src/Admin | 508 | `Layout` |
| src/Admin/Menu.php | src/Admin | 236 | `Menu` |
| src/Admin/Registry.php | src/Admin | 354 | `Registry` |
| src/Admin/Shell.php | src/Admin | 137 | `Shell` |
| src/Backup/Backup_Repository.php | src/Backup | 92 | `Backup_Repository` |
| src/Backup/Exposure_Probe.php | src/Backup | 367 | `Exposure_Probe` |
| src/Contracts/Backup_Repository_Interface.php | src/Contracts | 94 | `Backup_Repository_Interface` |
| src/Contracts/File_System_Interface.php | src/Contracts | 80 | `File_System_Interface` |
| src/Contracts/Module.php | src/Contracts | 32 | `Module` |
| src/Contracts/Settings_Repository_Interface.php | src/Contracts | 126 | `Settings_Repository_Interface` |
| src/Contracts/Stats_Repository_Interface.php | src/Contracts | 60 | `Stats_Repository_Interface` |
| src/Deactivation.php | src | 62 | `Deactivation` |
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
| src/Plugin.php | src | 199 | `Plugin` |
| src/S3/Alerting.php | src/S3 | 396 | `Alerting` |
| src/S3/Client_Factory.php | src/S3 | 115 | `Client_Factory` |
| src/S3/Connection_Tester.php | src/S3 | 53 | `Connection_Tester` |
| src/S3/Credential_Cipher.php | src/S3 | 227 | `Credential_Cipher` |
| src/S3/Delete_Jobs.php | src/S3 | 585 | `Delete_Jobs` |
| src/S3/Delete_Queue_Panel.php | src/S3 | 458 | `Delete_Queue_Panel` |
| src/S3/Delete_Worker.php | src/S3 | 308 | `Delete_Worker` |
| src/S3/Error_Mapper.php | src/S3 | 114 | `Error_Mapper` |
| src/S3/History_Migrator.php | src/S3 | 406 | `History_Migrator` |
| src/S3/Ignored_Paths.php | src/S3 | 122 | `Ignored_Paths` |
| src/S3/Inventory.php | src/S3 | 42 | `Inventory` |
| src/S3/Legacy_Snapshot.php | src/S3 | 220 | `Legacy_Snapshot` |
| src/S3/Log.php | src/S3 | 31 | `Log` |
| src/S3/Migration_Service.php | src/S3 | 161 | `Migration_Service` |
| src/S3/Monitoring_Panel.php | src/S3 | 209 | `Monitoring_Panel` |
| src/S3/Object_Store.php | src/S3 | 769 | `Object_Store` |
| src/S3/Options.php | src/S3 | 582 | `Options` |
| src/S3/Profile_Manager.php | src/S3 | 143 | `Profile_Manager` |
| src/S3/Prometheus.php | src/S3 | 212 | `Prometheus` |
| src/S3/Providers.php | src/S3 | 52 | `Providers` |
| src/S3/Remote_Operation_Guard.php | src/S3 | 160 | `Remote_Operation_Guard` |
| src/S3/Restore_Plan.php | src/S3 | 87 | `Restore_Plan` |
| src/S3/S3_Storage_Page.php | src/S3 | 291 | `S3_Storage_Page` |
| src/S3/Settings_Saver.php | src/S3 | 239 | `Settings_Saver` |
| src/S3/Storage_Adapter.php | src/S3 | 452 | `Storage_Adapter` |
| src/S3/Storage.php | src/S3 | 779 | `Storage` |
| src/S3/Sync_Actions.php | src/S3 | 161 | `Sync_Actions` |
| src/S3/Sync_Center_Module.php | src/S3 | 1519 | `Sync_Center_Module` |
| src/S3/Sync_Center_Panel.php | src/S3 | 88 | `Sync_Center_Panel` |
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
| src/Sync/Index_Repository.php | src/Sync | 2105 | `Index_Repository` |
| src/Sync/Keyset_Cursor.php | src/Sync | 135 | `Keyset_Cursor` |
| src/Sync/Kind.php | src/Sync | 88 | `Kind` |
| src/Sync/Metrics.php | src/Sync | 88 | `Metrics` |
| src/Sync/Scan_Service.php | src/Sync | 1011 | `Scan_Service` |
| src/Sync/Scan_Worker.php | src/Sync | 228 | `Scan_Worker` |
| src/Sync/Schema.php | src/Sync | 291 | `Schema` |
| src/Sync/Search_Token.php | src/Sync | 94 | `Search_Token` |
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
| src/Thumbnail/Settings.php | src/Thumbnail | 1038 | `Settings` |
| src/Thumbnail/Size_Catalog.php | src/Thumbnail | 80 | `Size_Catalog` |
| src/Thumbnail/Stats.php | src/Thumbnail | 192 | `Stats` |
| src/Thumbnail/Theme_Bridge.php | src/Thumbnail | 79 | `Theme_Bridge` |
| src/Thumbnail/Theme_Cache.php | src/Thumbnail | 229 | `Theme_Cache` |
| src/Uninstaller.php | src | 365 | `Uninstaller` |
| src/UserFiles/Admin/Page.php | src/UserFiles | 192 | `Page` |
| src/UserFiles/Admin/Space_Page.php | src/UserFiles | 274 | `Space_Page` |
| src/UserFiles/Capabilities.php | src/UserFiles | 155 | `Capabilities` |
| src/UserFiles/CLI.php | src/UserFiles | 397 | `CLI` |
| src/UserFiles/Editor/Assets.php | src/UserFiles | 103 | `Assets` |
| src/UserFiles/Editor/Context.php | src/UserFiles | 66 | `Context` |
| src/UserFiles/Editor/Module.php | src/UserFiles | 66 | `Module` |
| src/UserFiles/Editor/Picker.php | src/UserFiles | 100 | `Picker` |
| src/UserFiles/Frontend/Entry.php | src/UserFiles | 128 | `Entry` |
| src/UserFiles/Frontend/Provider.php | src/UserFiles | 60 | `Provider` |
| src/UserFiles/Frontend/Providers/Generic.php | src/UserFiles | 186 | `Generic` |
| src/UserFiles/Frontend/Providers/Zibll.php | src/UserFiles | 96 | `Zibll` |
| src/UserFiles/Frontend/Tab.php | src/UserFiles | 550 | `Tab` |
| src/UserFiles/Media/Library_Filter.php | src/UserFiles | 75 | `Library_Filter` |
| src/UserFiles/Media/Media_Hooks.php | src/UserFiles | 2183 | `Media_Hooks` |
| src/UserFiles/Module.php | src/UserFiles | 362 | `Module` |
| src/UserFiles/Repositories/Events.php | src/UserFiles | 86 | `Events` |
| src/UserFiles/Repositories/Files.php | src/UserFiles | 728 | `Files` |
| src/UserFiles/Repositories/Folders.php | src/UserFiles | 871 | `Folders` |
| src/UserFiles/Repositories/Notifications.php | src/UserFiles | 226 | `Notifications` |
| src/UserFiles/Repositories/Requests.php | src/UserFiles | 1202 | `Requests` |
| src/UserFiles/Rest/Controller.php | src/UserFiles | 2179 | `Controller` |
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
| src/UserFiles/Services/Space_Store.php | src/UserFiles | 3149 | `Space_Store` |
| src/UserFiles/Services/Sync_Service.php | src/UserFiles | 411 | `Sync_Service` |
| src/UserFiles/Services/Theme_Limits.php | src/UserFiles | 114 | `Theme_Limits` |
| src/UserFiles/Services/Uploader.php | src/UserFiles | 620 | `Uploader` |
| src/UserFiles/Services/Zibll_Bridge.php | src/UserFiles | 383 | `Zibll_Bridge` |
| src/UserFiles/Settings.php | src/UserFiles | 387 | `Settings` |
| src/UserFiles/Space_Panel.php | src/UserFiles | 349 | `Space_Panel` |
| src/UserFiles/Space_Settings.php | src/UserFiles | 842 | `Space_Settings` |
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
| `Image_Optimizer_Module` | includes/class-image-optimizer-module.php:23 | implements Module | 40 / 45 | 4 / 22 | 5 | 渲染图像优化后台设置页面 |
| `Image_Processor` | includes/class-image-processor.php:39 | implements Module | 17 / 17 | 7 / 24 | 7 | 以钩子适配上传转换、子尺寸优化与还原 |
| `Imagick_Handler` | includes/class-imagick-handler.php:16 |  | 3 / 5 | 4 / 4 |  | 用 Imagick 压缩图片并转换格式 |
| `Media_Library_Module` | includes/class-media-library-module.php:30 | implements Module | 23 / 28 | 3 / 22 | 21 | 增强媒体库格式筛选与异步批量优化 |
| `S3_CLI` | includes/class-s3-cli.php:14 |  | 5 / 13 | 0 / 14 |  | 提供 S3 历史文件迁移的 WP-CLI 命令 |
| `S3_Doge_Cloud` | includes/class-s3-doge-cloud.php:16 |  | 2 / 7 | 1 / 4 |  | 获取并缓存多吉云短期 S3 凭据 |
| `S3_Storage_Module` | includes/class-s3-storage-module.php:25 | implements Module | 12 / 20 | 7 / 35 | 10 | 装配 S3 存储后台设置及其职责服务 |
| `Stats` | includes/class-stats.php:15 |  | 7 / 9 | 2 / 0 | 1 | 双层记录优化汇总与单附件处理历史 |
| `Thumbnail_Manager` | includes/class-thumbnail-manager.php:20 | implements Module | 6 / 6 | 5 / 3 | 5 | 按尺寸控制裁剪并移除被禁子尺寸 |
| `Thumbnail_Optimizer_Module` | includes/class-thumbnail-optimizer-module.php:32 | implements Module | 14 / 18 | 5 / 17 | 19 | 按 v2 策略重写附件图片 URL |
