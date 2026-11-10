# sakura-tools 3D 图谱同步说明（v1.24.4）

> 配套文档：[sakura-tools 代码结构 1.24.4](sakura-tools-代码结构-1.24.4.md) ｜ **代码基线** commit `1660b4c`（1.24.3 发布；1.24.4 覆盖升级与文档修正随本次发布提交），图谱按 1.24.4 工作树生成。本文只回答三件事：**图谱变了什么、怎么和文档对上、以后怎么重建**。

## 0. 结论

- 图谱由同一份扫描事实表与文档同时产出，不是手工对齐的副产物；上一轮（2026-11-09 1.24.2 S3/Sync 运维观测）新增 7 条 `ref`、3 条模块边权与 2 个类节点的 `val`，节点、类、钩子集合不变（§2.9）。
- 2026-11-09 晚间的三条验证空白补测（Quota、删除审核→Executor、卸载、扫描游标、编辑器覆盖与顺序）没有改插件代码（§2.10）；本轮 1.24.3 修复卸载漏删 `file_tokens`，新增 1 条 `ref` 与 1 条模块边权，节点、类、钩子集合不变（§2.11）。
- 规模：**179 个生产 PHP 文件 / 164 个类 / 17 个模块 / 181 个节点 / 826 条连线**（612 `ref` + 164 `contains` + 50 `modref`）/ **199 个具名钩子** / **0 悬空边**。
- 本轮（2026-11-10）新增 `src/Upgrade_Guard.php` 并在 `Plugin::boot()` 接线，节点 180→181、连线 823→826（`ref` +2、`contains` +1）；钩子由 191 修正为 **199**：补扫出 11 条此前漏记的具名注册，同时剔除 2 条通用转发和 1 条 `add_submenu_page`，净 +8；代码本身没有新增钩子（§2.12）。页面四角为 179 / 181 / 826 / 199。
- 1.24.4 发布只提升版本号、同步文档与图谱标题，节点 / 连线 / 钩子集合不变；页面副标题与 `meta.version` 更新为 1.24.4。
- 页面四角数字由 `graph.json` 的 `stats` 注入，不再手写；页面显示 179 / 181 / 826 / 199，与文档附录计数一致。
- 生成、注入、组装三个脚本已固化到 `docs/tools/`，可在任意机器重跑（§7）。

## 1. 数据管线：谁生成谁

```
scanner.php（token_get_all 三趟静态扫描）
        │  /tmp/scan.json        179 文件 / 164 类 / 199 钩子 / 全部边
        ├── collect-hooks.php → 199 条具名钩子（含常量与拼接解析）
        ├── collect-comments.php → /tmp/comments.json   164 条类注释（"职责"列的依据）
        ▼
build-code-structure.mjs
        ├── modules.md  class-index.md  file-index.md  hooks.md  module-matrix.md  top-edges.md
        ├── classes/<模块>.md   16 个分类表
        └── graph.json          181 节点 / 826 连线 / 确定性坐标
        ▼
graph-inject.mjs ──► docs/graph-preview/index.html（内嵌 DATA）+ graph.json
        ▼
assemble-doc.py  ──► sakura-tools-代码结构-1.24.4.md（分章正文 + 上述表格）
```

| 步骤 | 命令（在插件根目录下执行） | 产物 |
|---|---|---|
| 1 扫描 | `php /root/DSH/plugins/dsh-codegraph/lib/scanner.php . > /tmp/scan.json` | 事实表 |
| 1b 钩子补扫 | 构建脚本自动调用 `php docs/tools/collect-hooks.php .` | 199 条具名钩子（解析常量、`self::`/`Class::` 与字符串拼接；排除 `add_submenu_page` 与 `Hook_Loader` 通用转发） |
| 2 注释 | `php docs/tools/collect-comments.php . > /tmp/comments.json` | 164 条类 docblock |
| 3 生成 | `node docs/tools/build-code-structure.mjs /tmp/scan.json /tmp/docparts /tmp/comments.json` | 表格 + graph.json |
| 4 注入 | `node docs/tools/graph-inject.mjs /tmp/docparts/graph.json docs/graph-preview --write` | index.html + graph.json |
| 5 组装 | `python3 docs/tools/assemble-doc.py --parts /tmp/docparts --chapters docs/tools/chapters` | 代码结构文档 |

> 第 4 步不带 `--write` 是 dry-run：只校验悬空边并打印将发生的改动，不落盘。悬空边不为 0 时脚本直接退出（退出码 1），不允许把坏数据写进页面。
> 第 5 步必须显式给 `--parts`：`/tmp/docparts` 是第 3 步的临时产物，仓库里只提交正文分章 `docs/tools/chapters` 与生成器。

## 2. 与上一版导出的对比

| 项 | 旧导出（`/tmp/graph.json.bak`） | 当前导出 | 说明 |
| 文件数 | 181 | 179 | 口径为"生产 PHP"，排除 `tests/`、`stubs/`、`.dsh-ops/`；本轮新增 `src/Upgrade_Guard.php` |
| 模块 | 15 | 17 | 根文件统一命名 `根文件`、`src` 单列、`includes` 与 `includes/views` 分开 |
| 类 | 147 | 164 | 本轮新增 `Upgrade_Guard`；见下方 id 规则变更 |
| 节点 | 162 | 181 | 164 类 + 17 模块 |
| 连线 | 761 | 826 | 旧 559 ref + 147 contains + 55 modref → 612 + 164 + 50 |
| 钩子 | 未收录（页脚写死 111） | 199（`stats.hooks`） | 本轮改为 `collect-hooks.php` 补扫；见 §2.12 |
| 布局 | 力导向 320 次迭代，radius 1699.4 | 确定性球面，radius 1710，`iterations: 0` | 同数据每次坐标一致，可 diff |
| 节点 id | 短类名，如 `cls:Alerting` | FQCN，如 `cls:Sakura\ImageOptimizer\S3\Alerting` | 见下方说明 |

### 2.1 增量（2026-11-08 清理 §6.8 死代码）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 删除节点 | `cls:Sakura\ImageOptimizer\S3\Upload_Queue`（231 行，src 内无实例化） | 节点 181→180，类 164→163 | §6.8① |
| 删除连线 | `mod:src/S3` → `cls:…\S3\Upload_Queue`（`contains`） | `contains` 164→163；S3 类 37→36 | §6.8① |
| 新增连线 | `cls:…\Uninstaller` → `cls:…\S3\Upload_URL`（`use`） | `ref` 598→599；`src→src/S3` 21→22 | §6.8④ |
| 不变 | 全部 190 个钩子、其余 163 个类节点 | 总连线仍 812 | §6.8 / §10.8 / §12 |

> 删类同时删除了 `tests/UploadQueueTest.php` 与 `tests/bootstrap.php` 的 require；`tests/` 本来不进图，故不产生节点变化。

**为什么改 id 规则**：当时 163 个类里，只有 140 个类的短名是唯一的；**10 组短名重复、共 23 个类**（`Assets`×3、`Module`×3、`CLI`×3，`Stats`/`Size_Catalog`/`Schema`/`Context`/`Generic`/`Provider`/`Settings` 各 ×2）。用短名做 id，这些类会落到同一个节点上、边也会被合并。旧导出只有 147 个类，**无法据此证明当年发生过合并**（旧时点的代码库本身就更小），但这个风险在新方案里已经不存在。

**代价**：新旧节点无法逐 id 对齐，所以不提供"从 162 到 180"的逐节点清单，只给可复核的计数与行数核对（§6、§8）。上一轮清理的增量已在 §2.1 单列；以后逐版追踪请保留每版 `graph.json`，`meta.version` 已写入版本号。

### 2.2 增量（2026-11-08 §12 三项核实）

| 项 | 该轮结果 | 说明 |
|---|---|---|
| 节点 | 180 → 180 | 无新增/删除类；`fast-search.php` 是脚本型文件，本来不进图 |
| 连线 | 812 → 812 | 599 `ref` + 50 `modref` + 163 `contains` 均未变 |
| 钩子 | 190 → 190 | 未改代码，未重扫 |
| 文档 | §8.9 改写、§10.10 新增、§12 改写 | 对应 `Space_Store::grant_from_order()` 字段缺陷、`Upload_Observer` 只记成功样本、fast-search 无缺陷 |
| 悬空边 | 0 | 未重新注入，沿用上一轮已校验数据 |

该轮三项结论都落在已有节点上：`Space_Store`、`Upload_Observer` 的职责与缺陷记录写入正文，`fast-search.php` 不在图谱节点集合内；没有新增或删除任何图谱对象，当时没有重建 `graph-preview/`。

### 2.3 增量（2026-11-08 上传指标并发修复）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 新增 `ref` | `cls:Sakura\ImageOptimizer\Activation` → `cls:Sakura\ImageOptimizer\S3\Upload_Metrics`（`new`，w=2） | `ref` 599→601；`src → src/S3` 的 `modref` 权重 22→24 | §6.9.2、§12.4 |
| 新增 `ref` | `cls:Sakura\ImageOptimizer\S3\Upload_Metrics` → `cls:Sakura\ImageOptimizer\S3\Log`（w=2） | `ref` +1（模块内，不改变 `modref`） | §6.9.2 |
| 权重变化 | `Upload_Observer → Options`、`Upload_Observer → Upload_Metrics` | `w` 4→5（新增失败采集分支的引用） | §6.9 |
| 新增钩子 | `sakura_imgopt_s3_upload_failed`（`src/S3/Upload_Observer.php:31`） | 钩子 190→191 | §6.4、§9.2 |
| 节点 | 163 类 + 17 模块 | 180→180，无增删 | — |
| 表 | `sio_s3_upload_events` | 不进图；文档 §9.1 由 22 张增至 23 张 | §6.9.2、§9.1 |

该轮没有新增/删除类，所以节点与 `contains` 边不变；变化全部来自 `Upload_Metrics` 重写与失败钩子接线。`modref` 总数仍是 50，但 `src → src/S3` 这一格的权重从 22 升到 24，因为 `Activation` 现在引用 `Upload_Metrics`。页面四角数字已由 `graph-inject.mjs` 重写为 178 / 180 / 814 / 191。仓库只保留 `index.html` 与 `graph.json`，不再生成截图文件。

### 2.4 增量（2026-11-08 空间支付收口）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 节点大小 | `cls:…\Space_Store` | `val` 9.7324→10.1193 | §8.9 |
| 边权 | `cls:…\Space_Store` → `cls:…\Space_Rules`（`ref`） | `w` 34→44 | §8.9.4、§10.12 |
| 边权 | `cls:…\Space_Panel` → `cls:…\Space_Store`（`ref`） | `w` 12→10 | §8.9.6 |
| 节点/连线/钩子 | — | 180 / 814 / 191 不变；0 悬空边 | §13 |

该轮 P0/P1 在 `Space_Store` 增加支付白名单、退款补偿、退款重试与每日对账方法，方法总数上升，节点 `val` 从 9.7324 变为 10.1193；退款与重试路径增加对 `Space_Rules` 的静态引用，边权 34→44；P2 让 `Space_Panel` 共享 `points_state`/`points_plans`，直接引用 `Space_Store` 的次数从 12 降到 10。没有新增/删除类，所以节点、`contains` 边与钩子计数都不变。已重扫并重建 `docs/graph-preview/`，页面四角仍为 178 / 180 / 814 / 191。

### 2.5 增量（2026-11-09 1.22.9 版本收敛，历史）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 版本元数据 | `graph.json` 的 `meta.version` | 1.22.8→1.22.9 | §13 |
| 生成时间 | `meta.generatedAt` | 2026-11-08T16:10:16.319Z→2026-11-09T00:18:33.084Z | §13 |
| 页面副标题 | `index.html` | `sakura-tools v1.22.8`→`v1.22.9` | §13 |
| 节点/连线/钩子 | — | 180 / 814 / 191 不变；601 `ref` + 50 `modref` + 163 `contains`；0 悬空边 | §13 |

1.22.9 的代码差异只有插件头/常量/readme 的版本号、4 条译者注释和 1 条 PHPCS 例外（`src/UserFiles/Editor/Assets.php`、`src/UserFiles/Repositories/Folders.php`）。没有新增/删除类、方法或钩子，静态引用边也未变化；`Assets.php` 增加 4 行使生产总行数 60,460→60,464，但不改变节点、边权或布局。`Space_Store` 的 `val` 仍 10.1193，`Space_Store → Space_Rules` 的 `ref` 权重仍 44，`Space_Panel → Space_Store` 的 `ref` 权重仍 10，均沿用上一轮支付收口后的值。当时已重扫并重建 `docs/graph-preview/`，页面四角仍为 178 / 180 / 814 / 191。

1.22.9 部署后已在生产执行 `Upload_Metrics::maybe_install()`：新建 `nb_sio_s3_upload_events`（0 行，`sakura_imgopt_s3_upload_schema=2.0.0`），CLI `wp sakura-sync stats` 能读出旧 option 的 91 条尝试基线。生产另有 1.12.0 遗留的空表 `nb_sio_s3_uploads`（0 行，代码不再创建或使用），不计入文档 §9.1 的 23 张代码表。

### 2.6 上一轮增量（2026-11-09 1.23.0 编辑页面板，历史）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 版本元数据 | `graph.json` 的 `meta.version` | 1.22.9→1.23.0 | §13 |
| 生成时间 | `meta.generatedAt` | 2026-11-09T00:18:33.084Z→2026-11-09T02:08:43.079Z | §13 |
| 页面副标题 | `index.html` | `sakura-tools v1.22.9`→`v1.23.0` | §13 |
| 节点/连线/钩子 | — | 180 / 814 / 191 不变；601 `ref` + 50 `modref` + 163 `contains`；0 悬空边 | §13 |
| 文件行数 | — | 178 文件；生产总行数 60,464→60,468；`src/UserFiles` 21,171→21,174（+3），`includes/views` 4,732→4,733（+1） | §8.10、§10.13 |

该轮代码差异全部在编辑页文件面板的前端 fork：`assets/editor-picker-manager.js` 增加「插入」菜单项、拖拽跟手 badge 与 `sufm:drag-files` / `sufm:drag-end` 事件；`assets/editor-picker.js` 增加 `insert(forced, point)`、`rangeAtPoint()` 与正文投放层；`assets/editor-picker.css` 增加拖拽行、badge、投放层与深色/减少动效适配；`includes/views/editor-picker-app.php` 增加 `#sufm-i-insert` 图标（+1 行）；`src/UserFiles/Editor/Assets.php` 增加 `dropInsert` / `dropInsertHint` 两条文案（+2 行）；`src/UserFiles/Frontend/Tab.php` 增加 `dragInsert` 共享文案（+1 行）。这些都是 JS/CSS/视图/字符串，图谱不收录 JS 函数与模板，因此没有新增/删除类、方法、钩子或静态引用边。

当时已重新扫描 1.23.0 工作树并重建 `docs/graph-preview/`：页面四角仍为 178 / 180 / 814 / 191，`Space_Store` 的 `val` 仍 10.1193，`Space_Store → Space_Rules` 的 `ref` 权重仍 44，`Space_Panel → Space_Store` 的 `ref` 权重仍 10。变化只在版本元数据与页面副标题；该轮是编辑页前端交互，图谱层面没有拓扑变化。用户中心 `/user/files` 仍走 `sufm-front`，没有该轮编辑器投放层与「插入」菜单，UI 未变。

### 2.7 上一轮增量（2026-11-09 1.24.0 支付方式勾选、对账退避与历史回填，历史）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 版本元数据 | `graph.json` 的 `meta.version` | 1.23.0→1.24.0 | §13 |
| 生成时间 | `meta.generatedAt` | 2026-11-09T02:08:43.079Z→2026-11-09T07:12:35.784Z | §13 |
| 页面副标题 | `index.html` | `sakura-tools v1.23.0`→`v1.24.0` | §13 |
| 新增 `ref` | `cls:Nboke\UserFiles\CLI` → `cls:Nboke\UserFiles\Services\Space_Store`（`static`/`call`，w=4） | `ref` 601→602；总连线 814→815 | §8.9.9、§10.14 |
| 边权 | `Space_Store` → `Space_Settings`（`ref`） | `w` 18→22 | §8.9.2、§8.9.5 |
| 边权 | `Space_Store` → `Space_Rules`（`ref`） | `w` 44→48 | §8.9.4、§8.9.5 |
| 节点大小 | `cls:Nboke\UserFiles\Services\Space_Store` | `val` 10.119288512538816→10.369185117452577 | §8.9 |
| 节点大小 | `cls:Nboke\UserFiles\Space_Settings` | `val` 5.986651818838307→6.4 | §8.9.2 |
| 节点大小 | `cls:Nboke\UserFiles\CLI` | `val` 3.919183588453085→4.233202097703345 | §8.9.9 |
| 节点/钩子/表 | — | 180 / 191 / 23 不变；`contains` 163、`modref` 50 不变；0 悬空边 | §9.1、§13 |

本轮代码改动集中在 `src/UserFiles`：`Space_Settings` 增加 `allowed_methods` / `points_enabled` / `sanitize_methods`，`Space_Store` 增加对账状态与指数退避、历史授权审计与回填，`CLI` 增加 `grants` 子命令并直接调用 `Space_Store::audit_legacy_order_grants()`，因此多出一条 `CLI → Space_Store` 的静态引用。`Space_Store` 新增对 `Space_Settings::allowed_methods()` / `points_enabled()` 的读取、以及对 `Space_Rules::flush()` 的调用，导致两条边权上升；三个类的方法数增加，节点 `val` 随之上升。没有新增/删除类、钩子或表，所以节点数、`contains` 与 `modref` 都不变。

文件总行数 60,468→61,215；`src/UserFiles` 21,174→21,833（支付白名单、对账退避、历史审计与 CLI），`includes/views` 4,733→4,821（支付方式卡片）。已重新扫描工作树并重建 `docs/graph-preview/`，注入后 dry-run 为 `changed:false`、`dangling:0`。

### 2.8 上一轮增量（2026-11-09 1.24.1 文件面板快捷多选，历史）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 版本元数据 | `graph.json` 的 `meta.version` | 1.24.0→1.24.1 | §13 |
| 生成时间 | `meta.generatedAt` | 2026-11-09T07:12:35.784Z→本轮重扫时间 | §13 |
| 页面副标题 | `index.html` | `sakura-tools v1.24.0`→`v1.24.1` | §13 |
| 节点/连线/钩子 | — | 180 / 815 / 191 不变；602 `ref` + 50 `modref` + 163 `contains`；0 悬空边 | §13 |
| 节点大小与边权 | `Space_Store`、`Space_Settings`、`CLI` 等 | 全部不变：`Space_Store` 的 `val` 10.369185117452577、`Space_Store → Space_Settings` 22、`Space_Store → Space_Rules` 48 | §13 |

本轮代码差异全部在文件面板前端：`assets/userfiles.js` 与 `assets/editor-picker-manager.js` 增加快捷多选、全选按钮与按键处理；`assets/editor-picker.js` 增加编辑器侧选择同步与 Esc 分层；`includes/views/userfiles-tab.php` / `editor-picker-app.php` 增加路径栏按钮；`src/UserFiles/Frontend/Tab.php` / `Editor/Assets.php` 增加文案键；`assets/userfiles.css` / `editor-picker.css` 增加路径栏样式与列表复选框方框护栏。没有新增/删除类、方法、钩子、表或 REST 路由，所以节点、边、边权、`val` 全都不变；只有版本元数据与页面副标题需要更新。

文件总行数 61,215→61,227；`src/UserFiles` 21,833→21,838（+5），`includes/views` 4,821→4,828（+7）。已重新扫描 1.24.1 工作树并重建 `docs/graph-preview/`；按 §7 的注入命令写回后，dry-run 应为 `changed:false`、`dangling:0`。

### 2.9 上一轮增量（2026-11-09 1.24.2 S3/Sync 运维观测）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 新增 `ref` | `S3_CLI → Upload_Metrics`(1)、`S3_CLI → Delete_Jobs`(4)、`S3_CLI → Sync\Index_Repository`(1)、`S3_CLI → Sync\Metrics`(2) | `includes → src/S3` 40→43、`includes → src/Sync` 4→7 | §6.9.5、§7.7.1、§10.16 |
| 新增 `ref` | `Prometheus → Delete_Jobs`(3)、`Prometheus → Sync\Index_Repository`(2)、`Prometheus → Remote_Operation_Guard`(2) | `src/S3 → src/Sync` 33→35 | 同上 |
| 权重上升 | `S3_CLI → Remote_Operation_Guard` 3→10 | `ref` 602→609；总连线 815→822 | §13 |
| 节点 `val` | `S3_CLI` 3.577708763999664→3.919183588453085（新增 1 个公开方法）；`Remote_Operation_Guard` 3.577708763999664→4.233202097703345（新增 2 个公开方法） | 节点仍 180（163 类 + 17 模块） | §13 |
| 文件起始行 | `Prometheus` 24→25（新增 `use`，`val` 不变） | 无计数影响 | §13 |

本轮代码改动只在三个已有 PHP 类：`S3_CLI` 的 `health()` 读取上传指标、删除队列、同步索引与同步计数；`Remote_Operation_Guard` 增加 `pending($limit,$offset)` 分页、`count_reservations()` 与只读 `count_held()`；`Prometheus` 增加四个只读 gauge 并调用前两个类。没有新增/删除类、钩子、表或模块，所以 `contains` 163 与 `modref` 50 不变，新增的 7 条边全部是类级 `ref`。

文件总行数 61,227→61,424；`includes` 5,999→6,097（CLI 增加 `health` 与分页参数），`src/S3` 10,803→10,902（守卫分页/计数与 Prometheus gauge）。已重新扫描工作树并重建 `docs/graph-preview/`；注入后 dry-run 为 `changed:false`、`dangling:0`。

### 2.10 上一轮补测（2026-11-09，1.24.2，无代码变化）

2026-11-09 对主文档 §12 的三条验证空白做运行补测：`Quota` 的预留/提交/释放、删除审核 → `Executor` 的预览/确认/执行、`Uninstaller` 的显式删除与默认保留两轨、`Scan_Service` 的三种损坏游标、编辑页的 TinyMCE/纯 textarea/块编辑器与插入顺序。

补测没有改插件代码，也没有新增/删除类、方法、钩子、表或模块，因此不重扫、不重建图谱：节点 180（163 类 + 17 模块）、连线 822（609 `ref` + 50 `modref` + 163 `contains`）、钩子 191、悬空边 0，与 §2.9 完全一致；`docs/graph-preview/index.html` 与 `graph.json` 本轮未改。

正文对应：§8.10.7（编辑页覆盖与顺序）、§10.17（五组运行证据）、§12.6（三条空白关闭）、§12.7（新发现的卸载漏删 `file_tokens` 缺陷）。该缺陷修复后属于代码变化，届时必须重扫并重建图谱；本轮没有把它伪装成“无变化”。

### 2.11 本轮增量（2026-11-09 1.24.3 卸载修复）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 新增连线 | `cls:…\Uninstaller` → `cls:Nboke\UserFiles\Schema`（`ref`，w=2） | `ref` 609→610；总连线 822→823 | §10.17-C、§12.7 |
| 模块边权 | `mod:src` → `mod:src/UserFiles` 7→9 | `modref` 总数仍 50 | §13 |
| 不变 | 全部 163 个类、17 个模块、191 个钩子、163 条 `contains`；0 悬空边 | 节点仍 180 | §13 |

1.24.3 的代码差异只有 `src/Uninstaller.php:12` 引入 `Schema`，以及 `:150-154` 把硬编码 14 张表的循环改为遍历 `Schema::tables()`；`tests/UninstallerTest.php:55-95` 增加 fake-wpdb 回归测试。没有新增/删除类、方法、钩子或数据表，所有节点 `val` 不变，`contains` 与 `modref` 总数也不变；新增的 1 条边是类级静态引用，`src` → `src/UserFiles` 的模块边因此从 7 升到 9。生产总行数按扫描口径为 61,425（上一轮记录 61,424），`src/UserFiles` 仍 21,838 行。

已重新扫描工作树并重建 `docs/graph-preview/`：`index.html` 167,825 字节、md5 `0c2e22e9df6b19d55a403c536d6daae7`；`graph.json` 205,026 字节、md5 `2b64d1cf5f2284dbfc01d86e1216ba22`；注入后 dry-run 为 `changed:false`、`dangling:0`。副标题与 `meta.version` 均为 1.24.3。隔离环境复跑卸载的显式删除轨道：15 张 `t_sufm_*`（`t_sufm_file_tokens` 3,117 行）→ 0 张，`t_sio_*` 也 0；测试后从 dump 恢复样本库。

### 2.12 本轮增量（2026-11-10 覆盖升级自愈与钩子计数修正）

| 变更 | 图谱对象 | 计数影响 | 文档对应 |
|---|---|---|---|
| 新增类节点 | `cls:Sakura\ImageOptimizer\Upgrade_Guard`（`src/Upgrade_Guard.php:23`，`val` 2.2627） | 文件 178→179、类 163→164、节点 180→181 | §3.2、§10.18 |
| 新增 `contains` | `mod:src` → `cls:…\Upgrade_Guard` | `contains` 163→164；连线 823→824 | §13 |
| 新增 `ref` | `Plugin` → `Upgrade_Guard`（`static`+`call`，w=2） | `ref` 610→611；连线 824→825 | §3.2 |
| 新增 `ref` | `Plugin` → `Activation`（`static`+`call`，w=2） | `ref` 611→612；连线 825→826 | §3.2 |
| 钩子计数 | `stats.hooks` | 191→199 | §9.2 |
| 不变 | `modref` 50、其余 163 个类节点、17 个模块；0 悬空边 | — | §13 |

代码差异只有新增 `Upgrade_Guard`、在 `Plugin::boot()` 调用 `maybe_upgrade()`，以及 `tests/UpgradeGuardTest.php`、`tests/bootstrap.php` 的 shim 调整。没有改现有类的方法或删除任何边，所以旧节点 `val` 不变；`src` 模块的文件数 4→5，但模块 `val` 取 `max(4, 文件数×0.8)`，仍为 4。1.24.4 发布提交只把版本号提升并同步文档/图谱，不再改变拓扑；发布前的覆盖升级运行验证见 §2.13。

钩子变化不是新增接线，而是计数口径修正。旧图谱直接使用 `scanner.php` 的 `scan.hooks`：它只认第一个字符串字面量，遇到 `self::HOOK`、`Class::CONST`、`'update_option_' . Settings::OPTION` 这类常量/拼接会漏记。`docs/tools/collect-hooks.php` 用 `token_get_all` 重新解析生产代码，得到 203 次 `add_action`/`add_filter` 调用，其中 4 次是 `Hook_Loader` 的通用转发（`$hook`/`$entry['hook']`，不是具名注册），其余 **199 条具名注册**。旧附录 191 条里，190 条是 action/filter，其中 2 条是 `Hook_Loader` 的通用转发（只解析出字面量 `hook`），实际具名 **188** 条；另有 1 条 `add_submenu_page` 被误算成钩子。相对旧的 188 条，新增 11 条此前完全漏记的具名注册；另 10 条旧扫描只解析到 `update_option_`、`add_option_`、`delete_option_`、`admin_post_` 等前缀，2 条是行号漂移，属于同一注册的重解析，不改变总数。因此 191 − 1（submenu）− 2（通用转发）+ 11（新具名）= **199**，相对旧总数净增 8（action/filter +9，submenu −1）。

### 2.13 1.24.4 发布与覆盖升级运行验证

1.24.4 的代码差异只有版本号、`Upgrade_Guard` 接线与测试/文档，拓扑已在 §2.12 固定。发布前在测试站 `/root/sio-e2e` 用工作树软链把 `sakura_imgopt_version` 回退到 1.24.3、清掉每日清理 cron，再请求 `/wp-login.php`：HTTP 200（9,197 字节），读回版本 1.24.4、cron 恢复、锁与错误 transient 均不存在；10 个并发请求全部返回 302，`cleanup_events=1`，没有重复排程或残留锁。验证后测试站已恢复 1.24.3 软链与 option。细节见主文档 §10.18。

重建产物：主文档 `sakura-tools-代码结构-1.24.4.md` 303,711 字节（md5 `6769bbdc5b704c4275d66199f90285fd`）；页面 `index.html` 168,449 字节（md5 `7705a556669d6287798f6ec04417748e`）；`graph.json` 205,832 字节（md5 `a63b6e4312932923938f79f9108afd53`）。页面副标题与 `meta.version` 均为 1.24.4，四角仍为 179 / 181 / 826 / 199。

## 3. 节点与连线的定义

**节点字段**

| 字段 | 类节点 | 模块节点 | 用途 |
|---|---|---|---|
| `id` | `cls:<FQCN>` | `mod:<模块路径>` | 边的两端引用 |
| `label` | 短类名 | 模块路径 | 画布文字 |
| `type` | `class` | `module` | 渲染样式与颜色 |
| `module` | 所属模块 | 自身 | 模块列表与配色 |
| `file` / `line` | 文件与行号 | — | 详情面板定位到源码 |
| `val` | `sqrt(公开方法数) × 1.6`（至少 1.2） | `max(4, 文件数 × 0.8)` | 球体大小 |
| `color` | 跟随模块 | 固定 17 色 | 视觉分组 |
| `x`/`y`/`z` | 预计算坐标 | 预计算坐标 | 同数据布局一致 |

**连线字段**

| `type` | 数量 | `w` 含义 | 语义 | 示例 |
|---|---:|---|---|---|
| `contains` | 164 | 固定 1 | 模块 → 类（归属） | `mod:src/S3` → `cls:…\S3\Options` |
| `ref` | 612 | 静态引用次数 | 类 → 类 | `Executor` → `Time`（w=50） |
| `modref` | 50 | 跨模块引用边数 | 模块 → 模块 | `includes` → `src/S3`（w=43） |

`ref` 的 `kinds` 数组记录了引用形态（`use` / `type` / `call` / `static` / `new` / `implements` / `callback`），鼠标点开详情可看到"引用/被引用"两侧。

## 4. 节点清单（按模块，17 个模块节点）

| 模块 | 文件数 | 类节点 | 说明 |
|---|---:|---:|---|
| `src/UserFiles` | 46 | 46 | 用户文件空间，最大簇 |
| `src/S3` | 36 | 36 | 对象存储（清理前 37） |
| `src/Sync` | 17 | 17 | 同步索引 |
| `src/Thumbnail` | 15 | 15 | 缩略图重写 |
| `includes` | 14 | 14 | 旧层门面与装配 |
| `includes/views` | 12 | 0 | 后台视图模板，只有文件不含类 |
| `src/Image` | 10 | 10 | 图片处理 |
| `src/Admin` | 7 | 7 | 菜单、路由与布局 |
| `src/Contracts` | 5 | 5 | 5 个接口 |
| `src` | 5 | 5 | 插件入口、运行时与升级守卫 |
| `根文件` | 3 | 0 | 主文件、`uninstall.php`、`fast-search.php` |
| `src/Backup` | 2 | 2 | 备份仓储 |
| `src/Oss` | 2 | 2 | 阿里云 OSS 适配 |
| `src/Support` | 2 | 2 | 钩子加载器与工具 |
| `src/Filesystem` | 1 | 1 | 文件系统契约实现 |
| `src/Settings` | 1 | 1 | 设置仓储 |
| `src/Stats` | 1 | 1 | 统计仓储 |
| **合计** | **179** | **164** | 与 §2.2、附录 C 一致 |

## 5. 最粗的六条模块连线

| 权重 | 边 | 含义 |
|---:|---|---|
| 43 | `includes` → `src/S3` | 旧层装配直接调用 S3 的服务类；本轮 `S3_CLI` 新增对 S3 服务类的引用后 40→43 |
| 35 | `src/S3` → `src/Sync` | 上传完成后回写同步索引；本轮 `Prometheus` 引用 `Index_Repository` 后 33→35 |
| 34 | `src/Sync` → `src/S3` | 同步的动作队列复用 S3 的存储适配与选项 |
| 24 | `src` → `src/S3` | 插件启动时构造 S3 旁支；清理轮 `Uninstaller` 引用 `Upload_URL` 后 21→22，本轮 `Activation` 引用 `Upload_Metrics` 后再 22→24 |
| 22 | `includes` → `src/Image` | 图片门面装配 |
| 22 | `src/Image` → `src/Contracts` | 处理器实现接口 |

> S3 与 Sync 互指（35 / 34）是全图最粗的一对，文档 §11.1 把它标为"事实上是一个整体"，改动时必须成对评估。

## 6. 与文档条目的对应关系

| 图谱元素 | 数量 | 文档中的对应条目 | 共同的生成来源 |
|---|---:|---|---|
| 模块节点 | 17 | §2.2 模块总览表（17 行） | `scan.files` 按模块聚合 |
| 类节点 | 164 | 附录 A 类索引（164 行，每行给 `文件:行`） | `scan.nodes(kind=class)` |
| `ref` 边 | 612 | §11.1 矩阵、§11.3 最粗 12 条边 | `scan.edges` |
| `modref` 边 | 50 | §11.1 矩阵的非零格（17×17） | 类边按模块聚合 |
| 钩子 | 199 | 附录 B（199 行）+ §9.2 分布表 | `collect-hooks.php`（构建时合并） |
| 23 张自定义表 | — | §9.1（图谱不建表节点，避免图噪） | 测试站 `SHOW TABLES` |

## 7. 重建步骤（可直接复制执行）

```
# 0. 在插件仓库根目录执行（扫描器属于 DSH 的 codegraph 插件，路径按本机实际替换）
cd /path/to/wp-content/plugins/sakura-tools

# 1. 扫描事实表
php /root/DSH/plugins/dsh-codegraph/lib/scanner.php . > /tmp/scan.json

# 2. 提取类注释（"职责"列依据；缺这一步职责列会退回中文职责表）
php docs/tools/collect-comments.php . > /tmp/comments.json

# 3. 生成表格与图谱数据（输出到 /tmp/docparts）
node docs/tools/build-code-structure.mjs /tmp/scan.json /tmp/docparts /tmp/comments.json

# 4. 注入静态页：先 dry-run 看校验结果，再 --write
node docs/tools/graph-inject.mjs /tmp/docparts/graph.json docs/graph-preview
node docs/tools/graph-inject.mjs /tmp/docparts/graph.json docs/graph-preview --write

# 5. 重新组装文档正文
python3 docs/tools/assemble-doc.py --parts /tmp/docparts --chapters docs/tools/chapters

# 6. 渲染核对（可选；不产出仓库文件）
# DSH 内置浏览器无 WebGL，如需目视核对，用 Chrome + SwiftShader 打开页面。
# 任何截图只能写到 /tmp 并在核对后删除；仓库不保存 preview.png 或任何截图。
google-chrome --headless=new --no-sandbox --user-data-dir=/tmp/chrome-graph \
  --use-gl=angle --use-angle=swiftshader --enable-unsafe-swiftshader --hide-scrollbars \
  --window-size=1440,900 --virtual-time-budget=15000 \
  file://$PWD/docs/graph-preview/index.html
```

**改代码后必须做的事**：重跑 1–5。只要有一步跳过，图表与正文的数字就可能不一致——这正是旧版页面出现"178 / 706 / 111"的原因。第 3 步会在 Node 内自动调用 `docs/tools/collect-hooks.php` 补扫钩子；如果 PHP 不在 `PATH`，用 `PHP_BIN=/path/to/php` 指定。补扫器按文件 `use` 别名、命名空间和类常量解析第一参数，覆盖 `self::CONST`、`Class::CONST` 与字符串拼接；无法解析的通用转发（如 `$hook`）会排除，不会被算成具名钩子。

**维护中文职责**：`docs/tools/purposes.zh.json` 是 164 条"一句话职责"的人工译述表（键为 FQCN）。新增类若缺条目，表的职责列会退回源码注释原文，不会空着；建议补一条中文，便于中文读者扫读。

## 8. 一致性核对（本次已执行）

| # | 检查项 | 方法 | 结果 |
|---:|---|---|---|
| 1 | 类节点数 = 附录 A 行数 | 解析 graph.json 与 Markdown 表格 | 164 = 164 ✅ |
| 2 | 模块节点数 = §2.2 行数 | 同上 | 17 = 17 ✅ |
| 3 | 钩子数 = 附录 B 行数 | 同上 | 199 = 199 ✅ |
| 4 | 连线数 = 三类之和 | `612 + 164 + 50` | 826 ✅ |
| 5 | 悬空边 | `graph-inject.mjs` 每次注入前校验 | 0 ✅ |
| 6 | 同源重建 | 分章 `p1–p6` + `build-code-structure.mjs` 生成块重新组装，与仓库正文比对 | §6.8 清理后 `1c82079ea7777f5d5beeaf6b8a18e6bf`；§7.7 改写后 `f2948e675caf9dbdb3cf58d0395623c9`；§12 三项回写后 `b479d3fb379c695a2c8ce2eac2a1f02b`；上传指标修复稿 `affe363d81b6325cbe739b54aac28446`；删除预览图后 `971f0c3996a10bffb207fe7f6d4dccc6`；空间支付收口稿 `5eb2bf875d00d1e776cf89089e41ae6f`（219,712 字节）；1.22.9 版本稿 `1aba478232bdbb9b1d196eebee1f794e`（220,481 字节）；1.23.0 编辑页面板稿 `4a801b137efc0bc3805f765df8eac822`（238,153 字节）；1.24.0 支付方式/对账/回填稿 `0979157c31883875e25e2b12922f9050`（250,746 字节）；1.24.1 快捷多选稿 `48e0369200f4ea9d2227c63f476aaacd`（266,095 字节）；1.24.2 运维观测稿 `13485930f8e997e0529bca0951c7c238`（274,169 字节）；1.24.2 验证回写稿 `f65d07350edf37b11609c2abccafb546`（288,928 字节）；1.24.3 卸载修复稿 `3325b5e0ce8a359a9a46396fd595c6f6`（290,562 字节）；2026-11-10 覆盖升级稿 `7b457df0269b6ce3e97a4e8c1325a04a`（295,120 字节）；1.24.4 发布稿 `d4be0a7939a44153c187f04c4a485839`（298,733 字节）；2026-11-10 p2/p5 修正稿 `6769bbdc5b704c4275d66199f90285fd`（303,711 字节）✅ |
| 7 | 注入幂等 | `graph-inject.mjs` dry-run 与页面 `DATA` 比对 | 本轮 `--write` 后 dry-run 报 `changed:false`、`dangling:0`，页面 `DATA` 与 `graph.json` 的结构与计数完全一致 ✅ |
| 8 | 文档可重装 | `python3 docs/tools/assemble-doc.py --parts /tmp/docparts-wt3 --chapters docs/tools/chapters --out /tmp/verify-sakura-1244.md` | 与仓库正文逐字节一致，md5 `6769bbdc5b704c4275d66199f90285fd`（303,711 字节）✅ |
| 9 | 渲染 | Chrome headless + SwiftShader 打开页面核对（截图只写 `/tmp`，不落仓库） | 星簇正常；副标题 `sakura-tools v1.24.4`、164 类 / 17 模块；四角 179 / 181 / 826 / 199；S3 详情 36 文件 ✅ |
| 10 | 文件数一致 | `stats.files` = 附录 C 行数 | 179 = 179 ✅ |

> 第 9 项是必要的：DSH 内置浏览器没有 WebGL，页面会渲染成空白 canvas，用它"看图谱"会得出错误结论。从 2026-11-08 起仓库不再保存渲染截图，核对用截图只写到临时目录。第 7 项的幂等判据是页面 `DATA` 与 `graph.json` 的结构、计数、悬空边一致，不是 HTML 文件字节不变；`meta.generatedAt` 会随生成时间变化，重建时以 dry-run 的 `changed`、节点/连线计数和悬空边为准。

**2026-11-08 各轮补充**：上午核实文档 §5.7 的两条待确认（缩略图 `provider` 取值、HTML 旧引擎 ob 回调边界），新增 §10.7、§12 由 10 项减为 8 项，未动类与钩子；傍晚处理 §6.8 四条遗留代码（删 `Upload_Queue`、收口 `Upload_Metrics::snapshot` 的 `counts/backlog`、说明两套 `target_hash` 不统一、标注 `Sync_Status` 遗留方法并接入 `Upload_URL::PREVIOUS_PATH_OPTION`），新增 §10.8、§12 由 8 项减为 5 项，图谱随之重建为 **180 / 812 / 190**；晚间处理 §7.7 三项（`sio_sync_operations` 准入账本、Sync 中心 admin-ajax、`scan_cursor` 双语义），新增 §10.9、§12 由 5 项减为 3 项，图谱不变；随后处理 §12 剩余三项（生产只读验证 `fast-search.php` 无额外 Web 限制、`grant_from_order()` 字段写入缺陷、`Upload_Observer` 只记录成功样本），改写 §8.9 并新增 §10.10，§12 由 3 项清零并转为两项已确认缺陷——这一轮未改代码，图谱仍为 **180 / 812 / 190**，无需重建；深夜完成上传指标并发修复（追加事件表 + 成功/失败双钩子），新增 §6.9 与 §10.11、§12 重写，图谱重建为 **180 / 814 / 191**——节点不变、`ref` +2、钩子 +1；随后完成空间支付收口（默认只售余额、订单按渠道记账、退款补偿与每日对账、面板空态），改写 §8.9、新增 §10.12、重写 §12，图谱再次重建为 **180 / 814 / 191**——节点不变，`Space_Store` 的 val 与两条 `ref` 权重更新（§2.4）。

**2026-11-08 归档调整**：按维护要求删除 `docs/graph-preview/preview.png`，图谱只保留 `index.html` 与 `graph.json`，后续不再生成截图文件；图谱数据未变（180 / 814 / 191），本次仅更新文档与交付清单。

**2026-11-09 版本收敛（1.22.9）**：插件版本统一到 **1.22.9**，消除全量 PHPCS 的 5 个既有错误；代码结构与钩子集合未变，重扫仍为 **178 / 163 / 180 / 814 / 191**，图谱仅更新 `meta.version`、`generatedAt` 与页面副标题（§2.5）。

**2026-11-09 编辑页面板（1.23.0）**：提交 `2fec6a3` 增加编辑页文件面板的拖拽插入、右键「插入」与批量条归位；只动 JS/CSS/视图/字符串，没有新增 PHP 类、方法或钩子，图谱重建后仍为 **178 / 163 / 180 / 814 / 191**，仅 `meta.version`、`generatedAt` 与副标题更新（§2.6）。生产总行数 60,464→60,468，`src/UserFiles` 21,171→21,174，`includes/views` 4,732→4,733；文档新增 §8.10 与 §10.13。


**2026-11-09 支付方式勾选、对账退避与历史回填（1.24.0）**：1.24.0 连同此前未发布的 `509d8cb`（支付方式勾选与积分独立开关）和 `1f88ca8`（对账租约/退避、历史订单授权审计与 `wp sufm grants`）一起发布。图谱重建后为 **178 / 163 / 180 / 815 / 191**：新增 `CLI → Space_Store` 一条 `ref`（w=4），`Space_Store → Space_Settings` 18→22，`Space_Store → Space_Rules` 44→48；`Space_Store` 的 `val` 10.1193→10.3692，`Space_Settings` 5.9867→6.4，`CLI` 3.9192→4.2332。没有新增/删除类、钩子或表（`contains` 163、`modref` 50 不变）。文件总行数 60,468→61,215，`src/UserFiles` 21,174→21,833，`includes/views` 4,733→4,821；文档新增 §10.14，改写 §8.9 与 §12（§2.7）。

**2026-11-09 文件面板快捷多选（1.24.1）**：提交 `427101c` 给用户中心与编辑页文件面板增加 Shift 连选、Ctrl/⌘ 点选、Ctrl/⌘+A 全选、Esc 清空与网格全选按钮，并在列表加复选框方框护栏。改动 9 个文件，全部在 JS/CSS/视图/文案；没有新增 PHP 类、方法或钩子，图谱重建后仍为 **178 / 163 / 180 / 815 / 191**，仅 `meta.version`、`generatedAt` 与副标题更新（§2.8）。生产总行数 61,215→61,227，`src/UserFiles` 21,833→21,838，`includes/views` 4,821→4,828；文档新增 §8.11 与 §10.15。

**2026-11-09 S3/Sync 运维观测（1.24.2）**：`S3_CLI` 新增只读 `health` 与 `remote-operations` 分页/计数，`Remote_Operation_Guard` 新增 `pending($limit,$offset)`、`count_reservations()`、`count_held()`，`Prometheus` 新增四个只读 gauge；没有新增/删除类、钩子、表或模块。图谱重建后为 **178 / 163 / 180 / 822 / 191**（609 `ref` + 50 `modref` + 163 `contains`）：新增 7 条 `ref`，`S3_CLI → Remote_Operation_Guard` 3→10，模块边 `includes → src/S3` 40→43、`includes → src/Sync` 4→7、`src/S3 → src/Sync` 33→35；`S3_CLI` 的 `val` 3.577708763999664→3.919183588453085，`Remote_Operation_Guard` 3.577708763999664→4.233202097703345（§2.9）。文件总行数 61,227→61,424，`includes` 5,999→6,097，`src/S3` 10,803→10,902；文档新增 §6.9.5、§10.16，改写 §7.7.1 与 §12.3。

**2026-11-09 卸载漏删 file_tokens（1.24.3）**：`drop_sufm_tables()` 改为遍历 `Schema::tables()`（`src/Uninstaller.php:150-154`），补 `tests/UninstallerTest.php:55-95`；隔离库复跑显式删除轨道 15 张表（3,117 条 token）→ 0。图谱新增 `Uninstaller → Schema` 一条 `ref`（w=2），模块边 `src → src/UserFiles` 7→9，重建为 **178 / 163 / 180 / 823 / 191**（610 `ref` + 50 `modref` + 163 `contains`）；没有节点 `val` 变化（§2.11）。生产总行数 61,424→61,425；文档 §10.17-C 改为修复前后对照，§12.7 改为已修复缺陷。

## 9. 读图方法

| 操作 | 效果 |
|---|---|
| 拖拽 | 旋转；滚轮缩放 |
| 单击类节点 | 右上角显示文件、行号、方法数、入度/出度、所属模块，以及"被引用/引用出"两组邻居 |
| 左侧模块行 | 选中该模块并把镜头推近（`focusNode`） |
| 连线亮度 | 与 `w` 成正比：越亮表示引用次数越多 |
| 球体大小 | 模块 = `max(4, 文件数×0.8)`；类 = `sqrt(公开方法数)×1.6`（至少 1.2） |
| 颜色 | 类的颜色 = 所属模块（与左侧列表一致） |

页面加载后约 1.5 秒会自动选中 `src/S3` 作为示例（便于第一次打开就有内容可看），可用左侧列表切换。

## 10. 已知局限与待确认

| # | 项 | 说明 |
|---:|---|---|
| 1 | 不收录全局函数与模板 | `scan.json` 的 64 个 function 节点含 tests/stubs；生产代码只有 `fast-search.php:27` 的 `sakura_fast_search_fail()`。12 个视图模板、`fast-search.php` / `uninstall.php` 不进图，只在附录 C 与正文出现。它们是"没有类的文件"，硬塞进类图会误导结构判断 |
| 2 | 入度/出度是静态引用 | token 级（`use` / `new` / 静态调用 / 类型声明），不是运行时调用次数；`call_user_func`、反射、字符串钩子都统计不到 |
| 3 | 181 vs 179 文件 | 旧导出 181、清理前 179、本轮 179；早期删除 `Upload_Queue.php` 可解释一次 -1，剩余差异的旧排除规则未记录、未逐个比对 → **待确认** |
| 4 | 新旧节点无法逐一对齐 | id 规则从短名改为 FQCN，见 §2；若要历史对比，请保留每版 `graph.json` |
| 5 | 图谱是静态快照 | 不含时间维度与 git 历史；"谁最近改的"仍要查 git |
| 6 | 布局不是力导向结果 | 坐标为确定性生成（模块球面 + 模块内错位），因此视觉上的"聚拢"只反映归属，不反映连接强度 |
| 7 | 页面需联网 | `index.html` 只内嵌数据，渲染库 `3d-force-graph` 从 jsdelivr CDN 加载；完全离线时会空白。若需要离线分发，把该库下载到 `docs/graph-preview/vendor/` 并改 script 的 src 即可 |

## 附录：本次交付的文件

| 文件 | 大小 | 作用 |
|---|---:|---|
| `docs/sakura-tools-代码结构-1.24.4.md` | 297 KB（303,711 字节） | 正文：§0–§13 + 附录 A–D（2026-11-10：覆盖升级自愈 + 计数复核；生产 61,562 行） |
| `docs/sakura-tools-图谱同步-1.24.4.md` | 42 KB（43,165 字节） | 图谱变更与重建说明 |
| `docs/graph-preview/index.html` | 165 KB（168,449 字节） | 单文件 3D 图谱（数据内嵌；3d-force-graph 库走 CDN，需联网） |
| `docs/graph-preview/graph.json` | 201 KB（205,832 字节） | 同数据，供脚本消费 |
| `docs/tools/build-code-structure.mjs` | 16 KB（16,036 字节） | 事实表 → 表格 + graph.json |
| `docs/tools/graph-inject.mjs` | 3.1 KB（3,180 字节） | graph.json → index.html（含悬空边校验、幂等） |
| `docs/tools/collect-hooks.php` | 17 KB（17,673 字节） | 补扫 `add_action`/`add_filter` 的常量与拼接钩子，输出 hooks.json |
| `docs/tools/assemble-doc.py` | 2.5 KB（2,591 字节） | 分章 + 表格 → 正文 |
| `docs/tools/collect-comments.php` | 3.0 KB（3,098 字节） | 类 docblock 提取 |
| `docs/tools/purposes.zh.json` | 15 KB（15,802 字节） | 163 条中文职责（Upgrade_Guard 走类注释回退） |
| `docs/tools/chapters/p1–p6.md` | 228 KB（233,486 字节） | 正文源片段（改正文改这里，再重组装） |

> 图谱可视化只交付 `index.html`（数据内嵌）与 `graph.json`；从 2026-11-08 起不再保存 `preview.png` 或其他渲染截图。
