# 清理记录：beta-27 A7/A8-2 验证（demo.77il.cn）

- 时间：2026-11-28 00:25 ~ 01:00（Asia/Shanghai）
- 站点：https://demo.77il.cn；插件目录 `/www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools`；WP-CLI 参数 `--allow-root --path=/www/wwwroot/demo.77il.cn`。
- 原则：只保留 `docs/plans/2026-11-28-beta27-a7-a8/evidence/`；临时用户、合成队列、主题开关、QM、/tmp 全部恢复/删除。

## 1. 清理前 → 清理后

| 项目 | 清理前 | 清理后 | 证据 |
|---|---|---|---|
| `wp_sufm_delete_requests` | 3（#216/#217/#218，marker `a7a8-20261128`） | **0** | `cleanup-raw.log` |
| `wp_sufm_delete_request_items` | 6（#407–#412） | **0** | 同上 |
| 按 marker 剩余 requests | 3 | **0** | 同上 |
| 孤儿 items | 0 | **0** | 同上 |
| WordPress 用户 | `yexu` + 9 个 `a7a8_*`（ID 6–14） | **仅 `yexu`（ID 1, administrator）** | 同上 |
| 临时用户 usermeta | 9 个用户 | **0 行**（`user_id IN (6..14)`） | 同上 |
| `zibll_options.user_disable_admin` | `0`（测试期临时值） | **`'1'`（原值，字符串）** | 同上 |
| `query-monitor` | `active` | **`inactive`**；`wp plugin is-active` 退出码 **1** | 同上 |
| `debug.log` 行数 | 165 | **165** | `preflight-beta27.txt` / `cleanup-raw.log` |
| `debug.log` md5 | `417da014f3ab49979b56a3cfb825afe7` | **同值** | 同上 |
| `wp_sufm_events` | 42 | **42**（本会话窗口内 0 条新增） | `cleanup-raw.log` |
| `wp_sufm_sanctions` | 0 | **0** | 同上 |
| 最终队列 REST | — | `{"total":0,"counts":{...0}}` | `http/final-queue-check.json` |

## 2. 关键命令（按执行顺序）

```bash
WP='/usr/local/bin/wp --allow-root --path=/www/wwwroot/demo.77il.cn'

# 1) QM 停用并核对退出码
$WP plugin deactivate query-monitor
$WP plugin get query-monitor --field=status        # inactive
$WP plugin is-active query-monitor; echo $?        # 1

# 2) 按唯一 marker 精确删除 items/requests
$WP db query "DELETE i FROM wp_sufm_delete_request_items i INNER JOIN wp_sufm_delete_requests r ON i.request_id=r.id WHERE r.idempotency_key LIKE '%a7a8-20261128%' OR r.reason LIKE '%a7a8-20261128%';"
$WP db query "DELETE FROM wp_sufm_delete_requests WHERE idempotency_key LIKE '%a7a8-20261128%' OR reason LIKE '%a7a8-20261128%';"

# 3) 删除全部 a7a8_ 临时用户（ID 6–14）
ids=$($WP user list --search='a7a8_*' --field=ID | tr '\n' ' ')
$WP user delete $ids --yes

# 4) 恢复子比主题后台开关（原值来自 /tmp/a7a8/original-option.json：字符串 '1'）
ORIG='1' $WP eval '$o=get_option("zibll_options"); $o["user_disable_admin"]=getenv("ORIG"); update_option("zibll_options",$o); var_export(get_option("zibll_options")["user_disable_admin"]);'
```

## 3. 清理细节

- 合成行只用于非空队列、抽屉、QM 与处罚弹窗：requests `216/217/218`，items `407–412`；owner 为 `a7a8_target`（14）和 `a7a8_review`（6），reason 前缀与 idempotency_key 均含 `a7a8-20261128`，删除后 marker 残留 0、孤儿 items 0、requests/items 均 0。
- 临时用户：`a7a8_review`、`a7a8_delete`、`a7a8_users`、`a7a8_audit`、`a7a8_sync`、`a7a8_review_users`、`a7a8_review_audit`、`a7a8_review_sync`、`a7a8_target`；随机密码只存在 `/tmp/a7a8/users.json`（600）与本地登录表单，已随 `/tmp/a7a8` 删除，未进入证据/聊天/仓库。
- 主题开关：测试前 `zibll_options.user_disable_admin` 原值为 **字符串 `'1'`**（`/tmp/a7a8/original-option.json` 记录）；为让非超管登录后台临时改为 `0`，清理时按原类型恢复为 `'1'`，最终 `var_export` 为 `'1'`。
- QM：测试期 `active`，在管理员非空队列页采集 toolbar + `window.QueryMonitorData.data`；测后 `inactive`，退出码 1（已核对）。
- `/tmp`：本地登录表单与 HTTP 服务（127.0.0.1:18099）、cookie jar、脚本、`users.json` 全部删除；后台服务 job 已停止。
- `debug.log`：清理前后均 165 行、md5 相同；`PHP Fatal/Warning/Parse` 计数仍为 40（历史行，无新增）。
- `wp_sufm_events`：清理前后均 42；按 `created_at >= 2026-11-27 16:20:00` 查询本会话窗口为 0 行，说明本轮只读测试没有产生审计事件。

## 4. 仓库改动范围

- 本轮只新增 `docs/plans/2026-11-28-beta27-a7-a8/evidence/**`（报告、JSON、截图、日志），没有改 `src/`、`tests/`、`sakura-tools.php`、`readme.txt`，没有提交/推送。
- 工作区里 `src/`、`tests/`、版本号的未提交改动是 beta-27 开发与本轮之外的产物，不是本验证子代理产生的；本子代理未对它们执行任何写入。
- 未触碰生产站；未部署；未改 demo 业务数据（除上面已清理的合成行/临时用户/主题开关/QM）。

## 5. 残余

- 无遗留的临时用户、合成行、QM 或主题开关。
- 仅保留证据目录内容；`evidence/code/*`、`evidence/http/independent-page-probe.txt` 是同一任务其他子代理的产出，本轮未修改。