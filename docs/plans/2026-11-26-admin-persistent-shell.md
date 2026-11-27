# 后台持久壳 / 零刷新导航实验方案

> 状态：**Phase 1 已实施并在实验站验收**（2026-11-26，`1.29.0-beta-1`）。以 `system-settings` 为首，覆盖 `image-rules/thumb/backup/stats` 四个测试页签。
> 基线：`1.29.0`；实验默认关闭（option `sakura_imgopt_admin_app`），可一键回滚。
> **实施记录与勘误见 §17** —— 本文有四处与实测不符，先读那一节再按本文施工。

## 0. 结论

- **已确认**：接受 `system-settings` + 4 个 `image-*` 页签作为测试路由；**必须 React 拥有壳**；开关用 option。
- 推荐 Phase 1 采用 **PHP 首帧同构 HTML + `hydrateRoot` 接管壳 + React 内容根 + 客户端路由**：PHP 只负责首帧和 bootstrap，React 在 hydration 后拥有顶栏/页签/内容。
- 这不是「PHP 壳」也不是「React 从空白开始挂载」：首屏由 PHP 输出同构标记，React 立即 hydrate；只有标记一致，才能既不闪、又让 React 真正拥有壳。
- 系统设置页只有 1 个页签，**单页无法验收页签切换**；方案固定加入 `image-rules / image-thumb / image-backup / image-stats` 四个测试页签。
- 当前系统设置页已有 `System_Payload`、`/system` GET、`/system/layout` POST，且顶栏/页签由 PHP 输出；Phase 1 是在此基础上加 **React shell hydration + 导航层**，不是重做数据层。
- **长期目标**：插件页面输出统一为 React（共享壳 + 页内容）+ 统一 REST API；本方案是第一步，但不等同于一次性迁移 16 页。

## 1. 现状（只读梳理）

| 部件 | 当前实现 | 与实验的关系 |
|---|---|---|
| 页面入口 | `src/Admin/System_Settings_Module::render_system_settings()` | 保持；只增加 app 分支 |
| 首屏数据 | `System_Payload::build()` → `includes/views/system-settings.php` 的 inline JSON | 保持；增加 app 清单 |
| 壳 | `UI\Page::render()` 的 island 分支调用 `Shell::header()`，PHP 输出 `.sakura-topbar` 与 `.sakura-tabs` | **保持 PHP 首帧**，导航时只更新状态 |
| 内容 | `assets/ui/src/system/index.jsx` 挂 `.sakura-admin__main`，`app.jsx` 渲染卡片 | 改为可复用的路由内容 |
| 读 | `GET /wp-json/sakura-tools/v1/system` | 保留；新增通用页面路由 |
| 写 | `POST /wp-json/sakura-tools/v1/system/layout` | 保留；不改成 admin-ajax |
| 图片页 | `Image_Payload`、`assets/ui/src/image/app.jsx`、REST `/image/...` | 作为第二路由的测试对象 |

关键代码事实：

- `Page::render()` 的注释已经写明：顶栏和页签 printed once here and never touched again，React 只替换 `<main>`；这是为了避免挂载时整条 bar 重绘。
- `System_Payload` 已构造 `chrome`，但当前 `page` 参数没有把它传给 `Page::render()`；实验顺手修正，避免壳状态来自空数组。
- `System_Payload` 与 `Image_Payload` 都是服务端 payload builder；REST 读接口可以复用它们，不需要第二个 serializer。

## 2. 目标 / 非目标

### 目标

1. 在实验开关打开时，`system-settings` 与 4 个 `image-*` 路由之间切换**不产生整页 document navigation**。
2. `.sakura-topbar` 的 DOM 节点、品牌 SVG、模块导航不重新挂载。
3. 页签条、当前模块、页面标题、`data-sakura-page`、页面 CSS 作用域同步更新。
4. 首屏仍然是 PHP 直出：HTML 到达即有壳、有骨架、有真实 payload。
5. 默认关闭时，16 个后台页面行为与 1.29.0 完全一致。
6. 可回滚：关闭开关后全部回到普通链接整页导航。

### 非目标

- 不把 WordPress 左侧主菜单 React 化（那是 WP 核心，不属于插件）。
- 不改造 `options.php` / `admin-post.php` 原生表单、下载、OAuth、媒体库弹窗。
- 不引入 React Router、`@wordpress/data`、全局 store 或 SSR。
- 不一次性迁移其余 11 个页面。
- 不承诺零重渲染；只承诺壳节点不卸载、内容区可局部替换。

## 3. 实验范围

### 路由

Phase 1 只允许 5 个页面：

| page id | 模块 | 页签 | 用途 |
|---|---|---|---|
| `system-settings` | system | settings | 试点首页 |
| `image-rules` | image | rules | 第二路由，验证模块切换 |
| `image-thumb` | image | thumb | 验证页签切换 |
| `image-backup` | image | backup | 验证页签切换 |
| `image-stats` | image | stats | 验证页签切换 |

**为什么必须加 image 路由**：`system` 只有 1 个页签，只做系统设置无法验证 `.sakura-tabs` 的 active 状态、模块切换和页签条内容变化；实验结果不能证明「页签切换零刷新」。如果坚持只做 system，验收范围必须缩减为「壳持久化 + 内容区无刷新」，不能宣称页签切换通过。

### 开关

- **已确认**：新增 `sakura_imgopt_admin_app` 选项，`bool`，默认 `false`；演示站用 `wp option update sakura_imgopt_admin_app 1` 打开，清零即回滚。
- 不新增设置 UI；只作为实验开关，避免把半成品暴露给普通管理员。
- 只对 `manage_options` + 实验 allowlist 生效；不写用户 meta，不影响其他管理员。
- 卸载时加入 `Uninstaller` 清理；option 关闭时所有链接走现有整页导航。

## 4. 目标架构（React 拥有壳）

```text
初始整页请求
  PHP 输出同构 Shell HTML（topbar/tabs）+ 内容骨架 + system bootstrap JSON
        |
        v
  admin-app.js
        +- hydrateRoot(topbarEl, <TopbarContent chrome>)   // 壳由 React 接管
        +- hydrateRoot(tabsEl,   <TabsContent chrome>)     // 壳由 React 接管
        +- createRoot(mainEl).render(<PageApp boot>)       // 内容由 React 接管
        |
  点击插件导航
        +- 同 URL / 不匹配 allowlist / 修饰键 -> 放行整页导航
        +- 匹配路由 -> preventDefault
              +- history.pushState
              +- fetch GET /pages/{page}
              +- ShellRoots.update(chrome)  // React root.render，不换壳节点
              +- ContentRouter.render(page, payload)
                    +- 显示内容骨架
                    +- 挂载目标 App（system/image）
                    +- 失败 -> 错误面板 + 重新加载兜底
```

### 4.1 壳的归属：hydration，而不是直接 createRoot

Phase 1：**React 拥有壳，但首帧由 PHP 输出同构 HTML，React 用 `hydrateRoot` 接管**。

这是同时满足「首帧不白」与「壳由 React 拥有」的唯一低成本路径：

- 如果直接 `createRoot(topbar)`：React 先清空 PHP 的壳，再重新挂载；中间必然有一段空白/重绘，正是之前的闪动；
- 如果只留 PHP 壳、不 hydrate：壳永远不是 React 的，状态只能手工改 DOM；
- 如果全量 SSR：需要一个 Node 渲染服务，超出本次实验范围。

具体拆法：

- PHP 继续输出 `<header class="topbar sakura-topbar">` 与 `<nav class="sakura-tabs">`，但内部标记必须与 React 组件逐节点一致；
- React 提供 `TopbarContent` / `TabsContent`（返回 fragment，不包外层 header/nav），分别 `hydrateRoot` 到已有 `<header>` / `<nav>`；
- 内容区单独 `createRoot(.sakura-admin__main)`；PHP 首帧的骨架只是等待态，不参与 hydration；
- 路由切换时，两个壳 root 调 `root.render(<TopbarContent chrome={next}/>)` / `root.render(<TabsContent chrome={next}/>)`，React 只 diff 内部节点，外层 header/nav 不换；
- 品牌 SVG 由 PHP 和 React 共用同一段字符串（`Shell::header_data()['brand']['mark']`），hydrate 后不重新请求/解析；
- 页签条在模块切换时可能变化，但 `.sakura-tabs` 这个 nav 节点仍是同一个；内部 React 子节点 diff，不整块替换 DOM。

### 4.2 同构标记约束（必须写进测试）

- `TopbarContent` 与 `Shell::header()` 的 topbar 子结构必须一致：brand、`.sakura-mods`、`.top-actions`、status/actions、`aria-*`、`data-sakura-route`；
- `TabsContent` 与 `Shell::header()` 的 `.sakura-tabs` 子结构必须一致：`<a>`/`<span>`、`is-active`、`aria-current`、禁用页签锁图标与 `screen-reader-text`；
- 不允许用 `suppressHydrationWarning` 掩盖差异；hydration warning 视为验收失败；
- 增加 **Node 侧 SSR 快照**：用 React `renderToStaticMarkup(TopbarContent/TabsContent)` 生成 fixture，PHP 测试/构建脚本对比同一份 `chrome` 数据；标记归一化只允许空白差异；
- 如果 PHP 侧新增/修改一个 topbar/tabs 元素，必须同时改 React 组件与 fixture，否则测试失败。

### 4.3 为什么不是全量 SPA

全量 SPA 还需要处理：WP 左侧菜单、路由级 CSS/JS、原生表单、媒体弹窗、`options.php` 重定向、nonce、History、首屏 SSR。系统页试点不需要这些；先把**壳 hydration + 导航层**验证出来，再决定是否扩大。

## 5. 路由与数据契约

### 5.1 PHP 路由清单 App_Manifest

新增 `src/Admin/App_Manifest.php`，从 `Registry::modules()` / `Registry::tab_gate()` / `Page_Assets::ISLAND_BUNDLES` 生成：

```php
array(
  'enabled' => true,
  'nonce'   => wp_create_nonce( 'wp_rest' ),
  'endpoint'=> rest_url( 'sakura-tools/v1/pages' ),
  'routes'  => array(
    'system-settings' => array('module'=>'system','tab'=>'settings','url'=>Registry::url('system','settings'),'label'=>'环境自检'),
    // image-rules / image-thumb / image-backup / image-stats
  ),
)
```

约束：

- 只输出 allowlist 内、当前用户 `tab_gate()['capable']` 为真的路由；
- `url` 由 `Registry::url()` 生成，不手拼 `admin.php?page=`；
- 路由清单只用于实验，不能成为第二套信息架构；`Registry` 仍是唯一来源。

### 5.2 通用读接口

新增 `src/Admin/Rest/Page_Controller.php` + `Page_Routes.php`：

```text
GET /wp-json/sakura-tools/v1/pages/{page}
```

- `permission_callback` 复用 `App_Manifest::can_view( $page )`：allowlist + `tab_gate` + `manage_options`；
- `{page}` 只接受 `[a-z0-9-]+`，并从 allowlist 查表，绝不拼文件路径；
- 返回与首屏 bootstrap **同一份 payload**：`system-settings` 用 `System_Payload::build()`，`image-*` 用 `(new Image_Payload())->build($tab)`；
- 响应不缓存（nonce/权限/实时环境数据）；
- `Page_Routes` 必须无条件装配（`rest_api_init` 不能挂在 `is_admin()` 模块里），否则 `/wp-json/` 404。

### 5.3 payload 形状

所有路由统一至少包含 `page`、`head`、`cards`、`strings`、`rest`、`chrome`。

`System_Payload` 当前把 `chrome` 放在 payload 顶层，但 `page` 参数没有 `chrome`；实验顺手把 `chrome` 并入 `page`，或让 `Page::render()` 从 payload 取。

### 5.4 客户端路由状态

- `history.state = { page: 'image-rules', scrollY: 0 }`；
- 初始 `replaceState`；点击 `pushState`；`popstate` 重新取数；
- 同一路由重复点击：不取数、只滚动到顶部；
- `AbortController` 取消过期请求；
- 简单内存缓存 `Map<page, payload>`，保存成功后失效当前页；不做持久缓存。

## 6. 壳更新细节（React root.render，不手工改 DOM）

`ShellRoots` 持有两个 hydration root：`topbarRoot`、`tabsRoot`。路由切换时调用：

```js
topbarRoot.render( <TopbarContent chrome={ nextChrome } onNavigate={ navigate } /> );
tabsRoot.render( <TabsContent chrome={ nextChrome } onNavigate={ navigate } /> );
```

React 只 diff 内部节点，外层 `.sakura-topbar` / `.sakura-tabs` 节点保持同一个；页面级更新：

1. `.sakura-admin` 的 `data-sakura-page` 更新（页面 CSS 作用域依赖它）；
2. 内容 root 渲染目标 App；
3. `document.title`：`page.title + ' ‹ ' + siteName + ' — WordPress'`；
4. 焦点：`h1` 或 `main`，`focus({ preventScroll: true })`；滚动按 `history.state` 或顶部；
5. 状态/动作/active 都在 `chrome` 数据里，由 React 渲染；不再手写 `.is-active` 或 `innerHTML`。

明确不做：替换 `.sakura-topbar` 本身、重绘品牌 SVG、重建 `.sakura-admin` 根节点；不用 `suppressHydrationWarning` 掩盖不一致。

## 7. 内容区与 App 复用

### 7.1 新 bundle：admin-app

新增 `assets/ui/src/admin-app/index.jsx`，由 `bin/build-ui.mjs` 打成 `assets/ui/dist/admin-app.js`：

- 从 `shared/ui.jsx` 暴露 `TopbarContent` / `TabsContent`（fragment，不包 `<header>`/`<nav>`）；
- 导入 `system/app.jsx` 的 `App` 与 `image/app.jsx` 的 `App`；
- `hydrateRoot(topbarEl, <TopbarContent chrome={boot.chrome} onNavigate={navigate} />)`；
- `hydrateRoot(tabsEl, <TabsContent chrome={boot.chrome} onNavigate={navigate} />)`；
- `createRoot(mainEl).render(<PageApp page={...} boot={...} onNavigate={navigate} />)`；
- 初始 payload 来自 `sakura-system-bootstrap`（实验页）；后续来自 `/pages/{page}`。

Phase 1 为了验证，`admin-app.js` 同时包含 system + image 两个 App，体积会更大；这是实验成本，不进入默认路径。

### 7.2 现有入口

- `system/index.jsx` 默认仍用于非实验路径；实验路径由 `System_Settings_Module::enqueue_admin_assets()` 改为入队 `admin-app.js`。
- `system/app.jsx` 已经是 `App({ boot })`，无需改数据结构；
- `image/app.jsx` 已是 `App({ boot })`；实验只导入 `App`，不执行 image 的 `index.jsx` 自动挂载逻辑。
- `Topbar` / `Tabs` 现有组件保留为外层包装；新增 `TopbarContent` / `TabsContent` 只返回内部节点，供 hydration 使用，避免嵌套第二个 `<header>`/`<nav>`。
- PHP `Shell::header()` 与 `TopbarContent`/`TabsContent` 共用 `Shell::header_data()` 的同一份 `chrome`，标记必须逐节点对齐。

### 7.3 CSS

`Page_Assets::enqueue()` 当前只加载当前页的 `pages/{module}.css` / `pages/{page}.css`；从 system 切到 image 时 `image.css` 未加载，会闪样式。

Phase 1 两个方案：

- **推荐**：实验开关打开时预加载 `pages/system.css` 与 `pages/image.css`（多几 KB，换取无 FOUC）；
- 或路由清单带 `styles`，切换前 `<link>` 预加载完成再替换内容；Phase 2 再做。

必须验证 `data-sakura-page` 更新后，页面级选择器与 `.page-body` 布局仍正确。

### 7.4 表单与动作

- system layout 已走 REST；
- image 四个页签的动作已走 REST（`Image_Controller`）；
- Phase 1 路由只接受「所有动作均可 REST」的页面；遇到原生表单/下载/`admin-post.php` 链接直接放行整页导航，不拦截；
- 拦截规则：仅 `.sakura-mods a`、`.sakura-tabs a`、页面内 `[data-sakura-route]` 的插件链接；排除 `download`、`target`、修饰键、`data-sakura-no-spa`。

## 8. 首屏与加载态

1. 初始文档仍由 `Page::render()` 输出 PHP 壳 + 骨架 + inline bootstrap；
2. `admin-app` 只替换 `.sakura-admin__main`，壳不重绘；
3. 路由切换时，内容区先显示骨架/加载条，壳保持不动；
4. 失败时显示页内错误（保留壳），提供「重试」「整页打开」；
5. 403/登录过期：整页跳转到目标 URL，交给 WP 处理；
6. 不重新播放入场动画：沿用 `data-sakura-island` 的关闭策略，路由切换只做内容区短暂淡入或不加动画。

## 9. 文件清单

### PHP（新增/修改）

| 文件 | 动作 |
|---|---|
| `src/Admin/App_Manifest.php` | 新增：allowlist、路由清单、权限、payload 分发 |
| `src/Admin/Rest/Page_Controller.php` | 新增：`GET /pages/{page}` |
| `src/Admin/Rest/Page_Routes.php` | 新增：无条件装配 `rest_api_init` |
| `src/Admin/System_Settings_Module.php` | 开关、入队 `admin-app.js`、传 chrome |
| `src/Admin/System_Payload.php` | `page.chrome`、`app` 清单 |
| `src/Admin/Page_Assets.php` | 实验开关下预加载 `image.css`；不改变默认逻辑 |
| `src/Admin/Shell.php` | 给 topbar/tabs 链接加 `data-sakura-route`；输出必须与 React 同构组件逐节点一致 |
| `src/Admin/Registry.php` / `Route.php` | 只读复用 `page_id()` / `url()`；不改信息架构 |
| `includes/views/system-settings.php` | 可选：`data-sakura-app` 标记；bootstrap id 保持 |
| `src/Uninstaller.php` | 清理 `sakura_imgopt_admin_app` option |

### JS（新增/修改）

| 文件 | 动作 |
|---|---|
| `assets/ui/src/admin-app/index.jsx` | 新增：读 bootstrap、hydrate shell、create content root |
| `assets/ui/src/admin-app/app.jsx` | 新增：壳/内容路由容器 |
| `assets/ui/src/admin-app/router.js` | 新增：History、allowlist、AbortController、缓存 |
| `assets/ui/src/admin-app/shell.jsx` | 新增：`ShellRoots`（topbar/tabs hydrate + update） |
| `assets/ui/src/admin-app/transport.js` | 新增：`fetch /pages/{page}`、nonce、错误归一 |
| `assets/ui/src/admin-app/routes.jsx` | 新增：page -> App 映射 |
| `assets/ui/src/shared/ui.jsx` | 拆分 `TopbarContent` / `TabsContent`，供 PHP 同构与 hydration |
| `assets/ui/src/system/app.jsx` | 不改结构；确保可被导入 |
| `assets/ui/src/image/app.jsx` | 不改结构；确保可被导入 |
| `bin/build-ui.mjs` | 自动发现新目录；确认构建入口与 bundle 名 |

### 测试

| 文件 | 动作 |
|---|---|
| `tests/Admin/AppManifestTest.php` | 新增：allowlist、权限、page_id/url、路由数量 |
| `tests/Admin/PageControllerTest.php` | 新增：GET 权限、非法 page、payload 结构 |
| `tests/Admin/PageHeadTest.php` | 检查 chrome/page head 仍一致 |
| `tests/Admin/ViewManifestTest.php` | 检查默认路径不变 |
| `tests/Admin/ShellMarkupTest.php` | PHP shell 输出与 React SSR fixture 归一化对比 |
| `tests/Admin/AdminAppOptionTest.php` | option 默认关闭、权限、allowlist |
| 手工 Playwright 验收 | 见第 11 节；不新增 npm 测试框架 |

## 10. 实施顺序（新会话按此执行）

1. **快照/基线**：记录当前 16 页正常、system 首屏、image 四页；确认 composer test/phpstan/lint 基线。
2. **服务端契约**：`App_Manifest` + `Page_Controller` + `Page_Routes` + payload `chrome`；先写 PHPUnit，不碰 JS。
3. **壳同构**：拆 `TopbarContent` / `TabsContent`，PHP 输出对齐，Node SSR fixture + `ShellMarkupTest` 先通过；再 hydrate。
4. **App 复用**：从 `system/app.jsx`、`image/app.jsx` 导入 App；构建 `admin-app.js`；默认路径不切换。
5. **Shell 更新 + Router**：先实现同模块（image 四页签）无刷新，再实现 system 与 image 模块切换；壳用 `root.render`，不手工改 DOM。
6. **首屏/资源**：预加载 image.css，确认 `data-sakura-page`、页面标题、当前页签。
7. **开关**：option `sakura_imgopt_admin_app`；默认关闭；只对 allowlist；卸载清理。
8. **浏览器验收**：no full reload、hydration 0 warning、back/forward、失败回退、390/1440、键盘。
9. **默认关闭回归**：16 页全跑一遍，确认与 1.29.0 无差异。
10. **文档**：把实际决定补进 `03-后台UI` / `12-React代码规范` / `10-静态资源手册`，并清理过期注释。

## 11. 验收清单

### 功能

- [ ] 关闭开关：16 个页面完全走旧路径，无新增 JS/CSS 请求。
- [ ] 打开开关：从 `system-settings` 点击 image 模块 / 四个页签，URL 变化但不整页刷新。
- [ ] 首屏 hydration：console 无 hydration mismatch/warning；`suppressHydrationWarning` 未使用。
- [ ] `.sakura-topbar` / `.sakura-tabs` 的 DOM 节点在导航前后是同一个（MutationObserver/元素引用）。
- [ ] 品牌 SVG、模块导航不重新加载；页签 active 与 `aria-current` 正确。
- [ ] 浏览器后退/前进恢复页面与滚动位置。
- [ ] 直接打开/刷新 image 页面仍正常（普通整页路径）。
- [ ] 网络失败、403、无权限、未知 page：不会白屏；有重试/整页打开。
- [ ] 保存布局（system REST）与 image 页动作不重复提交、不丢 nonce。

### 性能/稳定

- [ ] `performance.getEntriesByType('navigation').length` 在导航后仍为 1。
- [ ] 切换时只有 `/pages/{page}` 一条新请求（不含图片/字体）。
- [ ] 无 console error/warning；无重复事件监听。
- [ ] 1440 与 390 宽度；键盘 Tab/Enter、Esc、焦点回到标题。
- [ ] 壳不抖：记录 topbar/tabs 的 `getBoundingClientRect()`，切换前后位置不变。

### 回归

- [ ] `composer test` 基线不变（当前 918/3889/6）。
- [ ] `composer phpstan`、`composer lint:strict`、`composer lint:compat` 0 错。
- [ ] `npm run build` 产物提交；`git diff --check` 干净。

## 12. 回滚

- 关闭 `sakura_imgopt_admin_app`；
- 所有 `.sakura-mods a` / `.sakura-tabs a` 恢复普通链接；
- `admin-app.js` 不再入队；`system.js` 原路径恢复；
- 不涉及表结构/数据迁移；option 关闭即可回滚，卸载时清理；
- 回滚后按 16 页冒烟即可，无需数据库恢复。

## 13. 风险与未决点

| 风险 | 处理 |
|---|---|
| 系统页只有 1 个页签，无法验证页签切换 | 实验加 image 4 个路由；否则缩减验收 |
| 模块切换要换 tabs，壳并非完全不变 | `.sakura-tabs` 外层节点保持；内部由 React diff；测量布局 |
| 路由级 CSS 未加载导致 FOUC | Phase 1 预加载 image.css；Phase 2 资源清单 |
| 两个 App 打进一个 bundle 变大 | 实验接受；Phase 2 按路由懒加载 |
| 原生表单/下载/OAuth 不能 SPA | 明确排除，链接放行整页 |
| nonce 过期/403 | 整页跳转，不自己处理登录 |
| `data-sakura-page` 作用域漏更新 | 路由切换后断言根节点属性与 CSS |
| **hydration mismatch** | 这是最大风险：ShellMarkupTest + Node SSR fixture + console 检查；禁止 `suppressHydrationWarning` |
| PHP/React 两份壳标记漂移 | 共享 `chrome` 数据；同一份 fixture 同时约束 PHP/React；改一处必须改两处 |
| 测试成本 | 先 PHP/Node 契约 + Playwright 手工；不引入前端测试框架 |

### 已确认的选择

1. 路由：`system-settings` + `image-rules / image-thumb / image-backup / image-stats`。
2. 壳：**React 拥有**；首帧 PHP 同构 HTML，React `hydrateRoot` 接管；不做纯客户端 `createRoot` 壳。
3. 开关：option `sakura_imgopt_admin_app`，默认关闭。
4. Phase 2 是否扩展到文件空间/S3：实施后再定，不在本方案承诺。

## 14. 明确不做

- 不重写 `options.php` 表单；
- 不把 WordPress 左侧菜单 React 化；
- 不引入 React Router / `@wordpress/data` / 独立 Node SSR 服务；
- 不一次性迁移 16 页；
- 不在默认路径上改变任何页面行为。

## 15. 一劳永逸的边界

这套实验能一劳永逸解决的是：**插件内部模块/页签切换的壳重绘和内容闪烁**。

它不能解决、也不应该假装解决：

- WordPress 核心菜单/文章/媒体页面的整页跳转；
- `options.php` 表单提交、下载、OAuth、媒体库；
- 首次进入后台的 JS/CSS 加载；
- 浏览器前进/后退之外的跨站导航。

如果实验验收通过，再把 route manifest + 资源清单扩展到其他 React 页面；是否扩展到全部 16 页、是否清退 admin-ajax，按第 16 节分阶段决定。

## 16. 统一 React + REST 的长期迁移路径

本次实验只覆盖 5 条路由，但契约按最终目标设计。目标是：**所有插件页面输出由 React 壳 + React 内容组成；所有页面数据由一个统一的 payload / REST 契约提供；所有写操作走 REST。**

### Phase 1（本方案）

- React shell hydration + 5 路由零刷新；
- 通用 `GET /pages/{page}` 读接口；
- option 开关，默认关闭；
- 现有 image/system REST 写接口不改。

### Phase 2（实验通过后）

- 把 route manifest 从 allowlist 扩到所有已迁岛页面（16 页）；
- 每个页面提供统一 `Page_Payload`（`page/head/cards/strings/rest/chrome`），首屏与 REST GET 复用同一个 builder；
- 路由级 CSS/JS 清单 + 按需加载，去掉“预加载两个页面”的实验捷径；
- 原生 `options.php` 表单只留明确的导航/设置 API 例外，其余逐步改 REST；
- 新写操作只加 REST；禁止新增 `wp_ajax_`。

### Phase 3（统一完成）

- 所有页面内容 React、壳 React；
- 所有页面读取走统一 payload，写操作走统一 REST；
- 旧 `assets/ui/js/pages/*.js` 与 PHP 页面视图仅作为 bundle 缺失回退，最终清理；
- 统一客户端 transport：REST 主通道、旧 AJAX 兼容适配器、原生表单例外；
- 统一错误/字段错误/nonce/403 处理；
- 统一权限映射：REST `permission_callback` 与页面 `tab_gate` 同源。

### 统一不等于一次重写

- 每个阶段都可独立开关/回滚；
- 先让新页面和试点页面符合契约，旧页面按“碰到再迁”处理；
- 不为纯一致性重写高风险表单/下载/OAuth；
- 只有当 Phase 1 的收益（无整页刷新、壳不闪、hydration 无警告）被实测确认，才进入 Phase 2。
## 17. 实施记录与勘误（2026-11-26，1.29.0-beta-1）

**状态：Phase 1 已实施，实验站 demo.77il.cn 验收通过；开关默认关闭。**

### 17.1 与本文的出入（四处，前两处是文档写错了）

1. **§4.2「标记归一化只允许空白差异」是错的。** 实测（Chromium + React 19 开发版）：两个标签之间的换行或缩进会被解析成一个文本节点，客户端树里没有它，React 直接抛
   `Hydration failed … - {"\n  "}`。所以 PHP 壳与 React 输出必须**节点级一致**，测试按 DOM 树逐节点比对（属性值按解码后比较，空白不豁免）。
2. **§1 把「image 四页签零刷新」当成待验证目标，实际上它已经存在。** `assets/ui/src/image/app.jsx` 早就会拦 `.sakura-tabs a`、走 REST 取 payload、用 `syncChrome()` 手工改高亮。Phase 1 真正补的是**模块间**切换与「壳归 React 所有」。为此给 image App 加了 `embedded` 开关：被壳托管时关掉它自带的 document 点击监听与 popstate 监听，否则两套导航会重复请求、回退时还会 `location.reload()`。
3. **chrome 传不进 `Page::render()` 的根因不是「漏传参数」。** `Shell::header_data()` 的 `status` 参数只接受字符串，而 payload 里 `chrome['status']` 是数组——真传进去会得到 `Array to string conversion`。现在 `header_data()` 两种形状都接受，并对状态文本做 `wp_strip_all_tags()`（React 按文本渲染，带标签就会两边不一致）。
4. **新增风险（本文没有）：hydration root 在完成前不能被 `render()`。** `hydrateRoot()` 是异步的，hydration 完成前回写同一个 root 会抛 **React #424（This root received an early update…）**，整棵树降级为客户端渲染——正好是壳要避免的重绘。首帧不能再写壳（PHP 已经印好同一份 chrome），只在导航时更新。

### 17.2 实际落地

- **PHP**：新增 `src/Admin/App_Manifest.php`（allowlist / 权限 / payload 分发）、`src/Admin/Rest/Page_Controller.php`（`GET /pages/{page}`）、`src/Admin/Rest/Page_Routes.php`（无条件装配）；改 `Shell.php`（无空白输出、`data-sakura-route`、status 两种形状）、`System_Payload.php`（`app` 清单）、`includes/views/system-settings.php`（chrome 并入 page 参数）、`System_Settings_Module.php`（开关决定入队哪个 bundle）、`Page_Assets.php`（开关下预载 `system.css` + `image.css`）、`Uninstaller.php`（清理 option）。
- **JS**：新增 `assets/ui/src/admin-app/{index.jsx,shell.jsx,router.js,transport.js,app.jsx}`（`routes.jsx` 没有必要，页面映射就在 `app.jsx`；`src/index.jsx` 合进 `index.jsx`）；`shared/ui.jsx` 拆出 `TopbarContent` / `TabsContent`；`image/app.jsx` 增加 `embedded`。
- **测试**：`tests/Admin/ShellMarkupTest.php` + `tests/fixtures/admin-shell.{json,html}` + `bin/admin-shell-{chrome.php,fixture.mjs}`（`npm run fixture` 重新生成，两个 fixture 都要提交）；`AppManifestTest` / `PageControllerTest` / `AdminAppOptionTest`；`tests/bootstrap.php` 补 `rest_url` / `rest_ensure_response` / `register_rest_route` / `get_bloginfo` / `WP_REST_Request` 桩。

### 17.3 验收结果（demo.77il.cn，1440 与 390 都看过）

- **开关开**：系统页 → 图片优化 → 缩略图 → 备份还原 → 统计 → 后退两次，`performance.getEntriesByType('navigation').length` 始终为 1；`.sakura-topbar` / `.sakura-tabs` 前后是同一个 DOM 引用；每次导航**只有一条** `/pages/{page}` 请求，没有新的 JS/CSS 请求；标题、`data-sakura-page`、active 页签与 `aria-current` 同步；`image.css` 预载生效。
- **保存**：系统页布局保存走 REST，两次保存都弹自定义提示、值回落原值，无整页导航；统计页切换每页条数只发一条 `/image/stats` 请求。
- **开关关**：只加载 `system.js`（无 `admin-app.js`、无 `image.css` 预载），5 张卡片正常；image / 对象存储 / 文件空间三个页面 0 console 错误。
- **console**：全程无 `#418` / `#423` / `#424`（React 生产版只报这几条，细节要开发版）。

### 17.4 复核后改掉的三处

独立复核（只读）发现两个 P1，都在导航层，已修：

1. **竞态：命中缓存时没有中止在途请求。** 原实现只在缓存未命中时 `abort()` 并递增代次；先点 A（在途）再点 B（命中缓存、同步完成）时，A 的响应回来仍能通过代次检查，把 URL 和内容拉回 A，还多推一条历史。现在**每次导航**都取消在途请求并递增代次。
2. **payload 缓存永不失效。** 保存走 REST、不重载文档，缓存命中会把保存前的值放回屏幕。Phase 1 的 5 条路由直接**去掉缓存**：一次本地 REST 调用换一个不会撒谎的表单；失效机制留到 Phase 2 和资源清单一起做。
3. **开关值的类型判断太松。** `(bool) 'false'` 为真，手工 `wp option update` 写错值会静默打开实验。现在只认 `1` / `'1'` / `true`。

顺带修的小项：fetch 15 秒超时（超时走整页回退，不再让 `aria-busy` 永久挂着）、`history.state` 合并而不是覆盖、同页后退恢复滚动位置、REST 端点兼容 `rest_route` 查询形式（非漂亮固定链接）、按 payload 的 `module` 分派内容 App、bundle 二次加载守卫。

### 17.5 已知边界（不是缺陷，验收时别当 bug）

- **WordPress 左侧菜单的高亮不会跟着壳切换**：菜单在壳外，零刷新只换插件内容区；要同步得动 WP 核心菜单，超出范围。
- **REST 路由在开关关闭时仍然注册**（方案 §5.2 的要求：`/wp-json/` 请求不是 admin 页面，挂在 `is_admin()` 里会 404），关闭时调用返回 403，不产生任何页面行为变化。
- **禁用页签（锁）分支没有跨渲染器 fixture**：Phase 1 首屏固定是 `system-settings`（1 个启用页签），该分支不会被 hydration；复核已手工核对 PHP 与 React 逐节点一致，回归护栏留到 Phase 2 扩路由时补。
- **没有缓存会让后退多一次 `/pages/{page}` 请求**，换来的是表单永不过期。

### 17.6 路由铺满 16 页（1.29.0-beta-2）

用户要求「和系统设置/图片优化一样，把路由完全改造好，暂不动页签内容」，所以这一轮只做导航层：

- **路由表 16 条**：`App_Manifest::ROUTES` 加了对象存储 5、文件空间 5、子比增强 1；`payload()` 按页分发到各自 builder。
- **门禁收紧**：`can_view()` 从 `capable` 改成 `capable && enabled`。子比两个页签受主题门禁，不收紧会把「当前主题不是子比」的页面也拿去取数。
- **manifest 改由 `Page::render()` 打印**（`#sakura-app-manifest`）：之前只有系统页的 payload 带 `app`，其它 15 页壳拿不到路由表。现在任何一个允许路由的首帧都有。
- **一个 bundle 装 16 个 App**：`admin-app.js` 461 KB（约 130 KB gzip），替掉「11 个页面各带一份 React」的 2.59 MB。
- **页面自己的 bundle 被压掉**：`System_Settings_Module::suppress_page_bundles()` 在 `admin_enqueue_scripts` 999 按 `assets/ui/dist/` 路径 dequeue，避免同一页下载两份 React。按路径匹配而不是按句柄，改名不会复活。
- **11 个入口加守卫**：`window.__sakuraShellBooted` 为真时不挂载（`files/shared/mount.jsx` 一处覆盖 files-users/queue/log 三页）。这层是兜底：正常路径下 bundle 已经被 dequeue。
- **CSS 预载**：`Page_Assets` 现在按 `App_Manifest::styles()` 载入 8 个模块/页面样式表（约 151 KB 原始），首屏就有跨模块样式，切页不闪。

验收（demo.77il.cn，开关打开）：16 条路由在**同一个文档**里逐条点过，每一步 `performance.getEntriesByType('navigation').length` 都是 1，页面 id / 标题 / 卡片数 / 页签条（含 `aria-current`）全部正确，console 0 错误；深链接直接打开 `storage-connection`、`files-queue` 也由壳接管且只加载 `admin-app.js`；关闭开关后回到 `system.js` / `storage.js` 各自入队、壳不启动。1440 与 390 都看过。

**这一轮明确没做**（按用户要求逐页处理）：5 个页面的 12 个原生 options.php/admin-post 表单、`storage-sync` 的 10 处 `location.reload()`、`storage-deletes` 自己写 `history.state`、`files-zibll` 的 `wp.media` 弹窗、以及各页保存后不刷新的改造。它们现在都能用，只是保存时会整页刷新。

### 17.7 留给 Phase 2

- 路由清单仍只 5 条；扩到 16 页前先把其余页面的写操作收口到 REST（`options.php` 表单、下载、OAuth 属明确例外）。
- 本方案用「预载 system.css + image.css」绕开路由级资源清单，Phase 2 换成按路由加载。
- image App 的 `embedded` 只是开关；如果更多页面 App 接入壳，考虑把「宿主是否拥有导航」做成统一 props/contract。

### 17.8 回退层已删除（1.29.0-beta-5）

用户要求后台不留兜底：宁可产物缺失时停在骨架屏，也不维护一套只在故障时才会跑到的第二实现。这一轮把实验期并存的两条路径拆掉，只剩岛。**本节是现状；本文 §1–§16 保留实验期的设计原貌，里面的 `system.js`、`ISLAND_BUNDLES` 等只代表当时形态。**

- **实验开关删除**：`sakura_imgopt_admin_app` 及其 option、卸载清理、关闭分支全部移除；`admin-app.js` 由 `Page_Assets::enqueue()` 对 16 个路由统一入队（只保留文件存在性检查，不再有第二份产物或开关分支）。
- **per-page bundle 删除**：13 个 `assets/ui/dist/{name}.js` 与对应的 `assets/ui/src/{page}/index.jsx` 全部删除；构建入口只剩 `assets/ui/src/admin-app/index.jsx`，`npm run build` 只产出 `assets/ui/dist/admin-app.js`（约 461 KB，开发期 gzip 约 130 KB）。
- **资源层判断删除**：`Page_Assets::ISLAND_BUNDLES`、`island_bundle()`、`island_owns()` 与「bundle 存在才入队」的条件全部移除。`assets/ui/js/pages/` 只剩 `media-progress.js`，由 `App_Manifest::is_route()` 保证只有非路由页加载传统脚本。
- **经典视图删除**：`settings-main.php`、`settings-backup.php`、`settings-stats.php`、`thumb-settings.php`、`s3-sync-center.php`、`storage-url-rewrite.php`、`storage-cost.php`、`userfiles-admin.php`，以及 12 个 `assets/ui/js/pages/*.js` 旧控制器。各模块 `render()` 里的「bundle 不可读 → 走 classic」分支一并删除；现在 `render_*()` 只有一条：权限守卫 → 建 payload → `require {page}-island.php`。
- **文档同步**：02 §6.3（唯一渲染路径）、03 §9.3/§12/§13（删掉「岛 vs 回退页」的两种写法）、07（旧存储脚本与视图按已删除理解）、10 §2/§3.3（唯一产物与资源映射）、12（目录树、现状盘点、历史段的旧 bundle 表述）。

**已知代价**：`assets/ui/dist/admin-app.js` 缺失（没跑 build、产物没提交、部署漏拷）时，16 个后台页全部停在「正在读取页面信息…」骨架屏，控制台是一条 404，**没有经典视图可回退**。这是刻意的取舍：第二套渲染器只在故障时被跑到，却要长期维护两套 DOM、文案与保存通道，两边迟早漂移；宁可让故障显性、可诊断，也不让它静默走一条没人验收的路径。

**部署清单变化**：

- 发布包仍只收已跟踪文件，`assets/ui/dist/admin-app.js` 必须提交；产物从 13 个 bundle 变成 1 个，漏掉它整站后台不可用。
- 实验站 `rsync` 命令不变（`--exclude /dist` 只排根目录 `dist/`，不会排 `assets/ui/dist/`），但要确认拷贝后 `wp-content/plugins/sakura-tools/assets/ui/dist/admin-app.js` 存在且大小约 472,007 B。
- 回滚不再有「关开关」这一档：要回到旧行为只能回退整份代码（含已删除的 bundle 与视图），部署前先备份旧目录。


