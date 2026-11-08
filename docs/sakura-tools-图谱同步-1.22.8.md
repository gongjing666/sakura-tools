# sakura-tools 3D 图谱同步说明（v1.22.8）

> 配套文档：[sakura-tools 代码结构 1.22.8](sakura-tools-代码结构-1.22.8.md)。本文只回答三件事：**图谱变了什么、怎么和文档对上、以后怎么重建**。

## 0. 结论

- 本次是**全量重新生成**，不是增量补丁：同一份扫描事实表同时产出文档表格与图谱数据，这是"文档与图谱不脱节"的机制，而不是一次性手工对齐。
- 规模：**181 节点**（164 类 + 17 模块）/ **812 连线**（598 `ref` + 164 `contains` + 50 `modref`）/ **190 钩子** / **0 悬空边**。
- 旧页面的页脚数字（178 文件 / 162 节点 / 706 关系 / 111 钩子）与它自己的 `graph.json` 统计（181 文件 / 162 节点 / 761 关系）**本来就不一致**——那份页面是手工改数字改出来的。现在页脚数字由 `stats` 注入，不会再漂。
- 生成、注入、组装三个脚本已固化到 `docs/tools/`，可在任意机器重跑（§7）。

## 1. 数据管线：谁生成谁

```
scanner.php（token_get_all 三趟静态扫描）
        │  /tmp/scan.json        179 文件 / 164 类 / 190 钩子 / 全部边
        ├── collect-comments.php → /tmp/comments.json   164 条类注释（"职责"列的依据）
        ▼
build-code-structure.mjs
        ├── modules.md  class-index.md  file-index.md  hooks.md  module-matrix.md  top-edges.md
        ├── classes/<模块>.md   15 个分类表
        └── graph.json          181 节点 / 812 连线 / 确定性坐标
        ▼
graph-inject.mjs ──► docs/graph-preview/index.html（内嵌 DATA）+ graph.json + preview.png
        ▼
assemble-doc.py  ──► sakura-tools-代码结构-1.22.8.md（分章正文 + 上述表格）
```

| 步骤 | 命令（在插件根目录下执行） | 产物 |
|---|---|---|
| 1 扫描 | `php /root/DSH/plugins/dsh-codegraph/lib/scanner.php . > /tmp/scan.json` | 事实表 |
| 2 注释 | `php docs/tools/collect-comments.php . > /tmp/comments.json` | 164 条类 docblock |
| 3 生成 | `node docs/tools/build-code-structure.mjs /tmp/scan.json /tmp/docparts /tmp/comments.json` | 表格 + graph.json |
| 4 注入 | `node docs/tools/graph-inject.mjs /tmp/docparts/graph.json docs/graph-preview --write` | index.html + graph.json |
| 5 组装 | `python3 docs/tools/assemble-doc.py` | 代码结构文档 |

> 第 4 步不带 `--write` 是 dry-run：只校验悬空边并打印将发生的改动，不落盘。悬空边不为 0 时脚本直接退出（退出码 1），不允许把坏数据写进页面。

## 2. 与上一版导出的对比

| 项 | 旧导出（`/tmp/graph.json.bak`） | 本次 | 说明 |
|---|---:|---:|---|
| 文件数 | 181 | 179 | 口径改为"生产 PHP"，排除 `tests/`、`stubs/`、`.dsh-ops/`（见 §10 待确认 3） |
| 模块 | 15 | 17 | 根文件统一命名 `根文件`、`src` 单列、`includes` 与 `includes/views` 分开 |
| 类 | 147 | 164 | 见下方 id 规则变更 |
| 节点 | 162 | 181 | 147+15 → 164+17 |
| 连线 | 761 | 812 | 559 ref + 147 contains + 55 modref → 598 + 164 + 50 |
| 钩子 | 未收录（页脚写死 111） | 190（`stats.hooks`） | 现在页脚数字来自数据 |
| 布局 | 力导向 320 次迭代，radius 1699.4 | 确定性球面，radius 1710，`iterations: 0` | 同数据每次坐标一致，可 diff |
| 节点 id | 短类名，如 `cls:Alerting` | FQCN，如 `cls:Sakura\ImageOptimizer\S3\Alerting` | 见下方说明 |

**为什么改 id 规则**：当前 164 个类里，短名唯一的只有 151 个，**10 组短名重复、共 23 个类**（`Assets`×3、`Module`×3、`CLI`×3，`Stats`/`Size_Catalog`/`Schema`/`Context`/`Generic`/`Provider`/`Settings` 各 ×2）。用短名做 id，这些类会落到同一个节点上、边也会被合并。旧导出只有 147 个类，**无法据此证明当年发生过合并**（旧时点的代码库本身就更小），但这个风险在新方案里已经不存在。

**代价**：新旧节点无法逐 id 对齐，所以本次不做"新增/删除节点清单"，只给可复核的计数与行数核对（§6）。如需逐版追踪，保留每版的 `graph.json` 即可——`meta.version` 已写入版本号。

## 3. 节点与连线的定义

**节点字段**

| 字段 | 类节点 | 模块节点 | 用途 |
|---|---|---|---|
| `id` | `cls:<FQCN>` | `mod:<模块路径>` | 边的两端引用 |
| `label` | 短类名 | 模块路径 | 画布文字 |
| `type` | `class` | `module` | 渲染样式与颜色 |
| `module` | 所属模块 | 自身 | 模块列表与配色 |
| `file` / `line` | 文件与行号 | — | 详情面板定位到源码 |
| `val` | `1 + log` 方法数 | 文件数 | 球体大小 |
| `color` | 跟随模块 | 固定 17 色 | 视觉分组 |
| `x`/`y`/`z` | 预计算坐标 | 预计算坐标 | 同数据布局一致 |

**连线字段**

| `type` | 数量 | `w` 含义 | 语义 | 示例 |
|---|---:|---|---|---|
| `contains` | 164 | 固定 1 | 模块 → 类（归属） | `mod:src/S3` → `cls:…\S3\Options` |
| `ref` | 598 | 静态引用次数 | 类 → 类 | `Executor` → `Time`（w=50） |
| `modref` | 50 | 跨模块引用边数 | 模块 → 模块 | `includes` → `src/S3`（w=40） |

`ref` 的 `kinds` 数组记录了引用形态（`use` / `type` / `call` / `static` / `new` / `implements` / `callback`），鼠标点开详情可看到"引用/被引用"两侧。

## 4. 节点清单（按模块，17 个模块节点）

| 模块 | 文件数 | 类节点 | 说明 |
|---|---:|---:|---|
| `src/UserFiles` | 46 | 46 | 用户文件空间，最大簇 |
| `src/S3` | 37 | 37 | 对象存储 |
| `src/Sync` | 17 | 17 | 同步索引 |
| `src/Thumbnail` | 15 | 15 | 缩略图重写 |
| `includes` | 14 | 14 | 旧层门面与装配 |
| `includes/views` | 12 | 0 | 后台视图模板，只有文件不含类 |
| `src/Image` | 10 | 10 | 图片处理 |
| `src/Admin` | 7 | 7 | 菜单、路由与布局 |
| `src/Contracts` | 5 | 5 | 5 个接口 |
| `src` | 4 | 4 | 插件入口与运行时 |
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
| 40 | `includes` → `src/S3` | 旧层装配直接调用 S3 的服务类 |
| 34 | `src/Sync` → `src/S3` | 同步的动作队列复用 S3 的存储适配与选项 |
| 33 | `src/S3` → `src/Sync` | 上传完成后回写同步索引 |
| 22 | `includes` → `src/Image` | 图片门面装配 |
| 22 | `src/Image` → `src/Contracts` | 处理器实现接口 |
| 21 | `src` → `src/S3` | 插件启动时构造 S3 旁支 |

> S3 与 Sync 互指（34 / 33）是全图最粗的一对，文档 §11.1 把它标为"事实上是一个整体"，改动时必须成对评估。

## 6. 与文档条目的对应关系

| 图谱元素 | 数量 | 文档中的对应条目 | 共同的生成来源 |
|---|---:|---|---|
| 模块节点 | 17 | §2.2 模块总览表（17 行） | `scan.files` 按模块聚合 |
| 类节点 | 164 | 附录 A 类索引（164 行，每行给 `文件:行`） | `scan.nodes(kind=class)` |
| `contains` 边 | 164 | §4–§8 各子系统的类表（同模块归属） | 模块 → 类 |
| `ref` 边 | 598 | §11.1 矩阵、§11.3 最粗 12 条边 | `scan.edges` |
| `modref` 边 | 50 | §11.1 矩阵的非零格（17×17） | 类边按模块聚合 |
| 钩子 | 190 | 附录 B（190 行）+ §9.2 分布表 | `scan.hooks` |
| 22 张自定义表 | — | §9.1（图谱不建表节点，避免图噪） | 测试站 `SHOW TABLES` |

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
python3 docs/tools/assemble-doc.py

# 6. 截图（DSH 内置浏览器无 WebGL，必须用 Chrome + SwiftShader）
/usr/bin/google-chrome --headless=new --no-sandbox --use-gl=angle --use-angle=swiftshader \
  --enable-unsafe-swiftshader --hide-scrollbars --window-size=1440,900 \
  --virtual-time-budget=15000 --screenshot=/tmp/graph.png \
  file://$PWD/docs/graph-preview/index.html
cp /tmp/graph.png docs/graph-preview/preview.png
```

**改代码后必须做的事**：重跑 1–5。只要有一步跳过，图表与正文的数字就可能不一致——这正是旧版页面出现"178 / 706 / 111"的原因。

**维护中文职责**：`docs/tools/purposes.zh.json` 是 164 条"一句话职责"的人工译述表（键为 FQCN）。新增类若缺条目，表的职责列会退回源码注释原文，不会空着；建议补一条中文，便于中文读者扫读。

## 8. 一致性核对（本次已执行）

| # | 检查项 | 方法 | 结果 |
|---:|---|---|---|
| 1 | 类节点数 = 附录 A 行数 | 解析 graph.json 与 Markdown 表格 | 164 = 164 ✅ |
| 2 | 模块节点数 = §2.2 行数 | 同上 | 17 = 17 ✅ |
| 3 | 钩子数 = 附录 B 行数 | 同上 | 190 = 190 ✅ |
| 4 | 连线数 = 三类之和 | `598 + 164 + 50` | 812 ✅ |
| 5 | 悬空边 | `graph-inject.mjs` 每次注入前校验 | 0 ✅ |
| 6 | 同源重建 | 用同一事实表重建全部表格与 graph.json，逐文件 diff | 15 个类表 + 6 个汇总表**字节一致**；graph 仅 `generatedAt` 不同 ✅ |
| 7 | 注入幂等 | 连续执行两次 | 第二次 `changed:false` ✅ |
| 8 | 文档可重装 | 用 `docs/tools/chapters` + `docs/tools/assemble-doc.py` 重新组装 | 与仓库内文档字节一致 ✅ |
| 9 | 渲染 | Chrome headless + SwiftShader 截图 `preview.png` | 星簇正常、四角数字 179 / 181 / 812 / 190 ✅ |
| 10 | 文件数一致 | `stats.files` = 附录 C 行数 | 179 = 179 ✅ |

> 第 9 项是必要的：DSH 内置浏览器没有 WebGL，页面会渲染成空白 canvas，用它"看图谱"会得出错误结论。

**2026-11-08 补充**：文档 §5.7 的两条待确认（缩略图 `provider` 取值、HTML 旧引擎 ob 回调边界）已用静态 + 运行期证据核实，正文新增 §10.7、§12 由 10 项减为 8 项。这一轮只改正文结论，**没有新增类、没有改动钩子**，因此 `graph.json` 与 181/812/190 三个数字不变；上表 10 项核对结论继续有效，无需重建图谱。

## 9. 读图方法

| 操作 | 效果 |
|---|---|
| 拖拽 | 旋转；滚轮缩放 |
| 单击类节点 | 右上角显示文件、行号、方法数、入度/出度、所属模块，以及"被引用/引用出"两组邻居 |
| 左侧模块行 | 选中该模块并把镜头推近（`focusNode`） |
| 连线亮度 | 与 `w` 成正比：越亮表示引用次数越多 |
| 球体大小 | 模块 = 文件数；类 = 方法数（对数缩放） |
| 颜色 | 类的颜色 = 所属模块（与左侧列表一致） |

页面加载后 1.5 秒会自动选中 `src/S3` 作为示例（便于第一次打开就有内容可看），可用左侧列表切换。

## 10. 已知局限与待确认

| # | 项 | 说明 |
|---:|---|---|
| 1 | 不收录全局函数与模板 | 64 个全局函数、12 个视图模板、`fast-search.php` / `uninstall.php` 不进图，只在附录 C 与正文出现。它们是"没有类的文件"，硬塞进类图会误导结构判断 |
| 2 | 入度/出度是静态引用 | token 级（`use` / `new` / 静态调用 / 类型声明），不是运行时调用次数；`call_user_func`、反射、字符串钩子都统计不到 |
| 3 | 181 vs 179 文件 | 旧导出 181、本次 179，差 2 个文件；旧导出的排除规则未记录，未逐个比对 → **待确认** |
| 4 | 新旧节点无法逐一对齐 | id 规则从短名改为 FQCN，见 §2；若要历史对比，请保留每版 `graph.json` |
| 5 | 图谱是静态快照 | 不含时间维度与 git 历史；"谁最近改的"仍要查 git |
| 6 | 布局不是力导向结果 | 坐标为确定性生成（模块球面 + 模块内错位），因此视觉上的"聚拢"只反映归属，不反映连接强度 |
| 7 | 页面需联网 | `index.html` 只内嵌数据，渲染库 `3d-force-graph` 从 jsdelivr CDN 加载；完全离线时会空白。若需要离线分发，把该库下载到 `docs/graph-preview/vendor/` 并改 script 的 src 即可 |

## 附录：本次交付的文件

| 文件 | 大小 | 作用 |
|---|---:|---|
| `docs/sakura-tools-代码结构-1.22.8.md` | 152 KB | 正文：§0–§13 + 附录 A–D（2026-11-08 更新 §5.7 / §10.7 / §12） |
| `docs/sakura-tools-图谱同步-1.22.8.md` | 本文 | 图谱变更与重建说明 |
| `docs/graph-preview/index.html` | 166 KB | 单文件 3D 图谱（数据内嵌；3d-force-graph 库走 CDN，需联网） |
| `docs/graph-preview/graph.json` | 203 KB | 同数据，供脚本消费 |
| `docs/graph-preview/preview.png` | 205 KB | 1440×900 渲染截图 |
| `docs/tools/build-code-structure.mjs` | 15 KB | 事实表 → 表格 + graph.json |
| `docs/tools/graph-inject.mjs` | 3.1 KB | graph.json → index.html（含悬空边校验、幂等） |
| `docs/tools/assemble-doc.py` | 2.5 KB | 分章 + 表格 → 正文 |
| `docs/tools/collect-comments.php` | 2.9 KB | 类 docblock 提取 |
| `docs/tools/purposes.zh.json` | 16 KB | 164 条中文职责 |
| `docs/tools/chapters/p1–p6.md` | 74 KB | 正文源片段（改正文改这里，再重组装） |
