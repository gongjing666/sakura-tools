# beta-28：A8-1 #6 处罚弹窗焦点回流

## 结论

- 处罚弹窗用「取消」或 Esc 关闭后，`document.activeElement` 都是触发它的「处罚」按钮（目标行 `a8focus_target`），不再落到 `BODY`。
- 队列抽屉回归通过：打开 → 关闭（关闭详情）→ 再打开 → Esc 关闭，焦点每次都回到「详情」按钮。
- 同一轮浏览器 console：3 条 log，0 error / 0 warning。

## 根因与修复

`SanctionDialog` 是原生 `<dialog>`，平台只在 `dialog.close()` 时归还焦点；原实现先 `onClose()` 触发 React 卸载，卸载后的 cleanup 才对已脱离文档的 dialog 调 `close()`，归还时机丢失。

- `assets/ui/src/files-queue/sanction.jsx`：新增 `returnFocus`；挂载时记录触发器（找不到则回退到触发器所在 `.card` 的 `.card__title` 并补 `tabindex=-1`）；统一 `close()` 先 `dialog.close()`、再 `onClose()`，下一拍 `focus({ preventScroll: true })`。取消、Esc、加载失败、保存成功全部走这个函数。
- `assets/ui/src/files-queue/users.jsx` / `drawer.jsx`：在打开时记录「处罚」按钮，作为 `returnFocus` 传入。
- 契约依据：`docs/开发规则/12-React代码规范.md`「关闭后焦点回触发元素，找不到就回落到卡片标题；一律 focus({ preventScroll: true })」与 `docs/开发规则/14-后台页面契约.md`「必须支持 Esc、焦点回触发元素、Tab 不逃逸」。未改视觉样式。

## 实测

| 场景 | 关闭方式 | 关闭后 activeElement |
|---|---|---|
| 处罚弹窗 | 取消 | `BUTTON`「处罚」`btn btn--small btn--ghost`，行内 `a8focus_target` |
| 处罚弹窗 | Esc | 同上 |
| 队列抽屉 | 关闭详情 | `BUTTON`「详情」，#219 行 |
| 队列抽屉（二次打开） | Esc | `BUTTON`「详情」，#219 行 |

原始读数：`evidence/focus-results.json`；截图：`evidence/sanction-focus-esc.png`；console：`evidence/console-beta28.log`。

## 门禁与部署

- 三处版本 `1.30.0-beta-28`；bundle 490,521 B，SHA-256 `409b85796b7b37b7c209462a41500ba44a8d90f52b424850b31ba1e28a53a2a5`。
- `php -l`、PHPUnit 996 tests / 4553 assertions / 5 skipped、PHPStan `[OK]`、PHPCS strict 0/0、compat 0/0、`node --check`、`git diff --check` 全通过。
- demo 部署前备份 `/root/backups/2026-11-28-beta28-pre-sync/demo-sakura-tools`（145M）；demo 版本与 bundle SHA 与工作区一致；`debug.log` 165 行。
- 清理：合成申请 #219 + item 413 删除，临时用户 15 删除；requests 0 / items 0 / 用户仅 `yexu`；QM 保持 inactive。

## 限制

- 未构造「触发器被移除」场景，卡片标题回退只做了代码路径审查；攻击面小，但未实测。
- 授权失败、保存成功两条关闭路径未发真实请求（业务写按约束不测），它们与取消共用同一个 `close()`。
- 只验证 demo；未 push、未打 tag、未碰生产。
