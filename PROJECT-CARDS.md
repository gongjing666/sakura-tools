<!-- deep-ptc:project-cards v1 next=R1,F18,Q7,T3 -->
# Project cards

> Maintained by Deep PTC in DeepSeek Harness: edit in a Deep PTC conversation, view 特点卡片 / Project cards.
> User-approved settings guide this project. Features and requirements are records that can be checked and corrected.

## Implemented features / 已实现功能
<!-- card id=features kind=features rev=4 updated=2026-11-24T05:40:48.772Z -->

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


## User requirements / 用户要求
<!-- card id=requirements kind=requirements rev=1 updated=2026-11-18T10:59:04.305Z -->

- **Q1** 不自行发版。正式版本号只在用户说「同步到 GitHub」时才写；开发期用 基准-beta-N 且每次动 .php/.js/.css 就 +1。beta 号可以 commit 但不能 push main——release.yml 会给 beta 打 tag 并发 Release，而生产插件目录就是 main 的 checkout，push 等于上线。
- **Q2** 纯文档改动（只改 .md）不动三处版本号、不写 changelog、不跑测试，提交后直接 push main。
- **Q3** 改代码必须跑完三道关：composer test（基线 765 tests / 3251 assertions / 6 skipped）、composer phpstan（level 5，必须 [OK] No errors）、composer lint:strict（必须 0 error / 0 warning），外加 php -l、node --check、git diff --check。
- **Q4** 破坏性操作先备份：生产插件目录打包到 /root/backups/sakura-tools-prod-*.tar.gz 并导出数据库；改完逐项核对，把临时数据（临时配置行、临时管理员会话、人为改过的 post meta）清回原状。
- **Q5** 部署到生产之后必须确认：git status --porcelain 为空、属主 www:www、三处版本号一致、debug.log 无新增条目、前台首页与 ?p=232 文章页返回 200。
- **Q6** 回复用中文、结论先行、给可执行命令；不确定的事实先读代码或实测，不靠猜。


## Completed tasks / 已完成任务
<!-- card id=tasks kind=tasks rev=2 updated=2026-11-24T05:06:59.476Z -->

- **T1** [done] 为 sakura-tools 插件补一篇 PHP 代码规范文档（docs/开发规则/），以 PHP 8.2–8.4 兼容 (11/24)
- **T2** [done] 文档中所有描述实验站的地方（AGENTS.md、05 篇、09 篇、11 篇、03 篇、docs/验收脚本/README — 实验站 demo.77il.cn 的新部署（宝塔本机 /www/wwwroot/demo.77il.cn，PHP 8.4.28 + MariaDB 12.3.2）已写进 AGENTS.md 与 05/09/11/03 篇、验收脚本，并写入记忆；文档不含密码，凭据只在 /root/.dsh/secrets/demo-77il-login.txt；本地提交 f343907，未 push。 (11/24)
