# 12 React 代码规范

后台的 React 岛屿（系统设置、图像优化四页签）怎么写。**本篇管写法，不管视觉**——样式规则在 [03-后台UI](03-后台UI.md)，资源加载在 [10-静态资源手册](10-静态资源手册.md)。

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
4. 跑 §10 的自检 + 1440/390 的七个页面（系统设置 + 图像优化四页签 + 任选一个 PHP 直出页对照）。
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
```

规矩：

- **一个页签一个文件**，文件名与 `Layout::PAGES` 的 tab 同名；`app.jsx` 只做外壳与调度，不写业务渲染。
- **`shared/ui.jsx` 只放跨页面复用的件**。本页专用件（`RuntimeTable`、`SizeGrid`、`KpiBoard`…）留在页面目录。把一次性组件塞进 shared 会把公共 API 变成垃圾场。
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

我们是 React 19.3，Actions 模型（`useActionState` / `useOptimistic` / async `startTransition` / 表单 `action`）**都可用**。但现有四个页签仍是手写 `saving` + `try/catch`，这是历史写法，不是禁令：

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
- **类名复用 PHP 组件产出的那套**：`card` / `card__head` / `card__title` / `field` / `field__control` / `badge` / `btn btn--primary` / `card__savebar`。前端另造一套等于把设计系统复制两份。
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
10. 在组件里写死中文以外的东西：URL、nonce、option 名、字段 id —— 一律来自 payload。

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

## 11. 现状盘点（2026-11-24）

对 `assets/ui/src` 的实测统计，写在这里是为了让下一次改动知道「已经站在哪」：

| 项 | 数量 | 结论 |
|---|---|---|
| 文件 / 行数 | 13 个 / 3,224 行 | 规模可控 |
| `useState` | 35 | 集中在 `app.jsx` 与各页签的 draft/saved |
| `useEffect` | 6 | 全部带清理（click / popstate / keydown / 定时器） |
| `useRef` / `useCallback` | 3 / 3 | 合理个例 |
| `useMemo` / `memo` / `createContext` | 0 / 0 / 0 | 未过早优化，符合本文 |
| `forwardRef` / `PropTypes` / `defaultProps` / class 组件 | 0 | 无旧写法残留 |
| `dangerouslySetInnerHTML` | 4 | 全部是服务端字符串（品牌 SVG、tagline、弹窗图标、吉祥物） |
| 下标作 key | 4 | 全部是静态字符串列表（hint / warning / note / card.desc） |
| React 19 Actions（`useActionState` / `useOptimistic`） | 0 | 19.3 已具备，现有页签仍是手写 `saving`；按 §5 逐个页签迁移，不批量重写 |
| 打包产物 | `image.js` 254 KB（260,084 B）/ `system.js` 232 KB（237,162 B） | React 19.3.1 minified；18.3.1 版是 176/153 KB，差 74/80 KB，由开发者决定接受 |

结论：**没有需要立刻返工的旧版写法**；基线与 `package.json` 一致（`^19.0.0` → 19.3.1）。下一步真正有价值的是按 §5 把保存动作迁到 `useActionState`，而不是先加 `memo`。

## 附录 A　《React 性能优化实战：15 个核心技巧》逐条对照

来源：开发者提供的《React性能优化实战：从卡顿到丝滑，15个核心技巧覆盖全场景》（技术栈，转载）。**正文实际编号只有 13 条**（渲染 7 + 加载 4 + 内存 2），标题里的「15」把案例里的重复项与工具清单也算进去了；示例代码基于 `react-router`、`antd`、`react-window`，与本项目技术栈无关。下表按它的原编号逐条给结论，判据一栏是「什么时候可以推翻」。

| # | 文章主张 | 我们的结论 | 做法与判据 |
|---|---|---|---|
| 1 | `React.memo` 缓存子组件 | **用不上（默认不加）** | 页签 4–8 张卡，树浅；props 多为 payload 派生对象，浅比较基本失效。React 19 起有 Compiler 可自动做等价优化，手写更没必要。**启用门槛**：Profiler 里单卡一次渲染 > 16ms，或同一操作里同卡重渲 ≥ 3 次 |
| 2 | `useMemo` 缓存计算结果 | **改造后用** | 现在 0 处。唯一允许的场景：对全量行做派生排序/过滤且行数 > 200。**禁止**为了「稳定引用」memo——那该由状态结构解决（§3） |
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

### A.2 与官方文档不一致的三处（以官方为准）

1. **把 `memo` / `useMemo` / `useCallback` 当默认动作。** react.dev 对 `memo` 的定位是「性能优化，不是保证」，对 `useMemo` 明确写了「不要到处加」；React 19 的 Compiler 出现后手工缓存的价值进一步下降。我们的规则是「测量后加」。
2. **路由懒加载示例依赖 `react-router`。** 我们的页签是服务端路由 + `history.pushState`，没有前端路由库，也就没有它的路由级拆包。
3. **没提 React 19 的性能相关 API**：`useTransition`、`useDeferredValue`、`<Activity>`、`<ViewTransition>`（react.dev 19.3 的 Hooks 与 Components 参考里都已列出）。我们暂时用不上，但要清楚这是「现在不需要」而不是「不知道」——将来做实时搜索或页签保活时它们是第一顺位。

**一句话结论**：13 条里 5 条已经在做、3 条等具体场景再做（判据写在表里）、5 条对我们的规模不适用。这篇当**清单**用可以，当**规范**用会过度设计。
