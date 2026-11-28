# 2026-11-27 后台 React + REST 审计报告

对象：/root/work/sakura-tools @ main，1.30.0-beta-7 工作树（审计后升 1.30.0-beta-8）。
环境：实验站 https://demo.77il.cn（PHP 8.4.28 / WP 7.1.5 / 子比 9.2 / QM 已装）；生产站未触碰。

## 结论
- 阻断 0；高 13（安全 1、CSS 1、React 2、文档 4、性能 5），其中已修 9、待定 4（3 条无上限读取待做分页/分批，CSS F-1 按 C9 保持现状）；中约 61；低约 80。
- 已修：安全 1、PHP 1、CSS 1、React 2、文档 4，另加 6 处低（标题/令牌）与 20 处文档。CSS F-1 按 C9 保持现状。
- 15 页实测：查询 17–27、257–335ms、0 error/warning、无骨架、1440 与 390 无文档级横向滚动。
- 未完成：性能深读、files*/zibll React、JS 依赖分析、弹窗与多角色实操（详见文末）。

## 覆盖
| 分片 | 阅读范围 | 结果 |
|---|---|---|
| 安全A | 49 个注册点 = 112 路由；Admin/Rest 14 文件、UserFiles/Rest 全文分段、Zibll/Rest、Page_Assets/App_Manifest/Layout/Capability_Matrix/Registry、4 个 transport | 无 __return_true；能力统一 tab_gate；高 1 |
| 安全B | 371 个 $wpdb 点机器分类（PREP 279 / RAW 60 / VARSQL 21 / CONST 11），81 个非 prepare 点逐条复核；island JSON、S3 密钥、上传 realpath、nonce 配对 | 无 SQL 注入；JSON_HEX_TAG；密钥不下发 |
| CSS A | tokens 236、base 1166、components 3338、menu-icon、Page_Assets、Layout、views grep | 高 1 中 2 低 13 |
| CSS B | pages 9 文件 6700 行逐行 + 62 JSX className 对照 | 中 2 低 5 |
| React A | admin-app 5、shared 3、system/image/performance 全部；对照 Page/Shell/Payload 契约 | 高 1 中 4 低 10 |
| React B | storage/sync/deletes/rewrite/cost 33 文件约 7000 行 | 高 1 中 6 低 14 |
| PHP | 36 改动文件；php -l 36/36；lint:compat 278；tokenizer 276；phpstan/phpunit | 中 2 低 4；8.4 弃用 0 |
| 文档 A | 00–08 全文 + docs/README + AGENTS | 高 4 中 14 低 6 |
| 文档 B | 09–14 + 根文档 + 生成物 + plans | 中 17 低 9 |

## 高优先级发现
1. F-01 安全（已修）：UserFiles/Admin/Page.php:118-160 下载按任意 attachment id 读文件；Controller.php:1839-1849 nonce 不绑附件。修法：先校验附件存在于 delete_request_items，nonce 绑定附件 id。
2. CSS F-1（保留现状，C9）：Page_Assets.php:109-120 每页预载全部 9 个页面样式（12 表 / 292 KB 未压缩），是切页不闪的刻意代价。
3. RS-01（已修，beta-10）：admin-app/index.jsx:127-130 在 boot 为 null 时仍取 boot.chrome，TypeError 使骨架屏卡死且不走失败提示。
4. R1（已修，beta-10）：storage/monitoring.jsx:199-202 等在 section 退化为 {} 时整页白屏，缺 ErrorBoundary。
5. 文档 4 条（已修）：02 的 payload 四块/config.strings → 六键/strings；01 装配表漏 7 个无条件模块；01 仍称 includes 有类；00/06/AGENTS 版本基线停在 1.29.0。

## 中优先级（节选）
- 安全：/admin/caps 仅 manage_options 绕开 can_review；/admin/users 下发全站 email 给 DELETE_FILES；39/49 调用点无 args。破坏性动作经复核修正（原分片报「无确认与审计」过宽）：配额重置有 events 留痕但 LIMIT 5000 静默截断（UserFiles/Rest/Controller.php:2254-2281）；队列丢弃有确认文案（Admin/Deletes_Payload.php:402-404）但 DELETE 无 state 条件、无审计行（S3/Delete_Jobs.php:959-974），可丢弃 running 行；文件夹删除走 InnoDB 事务与互斥（Repositories/Folders.php:759）但无 events；备份清空有警示文案（Admin/Backup_Payload.php:123/144），UI 是否弹确认待实操验证。
- CSS：notice 样式加载 82.8 KB 且作用域泄漏；主按钮白字压浅粉渐变约 1.7–3.0:1 且渐变复制 5 份；files-queue 容量表缺 table-layout:fixed（已修）；image.css 约 210 行上一代死样式。
- React：性能页缺 island=>true 导致页头闪；stats 的 ?page 污染 rest_route；无 ErrorBoundary；清空搜索不重载、pageSize 闭包旧值、行态提升整树重渲染、写请求无超时/Abort、200 非 JSON 清空快照、配置切换无并发守卫。
- PHP：REST fields 传数组触发 Array to string conversion（已修 6 处）；git status 折叠未跟踪目录使清单 34 vs 36。
- 权限模型（实测新增）：只授 sufm_admin_review_deletions 的角色打开 files 页被重定向到前台、queue 页同样，storage/system 直接 403——审核页签 gate 用的是 execute（=DELETE_FILES），与权限矩阵「审 / 删」分离的语义不一致；纯审核员无法进入审核队列。证据：实验站临时角色实测（已清理），Registry.php:80-97 + Capabilities::can_execute():218-220。
- 文档：03/12/13/07/09/11/README 行号漂移、死路径、页签 16→15、树列含已删文件、生成物缺 70 类等（部分已修）。

## 交叉复核修正
- F-03 由「破坏性动作普遍无确认与审计」收窄为上列四处具体缺口；原表述作废。
- 新增权限模型发现：审核页签 gate 用 execute(=DELETE_FILES)，只授 REVIEW 的角色打不开审核队列（Registry.php:80-97 + Capabilities.php:218-220，实验站临时角色实测）。

## 修复与回归
- 代码 13 处：版本号三处；F-01 两处；M2 六处；files.css 补 table-layout；files-log 标题；tokens 补 --g-radius-input。
- 文档 20 处：00、01（2）、02（4）、06（9）、10、14、AGENTS（10）。
- 回归：php -l 6/6；lint:strict 0/0；phpstan OK；PHPUnit 939/4218/5；lint:compat 278 文件 0/0；git diff --check OK；npm run build 486,688 B；实验站 md5 一致、版本 beta-8。
- 浏览器：15 页 0 error/warning、无骨架、1440 无横向滚动；files-log 标题已变「最近操作」；保存回流 Windows→mac→保存→重载 mac→还原 Windows→保存→重载 Windows；390 三页文档宽度 390。

## 未验证 / 未覆盖
1. 弹窗开→关→再开：演示数据下无可安全触发的弹窗（套餐删除只改草稿，选项未变已核对；选图标为内联面板；破坏性按钮不宜实操）。需造一条删除申请或授权后补验。
2. 多角色权限实操：仅管理员；REVIEW/DELETE_FILES 授予情况与 F-01/F-04 现实影响面未测。
3. 性能：claim/lease 重入、N+1、无上限查询、缓存失效、内存峰值未深读；仅 15 页查询数与 autoload 实测。
4. JS：esbuild metafile 体积构成、隐式全局、未处理 Promise 未分析。
5. 转义/文件系统：grep + 定点读覆盖关键面，src/Filesystem/** 未逐行。
6. React files*/zibll：分片中断，未逐文件评审。
7. 生成物 docs/sakura-tools-代码结构-1.28.3.md 未重新生成（管线扫描器不在本仓库）。

## 补充分片（第二轮，3 个窄片）
- perf-close：0 阻断 / 5 高 / 5 中 / 4 低。队列不双取——Delete_Jobs::start():452-477 用带 state 条件的 UPDATE 并校验 affected rows==1；lease 600s 两侧一致；cron 29 处排程与 Deactivation:25-58 全部成对；缓存 key 均含指纹，唯一无 TTL 是 Theme_Cache.php:83。5 条高全是无上限读取。
- react-files2：0 高 / 6 中 / 7 低。RF2-01（caps 卡与 /admin/caps 权限错配）与 F-02 合并；RF2-03 批处理终态不刷新已批准列表、RF2-04 approved 固定 50 条且客户端过滤、RF2-05 drafts 重建丢未保存输入。
- js-globals：0 高 / 0 中 / 4 低。dist 与 src 无漂移（重建 486,623 B vs 提交 486,688 B，差 65 B 为 banner）；react-dom 210,070 B(43.2%)、react 单份、无第三方、无嵌套重复；隐式全局 0；仅 window.SakuraAdmin 与死名 window.SakuraUI；57 then/49 catch 无可触发 unhandledrejection；旧脚本仅非路由页加载。

## 第二轮修复（1.30.0-beta-9）
- PERF-01：export_rows() 改 keyset 分批（$after_id/$limit），Delete_Queue_Panel::stream_csv 每批 500 边取边写；运行时验证 page1=3/page2=3/游标 57→58/全量 73。
- PERF-02：job_start() 的 WHERE 扩为 queued OR (running AND lease_until < now)，回收崩溃后卡死的同步作业。
- PERF-07：keys_by_stem() 加 ORDER BY + LIMIT 500。
- F-02/RF2-01：capability-matrix 卡片仅对 manage_options 下发，非管理员不再看到必 403 的保存按钮；管理员实测仍在。
- 回归：php -l 5/5；939 tests / 4218 assertions / 5 skipped；phpstan [OK]；lint:strict 0/0；lint:compat 0/0；git diff --check 通过；重建产物 md5 c28f0035…，实验站同 md5、版本 1.30.0-beta-9；queue 页 17 查询 / 262ms / 0 error / 0 warning / scrollWidth=1440。

## 第三轮修复（1.30.0-beta-10：RS-01 / R1）
- RS-01：`admin-app/index.jsx` 在 manifest 已启用但数据岛为空时新增 `! boot` 分支，调用既有 `showFailure()` 并置 `__sakuraShellBooted`，不再执行 `createShell( boot.chrome )`。实测（演示站仅改视图输出 null，保留挂载节点）：`.sakura-boot` 变为 `role=alert`、`is-failed`，文案「页面数据加载失败，请刷新重试。」，后台框架完整。
- R1：新增 `shared/error-boundary.jsx`（渲染失败→「这块内容加载失败，可以重试」，带重试、控制台记录原始错误、`resetKey` 切页复位）；接入三处：页面入口两处 `createRoot`、共享 `Card` 的 body、storage 页五张卡逐个包裹。同时给 `storage/monitoring.jsx` 加 `section/strings/prometheus/alert.enabled` 安全默认值。实测（演示站让 monitoring 段只剩 id）：`profiles/connection/behaviour/upload-metrics` 四卡正常渲染，只有 monitoring 位置变成失败块，页面级兜底未触发；还原后 5 卡齐全、无兜底文案。
- 回归：1.30.0-beta-10；939 tests / 4218 assertions / 5 skipped；PHPStan `[OK]`；PHPCS 0/0；compat 0/0；`git diff --check` 通过；JSX 语法检查通过；产物 md5 `52988d71…` 同步实验站；正常页 1440 与 390 均为 5 卡、`scrollWidth=390/1440` 无溢出。

## 高优先级处置
- PERF-04 recursive_files 无上限、PERF-05 申请 items 无上限、PERF-06 全站 postmeta 一次入数组：需先定「上限语义/分页 UI」，截断会造成漏删或漏审，未擅自加 LIMIT。
- CSS F-1 每页预载 9 份页面样式、RS-01 boot 为 null 时取 boot.chrome、R1 卡片 section 退化时白屏：均需产品取舍或补 ErrorBoundary，未在本轮改。

## 复跑命令
php -l <changed>; composer test; composer phpstan; composer lint:strict; composer lint:compat; npm run build; git diff --check; rsync --exclude /dist 同步实验站。

## 证据位置
分片报告 /tmp/audit/*.md；查询点 /tmp/audit/wpdb_rows.json；页数据 baseline-pages.json / after-pages.json；390 截图 /tmp/audit/390-*.png；修复前备份 /root/backups/20261127-audit-baseline/。
## 第四轮（1.30.0-beta-11）：A6 分页 + 全选 + 删文件夹上限

### 改了什么

- 已批准面板改为服务端分页：`files-queue/app.jsx` 新增 `approvedQuery {page,per}`、`approvedTotal`，请求带 `unbatched=1&page&per_page`，复用 `Pager`（20/50），移除 `page:1, per_page:50` 与客户端 `filter(!batch_id)`。
- 勾选跨页保留：删除重载时的 `setSelected([])`；`toggleSelected` 统一 `Number()`；新增 `togglePage` 实现「全选本页」，面板显示「已选 X / 总数」。新增文案 `Page.php:261` `selectPage`。
- 过滤下推：`Rest/Controller.php:1696-1700` 读取 `unbatched`；`Repositories/Requests.php:1146` 增加参数并在 `:1157-1159` 追加 `AND r.batch_id=0`，`total` 与列表同一条件。
- 删文件夹上限：`Repositories/Files.php:787` `recursive_files( ..., int $limit = 0 )` 支持 `LIMIT %d`；`Services/Executor.php:948/963` 的空目录判定改传 `1`，不再把整棵子树的文件读进内存。

### 实测证据（实验站 1.30.0-beta-11）

- 服务端分页：临时插入 3 条 approved（其中 1 条 `batch_id=7`），`admin_queue('approved','',1,1,true)` → `total=2`、page1 id=7、page2 id=6；`unbatched=false` → `total=3`。测后删除，表回到 0 行。
- `recursive_files(…,1)`：真实含 15 个文件的文件夹返回 1 条，不带 limit 返回 15 条。
- 页面：queue 页壳与 `.approved-list` 存在，1440/390 `scrollWidth` 分别等于视口宽（无横向溢出），控制台 0 错误 / 0 警告，首屏 19 次查询。
- 静态：939 tests / 4218 assertions / 5 skipped；PHPStan `[OK]`；PHPCS 0/0；compat 0/0；`git diff --check` 干净；JSX 语法通过；产物 477 KB，md5 见下。

### 全选与计数（已实操）

- 临时插入 2 条 approved 后进 queue 页：`.approved-item` 共 3 个复选框（2 行 + 全选行），点「全选本页」后 3 个全部勾选，计数文案为「已选 2 项 / 2」；临时数据随即删除，表回 0 行。
- 跨页计数取服务端 `total`（与列表同条件），分页与服务端过滤已用数据验证；「批准前看到完整范围」由 `unbatched` 总数 + 面板计数共同保证。

### 弹窗回流（A3，已实操）

- 用队列页行内「详情」触发共享抽屉（原生 `<dialog class="queue-drawer">`）：打开后 `open` 属性存在、`:modal` 为 true、页面内仅 1 个 dialog、初始焦点在关闭按钮、内容为「详情 · #11 …」。
- Esc 关闭：dialog 从 DOM 移除，焦点回到触发它的「详情」按钮，`body` 未残留 overflow 锁。
- 再次打开：仍只有 1 个 dialog、内容一致（无重复挂载）。用关闭按钮关闭后 dialog 数为 0、焦点再次回到「详情」。
- 控制台全程 3 条消息、0 错误 0 警告；测试用申请行已删除，队列表回到 0 行。

### 删文件夹的收口方式（已确认）

- 读代码确认：删文件夹路径只判断「子树里是否还有存活文件」，不逐个删除（文件由各申请明细执行），因此不存在可分批的删除循环。按用户选择保留 `LIMIT 1` 存在性判定：真实 15 个文件的文件夹只查 1 条，内存不再随子树规模增长；事务与中断续跑语义不变，不做删除模型改造。

### PERF-06（beta-13）：postmeta 分批扫描

- 旧写法 `Backup.php:510-512` 一次 `SELECT post_id, meta_value … WHERE meta_key` 全取，再逐行 `maybe_unserialize` 建整张映射表。
- 新写法 `build_path_to_id_map( array $wanted )` 按 `meta_id` keyset 分页（每批 500，`meta_id > 上次`），只保留本次可能删除的文件路径对应条目；`$wanted` 为空时保持旧语义（全量映射）。
- 实测（实验站 1.30.0-beta-13，3000 条临时 meta）：旧写法峰值 1918 KB / 22 ms，新写法 487 KB / 20 ms，映射结果一致（map=1、hit=yes）；临时行按 `meta_id > 起始值` 精确删除。
- 说明：beta-12 首次测试清理误删了演示站原有的 9 条 `_sakura_imgopt_backup` 元数据（仅演示站，生产未受影响），后续清理已改为按 `meta_id` 范围删除。
## 2026-11-27 权限复核增量（1.30.0-beta-14）

### P2 / 中：审核能力与队列页签 gate 错配

- 触发条件：角色仅有 `sufm_admin_review_deletions`（REVIEW）与 WordPress `read`，不含 `sufm_admin_delete_files`（DELETE_FILES），访问文件空间的「审核与处置」队列。
- 影响：审核员不能打开包含批准/驳回/撤销操作的 React 队列页，虽然对应 REST 队列端点已允许 REVIEW；造成纯审核角色无法履职。该问题是 UI 误拒绝，不是越权。
- 证据：`src/UserFiles/Capabilities.php:33-43,209-219` 将 REVIEW 与 DELETE_FILES 明确分开；`src/Admin/Registry.php:80-83` 原 queue gate 为 `execute`；`src/UserFiles/Rest/Controller.php:630-637` 队列/审核回调为 `$review`、批次预览/执行回调为 `$execute`；`Registry::tab_can()` 在 `src/Admin/Registry.php:351-359` 按 gate cap 调相应能力。
- 复现：实验站 WP-CLI 以临时用户 ID 5（subscriber；仅加用户级 `read` 和 REVIEW）设置 current user 后执行 `Registry::tab_can('files','queue')`，原值 `false`；同一运行时读取 REST 注册项并调用权限回调，`/admin/delete-requests` 为 `true`、`/admin/delete-batches/preview` 为 `false`。复测后临时用户已删除。
- 修复：`src/Admin/Registry.php` 将 queue gate 从 `execute` 改为 `review`；`log` 仍保留 `execute` gate。版本头、常量、Stable tag 同步升至 beta-14。
- 回归证据：新增 `tests/Admin/TabGateTest.php::test_queue_tab_uses_review_capability`；定点 PHPUnit 6/6、24 assertions；完整 PHPUnit 940 tests / 4221 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS strict 与 PHPCompatibility 均 exit 0（各扫描 8 个文件且无报告项）；`php -l` 对两份改动 PHP 文件通过；`git diff --check` 通过。检查运行环境为 PHP 8.2.36。旧版系统 Composer 2.0 自身及其 Symfony 依赖向 PHP 8.2 输出 Deprecated 提示（见本轮工具输出），不属于插件源码告警；实验站生产等同的 PHP 8.4.28 环境未运行静态扫描。
- 浏览器：未验证队列页角色可见性。临时用户登录成功且前台出现退出链接，但站点将 `/wp-admin/`、`profile.php` 与插件页都重定向回前台；没有足够浏览器证据确认重定向来自主题还是后台策略。实验站代码当时仍是 beta-13，未同步本次修复。生产站未触碰。

### 本轮其余验证状态

- 系统设置后台（管理员会话）在实验站实测：19 次查询、263.954 ms；页面内容正常、环境检查 9/9；1440px 下 document/body 宽度均 1440。console 中唯一 error-level 条目是 `Transition was aborted because of invalid state. ViewTransition opt-in disabled`，无插件栈；warning 为 0。
- 首轮仪表盘仅作环境基线：37 次查询、270.242 ms，不能替代插件页面数据。
- 本轮其他七维残余（性能三态采样/未覆盖主题页、JS metafile/全局与未处理拒绝、其余 docs 逐册、reviewer 的浏览器多角色路径）沿用 §「未验证 / 未覆盖」标记，不据静态阅读判通过。

## 2026-11-27 权限 UI 与数据库 warning 增量（beta-15 / beta-16）

### P2 / 中：REVIEW 队列复合页面夹带 execute / sanction 数据

- 触发：REVIEW-only 用户打开文件空间队列。审核 REST 返回 200，但初始 App 同时挂载 execute、users、capability-matrix 卡，并向 execute-gated `/admin/users` 发请求；该响应此前包含全站 email、处罚摘要和 `can_sanction`。
- 影响：低权限角色发出预期 403 请求并看到不可用卡；execute-only 响应额外暴露了无权处理的处罚概要。未观察到写操作越权。
- 实验站 beta-15 运行时复现（无写数据库）：REVIEW-only：queue GET 200、users GET 403、payload permissions execute/sanction=false；execute-only：users GET 200，原 rows keys 含 email 与处罚数据。临时用户已删除。
- 修复（beta-15）：payload 既有 `data.permissions` 下发服务端 execute/sanction 布尔值；无 execute 时不挂载 execute/users/matrix 卡、不请求 approved 列表或 users；抽屉跳过 users 请求，审核批准/驳回仍只受 REVIEW 控制；处罚 UI 独立按 SANCTION_USERS 和 owner_id 控制；执行按钮只受 DELETE_FILES 控制。`admin_users()` 移除 email，处罚 map/summary/can_sanction 只在 SANCTION_USERS 时查询和序列化。REST permission callbacks 原样保留。
- beta-15 回归：本地 REVIEW-only gate 测试 6/6；staging 角色 probe 验证 queue=200/users=403、payload flags false；execute-only users=200，响应不含 email、sanction=null、can_sanction=false。beta-16 管理员真实浏览器打开队列：页面显示版本 beta-16 并完整 hydrate；console 总计3条、error=0、warning=0；`admin/delete-requests` 默认及 approved/unbatched 两次 GET 均200，管理员的 `/admin/users` GET=200。此结果只代表管理员会话；REVIEW-only 真实后台页面受该低权限用户访问 `/wp-admin/` 时被重定向到前台影响，仍标为未验证，不把 staging CLI probe冒充浏览器验证。

### P2 / 中：默认队列 count 把空参数数组交给 `$wpdb->prepare()`

- 触发：默认审核队列 `admin_queue('', '', 1, 20, false)`。status/search 均为空时，count 查询 where 子句为固定 `1=1` 且没有占位符，但旧代码仍执行 `prepare($count_sql, [])`。另一个无动态参数变体是 `unbatched=true`，只追加固定 `r.batch_id=0` 条件。
- 影响：WordPress 7.1.5 发出 `wpdb::prepare()` 无占位符的 doing_it_wrong notice，污染正常队列请求的调试日志。
- 实验站 beta-15 运行时调用栈：`src/UserFiles/Repositories/Requests.php:1172` <- `src/UserFiles/Rest/Controller.php:1700` `Controller::admin_queue()`。调用栈由 `doing_it_wrong_run` hook 采集；通知后已确认来自插件代码而非 CLI 外部工具。
- 修复（beta-16）：count SQL 先组合；仅 `$params` 非空时调用 `$wpdb->prepare()`，无动态参数时将固定 SQL 直接交给 `get_var()`。列表 SQL 仍始终 prepare `LIMIT/OFFSET`。
- 回归：新增 `tests/RequestsAdminQueueTest.php` fake `$wpdb`，空占位符 prepare 会抛异常；默认空筛选 count 不 prepare、分页 query prepare 2 值；status+search count/list 分别验证4/6占位符与参数。最终定点测试 2/2，10 assertions。beta-16 staging 用 hook 重跑角色 probe，无该 `doing_it_wrong` 命中。

### beta-16 最终验证

- staging 版本 `1.30.0-beta-16`；`admin-app.js` 工作副本与 staging SHA-256 一致：`089adeb31b33032b4c9d4971ff09939860cc94836c74c2370ef8b718cb7f02ff`；属主 `www:www`、权限 644。beta-16 同步前 staging beta-15 备份位于 `/root/backups/2026-11-27-beta16-pre-sync/sakura-tools`。临时 reviewer 已删除。
- 最终质量门禁：PHPUnit 942 tests / 4231 assertions / 5 skipped；PHPStan `[OK] No errors`；`lint:strict` 与 `lint:compat` exit 0（各8/8）；本轮 PHP 文件 PHP 8.4 `php -l` 全通过；React build 477.3 KB 成功；`git diff --check` 通过。
- Composer 调用本身来自系统 Composer 2.0 和 Symfony 依赖，在本机 CLI PHP 8.2.36 下仍输出 Deprecated；它们不是插件代码提示，PHP 8.4.28 对本轮改动文件的语法检查通过。

### beta-17 最终复核

- 测试夹具更正：`test_unbatched_count_without_dynamic_filters_skips_prepare()` 现在以 `unbatched=true` 运行，并断言 count SQL 含固定 `batch_id=0`；空动态参数 count 不 prepare，分页仍 prepare 两个值。定点 PHPUnit 2/2、11 assertions。
- 最终质量门禁（beta-17，PHP 8.4.28 PATH）：PHPUnit 942 tests / 4232 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS strict 与 PHPCompatibility exit 0（各8/8）；本轮 PHP 文件 PHP 8.4 `php -l` 通过；React build 477.3 KB；`git diff --check` 通过。
- 实验站 beta-17：版本 `1.30.0-beta-17`；工作副本与 staging `admin-app.js` SHA-256 均为 `dee088ce12dd852a108ca114d16f1bdc4b0bdbb02e8f229592c83fa79e1daf28`，`www:www`/0644；同步前 beta-16 备份在 `/root/backups/2026-11-27-beta17-pre-sync/sakura-tools`。
- beta-17 staging 只读角色 probe：REVIEW-only queue=200/users=403、execute/sanction flags false；execute-only users=200，无 email、处罚摘要 null、can_sanction=false；`doing_it_wrong_run` 未记录 `wpdb::prepare`。
- beta-17 管理员浏览器队列：快照标题“审核与处置”、页面数据岛显示 beta-17、空态正常；console 3条消息、0 errors/0 warnings；`admin/users`、默认 `admin/delete-requests`、approved/unbatched `admin/delete-requests` 请求均200。该浏览器会话为管理员；REVIEW-only 真实后台交互仍未验证（低权限用户 `/wp-admin/` 会被重定向到前台）。
- 系统 Composer 2.0/Symfony 与相关旧依赖在本机 PHP 8.2.36 和 8.4.28 下都打印 Deprecated；PHPCS installer 因 Composer Plugin API `^2.2` 与系统 Composer 2.0 不匹配而被跳过，但 `composer lint:strict` / `lint:compat` 脚本仍分别执行 `phpcs` 和兼容扫描并 exit 0。


- beta-17 JS 资产复核：`bin/build-ui.mjs:28-63` 仅发现 `admin-app/index.jsx` 一个 entry（全部页面共用单bundle）；同构建选项、`metafile:true`、`write:false` 内存构建得到1 output/488,791 bytes/88 inputs；React DOM client 201.1 KiB/42.1%，React 7.8 KiB/1.6%，scheduler 3.6 KiB，无外部依赖。源码共15个 `.js`、64个 `.jsx`；`window.*` 86处/18个属性名（插件自有 `SakuraAdmin` 与 `__sakuraShellBooted`，`SakuraUI` 为esbuild生成），`globalThis` 0处；静态 `.then(` 51处、`.catch(` 44处、`unhandledrejection` 字面量0处。未对51/44处逐条做AST链配对。管理员 queue→system SPA切换期间页面内拒绝监听器捕获0项。

- beta-17 移动端路由采样：按Registry的15个后台路径逐一在实验站真实打开；390px viewport 下每页 `documentElement.scrollWidth=390`、`body.scrollWidth=390`，数据岛均显示 beta-17；覆盖 system 1、image 4、storage 5、files 4、zibll 1 个路由。该证据只覆盖页面级横向溢出，不替代控件级/截图视觉验收。

### P3 / 低：发布流程的本地打包预期文件名滞后于基线

- 触发：新人照 `docs/开发规则/06-发布流程.md:102,108-109` 的本地打包示例操作。原命令要求 `--version 1.29.0`，输出名也写1.29.0；仓库当前基线已是 `1.30.0`（`AGENTS.md:5`，同页 `06-发布流程.md:23`），容易验证/交付错版本包。
- 证据：`bin/build-plugin-zip.sh:24-30` 说明 `--version` 必须与插件头一致，输出模板是 `<out>/sakura-tools-<version>.zip[.sha256]`。修后实跑 `bin/build-plugin-zip.sh --version 1.30.0 --out /tmp/sakura-doc-package-check-final` exit 0，产物 `sakura-tools-1.30.0.zip`，1211 files / 2,880,487 bytes，SHA256 `0945b96adbae9242ec5d3064848750bc70e6d298912e1f1bd6ebfd27fdd7c4f7`。
- 修复：`06-发布流程.md:102,108-109` 的命令和预期 ZIP/sha256 文件名均改为1.30.0；只写到 `/tmp` 验证，未在工作区创建包。

### P3 / 低：开发规则中的后台路由数与文件页签清单滞后

- 触发：新人按 `docs/开发规则/02-新增功能.md` 现行模块表寻找 `files-users` 路由或按16个后台页做清单；`10/12/01/03/07` 多处现行描述也沿用16。
- 影响：会以为审核/用户容量是两个 route，可能重复新建页面或误判迁移/契约缺页。
- 证据：`src/Admin/App_Manifest.php:40-104` 当前 ROUTES 为15项；`tests/Admin/PageContractTest.php:93-96` 遍历ROUTES并 assertCount(15)；`src/Admin/Registry.php:74-97` files 只有 settings/queue/log/zibll 四页，`files-users` 仅是内部 payload 数据 key（`src/UserFiles/Admin/Files_Admin_Payload.php:49,145`），不是 route。
- 修复：`02-新增功能.md` 当前文件模块清单改为四页并注明用户容量并入 queue；`01/02/03/07/10/12` 的现行路由口径更正为15；beta-5迁移时16页的有日期历史语句保留并注明“当时16、当前15”。
- 回归证据：在 `docs/开发规则` 搜索 `16 个/16个`，仅剩带迁移时间的历史描述；搜索 `五个页签` 仅命中仍有5页的storage模块；`git diff --check` 通过。纯文档修改未重复跑测试。

### P2 / 中：React shell 所有权规则与实现相反

- 触发：开发者依 `docs/开发规则/12-React代码规范.md:14` 写后台壳代码；原文称“顶栏、页签条、页头、骨架由 PHP 渲染，React 永远不碰它们”，与同篇 §1.3、03篇 §9.3 以及 beta-17 代码实际冲突。
- 影响：可能错误地把已经迁移的 shell 当作 PHP-only，导致导航时重复绘制/重建、违反 hydration DOM 契约，或错过通过同一 root 更新 shell 数据的责任边界。
- 证据：`assets/ui/src/admin-app/shell.jsx:20-25` 对 PHP 既有 topbar/tabs roots 调用 `hydrateRoot()`；`shell.jsx:34-45` 在同一 roots 调 `render()` 更新 children；`assets/ui/src/admin-app/index.jsx:140-141` 创建 shell roots 与内容区 `createRoot()`。
- 修复：12篇 §0 第1条改为“PHP输出首帧、React hydrateRoot 接管既有壳且不得重建/重复绘制；内容区由 createRoot 拥有”，并明确初次 hydration 前不提前更新shell。
- 回归证据：对照源码和 §9.3；纯Markdown改动后 `git diff --check` 通过。

### beta-18 最终复核

- P3 / 低：`tests/Admin/PageContractTest.php:20-24` 顶注仍称5个 files/Zibll 页面只检查 `page/head/cards` 且保留旧 `config`；但当前 `STRICT` 包含全部15 ROUTES，测试实际遍历每个 route 并断言完整六键、strings/rest refresh、cards/layout。
- 修复：三处版本号升至 `1.30.0-beta-18`；测试注释改为说明15路由都执行完整契约检查。定点 `vendor/bin/phpunit tests/Admin/PageContractTest.php`：2 tests / 228 assertions passed。
- PHP 8.4.28 全量门禁：PHPUnit 942 tests / 4232 assertions / 5 skipped；PHPStan `[OK] No errors`；strict 与 compatibility exit 0（各8/8）；React build 477.3 KB；PHP 8.4 lint 和 `git diff --check` 均通过。系统 Composer 2.0 / Symfony 在PHP8.4继续输出phar内Deprecated，退出码为0。
- beta-18 staging：版本 `1.30.0-beta-18`；工作副本/staging bundle SHA256 `29e2d3d206ea0bae6bfe473605a43ea365201a739a4538713e92ec7f3bfa7db2`，属主www:www/0644；同步前 beta-17 备份在 `/root/backups/2026-11-27-beta18-pre-sync/sakura-tools`。WP-CLI role/prepare hook probe无 prepare告警；浏览器queue在1440px显示beta-18，console 0 error/0 warning，users/default queue/approved queue GET均200。生产未触碰，beta未推送。

- Query Monitor 4.1.1在staging原为inactive；为实测页面级空队列基线曾临时启用，快照显示页面生成0.28秒、峰值内存15.4MB、16条SQL（SELECT 11 / SHOW 5）、DB合计3.0ms。测后已停用，WP-CLI is-active以exit 1确认inactive。此为初始admin页面请求，不代表REST异步请求自身的SQL成本。
- WP-CLI在实际staging WordPress/PHP进程中对 `GET /sufm/v1/admin/delete-requests` callback 开启 `$wpdb->save_queries`，每种3次（含真实DB访问，不含HTTP传输耗时）：空队列 total=0/rows=0，6 queries，DB中位0.434ms（0.392–0.616），dispatch中位0.695ms（0.630–1.235）；单条 total=1/rows=1，8 queries，DB中位0.694ms（0.676–0.786），dispatch中位0.998ms（0.965–1.106）；100条申请、每页20条，total=100/rows=20，46 queries，DB中位5.490ms（5.472–5.513），dispatch中位6.618ms（6.568–6.630）。
- 样本使用唯一marker的合成request/item，初始行数requests=0/items=0；脚本 `finally` 按request ID清items与requests，结束核对仍为0/0；临时脚本已删除。第一次以www读取root的/tmp文件权限失败，之后改由WP-CLI `--allow-root`执行成功。上述是REST callback/DB采样，不是浏览器HTTP端到端或Query Monitor的非空队列页面面板数据；QM现已恢复inactive。

### 文档复核（2026-11-27，第三遍：只读对照源码/脚本）

- 范围：只改 Markdown（`docs/开发规则/**` 与本文件），逐条对照 `Registry` / `App_Manifest` / `Payload_Contract` / 四个 Payload / `bin/build-plugin-zip.sh` / `phpcs.xml.dist` / `includes/` 实况；未改 PHP/JS/CSS/config，未访问站点，未跑测试。
- P1 路由与 payload：
  - `01-架构与装配.md:175` 的 files 页签仍列 `users`；`src/Admin/Registry.php:78-97` 现为 `settings/queue/log/zibll`，`src/Admin/App_Manifest.php:40-104` 为 15 个路由。表已改并注明用户容量并入 queue。
  - `03-后台UI.md:351,653`、`09-删除与结算.md:238`、`11-违规处置与用户处罚.md:405`、`12-React代码规范.md:239,258,278` 的 `config.strings` / `config.caps` / `config.capsForm` / `page/head/cards/config` 都是旧四键或旧嵌套；`src/Admin/Payload_Contract.php:26` 的 REQUIRED 为 `page/head/chrome/cards/strings/rest`，`caps` 在 `cards[]` 的 `capability-matrix` 卡上（`Files_Admin_Payload.php:241,295-342`），`capsForm` 全仓 0 命中。逐处改为六键与 `cards[].caps`。独立审计若读 HEAD 仍会看到这些旧键（`git show HEAD:…` 仍是旧版）；工作树已修，勿重复改。
- P2 打包/路径/规模/质量：
  - `06-发布流程.md:120` 补“已跟踪但未提交的改动也不进包”：脚本默认 `--ref HEAD`，`bin/build-plugin-zip.sh:14,69-72,109` 用 `git archive $COMMIT` 出提交树，版本号也从该 commit 读。`06-发布流程.md:123` 的必需文件清单按 `bin/build-plugin-zip.sh:157-169` 更正：去掉已删除的 `includes/class-image-processor.php`，补 `src/Admin/App_Manifest.php` 与 `assets/ui/dist/admin-app.js`。
  - `13-PHP代码规范.md:201,371,376` 的 includes 37 文件/2525 函数等已过时；按当前树重算为 278 PHP 文件 / 2572 个函数声明（`src/` 260、`includes/` 15、根 3）、`strict_types` 232（`src/` 229/260、`includes/` 2/15）、ABSPATH 248/260。`:83,94,101` 的 `includes/class-s3-cli.php`、`includes/class-gd-handler.php` 改为现路径与现行号。
  - `07-S3存储生命周期.md:513,517,518,779,799` 的 `includes/class-s3-doge-cloud.php`、`includes/class-s3-storage-module.php` 已迁 `src/S3/`（`Doge_Cloud.php` / `Profile_Actions.php`），行号按现文件校正。`:36` 属“施工前（1.28.1-beta-6）”历史表，保留原路径与语境。
  - `05-测试与质量.md:140` 声称 PHPCS 登记 7 个能力，`phpcs.xml.dist:103-108` 实为 6 个，缺 `sufm_admin_sanction_users`（代码只用 `Capabilities::SANCTION_USERS` 常量，PHPCS 抓不到字面量）；改为 6 并写明新增字面量调用时必须同步登记。
- P3 基线/版本/地图：
  - `05-测试与质量.md:122` 的 `minimum_supported_wp_version=6.5` 与插件头 `Requires at least: 6.4` 不一致；保留配置事实，注明它是 WPCS 嗅探基线、不是插件最低支持版本，写 6.4 兼容代码仍按 6.4 算（是否统一待定，改配置属代码提交）。
  - `00-索引.md` 文档地图漏 `14-后台页面契约.md`，已补；铁律第 1 条仍称 `includes/` 有类在承重，按 1.29.0-beta-16 实况改为“只剩 views，靠评审把关（无 CI/pre-commit 自动拒收）”。
  - `01-架构与装配.md:33,55,60` 的 23 视图/12 个剩余旧类保留日期并补 2026-11-27 现状；`01-架构与装配.md:175`、`03-后台UI.md:5`、`12-React代码规范.md:3,49` 的 16 页/文件五页改为当前 15 路由、files 四页；`12-React代码规范.md:268` 注明 `files-users` 是内部数据键、不是路由。
  - `05-测试与质量.md:19,73` 与 `06-发布流程.md:61` 的 PHPUnit 基线 918/3889/6 已落后；按 beta-18 记录改为 942/4232/5（本轮未重跑）。`12-React代码规范.md:195-210` 的盘点表补 2026-11-27 复核：15 路由、78 文件、`useActionState` 6（3 文件/3 调用）、唯一 class 为 ErrorBoundary、产物约 478 KB（复核时 489,276 B）；行数/体积注明以命令为准。
- 保留未改（历史/未验证）：
  - `11-违规处置与用户处罚.md:475`（1.28.8-beta-1 的页签清单）、`13-PHP代码规范.md §7` 其余 2026-11-26 行、`07-S3存储生命周期.md:36` 的施工前表，均按原有日期保留。`12-React代码规范.md:195` 的盘点表保留 2026-11-26 快照语义，但已补 2026-11-27 复核的页面数（15）、Actions（6/0）、class（1）与体积（约 478 KB，复核时 489,276 B）；行数与字节数会随开发变化，注明以 `find`/`wc`/`stat` 为准。
  - `AGENTS.md` 的 PHPUnit 基线 939/4218/5 已落后于 beta-18 的 942/4232/5，但不在本轮允许的写作用域内，未改。
  - `phpcs.xml.dist` 的 6.5 与插件头 6.4 的差异只写进文档，未改配置（改配置属代码提交）。
- 验证：`git diff --check` 通过；只读核对源码/脚本，无运行时代码改动，未跑测试。

### 未覆盖项（继续标注未验证）

- 性能：已完成空/单条/100条 REST callback 的WP-CLI `$wpdb`查询与DB耗时采样；**未验证**非空数据的浏览器HTTP端到端与Query Monitor完整页面请求面板样本（QM页面级实测目前只有空队列）。非空页面尝试：创建唯一marker `audit-ui-20261127-settings-1` 的1 request/1 item后，QM toolbar在 queue 初始导航可见；随后并行Playwright读取返回 `about:blank`，无证据确认合成申请渲染，故未追加bulk样本。清理删1 request，核实requests_left=0/items_left=0，Query Monitor已deactivated。files settings用户可见回流已覆盖：tree_style从windows临时切mac保存后再恢复windows保存，两个 `settings/cards` POST均200，成功对话框两次打开并关闭，重载后radio=windows、accent=slate、两卡保存按钮disabled、console 0 error/warning。仍未覆盖REVIEW-only真实后台浏览器路径、其它页面的保存/业务弹窗流程、各编辑器/执行/处罚角色完整浏览器工作流，以及所有docs规则逐册新人演练。低权限reviewer访问`/wp-admin/`被重定向；按限制未触碰生产站。

### beta-19 React 复审（sanction AbortError / sync 与 batch 轮询卸载续命 / 队列 busy 单槽）

- 范围：`assets/ui/src/files-queue/sanction.jsx`、`users.jsx`、`app.jsx`；`assets/ui/dist/admin-app.js` 重建；三处版本号升 `1.30.0-beta-19`。未改 `docs/开发规则/**`，未改 PHP 业务代码。
- 复现方法：用真实的组件 + 打桩 `window.fetch` 搭本地 Chromium harness（esbuild 打包，`/tmp/react-repro-beta19/`，结果 `/tmp/beta19-repro-results.json`），四个场景（sanction / users / busy / batch）各自控制响应时序；不在 staging 造申请、不触发审核/执行/sync。仓库内没有 JS 单测框架，未新增提交测试；PHP 测试不受影响。
- 安全前置：本轮未创建任何合成行；staging 队列表 requests=0 / items=0；未调用 sync POST。

#### P2-1 / 低：处罚弹窗把 AbortError 当加载失败提示

- 触发：`sanction.jsx:62-69` 的 `admin/users/{id}/sanction` GET 未返回时按 Esc/取消关闭弹窗（组件卸载）→ 清理函数 `controller.abort()` → fetch 以 `AbortError` 拒绝 → catch 无 AbortError 分支。
- 影响：关闭弹窗后额外弹红色错误 toast；`errorText()` 优先取 `error.message`，实际显示浏览器英文「The operation was aborted.」。`onClose()` 还会被 catch 再调一次（当前实现无害）。
- 复现：harness 挂载 `SanctionDialog`，GET 保持 pending，80ms 后 unmount：修复前 `toasts=[{message: The operation was aborted., tone: bad}]`、`closes=1`；修复后 `toasts=[]`、`closes=0`。
- 修复：catch 首部加 `if ( error && AbortError === error.name ) { return; }` 的等价判断，与 users/app/drawer 已有写法一致（3 行）。
- 不确定性：staging 唯一用户 yexu 的处罚按钮 disabled（self）、queue 为空，未创建临时用户/申请，因此没有 staging 真实点击证据；该条证据来自 harness。

#### P2-2 / 中：容量同步轮询在组件卸载后继续续命

- 触发：`users.jsx` 启动容量同步（`POST admin/sync` 返回 running）后，`pollSync()` 发出的 `GET admin/sync` 仍在飞行时离开页面/切页（SPA 卸载 UsersCard）。
- 机制：`users.jsx:143` 在请求前把 `syncTimer.current` 置 0；原卸载 cleanup 只 clearTimeout（此时 id=0），响应回来后 then 又 `setTimeout(pollSync,1200)`，于是卸载后仍每 1.2s 发一次 GET，直到整页卸载；`setNote`/`setReload` 也落在已卸载组件。
- 影响：离开页面后仍有持续的 REST 轮询（同步耗时越长越明显），后台请求/日志噪音，同步完成提示可能在没有该卡的页面触发；不写数据。
- 复现：harness 让 `POST admin/sync` 立即返回 running、`GET admin/sync` 延迟 400ms，60ms 时 unmount：修复前 `syncGets=2`、`getsAfterUnmount=1`；修复后 `syncGets=1`、`getsAfterUnmount=0`。
- 修复：新增 `syncAlive = useRef(true)`（users.jsx:33）；卸载 cleanup 置 false 并清零 timer（:71-78）；`pollSync` 与 `startSync` 的 then/catch 首部检查 `syncAlive`（:142-181、:193-215），只阻断续期和卸载后 setState，不额外 abort 无副作用的 GET。
- 不确定性：没有在 staging 真正启动 sync（会跑配额同步、改用户数据，按约束不触发）；该条证据来自 harness。

#### P2-3 / 中：队列单一 busy 字符串跨行/跨操作互相解锁

- 触发：同一时刻两个异步操作，例如先批准 #A 再批准 #B；或执行批次、停止批次、查看文件等不同 key 的操作并行。
- 机制：`app.jsx:47` 只有一个 busy 字符串；后开始的操作覆盖前一个 key，前一个按钮随即恢复可点；每个 `.finally(() => setBusy())` 无条件清空，先完成的操作会解锁另一个仍在飞行的操作。字符串版 `busy.startsWith(review:+row.id)` 还有 id 前缀歧义（1 会匹配 11）。
- 影响：飞行中的批准/驳回/查看文件/撤销/停止/执行按钮会提前恢复，可重复提交；服务端按 state 条件守卫，重复审核返回 409（提示「当前状态不能批准/已变化」），重复执行确认会多建一条 failed 批次并返回 409，不是数据双删，但会产生重复请求、误导性错误提示和残留失败批次。
- 复现：harness 渲染 `App`，两个 pending_review 行，review POST 挂起；点 #11 批准 → 点 #12 批准：修复前 `afterSecond=[false,true]`（#11 被解锁）、#12 完成后 `afterSecondSettled=[false,false]`，再次点 #11 又发一次 POST（`[11,12,11]`）；修复后 `afterSecond=[true,true]`、`afterSecondSettled=[true,false]`，再次点击不再发请求（仍 `[11,12]`），#11 完成后两行恢复可用。
- 修复：busy 字符串改为 `busyKeys` 对象（:47）；`markBusy(key, active)` 只增删自己的 key（:62-74），`busyWith(prefix)`/`busyIs(key)` 查询（:75-76）；review/revoke/stopBatch/openFiles/execute 的 finally 各自清自己的 key（:234-247、:265-274、:292-300、:312-335、:377-395）；disabled 判断改为带冒号前缀/精确 key（:474、:477、:481、:493、:504、:727、:743）；抽屉 busy 由 `busyWith(review:+detail.id+:)` 派生（:769），不改 drawer 契约。
- 不确定性：staging 队列 0 条、批准/执行是写操作，未造合成申请做真实双请求竞态；该条证据来自 harness，staging 只验证页面与 bundle。

#### P2-4 / 中：删除批次轮询在卸载后续跑，错误时无界重试

- 触发：执行删除或停止批次后，`pollBatch()` 的 `GET admin/delete-batches/{id}` 仍在飞行时离开页面/切页（App 卸载）。
- 机制：`app.jsx:399-436` 在请求前把 `batchTimer.current` 置 0；原卸载 cleanup（:161-168）只 clearTimeout（此时 id=0），响应为 running 时 then 又 `setTimeout(pollBatch,4000)`，卸载后继续每 4s 轮询；catch 分支以 6000ms 间隔无界重试并在每次失败时 toast，卸载后也会继续。
- 影响：离开页面后仍持续轮询批次，并可能在无该卡的页面反复弹错误 toast；只读 GET，不写数据。挂载期间的错误重试是否要上限属于产品取舍，本轮未改。
- 复现：harness 渲染 `App`（canExecute），选中 1 条 approved → 预览 → 输入「删除」→ 执行（fetch 打桩），`GET admin/delete-batches/99` 延迟 500ms，150ms 时 unmount：修复前 `batchGets=2`、`batchGetsAfterUnmount=1`；修复后 `batchGets=1`、`batchGetsAfterUnmount=0`。
- 修复：新增 `batchAlive = useRef(true)`（app.jsx:59）；卸载 cleanup 置 false 并清零 timer（:161-168）；`pollBatch` 入口与 then/catch 首部检查 `batchAlive`（:399-436）。
- 不确定性：staging 未执行真实删除批次（写操作），该条证据来自 harness；staging 只验证页面与 bundle。

#### 版本、门禁与 staging（beta-19）

- 版本三处：`sakura-tools.php:6`、`sakura-tools.php:22`、`readme.txt:7` 均为 `1.30.0-beta-19`。
- JS：`npm run build` 成功，最终 `assets/ui/dist/admin-app.js` 489,387 B，banner `1.30.0-beta-19`；`node --check assets/ui/dist/admin-app.js` 通过；三个 JSX 经 esbuild 打包无语法错误。
- PHP 全门禁（PHP 8.4.28 PATH）：`php -l sakura-tools.php` 通过；PHPUnit 942 tests / 4232 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS strict 8/8、PHPCompatibility 8/8 且无报告项；`git diff --check` 通过。Composer 2.0/Symfony 的 phar Deprecated 仍为工具链噪音，退出码 0。
- staging 备份：首次同步前 `/root/backups/2026-11-27-beta19-pre-sync/sakura-tools`（145M，beta-18，`admin-app.js` SHA-256 `29e2d3d2…`）；补 batch guard 后第二次同步前 `/root/backups/2026-11-27-beta19-pre-sync-2/sakura-tools`（145M，首次 beta-19，`9272725f…`）。
- 同步：按 AGENTS.md 整目录 rsync（`--exclude /dist` 锚定根目录，未排除 `assets/ui/dist`），`chown -R www:www`；staging `sakura-tools.php` 版本 `1.30.0-beta-19`，工作副本/staging `admin-app.js` SHA-256 均为 `03d6eae0362df478d7bce23f41bd193526525592399beb99868553479bca0532`，属主 `www:www`、644、489,387 B。
- 浏览器（staging，管理员已登录，二次同步后重新加载）：queue 页 4 张卡（queue/execute/users/capability-matrix）、数据岛 `1.30.0-beta-19`、无骨架、队列空态正常；console 3 条、error 0 / warning 0；`admin/users`、默认 `admin/delete-requests`、`status=approved&unbatched=1` 三个 GET 均 200；页面 19 查询 / 265.391ms；1440 与 390 下 document/body scrollWidth 均等于视口宽。debug.log 同步前后均 165 行（无新增条目）。
- 清理：`/tmp/sakura-react-fixture.php`、`/tmp/sakura-react-fixture-clean.php` 已按精确路径删除（glob 确认无第三个同前缀）；staging Query Monitor `is-active` exit 1（inactive）；fixture marker `audit-ui-20261127-settings-1` 在 `wp db search --all-tables` 无命中、requests/items 定向 LIKE 均为 0；队列表 requests=0 / items=0；本轮未创建合成行或临时插件状态。
- 未验证：P2-1/P2-2/P2-3/P2-4 的真实 staging 端到端时序（受限于 demo 只有 self 用户、队列为空、sync/审核/执行属写操作）；REVIEW-only 角色路径沿用前轮未验证标记；未做 1440/390 截图级视觉验收，仅宽度与 DOM/console 证据；未对挂载期间 pollBatch 失败的 6s 无界重试设上限（待产品决定）。

### beta-20 PHP 性能与一致性（管理队列批量聚合 / 空间卡共享 option 锁）

范围：`src/UserFiles/Repositories/Requests.php`、`src/UserFiles/Rest/Controller.php`、`src/UserFiles/Services/Option_Lock.php`（新增）、`src/UserFiles/Admin/Space_Cards.php`、`tests/RequestsAdminQueueTest.php`、`tests/AdminQueueSummaryTest.php`、`tests/SpaceCardsLockTest.php`、`tests/bootstrap.php`（补 `WP_REST_Response` / `rest_sanitize_boolean` 桩）。未改 JS 业务代码；`assets/ui/dist/admin-app.js` 仅因版本号重建。三处版本一次升 `1.30.0-beta-20`。

#### A. 管理队列每行 2 条 SQL（更正原「N+1」措辞）

- 触发：管理员打开「删除请求」队列（`GET /sufm/v1/admin/delete-requests`），任意页大小。
- 机制：旧 `Controller::admin_queue()` 对每行调用 `describe_request()`，后者为取 `item_count` 执行 `items()`、为取 `bytes` 执行 `bytes()`，即**每行固定 2 条 SQL**（不是经典 N+1 的「每行 1 条」）；另外还有 4 条 `count(0, status)` 分别统计四个状态。总计 `2N+2+4`：N=20 时 46 条（前轮审计的实测值即由此而来）。
- 影响：队列默认 20 行即 46 条查询，翻页/搜索/每页调大都线性增长；`items()` 还会读出 `manifest_snapshot` 长文本后丢弃，DB 与内存都白付。
- 修复：
  - `Requests::summaries( array $ids )`：一条 `GROUP BY request_id` 聚合 `COUNT(*)` 与 `SUM(CASE WHEN object_type='file' THEN charged_bytes ELSE 0 END)`，缺失 id 零填充；语义与旧 `items()` 计数、`bytes()` 的 `object_type='file'` 过滤逐字一致（见 `Requests::bytes()`）。
  - `Requests::status_counts()`：一条 `GROUP BY status` 取代 4 条 `count()`。
  - `Controller::admin_queue()`：先取当前页 id，调用一次 `summaries()` 与一次 `status_counts()`，再以 `describe_request( $row, $summary, false )` 组装；响应键 `rows/total/counts` 不变。
  - `describe_request()` 新增可选 `?array $summary`、`bool $with_items=true`；默认行为不变，用户侧 `list_requests` / `get_request` / 详情等 4 处调用仍返回完整 `items`。管理队列因 React 只消费 `row.item_count` / `row.bytes`（抽屉的明细走 `admin/delete-requests/{id}/items` 独立端点，`assets/ui/src` 中无 `row.items` 消费者），管理队列的 `items` 保持 `array()` 空数组。
- 回归证据（PHPUnit）：`AdminQueueSummaryTest` 用 fake wpdb 断言 1 行与 25 行都恰好 4 条查询、2 次 `prepare`、`items === []`、缺失 summary 零填充、counts 正确；并断言默认 `describe_request()` 仍加载 2 个 items、`item_count=2`、`bytes=123`，证明用户侧契约未被改掉。
- 实机证据（staging，WP-CLI + `rest_do_request`，管理员）：合成 100 条 request × 3 items（marker `audit-beta20-20261127140001`，结束后清理）：
  - `per_page=20`：`status=200 rows=20 total=100 plugin_sql=4`；首行 `item_count=3 bytes=6144 items=0`；`counts={"pending_review":100,"approved":0,"needs_review":0,"queued":0}`。
  - `per_page=50`：同样 `plugin_sql=4`、`rows=50`、首行同值。4 条 SQL 分别为 count、rows、`GROUP BY request_id`、`GROUP BY status`。
  - 清理：`cleanup_remaining_requests=0 items=0`。
- 不确定性：管理队列 JSON 的 `items` 从「全量数组」变为 `[]`，本插件 React 不读；若外部脚本依赖该字段会看到形状变化（未发现此类调用）。四个状态 COUNT 合并后语义等价，但状态集合来自 DB `GROUP BY`，若未来新增 status 而 Controller 未列出，仅缺少对应 counts 键，不影响既有四键。

#### B. 空间卡共享 option 的读-改-写丢失更新

- 触发：两个浏览器标签页（或重试/并发的 REST 请求）几乎同时保存不同的空间卡；两张卡都写 `sufm_space_settings`（入口/编辑器卡写 `sufm_settings`）。
- 机制：`Space_Cards::save_space()` / `save_settings()` 都是 `get_option()` → `sanitize( array_merge( $stored, ['_partial'=>1], $fields ) )` → `update_option()`。两个进程读到同一快照时，后写者用自己快照里的旧字段覆盖先写者，先写字段静默丢失。前端 pending 锁只能约束同一标签页，**不能跨进程**。
- 影响：保存成功提示正常，但另一张卡刚改的开关/价格/积分规则被回退；无错误、无日志，属静默数据丢失。
- 修复：新增 `Nboke\UserFiles\Services\Option_Lock`（MySQL `GET_LOCK` / `RELEASE_LOCK`）：
  - 锁名 `sakura_tools_opt_` + `md5( table_prefix | option_name )`（≤64 字符；不同站点前缀/不同 option 不互撞）。
  - `acquire()` 以 `GET_LOCK(name, 5)` 等待最多 5 秒；同请求重入直接成功，已持另一把锁时返回 false；`release()` 幂等，`finally` 保证释放。
  - `save_space()` / `save_settings()` 在**读 option 之前**取锁，写回与缓存 flush 在锁内，`finally` 释放；取锁失败返回 `WP_Error( 'sufm_space_locked', 设置正在被另一个请求保存，请稍后重试。, 503 )`，**不落盘**。`Space_Cards::save()` 将 WP_Error 原样上抛，REST 层返回 503。
  - 未改 `Settings` 其他保存路径、未改 `Space_Settings::sanitize()` 的 partial 语义，未用前端锁替代服务端锁。
- 回归证据（PHPUnit，`SpaceCardsLockTest`）：
  - 串行两次 partial merge：`enabled=1` 后 `apply_bonuses=0`，两者都在（4 次 lock 调用成对）。
  - 确定性「竞争写者」：首次保存成功后，第二次 `GET_LOCK` 返回 0（模拟另一进程持锁），返回 `sufm_space_locked`，第一张卡的值保留、第二张卡未写入，`Option_Lock::held()===''`。
  - 锁失败路径：`GET_LOCK=0` 时只发 1 条 GET_LOCK、无 RELEASE_LOCK、option 未变。
  - 入口/编辑器共享 option：`editor_enabled=1` 后 `enabled=0`，两者都在。
  - 公共 `Space_Cards::save()` 锁失败时返回 WP_Error 且不创建 option。
- 跨进程实机证据（staging，两个独立 WP-CLI 进程）：进程 A `acquire('sufm_space_settings')` 成功并持锁 8 秒；进程 B 1 秒后启动，`acquire()` 在 `after=5s` 返回 `acquired=0`；A 于 8 秒释放，`held=` 空。证明锁跨 PHP 进程生效，而非同进程 memo。
- 不确定性：真实 HTTP 并发保存未在 staging 触发（避免写/回滚真实设置），跨进程证据来自 CLI；锁失败时用户看到 503 文案而非自动重试，是否要前端提示/重试属产品取舍；`GET_LOCK` 依赖同一 MySQL 实例，若未来多库/多实例部署需换成 DB 行 CAS 或事务；本轮只保护两张空间卡，其他 option 保存路径未加锁。

#### 版本、门禁与 staging（beta-20）

- 版本三处：`sakura-tools.php:6`、`sakura-tools.php:22`、`readme.txt:7` 均为 `1.30.0-beta-20`。
- JS：`npm run build` 成功，`assets/ui/dist/admin-app.js` 489,387 B，banner `1.30.0-beta-20`，SHA-256 `9370935745ec2e1070e40142fd239b45890392eacd4027b162404d93ef722894`；`node --check` 通过。本批未改 JS 源，重建只为 banner。
- PHP 全门禁（PHP 8.4.28）：9 个改动 PHP 文件 `php -l` 全通过；PHPUnit **952 tests / 4302 assertions / 5 skipped**（较 beta-19 增 10 tests / 70 assertions）；PHPStan `[OK] No errors`；PHPCS strict 8/8、PHPCompatibility 8/8，均无报告项；`git diff --check` 通过。Composer 2.0/Symfony 的 phar Deprecated 仍为工具链噪音，退出码 0。
- 备份：`/root/backups/2026-11-27-beta20-pre-sync/sakura-tools`（145M，同步前 beta-19，`admin-app.js` SHA-256 `03d6eae0…`）。
- 同步：AGENTS.md 整目录 rsync（`--exclude /dist` 锚定根目录，未排除 `assets/ui/dist`），`chown -R www:www`；staging 版本 `1.30.0-beta-20`，工作副本/staging `admin-app.js` SHA-256 同为 `93709357…`，属主 `www:www`、644、489,387 B；`Option_Lock.php` 已随目录同步。
- debug.log：同步前后均 165 行（无新增 fatal/warning）。
- 清理：Query Monitor `plugin is-active` exit 1（inactive）；marker `audit-%` requests=0、孤儿 items=0、`sufm_space_settings` 无 `audit-ui-*` 残留；队列表 requests=0 / items=0；本轮 `/tmp/beta20-*` 临时脚本与日志、以及 beta-19 遗留 `/tmp/beta19-*` 已删除；未创建临时插件或持久 fixture。

#### 未验证

- 未在 staging 浏览器实际执行「保存空间卡」并观察 200/503 回流（避免写真实设置；锁语义已用两个独立 CLI 进程验证，保存路径由 PHPUnit 覆盖）。
- 未做 beta-20 队列页 1440/390 的浏览器视觉验收；本批 PHP 改动不改变 DOM，`items=[]` 的消费兼容性由静态 grep + 单测覆盖。
- 未构造旧代码的失败用例做「修复前 46 条 vs 修复后 4 条」同机对照：4 条为 staging 实测，46 条为前轮审计实测/公式推算。
- 管理队列 `row.items` 对外部 REST 消费者的影响未做生态排查；本插件内无消费者。
- PHP 队列的 N+1 仍按 lead 安排交由另一模型处理，本批未触碰。
### beta-21 安全修复：REST 处罚 `days` 负数绕过永久确认

范围：`src/UserFiles/Rest/Controller.php` 的 `admin_sanction_create()`、`tests/AdminSanctionDaysTest.php`（新增）、`tests/bootstrap.php`（补 `get_user_by` / `sanitize_user` / `get_current_blog_id` 桩，`WP_REST_Request` 补 ArrayAccess）。未改 `Sanction_Service.php` 的 `max(0, days)` 语义，未改 UI/JS 业务代码；`assets/ui/dist/admin-app.js` 仅因版本号重建。三处版本升 `1.30.0-beta-21`。

- 触发：拥有处罚能力的账号直接调用 `POST /sufm/v1/admin/sanctions/{id}`，传 `days=-1`（负数）；不需要输入登录名 `confirm`。
- 机制（修复前）：`Controller.php:2453` 先 `(int)` 取值；`:2462-2472` 只有 `0 === $days` 且非警告类型才校验 `confirm`，负数跳过确认；`Sanction_Service.php:152` 的 `max(0, (int) days)` 把它归 0；`:155-158` 因 `0 === $days` 把 `ends_at` 置 `null` → 永久。即 `-1` 与 `0` 最终等价，但少了永久确认这一道。
- 影响：任一持有 `sufm_admin_sanction_users` 的账号（或被 CSRF/脚本复用的会话）可绕过「输入登录名」的永久处罚确认，直接给目标用户写入无结束时间的禁上传/禁登录/全禁处罚；按时间无法自然解除，只能人工撤销，属安全绕过而非 UI 误操作。正常 UI 只发 `0` 或正数，所以从界面看不出来。
- 修复：在 `admin_sanction_create()` 计算 `$days` 后、调用服务前增加最小边界校验——`$days < 0` 返回 `WP_Error( 'sufm_sanction_days', '处罚天数不能为负数。', 400 )`，**不进入 `apply()`、不写任何记录**。`days=0` 仍走原有「非警告必须输入登录名」确认；正数仍按 `MIN_HOURS`/`MAX_DAYS` 限期；`Sanction_Service` 的兜底 clamp 未改。
- 回归证据（Controller 级，`tests/AdminSanctionDaysTest.php`，6 tests / 20 assertions）：用真实 `Controller` + 真实 `Sanction_Service`（真实 `Sanctions`/`Events`/`Notifications`）配 fake `$wpdb`，直接调用 `admin_sanction_create()`：
  - `days=-1` 且带正确 `confirm=target`：返回 `sufm_sanction_days`，`inserts=0`（未创建任何处罚）；
  - `days=-999999`：同样 400 且 `inserts=0`；
  - `days=0` 无 `confirm`：返回 `sufm_sanction_confirm`，`inserts=0`；
  - `days=0` + `confirm=target`：创建 1 条，`ends_at=null`（确认后永久语义未变）；
  - `days=7` 无需确认：创建 1 条，`ends_at` 非空（限期规则未变）；
  - `warn` + `days=0`：无需确认，仍创建警告（现有 UI/服务兼容）。
  这组用例在修复前会在第一项失败（`inserts=1`），因此能抓住该绕过。
- 全门禁（PHP 8.4.28）：`php -l` 4 个改动 PHP 文件全通过；PHPUnit **958 tests / 4322 assertions / 5 skipped**（较 beta-20 +6 tests / +20 assertions）；PHPStan `[OK] No errors`；PHPCS strict 8/8、PHPCompatibility 8/8；`git diff --check` 通过。
- 版本与部署：三处 `1.30.0-beta-21`；bundle 489,387 B、SHA-256 `2f004f561eb8b529c33328871acd8ddda45350319132ad7d2c022bfc1541c6cc`、banner beta-21；备份 `/root/backups/2026-11-27-beta21-pre-sync/sakura-tools`（145M，beta-20，`admin-app.js` `93709357…`）；AGENTS 整目录 rsync（`--exclude /dist` 锚定）+ `chown www:www`；staging 版本 beta-21、hash 一致、`sufm_sanction_days` 守卫已存在；debug.log 仍 165 行；`wp_sufm_sanctions` 计数 0。
- 未验证 / 边界：按要求**没有**发真实 sanction POST、没有创建真实处罚记录，也没有在 staging UI 走处罚弹窗；证据来自 Controller 级单测 + fake DB。`Sanction_Service::apply()` 被其他内部调用方直接传负数时仍会被 `max(0)` 归 0（当前只有此 REST 入口，未改服务语义）；若未来新增处罚入口，应复用同一负数校验或把校验下沉到服务层。

### beta-23：REST 权限门对齐与卡片数组输入边界

范围：`src/UserFiles/Rest/Controller.php`、`src/UserFiles/Admin/Settings_Cards.php`、`src/UserFiles/Admin/Space_Cards.php`、`tests/UserFilesCapsGateTest.php`（新增）、`tests/CardsScalarInputTest.php`（新增）；三处版本 `1.30.0-beta-23`。未改 `Capability_Matrix.php`、JS、CSS；未重建 bundle。

#### P2 / 中：REST `/admin/caps` 权限门漏掉 can_review（已修）

- 触发：具备 manage_options、但没有 `sufm_admin_review_deletions` 的账号直接 `POST /sufm/v1/admin/caps`。
- 机制（修复前）：`Controller.php:156-162` 的 permission_callback 是 `space_can_manage()`（只判断 manage_options）；同一份矩阵的 admin-post 入口 `Capability_Matrix::save()`（`Capability_Matrix.php:254-257`）要求 `manage_options && Capabilities::can_review()`。
- 影响：同一写操作两条入口门槛不一致；矩阵决定谁能直接删文件，REST 可以绕过「能审核」这一条，与 admin-post 明确表达的意图相悖。
- 修复：新增 `Controller::space_can_edit_caps()`（`Controller.php:250-252`）= `current_user_can('manage_options') && Capabilities::can_review()`；`/admin/caps` 改用该回调（`Controller.php:160`）。grants/revoke/extend 仍用 `space_can_manage`（`Controller.php:130/140/150`），审核读取仍用 `space_can_review`（`Controller.php:214-224`）。
- 回归：`tests/UserFilesCapsGateTest.php`（6 tests）：路由绑定新回调；manage_options-only 拒绝；manage_options+review 允许；仅 review 拒绝（与 admin-post 一致）；manage_options-only 时 `space_can_review()` 仍为 true（说明不能直接复用它）；grants 路由仍是 `space_can_manage`。

#### P3 / 低：MB 与套餐行的数组输入会告警或静默变成 1（已修）

- 触发：`/settings/cards` 或 `/space/cards` 的 fields 中，MB 字段（`quota_default_bytes_mb` 等）或 `packages` / `points_packages` 行字段传数组（如 `gb: ["8"]`、`price: ["30"]`、`bytes_per_step_mb: ["2"]`）。
- 机制（修复前）：`Settings_Cards.php:172` 的 MB 循环先做 `(string)` 比较，数组触发 `Array to string conversion` 并存入 "Array"；`Space_Cards.php:395-399/423-427` 的行字段裸 `(string)` 同样告警；`Space_Cards.php:335-336/369-371` 的 `(int)` / `(float)` 把非空数组静默变成 1（等级步进 1 点 / 1 MB、积分比例 1）。
- 修复：
  - `Settings_Cards.php:175` 增加 `is_scalar()` 守卫（先于字符串比较），非标量按缺失字段处理；标量路径不变。
  - `Space_Cards.php:335-336/369-371` 改用 `scalar_int()` / `scalar_float()`；`cash_rows()`（:386-407）与 `point_rows()`（:414-435）的字符串字段改用 `scalar_string()`（:468-473），尺寸列改用 `size_gb()`（:505-515；标量 `gb` 优先、旧 `bytes_gb` 兜底、非标量 `gb` 视为缺失）；`flag()`（:523-529）对非标量返回 0，避免数组把 `remove` 变成 1 误删套餐。
  - 顺带修复 `Space_Cards.php:361`：原 `is_scalar($fields['order_icon'] ?? '') ? (string)$fields['order_icon'] : ''` 在缺键时仍进入 true 分支重读键，保存套餐（不带图标）会 `Undefined array key "order_icon"`；改为 `scalar_string()`，输出不变。
- 回归：`tests/CardsScalarInputTest.php`（7 tests）用 `set_error_handler` 捕获 E_WARNING：数组 MB 字段零告警且回落默认；标量 MB 仍精确换算（512 MB、1.5 MB）；等级规则数组 → 0 / 0.0（旧为 1 / 1 MB），标量 → 10 / 2.0 不变；cash 行标量 `gb` 仍优先于 `bytes_gb`、旧 `bytes_gb` 单列仍生效、数组 `gb` 回落到标量 `bytes_gb`；行字段数组零告警且空串化；非标量 `remove` 不删除行；积分 config 数组 → 0。
- 边界（未修，超出本轮写作用域）：读路径（`Space_Cards::plans()` 等）读取 sanitize 后的 option，未加防御；`Settings::sanitize()` 仍对任何 `*_mb` 值做 `(float)`，Zibll 性能 REST（`src/Zibll/Rest/Performance_Controller.php:76-92`，需 manage_options）把任意 fields 交给它，理论上可用数组把配额写成 1 MB；`Settings.php` 不在本轮允许写入的文件内。

#### 版本、门禁与 staging（beta-23）

- 三处版本：`sakura-tools.php:6`、`sakura-tools.php:22`、`readme.txt:7` 均为 `1.30.0-beta-23`。
- PHP 门禁（PHP 8.4.28，`/www/server/php/84/bin` 置前）：本轮 6 个 PHP 文件 `php -l` 全通过；定点 PHPUnit 13 tests / 45 assertions；全量 PHPUnit **971 tests / 4367 assertions / 5 skipped**（较 beta-21 的 958 / 4322 增 13 tests / 45 assertions）；PHPStan `[OK] No errors`；`composer lint:strict` 与 `composer lint:compat` exit 0；`git diff --check` 通过。Composer 2.0/Symfony phar 在 PHP 8.4 下的 Deprecated 仍为工具链噪音，退出码 0。
- bundle：本轮无 JS 源改动，未重建 `assets/ui/dist/admin-app.js`；banner 仍 beta-22，工作区与 staging 的 SHA-256 同为 `84c836c9987192cb4f0ada232eb85519cd2d6d31183e17e9ec2f6f83c2cbbcec`（489,387 B）。
- staging：beta-22 同步前备份已确认（`/root/backups/2026-11-27-beta22-pre-sync/demo-sakura-tools` 145M；另有工作区备份 `sakura-tools` 406M）；本轮 pre-sync 工作区备份 `/root/backups/2026-11-27-beta23-pre-sync/sakura-tools`（406M，`--exclude node_modules`）。整目录 rsync（`--exclude /dist` 锚定根目录、未排除 `assets/ui/dist`）后 `chown -R www:www`；staging `sakura-tools.php` 仍为 beta-23，bundle hash 一致，属主 www:www / 0644；debug.log 同步前后均 165 行。
- staging 只读探针（WP-CLI `eval-file`，未发 HTTP/业务 POST）：`version=1.30.0-beta-23`，`/sufm/v1/admin/caps` 的 permission_callback 为 `Nboke\UserFiles\Rest\Controller::space_can_edit_caps`，`space_can_edit_caps` 与两个 scalar helper 均 `method_exists=true`；临时探针文件已删除。
- 未验证：未发送任何真实业务 POST（`/admin/caps`、`/settings/cards`、`/space/cards` 均未实际调用）；REST 权限门只有回调级单测 + staging 路由注册探针，未在 staging 用真实角色发 HTTP 请求；本轮无 UI 变化，未做 1440/390 视觉验收与浏览器实操。
- 已知 UX 后果（未改 UI）：capability-matrix 卡片仍只按 manage_options 下发（beta-9 的既有行为）。因此「有 manage_options、无 REVIEW」的账号仍能看到矩阵，但保存会按新门槛 403——这与 admin-post 的既有 403 一致；是否要在卡片层也隐藏/禁用属产品取舍，本轮未改 payload/React。

### beta-23 复核（retry：验证 + 补测试，未改运行时 PHP）

- 范围更正：本批开头「未改 `Capability_Matrix.php`」与实际不符，该文件确已修改：`src/UserFiles/Admin/Capability_Matrix.php:277-318` 抽出 `apply()`，`save()`（:254-269）保留原有 `manage_options && can_review` 门、nonce、重定向与 event 审计；REST `save_caps()`（`src/UserFiles/Rest/Controller.php:171-196`）复用同一 `apply()`，两条写入口的矩阵应用逻辑与审计不再分叉。上段「本轮 6 个 PHP 文件」也未计入该文件。
- 权限门复核（只读代码 + 回调级测试）：`/admin/caps` 路由在 `Controller.php:154-162`，permission_callback 为 `space_can_edit_caps`（:250-252 = `current_user_can( 'manage_options' ) && Capabilities::can_review()`）；admin-post 门在 `Capability_Matrix.php:255`，两者逐字一致。`/admin/space/grants`（:130）、`/admin/space/grants/(?P<id>\d+)/revoke`（:140）、`/extend`（:150）仍为 `space_can_manage`；`/admin/space/grants` GET 与 `/admin/space/reconcile` 仍为 `space_can_review`（:214-224）。
- 新增回归（2 个测试方法，+3 tests / +17 assertions）：`tests/UserFilesCapsGateTest.php:134-158` 一次性断言 10 条空间路由的最终 permission_callback（caps 新门 + 其余路由未变；`/admin/space/grants` 因 GET/POST 同路径只保留最后注册的写门，读门由 :87-95 覆盖）；`tests/CardsScalarInputTest.php:338-374` 等级/VIP 奖励映射对非标量值跳过且保留 0，`:376-397` order_icon 数组→空串、标量原样、packages 非数组视为缺失，两处均以 `set_error_handler` 断言零 warning。
- 全量门禁（PHP 8.4.28，PATH 置前）：全仓 423 个 PHP 文件 `php -l` 0 失败；PHPUnit 974 tests / 4384 assertions / 5 skipped（较 beta-23 记录的 971/4367 增 3 tests / 17 assertions，定点 caps+cards 由 13/45 变为 16/62）；PHPStan `[OK] No errors`；`composer lint:strict` 8/8、`composer lint:compat` 8/8，均无报告项；`git diff --check` 通过。Composer 2.0/Symfony 的 phar Deprecated 仍为工具链噪音，退出码 0。
- 演示站一致性（只读核对，未再同步）：工作区与 staging 的 `sakura-tools.php`、`readme.txt`、`Controller.php`、`Capability_Matrix.php`、`Settings_Cards.php`、`Space_Cards.php`、`assets/ui/dist/admin-app.js` 逐文件 md5 相同；staging 版本 `1.30.0-beta-23`；bundle SHA-256 `84c836c9987192cb4f0ada232eb85519cd2d6d31183e17e9ec2f6f83c2cbbcec`（489,387 B，644 www:www）；debug.log 仍 165 行。备份：`/root/backups/2026-11-27-beta22-pre-sync/demo-sakura-tools`（145M，内容为 beta-21，bundle `2f004f56…`）与 `/root/backups/2026-11-27-beta23-pre-sync/sakura-tools`（406M，beta-23）均在。本轮代码改动仅 tests/（另只追加本报告），运行时文件与 staging 已一致，无需再同步，也未覆盖任何备份。
- 仍未验证：未在 staging 用真实角色发 HTTP POST（`/admin/caps`、`/settings/cards`、`/space/cards` 均未调用）验证 200/403，权限门证据为回调级单测 + 既有 staging 路由探针；未做浏览器 UI 实操与 1440/390 视觉验收；`Space_Cards` 读路径与 `Settings::sanitize()` 对 `*_mb` 的 `(float)` 仍未防御（上段已标注，属本轮写作用域外）。

### beta-23 浏览器复核：队列抽屉与处罚弹窗（staging，只读）

- 用唯一 marker `dq-verify-20261127T2330Z` 临时插入 1 条 pending_review request（#215，owner yexu，item #406，object_id 987654321989，1 MB），顺序 Playwright 验证后按 marker 清理：删除 request 1 / item 1，marker 残留 0、孤儿明细 0、requests/items 回 0/0，队列页重载 0 行；Query Monitor 仍 inactive（exit 1），debug.log 165 行不变，`/tmp/dq-verify-*.php` 已删。未改代码/版本、未部署、未触碰生产。
- 队列显示 `1.30.0-beta-23` 与行 `#215 yexu 1 项 1.00 MB 待审核`；点「详情」打开原生 `<dialog class="queue-drawer">`（`:modal=true`、焦点在关闭按钮），`GET /sufm/v1/admin/delete-requests/215/items` 200、抽屉 `GET /admin/users?search=yexu` 200；标题「审核详情」、`1 个附件`、1 个缩略图（1 MB；因 object_id 是惰性假 ID，对应文件名空）。关闭后 dialog 数 0、焦点回「详情」。
- 再开抽屉点「处罚」：`GET /sufm/v1/admin/users/1/sanction` 200；因目标即当前管理员 yexu，`can_sanction=false`，弹窗按 self/manage_options 守卫自动关闭并提示「不能处罚拥有管理后台权限的账号。」，未出现可提交表单；users 卡同目标「处罚」按钮 disabled 且 title 同文案。全程仅 GET，无 review/revoke/sanction POST；console 3 条、error 0、warning 0。
- 残余不确定：处罚弹窗因目标是当前管理员而立即关闭，未能在浏览器中观察完整只读表单（types/durations/reasons 渲染）与手动关闭路径；证据为 GET 200 + self 守卫提示。快照/控制台在 `/root/.dsh/mcp-playwright/output/page-2026-11-27T15-16-*.yml` 与 `console-2026-11-27T15-16-48-983.log`。

### beta-25：UserFiles Settings::sanitize() 的 `*_mb` 非标量输入边界

- 范围：`src/UserFiles/Settings.php:217-230`、`tests/UserFilesCoreTest.php`（+1 测试）；三处版本 `1.30.0-beta-25`。未改 CSS/JS、未重建 bundle，未改 `docs/开发规则/03`，未触碰其它 PHP。
- 复核结论（更正原记录）：原残余「`Settings::sanitize()` 对任何 `*_mb` 做 `(float)`，且 Zibll 性能 REST 把任意 fields 交给它」前半句成立、后半句不成立。`src/Zibll/Rest/Performance_Controller.php:90` 调的是 `Sakura\ImageOptimizer\Zibll\Settings::sanitize()`，与 `Nboke\UserFiles\Settings` 是两个类，`src/Zibll/Settings.php` 没有任何 `_mb` 或浮点换算。以数组 `duration`/`unit` 调用它会得到 `duration=1`、`unit` 回落 `forever`，并触发一次 `Array to string conversion`（PHP 8.4 实测），不会写 `sufm_settings`。
- 已确认的缺陷（直接 harness，PHP 8.4.28）：`Settings::sanitize( array( '_partial' => 1, 'quota_default_bytes_mb' => array( '512' ), 'max_file_bytes_mb' => array( '1' ), 'daily_upload_bytes_mb' => array( '2048' ), 'site_storage_budget_bytes_mb' => array( '4096' ) ) )` 在修复前返回 `quota_default_bytes=1048576`、`max_file_bytes=1048576`、`daily_upload_bytes=1048576`、`site_storage_budget_bytes=1048576`，且 **0 条 PHP warning**：PHP 8.4 对数组做 `(float)` / `(int)` 是静默的 1，只有 `(string)` 才告警。
- 输入路径与能力（逐个核对）：
  - `POST /wp-admin/options.php`，`option_page=sufm_settings_group`（`src/UserFiles/Admin/Page.php:186-195` 注册，sanitize callback 即此方法）：WP core 要求 `manage_options` + `sufm_settings_group-options` nonce，原始 `sufm_settings[...]` 数组直达 sanitize。**这是唯一无字段过滤的路径**。
  - `POST /sufm/v1/admin/settings/cards`（`Settings_Controller::save` → `Settings_Cards::save`）：gate = `App_Manifest::can_view('files-settings')` = `Registry::tab_can('files','settings')` = `manage_options` 或 `Capabilities::can_review()`；MB 数组在 `Settings_Cards::clean_fields():175` 的 `is_scalar` 处已被丢弃（beta-23），不会到达 `(float)`。
  - `POST /sufm/v1/admin/space/settings`（`Space_Cards::save` 的 ENTRY/EDITOR 卡）：gate = `files-zibll`（`manage_options`）；`Space_Cards::fields():314-320` 只转发 `enabled` / `editor_enabled`，`*_mb` 不可达。
  - `Module::maybe_import_theme_limits()`（`Module.php:155`）传入的是 sanitize 后的形状，无 `_mb` 键。
- 影响分类：完整性/可用性，非越权。触发者需 `manage_options`，同一主体本可合法把这些值改成 1 MB；效果是配额、单文件上限、每日上传量、全站预算被静默压到 1 MB（`max_file_bytes` 从默认 50 MB 降到 1 MB），上传大面积失败；无 warning、无审计，属低危加固。REST 面（含 REVIEW 可写的 settings/cards）在 beta-23 之后不接收数组，因此不存在低权限数组注入面。
- 修复：`Settings.php:225-229` 改为 `isset() && is_scalar() && '' !== (string)` 才换算，非标量按缺失字段处理（回落默认/已存字节值），标量换算不变；与 `Settings_Cards::clean_fields()` 同契约。
- 回归：新增 `tests/UserFilesCoreTest.php::test_megabyte_arrays_are_ignored_instead_of_becoming_one_megabyte()`，用 `set_error_handler` 断言零 warning 且四值回落默认；该用例在修复前因 1 MB 而失败。
- 门禁（PHP 8.4.28）：`php -l` 3/3；PHPUnit **975 tests / 4389 assertions / 5 skipped**（较 beta-24 记录 +1 test / +5 assertions，定点 `UserFilesCoreTest` 14/60）；PHPStan `[OK] No errors`；`composer lint:strict` 8/8、`composer lint:compat` 8/8；`git diff --check` 通过。
- 未验证：未发真实 HTTP POST 到 `options.php` 或两条 REST 写路由（按约束不做业务 POST），数组输入证据为 sanitize 直调 harness + 路由/能力静态核对；未在浏览器做保存回流（本轮无 UI 变化）。
- 部署（demo，PHP-only）：同步前快照 `/root/backups/20261127-233410-beta25-pre-sync/sakura-tools`（145M，beta-24，`Settings.php` md5 `58374581b3ac919870b66e1918f8d4c1`，路径由 `test ! -e` 保证未覆盖任何已有备份）。按 AGENTS 整目录 rsync（`--delete`、`--exclude .git/.agents/.dsh-ops/node_modules/.phpunit.cache`、锚定 `--exclude /dist`，未排除 `assets/ui/dist`）后 `chown -R www:www`：demo 三处版本均为 `1.30.0-beta-25`；`src/UserFiles/Settings.php` 与 `tests/UserFilesCoreTest.php` 的 md5 与工作区一致（`304be20632cae20cdc8567ac1cd4266c` / `ff7aa919ccdc237e9d8590ac3f31acf1`）；`assets/ui/dist/admin-app.js` SHA-256 仍为 `84c836c9987192cb4f0ada232eb85519cd2d6d31183e17e9ec2f6f83c2cbbcec`（489,387 B、www:www/0644）；demo 侧 `php -l src/UserFiles/Settings.php` 通过；`debug.log` 同步前后均 165 行。生产未触碰、未 push、未重建 bundle（banner 仍 beta-24）。

### beta-26：A7 把 DELETE_FILES 与用户 / 审计 / 同步管理拆开（权限最小化）

- 分级：中 / 安全加固（权限边界）。原状：`DELETE_FILES` 同时放行 `GET /admin/users`（全站账号，含邮箱）、`PATCH /admin/users/{id}/quota`、`POST /admin/reset-quotas`、`GET /admin/events`、`POST /admin/sync`、`GET /admin/sync`、`POST /admin/reconcile`；只被信任删文件的人同时能读账号表、改容量、读审计、启动同步。A7 由用户确认，按建议拆成三个独立能力。
- 触发 / 影响：任何只持有 `sufm_admin_delete_files` 的角色调用上述端点即可越权到「读全站用户 + 改容量 + 读审计 + 启动同步」；风险不在文件删除本身。修复后按新能力分组，删除批次、审核、处罚 gate 全部不变。
- 能力与路由（`src/UserFiles/Capabilities.php:62,70,78`；`src/UserFiles/Rest/Controller.php:629-631,657-666,728-747`）：
  - `MANAGE_USERS = sufm_manage_users` → `GET /admin/users`、`PATCH /admin/users/{id}/quota`、`POST /admin/reset-quotas`。
  - `VIEW_AUDIT = sufm_view_audit` → `GET /admin/events`；`files/log` 页签 gate 从 `execute` 改为 `audit`（`src/Admin/Registry.php:86,359`）。
  - `MANAGE_SYNC = sufm_manage_sync` → `POST /admin/sync`、`GET /admin/sync`、`POST /admin/reconcile`。
  - 仍用 `DELETE_FILES`：`/admin/delete-batches/preview`、`/admin/delete-batches`、`/admin/delete-batches/{id}`、`/admin/delete-batches/{id}/stop`；仍用 `REVIEW`：`/admin/delete-requests*`；仍用 `SANCTION_USERS`：处罚三路由；`/admin/caps` 仍 `manage_options` + `REVIEW`。
- 矩阵 / UI：`Capability_Matrix::caps()` 新增 3 列（`src/UserFiles/Admin/Capability_Matrix.php:74-88`，标签「户 / 志 / 同」），`admin_caps()` 纳入三能力（`Capabilities.php:95`）；React 矩阵按 payload 自动多 3 列，未改 `caps.jsx`。`Files_Admin_Payload` 在缺少 `MANAGE_USERS` 时隐藏用户容量卡（`:259`），并下发 `permissions.users/audit/sync`（`:126-128`）；`app.jsx:25,524,756` 与 `users.jsx:15,184,268` 用这些位控制卡片与「同步容量」按钮，无 CSS/样式改动。
- 授权生命周期：`upgrade_capability_split()` 仅对 `administrator` 一次性补三能力（`Capabilities.php:236-250`，option `sufm_cap_split_version`），不由任何 `DELETE_FILES` 角色推导；`revoke()` 删该 option（`:151`），矩阵保存仍 `mark_managed()`；`phpcs.xml.dist` 白名单补 3 条。
- 测试（PHP 8.4.28）：全量 **992 tests / 4525 assertions / 5 skipped**（beta-25 基线 975 / 4389 / 5，+17 / +136）。新增 `tests/UserFilesCapabilitySplitTest.php` 14 例：DELETE_FILES-only 对 users/quota/reset/events/reconcile/sync 全拒；每个新能力只开自己那组、其余组与执行/审核仍拒；四个删除批次仍放行；REVIEW / SANCTION gate 不变；默认授权 / 一次性升级 / 撤销生命周期；矩阵 roundtrip 只产生 `editor:+sufm_manage_users` 且保留既有能力；payload 为 10 列 × 10 格。`CapabilityMatrixTest` 更新为 10 能力（14 例）；`TabGateTest` 新增 audit gate（+2）。
- 静态门禁：改动 10 个 PHP `php -l` 全通过；`node --check assets/ui/dist/admin-app.js` 通过；PHPStan `[OK] No errors`；`composer lint:strict` 8/8 exit 0；`composer lint:compat` 8/8 exit 0；`git diff --check` exit 0。
- 构建：`npm run build` → `assets/ui/dist/admin-app.js` 489,505 B，banner `1.30.0-beta-26`，SHA-256 `379b6c9e…573c9`。
- 部署（仅 demo）：备份 `/root/backups/2026-11-27-beta26-pre-sync/demo-sakura-tools`（145M，beta-25，`test ! -e` 保证不覆盖）；整目录 rsync（`--exclude /dist` 锚定、未排除 `assets/ui/dist`，另排除 `.ptc-repl-*`）后 `chown -R www:www`。三处版本 `1.30.0-beta-26`；13 个核对文件（含 CSS）工作区 / staging md5 全同；bundle SHA-256 同、www:www/0644；demo 侧 6 个改动 PHP `php -l` 通过；`debug.log` 同步前后均 165 行。WP-CLI 只读探针：10 个路由的 `permission_callback` 与上表一致；`admin_caps` / `matrix_caps` 均含 3 新能力。
- 迁移观测：只读探针 A 时 `sufm_cap_split_version` 缺失；探针 B 时已为 `1`，administrator 有 3 能力、editor / author / contributor / subscriber 均无。本次未显式调用迁移（`wp eval` 的 `did_action('admin_init')` 为 0），判断为期间站点上的一次真实 admin 请求触发了 `Module::maybe_install()`；最终状态即预期的一次性迁移，未观察到其它角色或 option 被改动。进程内 try/finally 快照尝试因取快照时已是迁移后状态而未产生回滚。
- 浏览器（demo，管理员 yexu，只读 GET）：queue 页 `USER FILE SPACE / 1.30.0-beta-26`、4 卡；展开权限矩阵表头为 `管 / 申 / 审 / 删 / 户 / 志 / 同 / 媒 / 移 / 罚` 10 列，管理员三个新格 checked、「删」仍为锁图标；`t=log` 正常打开（17 查询 / 260ms）；两页 console 0 error / 0 warning。
- 未验证 / 边界：未用真实非管理员角色发 REST 请求或走浏览器（仅单元门禁 + 管理员只读页）；未发任何业务 POST（保存矩阵、sync、reconcile、删除批次、处罚、容量重置均未触发）；未在 1440 / 390 复看 10 列矩阵排布（C5 禁止改 CSS/视觉，新增列沿用现有 `.caps-matrix` 规则，可能比 7 列更挤，未做截图级验收）；未验证 REVIEW-only / MANAGE_USERS-only 等组合在真实站点的页签可达性（`files` 模块 gate 仍是 `review`，本次未改）；未在生产、未 push、未打包发布；CSS 本轮 0 修改（文件 mtime 均早于本轮 23:38，`git status` 里的 CSS 改动是 beta-24 的用户改动）。上述残余已统一登记到文末 **A8**。


### beta-27：A8-1 收口（原始字节数组 / Zibll 数组 / Space 读路径 / 轮询上限 / grants 读门）

- 范围：`src/UserFiles/Settings.php`、`src/Zibll/Settings.php`、`src/UserFiles/Space_Settings.php`、`src/UserFiles/Admin/Space_Cards.php`、`src/UserFiles/Rest/Controller.php`、`src/UserFiles/Admin/Page.php`、`assets/ui/src/files-queue/app.jsx`、四个测试文件；三处版本 `1.30.0-beta-27`。
- A8-1 #1（Settings 原始字节/整数键）：`sanitize()` 新增 `pick()` / `pick_string()`，所有 `clamp_bytes()` / `clamp_int()` 的入参先按标量取值，非标量按缺失字段回落默认；`tree_style`、`folder_accent`、`allowed_types`、`reference_notice_version` 同样收口。
- A8-1 #2（Zibll `duration` / `unit`）：`sanitize()` 与 `duration()` / `unit()` 对非标量先回落到已存值或默认值，不再出现 `absint(array)=1` 或 `(string)array` 告警。
- A8-1 #3（Space 读路径）：`Space_Settings` 新增 `scalar_string()` / `scalar_float()` / `scalar_int()`；`sanitize_packages()` / `sanitize_points_packages()` / `sanitize_points()` / `sanitize_level_rule()` / `sanitize_vip_bonus()` / `sanitize_level_bonus()` / `order_icon()` / `with_points_label()` 改用标量入口；`map_from_megabytes()` / `map_from_bytes()` 跳过非标量，`gb_to_bytes()` / `mb_to_bytes()` / `clamp_bytes()` / `clamp_int()` 对非标量返回 0 或下界。`Space_Cards::plans()` 另加 `is_array()` / `is_scalar()` 防御。
- A8-1 #4（轮询上限，产品选择 6 × 6 秒）：`assets/ui/src/files-queue/app.jsx` 新增 `batchFailures` 计数与 `batchError` 状态；连续 6 次失败后停止轮询，toast 提示并在批次区持久显示「批次状态连续查询失败，已停止自动刷新。请刷新页面查看结果。」；新批次或成功响应会清零。文案由 `Page.php` 的 `batchPollFailed` 下发。
- A8-1 #5（grants 读门，产品选择 MANAGE_USERS）：`GET /admin/space/grants` 的 `permission_callback` 从 `space_can_review` 改为 `Controller::space_can_manage_users()` = `Capabilities::can_manage_users()`，与 `/admin/users` 完全同权；`manage_options` 单独也不放行。写路由仍只认 `space_can_manage`（`manage_options`）。
- 前后对照（同一 harness，staging beta-26 vs 工作区 beta-27）：beta-26 对数组输入给出 `quota_default_bytes=1 MB`、`max_file_bytes=1 KB`、`max_user_files=1`、`max_folder_depth=1`，并产生 7 条 `Array to string conversion`；套餐 `label=Array`、`days=1`、`active=true`，积分比例与等级步进都是 `1`。beta-27 全部回落默认（512 MB / 50 MB / 1000 / 8，0 warning），数组积分套餐整行丢弃、数组套餐字段按缺失处理。原始 JSON 见 `docs/plans/2026-11-28-beta27-a7-a8/evidence/code/`。
- 回归：PHPUnit `996 tests / 4553 assertions / 5 skipped`；新增测试 `test_raw_byte_and_integer_arrays_are_treated_as_missing`、`test_array_duration_and_unit_fall_back_to_stored_values`、`test_non_scalar_package_fields_do_not_warn_or_leak_into_settings`、`test_grant_ledger_read_gate_requires_manage_users`。PHPStan `[OK] No errors`；PHPCS strict 与 PHPCompatibility 均 0 error / 0 warning；改动 PHP `php -l` 全通过；`npm run build` 产出 489,780 B，`node --check` 通过；`git diff --check` 通过。
- 部署与真实角色证据：见下段「beta-27 验收」与 `docs/plans/2026-11-28-beta27-a7-a8/README.md`。

#### beta-27 部署与独立验收探针（2026-11-28 00:38）

- 部署：先备份 demo staging（`/root/backups/2026-11-28-beta27-pre-sync/demo-sakura-tools`，145M，`test ! -e` 保证不覆盖），整目录 rsync（`--exclude /dist` 锚定、保留 `assets/ui/dist`，另排除 `.ptc-repl-*`）后 `chown -R www:www`。demo 三处版本为 `1.30.0-beta-27`；bundle SHA-256 `3b946d61fbfaa267c2754aca89604af73e8bed0ae8c014c7b4982f198d17fc1e`（489,780 B）；6 个改动 PHP 在 demo 侧 `php -l` 全通过；`debug.log` 同步前后均 165 行。
- `GET /admin/space/grants` 读门（真实 cookie + `X-WP-Nonce`，beta-27）：`admin` 200、`users` 200、`review_users` 200；`review`、`audit`、`delete`、`review_audit`、`review_sync`、`sync` 均 403 `rest_forbidden`。beta-26 基线中 `review` 为 200，说明「REVIEW 读账本」已收紧。
- 角色页 HTML（真实 HTTP，beta-27）：`review` 打开 queue 页 200、版本 `1.30.0-beta-27`、payload `permissions={execute:false,users:false,audit:false,sync:false,sanction:false}`、卡片为 settings/queue/execute，**无 users 卡**；`review_users` 200、`users:true`、出现 users 卡；`users`、`audit`、`sync` 单项能力角色直接访问 files 模块均 403（模块 gate 仍是 `review`）。证据：`docs/plans/2026-11-28-beta27-a7-a8/evidence/http/independent-page-probe.txt`。
- 完整 120 格矩阵（beta-27）：与 beta-26 逐格对比**仅 4 格变化，全部是 grants 门**（`review` / `review_audit` / `review_sync` 200→403；`users` 403→200），其余 116 格一致，`unexpected=0`；汇总 allowed 26 / gate-denied 64 / unauth 11 / skipped 9 / blocked 10（PATCH 被 nginx 405）。证据：`docs/plans/2026-11-28-beta27-a7-a8/evidence/http/matrix-beta27.md`。
- 浏览器（beta-27，管理员 queue）：4 张卡；10 列矩阵 1440/390 均无横向溢出（`documentElement.scrollWidth` 分别 1440/390），console 0 error / 0 warning；抽屉 #216 打开 3 个附件、关闭后焦点回“详情”；review 仅 queue 卡且不发 `/admin/users`，review_users 有 users 卡且 `/admin/users` 200；users/audit/sync 单项能力角色侧栏无“文件空间”，queue 与 audit 的 `t=log` 均 403（WordPress 核心入口）。证据：`evidence/browser.md` 与 `browser/*.png`。
- 处罚弹窗（非本人 `a7a8_target`）：只读表单完整（2 类型 / 3 时长 / 6 理由 / 备注），未提交；关闭后焦点落在 `BODY`，登记为 A8-1 #6。**beta-28 已修复**（见下节）。
- QM（非空队列）：toolbar `0.28 s / 15.5 MB / 18 查询 / 0 error`，4 条 Profiler 计时；QM 4.1.1 的 `#qm` 面板 DOM 在本站未挂载，数值取 `window.QueryMonitorData.data`，截图是展开的 toolbar。证据：`evidence/browser/qm-beta27.json`、`browser/admin-qm-toolbar.png`。
- 清理（独立核对）：requests 3→0、items 6→0（marker / 孤儿 0），9 个 `a7a8_` 用户删除（仅剩 `yexu`），`user_disable_admin` 恢复字符串 `'1'`，QM inactive（exit 1），`debug.log` 165 行且 md5 不变，events 42→42；`/tmp/a7a8` 已删。证据：`evidence/cleanup.md`。

#### beta-28：A8-1 #6 处罚弹窗焦点回流（2026-11-28 08:25）

- 根因：`SanctionDialog` 是原生 `<dialog>`，平台只在 `dialog.close()` 时归还焦点；原关闭路径先 `onClose()` 让 React 卸载，被动 effect cleanup 再对已脱离文档的 dialog 调 `close()`，归还时机丢失，`document.activeElement` 落到 `BODY`。
- 修复：`sanction.jsx` 新增 `returnFocus`，挂载时记录触发按钮与同卡片 `.card__title` 回退；统一 `close()` 先 `dialog.close()`、再 `onClose()`，下一拍 `focus({ preventScroll: true })`；取消、Esc、加载失败、保存成功共用。`users.jsx` 与 `drawer.jsx` 在打开时记录「处罚」按钮并传入。未改视觉样式。
- 真实浏览器（beta-28，管理员 queue，目标 `a8focus_target`）：点「取消」后焦点为 `BUTTON`「处罚」（`btn btn--small btn--ghost`，行内 `a8focus_target`）；重新打开后按 Esc，结果相同；dialog 均关闭。抽屉回归：打开→关闭详情→再打开→Esc，焦点每次都回「详情」。console 3 条 log，0 error / 0 warning。证据：`docs/plans/2026-11-28-beta28-a8-1-6/`（`README.md`、`evidence/focus-results.json`、`evidence/sanction-focus-esc.png`、`evidence/console-beta28.log`）。
- 门禁：996 tests / 4553 assertions / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-28`；bundle 490,521 B，SHA-256 `409b85796b7b37b7c209462a41500ba44a8d90f52b424850b31ba1e28a53a2a5`。
- 部署：备份 `/root/backups/2026-11-28-beta28-pre-sync/demo-sakura-tools`（145M，`test ! -e`）后整目录 rsync + `chown www:www`；demo 版本与 bundle SHA 一致；`debug.log` 165 行。
- 清理：合成申请 #219 / item 413 删除、临时用户 15 删除；requests 0 / items 0 / 仅 `yexu`；QM 保持 inactive。发布动作：不 push、不打 tag、不碰生产。

### beta-29：审核与处置页两卡重排（2026-11-28 08:50）

- 布局：`Layout::PAGES['files-queue']` 改为 `queue/execute` 6/6 一行，`users => [2,1,12]`、`capability-matrix => [3,1,12]`；两卡不再并列，1440 下各 1220px，1100px 以下仍单列。
- 用户容量：表头 `th:nth-child(5)` 与 `.sanction-cell` 统一右对齐；1440 下表头文字右缘与按钮右缘均为 1371px，表 1170/1170 无横向滚动。移动端当时保留 stacked 布局，按钮在「处罚」标签下方左对齐（beta-30 已改为独立用户卡，见下节）。
- 权限设置：保留低频折叠；`caps.jsx` 用 `matrix.columns` 渲染 10 项说明网格（缩写 + 名称 + 一句说明），桌面 5 列 / ≤1199 3 列 / ≤782 2 列 / ≤560 1 列；旧 `matrix.legend` 仅作空数据回退。`Capability_Matrix` 的 10 条 hint 与 `capsNote` 精简，新增 `capsLegendTitle`。矩阵首列 22%，`min-width:760px`，390 下在卡片内 322/762 滚动，表头与行标题均 sticky。
- 真实浏览器（beta-29，admin，queue）：1440 `pageScrollWidth=1440`、两卡均 1220px、说明 10 项 5 列；390 `pageScrollWidth=390`、users/matrix 358px、说明 1 列、矩阵内滚 322/762；console 3 条 log，0 error / 0 warning。证据：`docs/plans/2026-11-28-beta29-queue-layout/`。
- 门禁：996 tests / 4565 assertions / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-29`；bundle 490,999 B，SHA-256 `223222317ad0605e678bd3734a94c17cbb49b9b777863eb75eae435ed7a07d8d`。
- 部署：备份 `/root/backups/2026-11-28-beta29-pre-sync/demo-sakura-tools`（149M）后整目录 rsync + `chown www:www`；demo 版本与 bundle SHA 一致；`debug.log` 165 行。只做只读验证，未创建临时数据；不 push、不打 tag、不碰生产。

### beta-30：移动端用户容量卡改为独立紧凑卡（2026-11-28 09:00）

- 问题：β29 的移动端直接复用桌面表格的 stacked 形态，390 下出现 92px 标签列、逐格堆叠和约 634px 的整卡高度，空白集中在记录尾部与分页下方。
- 修复：`users.jsx` 新增 `.users-mobile` 列表，≤782px 隐藏 users 表格，按用户渲染 `<article>`：头部是身份 + 处罚（含禁用态与 title），中部是容量使用/文件两列，底部是单独容量输入 + 保存；复用同一份 rows/drafts/saving/sanctionTrigger 与搜索、分页状态。桌面表格和 β29 的全宽/右基线不变。
- 390：卡片 480px（原 634px），移动卡 322×155px，分页 57px，卡尾 12px；页面 scrollWidth=390。搜索 `yexu` 正常、每页 50/25 切换正常、`-1` 被前端校验拦截（无 PATCH）；临时用户处罚弹窗 `:modal=true`，Esc 后焦点回到该卡按钮，临时用户已删除。
- 1440：users 仍是 1220px 表格，表 1170/1170，处罚表头/按钮右缘 1371/1371，`.users-mobile` display:none。
- 门禁：996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-30`；bundle 493,323 B，SHA-256 `54f66fc0d15c04a50ffff1aa33003447548b4ba972581a64b054d710cb8462a6`。
- 部署：备份 `/root/backups/2026-11-28-beta30-pre-sync/demo-sakura-tools`（150M）后 rsync；demo 版本与 bundle SHA 一致；`debug.log` 165 行；requests 0 / items 0；仅 `yexu`。证据：`docs/plans/2026-11-28-beta30-mobile-users/`。

### beta-31：用户容量分页统一当前页样式（2026-11-28 09:10）

- 问题：用户容量卡使用共享 `Pager` 的 `.pagination__now` 裸文本当前页，和删除/同步页的 `.pagination__link.is-current` 粉色实心不一致；截图里当前页只是 `1 / 1` 文本。
- 修复：`assets/ui/src/files/shared/Pager.jsx` 把当前页改为 `<span className="pagination__link is-current" aria-current="page">`；每页按钮、摘要、前后翻页、移动端两行布局与逻辑未改。共享组件改动同时统一文件日志/队列分页。
- 视觉：1440 当前页 53×36px、390 49×30px，粉色渐变 + 白字；390 页面 scrollWidth=390；1440 为 1440。
- 交互回归：每页 50 选中、恢复 25 正常；1/1 时上一页/下一页 disabled；console 3 log / 0 error / 0 warning。
- 门禁：996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-31`；bundle 493,357 B，SHA-256 `4f8565046314fa80aeb0724192bceee5616a11d519b8c95fa63d1f26136df58b`。
- 部署：备份 `/root/backups/2026-11-28-beta31-pre-sync/demo-sakura-tools`（152M）后 rsync；demo 版本与 bundle SHA 一致；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0。证据：`docs/plans/2026-11-28-beta31-pager/`。

### beta-32：粉色实心按钮统一 #F585AC（2026-11-28 09:20）

- 需求：所有按钮的粉色实心统一为开发者指定的 `#F585AC`。
- 实现：`--g-cta-fill` 改纯色 `#F585AC`、hover `#EE6F9B`、描边 `#D95B86`，新增 `--g-cta-ink: var(--g-ink)`；把 `.btn--primary`、`.sakura-mod.is-active`、`.sakura-tab.is-active`、`.pagination__link.is-current`、`.filter-chip.is-active`、`.button-primary` 的文字从 `--g-accent-ink` 换为 `--g-cta-ink`，并移除主按钮的深色 text-shadow。
- 对比度：白字在 `#F585AC` 上仅 2.38:1，墨字 `#3A2333` 为 6.02:1；因此坚持墨字。危险按钮 `.btn--danger` 保持砖红渐变 + 白字。
- 计算样式实测：主按钮/当前模块/当前页签/当前每页/当前页/筛选 chip/WP 主按钮均为 `rgb(245,133,172)` + `rgb(58,35,51)`；1440/390 pageScrollWidth 1440/390，390 用户卡仍 480px。
- 门禁：996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-32`；bundle 493,357 B，SHA-256 `21756ec85ef2eb9b05dfa36c1451b93a3f363708aebc153205b73c9767def78a`。
- 部署：备份 `/root/backups/2026-11-28-beta32-pre-sync/demo-sakura-tools`（153M）后 rsync；demo 版本与 bundle SHA 一致；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0；console 3 log / 0 error / 0 warning。证据：`docs/plans/2026-11-28-beta32-buttons/`。

### beta-33：粉色按钮改樱花淡粉渐变 + 白字（2026-11-28 09:55）

- 需求修订：β32 的浅粉实心 + 墨字被开发者否掉（“颜色深会显得土”），改为 `#F585AC → #FFB3CA 56% → #FFD6E5` 渐变 + 白字 + 轻粉阴影。
- 实现：`--g-cta-fill` / `--g-cta-fill-hover` 改淡粉渐变，`--g-cta-line` #E88AAB，`--g-cta-ink` #FFF；主按钮、当前模块/页签、当前每页/当前页、筛选 chip、WP 主按钮统一；危险按钮保持砖红渐变 + 白字。
- 对比度：白字在渐变起点 2.38:1、中点 1.67:1、终点 1.31:1，低于 4.5；这是开发者明确选择的审美例外，文档已标注，不得再用深色渐变/深色字回退。
- 实测：1440/390 pageScrollWidth 1440/390；390 用户卡 480px；各控件文字均为白色；console 3 log / 0 error / 0 warning。
- 门禁：996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。三处版本 `1.30.0-beta-33`；bundle 493,357 B，SHA-256 `4f5594b96faf0018be5c056e648f644da171bddfa3fa0a568c36769a5dfcd798`。
- 部署：备份 `/root/backups/2026-11-28-beta33-pre-sync/demo-sakura-tools`（153M）后 rsync；demo 版本与 bundle SHA 一致；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0。证据：`docs/plans/2026-11-28-beta33-buttons/`。

### 1.30.1 正式发布（2026-11-28）

- 发布范围：1.30.0 之后 beta-1～beta-33 的累计改动（A7 权限拆分、用户列表并入队列、后台 UI/分页/按钮、Zibll REST 化、输入边界与权限修复）。
- 版本：`1.30.1`；门禁 996 / 4565 / 5 skipped，PHPStan `[OK]`，PHPCS strict 0/0，compat 0/0；bundle 493,349 B / SHA-256 `e6b7a8ad9640e82c151d0785bf1b97116f8dad45bbce8e5f9a3a58dcb014abe8`。
- 生产备份：`/www/backup/sakura-tools-1.30.1-pre-20261128/`（代码 184M + 整库 2.9M，SHA 见同目录）。
- 发布记录：`docs/plans/2026-11-28-release-1.30.1.md`。

## A8 残余登记（2026-11-28 beta-27 / beta-28 更新）

> 2026-11-27 beta-26 收尾时登记的五条 A8-1 与八条 A8-2，已在 beta-27 处理或核验；本节保留原始问题描述并就地标注结果，不再以“未验证”作为整体结论。beta-27 的代码、门禁、部署与证据见上文 `### beta-27` 与 `docs/plans/2026-11-28-beta27-a7-a8/`（`README.md`、`evidence/http/matrix-beta27.md`、`evidence/browser.md`、`evidence/cleanup.md`）。

### A8-1 缺陷处置（beta-27）

1. **已修（beta-27）：`Settings::sanitize()` 的字节与整数收口对数组仍是静默 1。** 触发：`POST /wp-admin/options.php`（`option_page=sufm_settings_group`）直接提交 `sufm_settings[quota_default_bytes][]` 等原始字节/整数键。beta-26 实测 `quota_default_bytes→1048576`、`max_file_bytes→1024`、`max_user_files→1`、`daily_upload_bytes→1048576` 且 0 warning。修复：`sanitize()` 新增 `pick()` / `pick_string()`，所有 `clamp_bytes()` / `clamp_int()` 入参先按标量取值，非标量按缺失字段回落默认；`tree_style`、`folder_accent`、`allowed_types`、`reference_notice_version` 同步收口。证据：`evidence/code/before-beta26.json`（7 条 `Array to string conversion`、四值与实测一致）与 `after-beta27.json`（0 warning、512 MB / 50 MB / 1000 / 8）；回归测试 `tests/UserFilesCoreTest.php::test_raw_byte_and_integer_arrays_are_treated_as_missing`。
2. **已修（beta-27）：Zibll 授权缓存的 `duration` / `unit` 收数组会告警并把时长降为 1。** 触发：Zibll 性能卡保存路径把 `duration` / `unit` 传成数组。修复：`src/Zibll/Settings.php` 的 `sanitize()` 对非标量先回落到已存值、再回落默认；`duration()` / `unit()` 读取路径同样加标量守卫，不再 `absint(array)=1` 或 `(string)array`。回归测试 `tests/Zibll/ZibllSettingsTest.php::test_array_duration_and_unit_fall_back_to_stored_values`（0 warning，`6 / hour` 保留）。
3. **已修（beta-27）：`Space_Cards::plans()` 读路径未防御非标量。** 根因不只在读路径：`Space_Settings::get()` 每次读取都会跑 `sanitize()`，而 `sanitize_packages()` / `sanitize_points_packages()` / `sanitize_points()` / `level_rule` 等仍对数组裸做 `(string)/(float)/(int)`。修复：`Space_Settings` 新增 `scalar_string()` / `scalar_float()` / `scalar_int()`，上述入口、`map_from_*`、`gb_to_bytes()` / `mb_to_bytes()` / `clamp_*()` 全部改为标量守卫；`Space_Cards::plans()` 另加 `is_array()` / `is_scalar()` 防御。beta-26 对照：套餐 `label=Array`、`days=1`、`active=true`、积分比例/等级步进=1；beta-27：0 warning、数组积分套餐整行丢弃、数组套餐字段按缺失处理（`days=0`、`active=false`、label 回落 id）。证据同上 `evidence/code/`；回归测试 `tests/SpaceSettingsTest.php::test_non_scalar_package_fields_do_not_warn_or_leak_into_settings`。
4. **已修（beta-27，按产品选择 6 × 6 秒）：删除批次轮询失败后无退避上限。** `assets/ui/src/files-queue/app.jsx` 新增 `batchFailures` 计数与 `batchError` 状态：连续 6 次失败（间隔 6 秒）后停止轮询，toast 并持久显示「批次状态连续查询失败，已停止自动刷新。请刷新页面查看结果。」；成功响应或新批次开始时清零。文案由 `src/UserFiles/Admin/Page.php` 的 `batchPollFailed` 下发。**限制**：没有在 demo 造真实删除批次并注入连续网络失败（属业务写/故障注入），本条的验证是代码审查 + 构建 + `node --check`，未做浏览器真实失败注入。
5. **已按产品决定收紧（beta-27）：`GET /admin/space/grants` 对 REVIEW 开放。** 用户选择“收紧为 MANAGE_USERS”。实现：`Controller::space_can_manage_users()` = `Capabilities::can_manage_users()`（与 `/admin/users` 同权；`manage_options` 单独也不放行），GET 路由改用它；写路由仍只认 `space_can_manage`。beta-26 → beta-27 真实 HTTP：`review/review_audit/review_sync` 200→403，`users` 403→200，`review_users` 保持 200，`admin` 200；回归测试 `tests/UserFilesCapsGateTest.php::test_grant_ledger_read_gate_requires_manage_users`。
6. **已修（beta-28）：处罚弹窗关闭后焦点未回到触发按钮。** 复现（beta-27）：在 users 卡对 `a7a8_target` 打开处罚弹窗（完整只读表单、2 类型 / 3 时长 / 6 理由 / 备注，无提交），点“取消”后 `document.activeElement` 为 `BODY`；已显式先 `focus()` 触发按钮再复测，排除测试方法假象。影响：键盘用户关闭弹窗后丢失位置。修复：`SanctionDialog` 新增 `returnFocus`，挂载时记录触发器与卡片标题，`close()` 先关原生 dialog 再 `onClose()`，下一拍 `focus({preventScroll:true})`；取消 / Esc / 加载失败 / 保存成功共用，两个调用点传入触发按钮。beta-28 实测取消与 Esc 均回到目标行「处罚」按钮，console 0 error / 0 warning；抽屉两次开关仍回「详情」。证据：`docs/plans/2026-11-28-beta28-a8-1-6/`。

### A8-2 浏览器 / 角色流核验（beta-27）

1. **已关闭：非空队列的 Query Monitor 页面样本。** 3 条合成 pending_review 行（#216/#217/#218）下，管理员 queue 页 QM toolbar：0.28 s / 15.5 MB / 18 次查询 / 0 error，Profiler 4 条（`Sakura boot` 2.586 ms、`Sakura admin page files/queue` 4.447 ms 等）。**限制**：QM 4.1.1 的 `#qm` 面板 DOM 在本站未挂载，数值取面板同源的 `window.QueryMonitorData.data`，截图是展开的 toolbar；QM 已恢复 inactive。证据：`evidence/browser/qm-beta27.json`、`browser/admin-qm-toolbar.png`。
2. **已关闭：真实角色的 HTTP 检查（cookie + nonce + 真实角色）。** 9 个临时用户 + 匿名 × 12 端点 = 120 格；beta-27 与 beta-26 逐格对比只有 4 格变化，全部是 grants 门（见 A8-1 #5），其余 116 格一致，`unexpected=0`；汇总 allowed 26 / gate-denied 64 / unauth 11 / skipped 9 / blocked 10。**限制**：`PATCH /admin/users/{id}/quota` 在 demo 被 nginx 在 PHP 前拦成 405（`index.php`/`wp-json` 404），HTTP 层不能作为 gate 证据，另以 `rest_do_request` 逐角色补证（admin/users 404、review/delete/audit/sync 403）；业务写端点仍只测拒绝行。证据：`evidence/http/matrix-beta27.md` 与 `aux-patch-dispatch-beta27.json`。
3. **已关闭（只读表单渲染 + 焦点回流）。** 非本人目标 `a7a8_target`：处罚弹窗 `:modal=true`，类型 2、时长 3、理由 6、备注框齐全，无 `<button type=submit>` / form action，未发 POST；关闭后 dialog 数 0。**限制**：该弹窗的 `GET /admin/users/14/sanction` 未单独记状态码（表单完整渲染可佐证成功，但缺严格状态码）。焦点回流缺口已由 beta-28 关闭（见 A8-1 #6）。证据：`evidence/browser.md` §3、`docs/plans/2026-11-28-beta28-a8-1-6/`。
4. **仍未验证：其它页面的保存 → 重载回流。** 空间套餐、图片规则、存储连接/重写/成本、Zibll 授权缓存与性能页的保存/重载均会写 option 或触发外部探测，属业务 POST；按约束未发。已覆盖的 files settings 保存回流见前文 `### 未覆盖项`。
5. **已关闭：10 列权限矩阵在 1440 / 390 的排布。** 管理员 queue 页展开矩阵：1440 下表 548 px、容器 scrollWidth 550 px，390 下表 320 px、容器 322 px，均无横向滚动；`document.documentElement.scrollWidth` 分别等于 1440 / 390；表头 `管/申/审/删/户/志/同/媒/移/罚` 10 列、5 个角色行；console 0 error / 0 warning。截图：`browser/admin-queue-1440.png`、`browser/admin-queue-390.png`。
6. **已关闭为“设计边界已确认”：MANAGE_USERS-only / VIEW_AUDIT-only / MANAGE_SYNC-only 的页面可达性。** 三个单项能力角色后台侧栏均无“文件空间”，直接访问 queue 均 403（WordPress 核心入口拦截，文案“抱歉，您无权访问此页面。”）；`audit` 再试 `t=log` 同样 403。REST 已按能力拆开，但 `files` 模块 gate 仍是 `review`（`src/Admin/Registry.php`），所以这些角色“有端点权限、无页面入口”。这是已记录的设计边界，本轮不改 module gate；是否放宽到“任一管理能力”仍需产品决定。
7. **仍未验证：一次性迁移的真实触发观测。** demo `sufm_cap_split_version=1`、administrator 有三项新能力、其他角色无；迁移由哪次真实 `admin_init` 触发仍未受控重放（重放需删 option 再发后台请求，属持久状态改动）。按本轮范围不做。
8. **仍未验证：删除执行、审核、处罚的真实写路径。** 本轮仍未发业务写 POST（只读 GET 与空 body 预检除外）；三条链路的“路由/能力/单测不变”有证据，真实写入与数据库副作用没有。按约束不做，demo 也不适合造真实删除/处罚。

### A8-3 用户自有 CSS 与矩阵排布

- 工作区 5 个 CSS 文件的未提交改动（`assets/ui/base.css`、`components.css`、`pages/files.css`、`pages/storage.css`、`tokens.css`）是 **beta-24 用户资产**，beta-27 0 修改、0 回退；`git status` 里仍在。
- beta-26 时担心的“10 列矩阵排布”已在 beta-27 用 1440/390 截图验收：无横向溢出、列宽正常（见 A8-2 #5）。
- 审计已有的 CSS 高/中项（`Page_Assets` 每页预载全部 9 个页面样式、主按钮对比度、image.css 旧样式）继续按 C9/C5 保持现状。

### A8-4 延后范围（本轮仍未开始）

- 发布动作：不 push `main`、不打 tag、不跑生产部署、不把 beta-27 收敛成正式号；beta 号留在本地。
- `docs/开发规则/**` 其余逐册新人演练与行号漂移复核（本轮只改了 05/06/09 的基线/门说明）。
- 性能深读（三态采样、无上限读取剩余项）、JS metafile/隐式全局/未处理 Promise 的逐条 AST 配对、`src/Filesystem/**` 逐行。
- 生成物 `docs/sakura-tools-代码结构-1.28.3.md` 的重新生成（管线扫描器不在本仓库）。
- 约 80 条低风险审计项的批量清理；A8-2 #4/#7/#8 是下一批候选（A8-1 #6 已在 beta-28 关闭）。
- P2/P3 功能线（处罚邮件/申诉、媒体库管理页等）按既有文档状态继续延后。

### A8-5 暂停点与恢复条件（beta-27 更新）

- **beta-27 已完成并部署到 demo**：三处版本 `1.30.0-beta-27`，bundle SHA-256 `3b946d61…1fc1e`，门禁全绿，真实 HTTP / 浏览器 / QM / 清理证据已入库；生产未触碰、未 push、未打 tag。
- **新暂停点：本节写完后停止。** 不再改 PHP/JS/CSS、不再升版本、不再同步 staging；A8-1 #6 与 A8-2 #4/#7/#8 只登记，不代表已修。
- 恢复条件：开发者明确授权下一批后，按现有纪律执行——动第一行代码前把三处版本 +1；补失败测试（尤其处罚弹窗焦点回流）；改 JS 则重建 bundle；跑全量 PHPUnit/PHPStan/lint:strict/lint:compat/`php -l`/`node --check`/`git diff --check`；staging 同步前做新的非覆盖备份；仍不碰生产、不 push。
- 证据复用：A8-2 #4/#7/#8 如要关闭，优先在 demo 用受控临时数据与真实 HTTP/浏览器重放，完成后按 `evidence/cleanup.md` 的口径清理；在拿到这类证据前，不声称这些链路已端到端验收。
