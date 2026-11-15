# 参与贡献

感谢你愿意花时间。这个插件按「先定决策，再写代码」的方式维护，所以下面先说要读什么，再说要跑什么。

## 1. 动手前先读两处

| 你要做的事 | 读这个 |
| --- | --- |
| 第一次接触这个仓库 | `AGENTS.md`：骨架、装配顺序、十条铁律、验证命令 |
| 具体改一类功能 | [`docs/开发规则/00-索引.md`](docs/开发规则/00-索引.md)：按任务找文档 |

`docs/开发规则/` 六篇是规则正文（架构、新增功能、后台 UI、数据与存储、测试与质量、发布流程）。`docs/history/` 只是历史档案，开头标着「不代表现状」，不要照它改代码。

## 2. 环境

- PHP 8.2 或更高，Composer
- Node.js 仅在改 `assets/` 下的 JS 时用来 `node --check`
- 一个 WordPress 6.4+ 的测试站，用于手工验收（后台页面、上传、S3 连接）

```bash
composer install
```

## 3. 提交前必须跑的命令

```bash
composer test          # PHPUnit，基线 570 tests / 2608 assertions / 6 skipped
composer phpstan       # PHPStan level 5，必须 [OK] No errors
composer lint:strict   # PHPCS，必须 0 error
php -l <改过的每个 PHP 文件>
node --check <改过的每个 JS 文件>
git diff --check       # 行尾空白
```

CI 会跑 Tests 与 PHPStan 两个 workflow，但**不跑 PHPCS**，本地这一关不能省。

三个容易踩的点：

- 测试引导是手写的最小 WordPress shim（`tests/bootstrap.php`）。要用新的 WP 函数，先在 shim 里补桩，否则会 `Call to undefined function`。
- PHPStan 开了 `reportUnmatchedIgnoredErrors`：代码移动会让豁免正则失效并报错。那是提醒你更新 `phpstan.neon` 的 path，不是误报。
- PHPCS 已经刻意关掉全部「纯格式」类 sniff。**不要为了合规去重排对齐**，那类改动会被拒绝。

## 4. 提交信息

用 Conventional Commits 前缀，英文，正文写「为什么」而不是「改了什么」：

```text
fix(upload): keep the original when compression grows the file
perf(ui): cut the admin page-change cross-fade
docs(rules): tighten the facts three new pages got wrong
```

一次提交只做一类事：布局重构不夹带路由或文案改动，功能改动不夹带整页样式重写。

## 5. 提 PR

1. 先开 issue 说明你要解决的问题（小修复可以直接提 PR）。
2. Fork → 建分支 → 提交 → 开 PR。描述里写清：解决什么问题、怎么验证的、影响哪些页面或数据表。
3. 涉及后台界面的改动，附 1440 与 390 两个宽度的截图。
4. 涉及数据表、option 键、cron 或路由的改动，说明升级与卸载路径。

新增一个后台页签要同时改五处（模块注册、renderer、`Layout::PAGES`、别名、视图文件），漏一处就是「菜单有但打不开」，见 [02 新增功能](docs/开发规则/02-新增功能.md)。

## 6. 文档

改代码要顺手改文档。判据是：**如果这次改动会让一个新人按文档操作失败，就必须改**。规则只有一个出处，推翻旧规则时删掉旧的，不要两份并存。

## 7. 许可

本项目以 GPL-2.0-or-later 发布。提交 PR 即表示你同意你的贡献按同一许可分发。