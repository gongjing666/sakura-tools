# beta-30：移动端用户容量卡重设计

## 结论

- ≤782px 不再把桌面表格堆成「标签 | 值」的记录，改为每个用户一张独立紧凑卡：身份 + 处罚在头部，容量使用/文件两列，单独容量输入 + 保存一行。
- 390 单用户卡高度 **480px**（原 634px），移动卡本身 322×155px；页面 scrollWidth=390，无横向溢出；分页 57px，卡尾仅 12px 空白。
- 1440 桌面表格完全保留：users 卡 1220px、表格 1170/1170、处罚表头与按钮右缘仍为 1371/1371。

## 实现

- `users.jsx` 新增 `.users-mobile` 列表，复用同一份 `rows` / `drafts` / `saving` / `sanctionTrigger` / 搜索 / 分页状态；桌面表格原样保留。
- `files.css` 在 ≤782px 隐藏 users 的 `.table-scroll`，显示 `.users-mobile`；卡片之间只用 1px 分隔线，不嵌套第二层卡片；分页在移动端压成两行。
- 没有新增文案 key，也没有新弹窗；处罚继续走共享 `SanctionDialog`，保存继续走原 PATCH 通道。

## 交互验证

- 搜索：用户卡内输入 `yexu` + Enter，值保持、1 条结果；清空后恢复。
- 分页：点 50 后用户卡内 50 选中，点 25 恢复；每页按钮与页码都正常。
- 容量保存：输入 `-1` 点保存，前端提示「请输入不小于 0 的整数，或留空恢复默认容量。」，未发 PATCH；恢复 512。
- 处罚：临时订阅者 `beta30_target` 的移动端按钮可点，dialog `:modal=true`，标题「处罚用户 beta30_target」；Esc 关闭后焦点回到该卡「处罚」按钮。临时用户已删除，最终仅 `yexu`。
- console：3 条 log，0 error / 0 warning。

## 门禁与部署

- 三处版本 `1.30.0-beta-30`；bundle 493,323 B，SHA-256 `54f66fc0d15c04a50ffff1aa33003447548b4ba972581a64b054d710cb8462a6`，与 demo 一致。
- PHPUnit 996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`、`git diff --check` 通过。
- demo 部署前备份 `/root/backups/2026-11-28-beta30-pre-sync/demo-sakura-tools`（150M）；`debug.log` 165 行；requests 0 / items 0。
- 只改 demo，不 push、不打 tag、不碰生产。

## 证据

- `evidence/390-users.png`、`evidence/1440-users.png`、`evidence/1440-full.png`。
- `evidence/mobile-results.json`、`evidence/console-beta30.log`。

## 限制

- 只验 1440 与 390，未逐档验 783/782 边界与 320；CSS 断点为 782px，移动卡在 390 无横向溢出。
- 容量保存只验前端校验，未提交真实 PATCH；处罚弹窗只打开/关闭，未提交处罚。
