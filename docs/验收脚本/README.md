# 验收脚本

给「必须跑进真 WordPress 才看得出来」的断言用的。单元测试（`composer test`）跑在手写 shim 上，`$wpdb` 是替身，所以凡是依赖真实 SQL、真实钩子顺序、真实 session 的东西都得在这里验。

## 怎么跑

```bash
wp eval-file /path/to/this/dir/p1-lifecycle.php --path=/path/to/wordpress --allow-root
```

站点根目录取自环境变量 `SUFM_SITE`，缺省是实验站 `/root/sio-e2e/site`。

**`wp` 会拒绝它不认识的参数**（`unknown --apply parameter`），所以脚本的开关一律走环境变量，不要写成 `--flag`。

要一个干净的站点：

```bash
cd /root/sio-e2e/site && php -S 127.0.0.1:18080
```

## 实验站 vs 生产站

| | 实验站（`p1-rest` / `p1-lifecycle`） | 生产站（`p1-prod`） |
|---|---|---|
| 前提 | 无，自己建数据自己删 | 必须先手工建两个一次性订阅者 |
| 作用域 | 账号 37 / 38（实验站的固定探针） | 按登录名查，不认 id |
| 收尾 | 脚本内 `$wipe()` + 删附件 | 跑 `p1-prod-cleanup.php` |

**`p1-lifecycle.php` 里写死了实验站的探针 owner id（37/38）**，直接在生产上跑会指向错误对象。生产验收用 `p1-prod.php`，它按登录名解析。

## 生产验收的完整流程

```bash
SITE=/www/wwwroot/www.nboke.com
WP="wp --path=$SITE --allow-root"

# 1. 建两个一次性订阅者（p1-prod.php 发现它们不在就直接 abort）
$WP user create sufm_test_sanction   sufm_test_sanction@example.invalid \
  --role=subscriber --user_pass="$(openssl rand -base64 24)"
$WP user create sufm_test_sanction_b sufm_test_sanction_b@example.invalid \
  --role=subscriber --user_pass="$(openssl rand -base64 24)"

# 2. 跑验收（50 项）
SUFM_SITE=$SITE $WP eval-file p1-prod.php | grep -E "FAIL|===="

# 3. 先空跑看计划，再真删
SUFM_SITE=$SITE $WP eval-file p1-prod-cleanup.php
SUFM_SITE=$SITE SUFM_APPLY=1 $WP eval-file p1-prod-cleanup.php
```

`p1-prod-cleanup.php` 有两道拒绝规则，任何一条不满足就 abort 而不是猜：
账号必须叫 `sufm_test_sanction` / `sufm_test_sanction_b`；必须是纯订阅者且没有自己的文章与评论。
它的删除顺序是「token → 台账 → 用量/通知/事件/处罚/站内信 → 账号」，所以中途失败后重跑会收敛。

## 约定

每个脚本自带 `chk( $label, $ok, $detail )` 计数，最后打印 `==== N passed, M failed ====` 并以失败数退出。看一行就够了：

```bash
wp eval-file p1-lifecycle.php --allow-root | grep -E "FAIL|===="
```

**四个坑，写新脚本前先读**（都是这几轮真踩过的）：

1. **`wp eval-file` 在方法作用域里 `eval()`。** 文件顶层变量不是全局的，辅助函数里 `global $foo` 拿到的是空值。要么全部内联在一层作用域，要么只用 `$GLOBALS['x']`。
2. **`check()` 里的 `global $pass, $fail` 同样失效。** 计数要写 `$GLOBALS['p']`，否则永远打印 0/0。
3. **`wipe()` 必须把审计表一起清掉。** 只清业务表时，上一轮的 `events` 还在，下一轮断言「恰好 1 条」就会读到 2 条。`p1-lifecycle.php` 的 `$wipe` 就是踩过这个才补上 `DELETE FROM events` 的。
4. **`$argv` 在这一层不可用。** `wp` 已经把 CLI 参数吃掉了，脚本里读 `$argv[1]` 只会拿到 `null` 并可能触发 `Undefined variable` 警告——读环境变量。

## 一个和本功能无关、但清理时会看到的现象

`nb_sufm_file_tokens` 里有 190 条 `lifecycle='purged'` 的 token 残留。原因是 `Files::backfill_tokens()`（`src/UserFiles/Repositories/Files.php:364-390`）的回填查询不带 `lifecycle` 过滤，历史 purged 行也被写了 token；而正常删除路径（`Files.php:709`、`:774`）是会清的。属于既有行为，不是 P1 引入，清理生产探针数据时按 file_id 精确删即可。

## 已有的

| 脚本 | 覆盖 | 用例数 |
|---|---|---|
| `p1-rest.php` | 处罚的三个 REST endpoint（表单数据 / 创建 / 撤销）、永久确认门（缺 `confirm` → 400）、`admin_users` 行的 `sanction` 与 `can_sanction`、无能力者与自我处罚被拒 | 39 |
| `p1-lifecycle.php` | 懒到期（读时翻状态、立刻解除拦截）、`sufm_tick` 收尾（审计 + 到期通知 + 水位 + 幂等）、`Schema::VERSION`、卸载清单、`warn` 不写 `ends_at`、撤销后立刻放行 | 23 |
| `p1-prod.php` | 生产专用版：上面两组的关键项 + `Uploader::handle()` 真实返回 + 下架批量端到端（含 `operation_id` 串联与按 owner 聚合）+ 不能罚管理员 | 50 |
| `p1-prod-cleanup.php` | `p1-prod.php` 的收尾：删两个探针账号与一切键到它们的行，默认空跑 | — |

前三份都对应 `docs/开发规则/11-违规处置与用户处罚.md` 的 §11 验收清单；P1 的其余条目是浏览器点出来的（下架弹窗、用户页签、权限矩阵第 7 列、1440/390），没有脚本化。
