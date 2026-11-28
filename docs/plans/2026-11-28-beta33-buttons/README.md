# beta-33：粉色按钮渐变 + 白字

## 结论

- `--g-cta-fill` 改为樱花淡粉渐变：`#F585AC → #FFB3CA 56% → #FFD6E5`，hover `#F06F9E → #FFA8C3 → #FFCEDE`；描边 `#E88AAB`。
- 文字回到白色 `#FFFFFF`，并加 `0 1px 2px rgba(140,40,70,.30)` 轻粉阴影，保持温柔观感。
- 主按钮、当前模块/页签、当前每页/当前页、筛选 chip、WP 风格主按钮全部命中；危险按钮仍是砖红渐变 + 白字。

## 对比度限制（已确认的审美取舍）

| 渐变位置 | 色值 | 白字对比度 |
|---|---|---|
| 起点 | `#F585AC` | 2.38:1 |
| 中点 | `#FFB3CA` | 1.67:1 |
| 终点 | `#FFD6E5` | 1.31:1 |

这低于 WCAG AA 4.5:1。开发者明确拒绝深色渐变/深色字（“颜色深会显得土”），要求白字 + 樱花淡粉的温柔观感，因此保留该方案并加轻粉阴影，不再用深色“修正”。

## 实测

- 1440 页面 scrollWidth=1440；390 页面 scrollWidth=390，用户卡仍 480px，无横向溢出。
- 主按钮、当前模块/页签、每页 25、当前页 1/1、筛选 chip、WP 主按钮的计算文字均为 `rgb(255,255,255)`，背景均为同一渐变。
- 危险按钮 `.btn--danger` 仍是砖红渐变 + 白字。
- console：3 条 log，0 error / 0 warning。

## 门禁与部署

- 三处版本 `1.30.0-beta-33`；bundle 493,357 B，SHA-256 `4f5594b96faf0018be5c056e648f644da171bddfa3fa0a568c36769a5dfcd798`。
- PHPUnit 996 / 4565 / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`、`git diff --check` 通过。
- demo 备份 `/root/backups/2026-11-28-beta33-pre-sync/demo-sakura-tools`（153M）；`debug.log` 165 行；仅 `yexu`；requests 0 / items 0。

## 证据

- `evidence/1440-users.png`、`evidence/1440-primary.png`、`evidence/1440-filter.png`、`evidence/390-users.png`。
- `evidence/button-colors.json`、`evidence/console-beta33.log`。
