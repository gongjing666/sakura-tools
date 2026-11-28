# beta-26 HTTP 权限矩阵（A7 能力拆分 / A8-2 真实角色）

- 站点：https://demo.77il.cn；插件文件版本：**1.30.0-beta-26**（`sakura_imgopt_version` 选项为 `1.30.0`，DB 以正式号比较）
- 生成时间：2026-11-27T16:36:34.878Z
- 传输：curl cookie jar (wordpress_logged_in_* from wp-login.php POST) + X-WP-Nonce from /wp-admin/admin-ajax.php?action=rest-nonce
- REST 入口：`https://demo.77il.cn/?rest_route=/sufm/v1/<path>`；矩阵 `120` 格（10 角色 × 12 端点）
- 判定：`403`=gate 拒绝；`200`=允许；`404/400`=gate 通过但对象/参数被拒；匿名应为 `401`。PATCH 被 demo nginx 在 PHP 前拦截，见 §5。

## 1. 角色与登录

| 角色 | 账号 | 能力 | GET wp-login | POST wp-login | wordpress_logged_in | nonce 长度 |
|---|---|---|---|---|---|---|
| admin | `yexu` | manage_options | 200 | 302 | 1 | 10 |
| review | `a7a8_review` | sufm_admin_review_deletions | 200 | 302 | 1 | 10 |
| delete | `a7a8_delete` | sufm_admin_delete_files | 200 | 302 | 1 | 10 |
| users | `a7a8_users` | sufm_manage_users | 200 | 302 | 1 | 10 |
| audit | `a7a8_audit` | sufm_view_audit | 200 | 302 | 1 | 10 |
| sync | `a7a8_sync` | sufm_manage_sync | 200 | 302 | 1 | 10 |
| review_users | `a7a8_review_users` | sufm_admin_review_deletions, sufm_manage_users | 200 | 302 | 1 | 10 |
| review_audit | `a7a8_review_audit` | sufm_admin_review_deletions, sufm_view_audit | 200 | 302 | 1 | 10 |
| review_sync | `a7a8_review_sync` | sufm_admin_review_deletions, sufm_manage_sync | 200 | 302 | 1 | 10 |
| anonymous | `(none)` | — | — | — | — | — |

## 2. 矩阵（行=角色，列=端点）

| 角色 | queue | items | users | events | sync | grants | batch | preview | quota | reset | syncPOST | reconcile |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| admin | 200 允许 | 404 允许* | 200 允许 | 200 允许 | 200 允许 | 200 允许 | 404 允许* | 400 允许* | 405 nginx 拦截 | 跳过 | 跳过 | 跳过 |
| review | 200 允许 | 404 允许* | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| delete | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 404 允许* | 400 允许* | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| users | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 跳过 | 403 gate 拒绝 | 403 gate 拒绝 |
| audit | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| sync | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 跳过 | 跳过 |
| review_users | 200 允许 | 404 允许* | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 跳过 | 403 gate 拒绝 | 403 gate 拒绝 |
| review_audit | 200 允许 | 404 允许* | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| review_sync | 200 允许 | 404 允许* | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 跳过 | 跳过 |
| anonymous | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 405 nginx 拦截 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 |

图例：`允许`=gate 通过；`允许*`=gate 通过但对象/参数被拒（404/400）；`gate 拒绝`=403；`未登录拒绝`=401；`跳过`=业务写端点，只测非允许角色的 403，允许角色未发请求；`nginx 拦截`=请求在 PHP 之前被 demo web 层拒绝。

## 3. 端点、能力与期望

| key | 方法 | 路径 | beta-26 能力 | beta-27 能力 | 允许时状态 | 说明 |
|---|---|---|---|---|---|---|
| queue | GET | `admin/delete-requests` | review | review | 200 | 审核队列 |
| items | GET | `admin/delete-requests/999999/items` | review | review | 404 | 不存在申请的明细 |
| users | GET | `admin/users` | users | users | 200 | 全站用户表 |
| events | GET | `admin/events` | audit | audit | 200 | 审计事件 |
| syncstatus | GET | `admin/sync` | sync | sync | 200 | 同步状态(只读) |
| grants | GET | `admin/space/grants` | review | users | 200 | 空间授权列表(beta-26 现状 review) |
| batch | GET | `admin/delete-batches/999999` | delete | delete | 404 | 不存在的删除批次 |
| batchpreview | POST | `admin/delete-batches/preview` | delete | delete | 400 | 空 body 预检 |
| quota | PATCH | `admin/users/999999/quota` | users | users | 404 | 改不存在的用户配额(空 body) |
| resetquotas | POST | `admin/reset-quotas` | users | users | skip | 会真重置，只测 403 |
| syncstart | POST | `admin/sync` | sync | sync | skip | 会启动同步，只测 403 |
| reconcile | POST | `admin/reconcile` | sync | sync | skip | 会真核对账本，只测 403 |

## 4. 汇总

- `allowed-ok`：28
- `gate-denied`：62
- `unauth-ok`：11
- `skipped`：9
- `blocked-before-gate`：10
- 合计：120 格

## 5. 未到达 gate 的环境限制：PATCH

`PATCH /sufm/v1/admin/users/{id}/quota` 在 demo 上被 web 层在 PHP 之前拒绝：`/?rest_route=...` 返回 nginx `405 Not Allowed`，`/index.php?rest_route=...` 与 `/wp-json/...` 返回 nginx `404`；`--noproxy '*'` 结果相同，站点 nginx 配置里没有 `limit_except`/`request_method` 规则，属于站点外层（WAF/面板）行为，不在本任务允许的改动范围。因此 10 个角色的 PATCH 格均为 `405 nginx 拦截`，不能作为 gate 证据。

补充（非 HTTP，明确标注）：`wp eval-file` + `rest_do_request('PATCH', ...)`，当前用户逐个切换；路由与权限回调真实执行，结果与能力分组一致：

| 角色 | user_id | 状态 | code |
|---|---|---|---|
| admin | 1 | 404 | sufm_user_missing |
| review | 6 | 403 | rest_forbidden |
| delete | 7 | 403 | rest_forbidden |
| users | 8 | 404 | sufm_user_missing |
| audit | 9 | 403 | rest_forbidden |
| sync | 10 | 403 | rest_forbidden |
| review_users | 11 | 404 | sufm_user_missing |
| review_audit | 12 | 403 | rest_forbidden |
| review_sync | 13 | 403 | rest_forbidden |
| target | 14 | 403 | rest_forbidden |

来源：`aux-patch-dispatch.json`、`patch-env-probe.txt`。

## 6. 失败格与响应体（前 300 字）

无 `UNEXPECTED` 格。失败格定义：状态码不在该角色/该端点的预期集合内；本矩阵 0 格。

PATCH 被 nginx 拦截的 10 格共用同一响应体（首个 300 字）：

```
<html> <head><title>405 Not Allowed</title></head> <body> <center><h1>405 Not Allowed</h1></center> <hr><center>nginx</center> </body> </html>
```

## 7. beta-27 预期差异

| 角色 | 端点 | beta-26 期望 | beta-27 期望 | beta-26 实测 |
|---|---|---|---|---|
| review | grants | allowed | denied | 200 allowed-ok |
| users | grants | denied | allowed | 403 gate-denied |
| review_audit | grants | allowed | denied | 200 allowed-ok |
| review_sync | grants | allowed | denied | 200 allowed-ok |

## 8. 结论

- 真实登录 cookie + `X-WP-Nonce` 下，`MANAGE_USERS` / `VIEW_AUDIT` / `MANAGE_SYNC` 三组端点各自只对持有对应能力的角色放行：`GET /admin/users` 仅 admin/users/review_users；`GET /admin/events` 仅 admin/audit/review_audit；`GET /admin/sync` 仅 admin/sync/review_sync。
- 仅 `DELETE_FILES` 的 `a7a8_delete` 对 users/events/sync/reconcile/reset-quotas 全部 403；四个删除批次端点仍放行（batch 404、preview 400 说明 gate 通过）。
- `GET /admin/space/grants` 在 beta-26 仍只要求 REVIEW（review 组合 200，仅 users 403）；beta-27 改为 users 后，仅 `a7a8_users` 应从 403 变为 200，见 §7。
- 匿名请求除被 nginx 拦截的 PATCH 外全部 401；无 500/异常。
- 残余：PATCH 无 HTTP 层证据（环境限制）；`reset-quotas`/`sync` POST/`reconcile` 的允许角色按禁写约束未发请求。

## 9. 证据文件

- `matrix-beta26.json`：逐格状态、判定、错误码、响应体摘要（完整 300 字摘录）。
- `aux-patch-dispatch.json`：PATCH 的本地 REST 分发补充证据。
- `patch-env-probe.txt`：PATCH 传输层探测记录。
- `../preflight-beta26.txt`：基线（版本、队列 0、用户、option、debug.log、bundle）。
