# beta-27 HTTP 权限矩阵（A7 能力拆分 / A8-2 真实角色，部署后复跑）

- 站点：https://demo.77il.cn；插件文件版本：**1.30.0-beta-27**；bundle SHA-256 `3b946d61fbfaa267c2754aca89604af73e8bed0ae8c014c7b4982f198d17fc1e`（489780 B）
- 生成时间：2026-11-27T16:41:47.641Z；REST 入口 `https://demo.77il.cn/?rest_route=/sufm/v1/<path>`
- 传输：curl cookie jar (wordpress_logged_in_* from wp-login.php POST) + X-WP-Nonce from /wp-admin/admin-ajax.php?action=rest-nonce
- 矩阵 `120` 格（10 角色 × 12 端点）；每格判定与 beta-26 基线逐格对比，见 §4。

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
| review | 200 允许 | 404 允许* | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| delete | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 404 允许* | 400 允许* | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| users | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 跳过 | 403 gate 拒绝 | 403 gate 拒绝 |
| audit | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| sync | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 跳过 | 跳过 |
| review_users | 200 允许 | 404 允许* | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 跳过 | 403 gate 拒绝 | 403 gate 拒绝 |
| review_audit | 200 允许 | 404 允许* | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 |
| review_sync | 200 允许 | 404 允许* | 403 gate 拒绝 | 403 gate 拒绝 | 200 允许 | 403 gate 拒绝 | 403 gate 拒绝 | 403 gate 拒绝 | 405 nginx 拦截 | 403 gate 拒绝 | 跳过 | 跳过 |
| anonymous | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 | 405 nginx 拦截 | 401 未登录拒绝 | 401 未登录拒绝 | 401 未登录拒绝 |

图例：`允许`=gate 通过；`允许*`=gate 通过但对象/参数被拒；`gate 拒绝`=403；`未登录拒绝`=401；`跳过`=业务写端点，允许角色未发请求；`nginx 拦截`=PATCH 在 PHP 之前被 web 层拒绝。

## 3. grants 关键断言（beta-27 改为 MANAGE_USERS）

| 角色 | 能力 | beta-26 状态 | beta-27 状态 | beta-27 判定 | 预期 | 通过 |
|---|---|---|---|---|---|---|
| admin | manage_options | 200 | 200 | allowed-ok | 200 | 是 |
| review | sufm_admin_review_deletions | 200 | 403 | gate-denied | 403 | 是 |
| delete | sufm_admin_delete_files | 403 | 403 | gate-denied | 403 | 是 |
| users | sufm_manage_users | 403 | 200 | allowed-ok | 200 | 是 |
| audit | sufm_view_audit | 403 | 403 | gate-denied | 403 | 是 |
| sync | sufm_manage_sync | 403 | 403 | gate-denied | 403 | 是 |
| review_users | sufm_admin_review_deletions, sufm_manage_users | 200 | 200 | allowed-ok | 200 | 是 |
| review_audit | sufm_admin_review_deletions, sufm_view_audit | 200 | 403 | gate-denied | 403 | 是 |
| review_sync | sufm_admin_review_deletions, sufm_manage_sync | 200 | 403 | gate-denied | 403 | 是 |
| anonymous | — | 401 | 401 | unauth-ok | 401 | 是 |

## 4. 与 beta-26 基线逐格对比

| 角色 | 端点 | beta-26 | beta-27 |
|---|---|---|---|
| review | grants | 200 allowed-ok | 403 gate-denied |
| users | grants | 403 gate-denied | 200 allowed-ok |
| review_audit | grants | 200 allowed-ok | 403 gate-denied |
| review_sync | grants | 200 allowed-ok | 403 gate-denied |

共 4 格变化，全部是 `grants` 的能力迁移（REVIEW → MANAGE_USERS）；其余 116 格状态与判定不变。

## 5. 汇总与断言

- `allowed-ok`：26
- `gate-denied`：64
- `unauth-ok`：11
- `skipped`：9
- `blocked-before-gate`：10
- 合计：120 格；断言失败（unexpected）：0

## 6. 未到达 gate 的环境限制：PATCH

`PATCH /sufm/v1/admin/users/{id}/quota` 在 demo 上被 web 层在 PHP 之前拒绝：`/?rest_route=...` 返回 nginx `405 Not Allowed`，`/index.php?rest_route=...` 与 `/wp-json/...` 返回 nginx `404`；`--noproxy '*'` 结果相同。站点 nginx 配置无 `limit_except`/`request_method` 规则，属站点外层行为，不在允许改动范围。10 个角色的 PATCH 格均为 `405 nginx 拦截`。

补充（非 HTTP，明确标注）：`wp eval-file` + `rest_do_request(PATCH, ...)`，逐个切换当前用户；结果与能力分组一致：

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

## 7. 失败格

无。全部非 PATCH 格的 HTTP 状态与 beta-27 预期一致；PATCH 格因环境限制单独标注。

## 8. 结论

- grants：`a7a8_review` / `a7a8_review_audit` / `a7a8_review_sync` 从 200 变为 403；`a7a8_users` 从 403 变为 200；`a7a8_review_users` 保持 200；admin 200；delete/audit/sync 仍 403；匿名 401。
- 其余端点与 beta-26 逐格一致：MANAGE_USERS 只开 users/quota 组，VIEW_AUDIT 只开 events，MANAGE_SYNC 只开 sync/reconcile 组，DELETE_FILES 只开四个删除批次端点，REVIEW 只开 delete-requests 组。
- 无 500、无异常状态；唯一未覆盖的 HTTP 层是 PATCH（环境拦截），已用本地 REST 分发补充。

## 9. 证据文件

- `matrix-beta27.json`：逐格状态、判定、期望、断言通过标记与响应体摘要。
- `matrix-beta26.json` / `.md`：部署前基线（保留）。
- `aux-patch-dispatch-beta27.json` / `aux-patch-dispatch.json`：PATCH 本地分发。
- `patch-env-probe.txt`：PATCH 传输探测。
- `../preflight-beta27.txt`：部署后版本、bundle、用户、option、debug.log。
