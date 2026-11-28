# beta-27 浏览器 / QM 证据（A7 能力拆分 / A8-2）

- 站点：https://demo.77il.cn；插件文件版本 **1.30.0-beta-27**；bundle SHA-256 `3b946d61fbfaa267c2754aca89604af73e8bed0ae8c014c7b4982f198d17fc1e`（489,780 B）。
- 说明：beta-26 在部署前没有采浏览器证据。按 lead 2026-11-28 指令，浏览器与 QM 全部按 **beta-27** 断言；beta-26 的真实 HTTP 矩阵保留在 `../http/matrix-beta26.*` 作为基线。
- 登录方式：把临时账号密码写入仅 root 可读的本地 HTML 表单（`127.0.0.1:18099`，自动 POST 到 `wp-login.php`），用 Playwright 打开表单完成真实登录；密码没有出现在任何工具参数、console、快照或本目录文件中。临时表单/服务已在清理阶段删除。
- 所有页面均在 `?page=sakura-tools-files` 下；版本断言取 `.page-head__eyebrow`（CSS 会把文本转大写，DOM 文本为 `USER FILE SPACE / 1.30.0-BETA-27`，源码值 `1.30.0-beta-27`）。
- 截图目录：`browser/`；机器可读的 QM 面板数据：`browser/qm-beta27.json`。

## 1. 管理员 queue：1440 与 390

URL：`https://demo.77il.cn/wp-admin/admin.php?page=sakura-tools-files&t=queue`；登录账号 `yexu`。页面含 3 条合成 pending_review 行（#216/#217/#218）。

### 1.1 1440×900

| 断言 | 值 |
|---|---|
| 文档状态 | 200 |
| title | `审核与处置 ‹ DEMO — WordPress` |
| eyebrow | `USER FILE SPACE / 1.30.0-BETA-27`（含 beta-27） |
| 卡片（data-sakura-card） | `queue` 删除申请队列、`execute` 待执行删除、`users` 用户容量、`capability-matrix` 权限设置 |
| 队列行 | `#218 A7A8 Target 2 项 15.5 MB 待审核`、`#217 A7A8 Review 1 项 4.00 MB 待审核`、`#216 A7A8 Target 3 项 4.00 MB 待审核` |
| 权限矩阵 | 默认折叠；点 `权限设置（低频）` 后 `details.open=true`；表头 `role + 管/申/审/删/户/志/同/媒/移/罚` = 10 个能力列（th=11）；5 个角色行 |
| 矩阵宽度（1440） | table 548 px，滚动容器 550 px / scrollWidth 550 px，无横向滚动 |
| 页面横向溢出 | `document.documentElement.scrollWidth=1440` = `innerWidth` |
| console | 3 条，error 0，warning 0（JQMIGRATE、插件自身耗时日志、Zibll 主题 banner） |
| 网络 | `GET /wp-json/sufm/v1/admin/users?page=1&per_page=25` 200；`GET admin/delete-requests?page=1&per_page=20` 200；approved 队列 200；`admin-ajax.php` 200 |

截图：`browser/admin-queue-1440.png`（整页，矩阵展开）。

### 1.2 390×844

| 断言 | 值 |
|---|---|
| 视口 / 页面宽 | `innerWidth=390`；`document.documentElement.scrollWidth=390`；`body.scrollWidth=390` |
| 矩阵宽度 | table 320 px，容器 322 px / scrollWidth 322 px，无横向溢出 |
| 版本 | eyebrow 仍为 `1.30.0-BETA-27` |

截图：`browser/admin-queue-390.png`（整页，矩阵保持展开）。

## 2. 非空队列行详情抽屉 #216

- 在 queue 表格第 3 行（#216）点 `详情`；原生 `<dialog class="queue-drawer">` 打开：`dialog.open=true`、`:modal=true`。
- 标题 `审核详情`；附件区 `3 个附件`，3 个 `.queue-thumb`；用户上下文 `A7A8 Target`；打开时焦点在 `关闭详情` 按钮。
- 关闭：点 `关闭详情` 后 `dialog[open]` 数量 0，焦点回到该行触发的 `详情` 按钮（`document.activeElement.innerText === "详情"`）。
- 全程只发 GET（items / users 搜索由抽屉自身发起），没有 review/execute/sanction POST。

截图：`browser/admin-drawer-216.png`。

## 3. 非本人目标的处罚弹窗（a7a8_target，只读）

- 在 users 卡（`data-sakura-card="users"`）找到 `A7A8 Target / a7a8_target` 行，先把 `处罚` 按钮聚焦再点击。
- 弹窗 `<dialog class="sakura-dialog">` 打开，`:modal=true`，标题 `处罚用户 A7A8 Target`；表单完整渲染：
  - 类型：`仅警告（只通知，不限制上传）`、`禁止上传（仍可登录、仍可删除自己文件）`（2 项）
  - 时长：`7 天`、`30 天`、`永久（需输入用户名确认）`（3 项；默认 7 天，所以永久确认输入框未出现）
  - 理由：侵犯版权 / 违法违规内容 / 辱骂或骚扰 / 泄露他人隐私 / 违反站点规则 / 其他（6 项）
  - 备注输入框存在（maxlength=120）；按钮 `取消`、`确认处罚`；没有 `<button type=submit>`，没有 form action。
- 关闭：点 `取消` 后 `dialog[open]` 数量 0；**焦点没有回到触发按钮**：`document.activeElement` 是 `BODY`。第一次用脚本 `.click()`（未先聚焦）得到同样结果，第二次已显式 `btn.focus()` 后再打开，关闭后仍是 BODY，因此判定为真实缺口，不是测试方法造成的假象。

截图：`browser/admin-sanction-target.png`。

## 4. review 与 review_users

| 角色 | 登录后身份 | queue 状态 | 卡片 | users 卡 | `/admin/users` | console |
|---|---|---|---|---|---|---|
| `a7a8_review`（仅 REVIEW） | `您好，A7A8 Review` | 200，title `审核与处置`，eyebrow beta-27 | 仅 `queue` | 无 | 未发起（网络只有 `admin/delete-requests` 200） | 3 条，error 0 / warning 0 |
| `a7a8_review_users`（REVIEW+MANAGE_USERS） | `A7A8 Review Users` | 200，title `审核与处置`，eyebrow beta-27 | `queue`、`users` | 有 | `GET /admin/users?page=1&per_page=25` 200 | 3 条，error 0 / warning 0 |

- 两页均无 `capability-matrix`（非 manage_options），符合 payload 的隐藏规则。
- 截图：`browser/review-queue.png`、`browser/review-users-queue.png`。

## 5. 单项能力角色进 files 模块（users / audit / sync）

| 角色 | 后台身份 | 侧栏“文件空间” | queue 最终 URL | queue 状态 / 标题 / 正文 | audit 再试 `t=log` |
|---|---|---|---|---|---|
| `a7a8_users`（仅 MANAGE_USERS） | `A7A8 Users`（profile.php） | 无 | 同 queue URL，未重定向 | **403** / `WordPress › 错误` / `抱歉，您无权访问此页面。` | — |
| `a7a8_audit`（仅 VIEW_AUDIT） | `A7A8 Audit`（profile.php） | 无 | 同 queue URL，未重定向 | **403** / 同上 | **403**，同样正文 |
| `a7a8_sync`（仅 MANAGE_SYNC） | `A7A8 Sync`（profile.php） | 无 | 同 queue URL，未重定向 | **403** / 同上 | — |

- 三个角色后台都没有“樱花工具箱 / 文件空间”菜单项（`pluginLinks=[]`），说明菜单被 Registry 的 module cap（`review`）隐藏；直接访问由 WordPress 核心在 `admin.php` 入口以 403 拦下（正文是核心的“抱歉，您无权访问此页面。”，不是插件 `wp_die` 文案）。
- 这证明：REST 已按新能力拆开，但 files 模块 gate 仍是 `review`；只有 users/audit/sync 能力的角色有端点权限却没有页面入口（A8-2 第 6 条的核心结论）。
- 截图：`browser/users-only-queue.png`、`browser/audit-only-queue.png`、`browser/audit-only-log.png`、`browser/sync-only-queue.png`。

## 6. Query Monitor（非空队列）

- 测试前 `query-monitor` 为 inactive；`wp plugin activate query-monitor` 后为 active，管理员打开 queue 页（3 行）。
- toolbar 文本：`0.28 秒 15.5MB 0.00 秒 18次查询 时间线 数据库查询 18 计时 4 日志 请求 管理界面 脚本 21 样式 40 … Object Cache (98.16%)`。
- 面板数据（`window.QueryMonitorData.data`，QM 4.1.1 的前端数据源）：
  - 页面耗时 `0.2795 s`（time_limit 100 ms）；内存 `16,294,896 B = 15.54 MB`（limit 512 MB，usage 3.04%）。
  - `db_queries.total_qs=18`，errors 0，rows 18；最慢 3 条：`SHOW FULL COLUMNS FROM wp_options` 0.398 ms、`UPDATE wp_options ...` 0.33 ms、`SELECT COUNT(*) FROM wp_sio_s3_deletes WHERE state='dead'` 0.224 ms，组件均为 `插件：sakura-tools`。
  - `timing.timing` 4 条（Profiler 桥）：`Sakura boot` 2.586 ms、`Sakura modules` 1.2 ms、`Sakura register hooks` 0.194 ms、`Sakura admin page files/queue` 4.447 ms。
- **限制**：QM 4.1.1 的 `#qm` 面板在本站没有挂载（点击 toolbar 与 submenu、`#qm-overview` 直达均只有 `#qm-cache-container`，`document.querySelector('#qm')` 为 null）。因此截图是展开的 QM toolbar（`browser/admin-qm-toolbar.png`），数值取 QM 自己的 `QueryMonitorData.data`（面板渲染用的同一份数据），不是手写/推算。
- 测后 `wp plugin deactivate query-monitor`：状态 inactive，`wp plugin is-active query-monitor` 退出码 1。
- 机器可读数据：`browser/qm-beta27.json`。

## 7. 版本断言汇总

| 页面 | beta-27 证据 |
|---|---|
| 管理员 queue 1440 / 390 | `.page-head__eyebrow = USER FILE SPACE / 1.30.0-BETA-27`；整页 HTML 不含 `1.30.0-beta-26` |
| review queue | 同上 |
| review_users queue | 同上 |
| users/audit/sync 403 页 | 未渲染插件页，无版本号（核心错误页） |
| QM page | 管理员 queue 页，eyebrow beta-27；QM toolbar 正常 |

## 8. Console 汇总

- 管理员 queue、review queue、review_users queue：各 3 条日志，error 0 / warning 0；日志为 JQMIGRATE、插件耗时日志、Zibll banner。
- 单项能力 403 页：核心错误页，无插件脚本；未记录到 error/warning。
- QM 开启后的管理员 queue：console 3 条，error 0 / warning 0；QM 自己输出 `数据库查询：17次 | 页面生成耗时：279.811ms`（与 toolbar/JSON 的 18 次有 1 次差异，属 QM 自身统计口径，原样记录）。

## 9. 残余 / 限制

1. QM 面板 DOM 未挂载（见 §6），面板数值用 `QueryMonitorData.data`；toolbar 截图可复核。
2. 处罚弹窗关闭后焦点未回到触发按钮（见 §3），这是本轮发现的真实可用性缺口。
3. 处罚弹窗的 `GET /admin/users/14/sanction` 没有单独记录状态码；表单/选项完整渲染说明该 GET 成功，但严格的状态码证据缺失。
4. 单项能力角色的 403 由 WordPress 核心在 `admin.php` 入口产生，不是插件 `Menu::render()` 的 `wp_die`；两者都是 403 + 菜单隐藏，行为符合“Registry 拦截”的预期，但文案来源不同。
5. 本轮所有业务写端点均未发请求（review/execute/sanction/sync/reconcile/quota/reset 的实际写入未测）。