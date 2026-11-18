-- =======================================================================
-- 对象存储模块 · 历史残留清理脚本
-- 依据：2026-11-17 生产库审计（10 张 sio_* 表逐个核对）
-- 用法：分段执行，不要整文件一次性 source。每段执行前先跑该段的「预检」。
-- 表前缀：nb_   （脚本里已写死；换站点请全局替换）
-- =======================================================================


-- -----------------------------------------------------------------------
-- 第 0 段：备份（必做，且必须在这之前做）
-- 下面几条是命令行，不是在 MySQL 里跑：
--
--   cd /www/wwwroot/www.nboke.com
--   mysqldump -u nboke -p --no-data --skip-lock-tables nboke nb_sio_s3_uploads \
--       > /root/backup-sio-schema-$(date +%Y%m%d-%H%M%S).sql
--   （-p 后面不要挨着写密码，会当场提示你输入；MariaDB 会警告
--    "Deprecated program name... use mariadb-dump"，可忽略，导出正常）
--
--   wp option get sakura_imgopt_s3_queue_schema \
--       --url=https://www.nboke.com --allow-root \
--       > /root/backup-sio-queue-schema.txt
--
-- 该表 0 行，备份结构即可。想更稳就整库导出：
--   wp db export /root/backup-nboke-$(date +%Y%m%d-%H%M%S).sql \
--       --url=https://www.nboke.com --allow-root
-- -----------------------------------------------------------------------


-- #######################################################################
-- 第 1 段：删除废弃表 nb_sio_s3_uploads              【安全 · 建议执行】
-- #######################################################################
--
-- 依据：异步上传队列在插件 1.12.0 被完整移除（Upload_Worker、每分钟 cron、
--       看门狗、async_upload 过滤器全部删除）。当前代码里唯一提到这张表的
--       地方是 src/Uninstaller.php:118 的删表清单 —— 除了卸载，没有任何
--       读写方。
--
-- 实测：0 行、AUTO_INCREMENT=38、无触发器、无外键、无视图依赖。

-- 预检 1：必须是 0
SELECT COUNT(*) AS should_be_zero FROM nb_sio_s3_uploads;

-- 预检 2：必须返回空
SELECT TABLE_NAME FROM information_schema.VIEWS
 WHERE TABLE_SCHEMA = DATABASE()
   AND VIEW_DEFINITION LIKE '%sio_s3_uploads%';

-- 执行：
DROP TABLE IF EXISTS nb_sio_s3_uploads;

-- 验证：应返回空
SHOW TABLES LIKE 'nb_sio_s3_uploads';


-- #######################################################################
-- 第 2 段：删除孤儿 option sakura_imgopt_s3_queue_schema   【安全 · 建议执行】
-- #######################################################################
--
-- 依据：这是 1.12.0 旧上传队列的 schema 标记（值 1.1.0）。全仓 grep 只有
--       两处命中：src/Uninstaller.php:131（删它）和 docs/history（记录）。
--       没有任何代码读它来决定行为，也没有任何写入方。
--
-- ⚠️ 重要：不要在 MySQL 里 DELETE，要用 wp option delete。
--    【已在本生产站实测确认，不是推测】本站装了 Redis 对象缓存
--    （Object Cache Pro 1.25.6，wp-config.php 的 WP_REDIS_CONFIG：
--    127.0.0.1:6379 / database=1 / split_alloptions => true）。
--    实测过程：wp option update 写入 → 直连 SQL DELETE 成功、库里确已为空
--    → wp option get 仍返回旧值（缓存残影）。
--    所以只删 DB 不动缓存 = 插件读到的还是旧值，等于没删。
--
--    若你已经手滑用 SQL 删了，用这条补清缓存（按 option 名 + options 组）：
--      wp cache delete sakura_imgopt_s3_queue_schema options \
--          --url=https://www.nboke.com --allow-root
--    注意 wp cache delete alloptions 在这里无效（split_alloptions 下每个
--    option 是独立缓存条目，会报 "The object was not deleted"）。
--
--    ✔ 反过来也实测过：wp option delete 会**同步清掉缓存**（删完
--    wp cache get <name> options 返回 not found），所以按下面的命令走
--    不需要额外清缓存。

-- 预检：记下当前值（应为 1.1.0）
SELECT option_id, option_name, option_value, autoload
  FROM nb_options
 WHERE option_name = 'sakura_imgopt_s3_queue_schema';

-- 执行（命令行）：
--   cd /www/wwwroot/www.nboke.com
--   wp option delete sakura_imgopt_s3_queue_schema \
--       --url=https://www.nboke.com --allow-root
--
-- 验证（命令行，应输出 "Could not get 'sakura_imgopt_s3_queue_schema'."）：
--   wp option get sakura_imgopt_s3_queue_schema \
--       --url=https://www.nboke.com --allow-root


-- #######################################################################
-- 第 3 段：清理 bench_* 压力测试事件          【可选 · 不删也会自动消失】
-- #######################################################################
--
-- 背景：2026-10-25 做过三轮压力测试，留下 6 行事件。这 3 个 profile
--       （bench_local_100k / bench_remote_1m / bench_minio_1m）已不在
--       s3_options.profiles 里，但事件行还在。
--
-- ⚠️ 其实不需要手动删：src/Sync/Index_Repository.php:2019 有 30 天保留策略
--    （DELETE FROM ... WHERE created_at < now-30d），由 cron
--    sakura_imgopt_sync_cleanup 每天跑。这 6 行是 2026-10-25 的，
--    到期后会自动消失。下面的 DELETE 只是「现在就想清干净」时用。
--
-- 预检：确认 6 行、且这些 profile 确实已不存在于配置里
SELECT MIN(id) AS min_id, MAX(id) AS max_id, COUNT(*) AS n
  FROM nb_sio_sync_events
 WHERE profile_id LIKE 'bench%';

-- 执行（删前请再确认一次上面的结果）：
DELETE FROM nb_sio_sync_events
 WHERE profile_id IN ('bench_local_100k', 'bench_remote_1m', 'bench_minio_1m');

-- 验证：应返回 0
SELECT COUNT(*) AS should_be_zero FROM nb_sio_sync_events WHERE profile_id LIKE 'bench%';


-- #######################################################################
-- 第 4 段：不要动的东西 —— 明确列出来，避免误删
-- #######################################################################
--
-- 【不要删】sakura_imgopt_s3_upload_metrics（1465 字节的旧指标 option）
--   它看起来像废弃数据，其实是**有意保留的冻结基线**。
--   src/S3/Upload_Metrics.php:23-25 的类注释写明：
--     "The legacy option is read as a frozen baseline. It is no longer
--      written: sites that upgrade keep their historical totals."
--   时间轴验证：option 里是 2026-10-28 ~ 11-07 的 8 天数据（ok=91），
--   明细表是 2026-11-14 起的（ok=143），两者**不重叠**。
--   snapshot() 显示 ok=234 是正确的累计值（迁移前 + 迁移后），
--   不是重复计算。删了会让页面累计数凭空少 91 次上传。
--
-- 【不要删】nb_sio_s3_deletes（0 行、AUTO_INCREMENT=104）
--   这是**在用**的删除队列，任务完成后正常出队，所以是 0 行。
--   不是废弃表。
--
-- 【不要删】nb_sio_sync_jobs（0 行）、nb_sio_sync_operations（0 行）
--   队列表，空闲时就是 0 行。sync_operations 刻意不做 TTL。
--
-- 【不要删】nb_sio_url_rewrites 里的 159 条 state=0 记录
--   这是待回滚的链接改写账本，不是垃圾数据。处理方式见第 5 段。
--

-- #######################################################################
-- 第 5 段：159 条待回滚账本 —— 这里只给查询，删改需要你先做决定
-- #######################################################################
--
-- 现状（2026-11-17 实测）：
--   state=0（待回滚）159 行；state=1（已回滚）3 行。
--   其中 130 行改的是 nb_posts.guid —— guid 按 WP 规范不应改写。
--   157 行可以干净回滚（live 值仍等于 new_value）；
--   2 行（nb_options 222 zibll_options / 223 zibll_options_backup）
--   已被后续改动覆盖，回滚会判 conflict。
--
-- 我的建议：**先别删**。要么在「链接改写」页回滚，要么明确接受
-- doge 域名后再说。删掉账本 = 永远失去回滚能力。

-- 看清都有哪些改动：
SELECT table_name, column_name, COUNT(*) AS n, state
  FROM nb_sio_url_rewrites
 GROUP BY table_name, column_name, state
 ORDER BY n DESC;

-- 哪些能回滚成功（live 仍等于 new_value）：
SELECT
  (SELECT COUNT(*) FROM nb_sio_url_rewrites r JOIN nb_posts p ON p.ID = r.pk_value
    WHERE r.state=0 AND r.table_name='nb_posts' AND p.guid = r.new_value) AS guid_ok,
  (SELECT COUNT(*) FROM nb_sio_url_rewrites r JOIN nb_posts p ON p.ID = r.pk_value
    WHERE r.state=0 AND r.table_name='nb_posts' AND r.column_name='post_content'
      AND p.post_content = r.new_value) AS content_ok,
  (SELECT COUNT(*) FROM nb_sio_url_rewrites r JOIN nb_postmeta p ON p.meta_id = r.pk_value
    WHERE r.state=0 AND r.table_name='nb_postmeta' AND p.meta_value = r.new_value) AS postmeta_ok,
  (SELECT COUNT(*) FROM nb_sio_url_rewrites r JOIN nb_termmeta p ON p.meta_id = r.pk_value
    WHERE r.state=0 AND r.table_name='nb_termmeta' AND p.meta_value = r.new_value) AS termmeta_ok,
  (SELECT COUNT(*) FROM nb_sio_url_rewrites r JOIN nb_links p ON p.link_id = r.pk_value
    WHERE r.state=0 AND r.table_name='nb_links' AND p.link_image = r.new_value) AS links_ok;

-- 预期：guid_ok=130, content_ok=17, postmeta_ok=3, termmeta_ok=6, links_ok=1
--       合计 157 可回滚，另有 2 行 nb_options 会 conflict。
