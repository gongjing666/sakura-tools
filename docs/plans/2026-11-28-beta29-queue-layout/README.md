# beta-29：审核与处置页两卡重排

## 结论

- 用户容量卡与权限设置卡不再并列，1440 下各占整行 1220px；queue/execute 仍保持 6/6 一行。
- 用户容量卡的「处罚」表头与行内按钮统一右对齐，1440 下表头文字右缘与按钮右缘同为 1371px，用户表 1170/1170 无横向滚动。
- 权限设置保留低频折叠；展开后是全宽矩阵 + 10 项权限说明网格（5 列 / 平板 3 列 / 手机 1 列），说明由长 legend 行改为「缩写 + 名称 + 一句说明」。
- 390 下页面 scrollWidth=390；矩阵在卡片内 322/762 横向滚动，表头「角色」与行标题 sticky，不再整页横移。

## 改动

- `src/Admin/Layout.php`：`users => [2,1,12]`、`capability-matrix => [3,1,12]`。
- `src/UserFiles/Admin/Files_Admin_Payload.php`：`capsCollapse` / `capsCollapseHint` 改为「权限矩阵（低频）」；新增 `capsLegendTitle`；`capsNote` 精简。
- `src/UserFiles/Admin/Capability_Matrix.php`：10 项 `hint` 收成一句，保留原有语义（delete_post、只读、不删除、与处罚分开等）。
- `assets/ui/src/files-queue/caps.jsx`：用 `matrix.columns` 渲染 `caps-legend` 网格，旧 `matrix.legend` 仅作空数据回退。
- `assets/ui/pages/files.css`：处罚列右基线；矩阵 `min-width:760px`、首列 22%、表头/行标题 sticky；说明网格断点。只追加规则，未回退用户既有 CSS。
- 三处版本 `1.30.0-beta-29`；bundle 重建 490,999 B，SHA-256 `223222317ad0605e678bd3734a94c17cbb49b9b777863eb75eae435ed7a07d8d`。

## 验收读数

| 项 | 1440 | 390 |
|---|---|---|
| 页面 scrollWidth | 1440 | 390 |
| 两卡宽度 | 1220 / 1220 | 358 / 358 |
| 权限说明 | 10 项，5 列 | 10 项，1 列 |
| 矩阵滚动 | 1170/1170 | 322/762（卡片内） |
| 表头 sticky | sticky | sticky |
| 处罚表头/按钮右缘 | 1371 / 1371 | 表头隐藏，按钮在标签下方左对齐 |

原始 JSON：`evidence/layout-results.json`；截图：`evidence/1440-users.png`、`evidence/1440-matrix.png`、`evidence/1440-full.png`、`evidence/390-users.png`、`evidence/390-matrix.png`、`evidence/390-matrix-scrolled.png`；console：`evidence/console-beta29.log`（3 log / 0 error / 0 warning）。

> 矩阵实际是 10 项能力（管/申/审/删/户/志/同/媒/移/罚）；任务描述里的「11 项」是计数笔误，交付按现有数据源 10 项。

## 门禁与部署

- PHPUnit 996 tests / 4565 assertions / 5 skipped；PHPStan `[OK]`；PHPCS strict 0/0；compat 0/0；`node --check`；`git diff --check`。
- demo 部署前备份 `/root/backups/2026-11-28-beta29-pre-sync/demo-sakura-tools`（149M）；demo 三处版本与 bundle SHA 一致；`debug.log` 165 行。
- 只做只读页面验证，未发业务写 POST，未创建临时用户/申请。

## 限制

- 只验 1440 与 390，未单独验 1024 / 768 中间宽度；`Layout` 的 12 列与 `card-grid` 的 1100 断点由现有测试覆盖。
- 移动端矩阵仍依赖卡片内横向滚动；表头与行标题已 sticky，但不是把 10 列改成纵向卡片。
