# beta-31：用户容量分页统一为插件样式

## 结论

- 用户容量卡下方分页的当前页从裸文本 `.pagination__now` 改为 `span.pagination__link.is-current`，和删除/同步页一致：粉色渐变实心、白字、`aria-current="page"`。
- 1440 当前页 53×36px，390 为 49×30px；每页 25/50/100、摘要、前后翻页和移动端两行布局都没动。
- 改的是共享 `Pager`，所以同样使用它的文件日志/队列分页也一并统一；删除/同步页本来就是这套 `.is-current` 样式。

## 实测

- 1440：`1 / 1` 为 SPAN，渐变 `linear-gradient(140deg, #d23a66, #c7335b 48%, #b02a4f)`，白字；每页 25 选中；页面 scrollWidth=1440。
- 390：同一 SPAN，49×30px；页面 scrollWidth=390；每页按钮与前后翻页仍为一行，无横向溢出。
- 每页 50 选中后 `1 / 1` 仍为实心；恢复 25 正常；1/1 时上一页/下一页均 disabled（符合只有 1 页的数据）。
- console：3 条 log，0 error / 0 warning。

## 门禁与部署

- 三处版本 `1.30.0-beta-31`；bundle 493,357 B，SHA-256 `4f8565046314fa80aeb0724192bceee5616a11d519b8c95fa63d1f26136df58b`，与 demo 一致。
- PHPUnit 996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`、`git diff --check` 通过。
- demo 备份 `/root/backups/2026-11-28-beta31-pre-sync/demo-sakura-tools`（152M）；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0。

## 证据

- `evidence/390-users.png`、`evidence/1440-users.png`、`evidence/pager-results.json`、`evidence/console-beta31.log`。

## 限制

- demo 只有 1 个用户，无法实测多页时点下一页/页码变化；分页结构和按钮逻辑未改，多页行为由删除/同步页现有实现共用同一 CSS。
