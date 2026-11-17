# 文档目录

这个目录里的文件按「**能不能照着做事**」分成四类。先看你要做的事属于哪一类。

## 1. 开发规则 — 改代码前必读

[`docs/开发规则/`](开发规则/) 是当前唯一有效的开发规范，八篇各有明确边界，不重复。

| # | 文档 | 什么时候读 |
|---|---|---|
| 00 | [索引](开发规则/00-索引.md) | **从这里开始。** 五分钟上手路径 + 按任务找文档 + 十条铁律 |
| 01 | [架构与装配](开发规则/01-架构与装配.md) | 想知道插件怎么启动、模块怎么装配、路由怎么走、数据落在哪 |
| 02 | [新增功能](开发规则/02-新增功能.md) | 正要做功能：改动分类 → 落哪个模块 → 服务类 → AJAX → 新页面 → 测试 |
| 03 | [后台 UI](开发规则/03-后台UI.md) | 动后台界面：样式分层、设计令牌、间距、边框、页头、弹窗、控件、移动端 |
| 04 | [数据与存储](开发规则/04-数据与存储.md) | 动数据：option、`Card_Form`、建表、迁移、卸载、文件、队列、多站点 |
| 05 | [测试与质量](开发规则/05-测试与质量.md) | 写测试，或 PHPUnit / PHPStan / PHPCS 报错要知道口径 |
| 06 | [发布流程](开发规则/06-发布流程.md) | 发版：版本号、changelog、三关检查、打包、GitHub Release、部署、回滚 |
| 07 | [S3 存储生命周期](开发规则/07-S3存储生命周期.md) | 动 S3 存储：配置状态机、绑定与锁定、高风险选项确认、删除与连带清理、换桶唯一路径 |
| 08 | [OpenList 迁移通道](开发规则/08-OpenList迁移通道.md) | 换桶时搬对象：OpenList 部署与权限、挂载与驱动字段、`/api/fs/copy` 异步语义、任务 state、迁移五步 |

## 2. 生成产物 — 查，不要手改

| 文件 | 内容 | 怎么更新 |
|---|---|---|
| [代码结构文档](sakura-tools-代码结构-1.28.0.md) | 文件树、类职责、依赖矩阵、钩子分布、类/钩子/文件三个附录（1.28.0 生成；1.28.1 结构未变） | `docs/tools/` 五步管线 |
| [3D 图谱](graph-preview/index.html) | 模块与类的交互式关系图，单文件、无外部依赖 | 同上（第 4 步写入图数据） |

两者同源：都从一次代码扫描产出。GitHub 不执行页面脚本，图谱要克隆后用浏览器打开。

生成管线（在仓库根执行）：

```bash
php /root/DSH/plugins/dsh-codegraph/lib/scanner.php . > /tmp/scan.json
php docs/tools/collect-comments.php . > /tmp/comments.json
node docs/tools/build-code-structure.mjs /tmp/scan.json /tmp/docparts /tmp/comments.json
node docs/tools/graph-inject.mjs /tmp/docparts/graph.json docs/graph-preview --write
python3 docs/tools/assemble-doc.py --parts /tmp/docparts --out docs/sakura-tools-代码结构-<版本>.md
```

正文分章在 `docs/tools/chapters/`（`p1`–`p6`，拼接顺序 `p1 → p2 → p5 → p3 → p4 → p6`）。
表格、图谱与统计数字由管线刷新；章节正文是手写的，改行为时要同步改对应章节。

## 3. 归档 — 不代表现状

[`docs/history/`](history/) 里是**历史记录**，只用于追溯「当时为什么这么做」。每份开头都有醒目的声明。

| 文件 | 归档原因 |
|---|---|
| [DEVELOPMENT.md](history/DEVELOPMENT.md) | 早期开发流水；文件结构章节停在 1.3.3，写的是 `includes/class-*.php` 六个类 |
| [后台UI重构方案.md](history/后台UI重构方案.md) | 2026 年那次后台改版的实施方案，已执行完毕 |
| [sakura-tools-代码结构-1.25.9.md](history/sakura-tools-代码结构-1.25.9.md) | 1.25.9 时期的代码结构产物 |
| [sakura-tools-代码结构-1.27.5.md](history/sakura-tools-代码结构-1.27.5.md) | 1.27.5 时期的代码结构产物（1.27.6 换版时归档） |
| [sakura-tools-代码结构-1.27.6.md](history/sakura-tools-代码结构-1.27.6.md) | 1.27.6 时期的代码结构产物（1.28.0 换版时归档） |
| [sakura-tools-图谱同步-1.25.9.md](history/sakura-tools-图谱同步-1.25.9.md) | 图谱口径说明；生成方法仍适用，数字属 1.25.9 |

**这些文件里的路径、行号、类名一律不可信。** 需要现状就看第 2 类，或直接读代码。

## 4. 其它

| 位置 | 内容 |
|---|---|
| [`docs/tools/`](tools/) | 上表的生成脚本（scanner 调用、注释采集、章节组装、图谱注入） |
| [`DECISIONS.md`](../DECISIONS.md)（仓库根） | 关键设计取舍与已知边界，记录「为什么」而不是「现在有什么」 |
| [`README.md`](../README.md)（仓库根） | 面向使用者的功能、安装、配置说明 |
| [`readme.txt`](../readme.txt)（仓库根） | WordPress 插件目录格式：安装说明、FAQ、完整 changelog |

## 维护约定

- **规则只有一个出处。** 新增一条规则写进对应那一篇；推翻旧规则时**删掉旧的**，不要两份并存。
- **改代码顺手改文档。** 判据：这次改动会不会让一个新人照文档操作失败？会就必须改。
- **生成产物不手改。** 代码结构文档与图谱都从扫描结果产出，手改会在下次生成时丢失。
- **归档不删。** 文档作废时移到 `docs/history/` 并加声明，保留追溯价值。
- **文档不进发布包。** `bin/build-plugin-zip.sh` 会排除 `docs/`，可以放心写细节。
