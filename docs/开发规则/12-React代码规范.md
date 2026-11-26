# 12 React 代码规范

后台的 React 内容区（PHP 外壳 + React 内容区混合渲染；1.29.0 起 16 个后台页面的内容区全部迁岛）怎么写。**先读 [03-后台UI](03-后台UI.md) §9.3 的分工表**——哪块归 PHP、哪块归 React 由那张表定；本篇只讲 React 这一侧怎么写。**本篇管写法，不管视觉**——样式规则在 [03-后台UI](03-后台UI.md)，资源加载在 [10-静态资源手册](10-静态资源手册.md)。

- 基线版本：`react@19.3.1` / `react-dom@19.3.1`（`package.json` 写 `^19.0.0`，实际解析到 19.3.1，2026-11-24 核对）、`esbuild@0.25.12`
- **为什么追最新 19**：插件把 React 打进自己的 bundle（`var SakuraUI`，不读 `window.React`），与 wp-admin 自带的 18.3.1 **互不共享**，所以不存在「同页两个副本版本打架」的问题；开发者 2026-11-24 决定以最新版为基准（推翻了当天早些时候「跟随 WordPress」的方案，原因见 `DECISIONS.md`）。代价是包体更大（19 版 `image.js` 254 KB vs 18 版 176 KB），换来 19 的写法（Actions、`ref` 当 prop、`<Ctx value>`）可用。
- 参考：[React v19 发布说明](https://react.dev/blog/2024/12/05/react-19)、[React 19.2 新特性](https://react.dev/blog/2025/10/01/react-19-2)、[React Compiler](https://react.dev/learn/react-compiler)（仍不用，见 §1）
- 适用文件：`assets/ui/src/**`（`shared/ui.jsx` 与各页面模块）

## 0. 先记住三条前提

这三条比任何通用 React 最佳实践都优先，因为它们决定了「什么不能做」：

1. **顶栏、页签条、页头、骨架由 PHP 渲染，React 永远不碰它们。** React 的挂载点是 `.sakura-admin > .sakura-admin__main`，只接管 `<main>` 里的内容。理由见 [03-后台UI](03-后台UI.md) §9.3：同模块页签切换不能整页刷新，而顶栏/页签在每个页签里完全一样，重建一次就是一次无意义重绘。
2. **引导数据是 PHP 写进文档的 JSON，不是接口。** `<script type="application/json" id="sakura-*-bootstrap">` 必须在挂载节点**之外**（`Page::render()` 的 `island` 分支负责），`index.jsx` 读它、`JSON.parse` 失败就显示失败态。REST 只用于**动作**（保存、扫描、翻页、重置），不用于首屏。
3. **服务端算出来的东西必须由服务端回传，不在前端猜。** 卡片上的「已保存值」「摘要文案」「派生字符串」（如 `固定文本 · Sakura`）都由 PHP 拼好；保存接口的响应要带回落库后的值，前端拿它覆盖本地状态。**前端复刻业务计算是禁止行为。**

## 1. 写法对照：旧 React → React 19

右列是**当前可用**的写法。左列在本仓库出现即视为缺陷。我们是 19.3，19 的能力都可用——但**「可用」不等于「要用」**，是否采用看 §5 与附录 A。

| 旧写法（不要用） | 现在这么写 | 备注 |
|---|---|---|
| `ReactDOM.render( … )` | `createRoot( node ).render( … )` | 18 起 `ReactDOM.render` 已删除 |
| 字符串 ref / `findDOMNode` | `useRef( null )` + `ref` prop | 18 已不可用 |
| `Comp.defaultProps = {…}` | 参数默认值 `{ size = 'md' }` | 函数组件 `defaultProps` 已废弃 |
| `PropTypes` | 不写运行时类型 | 校验在 PHP sanitize，前端只做 `Number()`/白名单 |
| `forwardRef( ( props, ref ) => … )` | 直接把 `ref` 当 prop 传下去 | 19 起 `ref` 是普通 prop，不用再包一层 |
| `<Ctx.Provider value={v}>` | `<Ctx value={v}>` | 19 起 Context 本身可当 Provider |
| `useFormState` | `useActionState` | 19 把 `useFormState` 改名成 `useActionState` |
| 手写 `saving` + `try/catch` | 优先 `useActionState` / 表单 `action` | 见 §5；跨页签迁移时逐个改，别一次全换 |
| `ref` 回调不返回值 | `ref` 回调可以返回清理函数 | 19 新能力；只在确实需要绑/解绑时用 |

**我们明确不用的东西**（不是「还没用」，是决定不用）：

- **不追 React 的新机制，但追新版本。** 版本以最新 19.x 为基准（见文首）；19 的新 API 用不用，看 §5 与附录 A 的逐条判断，不因为「新」就上。
- **不带 React Compiler。** 构建链是 esbuild，Compiler 需要 Babel/SWC 插件；引入它等于给打包加一整层依赖。因此**手动 memo 只在测量之后加**，不要先验地包 `memo`/`useMemo`。
- **不用 SSR / `hydrateRoot`。** wp-admin 里我们的 markup 由 PHP 拼、内容由 React 建，两者不是同一棵树，hydration 只会带来 mismatch。零重绘靠「React 不碰顶栏/页签」实现，不靠 hydrate。
- **不用 CSS-in-JS。** 令牌（`--g-*`）在 `:root` 上，React 同文档自动继承；改用 CSS-in-JS 或 Shadow DOM 会切断继承。
- **不用 UI 框架**（Ant Design / MUI 等）。见 DECISIONS 2026-11-14、2026-11-23。

### 1.1 升级 React 的流程

版本由 `package.json` 的两个 `^19.0.0` 决定（实际解析值见 §11）。升级按这五步，别跳：

1. `npm view react version` 看最新版；记下 `react` 与 `react-dom` 是否是同一版本（两者必须一致）。
2. 改 `package.json`（保持 `^19.0.0` 这种范围，或临时写死精确版本做对比），`npm install`。
3. `node bin/build-ui.mjs`，确认产物里 `O.version="<新版本>"`，记下 `image.js` / `system.js` 的新字节数。
4. 跑 §10 的自检 + 1440/390 的全部 16 个后台页面（至少覆盖系统设置、图像四页、存储五页、文件五页与子比增强）。
5. 改本文件文首的基线行、§11 的包体数字，再提交。**升级单独一次提交**，不要和功能改动混在一起。

> 附注：WordPress 7.1.4 自带的是 React 18.3.1（见 `wp-includes/js/dist/vendor/react.js` 的 `ReactVersion`）。我们不用它，因为 bundle 自包含；但如果哪天要改成读 WP 的那份，先读这个文件确认版本，再按「同页两个副本」的风险表逐项验证——2026-11-24 走过一遍，包体小 74/80 KB，随后被开发者否掉。

## 2. 组件与文件结构

```
assets/ui/src/
  shared/ui.jsx        ← 跨页面的呈现件：Card / Field / BusyLabel / Prompt / Badge / PageHead / …
  image/
    index.jsx          ← 唯一入口：找挂载点、读 bootstrap、createRoot
    app.jsx            ← 外壳：状态、页签切换、错误条、Prompt
    transport.js       ← 唯一 fetch 出口：call( rest, strings, path, body )
    rules.jsx thumb.jsx backup.jsx stats.jsx fields.jsx
  system/
    index.jsx app.jsx components.jsx boot.js
  storage/
    index.jsx          ← 同上，挂在 .sakura-admin[data-sakura-page^="storage-"] 的 <main>
    app.jsx            ← 外壳：保存 / 切换 / 删除 / 探针 / 风险确认，调度五张卡
    transport.js       ← 与 image 同签名，路由前缀 /storage
    dialog.jsx         ← 确认框、切桶提醒、多吉云空间选择与测试结果共用一套 .sakura-modal 标记
    profiles.jsx connection.jsx behaviour.jsx metrics.jsx monitoring.jsx
  sync/
    index.jsx          ← 挂在 .sakura-admin[data-sakura-page="storage-sync"] 的 <main>
    app.jsx            ← 外壳：扫描 / 续跑 / 刷新 / 取消 / 重试 / 同步记录开关 / 延迟探针，调度七张卡
    overview.jsx       ← 樱前线与五张 KPI 票根，数据全部来自 payload，不在前端算
    tasks.jsx health.jsx storage.jsx reconcile.jsx logs.jsx ignored.jsx notes.jsx
    dialogs.jsx        ← 复刻 Config_Notice 的 setup / paused / reconcile 三个引导弹窗
    transport.js       ← ajax() 直接转发 admin.api，reloadWithToast() 走 sessionStorage 跨重载
    format.js          ← 数字 / 字节 / 日期格式化
  deletes/
    index.jsx app.jsx live.jsx table.jsx transport.js
  rewrite/
    index.jsx app.jsx target.jsx scan.jsx preview.jsx run.jsx history.jsx status.js transport.js
  cost/
    index.jsx app.jsx transport.js
  files/
    index.jsx app.jsx form.jsx policy.jsx appearance.jsx color.js entry-gate.jsx
  files-users/ files-queue/ files-log/
    index.jsx app.jsx（+ sanction.jsx / caps.jsx）
  zibll/
    index.jsx app.jsx diagnostics.jsx payment.jsx entry.jsx gate.jsx bonus.jsx plans.jsx grants.jsx reconcile.jsx controls.jsx form.jsx
  performance/
    index.jsx app.jsx
  shared/
    ui.jsx
```

规矩：

- **一个页签一个文件**，文件名与 `Layout::PAGES` 的 tab 同名；`app.jsx` 只做外壳与调度，不写业务渲染。
- **`shared/ui.jsx` 只放跨页面复用的件**。本页专用件（`RuntimeTable`、`SizeGrid`、`KpiBoard`…）留在页面目录。把一次性组件塞进 shared 会把公共 API 变成垃圾场。
- **共享 `Card` 的卡头动作走 `actions` 槽**：`<Card card={card} actions={<button …>}>`，它渲染在 `<header>` 的 `.card__actions` 里，与 PHP `Card::head()` 同一个位置——有 `kicker` 时 CSS 自动下沉 20px 让按钮顶边与标题首行齐平，≤782px 落到标题下方整行右对齐（见 03 篇 `actions` 那条）。**不要把 `.card__actions` 写进 `card__body`**：那样按钮会跑到标题下方、正文顶部。对象存储「已保存的配置」卡 1.28.8-beta-43 修的就是这个——旧 PHP 页用 `UI::card( 'actions' => … )` 放在卡头，迁成 React 岛时掉进了 body。
- **冻结 / 锁定的图标只有一个来源**：用 `shared/ui.jsx` 的 `LockIcon`（与 PHP `UI::lock_icon()` 同一段 svg path、同一个 `viewBox`），放进 `.check__lock` 里，`title` 用 payload 的 `strings.lockTitle`。页面里不要手画第二个锁，也不要留 `<span className="check__lock" />` 这种空壳——它只有 22×22 的底色块，看上去就是「锁没了」。对象存储「上传行为」卡冻结行 1.28.8-beta-44 修的就是这个：旧 PHP 页输出 `UI::lock_icon()`，迁成 React 岛时只剩一个空格子，「这里不能改」读不出来。
- **组件默认导出只给 `index.jsx` 的挂载调用**；页面组件用命名导出，便于测试与引用。
- **props 来自 payload，不要自己命名一套。** `boot.cards.layout.fields` 就是渲染依据；加字段先改 PHP 的 payload，不在前端硬编码。
- 命名：组件 `PascalCase`，函数/变量 `camelCase`；与 PHP 侧共享的名字（`data-sakura-card`、`is-active`、`card__savebar`）**逐字对齐**，它们是契约。

## 3. 状态与数据流

一条铁律：**服务端是唯一事实来源，本地只有「草稿」和「已保存」两份。**

```js
const [ saved, setSaved ] = useState( boot.cards.layout.values ); // 服务端值
const [ draft, setDraft ] = useState( boot.cards.layout.values ); // 用户正在改的
const dirty = !same( draft, saved );
```

- 保存成功后：**用响应里的值同时覆盖 `saved` 与 `draft`**（服务端会 clamp，例如 `quality: 500 → 100`），再打开提示框。不要把提交值当作结果。
- 出现「服务端算的字符串」时（标签、摘要、预览名），两条路：
  1. 能由草稿算 → 在渲染时算（`section.fixed_prefix.replace( '%s', fixed )`）；
  2. 算不了 → **保存接口把新的 `section` 一起回传**，前端按卡号存进 `sections[cardId]`。
- **页面级派生数据也要跟着保存走**：页头状态卡（`head.status`）与卡片上方的告警（`payload.alerts`）都是服务端按 option 算的，`boot` 里那份只是开页时的快照。保存响应要带回重算结果，由 `App` 合并进 `head.status` / `payload.alerts`（缩略图页的 `onServerState`），否则翻开关后卡片还停在旧配色，要刷新才变（1.28.8-beta-39 修的就是这个）。
- **页头状态卡的新字段要过两关**：`Page::status_data()` 的白名单（键不在白名单里，`boot.head.status` 里就没有）与 `assets/ui/src/shared/ui.jsx` 的 `StatusCard`（PHP 与 React 是同一套标记的双份实现，改一处必须改另一处）。页头状态卡的徽章就是这样：PHP 侧 `Page::status()` 输出 `.status-card__top` + `.status-card__badge--{tone}`，React 侧同名同结构，规则见 03 篇 §5。
- 表格类要留 `baseline`：初始行单独存一份，用户改了再比较，保存成功后把 `baseline` 换成新行。不要拿「渲染用的行」直接比。
- **开关值不一定是布尔。** WordPress option 里可能是 `"0"`/`"1"`。payload 侧转 `(int)`，前端用 `isOn( value )`，**禁止 `Boolean( value )`**（`Boolean( "0" ) === true`，会显示成已启用而实际关闭）。
- 派生状态用渲染期算，不用 `useEffect` 同步：`const shownPolicy = probe ? derive( probe ) : savedPolicy`。`useEffect` 只用于外部系统。
- 跨页签的草稿不保留：页面组件挂 `key={ payload.tab }`，切页签即重挂载。

## 4. 副作用

`useEffect` 只做三件事：**订阅外部事件、同步到浏览器 API、清理资源**。数据获取放在事件处理器或动作里。

```js
useEffect( () => {
	const onClick = ( event ) => { … };
	document.addEventListener( 'click', onClick );
	return () => document.removeEventListener( 'click', onClick );   // 必须有
}, [] );
```

- 每个 `addEventListener` 都要有对应的 `removeEventListener`；每个 `setTimeout` 都要在清理里 `clearTimeout`（我们的 `Prompt` 就有一个 40ms 的聚焦定时器）。
- 依赖数组写全。不要用「空数组 + 读最新 props」的写法绕过闭包——真需要最新值就放进依赖，或用 `useRef` 镜像并在注释里说明。
- **不要在 effect 里 setState 做同步**（会造成二次渲染与闪烁）。能渲染期算的就渲染期算。

## 5. 动作（Actions）与表单

我们是 React 19.3，Actions 模型（`useActionState` / `useOptimistic` / async `startTransition` / 表单 `action`）**都可用**。但现有页面仍以手写 `saving` + `try/catch` 为主，这是历史写法，不是禁令：

- **新写的保存动作优先 `useActionState`**：`const [ state, action, pending ] = useActionState( save, null )`，把 `pending` 交给 `BusyLabel`、把 `state.notice` 交给 `Prompt`。省掉三份手写状态（`saving` / `error` / `notice`）。
- **老的别一次性全换**。真正的收益在于少写状态机；批量重写会掩盖回归。按页签逐个迁移，并在提交信息里写清「哪个页签换成了 Actions」。
- `useOptimistic` 只用于**可回滚的即时反馈**（翻开关、勾选）。我们的保存要等服务端 clamp 后回值，别用它演「已保存」。
- `startTransition( async () => … )` 在 19 里是合法的（异步 transition）；但它只影响「何时提交更新」，不减少请求、不改变视觉，别拿它当性能开关。
- 破坏性动作（重置统计、清空备份）用 `window.confirm( strings.resetConfirm )`，文案来自 payload，不在前端写死中文。
- 表单里 `name="action"` 的 hidden 输入是 WordPress 的约定，读 `form.action` 会拿到那个 input（OverrideBuiltIns）。**任何读表单属性的代码都要走 `getAttribute`。**

## 6. 性能

顺序永远是：**先测量，再优化，最后才 memo。**

- 默认不写 `useMemo` / `useCallback` / `memo`。我们的树很浅（一个页签 4–8 张卡），重渲染代价远低于维护成本。现在的 `useCallback`（3 处）是为稳定引用给子组件做依赖，属于合理个例。
- 真需要时按这个次序找原因：① 是不是把新对象/新数组当 prop 传给了纯组件；② 是不是列表 key 用了下标导致整列重建；③ 是不是在大列表里做同步计算；④ 最后才是 `memo`。
- **key 用业务 id**（`card.id`、`row.key`、`option.value`）。只有「静态字符串列表」允许用下标（我们 `field.hint` 的列表属于此类，已注明）。
- 长列表用分页（`Stats_Payload::PER_PAGE`），不做虚拟滚动——行数上限 100，虚拟化反而增加复杂度。
- **动画只动 `opacity` / `transform`**。绝不在首帧、保存、切页签时动 `height`/`margin`/`font-size`：那是布局位移，读者看到的就是「抖」。骨架屏的存在就是为了让挂载前后位置不变。
- 浏览器层面：React 的更新是「局部替换 DOM」，无法避免被替换区域的像素重绘。**能做到的上限是「不位移 + 只画变化区」**，不要承诺「零重绘」。

## 7. DOM 契约与可访问性

- **钩子属性**（后端也会读）：`data-sakura-page`、`data-sakura-card`、`data-sakura-layout`、`data-sakura-modal-open`、`data-sakura-return-target`、`data-sakura-ping`。新增钩子先确认没有更合适的现有属性。
- **类名复用 PHP 组件产出的那套**：`card` / `card__head` / `card__title` / `field` / `field__control` / `badge` / `btn btn--primary` / `card__savebar` / `check` + `check__text` + `check-grid`（勾选与开关）。前端另造一套等于把设计系统复制两份。
- **开关/勾选只有 `label.check` + `<input class="is-switch">` + `<span class="check__text">` 这一种写法**：间距、对齐、折行全由 `components.css` 的 `.check` 负责（`gap: var(--g-check-gap)`，10px）。自造类名（曾出现 `field__check`）在 CSS 里根本不存在，只是恰好作为 `.field__control` 直接子元素时被那条 `>` 选择器兜住；一旦放进 `.check-grid` 两列网格就完全失去样式，文字贴到开关上、长文案折到开关下方（1.28.8-beta-38 修）。
- **弹窗只能挂在 `.sakura-admin` 内**（CSS 选择器带该前缀），不能用 `document.body` portal；打开时写 `data-sakura-modal-open="1"`，关闭走 140ms 退场。
- 可聚焦元素：弹窗内 Tab 循环（首尾相接），关闭后焦点回触发元素，找不到就回落到卡片标题；一律 `focus( { preventScroll: true } )`。
- 表单控件必须有 `id`，`label[for]` 要指得到；自造 id 的拼法：`field.id` 优先，缺省 `sakura_imgopt_{键}`，多选组追加 `_{item.key}`。
- `dangerouslySetInnerHTML` **只允许**注入服务端已转义/已净化的字符串：品牌 SVG、`head.tagline`（`wp_kses` 过滤）、弹窗图标与吉祥物。其余一律用文本节点。
- 只读信息用 `role="status"`，失败用 `role="alert"`；装饰性骨架用 `aria-hidden="true"`。

## 8. 错误与加载态

- **文案必须中文**，且说清「发生了什么、怎么办」：网络失败 → 「网络请求失败，服务器没有响应。」；非 2xx → 「服务器返回 %d，请稍后重试。」；服务端 `message` 优先。
- 错误不吞：显示在卡片上方或页头下方，不要只 `console.error`。
- 按钮挂起态**不许改宽度**（用 `BusyLabel`，两层文案叠在同一格取较宽者）；挂起期间不要 `disabled`（会丢掉 hover 与焦点），用 `aria-busy` + 提交里防重入。
- 首帧失败时，骨架要被「失败提示」替换（`index.jsx` 里把 `.sakura-boot` 转失败色并改文案）；没有动作行的页把提示写进 `.page-body`。

## 9. 禁止清单

每条都对应一次真实事故，违反要返工。

1. `Boolean( value )` 判开关 —— `"0"` 会被当成开（1.28.8-beta-25）。
2. 读 `form.action` / `form.method` —— 被 `name="action"` 的 hidden 覆盖，`TypeError: action.indexOf is not a function`（beta-1）。
3. `placement` 传对象 —— 共享 `Card` 按三元数组解构，直接白屏（beta-16）。
4. 保存后不覆盖本地状态 —— 界面停在提交值，服务端 clamp 后不一致。
5. 用扫描快照盖过刚保存的结果 —— 勾选与状态列互相矛盾（beta-20）。
6. 前端复刻服务端计算 —— 早晚漂移，且注定漏掉 clamp。
7. 列表 key 用下标且列表可重排。
8. 在 effect 里 `setState` 做同步推导。
9. 直接操作 React 拥有的 DOM 节点（唯一例外：`app.jsx` 的 `syncChrome()` 按 URL 挪 `.sakura-tab` 的 `is-active`，因为页签条归 PHP）。
10. 在组件里写死 URL、nonce、option 名、字段 id —— 一律来自 payload；中文文案同样应读 `strings`，不要写成字面量（`|| '中文'` 兜底可以留，存量清单与补齐步骤见 §11.1）。

## 10. 提交前自检

```bash
node bin/build-ui.mjs          # 产物必须重建并提交 assets/ui/dist/*.js
php -l <改动的 PHP 文件>
node --check <改动的普通 JS>    # .jsx 由 esbuild 编译，无需 node --check
composer test && composer phpstan && composer lint:strict
git diff --check
```

浏览器里逐条过（开发者指定的环境）：

- [ ] 首屏：骨架在、挂载前后页头/卡片位置零位移（`data-sakura-island` 页面不该有 `main` 入场动画）
- [ ] 保存：不导航、`scrollY` 不变、卡片几何不变、弹窗出现且图标/吉祥物/关闭按钮齐全
- [ ] 保存后：输入框、状态列、摘要、页头状态卡**都**是落库值
- [ ] 页签切换：顶栏/页签节点引用不变、`performance` 的 navigation 条目仍为 1
- [ ] 键盘：Tab 不出弹窗、Esc 关闭、焦点回触发元素
- [ ] 1440 与 390 两个宽度：无横向溢出，控制台 0 error / 0 warning

## 11. 现状盘点（2026-11-26，1.29.0）

对 `assets/ui/src` 的实测统计，写在这里是为了让下一次改动知道「已经站在哪」：

| 项 | 数量 | 结论 |
|---|---|---|
| 文件 / 行数 | 85 个 / 13,758 行 | 16 个页面入口 + 13 个 bundle；storage 9、sync 13、deletes 5、rewrite 9、cost 3、files 11、files-users/queue/log 8、image 8、system 4、zibll 12、performance 2、shared 1 |
| `useState` | 214 | 集中在各页 `app.jsx` 与卡片的 draft/saved（含 import 计数） |
| `useEffect` | 62 | 全部带清理或只读依赖键（click / popstate / keydown / 定时器 / 值变化） |
| `useRef` / `useCallback` | 45 / 12 | 合理个例 |
| `useMemo` / `memo` / `createContext` | 3 / 0 / 0 | 仅为稳定引用与大列表渲染；未引入 Compiler |
| `forwardRef` / `PropTypes` / `defaultProps` / class 组件 | 0 | 无旧写法残留 |
| `dangerouslySetInnerHTML` | 7 | 全部是服务端字符串（品牌 SVG、tagline、弹窗图标、吉祥物） |
| 下标作 key | 6 | 全部是静态字符串列表（hint / warning / note / card.desc / 告警行） |
| React 19 Actions（`useActionState` / `useOptimistic`） | 0 | 19.3 已具备，现有页签仍是手写 `saving`；按 §5 逐个页签迁移，不批量重写 |
| 打包产物 | `files.js` 231 KB（236,601 B）/ `files-users.js` 237 KB（242,334 B）/ `files-queue.js` 240 KB（246,137 B）/ `files-log.js` 228 KB（233,044 B）/ `zibll.js` 254 KB（260,246 B）/ `performance.js` 227 KB（232,615 B）/ `image.js` 255 KB（260,969 B）/ `storage.js` 255 KB（260,837 B）/ `sync.js` 267 KB（273,702 B）/ `deletes.js` 241 KB（246,596 B）/ `rewrite.js` 239 KB（244,609 B）/ `cost.js` 228 KB（233,873 B）/ `system.js` 232 KB（237,637 B） | React 19.3.1 minified；18.3.1 版是 176/153 KB，差 74/80 KB，由开发者决定接受 |

结论：**没有需要立刻返工的旧版写法**；基线与 `package.json` 一致（`^19.0.0` → 19.3.1）。下一步真正有价值的是按 §5 把保存动作迁到 `useActionState`，而不是先加 `memo`。

### 11.1 待办：sync/ 下未走翻译的中文

「同步中心」迁岛（1.28.8-beta-48）时，为了先保证功能等价，大量文案直接写在 JSX 里。**功能正常**（都有中文，页面显示没错），但绕过了 PHP 的 __() 与 wp_set_script_translations，翻译文件里查不到这些句子。2026-11-25 实测 assets/ui/src/sync/ 共 13 个文件：

- **直接写死的中文 32 行**，分布在 app.jsx(10)、dialogs.jsx(3)、health.jsx(1)、ignored.jsx(4)、index.jsx(1)、logs.jsx(2)、overview.jsx(1)、reconcile.jsx(6)、storage.jsx(1)、tasks.jsx(3)。主要是六段提示条（停用 / 切换封存 / 兼容模式 / 核对进行中 / 超过 30 天未校准 / 差异告警）、三个引导弹窗的按钮、日志卡的导出与级别筛选、表格 aria-label、索引卡的「N 个文件」。
- **带 `|| '中文'` 兜底 43 行**，errors/兜底这类写法本身可接受（服务端没给键时仍有可读文案），但其中大部分键其实已经在 Sync_Payload::strings() 里提供了，等于双份维护。

补齐时按这个顺序做，别一次全改：

1. 先补 **aria-label 与按钮**（reconcile.jsx 的表格/分页 aria-label、dialogs.jsx 的三个按钮、logs.jsx 的导出链接）：它们出现在可访问性树里，读屏用户直接读到中文。
2. 再补 **提示条六段**（app.jsx 的 Notices）：句子最长、占位符最多（`%1$s` / `%2$s`），一次一段改并对着页面核。
3. 最后清 **死的兜底**：键已存在的，把 `|| '中文'` 去掉，只留 `strings.x`，让缺键在开发期暴露出来。

新增键一律加在 Sync_Payload::strings() 的对应分组里并带 translators 注释；改完跑 composer lint:strict（TranslationCoverageTest 会扫 PHP 侧中文）与 npm run build。前端硬编码中文 lint 抓不到，只能靠这一节人工比对。

### 11.2 删除队列岛（1.28.8-beta-55）

- **PHP 算完再下发**：`src/Admin/Deletes_Payload.php` 产出 counts / activity / running / rows / pagination / status；速率、ETA、下次到期、每行百分比都由服务端算，React 只渲染与倒计时。REST 在 `src/Admin/Rest/Deletes_Controller.php`（`GET /storage/deletes`、`POST /storage/deletes/{retry|drop}`）；导出仍走 admin-post，因为 WP REST 会把 CSV 字符串再编码成 JSON。
- **轮询只有一处**：`app.jsx` 里活动状态 4s、空闲 15s，连续失败按 ×2 退避到 30s；`document.hidden` 时暂停，`visibilitychange` 回来后立即拉一次。定时器在 effect 里 set/clear，不用 `setInterval` 叠请求；`load()` 同时只允许一个请求在飞。
- **操作不整页刷新**：retry / drop 的响应直接带回新 snapshot，`apply()` 合并；筛选与分页用 `history.replaceState` 写 `state` / `paged`，越界页由服务端夹回范围。
- **状态语义**：`done` 行保留 7 天（`Delete_Jobs::DONE_RETENTION_DAYS`），worker 每小时最多清一次；重试只覆盖 queued / failed / dead，**不重试 done**（同一 key 可能已被重新上传，重跑会删掉新对象）；已完成记录可导出 CSV。
- `Page_Assets::ISLAND_BUNDLES` 已登记 `storage-deletes => assets/ui/dist/deletes.js`，旧页脚本不再入队。

### 11.3 链接改写岛（1.28.8-beta-56）

- **PHP 侧两件事分开**：`src/Admin/Rewrite_Payload.php` 只组装 `page/head/cards/config`；`src/S3/Url_Rewrite_Scope.php` 只回答「当前活动配置的附件 URL 路径前缀是什么」。扫描、执行、预览、回滚仍然走原来的 `wp_ajax_sakura_url_rewrite_*`，所以兜底页和岛共用同一套游标、时间盒与账本，不新增 REST。
- **路径前缀优先级**：活动配置的 `upload_url_path` 有路径就用它的路径；没有路径才回落 `upload_path`。页面只读展示 `/{prefix}/` 与来源，不提供覆盖输入（旧桶前缀不同时，本轮明确不处理）。
- **URL 级而不是整值替换**：`Link_Rewriter` 构造器第三个参数是 `?string $path_prefix`；传 `null` 保持旧的全域名替换（老测试仍覆盖它），传字符串进入 scoped 模式。scoped 模式先匹配 scheme-qualified URL（`http://`、`https://`、`//`，含 JSON 的 `\/` 写法），再匹配裸 `host/path`；host 必须精确、path 必须以 `/{prefix}/` 开头、大小写敏感；命中的只替换 host，同一行里的其它链接原样保留。序列化数据仍走 `rewrite_value()` 的递归与长度修复。
- **扫描是粗筛，写库前精确匹配**：`Url_Rewrite_Service::scan_column()` 以旧域名 + 路径前缀（plain 与 JSON escaped 两种 LIKE）做候选集，多个 needle 必须是 `AND (a OR b)`；任务行数是候选行数，执行时 `Link_Rewriter` 再按 URL 精确判断，粗筛到但没命中的行计入「已跳过」。这个区别在页面上由执行卡的第一条 notes 说明。
- **状态带 scope**：`path_prefix` 写进 `sio_url_rewrite_state`；`scan_step / run_step / preview` 都从 state 取同一个前缀，续跑不会切到另一套规则；旧版本留下的 state 没有这个键时按 `null` 走兼容路径。
- **UI 文件**：`assets/ui/src/rewrite/` 的 app / target / scan / run / history / preview / status / transport；目标卡用共享 `Field` 渲染两个域名输入与 datalist，scope 用只读提示条；扫描卡负责选择与样例，执行卡负责统计与进度，历史卡负责回滚。`Page_Assets::ISLAND_BUNDLES` 登记 `storage-rewrite => assets/ui/dist/rewrite.js`，bundle 缺失时回落 `storage-url-rewrite.php` 与旧控制器（已实测）。
- **扫描工具栏**：`.rewrite-toolbar` 是带边框的面板，`.rewrite-presets` 内先放「快捷选择」标签，再放两个 `btn btn--ghost btn--small rewrite-preset`；**不要用 `btn--quiet`**——它在浅色卡片上会变成无边框胶囊，选项与背景融在一起。窄屏（≤600px）工具栏改成两行网格：第一行「全选 + 已选」，第二行「快捷选择 + 两个等宽按钮」。
- **空状态与自动列名**：`.rewrite-empty-cell` 必须带 `data-label=""`（React 岛和旧页兜底都要），否则 `assets/ui/js/ui.js` 的 `labelCells()` 会把第一列表头「选择/时间」自动补到 `colspan` 合并单元格上；≤782px 时该单元格 `display:block`、隐藏 `::before`，空状态 `min-height:168px` 铺满并居中。
- **测试**：`tests/LinkRewriterTest.php` 新增 scoped 用例（只改附件、保留其它路径/相似域名、JSON 转义、序列化长度、根前缀、编码前缀）；`tests/UrlRewriteScopeTest.php` 覆盖路径优先级、回退、规范化与 payload 形状。

### 11.4 费用估算岛（1.28.8-beta-56）

- **服务端定义表单**：`src/Admin/Cost_Payload.php` 把字段（key/id/type/label/hint/min/max/step/options）、首屏 `Cost_Estimator::estimate(defaults)`、价格基准、口径 notes 与去重后的来源链接一起下发；React 不再自己维护第二份字段表。
- **重新估算仍走 admin-ajax**：`app.jsx` 只改表单 draft，点「重新估算」把当前值发到 `sakura_storage_cost_estimate`，服务端 `Cost_Estimator::normalize()` 夹紧范围后返回同形状结果；前端用返回值覆盖结果并回填规范化后的输入。
- **表格与口径**：金额格式化在客户端完成（`Intl.NumberFormat('zh-CN')`，与 PHP `number_format` 的两位小数/千分位一致）；「口径」按钮控制每行的 detail 行，来源链接来自 payload 的 `sources`，notes 来自 `notes`，都不在 JSX 里重写。
- **无持久化**：这一步没有 option/表写入，刷新即回默认需求；`Page_Assets::ISLAND_BUNDLES` 登记 `storage-cost => assets/ui/dist/cost.js`，bundle 缺失时回落 `storage-cost.php` 与旧控制器（已实测）。

### 11.5 文件空间设置岛（1.28.8-beta-58）

- **数据与渲染分开**：`src/UserFiles/Admin/Files_Settings_Payload.php` 组装 `page/head/cards/config`，页面参数、入口提示、两张卡、策略字段、主题限制、允许类型与全部文案都在 PHP 侧完成；`includes/views/files-settings-island.php` 只打印 bootstrap 和空 `UI::page()` 外壳，React 通过 `assets/ui/src/files/` 挂载。
- **健康卡同源**：新增 `src/UserFiles/Admin/Health.php`，从 `Zibll_Bridge::capabilities()/theme()`、`Settings::entry_enabled()` 与 `Entry::status()` 取同一份快照。设置页显示「子比桥接 N/M 项能力 + 主题名 + 前台入口状态/入口标签」；子比专属页仍用同一组桥接数据，旧页兜底也改为 `Health::status()`。
- **保存不另造通道**：两张卡仍是原生 `options.php` 表单。外观卡提交 `tree_style` 与 `folder_accent`，策略卡提交数字字段、主题限制与允许类型；`Settings::sanitize()` 在收到 `sufm_settings[_partial]` 时先合并已存值。**这里必须用模块自己的 `_partial`，不是 `Card_Form::PARTIAL_FIELD` 的 `_sakura_partial`**，否则保存外观会清掉入口开关与主题限制（本轮实测踩到并修复）。
- **自定义文件夹颜色**：`folder_accent` 支持空 / 六个预设 / `#rrggbb`。`Settings::accent_class()` 对自定义色只输出 `sufm-accent-custom`，`Settings::accent_style()` 输出 `--sufm-folder-ink` 与由该色混白 28% 得到的 `--sufm-folder-fill`。前台用户中心、前台写文章编辑面板和媒体库三处都通过同一对变量取色；预设色仍走旧 CSS 类，不写内联样式。旧页外观卡也补了自定义色单选、取色器、hex 输入和实时预览，bundle 缺失时不会把自定义色写回空值。
- **界面**：外观卡保留原左右预览/选择布局与六个预设胶囊，新增自定义颜色行；390px 下改为单列，自定义取色、hex 与按钮换行但不横向溢出。策略卡沿用原分组、字段名与提示，主题限制和允许类型仍在原位置；策略卡头部不再放入口按钮，旧的入口迁移提示已删除。允许类型网格在 ≤782px 被全局规则压成单列时，用文件设置页自己的断点改回两列（390px 实测 139×2，卡片高度 2508→2264）。
- **兜底与资源**：`Page_Assets::ISLAND_BUNDLES` 登记 `files-settings => assets/ui/dist/files.js`；`Page::render()` 只在 bundle 可读时走岛，否则回到 `userfiles-admin.php` 与旧 `files-settings.js`。`Page::boot_height()` 增加 appearance/settings 的骨架高度，入口关闭时 gate 卡把两张卡整体下移一行。
- **验收**：1440 与 390 均无页面横溢、控制台 0 error；外观/策略分别保存且互不清空；自定义色保存后三处读到的 ink/fill 一致；预设 amber + mac 仍走原类且无内联变量；bundle 移除后旧页健康卡、表单与自定义色保存仍可用。

### 11.6 文件空间三页岛（1.28.8-beta-60）

- **一个 payload，三个入口**：`src/UserFiles/Admin/Files_Admin_Payload.php` 按 `files-users` / `files-queue` / `files-log` 输出 `page/head/cards/config`；页面参数复用 `Page::page_data()` 的 strings，不复制第二份文案。`includes/views/files-admin-island.php` 打印同一个 bootstrap id，三个入口分别打 `files-users.js` / `files-queue.js` / `files-log.js`，各自目录下共享 transport/Pager/空态。
- **业务逻辑零迁移**：用户容量、处罚、审核、预览、批次执行、停止、审计仍走原来的 `sufm/v1/admin/*`；React 只负责列表状态、按钮启用条件与刷新时机。权限矩阵仍是原生 `admin-post.php` 表单（`sufm_save_caps` + `_wpnonce` + 锁定管理员行），保存后按旧 notice 参数回流。
- **用户容量**：搜索、25/50/100 分页、刷新、同步容量（1.2s 轮询）、重置容量确认、每行 MB 输入与保存、用法条、处罚徽标与处罚弹窗。处罚弹窗保留服务端下发的类型/期限/理由/建议，永久禁上传必须输入登录名；撤销走 `admin/sanctions/{id}/revoke`。
- **审核与执行**：状态筛选、用户搜索、分页、批准/驳回/撤销、申请文件预览、已批准勾选、范围预览与哈希、短语 `删除`、建立批次、4s 批次轮询与停止。卡片顺序沿用 `Layout::PAGES['files-queue']`：执行卡与权限矩阵在第一行、申请队列在第二行；不要在 payload 里另写一份顺序。
- **最近操作**：服务端只返回当前页，结果/理由/文字筛选都在当前页内做，保持旧语义；分页与刷新回服务端。
- **验收**：1440/390 三页均无页面横溢、console 0 error；容量保存与清除、处罚弹窗打开/永久输入/申请与撤销、权限矩阵保存、日志筛选均实测；分别移除三个 bundle 后旧页与旧控制器仍可用。390 下用户表按 `data-label` 堆叠，权限矩阵在卡片内完整显示。

### 11.7 子比专属岛（1.28.8-beta-61）

- **10 卡 / 7 表单**：`src/UserFiles/Admin/Space_Payload.php` 把诊断、支付方式、前台入口、编辑器面板、门禁、等级/VIP 加成、套餐、授权流水、对账全部转成 `page/head/cards/config`；`assets/ui/src/zibll/` 负责渲染。只读卡不提交表单，7 个可编辑卡仍各自 POST 到 `options.php`，字段名、`option_page`、`_partial`、`allowed_methods_present`、`packages[_present]`、`points_packages[_present]` 与旧页一致。
- **动态交互留在前端**：套餐新增/删除行、图标媒体选择、等级/VIP 合计、等级规则禁用联动、开关确认、授权延长/撤销、对账 dry-run/apply；授权与对账继续走 `sufm/v1/admin/space/grants`、`.../extend`、`.../revoke` 与 `.../reconcile`，不新增接口。
- **纯岛页**：旧 `includes/views/userfiles-space-admin.php`、`assets/ui/js/pages/files-zibll.js` 与 `Space_Page::page_data()` 已删除；`Space_Page::render()` 只在 bundle 可读时走岛，bundle 缺失时显示「资源缺失」提示，不再保留旧设置页兜底。主题不可用时仍走原只读提示。
- **验收**：1440/390 无页面横溢、console 0 error；7 个表单逐个无改动保存后两个 option 的 JSON 与备份完全一致；开关关闭保存只改目标键；套餐新增保存后恢复备份；授权延长/撤销与对账检查实测后清理测试数据。

### 11.8 子比增强 · 性能优化岛（1.28.8-beta-63）

- **2 卡 / 1 表单**：`src/Zibll/Performance_Payload.php` 输出页头、授权缓存卡与状态卡；`assets/ui/src/performance/` 渲染。保存仍走原生 `options.php`，`sakura_imgopt_zibll` 的 `license_cache`、`duration`、`unit` 与 `_sakura_card` / `_sakura_partial` 全部不变。
- **交互留在前端**：单位选「永久」时禁用时长输入（禁用字段不提交，服务端沿用旧值）；「重新验证」继续调用 `wp_ajax_sakura_zibll_warm`，成功/失败状态与 toast 保持原行为；状态表的版本、缓存时间、来源、有效期、授权到期和下次复验仍由 PHP 预格式化。
- **纯岛页**：旧 `includes/views/zibll-performance.php` 与 `assets/ui/js/pages/zibll-performance.js` 已删除；bundle 缺失时显示资源缺失提示。页面 CSS 选择器逐个对照新 DOM，全部仍被使用，只删掉了不再生效的 `.zibll-duration .field { margin: 0; }` 冗余规则。
- **布局**：两张卡固定 6/6 等宽；状态卡改用 `table--records`，窄屏按记录行排布，值、胶囊与说明同行流式换行，胶囊不再独占一行。表格胶囊与值统一 `var(--g-check-gap)` 10px，系统设置页的运行时表也复用这条规则。
- **验收**：1440/390 无页面横溢、console 0 error；无改动保存后 option 逐字节一致；`forever` 保存与回读、开关关闭/重新启用、AJAX 重验均实测，随后恢复原 option。

## 附录 A　《React 性能优化实战：15 个核心技巧》逐条对照

来源：开发者提供的《React性能优化实战：从卡顿到丝滑，15个核心技巧覆盖全场景》（技术栈，转载）。**正文实际编号只有 13 条**（渲染 7 + 加载 4 + 内存 2），标题里的「15」把案例里的重复项与工具清单也算进去了；示例代码基于 `react-router`、`antd`、`react-window`，与本项目技术栈无关。下表按它的原编号逐条给结论，判据一栏是「什么时候可以推翻」。

| # | 文章主张 | 我们的结论 | 做法与判据 |
|---|---|---|---|
| 1 | `React.memo` 缓存子组件 | **用不上（默认不加）** | 页签 4–8 张卡，树浅；props 多为 payload 派生对象，浅比较基本失效。React 19 起有 Compiler 可自动做等价优化，手写更没必要。**启用门槛**：Profiler 里单卡一次渲染 > 16ms，或同一操作里同卡重渲 ≥ 3 次 |
| 2 | `useMemo` 缓存计算结果 | **改造后用** | 现有 1 处（既存 sync），本轮未新增。唯一允许的场景：对全量行做派生排序/过滤且行数 > 200。**禁止**为了「稳定引用」memo——那该由状态结构解决（§3） |
| 3 | `useCallback` 缓存函数 | **已在用（3 处）** | 只作为 `useEffect` 依赖或传给 `memo` 子件时才包；其余内联更可读 |
| 4 | key 用唯一稳定 id，不用索引 | **已在用（照做）** | `card.id` / `row.key` / `option.value`；仅 4 处静态字符串列表用下标并已注明。违反即返工（§9 第 7 条） |
| 5 | 拆分大型组件，缩小重渲染范围 | **已在用** | 一页签一文件 + 卡组件。当前最大风险点是 `assets/ui/src/image/fields.jsx`（各类型字段的渲染函数集中在一起，约 500 行）：再长就按 field type 拆文件，而不是靠 `memo` |
| 6 | `useContext` + `useReducer` 管全局状态，避免 props 透传 | **不用** | 现在 0 个 context。payload 从 `app` 逐层传到卡，深度只有 2–3 层；引入 context 后「哪张卡会重渲」变得不可预测。跨卡共享只有页头 status 与 usage KPI，`app.jsx` 两条 state 足够。`useReducer` 只在**单卡 ≥ 3 个互相约束字段**时考虑（典型是尺寸表的行 + 校验 + 脏值） |
| 7 | 虚拟列表（`react-window` / `react-virtualized`） | **不用** | 统计页上限 100 行且分页 25/50/100；虚拟化会破坏窄屏 `td[data-label]` 折行与打印 |
| 8 | 路由懒加载（`React.lazy` + `Suspense`） | **不用** | 后台每页只有一个 bundle，页签切换已是「不重载换内容」；懒加载等于多一次往返 |
| 9 | 组件懒加载（弹窗、抽屉等非首屏） | **不用** | 我们的提示框是 `Prompt`，几十行，且图标/吉祥物是服务端给的字符串；拆包收益为负 |
| 10 | 优化资源打包（按需引入 UI 库、gzip、图片 WebP + 懒加载） | **部分已在用** | 已做：esbuild `minify` + `define NODE_ENV=production`、单页单 bundle、SVG 内联。不做/不适用：没有 UI 库可「按需引入」；gzip 由 Web 服务器负责；后台没有大图可懒加载 |
| 11 | 预加载关键资源（preload / preconnect） | **有等价物，暂缓** | 我们的等价做法是「悬停/空闲预取页签载荷」（感知卡顿多在网络往返），2026-11-24 讨论后决定暂缓；`preload`/`preconnect` 对同源静态文件收益很小 |
| 12 | 及时清理定时器与事件监听 | **已在用** | 6 个 effect 全带清理（含 `Prompt` 的 40ms 聚焦定时器）；每个 `addEventListener` 都有对应 `removeEventListener` |
| 13 | 清理订阅与第三方库实例 | **已在用（当前为空）** | 没有图表/编辑器实例，没有 EventEmitter 类订阅；唯一的外部订阅是 `document` 上的 click / popstate / keydown 三处，都在 effect 清理里撤掉 |
| — | 实战案例：2000 行表格从 3.2s 降到 800ms | **不适用** | 我们的统计页是 100 行 + 分页，不在同一量级；真出现 2000 行，先分页，再考虑虚拟化 |
| — | 工具清单（DevTools Performance / Memory、react-window、react-lazyload…） | **部分已在用** | 用 React DevTools Profiler + Chrome Performance/Memory；本仓库另外固定了两种测法：rAF 逐帧采 `getBoundingClientRect` 找「谁在动」、`PerformanceObserver` 看 layout-shift（写法见 03 文档 §12） |

### A.1 文章没写、但我们踩过的六件事（比技巧清单更值钱）

1. **先测量，再优化。** 例：切页签抖动不是「重渲太多」，而是 `sakura-main-in` 在岛屿页被播放两次（`240 → 246 → 240`）；没有逐帧采样只能猜。
2. **布局位移优先于渲染次数。** 一次 `setState` 换来 1px 位移，比 10 次重渲更糟。保存条按钮的 1px `hover` 抬升、弹窗关键帧里的 `translateY` 都是这类问题，比任何 `memo` 都值得先修。
3. **服务端是唯一事实来源。** 保存响应必须回传落库值与派生字符串；否则页面停在旧值（「固定文本 · Sakura」不更新的那次）。
4. **可访问性不是性能之外的事。** Tab 不出弹窗、关闭后焦点回触发元素、`label[for]` 绑得住、挂起用 `aria-busy` 而不是 `disabled`——这些做错，用户会觉得「卡」。
5. **首帧属于服务端。** bundle 还没到时 React 不在场，CLS 只能由 PHP 的骨架保证（§附录 B 见 03 文档 §9.4）。
6. **版本与产物纪律。** 构建产物必须提交、版本三处一致、升级单独一次提交；这三条不遵守，性能问题会被「到底跑的是哪份代码」掩盖。

### A.2 与官方文档的对照（原文已核，不是转述）

那篇文章的结论要拿 react.dev 原文校验。注意 react.dev 的页面是客户端渲染的，直接抓 HTML 只能拿到侧栏；**要读正文得用文档仓库里的 Markdown 源**（`reactjs/react.dev` 的 `src/content/reference/react/*.md`）。以下是核过的原文（2026-11-24 取自 `main` 分支）：

**① `memo` 的定位是「优化」不是「保证」**（[memo.md](https://github.com/reactjs/react.dev/blob/main/src/content/reference/react/memo.md)）：

> But React may still re-render it: memoization is a performance optimization, not a guarantee.
>
> **You should only rely on `memo` as a performance optimization.** If your code doesn't work without it, find the underlying problem and fix it first.

**② 官方直接把我们这类应用归为「不需要到处 memo」**（`memo.md` 与 `useMemo.md` 的 DeepDive *Should you add memo everywhere?*，两处文字几乎相同）：

> If your app is like this site, and most interactions are coarse (like replacing a page or an entire section), memoization is usually unnecessary.

我们的交互正是「替换一整页/一整块」（页签切换、保存一张卡）——这就是附录 A 里 5 条标「不适用」的原文依据，不是我们的主观判断。

**③ 官方给「贵不贵」的量法，和我们的做法一致**（`useMemo.md`）：

> unless you're creating or looping over thousands of objects, it's probably not expensive. […] If the overall logged time adds up to a significant amount (say, `1ms` or more), it might make sense to memoize that calculation.

以及一条容易忽略的：

> `useMemo` won't make the *first* render faster. It only helps you skip unnecessary work on updates.

**④ 19 起的 Compiler 会接管这件事**（`memo.md` 开头 Note）：

> [React Compiler](/learn/react-compiler) automatically applies the equivalent of `memo` to all components, reducing the need for manual memoization.

我们目前没上 Compiler（esbuild 链，需要 Babel/SWC 插件），但这决定了「将来要不要手写 memo」的答案会变成「不用」。

### A.3 官方那五条原则，逐条对照我们的代码

`memo.md` 的 DeepDive 末尾给了五条「让 memo 变得不必要」的原则，比技巧清单有用得多：

| 官方原则（原文摘要） | 我们的现状 | 结论 |
|---|---|---|
| 包装型组件接收 JSX 作为 children，这样父组件自身 state 变化时子组件不必重渲 | `Card`、`PageHead`、`Topbar`、`Tabs`、`Prompt` 都收 `children` | **已在做** |
| 状态尽量留在局部，别把表单、hover 这类瞬时状态提到树顶或全局 | **没做到**：`app.jsx` 持有整个页签的 `draft`，每敲一个字符整页重渲 | 我们的规模（单卡 ≤ 13 个字段、页签 ≤ 8 张卡）实测没有可感知延迟，所以**暂不改**；但如果哪天卡变多，第一件事是把 `draft` 下移到卡内部，而不是加 `memo` |
| 渲染逻辑保持纯；重渲产生可见异常是 bug，先修 bug | §9 禁止清单第 8 条（effect 里 setState）与「不直接改 React 拥有的 DOM」都在守这条 | **已在做**；唯一例外是 `syncChrome()`（改的是 PHP 拥有的页签条），已在代码注释里写明理由 |
| 避免「effect 里 setState」形成的更新链——官方称这是 React 应用性能问题的主要来源 | 6 个 `useEffect` 里 0 个调用 setState（全是订阅/定时器/焦点） | **已在做** |
| 减少 effect 依赖：能用「把对象/函数移进 effect 或移到组件外」解决的，不要用 memo | 同理 | **已在做** |

> 原句（`memo.md`）：*Most performance problems in React apps are caused by chains of updates originating from Effects that cause your components to render over and over.*

这条对我们尤其重要：我们是「保存后整块换 state」的页面，一旦有人在 effect 里 `setState` 同步派生值，就会立刻变成「渲染→effect→setState→渲染」的循环。§9 那条禁止清单就是为此写的。
