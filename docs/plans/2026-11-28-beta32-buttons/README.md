# beta-32：所有粉色实心按钮统一为 #F585AC

## 结论

- `--g-cta-fill` 从深樱渐变改为纯色 `#F585AC`，hover `#EE6F9B`，描边 `#D95B86`；新增 `--g-cta-ink: var(--g-ink)`，实际墨色 `#3A2333`。
- 主按钮、选中模块/页签、当前页、active 每页按钮、筛选 chip、WP 风格 `.button-primary` 全部命中同一填充与墨字。
- 浅粉上白字只有 **2.38:1**，因此文字改用墨色 **6.02:1**；危险按钮 `.btn--danger` 仍是砖红渐变 + 白字，没有改动。
- 链接、图标、进度条、色块等装饰性深粉仍用 `--g-accent: #C7335B`，没有被全局替换。

## 实测

| 控件 | 填充 | 文字 |
|---|---|---|
| `.btn--primary`「保存权限/保存规则」 | rgb(245,133,172) | rgb(58,35,51) |
| 当前模块「文件空间」 | rgb(245,133,172) | rgb(58,35,51) |
| 当前页签「审核与处置」 | rgb(245,133,172) | rgb(58,35,51) |
| 当前每页 25 / 当前页 1/1 | rgb(245,133,172) | rgb(58,35,51) |
| 筛选 chip「全部 11」 | rgb(245,133,172) | rgb(58,35,51) |
| `.button-primary`（媒体库） | rgb(245,133,172) | rgb(58,35,51) |
| `.btn--danger` | 砖红渐变 | 白字（保持不变） |

- 1440 页面 scrollWidth=1440；390 页面 scrollWidth=390，用户卡仍 480px，无横向溢出。
- console：3 条 log，0 error / 0 warning。

## 门禁与部署

- 三处版本 `1.30.0-beta-32`；bundle 493,357 B，SHA-256 `21756ec85ef2eb9b05dfa36c1451b93a3f363708aebc153205b73c9767def78a`，与 demo 一致。
- PHPUnit 996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`、`git diff --check` 通过。
- demo 备份 `/root/backups/2026-11-28-beta32-pre-sync/demo-sakura-tools`（153M）；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0。

## 证据

- `evidence/1440-users.png`、`evidence/1440-primary.png`、`evidence/1440-filter.png`、`evidence/390-users.png`。
- `evidence/button-colors.json`、`evidence/console-beta32.log`。

## 限制

- `.button-primary` 通过当前 `.sakura-admin` 内的临时探针验证计算样式；它只在媒体库「新建文件夹」等位置出现。
- 只改按钮填充；链接、图标、进度条等深粉强调色按需求保持不变。
