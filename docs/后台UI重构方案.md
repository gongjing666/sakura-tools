# 后台 UI 完整重构方案（云雾白 · 墨石墨）

> 状态：已执行（2026-11-11 完成代码改造；未部署生产）
>
> 实现规范：`docs/后台UI规则.md`
>
> 适用仓库：sakura-tools（WordPress 插件，Version 1.24.4）
>
> 目标分支：dsh-717cea72-39a8
>
> 生产目录：/www/wwwroot/www.nboke.com/wp-content/plugins/sakura-tools（本方案不直接改动）
>
> 关联文档：`docs/后台UI规则.md`

## 0. 执行摘要

这次不是再叠一层皮肤，而是把后台 UI 从「六层 CSS 互相覆盖 + 四个各自为政的 IIFE + 页面内联脚本」改成「一层设计令牌 + 一层基础骨架 + 一层组件 + 页面级组合 + 每页一个 JS 控制器」。

完成后应达到四个可验证的结果：

1. 13 个 Registry 后台页面 + 媒体批量处理页全部使用同一套 CSS 与 JS，不再有旧样式栈页面。
2. 后台只保留 `assets/ui/` 下的样式与脚本；旧 `admin*.css`、`admin.js`、`admin-layout.js`、`userfiles-admin.*`、`userfiles-space-admin.*` 全部删除。
3. 每张可编辑卡片仍然是「一卡一表单、只保存本卡」，不因 UI 重构回退到整页保存。
4. 页面无内联可执行脚本、无 onclick/onsubmit、页面控制器不依赖 jQuery、无 `!important`、无 ID 选择器、无全局 body 样式。

目标体量：后台 CSS 从约 310KB 降到整体 100–120KB、单页 60–90KB；单页加载的 JS 从固定 72KB admin.js + 40KB 布局脚本，降到 20–30KB 共享内核 + 3–12KB 页面控制器，需要卡片布局的页面再加约 40KB。

本方案分 7 个阶段（0–6）、5 个迁移批次（Batch 0–4）。每个阶段都可独立提交、独立回滚；在最后一页迁移完成前不删除旧栈，避免出现半页无样式。

---

## 1. 现状审计

### 1.1 页面矩阵

Registry 共 4 个模块、13 个路由。另有媒体批量处理页与锁定页，不在 Registry 内，但使用同一套后台视觉。

| 页面 ID | 路由 | 视图/入口 | 卡片/表单 | 现有 JS | 复杂度 | 批次 |
|---|---|---|---|---|---|---|
| `system-overview` | `admin.php?page=sakura-tools` | `includes/views/basic-settings.php` | 5 卡 / 1 表单（仅间距卡可编辑） | 无专属脚本 | 中 | 4 |
| `image-rules` | `admin.php?page=sakura-tools-image` | `includes/views/settings-main.php` | 4 卡 / 4 卡内表单 | admin.js 裁剪扫描、压缩模式联动 | 低（已试点） | 0 |
| `image-thumb` | `...&t=thumb` | `includes/views/thumb-settings.php` | 9 卡 / 2 表单 | admin.js 探测、缓存、尺寸表 | 中 | 1 |
| `image-backup` | `...&t=backup` | `includes/views/settings-backup.php` | 3 卡 / 1 表单 | 内联 jQuery（刷新/清理/清空/探测） | 低 | 1 |
| `image-stats` | `...&t=stats` | `includes/views/settings-stats.php` | 1 卡 + KPI / 0 表单 | 内联 confirm、历史分页 | 低 | 1 |
| `storage-connection` | `admin.php?page=sakura-tools-storage` | `src/S3/S3_Storage_Page.php` + `Monitoring_Panel.php` | 3 卡 + 监控面板 / 8 表单 | 内联新增配置、删除确认、测试、监控 | 中 | 2 |
| `storage-sync` | `...&t=sync` | `includes/views/s3-sync-center.php` | 2 卡 / 1 表单 + JSON 数据块 | admin.js 扫描轮询、筛选、分页、行操作、日志、配置弹窗 | 高 | 2 |
| `storage-deletes` | `...&t=deletes` | `src/S3/Delete_Queue_Panel.php` | 1 卡 / 2 表单 | 内联全选、丢弃确认 | 低 | 2 |
| `files-settings` | `admin.php?page=sakura-tools-files` | `includes/views/userfiles-admin.php`（settings 分支） | 视图内 6 卡 / 2 表单，按 tab 分支 | userfiles-admin.js 风格/策略/媒体图标 | 中 | 3 |
| `files-users` | `...&t=users` | 同上（users 分支） | 1 卡动态表 | userfiles-admin.js REST 表格 | 中 | 3 |
| `files-queue` | `...&t=queue` | 同上（queue 分支） | 1 卡动态表 + 执行面板 | userfiles-admin.js 审核/预览/执行 | 高 | 3 |
| `files-log` | `...&t=log` | 同上（log 分支） | 1 卡动态表 | userfiles-admin.js 事件列表 | 低 | 3 |
| `files-zibll` | `...&t=zibll` | `includes/views/userfiles-space-admin.php` | 10 卡 / 7 表单 | userfiles-space-admin.js 套餐、授权、图标 | 高 | 3 |
| `media-progress` | `upload.php` 媒体批量处理 | `includes/views/media-progress.php` | 2 卡 | 内联 jQuery 批处理轮询 | 中 | 4 |
| `locked` | 各模块不可用 tab | `Shell::missing()` / `Menu` 403 | 1 空卡 | 无 | 低 | 4 |

说明：

- `files-settings/users/queue/log` 目前由同一个 `userfiles-admin.php` 按 `Registry::current()` 的 tab 分支渲染；迁移时拆成四个 partial，页面 ID 与资源加载才能一一对应。
- `files-zibll` 由 `Space_Page` 单独渲染，仍属于 files 模块。
- `storage-connection` 的表单数包含 `Monitoring_Panel` 的 3 个 form。
- `image-rules` 是唯一已迁到 UI 层的页面，但它是旧 pilot，仍使用 `.sakura-sync-app` 根节点与旧 `UI::card` 标记，需要在本方案 Batch 0 重新按新规范落地，不能当作最终样板。

### 1.2 CSS 审计

| 文件 | 大小 | 规则数 | 选择器数 | `!important` | 角色 | 结论 |
|---|---:|---:|---:|---:|---|---|
| `assets/admin.css` | 80KB | 621 | 726 | 18 | 旧樱花底座 + 布局补丁，含云/樱花 SVG、蓝粉渐变、胶囊按钮 | 删除 |
| `assets/admin-ext.css` | 20KB | 147 | 171 | 2 | 旧页面细节补丁 | 删除 |
| `assets/admin-unify.css` | 20KB | 76 | 147 | 8 | 圆角/字号/控件尺寸/勾选皮肤补丁 | 并入 tokens/components 后删除 |
| `assets/admin-shell.css` | 4KB | 18 | 21 | 0 | 顶栏、模块切换、tab | 并入 base 后删除 |
| `assets/admin-layout.css` | 12KB | 60 | 64 | 14 | 12 栏卡片拖拽/缩放布局引擎皮肤 | 迁移为 ui/layout.css |
| `assets/admin-theme.css` | 120KB | 853 | 1064 | 14 | 最后的云雾白覆盖层，包含 tokens、chrome、hero、卡片、字体、按钮、表单、表格、扫描、保存条、移动端、可读性 | 拆解后删除 |
| `assets/userfiles-admin.css` | 28KB | 219 | 249 | 1 | 文件空间后台表格/状态/分页/执行步骤 | 并入 pages/files.css 后删除 |
| `assets/userfiles-space-admin.css` | 32KB | 179 | 201 | 0 | 文件空间套餐/策略/授权卡 | 并入 pages/files.css 后删除 |
| `assets/media-library.css` | 4KB | 9 | 10 | 0 | 媒体列表列宽 | 保留为 wp-media 集成 |
| `assets/media-modal-folders.css` | 4KB | 12 | 12 | 1 | WP 媒体弹窗文件夹选择器 | 保留为 wp-media 集成 |
| `assets/ui/tokens.css` | 8KB | 17 | 28 | 0 | 现有 UI 试点令牌 | 扩展为唯一令牌源 |
| `assets/ui/components.css` | 32KB | 201 | 290 | 18 | 现有 UI 试点组件 | 重写并去掉 `!important` |

关键问题不是文件数量，而是所有权混乱：

- 同一组 `.form-table` 控件规则在 `admin.css`、`admin-ext.css`、`admin-unify.css`、`admin-theme.css` 各写一遍，最后一层靠更高特异性覆盖。
- `admin-theme.css` 里已经有完整视觉，但前面五层仍然加载；改一个颜色要确认五层都没有反制。
- `admin-unify.css` 在 `admin_enqueue_scripts` 优先级 999 加载，`admin-theme.css` 在 1000 加载，靠「更晚加载」取胜，不是靠结构。
- `admin.css` 仍定义 `--blue/--pink/--rose`、粉蓝 radial-gradient、`admin-clouds.svg`、`admin-sakura.svg`；这些是旧视觉残留。
- 页面级 `userfiles-admin.css` 与 `userfiles-space-admin.css` 又各自建立一套 `.sakura-sync-app` 作用域，与全局层重复。

### 1.3 JS 审计

| 文件 | 大小 | jQuery | 角色 | 问题 |
|---|---:|---:|---|---|
| `assets/admin.js` | 72KB | 24 处 | 单 IIFE 拼装：toast、AJAX、扫描轮询、同步表、批量选择/上传/下载/删除、S3 测试、压缩联动、裁剪扫描、masonry、统计分页、S3 配置弹窗 | 所有页面都加载；顶部先查 `.sakura-sync-app`；每个功能靠 DOM id 和全局配置；无命名空间、无生命周期、无事件总线 |
| `assets/admin-layout.js` | 40KB | 0 | 单 IIFE，读 `window.sakuraLayout`，管理 12 栏拖拽/缩放、工具栏、user meta 保存 | 与页面根节点强耦合；`labelTables()` 只处理 `.sakura-sync-app`；末尾有全局 `__sakuraMasonryTimer` |
| `assets/userfiles-admin.js` | 24KB | 22 处 `$` 风格选择器（非 jQuery） | REST 表格、审核、执行、用户容量、事件 | 闭包 state + 直接 DOM；配置来自 `window.sufmAdmin`；与 `userfiles-admin.php` 的 id 强耦合 |
| `assets/userfiles-space-admin.js` | 24KB | 0 | 套餐 MB 合计、破坏性开关确认、媒体库图标、授权表刷新 | 配置来自 `window.sufmSpaceCfg`；逻辑与页面结构耦合 |
| 页面内联脚本 | 5 处 | 部分 | 备份、媒体批处理、S3 新增配置、删除队列全选、统计重置 | 无法缓存、无法测试、nonce 直接写在 HTML 里 |

现有后台没有统一的：

- 命名空间与启动入口；
- 组件注册与销毁；
- 共享 API 客户端；
- 统一错误模型；
- 统一弹窗/确认/Toast；
- 页面数据传递契约；
- 可测试的纯函数层。

### 1.4 旧资源加载链

当前 `src/Admin/Assets.php` 的实际路径：

```text
enqueue_page()
  ├─ is_ui_screen() ? enqueue_ui() : enqueue_style()
  │    ├─ enqueue_ui(): tokens.css + components.css + Shell::enqueue()
  │    └─ enqueue_style(): admin.css + admin-ext.css + Shell::enqueue()
  ├─ admin.js（依赖 jquery，所有页面）
  └─ enqueue_layout(ui ? 'sakura-imgopt-ui-components' : 'sakura-imgopt-admin')
       ├─ admin-layout.css
       ├─ inline style: .sakura-sync-app { --gap-x/--gap-y }
       ├─ admin-layout.js
       └─ wp_localize_script('sakuraLayout')
```

另有：

- `Plugin.php` 在 `admin_enqueue_scripts` 优先级 999 挂 `Assets::enqueue_unify()`，优先级 1000 挂 `Assets::enqueue_theme()`；两者都先检查 `wp_style_is('sakura-imgopt-admin')`。
- `Sync_Center_Module.php`、`Basic_Settings_Module.php`、`UserFiles/Admin/Page.php`、`Space_Page.php` 各自手工 enqueue，导致同一页面在不同模块下加载的样式子集不同。
- `is_ui_screen()` 只认 `page=sakura-tools-image` 且 `t` 为空或 `rules`，所以只有图片规则页走新层，其余 12 页仍走旧层。

### 1.5 根因归纳

1. **没有单一所有权**：颜色、圆角、按钮、表单、卡片分别被 4–6 个文件定义。
2. **两套栈并存**：UI pilot 与旧栈各自加载，页面无法预测自己会得到什么。
3. **JS 是页面功能的总汇**：admin.js 不区分页面，所有逻辑常驻；任何一页的改动都可能影响其他页。
4. **行为与样式耦合**：JS 依赖 `.sakura-sync-app`、`.btn`、具体 id；CSS 依赖页面结构。
5. **内联脚本绕过资源层**：nonce、URL、i18n 直接写进 HTML，无法统一 CSP、缓存、测试。
6. **删除滞后**：新层建好后旧层没有退出计划，最后变成永久共存。

---

## 2. 目标架构

### 2.1 设计原则

1. **单一所有权**：一个视觉决定只在一个文件里定义；组件不知道页面，页面只做组合。
2. **根节点作用域**：所有后台样式以 `.sakura-admin` 为根；所有页面控制器以 `[data-sakura-page]` 为根。
3. **令牌只存值**：`tokens.css` 只允许自定义属性，不写选择器规则（除 `:root`）。
4. **行为与样式分离**：CSS 用 class；JS 用 `data-sakura-*`。JS 不查 `.btn`/`.card`，CSS 不依赖 JS 生成的 class。
5. **渐进增强**：表单在没有 JS 时仍能正常 POST；JS 只做确认、异步刷新、局部更新。
6. **不做构建链**：不使用打包器、CSS 预处理器、`wp_enqueue_script_module`（最低 WP 6.4 不支持）；经典 IIFE + 文件依赖。
7. **只改 UI 层**：后端 option 名、AJAX action、nonce action、REST 路由、权限判断全部冻结。
8. **先迁后删**：每一页迁移并验证后才从旧栈移除；最后一页完成后统一删除旧资产。

### 2.2 CSS 分层

最终后台 CSS 结构：

| 文件 | 职责 | 允许的选择器 | 依赖 |
|---|---|---|---|
| `assets/ui/tokens.css` | 颜色、字体、圆角、阴影、间距、控件高度、动效、断点 | 仅 `:root` 自定义属性 | 无 |
| `assets/ui/base.css` | 作用域 reset、画布、顶栏、模块切换、tab、页头、排版、工具类、响应式断点 | `.sakura-admin` 及其子元素 | tokens |
| `assets/ui/components.css` | 卡片、按钮、字段、表格、徽章、提示、空状态、KPI、进度、分页、弹窗、Toast | `.sakura-admin` 下的组件 class | base |
| `assets/ui/layout.css` | 12 栏卡片布局、拖拽/缩放工具条、网格预览、响应式折叠 | `.sakura-admin` 下的布局 class 与 `[data-sakura-*]` | components |
| `assets/ui/pages/image.css` | 图片模块专属：扫描结果、尺寸表、裁剪警告、探测状态 | `.sakura-admin[data-sakura-page^="image-"]` | components |
| `assets/ui/pages/storage.css` | 对象存储专属：连接状态、同步表、指标、队列 | `.sakura-admin[data-sakura-page^="storage-"]` | components |
| `assets/ui/pages/files.css` | 文件空间专属：树样式、套餐、授权、执行步骤 | `.sakura-admin[data-sakura-page^="files-"]` | components |
| `assets/ui/pages/system.css` | 系统自检专属：检查表、评分、扩展列表 | `.sakura-admin[data-sakura-page="system-overview"]` | components |
| `assets/ui/pages/media.css` | 媒体批量处理页 | `.sakura-admin[data-sakura-page="media-progress"]` | components |
| `assets/ui/wp-media.css` | WP 媒体弹窗/媒体列表集成 | `body.upload-php`、`.media-modal` 等 WP 作用域 | 独立 |

加载顺序由依赖保证：

```text
tokens → base → components → layout → pages/*.css
```

页面不加载不需要的 page CSS；没有 `[data-sakura-layout]` 的页面不加载 layout.css。

#### 2.2.1 选择器契约

必须满足，代码评审与脚本都检查：

- 组件与页面规则必须以 `.sakura-admin` 开头；`base.css` 内可以写 `.sakura-admin h1` 这类后代元素选择器，但不得写裸 `h1`。
- 禁止 ID 选择器（`#sakura-*`、`#sufm-*`）。
- 禁止 `!important`。唯一例外是 `wp-media.css` 中覆盖 WP 核心弹窗的必要规则，且必须写明原因。
- 禁止 `body`、`#wpcontent`、`#wpbody-content`、`.wrap` 等全局选择器；画布效果放在 `.sakura-admin` 上。
- 禁止在 `components.css` 出现 `[data-sakura-page=...]` 或模块名。
- 禁止 CSS 嵌套、`@layer`、`@container`（WP 6.4 目标浏览器与 WP 后台未分层 CSS 会让 `@layer` 规则被未分层规则压过）。
- 组件规则最大特异性 `(0,2,0)`；页面规则允许 `.sakura-admin[data-sakura-page]` 作用域，最大 `(0,3,0)`；状态用 `.is-*` 或 `[aria-*]`，不靠叠加 4 个 class 取胜。
- 动态数值只用 CSS 自定义属性，不在 JS 里拼样式规则。
- 断点固定为 `1200px / 1024px / 782px / 600px`；不要每页自造断点。

#### 2.2.2 命名约定

- 块：`.card`、`.btn`、`.field`、`.table`、`.badge`、`.alert`、`.modal`、`.toast`、`.kpi`、`.pagination`。
- 元素：`.card__head`、`.card__body`、`.card__savebar`。
- 修饰符：`.btn--primary`、`.btn--danger`、`.btn--ghost`、`.btn--small`、`.card--wide`。
- 状态：`.is-active`、`.is-busy`、`.is-open`、`.is-disabled`、`.is-error`。
- 后台 chrome 保留 `.sakura-*`：`.sakura-topbar`、`.sakura-mods`、`.sakura-tabs`，避免与 WP 核心 class 冲突。
- JS 钩子统一 `data-sakura-*`：`data-sakura-page`、`data-sakura-action`、`data-sakura-role`、`data-sakura-id`、`data-sakura-card`、`data-sakura-layout`、`data-sakura-group`、`data-sakura-collect`。

#### 2.2.3 设计令牌

保留现有 `--g-*` 命名，避免全量重命名造成无意义 diff；`tokens.css` 是唯一来源。

核心颜色：

```css
:root {
  --g-ink: #1c1d1f;
  --g-ink-soft: #383a3d;
  --g-muted: #606265;
  --g-faint: #8b8d8a;
  --g-line: #e6e5e0;
  --g-line-soft: #efeeea;
  --g-line-strong: #d3d3ce;
  --g-paper: #f7f7f4;
  --g-card: #ffffff;
  --g-wash: #fbfbf9;
  --g-accent: #202225;
  --g-accent-soft: #efeee9;
  --g-ok: #35734f;
  --g-warn: #8f6a1f;
  --g-bad: #a3463d;
  --g-info: #2f5f8f;
}
```

形状与控件：

```css
:root {
  --g-radius-xl: 18px;
  --g-radius-lg: 14px;
  --g-radius-md: 10px;
  --g-radius-sm: 6px;
  --g-control: 38px;
  --g-control-sm: 32px;
  --g-font-xs: 11px;
  --g-font-sm: 12.5px;
  --g-font-md: 13.5px;
  --g-font-base: 14px;
  --g-font-lg: 17px;
  --g-font-xl: 20px;
  --g-font-display: 28px;
}
```

阴影、焦点、动效：

```css
:root {
  --g-shadow-card: 0 1px 2px rgba(28, 29, 31, .04), 0 16px 40px -28px rgba(28, 29, 31, .28);
  --g-shadow-pop: 0 24px 64px -24px rgba(28, 29, 31, .35);
  --g-focus: 0 0 0 2px #ffffff, 0 0 0 4px rgba(32, 34, 37, .28);
  --g-ease: cubic-bezier(.2, .7, .3, 1);
  --g-dur: 140ms;
}
```

新增令牌只允许进 `tokens.css`；组件里不得出现新的裸色值。`rgba()` 阴影值可以保留在 token 定义里，不得散落到页面 CSS。

#### 2.2.4 视觉配方

**画布**：软白底 + 云雾感，纯 CSS 大半径径向渐变，不用 SVG、不用重复图案、不用噪点。

```css
.sakura-admin {
  background-color: var(--g-paper);
  background-image:
    radial-gradient(1100px 520px at 12% -8%, rgba(255, 255, 255, .95), rgba(255, 255, 255, 0) 62%),
    radial-gradient(900px 480px at 88% 2%, rgba(233, 236, 238, .55), rgba(233, 236, 238, 0) 58%),
    radial-gradient(700px 420px at 50% 110%, rgba(255, 255, 255, .70), rgba(255, 255, 255, 0) 60%);
}
```

**页面骨架**：

- 顶栏高度 56–60px，底部 1px `--g-line`，白底，不使用渐变。
- 主内容最大宽度 1440px，左右留白桌面 32px、1200px 以下 24px、782px 以下 16px、600px 以下 12px。
- 页头不是 hero：无背景、无插画、无大块渐变；标题 26–28px，说明 14px、最大 68ch，右侧状态卡宽 260–280px。
- 页头与第一张卡片之间 20–24px；卡片之间使用布局令牌 `--g-grid-gap-x/--g-grid-gap-y`，数值来自系统页保存的 `Layout::gaps()`。

**卡片**：

- 白底、1px `--g-line`、`--g-radius-xl` 圆角、`--g-shadow-card`。
- 头部与身体内边距 20–24px；头部底部 1px 分隔线，说明文字与标题间距 6–8px。
- 卡片内不出现第二层白底卡片；需要分组用 `.field-group` 或 `.subblock`，用 `--g-wash` 底和 `--g-radius-lg`。
- 卡片 hover 不抬升、不缩放；管理界面不需要装饰性动效。
- 可编辑卡片的保存条固定在卡片内容末尾，右对齐；不悬浮、不跨页。

**字体**：

- 系统字体栈：`-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif`。
- 正文 14px/1.65；表格 13.5px；辅助 12.5px；eyebrow 11px 大写、字距 .08em；只有 eyebrow 允许 11px。
- 标题字重 600；正文 400；按钮/标签 500。
- 统计数字用 `font-variant-numeric: tabular-nums`。
- 段落最大宽度 72ch；表单说明最大 60ch；避免整行 1200px 的长文本。

**控件**：

- 输入框/选择框高 38px，圆角 `--g-radius-md`，1px `--g-line-strong`，聚焦用 `--g-focus`。
- 文本输入最大宽度 520px；数字输入 160px；文本域 100%、最小高 96px。
- 按钮高 36px，圆角 `--g-radius-md`；主按钮石墨底暖白字；次按钮白底石墨字；危险按钮只用于破坏性操作。
- 勾选框/单选框保持既定规范：18px 圆形、石墨底 `#2f3134`、选中点 `#f6f4ed`；标签点击区域至少 44px 高。
- 开关只用于即时生效的布尔项；表单保存型布尔项一律用勾选框。

**表格**：

- `.table-wrap` 负责横向滚动；`.table` 最小宽度按内容设置。
- 表头 12.5px、`--g-muted`、白底或 `--g-wash`；行 13.5px；单元格内边距 10–12px；数字右对齐。
- 长表格表头 sticky；行 hover `--g-wash`；不使用斑马纹。
- 782px 以下切换为卡片行：`td::before` 读取 `data-label`；标签由共享脚本写入，页面不手写。

**弹窗**：

- 使用原生 `<dialog class="modal">`；`showModal()` 提供焦点陷阱、Esc、backdrop。
- 最大宽度 560px（配置类 640px）；圆角 `--g-radius-xl`；阴影 `--g-shadow-pop`。
- backdrop `rgba(28,29,31,.32)` + `backdrop-filter: blur(2px)`。
- 标题、正文、底部操作三段；危险操作主按钮用 `.btn--danger`；确认文案必须说明后果。
- 关闭后焦点回到触发元素。

**Toast**：

- 右下角，宽 320–380px；墨石墨底、暖白字；左侧 3px 状态色。
- `aria-live="polite"`；同时最多 3 条；3.2 秒自动消失；错误可手动关闭。
- 不使用 `alert()`、`confirm()`。

#### 2.2.5 体量预算

| 文件 | 预算 |
|---|---:|
| tokens.css | ≤ 8KB |
| base.css | ≤ 24KB |
| components.css | ≤ 36KB |
| layout.css | ≤ 12KB |
| pages/*.css 合计 | ≤ 40KB |
| 单页后台 CSS（未压缩） | ≤ 90KB |

超过预算时先检查是否把页面规则写进了 components，而不是继续加文件。

### 2.3 PHP 组件层

目标不是把每个 HTML 标签都包一层，而是把重复的「卡片、字段、表格、反馈、弹窗、分页」收口，页面视图只保留业务组合。

目录：

```text
src/Admin/
  UI.php                 // 门面，保持 UI:: 调用风格
  UI/
    Page.php             // 页面根节点 + Shell + 页头
    Card.php             // 卡片 + 卡内表单 + 保存条
    Field.php            // 输入/选择/文本域/勾选/单选/开关
    Table.php            // 简单表格；复杂表格用同一套 class 手写
    Feedback.php         // badge / alert / empty / notice
    Modal.php            // 原生 dialog
    Progress.php         // 进度条
    Pagination.php       // 分页
    Kpi.php              // 指标块
```

#### 2.3.1 `UI::page()`

职责：输出页面根节点、调用 `Shell::header()`、输出页头、打开主内容区。

```php
UI::page(
    array(
        'id'      => 'image-rules',
        'module'  => 'image',
        'tab'     => 'rules',
        'eyebrow' => 'IMAGE OPTIMIZATION / ' . SAKURA_TOOLS_VERSION,
        'title'   => __( '优化规则', 'sakura-imgopt' ),
        'lede'    => __( '控制压缩、格式转换与裁剪策略。', 'sakura-imgopt' ),
        'status'  => array(
            'label' => __( '优化引擎', 'sakura-imgopt' ),
            'value' => 'GD',
            'unit'  => '',
            'sub'   => __( '已启用', 'sakura-imgopt' ),
            'tone'  => 'ok',
        ),
        'actions' => array(
            array( 'label' => __( '查看统计', 'sakura-imgopt' ), 'url' => $stats_url, 'variant' => 'ghost' ),
        ),
    ),
    static function () use ( $options ): void {
        // cards
    }
);
```

输出结构：

```html
<div class="sakura-admin" data-sakura-page="image-rules">
  <header class="sakura-topbar">...</header>
  <main class="sakura-admin__main">
    <header class="page-head">...</header>
    <div class="card-grid" data-sakura-layout="image" data-sakura-group="main">
      ...cards...
    </div>
  </main>
</div>
```

约束：

- 一个页面只能有一个 `<h1>`，由 `UI::page()` 输出。
- `status` 为空时不输出状态卡，页头保持单列。
- `actions` 只接受数组，不接受 HTML 字符串；每个动作是 `label/url/variant/icon`。
- `UI::page()` 不输出 `settings_errors()`；页面在调用前自行输出，避免把 WP 通知塞进页头。
- 根节点不输出 `sky-layer`、`sakura-layer`、`.app`。

#### 2.3.2 `UI::card()`

```php
UI::card(
    array(
        'id'          => 'compression',
        'kicker'      => 'Compression',
        'title'       => __( '压缩策略', 'sakura-imgopt' ),
        'description' => __( '决定上传图片的压缩方式与质量。', 'sakura-imgopt' ),
        'form'        => array(
            'option_group' => 'sakura_imgopt_options',
            'option'       => 'sakura_imgopt_options',
            'card'         => 'compression',
        ),
        'save'        => __( '保存压缩策略', 'sakura-imgopt' ),
        'wide'        => false,
        'actions'     => array(),
    ),
    static function () use ( $options ): void {
        UI::field( array( /* ... */ ) );
    }
);
```

输出：

```html
<section class="card" data-sakura-card="compression">
  <header class="card__head">
    <div class="card__heading">
      <p class="card__kicker">Compression</p>
      <h2 class="card__title">压缩策略</h2>
      <p class="card__desc">决定上传图片的压缩方式与质量。</p>
    </div>
    <div class="card__actions">...</div>
  </header>
  <div class="card__body">
    <form method="post" action="options.php">
      ...hidden markers + nonce...
      ...body...
      <div class="card__savebar"><button class="btn btn--primary">保存压缩策略</button></div>
    </form>
  </div>
</section>
```

约束：

- `form` 为空 = 只读卡，不输出 `<form>`、不输出保存条。
- `form` 非空 = 一卡一表单；表单必须包住 `card__body` 内的全部字段与保存条。
- `Card_Form::fields()` / `option_fields()` / `guard_options()` 继续作为唯一保存契约，不新增第二套。
- `actions` 用于刷新、测试、跳转等非保存动作；放在 `card__head` 右侧，不放进保存条。
- `wide` 输出 `card--wide`；布局宽度仍由 `data-sakura-colspan` 或布局引擎控制。
- `id` 同时作为 `data-sakura-card` 和字段 id 前缀；同一页必须唯一。
- `save` 为空但有表单时，仍输出保存条，但按钮文案用默认「保存更改」。

#### 2.3.3 `UI::field()`

```php
UI::field(
    array(
        'type'        => 'select',
        'name'        => 'sakura_imgopt_options[compression_mode]',
        'id'          => 'compression-mode',
        'label'       => __( '压缩方式', 'sakura-imgopt' ),
        'value'       => $options['compression_mode'],
        'options'     => array(
            'lossy'  => __( '有损压缩', 'sakura-imgopt' ),
            'lossless' => __( '无损压缩', 'sakura-imgopt' ),
        ),
        'hint'        => __( '有损压缩体积更小，无损压缩保留更多细节。', 'sakura-imgopt' ),
        'required'    => false,
        'disabled'    => false,
        'attrs'       => array( 'data-sakura-role' => 'compression-mode' ),
    )
);
```

支持类型：`text`、`number`、`url`、`password`、`email`、`select`、`textarea`、`checkbox`、`radio`、`toggle`、`hidden`、`custom`。

输出统一为：

```html
<div class="field field--select">
  <div class="field__main">
    <label class="field__label" for="compression-mode">压缩方式</label>
    <p class="field__hint" id="compression-mode-hint">...</p>
  </div>
  <div class="field__control">...</div>
</div>
```

规则：

- `name` 必须保持后端已有 key，迁移时不得改名；`id` 可改，但必须同步 `for`、`aria-describedby` 与 JS 钩子。
- `hint` 通过 `aria-describedby` 关联；错误用 `aria-invalid` + `.field__error`。
- `checkbox`/`radio` 使用原生 input，视觉层用 `.check` 包装；不要用 div 模拟。
- `toggle` 只用于即时生效项；保存型布尔项用 `checkbox`。
- `custom` 允许调用方输出复杂控件，但仍包在 `.field` 结构里。

#### 2.3.4 `UI::table()`

简单表格：

```php
UI::table(
    array(
        'columns' => array(
            array( 'label' => __( '对象', 'sakura-imgopt' ), 'key' => 'key' ),
            array( 'label' => __( '大小', 'sakura-imgopt' ), 'key' => 'size', 'align' => 'right' ),
        ),
        'rows'    => $rows,
        'empty'   => __( '暂无记录。', 'sakura-imgopt' ),
        'class'   => 'table--sync',
    ),
    static function ( array $row ): array {
        return array(
            'key'  => esc_html( $row['key'] ),
            'size' => esc_html( size_format( (int) $row['size'] ) ),
        );
    }
);
```

复杂表格（合并单元格、行内按钮、可展开行）继续手写 `<table class="table">`，但必须使用同一套 `.table-wrap` / `.table` / `.table__head` class；不要另建 `sufm-table`、`sakura-sync-table` 等第二套命名。

#### 2.3.5 反馈组件

- `UI::badge( $label, $tone = 'neutral' )`：`ok`、`warn`、`bad`、`info`、`neutral`。
- `UI::alert( $message, $tone = 'info', $title = '' )`：页面级通知；不再使用 `.alert-strip`。
- `UI::empty_state( $title, $message, $action = array() )`：空表、无配置、无日志。
- `UI::notice( $message, $tone = 'info' )`：卡片内短提示。
- `UI::modal( $id, $title, callable $body, array $footer = array() )`：原生 `<dialog>`。
- `UI::progress( $value, $max = 100, $label = '' )`：`role="progressbar"` + `aria-valuenow`。
- `UI::pagination( $current, $total, callable $url )`：上一页/下一页/页码；不输出裸 `«`。
- `UI::kpi( $label, $value, $unit = '', $sub = '' )`：统计页指标块。

所有组件输出都必须转义：`esc_html()`、`esc_attr()`、`esc_url()`；`description` 不接受 HTML，除非调用方显式使用 `wp_kses_post()` 并说明来源。

#### 2.3.6 Shell 契约

`Shell::header()` 的 class 与层级保持不变：

- `header.topbar.sakura-topbar`
- `.brand` / `.brand-mark` / `.path`
- `nav.sakura-mods` / `a.sakura-mod`
- `.top-actions` / `.conn-pill`
- `nav.sakura-tabs` / `a.sakura-tab` / `.sakura-tab.is-disabled`

只改两件事：

1. `Shell::enqueue()` 不再加载 `admin-shell.css`，由 `base.css` 接管。
2. `Shell::missing()` 的空卡改用 `UI::empty_state()`，不再手写旧 `.card` 结构。

在旧页面迁移期间，Shell 输出保持兼容；旧页面仍加载旧 CSS，新页面加载 base.css。两边都认同一套 class，不打架。

### 2.4 JS 内核与页面控制器

最终后台 JS 结构：

| 文件 | 职责 | 依赖 |
|---|---|---|
| `assets/ui/js/core.js` | 命名空间、启动、DOM 工具、事件总线、格式化、JSON 数据块读取 | 无 |
| `assets/ui/js/api.js` | admin-ajax、REST、上传、错误模型、超时/取消 | core |
| `assets/ui/js/ui.js` | Toast、确认框、原生 dialog、busy、复制、响应式表格标签 | core |
| `assets/ui/js/layout.js` | 12 栏拖拽/缩放、工具栏、user meta 保存（由 admin-layout.js 迁移） | core, ui |
| `assets/ui/js/pages/*.js` | 每页一个控制器，只在当前页加载 | core, api, ui, layout（按需） |
| `assets/ui/js/wp-media.js` | WP 媒体弹窗文件夹选择器集成 | core（不使用页面控制器） |

#### 2.4.1 全局契约

只暴露一个全局：

```js
window.SakuraAdmin = {
  config,          // ajaxUrl, restUrl, nonce, restNonce, page, strings, locale
  page,            // page(id, init)
  ready,           // ready(fn)
  qs, qsa,         // querySelector / querySelectorAll
  on, delegate,    // 事件绑定
  bus,             // on(name, fn) / emit(name, detail)
  data,            // data(id) 读取 JSON 数据块
  format           // bytes / number / date / duration
};
```

禁止再出现：

- `window.sakuraSyncCenter`
- `window.sufmAdmin`
- `window.sufmSpaceCfg`
- `window.sakuraLayout`
- `window.__sakuraMasonryTimer`

页面配置统一走 `wp_localize_script()` 的 `sakuraPage`：

```php
wp_localize_script(
    'sakura-ui-js-page-storage-sync',
    'sakuraPage',
    array(
        'nonces'  => array(
            'scan'    => wp_create_nonce( 'sakura_s3_sync_scan' ),
            'retry'   => wp_create_nonce( 'sakura_s3_retry' ),
            'action'  => wp_create_nonce( 'sakura_s3_sync_action' ),
        ),
        'urls'    => array(
            'settings' => $settings_url,
            'export'   => $export_url,
        ),
        'flags'   => array(
            'configured' => $is_configured,
        ),
        'strings' => array(
            'scanning' => __( '正在核对…', 'sakura-imgopt' ),
            'failed'   => __( '操作失败，请重试。', 'sakura-imgopt' ),
        ),
    )
);
```

大块只读数据（同步日志、统计历史）保留 `<script type="application/json" data-sakura-data="sync-log">`，由 `SakuraAdmin.data('sync-log')` 读取；这属于数据块，不是可执行内联脚本，可以保留。

#### 2.4.2 启动流程

```text
DOMContentLoaded
  └─ SakuraAdmin.ready()
       ├─ 读取 window.sakuraAdmin 基础配置
       ├─ 读取 window.sakuraPage 页面配置
       ├─ 查找 document.querySelector('[data-sakura-page]')
       ├─ 读取 data-sakura-page 得到页面 ID
       ├─ 执行已注册的 page init（若存在）
       ├─ 初始化响应式表格标签
       └─ 初始化 layout（若页面有 [data-sakura-layout]）

pagehide
  └─ abort ctx.signal
  └─ 调用 init 返回的 cleanup
```

每个页面只加载自己的 controller；没有「先判断当前页再执行」的巨型 if。

#### 2.4.3 `api` 契约

```js
SakuraAdmin.api.ajax(action, data, options)
// options: { nonce, nonceField = 'nonce', signal, timeout = 20000 }

SakuraAdmin.api.rest(path, options)
// options: { method = 'GET', body, signal, timeout = 20000 }

SakuraAdmin.api.upload(file, options)
// options: { action, fields, nonce, signal, onProgress }

SakuraAdmin.api.get(url, params, options)
// 用于同步中心的 fast page 只读端点
```

规则：

- 所有请求返回 Promise；成功解析为 `data`，失败抛出 `SakuraApiError`。
- `SakuraApiError` 字段：`message`、`code`、`status`、`payload`。
- API 层不自动弹 Toast；由页面控制器决定如何展示。
- 默认超时 20 秒；上传不设超时；扫描单步 15 秒。
- 只有 GET 允许重试一次；POST 不自动重试，避免重复执行。
- 非 JSON 响应统一报「服务器返回了无法解析的响应」，不把 HTML 错误页塞进界面。
- 所有 nonce 从 `sakuraPage.nonces` 读取，不写死在 DOM、不拼接进 URL。
- 上传使用 `XMLHttpRequest`（需要 progress），其余使用 `fetch`。
- `signal` 由 controller 的 `ctx.signal` 传入；页面卸载时 abort。

#### 2.4.4 `ui` 契约

```js
SakuraAdmin.ui.toast(message, { tone: 'info', duration: 3200 });
SakuraAdmin.ui.confirm({
  title: '清空备份？',
  message: '此操作不可恢复。',
  confirmLabel: '清空',
  cancelLabel: '取消',
  tone: 'danger'
}).then(function (ok) { /* ... */ });

SakuraAdmin.ui.dialog(id);       // { open(), close(), el }
SakuraAdmin.ui.busy(el, true);    // 按钮 loading + disabled + aria-busy
SakuraAdmin.ui.notice(el, { tone, message });
SakuraAdmin.ui.copy(text);
SakuraAdmin.ui.formatBytes(bytes);
```

`ui.confirm()` 必须基于原生 `<dialog>`，返回 Promise；Esc、backdrop、取消都 resolve `false`；确认 resolve `true`；关闭后焦点回到触发元素。页面里禁止 `window.confirm()`、`window.alert()`。

#### 2.4.5 页面控制器契约

```js
SakuraAdmin.page('image-backup', function (ctx) {
  'use strict';

  var root = ctx.root;        // [data-sakura-page="image-backup"]
  var data = ctx.data;        // window.sakuraPage
  var api = ctx.api;
  var ui = ctx.ui;
  var bus = ctx.bus;
  var signal = ctx.signal;

  function refresh(button) {
    ui.busy(button, true);
    api.ajax('sakura_refresh_backup', {}, { nonce: data.nonces.refresh, signal: signal })
      .then(function (result) {
        ui.toast(result.message || data.strings.refreshed, 'ok');
      })
      .catch(function (error) {
        ui.toast(error.message, 'bad');
      })
      .then(function () {
        ui.busy(button, false);
      });
  }

  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-sakura-action]');
    if (!button || !root.contains(button)) {
      return;
    }
    var action = button.getAttribute('data-sakura-action');
    if ('refresh' === action) {
      refresh(button);
    }
  });

  return function cleanup() {
    // 移除手动绑定的事件；AbortController 已处理请求
  };
});
```

规则：

- 控制器只操作 `ctx.root` 内部；不允许 `document.querySelector('.sakura-sync-app')`。
- 行为钩子用 `data-sakura-action` / `data-sakura-role`；不允许用 `.btn-primary`、`#sakura-sync-scan` 作为唯一钩子。
- 用户数据用 `textContent` / `createElement` 渲染；`innerHTML` 只允许静态模板或服务端已转义的片段。
- 不直接写 `fetch`、`$.ajax`、`$.post`；统一走 `ctx.api`。
- 不直接写 `window.confirm` / `alert`；统一走 `ctx.ui`。
- 每个异步动作必须 `busy` + 成功/失败 Toast；失败不得静默。
- 每个控制器返回 cleanup 函数；定时器、观察器、手动监听在 cleanup 里清理。
- 扫描轮询用 `AbortController` + 退避（400ms → 1s → 2s → 5s），页面卸载立即停止。
- 不在控制器里保存跨页全局状态；需要跨组件通信时用 `bus`，事件名格式 `domain:action`，如 `sync:updated`。

#### 2.4.6 页面控制器拆分

| 页面 | 控制器 | 主要职责 |
|---|---|---|
| image-rules | `pages/image-rules.js` | 压缩模式联动、裁剪扫描、扫描结果渲染 |
| image-thumb | `pages/image-thumb.js` | 提供方探测、缓存清理、尺寸表联动 |
| image-backup | `pages/image-backup.js` | 刷新占用、清理过期、清空备份、探测 |
| image-stats | `pages/image-stats.js` | 重置确认、历史分页、KPI 局部刷新 |
| storage-connection | `pages/storage-connection.js` | 新增配置、删除确认、连接测试、监控轮换/告警 |
| storage-sync | `pages/storage-sync.js` | 扫描状态机、筛选、分页、行操作、日志、配置弹窗、指标 |
| storage-deletes | `pages/storage-deletes.js` | 全选、丢弃确认、队列刷新 |
| files-settings | `pages/files-settings.js` | 树样式预览、策略开关确认、媒体图标 |
| files-users | `pages/files-users.js` | REST 用户表、容量编辑、分页 |
| files-queue | `pages/files-queue.js` | 申请列表、预览、批准/驳回、执行确认 |
| files-log | `pages/files-log.js` | 事件表、分页、筛选 |
| files-zibll | `pages/files-zibll.js` | 套餐合计、等级/VIP、支付方式、授权表、破坏性开关 |
| system-overview | `pages/system.js` | 仅间距表单增强；无 JS 也可用 |
| media-progress | `pages/media-progress.js` | 批处理轮询、进度、重试、完成态 |

同步中心与文件空间队列的表格渲染可以抽 `assets/ui/js/components/data-table.js`（可选），但不要为了复用把三套不同表硬塞进一个万能组件。

### 2.5 资源加载

新增 `src/Admin/Route.php`（避免与 `WP_Screen` 混淆）：

```php
final class Route {
    public static function current(): ?array {
        list( $module, $tab ) = Registry::current();
        if ( '' === $module ) {
            return null;
        }
        return array(
            'module' => $module,
            'tab'    => $tab,
            'id'     => $module . '-' . $tab,
        );
    }
}
```

新增 `src/Admin/Page_Assets.php`：

- 页面 ID → 样式文件、脚本文件、是否需要 layout、页面数据。
- 只在这里登记页面资源，模块不再各自 enqueue。
- 页面 ID 固定为 `module-tab`，与 `data-sakura-page` 一致。

`Assets::enqueue_page()` 新流程：

```text
Route::current()
  ├─ null → 返回
  ├─ 不在 UI_PAGES → enqueue_legacy()（迁移期临时分支）
  └─ 在 UI_PAGES
       ├─ enqueue_ui_assets(page_id)
       │    ├─ sakura-ui-tokens
       │    ├─ sakura-ui-base
       │    ├─ sakura-ui-components
       │    ├─ sakura-ui-page-{group}（image/storage/files/system/media）
       │    └─ sakura-ui-layout（仅页面有 [data-sakura-layout]）
       ├─ enqueue_ui_scripts(page_id)
       │    ├─ sakura-ui-js-core
       │    ├─ sakura-ui-js-api
       │    ├─ sakura-ui-js-ui
       │    ├─ sakura-ui-js-layout（按需）
       │    └─ sakura-ui-js-page-{id}
       └─ Page_Assets::localize(page_id)
```

迁移期：

```php
private const UI_PAGES = array(
    'image-rules', // Batch 0
    // 每完成一页，加入这里；阶段 6 删除整个常量和 enqueue_legacy()
);
```

旧栈分支只保留到最后一页迁移完成；它加载 `admin.css`、`admin-ext.css`、`admin-unify.css`、`admin-theme.css`、`admin-shell.css`、`admin-layout.css`、`admin.js`、`admin-layout.js`。新栈分支不加载其中任何一个。

资源加载规则：

- 每个模块的 `admin_enqueue_scripts` 只调用 `Assets::enqueue_page()`；删除各自的 `enqueue_style()`、`enqueue_layout()`、手工 `wp_enqueue_style()`。
- `Plugin.php` 的 999/1000 优先级钩子删除；不再有 `enqueue_unify` / `enqueue_theme`。
- `Shell::enqueue()` 删除；base.css 由 `Assets` 统一加载。
- `wp_localize_script` 只允许在 `Page_Assets` 与 `core` 配置处出现。
- 版本号统一由 `Assets::version_for( array $relative_paths )` 生成，`filemtime + filesize`；不再为每个文件写一个 `*_version()` 方法。
- 所有脚本 `in_footer = true`；所有样式 `media = 'all'`。
- `wp_set_script_translations()` 对每个页面脚本调用，域 `sakura-imgopt`。

### 2.6 目录结构对比

改造前（后台相关）：

```text
assets/
  admin.css
  admin-ext.css
  admin-unify.css
  admin-shell.css
  admin-layout.css
  admin-theme.css
  admin.js
  admin-layout.js
  userfiles-admin.css
  userfiles-admin.js
  userfiles-space-admin.css
  userfiles-space-admin.js
  admin-clouds.svg
  admin-sakura.svg
  ui/
    tokens.css
    components.css
```

改造后：

```text
assets/
  ui/
    tokens.css
    base.css
    components.css
    layout.css
    wp-media.css
    pages/
      image.css
      storage.css
      files.css
      system.css
      media.css
    js/
      core.js
      api.js
      ui.js
      layout.js
      pages/
        image-rules.js
        image-thumb.js
        image-backup.js
        image-stats.js
        storage-connection.js
        storage-sync.js
        storage-deletes.js
        files-settings.js
        files-users.js
        files-queue.js
        files-log.js
        files-zibll.js
        system.js
        media-progress.js
      wp-media.js
```

前台的 `userfiles.css`、`userfiles.js`、`userfiles-space.css`、`userfiles-space.js`、`editor-picker*.css/js`、`media-*.css/js` 不属于本方案，保持在原位；只把后台媒体列表/弹窗的集成样式迁到 `wp-media.css`。

---

## 3. 页面迁移计划

### 3.1 迁移顺序原则

1. 先做内核与最熟悉的 `image-rules`，验证组件、API、布局、截图流程。
2. 再迁图片模块其余三页：结构简单、回归面小，能快速暴露组件缺口。
3. 然后对象存储：先 `connection`，再 `sync`（最复杂），最后 `deletes`。
4. 文件空间放后面：四个 tab 共用视图，需要先拆 partial；`zibll` 表单最多，单独留到最后。
5. `system` 与 `media-progress` 最后：一个读多写少，一个是 upload.php 特殊页，不阻塞主流程。
6. 每批完成后，旧栈页面数量必须减少；不允许「新层也加载、旧层也加载」的页面。

### 3.2 Batch 0：内核 + image-rules 重做

范围：

- 新建 `base.css`、重写 `components.css`、新建 `layout.css`、新建 `pages/image.css`。
- 新建 `core.js`、`api.js`、`ui.js`、`layout.js`、`pages/image-rules.js`。
- 新建 `Route.php`、`Page_Assets.php`、`UI/Page.php`、`UI/Card.php`、`UI/Field.php`、`UI/Feedback.php`。
- 重写 `includes/views/settings-main.php`：`UI::page()` + 4 张 `UI::card()`，保留原有 option 与 `Card_Form`。
- 删除该页对 `admin.css` 的依赖；`Assets::UI_PAGES` 只放 `image-rules`。

验收：

- 图片规则页只加载 tokens/base/components/pages-image/layout + core/api/ui/layout/image-rules。
- 4 张卡片都能单独保存，保存后其他卡片 option 不被清空。
- 裁剪扫描、压缩模式联动正常；无 console error。
- 390 / 782 / 1440 三档截图通过；无横向滚动。
- 旧页面的样式与 JS 不受影响。

### 3.3 Batch 1：图片模块

#### image-thumb

- 9 卡、2 表单；重点是提供方状态、模式选择、命名样式映射、尺寸表、缓存/探测。
- 所有卡片改用 `UI::card()`；尺寸表用 `UI::table()` 或统一 `.table`。
- 把 admin.js 中的探测、缓存、尺寸表逻辑迁到 `pages/image-thumb.js`。
- 保留服务端表单提交；探测/清理按钮走 AJAX + Toast。
- 页面专属规则进 `pages/image.css`：`[data-sakura-page="image-thumb"] .thumb-*`。

#### image-backup

- 3 卡、1 表单；内联 jQuery 全部删除。
- 刷新占用、清理过期、清空备份、探测四个动作迁到 `pages/image-backup.js`。
- 清空备份用 `ui.confirm()`，文案必须说明不可恢复。
- 卡片内的计数/大小用 `data-sakura-role` 更新，不整页刷新。

#### image-stats

- 1 卡 + KPI；重置统计的两个 `onclick` 删除，改用 `ui.confirm()`。
- 历史分页从 admin.js 迁到 `pages/image-stats.js`；保留服务端分页 URL 作为无 JS 回退。
- KPI 数字用 `tabular-nums`；空数据用 `UI::empty_state()`。

验收：图片模块 4 页全部走新栈；旧 `admin.css` 不再在 `page=sakura-tools-image` 加载；所有保存/扫描/备份/分页动作通过。

### 3.4 Batch 2：对象存储

#### storage-connection

- 3 张主卡 + 监控面板；8 个 form。
- 新增配置按钮的内联脚本迁到 `pages/storage-connection.js`；用 `data-sakura-action="add-profile"`。
- 删除配置的 `onsubmit confirm` 改为 `ui.confirm()`，表单仍在无 JS 时提交。
- 连接测试、监控轮换、告警测试全部走 `api.ajax` + Toast。
- 配置卡里的密钥字段保持 `password`，不回显完整值；UI 不改变后端加密逻辑。

#### storage-sync（最高风险）

- 2 卡、1 表单，但包含扫描状态机、同步表、失败表、日志、指标、导出、配置弹窗。
- 控制器拆成函数：`initScan`、`followScan`、`loadPage`、`renderRows`、`rowAction`、`deleteRemote`、`toggleIndex`、`testConnection`、`export`、`openConfigDialog`、`loadMetrics`。
- 扫描轮询用 `api.ajax('sakura_s3_sync_scan_step')` + 退避；页面卸载 abort。
- 快速分页端点保留，失败时回退 admin-ajax；这是现有行为，不删。
- 所有行操作保留原 action 名与 nonce；只改调用方式与 DOM 渲染。
- 删除远端用 `ui.confirm()`；`REPLACE` 确认改为明确的「覆盖云端文件」文案。
- 日志/表格数据量大的部分继续用 `<script type="application/json">`，不要塞进 `wp_localize_script`。
- 页面专属规则进 `pages/storage.css`：同步表列宽、状态点、指标条、日志滚动区。

#### storage-deletes

- 1 卡、2 表单；全选与丢弃确认迁到 `pages/storage-deletes.js`。
- 全选使用 `data-sakura-role="select-all"` + `data-sakura-role="row-check"`，不依赖 `#sakura-select-all`。
- 丢弃按钮的 `onclick confirm` 改为 `ui.confirm()`。
- 队列为空时输出 `UI::empty_state()`，不再手写空行。

验收：对象存储 3 页全部走新栈；扫描/停止/继续/重试/测试/删除/导出/开关索引全部通过；无内联脚本；同步表在 782px 以下可横向滚动或卡片化。

### 3.5 Batch 3：文件空间

先拆视图：

```text
includes/views/files/
  settings.php
  users.php
  queue.php
  log.php
```

`UserFiles/Admin/Page.php` 的 `render()` 根据 `Registry::current()` 的 tab 选择 partial；四个 tab 分别对应 `files-settings`、`files-users`、`files-queue`、`files-log`。`zibll` 继续由 `Space_Page` 渲染，但视图改名为 `includes/views/files/zibll.php`。

#### files-settings

- 6 卡（按 tab 实际显示）、2 表单；树样式、文件夹配色、配额策略、主题上传限制、MIME 类型。
- 样式选择用 `UI::field( array( 'type' => 'radio', ... ) )`，保留左侧预览；配色用色块单选。
- 破坏性开关（如关闭入口、清空策略）用 `ui.confirm()`。
- 媒体库图标/主题上传限制的 JS 迁到 `pages/files-settings.js`。
- 注意：该页有多个 `settings_fields()` 与 hidden 字段；迁移时保留所有 hidden 值与 `name`，只换外层结构。

#### files-users

- 动态表；`pages/files-users.js` 使用 `api.rest('users')` 等现有 REST 路径。
- 容量编辑保留「留空 = 默认」语义；输入框用 `UI::field( array( 'type' => 'number', ... ) )` 风格，但表格内用紧凑 `.input--sm`。
- 分页用 `UI::pagination()`；空表用 `UI::empty_state()`。
- 不再用 `window.sufmAdmin`；配置走 `sakuraPage`。

#### files-queue

- 申请列表、预览、批准/驳回、执行确认。
- 执行确认必须保留「输入删除二字」的强确认；用 `ui.dialog()` 自定义 footer，不降级为普通 confirm。
- 预览范围用表格/列表；执行进度用 `UI::progress()`。
- 所有破坏性动作保留原 REST 路径与 nonce；只改渲染层。

#### files-log

- 事件表；分页/筛选；只读，无表单。
- 空状态与时间格式统一；不显示文件路径/对象键/凭证（现有隐私约束）。

#### files-zibll

- 10 卡、7 表单；套餐、等级、VIP、积分、支付方式、授权表。
- 套餐 MB 合计、等级/VIP 联动、支付方式勾选、授权表刷新迁到 `pages/files-zibll.js`。
- 破坏性开关（关闭入口、重置授权）用 `ui.confirm()`；保留服务端 nonce。
- 授权表用 `.table`；空表用 `UI::empty_state()`。
- 页面专属规则进 `pages/files.css`；不要继续用 `sufm-*` 前缀另起一套。

验收：files 四个 tab + zibll 全部走新栈；四个 tab 的页面 ID、CSS、JS 一一对应；所有保存、审核、执行、容量、套餐动作通过；文件空间前台页面不受影响。

### 3.6 Batch 4：系统、媒体、锁定页

#### system-overview

- 5 卡：运行时、引擎、检查项、扩展、卡片间距。
- 前 4 卡只读；检查项用 `.table` + `UI::badge()`；评分用 `UI::kpi()`。
- 间距卡是唯一可编辑卡：`UI::card()` + `UI::field( array( 'type' => 'number', ... ) )` × 2；表单仍提交到 `options.php`。
- 页面无专属 JS 也可用；`pages/system.js` 只做数字输入联动预览，可选。
- 该页最后迁，因为它证明「只读卡 + 单编辑卡」也能用同一套组件。

#### media-progress

- upload.php 的批量处理页；2 卡、内联 jQuery 轮询。
- 重写为 `pages/media-progress.js`：启动、轮询、进度、当前文件、成功/失败计数、重试、完成态。
- 保留原 AJAX action 与 nonce；不要改变批处理后端。
- 页面根节点仍是 `.sakura-admin`；但 WP 上传页的外层 `.wrap` 不动，只替换插件输出的部分。
- 防止重复启动：按钮 `busy` + 状态机 `idle/running/done`。

#### locked / missing

- `Shell::missing()` 与 `Menu` 的 403 空卡改用 `UI::empty_state()`。
- 不再输出 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`。
- 无 JS。

验收：13 个 Registry 页面 + media-progress + locked 全部新栈；后台任何页面不再加载旧 `admin*` 资源。

### 3.7 单页迁移配方

每页按同一顺序做，避免边改边猜：

1. **冻结行为**：列出该页所有 `form action`、option 名、AJAX action、nonce action、REST 路径、跳转 URL。
2. **留存基线**：1440/782/390 三张整页截图；记录控制台错误与网络失败。
3. **换壳**：`.sakura-sync-app` + `sky-layer` + `sakura-layer` + `.app` + `.hero` → `UI::page()`。
4. **换卡**：每张 `<section class="card">` → `UI::card()`；可编辑卡把 `<form>` 包进 `card__body`，保存条放表单末尾。
5. **换字段**：`.form-table` 行 → `UI::field()`；`name` 不变，`id`/`for` 同步。
6. **换表格**：`sufm-table`/`sakura-sync-table` → `.table-wrap > .table`；保留行数据属性，改用 `data-sakura-*`。
7. **换反馈**：`alert-strip` → `UI::alert()`；空行 → `UI::empty_state()`；`badge--*` 统一。
8. **迁 JS**：内联脚本/`onclick`/`onsubmit` → 页面控制器；所有请求走 `api`；所有确认走 `ui.confirm()`。
9. **切资源**：把页面 ID 加入 `UI_PAGES`；确认旧 CSS/JS 不再加载；删除该页旧 enqueue。
10. **验证**：静态检查、三档截图、所有动作、卡片保存互不覆盖、控制台零错误；提交并更新页面矩阵。

### 3.8 页面完成定义

一页只有同时满足以下条件才算完成：

- 根节点是 `.sakura-admin[data-sakura-page="..."]`，无 `sky-layer`/`sakura-layer`/`.app`/`.hero`。
- 页头由 `UI::page()` 输出；一个 `<h1>`；状态卡可选。
- 所有卡片由 `UI::card()` 输出；可编辑卡一卡一表单；只读卡无表单。
- 所有字段由 `UI::field()` 输出，或使用同一套 `.field` class；`name` 未变。
- 所有表格使用 `.table` 体系；无页面私有的 `*-table` 皮肤。
- 所有按钮/链接有可见焦点；破坏性操作走 `ui.confirm()`。
- 无内联可执行脚本、`onclick`、`onsubmit`、`onchange`。
- 页面只加载自己的 page CSS/JS；无旧 `admin*` handle。
- 1440/782/390 截图通过；无横向滚动、无文字重叠、无 11px 正文。
- 卡片保存后其他卡 option 不被清空；AJAX 动作成功/失败都有反馈。
- 控制台零 error；网络面板无 404；页面无 jQuery 依赖（WP 媒体除外）。

---

## 4. 阶段与验收门槛

| 阶段 | 范围 | 主要产出 | 退出条件 | 回滚 |
|---|---|---|---|---|
| 0 | 基线与护栏 | 截图脚本、静态检查脚本、页面矩阵、旧资产清单 | 全部后台页基线截图完成；检查命令可重复 | 无代码变更 |
| 1 | 内核 + image-rules | tokens/base/components/layout、core/api/ui、UI 组件、Route/Page_Assets | 规则页新栈通过；无 console error；4 卡保存 | 从 `UI_PAGES` 移除 `image-rules` |
| 2 | 图片模块 | thumb/backup/stats 控制器与页面 | 图片模块 4 页全部新栈；旧 CSS 不在 image 页加载 | 逐页移出 `UI_PAGES` |
| 3 | 对象存储 | connection/sync/deletes | 3 页全部新栈；扫描/删除/测试/导出通过 | 逐页移出 `UI_PAGES` |
| 4 | 文件空间 | 4 个 partial + zibll | 5 页全部新栈；审核/执行/套餐/容量通过 | 逐页移出 `UI_PAGES` |
| 5 | 系统/媒体/锁定 | system、media-progress、missing | 13+2 页全部新栈；旧栈只被未迁移页使用（应为 0） | 移出 `UI_PAGES` |
| 6 | 删除与收尾 | 删除旧文件/handle/方法/内联；文档；最终 QA | 旧资产零引用；全量验收通过 | git revert 本阶段 |

阶段 6 之前不得删除旧文件；阶段 6 之后不得再出现「临时兼容」分支。

---

## 5. 验证与验收

### 5.1 静态检查

每次提交前：

```bash
# PHP 语法
find src includes -name '*.php' -print0 | xargs -0 -n1 php -l

# JS 语法
for f in assets/ui/js/*.js assets/ui/js/pages/*.js; do node --check "$f"; done

# 旧资源引用（应为空）
rg -n "admin\.css|admin-ext\.css|admin-unify\.css|admin-theme\.css|admin-shell\.css|admin-layout\.css|admin\.js|admin-layout\.js" src includes

# 旧标记（应为空）
rg -n "sakura-sync-app|sky-layer|sakura-layer|page-savebar" src includes assets/ui

# 内联事件（应为空）
rg -n "onclick=|onsubmit=|onchange=" src includes

# 旧 handle（应为空）
rg -n "sakura-imgopt-admin|sakura-imgopt-admin-ext|sakura-imgopt-unify|sakura-imgopt-theme|sakura-imgopt-layout|sufm-admin|sufm-space-admin" src includes

# CSS 禁用项（应为空）
rg -n "!important" assets/ui
rg -n "#[a-zA-Z][a-zA-Z0-9_-]*\s*\{" assets/ui

# 页面控制器禁用项（应为空）
rg -n "\$\.ajax|\$\.post|fetch\(|window\.confirm|window\.alert" assets/ui/js/pages

# diff 空白
git diff --check
```

PHP 测试：

```bash
vendor/bin/phpunit
```

已知基线：`TranslationCoverageTest` 目前唯一失败在 `src/S3/Config_Notice.php:49/50/53`，与本重构无关。新增 UI 字符串不得增加新的翻译覆盖失败；若新增失败，必须在本阶段修掉。

### 5.2 视觉验收

每个页面三档视口：

- 1440×1000 桌面
- 782×1000 平板
- 390×844 手机

检查项：

- 无横向滚动（`document.documentElement.scrollWidth <= window.innerWidth`）。
- 页头标题、说明、状态卡不重叠；390px 下状态卡在标题下方。
- 卡片间距一致；最后一张卡底部有 40px 以上留白。
- 表单标签不与控件重叠；长说明不超过 60ch；输入框不被拉成整行 1200px。
- 表格在窄屏可横向滚动或卡片化；表头不压住内容。
- 按钮不换行成两行；危险按钮颜色只用于破坏性动作。
- 弹窗在 390px 下不超出视口；footer 按钮可点。
- 文字对比度：正文 `--g-ink` on `--g-card`；辅助文字 `--g-muted`；`--g-faint` 只用于非必要信息。

截图目录建议：

```text
tools/ui-qa/shots/
  before/{page}-{width}.png
  after/{page}-{width}.png
```

截图脚本与输出放 `tools/ui-qa/`，不进入插件发布包；发布打包时排除该目录（当前仓库没有 `.distignore`，需在发布脚本或打包清单中处理）。

### 5.3 交互验收

逐页 smoke：

- image-rules：切换压缩模式、扫描裁剪、保存 4 张卡。
- image-thumb：切换模式、探测、清理缓存、保存尺寸/样式。
- image-backup：刷新、清理过期、清空（确认框取消/确认各一次）、探测。
- image-stats：重置取消/确认、历史翻页。
- storage-connection：新增配置、切换配置、删除配置取消/确认、测试连接、监控轮换/告警。
- storage-sync：扫描、停止、继续、重试失败、筛选、翻页、上传/下载/删除单行、导出、开关索引、配置弹窗。
- storage-deletes：全选、反选、丢弃取消/确认、空队列。
- files-settings：树样式切换、配色、策略开关、保存。
- files-users：加载、翻页、修改容量、留空恢复默认。
- files-queue：加载、预览、批准、驳回、执行强确认（输入错误/正确各一次）。
- files-log：加载、翻页、空状态。
- files-zibll：套餐合计、等级/VIP、支付方式、授权刷新、破坏性开关取消/确认。
- system-overview：5 卡显示、间距保存、只读卡无保存按钮。
- media-progress：开始、进度、完成、失败重试、重复点击不重复启动。

每个动作检查：

- 成功 Toast 或局部更新；
- 失败时有可读错误，不静默；
- 按钮 loading 状态恢复；
- 不产生重复请求；
- 不刷新整页（除非表单提交）。

### 5.4 表单完整性

这是本重构最容易回归的部分。每页至少验证：

1. 只改 A 卡并保存，B/C/D 卡的 option 不变。
2. 连续保存 A、B、C 三次，最终所有值正确。
3. 只读卡没有保存按钮，也不会提交。
4. 多 tab 共享 option 的页面（files、zibll）保存一个 tab 不影响另一个 tab。
5. S3 配置保存不会清空未提交的字段。
6. 无 JS 时表单仍可提交（禁用 JS 或断网模拟）。

保留 `Card_Form::guard_options()` 的现有逻辑；如果新组件改变 `option_page`/`action`/`_wpnonce` 位置，必须重新验证。

### 5.5 可访问性

- 每页一个 `<h1>`；标题层级不跳级。
- 所有输入有 `<label for>`；提示用 `aria-describedby`；错误用 `aria-invalid`。
- 所有操作可用键盘完成；焦点可见；弹窗焦点陷阱与返回焦点正常。
- 表格有 `<caption>` 或 `aria-label`；排序/筛选有可读标签。
- Toast `aria-live="polite"`；错误信息不被仅颜色表达。
- 勾选框/单选框点击区域 ≥ 44px；18px 视觉尺寸不缩小。
- `prefers-reduced-motion: reduce` 下关闭过渡与动画。
- 颜色对比：正文 ≥ 4.5:1；大字号 ≥ 3:1；`--g-faint` 不用于关键信息。

### 5.6 性能与兼容

- 单页后台 CSS ≤ 90KB 未压缩；JS ≤ 90KB 未压缩（不含 WP 核心）。
- 无 jQuery 依赖（页面控制器）；WP 媒体弹窗除外。
- 首屏无布局抖动；表格/卡片不因 JS 初始化跳位。
- 扫描轮询使用退避；页面隐藏时暂停轮询（可选）。
- 兼容 WP 6.4+、PHP 8.2+；Chrome/Edge/Firefox 最近两个版本、Safari 15.4+。
- 不使用 `wp_enqueue_script_module`、CSS 嵌套、`@layer`、IE 兼容代码。
- 所有资源 200；无 404；无 console error/warning（WP 核心 warning 除外，需记录）。

---

## 6. 旧资产删除清单

> 2026-11-11：以下清理已全部执行。旧 CSS/JS/SVG 与 PHP 方法、钩子、旧 class、内联事件、全局变量均已删除；`assets/ui/` 中 `!important` 已清零。媒体样式保留为 `assets/ui/wp-media/`。

### 6.1 文件

后台旧文件，最终删除：

```text
assets/admin.css
assets/admin-ext.css
assets/admin-unify.css
assets/admin-shell.css
assets/admin-layout.css
assets/admin-theme.css
assets/admin.js
assets/admin-layout.js
assets/userfiles-admin.css
assets/userfiles-admin.js
assets/userfiles-space-admin.css
assets/userfiles-space-admin.js
assets/admin-clouds.svg
assets/admin-sakura.svg
```

保留/迁移：

- `assets/media-library.css`、`media-modal-folders.css`、`media-new-folders.css` → 合并/迁入 `assets/ui/wp-media.css`（或保留为 `assets/ui/wp-media/*.css`，但只允许 WP 媒体作用域）。
- `assets/space-package.svg` 先确认引用；若仅后台套餐卡使用，迁到页面 CSS 所需位置或保留为图片资源，不按旧 CSS 处理。
- 前台 `userfiles.css/js`、`userfiles-space.css/js`、`editor-picker*.css/js`、`media-*.css/js` 不删除。

### 6.2 PHP

删除：

- `Assets::enqueue()`
- `Assets::enqueue_style()`
- `Assets::enqueue_ui()`（合并进新 `enqueue_ui_assets()`）
- `Assets::is_ui_screen()`
- `Assets::enqueue_theme()`
- `Assets::enqueue_unify()`
- `Assets::ext_version()`、`theme_version()`、`unify_version()`、`version()`、`script_version()`、`layout_version()`（由 `version_for()` 取代）
- `Shell::enqueue()`
- `Plugin.php` 中 `admin_enqueue_scripts` 999/1000 的两个钩子
- 各模块中重复的 `wp_enqueue_style()` / `wp_enqueue_script()` / `wp_localize_script()`（迁到 `Page_Assets`）
- `UI::savebar()`（由 `UI::card()` 的 `save` 取代）
- 旧 `UI::hero()` / `UI::card()` 签名（重写后保留同名新签名，不保留旧分支）

### 6.3 标记与 class

删除：

- `.sakura-sync-app`（由 `.sakura-admin` 取代）
- `.sky-layer`、`.sakura-layer`
- `.app`（由 `.sakura-admin__main` 取代）
- `.hero`、`.hero-copy`、`.eyebrow`、`.lede`、`.hero-actions`、`.hero-status*`（由 `.page-head*` / `.status-card*` 取代）
- `.compact-cards`（由 `.card-grid` 取代）
- `.card-head`、`.card-body`、`.card-savebar`（由 `.card__head`、`.card__body`、`.card__savebar` 取代）
- `.section-kicker`（由 `.card__kicker` 取代）
- `.alert-strip`（由 `.alert` 取代）
- `.page-savebar`、`.floating-savebar`
- `.sufm-table`、`sufm-pager`、`sakura-sync-table`（由 `.table` / `.pagination` 取代）
- `#sakura-s3-save-form` 相关高特异性选择器
- `#sakura-*` / `#sufm-*` 作为 CSS 选择器

### 6.4 JS 全局与内联

删除全局：

- `window.sakuraSyncCenter`
- `window.sufmAdmin`
- `window.sufmSpaceCfg`
- `window.sakuraLayout`
- `window.__sakuraMasonryTimer`

删除内联：

| 位置 | 现状 | 新归属 |
|---|---|---|
| `includes/views/media-progress.php:115` | jQuery 批处理轮询 | `pages/media-progress.js` |
| `includes/views/settings-backup.php:138` | jQuery 刷新/清理/清空/探测 | `pages/image-backup.js` |
| `src/S3/S3_Storage_Page.php:231` | 新增配置按钮脚本 | `pages/storage-connection.js` |
| `src/S3/S3_Storage_Page.php:114` | 删除配置 `onsubmit confirm` | `pages/storage-connection.js` + `ui.confirm()` |
| `src/S3/Delete_Queue_Panel.php:325` | 全选脚本 | `pages/storage-deletes.js` |
| `src/S3/Delete_Queue_Panel.php:292` | 丢弃 `onclick confirm` | `pages/storage-deletes.js` + `ui.confirm()` |
| `src/S3/Monitoring_Panel.php:53` | URL 输入 `onclick select` | `pages/storage-connection.js` |
| `includes/views/settings-stats.php:43` | 重置统计 `onclick confirm` | `pages/image-stats.js` |
| `includes/views/settings-stats.php:72` | 重置统计 `onclick confirm` | `pages/image-stats.js` |
| `includes/views/s3-sync-center.php:705` | JSON 数据块 | 保留为 `<script type="application/json" data-sakura-data="...">` |
| `includes/views/userfiles-space-panel.php:272` | JSON 数据块 | 保留为数据块 |

### 6.5 删除证明

最终这些命令必须无输出：

```bash
rg -n "sakura-sync-app|sky-layer|sakura-layer|page-savebar|floating-savebar" src includes assets/ui
rg -n "admin\.css|admin-ext\.css|admin-unify\.css|admin-theme\.css|admin-shell\.css|admin-layout\.css|admin\.js|admin-layout\.js" src includes
rg -n "sakura-imgopt-admin|sakura-imgopt-admin-ext|sakura-imgopt-unify|sakura-imgopt-theme|sakura-imgopt-layout|sufm-admin|sufm-space-admin" src includes
rg -n "onclick=|onsubmit=|onchange=" src includes
rg -n "sakuraSyncCenter|sufmAdmin|sufmSpaceCfg|sakuraLayout|__sakuraMasonryTimer" src includes assets/ui
rg -n "!important" assets/ui
```

允许保留的：

- `<script type="application/json">` 数据块；
- `wp_localize_script()` 生成的配置脚本（不是手写内联逻辑）；
- WP 媒体弹窗对 `wp.media` / jQuery 的依赖（不在页面控制器内）。

---

## 7. 风险与应对

| 风险 | 影响 | 应对 | 验证 |
|---|---|---|---|
| 拆卡表单导致 option 被清空 | 数据丢失，最高风险 | 继续用 `Card_Form::guard_options()`；一卡一表单；每页做「只改 A 卡」测试 | 保存 A 后读 B/C/D option |
| 新旧 CSS 同时加载 | 样式不可预测 | `UI_PAGES` 白名单；每页只走一条分支；页面迁移后立刻移除旧 enqueue | 页面源码只出现一套 handle |
| WP 后台核心 CSS 冲突 | 按钮/表格/通知变形 | 全部规则以 `.sakura-admin` 作用域；不写全局 `body`/`.wp-core-ui`；必要时在 `wp-media.css` 做局部覆盖 | 与 `.notice`、`.button`、`.form-table` 同页检查 |
| JS 行为漂移 | 扫描/删除/保存失效 | 冻结 action 名、nonce、参数；逐动作 smoke；保留服务端表单回退 | 网络面板 action 名与旧版逐项对比 |
| 内联脚本删除后功能丢失 | 备份/批处理/新增配置失效 | 先迁移再删除；保留原函数逻辑；每个动作有成功/失败路径 | 逐页交互清单 |
| jQuery 移除影响 WP 媒体 | 媒体弹窗/文件夹选择器失效 | 页面控制器不用 jQuery；WP 媒体集成单独文件，继续用 `wp.media` | 打开媒体弹窗、选图、建文件夹 |
| `<dialog>` 兼容性 | 旧 Safari 无法打开弹窗 | 目标 Safari 15.4+ 原生支持；如线上仍有更低版本，改用 `div[role=dialog]` + 焦点陷阱，不引第三方库 | 浏览器矩阵 |
| 根节点从 `.sakura-sync-app` 改为 `.sakura-admin` | 布局脚本/表格标签失效 | 布局脚本同时支持两个根节点直到阶段 6；CSS 只认新根 | 拖拽、缩放、表格标签 |
| 资源缓存 | 用户看到旧 CSS/JS | 统一 `filemtime + filesize` 版本；不手写版本号 | 改文件后 URL 变化 |
| 翻译覆盖 | 新字符串未进 pot | 所有字符串 `__()`；JS 字符串走 `sakuraPage.strings`；跑翻译测试 | `vendor/bin/phpunit` |
| 页面私有 CSS 泄漏 | 改一页影响另一页 | 页面 CSS 必须带 `[data-sakura-page]` 前缀；评审检查 | 跨页截图 |
| 过度抽象 | 组件难用、页面代码更绕 | 只抽重复 3 次以上的模式；复杂表格允许手写同一套 class | 代码评审 |
| 范围蔓延到前台 | 风险扩大、无法收尾 | 明确排除 `userfiles*.css/js`、`editor-picker*`、`media-*` 前台逻辑 | 变更文件清单 |

---

## 8. 执行清单

### 8.1 每页迁移清单

- [ ] 列出 form/option/AJAX/nonce/REST/URL
- [ ] 截 1440/782/390 基线
- [ ] 换 `UI::page()` 根节点与页头
- [ ] 每卡换 `UI::card()`，可编辑卡一卡一表单
- [ ] 字段换 `UI::field()`，`name` 不变
- [ ] 表格换 `.table` 体系
- [ ] 反馈换 `UI::badge/alert/empty/progress/pagination`
- [ ] 内联脚本/事件迁到页面控制器
- [ ] 请求走 `api`，确认走 `ui.confirm()`
- [ ] 页面加入 `UI_PAGES`，旧 enqueue 删除
- [ ] 静态检查 + PHPUnit + 三档截图
- [ ] 卡片保存互不覆盖
- [ ] 控制台/网络零错误
- [ ] 更新页面矩阵与文档

### 8.2 每阶段清单

- [ ] 本阶段页面全部在新栈
- [ ] 旧栈页面数量减少到预期
- [ ] 无新增 `!important` / ID 选择器 / 内联事件
- [ ] 无新增 jQuery 依赖
- [ ] 资源体量在预算内
- [ ] 已知失败测试没有增加
- [ ] 提交信息写明批次与页面 ID

### 8.3 最终发布清单

- [ ] 13 个 Registry 页面 + media-progress + locked 全部新栈
- [ ] `UI_PAGES` 与 `enqueue_legacy()` 删除
- [ ] 旧 CSS/JS/SVG 文件删除
- [ ] 旧 handle/方法/钩子删除
- [ ] `.sky-layer`/`.sakura-layer`/`.hero`/`.page-savebar` 零引用
- [ ] 内联可执行脚本零引用
- [ ] `!important` 零引用（`wp-media.css` 例外需列明）
- [ ] `vendor/bin/phpunit` 通过（除已知基线）
- [ ] 全部后台页 × 3 视口截图通过
- [ ] 交互 smoke 全通过
- [ ] 文档 `docs/后台UI规则.md` 更新为最终规范
- [ ] 生产部署前在测试站完整回归
- [ ] 生产目录不直接修改；通过正常发布流程

---

## 9. 已定决策

1. **保留 `--g-*` 令牌名**：避免全量重命名，`tokens.css` 作为唯一来源。
2. **新根节点 `.sakura-admin`**：替代 `.sakura-sync-app`；布局脚本在迁移期兼容两者。
3. **使用原生 `<dialog>`**：目标浏览器支持；不引第三方弹窗库。
4. **不做构建链**：继续经典 IIFE + 文件依赖；不用 `wp_enqueue_script_module`。
5. **页面 CSS 按模块拆**：image/storage/files/system/media 五个文件，不把所有页面规则塞进 components。
6. **文件空间拆 partial**：settings/users/queue/log 四个视图文件，页面 ID 与资源一一对应。
7. **system 最后迁**：验证「只读卡 + 单编辑卡」路径。
8. **保留 WP 媒体集成独立层**：`wp-media.css/js` 不混入后台页面主栈。
9. **后端契约冻结**：option 名、AJAX action、nonce action、REST 路径、权限判断不改。
10. **先迁后删**：阶段 6 之前不删除旧资产。

---

## 10. 附录

### 10.1 页面 ID / 资源映射

| 页面 ID | CSS | JS | Layout |
|---|---|---|---|
| system-overview | pages/system.css | pages/system.js | 否 |
| image-rules | pages/image.css | pages/image-rules.js | 是 |
| image-thumb | pages/image.css | pages/image-thumb.js | 是 |
| image-backup | pages/image.css | pages/image-backup.js | 是 |
| image-stats | pages/image.css | pages/image-stats.js | 否 |
| storage-connection | pages/storage.css | pages/storage-connection.js | 是 |
| storage-sync | pages/storage.css | pages/storage-sync.js | 是 |
| storage-deletes | pages/storage.css | pages/storage-deletes.js | 是 |
| files-settings | pages/files.css | pages/files-settings.js | 是 |
| files-users | pages/files.css | pages/files-users.js | 是 |
| files-queue | pages/files.css | pages/files-queue.js | 是 |
| files-log | pages/files.css | pages/files-log.js | 是 |
| files-zibll | pages/files.css | pages/files-zibll.js | 是 |
| media-progress | pages/media.css | pages/media-progress.js | 否 |

### 10.2 旧 → 新 class 映射

| 旧 | 新 |
|---|---|
| `.sakura-sync-app` | `.sakura-admin` |
| `.app` | `.sakura-admin__main` |
| `.hero` | `.page-head` |
| `.hero-copy` | `.page-head__copy` |
| `.eyebrow` | `.page-head__eyebrow` |
| `.lede` | `.page-head__lede` |
| `.hero-actions` | `.page-head__actions` |
| `.hero-status` | `.status-card` |
| `.hero-status-top/label/main/sub/bar` | `.status-card__top/label/main/sub/bar` |
| `.compact-cards` | `.card-grid` |
| `.card-head` | `.card__head` |
| `.card-body` | `.card__body` |
| `.card-savebar` | `.card__savebar` |
| `.section-kicker` | `.card__kicker` |
| `.alert-strip` | `.alert` |
| `.sufm-table` | `.table` + `.table--files` |
| `.sufm-pager` | `.pagination` |
| `.sakura-modal` | `.modal`（原生 dialog） |

### 10.3 旧 CSS 内容去向

| 旧文件/区段 | 新归属 |
|---|---|
| admin.css 变量/基础/画布 | tokens.css + base.css |
| admin.css 顶栏/模块/tab | base.css |
| admin.css hero/状态卡 | base.css（page-head/status-card） |
| admin.css 卡片/网格 | components.css + layout.css |
| admin.css 按钮/表单/表格 | components.css |
| admin.css 扫描/尺寸/状态 | pages/image.css |
| admin-ext.css 页面补丁 | pages/*.css |
| admin-unify.css 圆角/字号/控件/勾选 | tokens.css + components.css |
| admin-shell.css | base.css |
| admin-layout.css | layout.css |
| admin-theme.css tokens/画布/chrome/hero/卡片/字体/按钮/表单/表格/扫描/保存条/移动端/可读性 | 拆到 tokens/base/components/pages；不保留覆盖层 |
| userfiles-admin.css | pages/files.css |
| userfiles-space-admin.css | pages/files.css |
| media-library.css | wp-media.css |
| media-modal-folders.css | wp-media.css |
| media-new-folders.css | wp-media.css |

### 10.4 旧 JS 内容去向

| 旧内容 | 新归属 |
|---|---|
| toast / request / fastPage | core.js + api.js + ui.js |
| 扫描轮询 / 停止 / 继续 / 重试 | pages/storage-sync.js |
| 同步表筛选 / 分页 / 行操作 / 删除远端 | pages/storage-sync.js |
| 批量选择 / 上传 / 下载 / 删除 | 对应页面控制器 + `ui/upload` |
| S3 测试 / 配置弹窗 / 指标 | pages/storage-connection.js |
| 压缩模式联动 / 裁剪扫描 | pages/image-rules.js |
| 备份刷新/清理/清空/探测 | pages/image-backup.js |
| 统计历史分页 / 重置确认 | pages/image-stats.js |
| masonry / 卡片布局 / 表格标签 | layout.js + ui.js |
| userfiles-admin.js 表格/审核/执行/容量/事件 | pages/files-users.js / files-queue.js / files-log.js / files-settings.js |
| userfiles-space-admin.js 套餐/授权/图标 | pages/files-zibll.js |
| 内联脚本（见 6.4） | 对应页面控制器 |

### 10.5 页面数据示例

同步中心：

```php
Page_Assets::localize(
    'storage-sync',
    array(
        'nonces' => array(
            'scan'    => $scan_nonce,
            'retry'   => $retry_nonce,
            'action'  => $action_nonce,
            'page'    => $page_nonce,
            'toggle'  => $toggle_nonce,
            'test'    => $test_nonce,
            'refresh' => $refresh_nonce,
            'cancel'  => $cancel_nonce,
            'resume'  => $resume_nonce,
        ),
        'urls'   => array(
            'settings' => $settings_url,
            'export'   => $export_url,
        ),
        'flags'  => array(
            'configured'   => $is_configured,
            'indexEnabled' => $index_enabled,
            'scanRunning'  => $scan_running,
            'scanResumable'=> $scan_resumable,
        ),
        'counts' => array(
            'localFiles'   => $local_files,
            'cloudObjects' => $cloud_objects,
            'synced'       => $synced,
            'pending'      => $pending,
            'failed'       => $failed_count,
        ),
        'strings' => array(
            'scanning' => __( '正在核对…', 'sakura-imgopt' ),
            'stopped'  => __( '已停止', 'sakura-imgopt' ),
            'failed'   => __( '操作失败，请重试。', 'sakura-imgopt' ),
            'confirmDelete' => __( '确定删除远端文件？此操作不可恢复。', 'sakura-imgopt' ),
        ),
    )
);
```

### 10.6 迁移期兼容策略

- `UI_PAGES` 是唯一的页面开关；不要在各模块里再写第二份 `is_ui_screen()`。
- 布局脚本同时识别 `.sakura-admin` 与 `.sakura-sync-app`，直到阶段 6；阶段 6 删除旧根支持。
- `Shell::header()` 输出不变；新页面用 base.css，旧页面用 admin-shell.css，互不依赖。
- 旧页面的 `.hero` 可以继续存在，直到该页迁移；不要为了统一而提前改旧页标记。
- 新组件只在新页面使用；不要为了「顺手」把旧页面局部替换成新组件又继续加载旧 CSS，那会制造第三种状态。

### 10.7 文档维护

- 本文件是迁移执行方案，阶段完成后在「状态」处更新。
- `docs/后台UI规则.md` 在阶段 6 重写为最终规范：只保留新架构、组件清单、class 约定、检查命令，不再描述旧栈。
- 每个 Batch 完成后更新第 1.1 节页面矩阵的「批次」列。
- 新增页面时必须同时登记 `Route`、`Page_Assets`、页面矩阵、`UI_PAGES`；缺一不可。

---

## 11. 一页纸结论

这次重构的核心不是「再设计一次」，而是**收回所有权**：

- 颜色和形状只在 `tokens.css`；
- 骨架只在 `base.css`；
- 组件只在 `components.css`；
- 页面差异只在 `pages/*.css`；
- 行为只在 `pages/*.js`；
- PHP 页面只做业务组合，不再手写卡片/字段/表格；
- 旧栈逐页退出，最后一页完成后整体删除。

按本方案执行，最终后台不再存在「改一个按钮要改四个文件」的情况，也不存在「这页为什么和那页不一样」的样式栈分歧。
