<!-- deep-ptc:project-cards v1 next=R1,F28,Q7,T13 -->
# Project cards

> Maintained by Deep PTC in DeepSeek Harness: edit in a Deep PTC conversation, view 特点卡片 / Project cards.
> User-approved settings guide this project. Features and requirements are records that can be checked and corrected.

## Implemented features / 已实现功能
<!-- card id=features kind=features rev=14 updated=2026-11-24T10:46:34.405Z -->

- **F1** 切桶封存与解锁（R18）：切桶时同一次 update_option 写入 s3_options['reconcile_pending'] = {profile_id, at, bucket, from}，同步中心立刻封存；只有跑完 mode=full 或 resume 的完整核对，才由 Scan_Service::finalize() 调 Options::clear_reconcile_pending() 清零。验证：同步中心 #sakura-sync-state 的 data-reconcile-gated=1，refresh / resume / retry 三个端点返回 409 sio_reconcile_required。
- **F2** 切桶提醒走插件弹窗（#sakura-s3-switch-modal，kicker「OBJECT STORAGE / SWITCHED」）：列出缩略图改写已关、同步中心数据仍是上一个桶的、上传行为两开关仍按原绑定三件事，按钮直达同步中心。刷新后才出现的顶部 WP admin notice 已删除。
- **F3** 桶身份只有一处定义（R19）：S3\Bucket_Identity 的 ADDRESS_FIELDS = provider | bucket | endpoint | upload_path，只有这四项决定对象在哪。region 归可达性（B 档），space 与 upload_url_path 只做展示（C 档），都不参与「是不是同一个桶」的判定。
- **F4** 存在性确认只有一个入口（R19.4）：Bucket_Confirmation 的 confirms(int) / confirms_key(string) / confirms_many(array)，带 wp_cache（CACHE_GROUP sakura_imgopt_bucket，TTL 3600，删除对象时整组 generation 退休）。批量场景用 confirms_many 一次查询，不要逐 key 调 confirms。
- **F5** 对账索引按桶身份分区（Schema 1.9.0）：sio_sync_index 与 sio_sync_counts 的首列是 target_hash，唯一键 target_object(target_hash, object_key_hash)。升级后首次请求自动迁移（回填桶身份、合并同桶重复行、重建 counts）。验证：SHOW INDEX 只剩 PRIMARY + status_keyset / target_dir / target_key / target_kind / target_object / target_recent。
- **F6** 渲染期绝不写库（R19.5）：Storage 在 wp_get_attachment_url（优先级 25）与 wp_calculate_image_srcset（优先级 20）上只读不写 post meta；指纹回填只在完整/续跑核对结束时由 Scan_Service::backfill_fingerprints() 做一次。验证：连续 30 次 wp_get_attachment_url 的 write_queries=0。
- **F7** 绑定与冻结（R3 / R4 / R11）：第一次成功上传确认 synced 之后、删除本地文件之前写 binding 快照；绑定后改任一项身份，服务端返回 error=locked 并拒绝写库；上传行为两开关（no_thumb / no_local_saving）整卡冻结，忽略 POST、取 binding 快照。
- **F8** 缩略图能力推断不再联网（R15）：已删除 Thumbnail\Probe 与 Thumbnail\Provider_Status 两个类，改由 Thumbnail\Provider_Registry 静态声明能力；旧 option sakura_imgopt_thumb_probe 会被主动 delete_option()。切桶时缩略图改写总开关自动关闭，并写一次性 transient 触发弹窗。
- **F9** 多吉云临时凭据按需续期（R17）：多吉云只签发约两小时有效的 S3 临时凭据，客户端不再取一次当永久用；凭据错误由 S3\S3_Credential_Error 承载。跨两小时的批量任务（全量重建、重试队列、WP-CLI）不会再中途集体失败。
- **F10** 云端下载回本地补写两处（R13 / R14）：附件落库后回写同步状态（Sync_Status::mark_synced() → status=synced）与用户文件空间账本（UserFiles\Services\Media_Adoption 写 sufm_usage）。此前只落了文件，同步中心显示 pending、配额少算一份。
- **F11** WP-CLI：wp sakura-sync scan 支持 --profile / --full / --resume / --pages / --seconds / --local-root。--full 会清 R18 封存闸门并重贴 R19 指纹，不加则走增量。1.28.3 修的是 docblock 漏声明 --full，导致 WP_CLI 直接拒绝整个命令。
- **F12** 同步中心「开始完整核对」发的是 full：Sync_Center_Module::ajax_scan() 调 Scan_Service::start('', 'full')。1.22.8–1.28.2 期间不传 mode 走默认增量，所以这个按钮从未真正全量过，也让 R18 的解锁条件不可能满足。
- **F13** 对账索引批量写入的回归护栏：Index_Repository::upsert() 每个元组自带 profile_id 标签（12 个占位符对 13 列）。tests/IndexPurgeTest.php 的 test_a_batch_upload_carries_one_label_per_row 用 substitute() 把参数填回 SQL 断言每行各带自己的标签——旧 wpdb double 只序列化、抓不到这类错位。
- **F14** fast-search.php 走 SHORTINIT、没有 autoloader：索引查询必须手写与 Bucket_Identity::hash() 一致的哈希（sha256('v2|provider|bucket|endpoint|upload_path')）并查 target_hash 列。改桶身份算法时这一处必须同步改。
- **F15** PHP 兼容静态检查（8.2 语法下限 / 8.4 生产运行时）：phpstan.neon 加 phpVersion: 80200；新增 phpcs-compat.xml.dist（PHPCompatibility 10.0.0-alpha2，testVersion 8.2-8.4）与 composer lint:compat 脚本；修掉 7 处未传 $escape 的 fputcsv（includes/class-s3-cli.php 2 处、src/S3/Delete_Queue_Panel.php 2 处、src/S3/Sync_Center_Module.php 3 处，均显式传逗号、双引号、空串）。验证：phpcs --standard=phpcs-compat.xml.dist 0 error / 0 warning；PHPStan [OK] No errors；897 tests / 3816 assertions / 6 skipped；lint:strict 0/0。规则见 docs/开发规则/13-PHP代码规范.md §6 与 05-测试与质量.md 版本兼容小节
- **F16** F16 全新安装不再打数据库错误、实验站迁到宝塔本机：Sync\Schema::prepare_bucket_partition() 在索引表尚未创建时先跑 SHOW INDEX（WP_DEBUG_DISPLAY 会把 WordPress database error 打进 debug.log），新增 has_table() 守卫，表不存在直接返回让 dbDelta 建表。验证：demo.77il.cn 整站重置（drop 25 张表 + 删全部 sakura_imgopt_* / sufm_* / s3_options 后 activate）→ 25 张表重建、debug.log 0 行、五个页签零弃用零警告；旧站 /root/sio-e2e 与 docker 的 MariaDB/MinIO 已删除，备份 /root/backups/sio-e2e-20261124-1316/。版本 1.28.8-beta-35，提交 2ff2a92（未 push）。
- **F17** F17 实验站同步完整性与 S3 客户端不读服务器共享配置：① 实验站同步必须带 assets/ui/dist/（system.js / image.js），rsync 排除项写 /dist（锚定根目录）不能写 dist，否则 React 页脚本 404、永远停在「正在读取页面信息…」骨架屏——2026-11-24 实测复现并修好，AGENTS.md §6 与 05 篇已写入命令；② src/S3/Client_Factory.php 的 client config 加 use_aws_shared_config_files => false，避免 SDK 对 ~/.aws/config 调 is_readable 在 open_basedir 下打 PHP Warning 进 debug.log（宝塔站点实测），凭据仍由插件显式给出。验证：demo.77il.cn 五页签零报错零失败请求，wp sakura-sync scan --pages=1 真实扫描 16 对象 / 10.56 MB 后 debug.log 无新增；版本 1.28.8-beta-36。
- **F18** 「禁止 WordPress 裁剪」尺寸表窄宽可读：React 版 SizeTable 外层补上 .table-scroll（783–1100px 内部横滚），≤782px 改成逐条记录——thead 视觉隐藏、每行 grid 三行（名称+生成状态 / 宽×高+裁剪方式 / 来源），来源列用 data-label + ::before 补出「来源：」前缀。列只加类名与一个 data-label，表单 name、option 键、data-size、aria-label 未动。验证：实验站 1.28.8-beta-37 上 320/390/768/800/850/900/1024/1440 八档 scrollWidth === clientWidth，「来源」列右边界始终在卡片内（320 下 282 ≤ 304），medium_large 等名称在 390/768 下均单行，390 下复选框可切；console 0 error、无 Deprecated/Warning；composer test 897/3816/6、phpstan [OK]、lint:strict 与 l
- **F19** 开关与勾选统一走共享组件类：React 的 Checkbox（assets/ui/src/image/thumb.jsx、backup.jsx）原先用自造类 `field__check`，全仓 CSS 里没有这条规则，只在恰好作为 `.field__control` 直接子元素时才被 `>` 选择器兜住；放进 `.check-grid` 两列网格后完全失去样式——「生效位置」4 个、「高级」8 个开关文字贴住开关，长文案还折到开关下方。改用 `label.check` + `<span class="check__text">` 后，13 个开关 computed gap 与实测间距全部 10px（`--g-check-gap`），两列左缘 835/1121、行顶 1160/1211/1262 对齐；1440 与 390 均 `documentElement.scrollWidth === clientWidth`，console 0 error、无 Deprecated/Warning，开关可点击。规则写进 12 篇 §7 与 03 篇开关条目。版本 1.28.8-beta-38。
- **F20** 缩略图优化页保存「总开关与处理方式」后，右上角状态卡与告警跟着落库值刷新：Thumb_Payload 把 status()/alerts() 提为公开方法，Image_Controller 的 save_thumb_card 响应带回重算结果，React 侧 onServerState 合并进 head.status 与 payload.alerts（1.28.8-beta-39）。
- **F21** 缩略图优化页右上角健康卡改用文字徽章表达状态（1.28.8-beta-40）：status 数组新增 badge {text,tone}，Page::status() 与 React StatusCard 都输出 .status-card__top（label 左、徽章右，底 --g-*-bg / 字 --g-* / 11px 胶囊），有徽章时副行回中性色；三态为已停用 neutral / 已启用 ok / 未生效 warn（判据与 alerts() 一致）。同时补上 Page::status_data() 白名单里的 badge 键——漏了它 boot.head.status 丢字段，徽章根本不出现。验证：demo.77il.cn beta-40 上 1440 与 390 徽章「已启用」(47×20.5，右边缘落在卡片内) 点开关保存后立刻变「已停用」--neutral 再变回，页面不横溢；系统设置/图片优化/对象存储/备份/统计/文件空间六处状态卡无徽章、副行仍按 tone 上色、高度仍 84.5–84.6px；903 tests / 3831 assertions / 6 ski
- **F22** 对象存储模块「S3 存储」页已迁成 React 岛（1.28.8-beta-41）：五张卡（已保存的配置 / 添加存储配置 / 上传行为 / 上传吞吐 / 监控与告警）走 Storage_Payload + Storage REST（GET /storage、POST /storage/cards/{connection|behaviour}、/storage/profile、/storage/profile/delete、/storage/probe、/storage/probe/profile、/storage/doge-buckets），服务端决策抽成 Profile_Actions / Probe_Runner / Save_Refused，旧 admin-post 与 admin-ajax 处理器改为调用同一批服务；实测 1440 排成四行、390 单列不横溢、切桶徽章与 tone 同步、并发数保存与新建/删除配置全 200，console 0 error，debug.log 无新增。
- **F23** 「添加存储配置」卡的「服务商 + 测试连接」「存储空间名称 + 获取 SDK 参数」两行收进一个字段列（1.28.8-beta-42）：根因是 React 把 field__grow 加在外层 span 上（select 被撑到 1075px 铺满整行），且多吉云按钮拿整句提示当忙碌文案（被撑到 499×38）、还沿用 .btn--small（30px，比 38px 输入框矮一截）；改成把类加回控件本身、忙碌文案换短句「获取中…」、同行按钮统一抬到 --g-ctl-h，并在 min-width:1101px 下给 .s3-provider-row.is-empty .field__row 与 .field__row--column 设 max-width: calc((100% - 26px)/2)。验证：demo.77il.cn beta-42 上 1440 / 1912 两行按钮右缘与同列 region 字段右缘对齐（787 / 1023）、间距 10px、控件全 38px；bitiful（带已保存配置 chips）与 dogecloud（is-empty）两种状态一致；390 换
- **F24** 「已保存的配置」卡的「新增账户配置」按钮回到卡头右上角，与标题同排（1.28.8-beta-43）：根因是 React 迁移时把 .card__actions 写进了 card__body——旧 PHP 页用 UI::card( 'actions' => … ) 放在卡头（Card::head() 输出在 <header> 里），而共享 React Card 组件当时没有 actions 槽。给 shared/ui.jsx 的 Card 加 actions 槽（渲染在 header，与 PHP 同一位置），profiles.jsx 改为 actions={<button/>}。验证：demo.77il.cn beta-43 上 1440 按钮右缘 1385（= 卡右内边距 24px）、顶边 441.1 与标题 440.8 齐平（kicker 下沉 20px 规则生效）、body 里已无 .card__actions；390 时 .card__actions 落到标题下方整行右对齐（margin-top 12px、右缘 356 落在卡片 374 内），docW = clientW = 3
- **F25** 「上传行为」卡的锁图标与并发说明（1.28.8-beta-44）：冻结行的 `.check__lock` 原先在 React 侧只有一个空 span，迁岛时丢了旧 PHP 输出的 `UI::lock_icon()` svg；共享件新增 `LockIcon`（同一段 svg path、同 viewBox），`title` 走 payload 的 `strings.lockTitle`，于是冻结行读得出「这里不能改」。并发字段加 `field--full` 让说明吃满卡片内容宽（原先卡半宽 550px 里说明只 322px），加 `field--w-fit` 让下拉按内容收到 168px（原 322px 拉满一行），两个类都加在 `.field` 包裹层；`fallbackNote` 从 `.field-list` 移到字段网格外层。验证：demo.77il.cn beta-44 上 1440 锁 22×22 含 13×13 svg、title 正确、并发字段 550px、说明 550×41、下拉 168×38；390 说明 322 宽右缘 356 落在卡片 374 内、下拉 168×38
- **F26** 岛页面不再加载旧页脚本、清掉无生产者的 .mono 样式（1.28.8-beta-45）：Page_Assets 新增 private const ISLAND_BUNDLES = array('storage-connection' => 'assets/ui/dist/storage.js') 与 island_owns()（bundle 登记且在盘才 true），第 7 行页脚本入队条件加 && ! self::island_owns( $page )；旧脚本找不到 [data-sakura-connection-strings] 时不会提前 return，12 个 init* 照跑，与 React 岛共用 #sakura-s3-test-connection 导致点一次「测试连接」发两条请求（REST /storage/probe + admin-ajax），修后只剩 REST 一条。assets/ui/base.css 删掉 .sakura-admin .mono（全仓无裸 mono 类，剩下的都是 profile-card__meta--mono 这类 BEM 修饰符）。b
- **F27** 图片优化四页也跳过旧页脚本（1.28.8-beta-46）：Page_Assets::ISLAND_BUNDLES 从 1 项扩到 5 项，image-rules / image-thumb / image-backup / image-stats 都映射 assets/ui/dist/image.js，入队条件仍是 is_readable( page_js ) && ! island_owns( page )。与 S3 存储页不同，这三个旧脚本（image-rules.js 14270B、image-backup.js 3732B、image-stats.js 3864B）取不到挂载点都会提前 return，不会重复发请求，只是白下载约 22KB，属省流量而非修 bug。实测 demo.77il.cn beta-46：四个图片页 legacy=[]、只加载 image.js、无骨架、1440 与 390 均 docW=clientW、正文零 Deprecated/Warning；system-settings 与 storage-connection 未误伤；未重构的 storage


## User requirements / 用户要求
<!-- card id=requirements kind=requirements rev=1 updated=2026-11-18T10:59:04.305Z -->

- **Q1** 不自行发版。正式版本号只在用户说「同步到 GitHub」时才写；开发期用 基准-beta-N 且每次动 .php/.js/.css 就 +1。beta 号可以 commit 但不能 push main——release.yml 会给 beta 打 tag 并发 Release，而生产插件目录就是 main 的 checkout，push 等于上线。
- **Q2** 纯文档改动（只改 .md）不动三处版本号、不写 changelog、不跑测试，提交后直接 push main。
- **Q3** 改代码必须跑完三道关：composer test（基线 765 tests / 3251 assertions / 6 skipped）、composer phpstan（level 5，必须 [OK] No errors）、composer lint:strict（必须 0 error / 0 warning），外加 php -l、node --check、git diff --check。
- **Q4** 破坏性操作先备份：生产插件目录打包到 /root/backups/sakura-tools-prod-*.tar.gz 并导出数据库；改完逐项核对，把临时数据（临时配置行、临时管理员会话、人为改过的 post meta）清回原状。
- **Q5** 部署到生产之后必须确认：git status --porcelain 为空、属主 www:www、三处版本号一致、debug.log 无新增条目、前台首页与 ?p=232 文章页返回 200。
- **Q6** 回复用中文、结论先行、给可执行命令；不确定的事实先读代码或实测，不靠猜。


## Completed tasks / 已完成任务
<!-- card id=tasks kind=tasks rev=12 updated=2026-11-24T11:35:13.385Z -->

- **T1** [done] 为 sakura-tools 插件补一篇 PHP 代码规范文档（docs/开发规则/），以 PHP 8.2–8.4 兼容 (11/24)
- **T2** [done] 文档中所有描述实验站的地方（AGENTS.md、05 篇、09 篇、11 篇、03 篇、docs/验收脚本/README — 实验站 demo.77il.cn 的新部署（宝塔本机 /www/wwwroot/demo.77il.cn，PHP 8.4.28 + MariaDB 12.3.2）已写进 AGENTS.md 与 05/09/11/03 篇、验收脚本，并写入记忆；文档不含密码，凭据只在 /root/.dsh/secrets/demo-77il-login.txt；本地提交 f343907，未 push。 (11/24)
- **T3** [done] 修好图片优化页「禁止 WordPress 裁剪」卡片在窄宽下的显示问题：表格各列（禁用 / 尺寸名称 / 宽×高 / 生 (11/24)
- **T4** [done] 「按位置指定尺寸」卡片的四个下拉框按实际渲染判断：若控件与文字间距同样偏离 10px 规则，本轮一并调整；本来就合规则不 — 开关/勾选改用共享 .check 组件，13 个开关间距恢复 10px；1440/390 实测通过，提交 da518fd (11/24)
- **T5** [done] 改完后 1440 与 390 两个宽度实测：状态能在第一眼辨认（启用/停用不用读文字颜色），页面不横溢，其余 16 处状 — 健康卡改用文字徽章（已启用/已停用/未生效）表达状态，1440 与 390 实测通过，其余 16 处状态卡不变；版本 1.28.8-beta-40，提交 a7cc402（未 push） (11/24)
- **T6** [done] 重构对象存储模块的第一个页面「S3 存储」，与已经重构好的「系统设置」「图片优化」两个模块保持同一套视觉与交互（页头状态 (11/24)
- **T7** [done] 五张卡（已保存的配置 / 添加存储配置 / 上传行为 / 上传吞吐 / 监控与告警）与旧页功能等价：切换与停用配置、删除 (11/24)
- **T8** [done] 调整「添加存储配置」卡片：切换到多吉云时，「服务商 + 测试连接」「存储空间名称 + 获取 SDK 参数」这两行的输入框 — 服务商/存储空间两行收进一个字段列，1440、1912、390 实测对齐，提交 c4fe17f（未 push） (11/24)
- **T9** [done] 把「已保存的配置」卡的「新增账户配置」按钮移到卡头右上角，与标题「已保存的配置」同排（水平对齐）。 — 「新增账户配置」回到卡头右上角、与标题同排（卡头 actions 槽），1440/390 实测通过，提交 56ba8d1（未 push） (11/24)
- **T10** [done] 补回「上传行为」卡冻结行里缺失的锁形 SVG 图标，让「已锁定」状态看得懂。 — 上传行为卡补回锁图标、并发说明改整卡全宽并用 w-fit 收窄下拉；1440/390 实测通过，提交 07c415c (11/24)
- **T11** [done] 改动要落在测试站 demo.77il.cn 上，让它能正常采集扩展查询信息（组件名与受影响行数）；不破坏站点现有功能与插 — 实验站 demo.77il.cn 的 Query Monitor db.php drop-in 已就位，面板显示组件与行数，提示消失 (11/24)
- **T12** [done] 确认 includes/class-s3-storage-module.php 里那 12 个旧 admin-post — 13 个旧 S3 处理器逐个查证：仅 retry 有活入口（同步中心 storage-sync.js:552）且承载 R18 闸门，save 被测试断言引用，其余 11 个可安全删除 (11/24)
