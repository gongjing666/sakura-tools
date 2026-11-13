# 后台 UI 规则

适用范围：图片优化（`sakura-tools-image`）4 页、对象存储（`sakura-tools-storage`）3 页、文件空间（`sakura-tools-files`）5 页、系统设置（`sakura-tools`）与批量处理页。后台只有一套样式栈，全部页面加载 `assets/ui/`；旧的 `admin*.css`、`admin.js`、`admin-layout.js`、`userfiles-admin.*`、`userfiles-space-admin.*` 已删除，不要再引用，也不要新造第二套皮肤。

## 1. 样式分层与加载顺序

`src/Admin/Page_Assets.php` 按以下顺序加载：

1. `assets/ui/tokens.css`：设计令牌，只有 `:root`。
2. `assets/ui/base.css`：画布、共享顶栏、模块切换、页签、页头、通用排版与 WP 管理页槽位重置。
3. `assets/ui/components.css`：卡片、表单、按钮、状态、保存条、弹窗（含全局提示弹窗 `.sakura-modal`）、表格、提示等组件。
4. `assets/ui/layout.css`：12 栏卡片拖拽布局，只给登记在 `Page_Assets::LAYOUT_PAGES` 的页面加载。
5. `assets/ui/pages/{module}.css`：模块级样式；模块名取页面 id 的第一段，例如 `image-rules` → `image.css`。
6. `assets/ui/pages/{page}.css`：单个页面的补充样式，只在文件存在时加载。

脚本顺序固定为 `core.js` → `api.js` → `ui.js` →（可选）`layout.js` → `pages/{page}.js`。

规则：

- 视觉修改只写 `assets/ui/`。页面专属样式优先复用 `components.css`；确属一次性的少量规则，先放模块 stylesheet，超过约 50 行再拆 `pages/{page}.css`。
- 配色是「樱雾」一套：暖樱白画布 + 白卡片 + 深樱强调色。允许粉色，但只允许 `tokens.css` 里这一组樱色（`--g-sakura-1..4` 与 `--sakura-*`）；不要在页面里另调一个粉或另调一个红，也不要用天蓝色。
- 禁止引入位图/矢量贴图：不许放樱花 SVG、云朵插画、照片背景。画布的花瓣感只能由 `base.css` 的径向渐变 + 点阵 `.sakura-admin::before` 生成，那是图案不是插画。
- 唯一强调色是 `--g-accent` 深樱 `#9d3452`；状态色只表达状态。
- 不要用 `!important`。若确需覆盖 WordPress 管理页槽位（`#wpcontent` / `#wpbody-content`），先用 `:has(.sakura-admin)` 限定作用域，再在评审中说明原因。
- 页面根节点由 `UI::page()` 输出 `.sakura-admin[data-sakura-page]`。不要再手写 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`、`.app` 这些旧根节点。
- WordPress 媒体库的样式独立放在 `assets/ui/wp-media/`，由 `Media_Hooks` 按屏幕单独加载，不属于后台页面布局，不参与上表顺序：`media-library.css` 只加载于 `upload.php`；`media-modal-folders.css` 在除 `upload.php` / `media-new.php` 外的后台屏幕加载（媒体弹窗可从任意页面打开，因此后台页面上看到它是正常现象）；`media-new-folders.css` 只加载于 `media-new.php`。

## 2. 设计令牌

颜色（`tokens.css`）：

| 变量 | 值 | 用途 |
| --- | --- | --- |
| `--g-ink` | `#241a1e` | 主文字、主按钮（带樱色的暖墨，不是纯黑） |
| `--g-ink-soft` | `#3d3035` | 正文、表格 |
| `--g-muted` | `#6b5a60` | 说明、次级信息 |
| `--g-faint` | `#776469` | 眉标、辅助信息下限（再浅就过不了 KPI 底上的 4.5:1） |
| `--g-line` | `#f0e3e6` | 卡片边框 |
| `--g-line-soft` | `#f7eef0` | 卡片内分隔线 |
| `--g-line-strong` | `#dfc9ce` | 输入框边框 |
| `--g-paper` | `#fdf7f8` | 页面底色 |
| `--g-paper-deep` | `#f8eef0` | KPI 块、嵌套底 |
| `--g-card` | `#ffffff` | 卡片内控件（输入、按钮）白底 |
| `--g-card-surface` | `#fffdfd` | 卡片表面；系统设置可改，只作用于 `.card` |
| `--g-card-bloom` | 两组径向渐变 | 卡片角落的樱粉泛光，只做底纹，永远不承载文字 |
| `--g-wash` | `#fdf9fa` | 弱底、嵌套面板 |
| `--g-accent` | `#9d3452` | 唯一强调色（深樱，白字 6.89:1） |
| `--g-accent-hover` / `--g-accent-line` | `#832a45` / `#8f2f4d` | 主按钮 hover / 主按钮描边 |
| `--g-accent-soft` | `#fbe9ee` | 强调色弱底 |
| `--g-sakura-1..4` | `#8f2f4d` `#c25e7d` `#e79fb3` `#f3d8dd` | 四级樱色阶，只给图表与多档色块；3、4 只做填充，不承载文字 |
| `--g-track` / `--g-fill` | `#fbe9ee` / 三段渐变 | 进度条轨道与条体 |
| `--g-select` / `--g-select-dot` | `#9d3452` / `#fff6f8` | 勾选/单选底与点 |
| `--g-ok` / `--g-ok-bg` | `#2f6b4a` / `#edf5f0` | 成功 |
| `--g-warn` / `--g-warn-bg` | `#8a651d` / `#faf3e4` | 警告 |
| `--g-bad` / `--g-bad-bg` | `#a63a2c` / `#fbedeb` | 错误、破坏性操作 |

- 页面只允许一个强调色。状态色只表达状态，不拿来当装饰；「等待配置」用中性或琥珀，不用绿色。
- 图表、donut、进度条只用 `--g-sakura-1..4` 这四级樱色阶，按「同一色相由深到浅」排，读起来是一个体量被切开，而不是四个互不相关的类别。空数据用 `--g-track`。
- 进度条轨道 `--g-track`、条体 `--g-fill`（左浅右深的渐变），两者已在 `components.css` 的 `.progress` / `.progress__bar` 定好，页面不要再写死颜色。百分比再小（如 1.5%）也必须看得见：条体有 `min-width:6px`，且数值始终印在条旁。
- `.progress__bar` 是 `<span>`，`components.css` 里给了 `display:block`；自写进度条若换成行内元素，`width` 会被忽略、条彻底不可见。

圆角与控件：

| 变量 | 值 |
| --- | --- |
| `--g-radius-card` | 18px（卡片；系统设置可设 0–40px） |
| `--g-card-border-width` | 1px（卡片边框；系统设置可设 0–6px） |
| `--g-radius-md` | 14px（嵌套面板、缩略图外框） |
| `--g-radius-sm` | 10px（输入框、按钮） |
| `--g-radius-xs` | 7px（行内 code 等） |
| `--g-ctl-h` | 38px |
| `--g-btn-h` / `--g-btn-h-sm` | 36px / 30px |

字号：

| 变量 | 值 | 用途 |
| --- | --- | --- |
| `--g-fs-meta` | 12.5px | 最小辅助信息下限 |
| `--g-fs-small` | 13px | 卡片说明、次级正文 |
| `--g-fs-body` | 14px | 正文、输入 |
| `--g-fs-table` | 13.5px | 表格 |
| `--g-fs-h2` | 17px | 卡片标题 |
| `--g-fs-h1` | `clamp(26px, 2.5vw, 32px)` | 页面标题 |

正文行高 1.65。说明文字最大宽度 74ch，页头说明 63ch。

## 3. 间距：自适应但有下限

所有横向内边距用令牌，不写死：

| 变量 | 值 | 用途 |
| --- | --- | --- |
| `--g-gutter` | `clamp(16px, 2.4vw, 30px)` | 页面左右留白 |
| `--g-app-top` | `clamp(14px, 1.7vw, 22px)` | 页面顶部留白 |
| `--g-app-bottom` | `clamp(56px, 5vw, 80px)` | 页面底部留白 |
| `--g-page-max` | 1480px（系统设置可设 960–1920px） | 内容最大宽度 |
| `--g-card-pad-x` | `clamp(17px, 2.1vw, 24px)` | 卡片左右内边距 |
| `--g-card-pad-top` | `clamp(18px, 1.9vw, 22px)` | 卡片顶部 |
| `--g-card-pad-bottom` | `clamp(18px, 2vw, 24px)` | 卡片底部 |
| `--g-stack` | `clamp(16px, 1.6vw, 20px)` | 页头与内容之间、页内顶层区块之间的堆叠 |
| `--g-grid-gap-x` / `--g-grid-gap-y` | 20px（系统页可设 0–48px） | 桌面多列卡片网格的左右 / 上下间隔 |
| `--g-space-1` … `--g-space-6` | 6 / 10 / 14 / 18 / 24 / 32px | 组件内部节奏 |

硬性下限：

- 文字到卡片边框至少 16px；嵌套面板内至少 12px。
- 相邻卡片之间至少 16px；卡片网格读取系统设置页「布局设置」保存的 `Layout::gaps()`，由 `Page_Assets` 写成 `.sakura-admin` 的 `--g-grid-gap-x` / `--g-grid-gap-y` 行内变量。
- 同一条竖直内容线：卡片标题/正文、KPI、页头状态卡、页面级面板都使用 `--g-card-pad-x`；只有卡片内部的嵌套框降一级。
- 不允许再引入 13/15/17/19px 这类一次性内边距；嵌套框统一用 `--g-space-3`，行内上下用 `--g-space-2`，紧凑网格用 `--g-space-2`。
- 页面上下留白用 `--g-app-top` / `--g-app-bottom`，不要在断点里写死 18/20/60。
- 页面不允许横向滚动（`scrollWidth == innerWidth`）。表格放不下时只能让 `.table-wrap` 内部横向滚动。
- `.page-body` 的直接子块（KPI 行、卡片网格、独立卡片）统一由 `base.css` 的 `.sakura-admin .page-body > :not(:first-child) { margin-top: var(--g-stack); }` 拉开。`:not(:first-child)` 的特异性是故意的，用来压过 `components.css` 后面 `.card { margin: 0 }` 的复位；不要改回 `* + *`，否则独立卡片又会和上一块贴在一起。验收时相邻块间距桌面 20px、窄屏 16px，不能出现 0。
- KPI 网格自身也用 `--g-stack` 作 `gap`；不要在单页 CSS 再给 `.kpis` 加 `margin-bottom`，否则会与上面的堆叠叠加成双倍。
- 自适应是重排，不是按设备写死。除极窄屏（≤360px）外，不要为某个机型单独写断点；这个断点只允许给确实放不下的控件做紧凑化。

## 4. 边框与层级

- 一张卡片只有一层可见边框：`var(--g-card-border-width, 1px) solid var(--g-line)` + `var(--g-radius-card)` 圆角 + `var(--g-shadow-card)`。默认是 1px / 18px / 三层柔和阴影；系统设置可以整体改细、改圆或去掉阴影，但不要在同一页对单张卡片另写一套。
- 卡片内部的分组用分隔线（`--g-line-soft`），不要再套一张带边框的卡。
- 表单行在移动端是「分隔线分隔的块」，不是第二张卡。
- 嵌套结构（缩略图尺寸表、开关列表）只保留最外层一个框，内部用 `border-top` 分隔。
- 阴影只表达层级，不表达状态；hover 不额外加边。
- 选中态不用黑色内描边。已保存配置的 `.profile-card.is-active` 只用 `--g-accent-soft` 背景 + 「使用中」badge；键盘焦点用 `--g-select-focus` 2px 外环，普通鼠标点击不显示焦点环（`.profile-card:focus { outline: 0 }`，只有 `:focus-visible` 才画环）。

### 4.1 卡片动作与保存

- **一张可编辑卡片 = 一个独立表单，只保存本卡。** 用 `UI::card( array( 'form' => array( ... ), 'save' => '保存设置' ), $body )`，组件会把 `form` 放进 `.card__body`，字段和保存条都在同一个表单内；不要把 `section` 本身换成 `form`，也不要在视图里手写 nonce。
- 多 option 表单传 `form.option_group`，由组件输出 `option_page` / `action=update` / `_wpnonce`；字段标记用默认的 `markers=fields`（等同 `Card_Form::fields( $card )`）。单个数组 option 用 `markers=option_fields` + `option`，把 `_sakura_card` 写进该 option 数组。自定义 saver 用 `markers=none` 并在视图里自行输出 nonce。
- 服务端必须按卡片合并已存值：`options.php` 多 option 用 `Card_Form::guard_options()` 保护未提交字段；单数组 option / 自定义 saver 按卡片标记只更新本卡字段。未提交字段保持原值，未知卡片直接返回旧值，不能清空。
- checkbox 组要有 hidden `0`；保存本卡时未勾选必须能写回 0。
- 保存按钮由组件输出在 `.card__savebar`，靠右；移动端按钮整宽。按钮文案说明保存范围（如「保存连接配置」「保存清理策略」），单卡内可统一写「保存设置」。
- 只读卡、纯操作卡（清理、探测、刷新、删除队列）不传 `form` / `save`，动作按钮留在卡内的 `.card__actions` 或 `.card__savebar`，不要再造页面级操作条。卡头按钮用 `actions`：链接传 `label` + `url`；需要 JS 绑定的普通按钮传 `label` + `button => true`，可再传 `id` / `attrs` / `variant` / `disabled`。有 `kicker` 时 `.card__actions` 会自动下沉 20px，让按钮顶边与标题首行齐平。
- 卡头动作最多三个；超过三个先合并或移到卡内。≤782px 时 `.card__head` 允许换行，`.card__actions` 自动落到标题下方、整行右对齐并允许按钮换行，不能把标题挤成一条细缝，也不能横向溢出。
- 以「行/项」为保存单位的列表卡（如用户容量）把保存按钮放在对应行内，这是唯一例外。
- 不再使用整页 `.page-savebar`，也不再使用浮动保存条。新增页面直接照本节写，不要回到「多卡共享一个表单、按钮提交整组」的旧模式。

### 4.2 布局与外观设置

- 系统设置页「布局设置」卡同时保存卡片间隔与全局外观，写入 `sakura_imgopt_layout_gaps` 和 `sakura_imgopt_ui_style` 两个 option。改外观后刷新页面生效。
- 可调项与范围：页面宽度 960–1920px（默认 1480）、页面背景色（默认暖白渐变）、卡片背景色（默认 `#ffffff`）、边框粗细 0–6px、圆角 0–40px、阴影 默认/无/轻/强。
- 外观值由 `Appearance::inline_style()` 只在非默认时输出到 `.sakura-admin` 根节点：`--g-page-max`、`--g-card-surface`、`--g-card-border-width`、`--g-radius-card`、`--g-shadow-card`，自定义页面背景用 `background` 覆盖默认渐变。默认状态下不输出行内样式，令牌表保持唯一来源。
- 卡片背景只改 `--g-card-surface`；`--g-card` 继续给输入框、按钮、下拉使用，否则卡片一换色，控件也会跟着变色。页面背景换成深色时要检查正文对比度，系统设置里已有提示，不能为了「个性」牺牲可读性。

### 4.3 表格与分组

- 一张表只允许一个 `<thead>`。不要把同一组数据 `array_chunk()` 成两张表各自带表头；等级加成曾因此出现两遍「等级 / 门槛积分 / 加成（MB）」。
- 需要分栏或分组时，用单表 + 分组行，或两个不带 `<thead>` 的面板并排；只有打印或固定表头场景才重复表头，且必须是有意为之。

### 4.4 列表页筛选与操作

- 列表页的状态计数只保留一层：删除队列把「排队 / 执行中 / 失败 / 已放弃」做成列表卡内的四个紧凑状态块，不要再在卡片上方放一排同义的大 KPI，也不要再放一个重复的「全部状态」下拉；页面级只保留右上角状态卡。四个状态块占满卡内工具行，再次点击当前状态回到全部。
- 列表卡的工具行负责筛选，不再承担主操作。主操作（重新核对、导出、补建）放卡头 `actions`，与标题同排；筛选行的搜索框 240px（上限 280px）、下拉 160px（上限 180px），控件用 `flex:0 0 auto` / `flex:0 1 240px`，禁止 `flex:1` 或 `width:100%` 拉满；保持一条，≤782px 再换行。宽度上限见 10.2。
- 状态块使用 `aria-current="true"` 表示当前筛选；再次点击当前状态回到全部，不另设同义下拉。若页面还有其他筛选下拉，必须与状态块同步（同一 `state` 参数）。
- 卡头已经有三个按钮时（用户容量：重置容量 / 同步容量 / 刷新），搜索框不要挤成卡头第四个控件；它留在卡内工具行、左对齐 240px，≤782px 才整行。搜索框不是按钮，但也不靠“它是筛选”这个理由破例。
- 列多的用户容量表按生产版固定列宽：用户 28%、容量使用 34%、文件 90px、单独容量 210px，`table-layout: fixed`；最后一列表头与内容右对齐，输入框和保存按钮靠右。≤1100px 收窄为 26% / 32% / 76px / 192px，≤782px 必须恢复 `table-layout: auto` 与 `width: auto`，否则窄屏会内滚。表内多段文字用两条 `.files-cell__line`，不要让三段竖排把行高顶到 84px。

## 5. 页头、状态与弹窗

- 页头由 `UI::page()` 输出：上方眉标（`.page-head__eyebrow`）、标题、1–2 行说明（`.page-head__lede`），说明下方是可选的页头操作（`.page-head__actions`，靠左，与说明间距 20px）；状态卡单独放右侧，不与操作按钮同排。页头底部一条 `--g-line` 分隔线。不要放樱花插画、照片或高对比大面积色块；樱色只出现在画布、卡片泛光、按钮和图表里。
- 背景默认是暖樱白底 + 3 层樱雾径向渐变 + `.sakura-admin::before` 的点阵「樱尘」（`base.css:33-79`）；系统设置可以换成单一纯色，但默认值必须保持这套樱雾画布，禁止贴图。改画布只改 `tokens.css` 的 `--sakura-*` 与 `base.css` 的这几层渐变，不要往页面里塞背景图。
- 卡片表面是 `--g-card-surface` 打底 + `--g-card-bloom` 叠一层角落泛光（`components.css` 的 `.card`）。改卡片颜色时颜色和泛光要分开写：`background-color` 放令牌，`background-image` 放泛光，否则系统设置里的自定义卡片色会被盖掉。
- 顶栏状态胶囊支持 `ok / off / warn / error`：未传 tone 时是中性灰点；`ok` 绿点，`off` 灰字，`warn` 琥珀，`error` 淡红。不要所有状态都用绿点。
- 页头状态卡与页头内的小状态标签同样遵循语义，不要用绿色表示「等待配置」。

### 5.1 提示弹窗（`.sakura-modal`）—— 全局组件

**两条通道，别混用：**

| 场景 | 用什么 | 例子 |
| --- | --- | --- |
| **动作结果**（用户刚点了按钮） | 居中提示弹窗 `UI::modal()` | 保存成功/失败、连接测试结果、重置统计、批量重排、投递测试 |
| **持续状态**（明天还在） | 页内 `UI::alert()` / `UI::notice()` | 对象存储已停用、兼容模式、密钥无法解密、主题不是子比、待处理差异 |
| **AJAX 小动作**（不离开当前页） | 右下角 toast `admin.ui.toast()` | 复制成功、异步刷新失败、指标刷新 |

判断标准只有一句：**这个提示是「刚刚发生的一件事」还是「现在的一个状态」**。前者弹窗，后者页内。不要两者都来一遍——同一个事实不允许「顶部 notice + 弹窗」重复出现。

不允许再用 WordPress 原生 `notice notice-*` 输出（`admin_notices` 钩子也一样）：跨页面的维护类提示走 `\Sakura\ImageOptimizer\Admin\UI\Feedback::admin_notice_html( $message, $tone, $link, $label )`，它自己带一个 `.sakura-admin-notice-root` 外壳，样式与页内 `.alert` 同源。

- **样式在 `components.css` 的「Prompt modal」段**（全局，没有页面前缀），结构由两处输出：
  - `src/Admin/UI/Modal.php` + `UI::modal( $args )` / `UI::modal_html( $args )` —— 保存结果、检测结果、任何一次性事件都用它。`$args`：`id`、`tone`（ok/bad/warn/info/neutral，决定图标配色）、`kicker`、`title`、`message`、`body`、`size`（`compact` = 440px，报告类用；省略 = 560px）、`button`、`variant`、`autoshow`、`return_to`。
  - `src/S3/Config_Notice.php` —— 对象存储的三条**拦截式**提示（配置缺失 / 已停用 / 未核对），自带「不再提醒」与主按钮，用的是同一套类名。对象存储的缺失项胶囊样式仍在 `storage.css`（`.sakura-config-missing` 等），那是页面专属家具。
- **图标五色**：`--ok` 绿、`--bad` 赤、`--warn` 琥珀、`--info` 灰紫、`--neutral` 暖灰。成功不要用主按钮的樱红，否则和「失败」分不开。
- **弹窗不得改变用户的位置**（新窗口、关闭都一样）。这条是硬性的，验收时按下面三项量：
  - **滚动位置**：打开前 `scrollY` 是多少，关闭后必须还是多少，误差 0；弹窗内部滚动不继承给页面。锁滚动不要用 `body { overflow: hidden }`（会让页面宽变窄、有滚动条的桌面出现横向跳动），改为 `position: fixed` + `top: -scrollY` 的经典锁法，关闭时恢复并 `scrollTo` 回原位。
  - **焦点位置**：打开时焦点落标题、关闭时回到触发元素，但**两次焦点都必须 `focus({ preventScroll: true })`**，且关闭时若目标已在视口外，不能把它滚进视野——用户停在哪就停在哪。目标控件已被移走（重定向后常见）时，焦点回到 `<body>` 或页面根，绝不用滚动去追。
  - **视口坐标**：打开前后 `getBoundingClientRect()` 的页面内容位置不得变化；用 `visualViewport` 或 `innerWidth - clientWidth` 复核滚动条补偿，禁止出现 1–20px 的横向抖动。
  - **保存后的整页往返**：卡片表单提交到 `options.php` / `admin-post.php` 是 POST + 302 + 重新加载，浏览器必然从 `scrollY=0` 画新文档——这不是弹窗造成的，但用户会一起感知成「位置丢了」。`ui.js` 在 submit 时把 `路径|page + scrollY` 写进 `sessionStorage.sakuraAdminSaveScroll`，新文档加载后校验同一 screen key 再恢复，读完即删。**screen key 故意不含 `t`**：部分 saver 重定向回来时只带 `page=`、不带 `t=`（对象存储保存连接配置就是这样），带上 tab 会让 key 对不上、恢复静默失效；切页签是链接不是提交，本来就不会写入。**恢复必须发生在自动弹窗打开之前**（`admin.ready()` 的第一步），并且锁滚动时 `body.top` 要等于恢复值：否则会看到「先顶部 → 弹出来 → 再跳回去」的两段跳。图片/字体晚到可能把文档撑高导致过早的滚动被钳制，所以 `load` 后再幂等校正一次。新增「提交后跳转」的表单不用另写逻辑，但换页面 id 或改成 AJAX 时要确认这条路径仍然成立。
- **尺寸**：`width: min(560px, 100%)`、`padding: var(--g-space-5)`（24px）、`border-radius: var(--g-radius-lg)`（22px）、白底 `--g-card`、`--g-shadow-pop` 阴影；**没有边框**，靠阴影与暖白画布分层。`max-height: calc(100vh - 2 * var(--g-space-4))`（视口高 −36px），内容超高时卡片内部滚动（`overflow-y: auto`）。外层 `.sakura-modal` 是 `position: fixed` + `inset: 0` + `z-index: 100000` 的自绘遮罩，`padding: var(--g-space-4)`（18px）保证窄屏不贴边。
- **`.sakura-modal--config` 只是语义标记**：仓库里它没有任何 CSS 规则，判断是否为提示弹窗看的是最外层 `.sakura-modal`，不是这个修饰类。
- **结构固定**：图标（46×46、`--g-radius-md`）→ 大写眉标 → 标题 → 1–2 行说明 →（可选）缺失项面板 → 底部「不再提醒」+ 次按钮 + 主按钮。三个变体共用 `render_modal()`，差别只在中段：
  - `modal_html( $options, $context )`（`:233`）—— 配置缺失，有 `.sakura-config-missing` 胶囊面板（小标 + 缺哪几项），主按钮按上下文指向连接页或 `#sakura-s3-connection`。
  - `paused_html( $options )`（`:312`）—— 已停用，无胶囊，主按钮固定去连接页（`Registry::url( 'storage', 'connection' )`）。它只挂在同步中心：连接页上开关就在眼前，再弹一个模态会挡住用户正要点的控件。
  - `reconcile_html( $options, $configured, $has_scan, $busy )`（`:283`）—— 已配置但从未核对，无胶囊，主按钮是 `[data-sakura-action="scan"]`，走页面自己的扫描流程；核对完页面重载、`$has_scan` 成立，弹窗自然不再出现，**不需要**额外的「成功才关闭」状态机。
- **一次只弹一个**：`includes/views/s3-sync-center.php:956-963` 按「缺字段 → 停用 → 未核对」取第一个非空结果渲染，注释是 `One dialog at a time, most blocking first`。新增变体要插进这条链，不要在页面里各弹各的。
- **对齐**：图标、眉标、标题三行居中（图标用 `display: flex` + `margin: 0 auto`，inline 级盒子会被 auto margin 忽略）；说明段与底部操作区保持左对齐 / 两端对齐。说明段不要加 `max-width: 46ch` 之类的行宽上限——中文在这张卡里排到 23 字就折行，右侧空出一大块，看着像排版错误。
- **配置缺失不是错误**：图标用暖灰 `--g-accent-soft` 或中性灰 `.sakura-modal__icon--neutral`，缺失胶囊用 `--g-warn-bg` / `--g-warn`；红色只留给破坏性操作。
- **≤520px**：底部操作区纵向排列、「不再提醒」在上、按钮整宽；次按钮保留描边和暖白底，不能变成无边界的一行字。390 / 320 下弹窗不得横向溢出。
- **打开与焦点**：延迟约 220ms 打开（同一页有多个候选时第 n 个再累加 `120ms × n`）；`role="dialog"` / `aria-modal` / `aria-hidden` 与 `hidden` 成对切换；打开后 40ms 焦点落标题，所以标题要 `outline: none`（浏览器在非交互元素上画焦点环看着像 bug）；Tab 焦点圈在弹窗内首尾回绕；Esc / 点遮罩 / 关闭按钮都能关；关闭后焦点回到触发前元素。
- **关闭分两档，且只有一档会写库**：
  - 勾选「不再提醒」后，不论用哪种方式关闭都写 `sakura_imgopt_s3_config_notice_dismissed`，永久保存，直到手动清除该 user meta。
  - 未勾选时**不写任何东西**：X、Esc、点背景、次按钮「暂不处理」都只关掉这一次，刷新后状态仍然成立就继续弹。理由是这个弹窗是页面上唯一说明「功能坏了 / 桶不再收文件」的地方，把「关掉」当成「处理过」会让问题静默存在。
  - 1.25.6 及更早的 12 小时 snooze（`sakura_imgopt_s3_config_notice_snoozed_until`）已废弃：升级后读到残留时间戳直接删除，不再压制弹窗。
- **主按钮跳转下一页时**保留 sessionStorage 一次性跳过（`sakuraS3ConfigSkipOnce`），只作为 AJAX 写入失败时的兜底，不能当长期静默用。
- **锁滚动用共享实现 `admin.ui.scrollLock.lock()/unlock()`**（`ui.js`）：`position: fixed` + `top: -scrollY` 钉住 body，根节点加 `html.sakura-scroll-locked`；关闭时还原内联样式并 `window.scrollTo(0, 记录值)`。**不要用 `body { overflow: hidden }`**（滚动条消失会让视口变宽、整页横向抖动，且 Chrome 会把页面拉回顶部）。Prompt modal、对象存储配置弹窗、媒体预览三处都走这套；新增弹窗不要另写锁。
- **节点必须由页面根节点输出**，遮罩才盖满整个视口；不要依赖旧 `.app` 的堆叠上下文（同步中心把弹窗 echo 在 `ob_get_clean()` 之前正是这个原因）。
- 提示弹窗没有入场动画，`prefers-reduced-motion` 在这里是空条件，不要为它单写动画开关。

### 5.2 二次确认弹窗（`.sakura-dialog`）

破坏性操作与「需要一句确认才继续」的动作走 `SakuraAdmin.ui.confirm( message, options )`（`assets/ui/js/ui.js:75`），不要用 `window.confirm`。列表里的按钮只要写 `data-sakura-confirm="<说明>"`，`ui.js:907` 的 `initConfirmations()` 会自动接管，并默认按危险样式处理。

- **结构**：原生 `<dialog class="sakura-dialog">` + `showModal()`；内部 `form.sakura-dialog__form`（`method="dialog"`）→ `.dialog__body`（`h2.dialog__title` + `p.dialog__message`）→ `.dialog__actions`。**没有图标、没有眉标、没有复选框**，就是标题 + 一句正文 + 两个按钮；不要为了「和提示弹窗一致」给它加图标或第三颗按钮。
- **样式在 `components.css:1138-1184`**，不带页面前缀，任何后台页都能用：`width: min(440px, calc(100vw - 32px))`、`border: 1px solid var(--g-line)`、`border-radius: var(--g-radius-card)`（18px）、白底 `--g-card`、`--g-shadow-pop`；`::backdrop` 是 `--g-overlay` + `blur(2px)`。注意这三项与提示弹窗**是两套外观**：提示弹窗 560px / 22px 圆角 / 无边框，确认弹窗 440px / 18px 圆角 / 有 1px 描边，不要互相「对齐」。
- **底部动作区是 `.dialog__body` 的兄弟节点，不放在 body 里**：`.dialog__body` 内边距 `22px 22px 8px`，`.dialog__actions` 是 `16px 22px 20px` + `justify-content: flex-end` + `gap: 8px`。新增变体请保持这个层级；塞进 body 会让左右内边距变成 22/45px（`files-zibll.js` 的输入框变体就是这种写法，属现存的小不一致）。
- **按钮语义**：取消是 `.btn--ghost`，确认在 `danger: true` 时是 `.btn--danger`、否则 `.btn--primary`。`confirm` 返回 Promise：点确认 resolve `true`，取消 / Esc resolve `false`。
- **焦点**：`showModal()` 后主动 `focus()` 确认按钮（`ui.js:140`），所以打开后直接回车就是「确认」——破坏性操作请在文案里把这个后果写清楚，不要靠按钮位置防误触。
- **行为差异要记住**：Esc 能关；**点遮罩不关**（与媒体预览相反）；**不锁页面滚动**——确认弹窗足够小，锁滚动反而会让背景跳位。
- **输入型变体**（`files-zibll.js:100` 的 `ask()`）用于需要填一个值才能继续的动作（延长授权天数、输入「撤销」二字）。输入框沿用第 6 节的 38px 高 / 10px 圆角 / 1px `--g-line-strong`；校验不通过时不要关闭弹窗，也不要把按钮文字改成错误提示。

### 5.3 媒体预览弹窗

- 「文件核对」等列表的文件详情不再跳媒体库，统一用 `SakuraAdmin.ui.mediaPreview(payload, { trigger })` 打开预览。触发元素是 `button[data-sakura-preview]`，属性里放 JSON；PHP 首屏和 AJAX 重绘（`storage-sync.js` 的 `makeRow`）必须输出同一份字段，不能只改一边。
- 多文件浏览：传 `{ items: [payload, ...], index?, labels: { prev, next, counter, gallery } }`。`items` 顺序就是文件顺序，弹窗打开后左右两侧出现 42px 圆形按钮（≤782px 为 38px），舞台顶部居中显示 `当前 / 总数` 胶囊；只有 1 个文件时整组导航隐藏。键盘 ←/→ 切换，触摸横向滑动 ≥48px 且横向位移大于纵向 1.2 倍时切换；切换视频前必须 `pause()`、移除 `src` 并 `load()`。下载按钮、头部大小/状态/位置胶囊和底部路径都跟随当前文件更新，不能只换舞台。
- 结构固定：`<dialog class="sakura-media-dialog">` → 头部（类型 + 扩展名眉标、文件名、大小/状态/时间/本地或云端胶囊、38px 关闭按钮）→ 媒体舞台 → 底部（对象键/路径 + 下载主按钮）。不要用 `alert` / `confirm` / 浏览器原生视频控件，也不要把 `<dialog>` 的 UA 默认边框、内边距、按钮样式和聚焦黑描边直接露出来；`dialog` 自身聚焦时用 `outline: 0`，键盘焦点由内部可操作元素的 `:focus-visible` 承担。
- 尺寸：`width: min(1040px, calc(100vw - 48px))`、`max-height: min(88vh, 920px)`、24px 圆角、1px 暖灰描边、`--g-card-surface` 白底、强阴影；`::backdrop` 用 `rgba(18,19,21,.62)` + `blur(8px) saturate(.9)`。媒体舞台使用与插件画布一致的暖白渐变（`#f8f7f3 → #f4f3ee → #f7f6f2`），空状态/错误提示用墨色文字而不是白字；视频舞台仍为深色以承载自绘控件。图片 `object-fit: contain`，最大高度 `min(calc(62vh - 40px), 610px)`、舞台内边距 20px，图片四周至少留 20px，不能被裁切或出现内滚动条。
- 类型差异：图片直接展示 + 下载；视频用自绘控件播放 + 下载（播放/暂停、当前时间/总时长、进度、静音、音量、全屏；支持 Space 播放暂停、←/→ 前后 5 秒），`<video>` 不写 `controls`；压缩包、文档及其他类型不加载媒体，只显示「暂不支持在线预览」说明 + 下载按钮。下载按钮始终是主按钮，`href` 走带 nonce 的 `admin-post.php`（对象存储用 `action=sakura_s3_media_download`；删除申请用 `action=sakura_sufm_download&attachment=附件 ID`），响应头必须是 `Content-Disposition: attachment`，不能直接给 CDN 直链（跨源时 `download` 属性会失效）。
- 文件空间「审核与执行」的删除申请队列，对象单元格只放「N 项 / 大小」两行；「查看文件」和「批准 / 驳回」一起放在操作列，不再在对象列另起一个文字按钮。点击后才请求 `admin/delete-requests/{id}/items`，不要把每个文件的预览 URL 塞进队列表。响应里的文件夹项要展开冻结快照中的实际文件，快照为空时用文件夹说明占位。多文件仍复用同一个媒体预览弹窗，不另写一套画廊。
- 审核队列操作列：待审核行依次是「批准」(primary) /「驳回」(ghost) /「查看文件」(ghost)；`item_count` 为 0 时省略「查看文件」。操作按钮语义固定——批准只把申请推进到可执行，不删除；驳回退回申请并立即解除对象锁；「需核实」不再作为按钮出现（历史行仍可能带 `needs_review` 状态，按待审核同样处理）。
- 操作列的单元格本身必须是普通 `td`（保持 `vertical-align: middle`），按钮组放在内部的 `.queue-actions` flex 容器里。不要给 `td` 直接加 `display:flex`：那会让单元格脱离表格垂直居中，按钮比状态胶囊高出一截。验收标准是按钮中线与同行的状态胶囊、提交时间中线偏差 ≤2px。
- 行为：`showModal()` 打开，焦点落在 `dialog` 容器但不显示 UA 焦点环；Esc、点遮罩、点关闭都能关；关闭后焦点回到触发按钮；打开期间用 `body { position: fixed; top: 当前 body 的视口位置 }` 固定页面，并只给 `html` 加 `sakura-media-open` 锁根滚动；不要给 `body` 设 `overflow: hidden`（Chrome 会把页面滚回顶部）。关闭时恢复 body 内联样式并 `scrollTo` 回原位置，`trigger.focus({ preventScroll: true })` 避免焦点回退再次滚动；关闭视频要 `pause()` 并清空 `src`，避免后台继续解码。图片加载失败、视频解码失败、没有可预览地址分别给独立标题和说明，不要只显示一串英文错误。
- 移动端 ≤782px 全屏：`100vw / 100dvh`、无圆角无边框、头部/底部内边距收窄、下载按钮整宽、音量条隐藏；320px 下标题可换行，弹窗和页面都不能横向溢出。
- `prefers-reduced-motion` 下关闭入场动画；遮罩和弹窗动画只做透明度和轻微位移，不做缩放弹跳。

## 6. 控件

- 输入框、下拉、文本域：38px 高、10px 圆角、1px `--g-line-strong` 边框、白底、14px 字。文本域最小高度 88px，可纵向拉伸。
- 输入框、下拉的 `width:100%` 只表示填满所在字段列，不是填满卡片；筛选行、工具行和短字段必须按 10.2 的上限表限宽。勾选/单选固定 18×18，不参与列宽拉伸。
- 颜色字段用 `UI::field( 'type' => 'color' )`：与输入框同高、同边框，内层 swatch 留 1px 暖灰内描边并跟随 10px 圆角（纯白卡片底色也要能看出色块边界）；不要手写 `<input type="color">` 绕过组件。`::-webkit-color-swatch` 与 `::-moz-color-swatch` 必须拆成两条规则：合并后 Chrome 不认识 Mozilla 伪元素，会把整条规则丢弃（实测边框不生效）。
- 勾选框/单选框统一 18px 圆形，不再区分方框与圆圈。基础态为 `--g-select-surface` `#fffdfd` + 1.5px `--g-select-border` `#dcc4c9` 边框；hover 边框 `--g-select-border-hover` `#b99aa2`。
- 勾选态必须改变填充，不能只换边框：统一为 `--g-select` 深樱底 `#9d3452` + 6px `--g-select-dot` 暖白圆点 `#fff6f8`。勾选/未勾选在 1:1 截图里必须一眼可分；禁止再出现黑色方框 + 白勾。
- 白点必须相对控件绝对居中：`::before` 用 `position:absolute; top:50%; left:50%; margin:0; transform: translate(-50%, -50%) scale(...)`。WordPress 后台 `forms.css` 的 `input[type=checkbox]:checked::before` 会残留 `margin:-3px 0 0 -4px`；若改用 grid/flex 居中而不清 margin，6px 白点会被推到左上角。`margin:0` 不是冗余声明。
- 禁用态为暖灰底/边框；`checked:disabled` 用 `#b8a5ab` 底 + 暖白点。选择控件焦点环 3px `--g-select-focus` `rgba(157,52,82,.16)`。
- 选择控件放在 flex 行里时必须 `flex: none`（或 `0 0 18px`），否则在 320px 会被压成 16px 宽；宽度和高度都属于硬性 18px。
- `appearance: none` 的基础态写了白底后，`:checked` 必须用同等或更高特异性重新声明背景和边框；否则基础态会把勾选态盖掉。
- 卡片里的布尔项一律用勾选样式：`label.check` + `check__box` + `check__text`（`UI::field` 的 `type=checkbox`）。不要在卡片里使用滑动开关 `.switch` / `type=toggle`，也不要同一张卡片一半勾选框一半滑块。历史变量 `$sufm_space_switch` 只是兼容名，输出必须是 checkbox；交互确认仍通过 `data-zibll-switch` 绑定，不依赖控件外观。
- 不在页面根节点内的原生控件（如布局工具条的「显示网格」）要单独给皮肤。
- 焦点环统一为 3px `--g-select-focus` `rgba(157,52,82,.16)`，边框变 `--g-accent`；不要使用蓝色 outline。
- 主按钮是深樱实心白字（`--g-accent`，上浅下深的三段渐变 + `--g-accent-line` 描边，见 `components.css` 的 `.btn--primary`）；次按钮白底描边（页头操作即使传 `quiet` 变体也不能无边框）；危险按钮只在确认场景实心 `--g-bad`。按钮不要再写死颜色，也不要给某一种变体单独换色相。
- 表格表头 12.5px、正文 13.5px；数字列可等宽字体，但不要用蓝色。
- 多列勾选/选项网格里的说明文字必须 `grid-column: 1 / -1` 单独成行，不能被误读成某个选项的标签。

## 7. 移动端

- ≤900px：页头改单列，说明和操作按钮依次排列；按钮可以换行，不要横向滚动，也不要压住状态卡。
- ≤782px：卡片标题区换行，标题占满一行，操作按钮落到下一行并右对齐。
- 页签保持单行：`gap: clamp(10px, 2.8vw, 18px)`、字号 13px；横向滚动时两端用 mask 渐隐，不能露出半个字。验收时最后一个页签必须完整可见。
- 分段控件、日志筛选允许换行；不要用横向滚动藏按钮。
- 表格用 `.table-wrap` 内部滚动，页面本身不横溢。桌面的固定列宽在 ≤782px 必须重置为 `width:auto; min-width:0`。
- 内容型表格（审核队列、授权流水、套餐编辑器）在窄屏重排为带标签的行：数据行用 `data-label` + `::before` 显示列名，表头隐藏；空标签 `data-label=""` 的单元格保持块级，不加冒号。
- 审核队列在 ≤782px 不用默认的 92px 标签沟槽，改成「标签 | 值」两行式紧凑列举：行用 `display:grid; gap:9px; padding:14px 0`，单元格是 `grid-template-columns: 62px minmax(0,1fr)` + `align-items:baseline`，列名 12.5px `--g-muted`（不是 11.5px 的暗淡眉标），用户 ID 与大小这类补充行升到 12.5px `--g-muted`。操作行 `grid-template-columns: minmax(0,1fr)`、隐藏 `::before`、上方 1px `--g-line-soft` 分隔线，按钮靠右、高 34px、字 13px。没有操作的行（`td.queue-cell.is-empty`，由 JS 打标）整行隐藏，不要在卡片里留一条孤零零的「—」。验收：390 下每行信息区首行顶到卡片左内边距，按钮右缘等于单元格右缘，320 不横向溢出。
- 桌面顶栏是三列 grid：品牌左、模块导航绝对居中、状态/动作右；导航中心必须等于页面内容中心，不能被左侧品牌文字宽度推偏。≤1100px 回到 flex-wrap，品牌与状态一行、模块导航整行另起。≤660px 收成「品牌 + 状态」一行；≤340px 隐藏长状态胶囊，避免挤成三行。

## 8. 常见坑

- 后台 tab 参数是 `t`，例如 `admin.php?page=sakura-tools-image&t=thumb`；不是 `tab`。截图验收时用错参数会全部落在默认页。
- 资源门控只看 `Route::current_page()`，不再有迁移白名单。新页面只要注册了路由并调用 `Assets::enqueue_page()` 就会加载 UI 栈。
- 隐藏页面（如批量处理）不在 `Registry` 里，不要调用会反查路由的 `Assets::enqueue_page()`；直接 `Page_Assets::set_data()` + `Page_Assets::enqueue( 'media-progress' )`。
- WP 媒体库样式已迁到 `assets/ui/wp-media/`；不要放回 `assets/` 根目录，也不要让它们参与后台页面布局。后台页面出现 `media-modal-folders.css` 是媒体弹窗的全局依赖，不是页面样式串场。
- 窄屏重排用的 `data-label` 要加在真正承载列名的元素上，且只加一次；`::before { content: attr(data-label) }` 不会走翻译，当前后台仅中文，新增语言时需改为视图内可见标签。
- 勾选框最容易出现「看着能点，但勾没勾一个样」：基础态选择器特异性高于 `:checked` 时，白底会把深樱勾选态盖掉；要在同一特异性补 `:checked`。
- 勾选框白点跑到左上角：先看 `::before` 的计算样式。根因通常是 WP 后台 `forms.css` 的 `checked::before` 负 margin 与 grid/flex 居中冲突；按第 6 节改成绝对定位 + `margin:0`，不要用 `!important` 硬盖。
- 支付方式少了支付宝：页面只渲染主题实际启用的通道。测试站先查 `zibll_options['pay_alipay_sdk_options']` 是否为 `null`；生产为 `official_alipay` 且 `pay_sdk_order.alipay=1`。这是主题数据差异，不要把支付宝开关硬编码进视图。
- 右侧「布局修改」消失：按顺序查 `data-sakura-layout` 属性、`Page_Assets::LAYOUT_PAGES` 登记、布局 id 是否等于生产已存组名；三者缺一都不会加载 `layout.css` 和工具栏。系统设置页的页面 id 与布局 id 都是 `system-settings`。
- 布局工具条文案竖排用「一字宽块 + 自然换行」：`width:1.1em; white-space:normal; word-break:break-all; line-height:1.34; text-align:center`，不要用 `writing-mode: vertical-rl`。部分 CJK 回退字体在这里上报的纵向步进只有约 4px，四字会叠成一团（实测标签高 4.17px）；编辑态恢复 `width:auto; white-space:nowrap; word-break:normal`。改完在 1440 和 1101 截图检查四字完整。
- 重构后按钮/操作入口不见：先按 10.1 的操作清单对照，确认是不是在搬卡片、换布局时删了 DOM；不要用 CSS 补一个只有外观的假按钮，也不要把入口藏进折叠区。
- 筛选框/输入框铺满整行：全局输入框的 `width:100%` 只填字段列，检查父级是否给了 `flex:1` 或没有 `max-width`；按 10.2 的上限表改父容器，不要在每个页面单独写 `!important`。
- 多选组（源格式、尺寸等 chip）的说明段落不要放进 grid：`<p class="description">` 会成为网格项，把同一行最后一个 chip 拉到说明块的高度（实测 103px 的竖长框）。说明放在 grid 外的 notes 容器里，用分隔线与 chip 区分。
- 全宽表格的滚动容器只包 `<table>`：说明段落放进容器会被负 margin 一起拖到卡片边框（≤782px 尤其明显）。说明放在滚动容器外，让它继承卡片内容区内边距。
- 验收宽度跟着改动范围走，不机械跑固定清单：
  - 单页/单组件、未动响应式：1440 即可；
  - 单页/单组件、动了响应式：1440 + 390；
  - 共享 `tokens` / `base` / `components` / 全局布局：1440 覆盖全部后台路由，390 抽查至少 3 个代表页；
  - 页面骨架或断点：再补 1366、1101、782、424、320。
- 每轮都检查 `document.documentElement.scrollWidth == clientWidth`、`.page-body` 相邻块间距 ≥16px、同一卡片无 `.switch` / `.check` 混用、每张表只有一个 `<thead>`，并确认 console 无报错。

## 9. 组件层与静态资源

### 9.1 PHP 组件

- 页面用 `UI::page( $args, $body )`。`$args`：`id`（页面 id，写入 `data-sakura-page`）、`module`、`tab`、`eyebrow`、`title`、`lede`、`status`、`actions`、`chrome`。
- 卡片用 `UI::card( $args, $body )`。`$args`：`id`、`kicker`、`title`、`description`、`actions`、`wide`、`class`、`anchor`、`form`、`save`。`actions` 的每一项：`label`、`url`（链接）或 `button => true`（按钮）、`variant`、`id`、`attrs`。
- 表单用 `form` 参数：
  - `id`：表单 id，默认 `sakura-card-form-{card}`；
  - `action`：默认 `options.php`；自定义 saver 传 `admin-post.php`；
  - `option_group`：传给 `wp_create_nonce( $group . '-options' )` 的组名；
  - `markers`：`fields`（默认）、`option_fields`、`none`；
  - `option`：`markers=option_fields` 时的 option 名；
  - `hidden`：额外 hidden 字段。
- 字段用 `UI::field( $args )`，类型支持 `text/number/url/password/email/color/select/textarea/checkbox/radio/toggle/hidden/custom`；复杂控件用 `custom` + `control` 回调，只外包标签与间距。
- `toggle` 仅为历史兼容保留；后台卡片不要使用，布尔项统一 `checkbox`（见第 6 节）。
- 反馈用 `UI::badge()`、`UI::alert()`、`UI::notice()`、`UI::empty_state()`、`UI::modal()`。动作结果用 `UI::modal()`，持续状态用 `alert`/`notice`，AJAX 小动作用 `admin.ui.toast()`（见 5.1）。
- 新页面不要手写 `section.card > .card__head > .card__body` 骨架，也不要在视图里重复 nonce、卡片标记和保存按钮。特殊版式通过 `wide` / `class` 传给组件，不改组件结构。
- 组件在 `src/Admin/UI.php` 与 `src/Admin/UI/`，`Card_Form` 在 `src/Admin/Card_Form.php`，均由 PSR-4 自动加载。

### 9.2 页面接入

- 在 `src/Admin/Registry.php` 注册页面与 tab；页面 id 同时决定 CSS/JS 文件名。默认 tab 可以省略 `t`。
- 渲染前把该页需要的运行时数据交给 `Page_Assets::set_data( $page, $data )`，再调用 `Assets::enqueue_page()`。数据会进入前端 `SakuraAdmin.data`。
- 页面 CSS 命名：`assets/ui/pages/{module}.css` 是模块共享样式，`assets/ui/pages/{page}.css` 是可选页面样式；JS 只加载 `assets/ui/js/pages/{page}.js`。
- 需要卡片拖拽布局的页面，在 `Page_Assets::LAYOUT_PAGES` 登记 `页面 id => 布局 id`；布局根节点输出 `data-sakura-ready`、`data-sakura-layout`，每张卡同时带 `data-sakura-card`。系统设置页也走同一套，5 张卡（runtime / engine / checks / extensions / layout）默认为 6/6/12/6/6 跨列。
- 映射右侧的布局 id 必须是生产已存的布局组名。改页面 id 或文件名时不要顺手改布局 id：文件空间-子比专属的页面 id 是 `files-zibll`，布局 id 必须保持 `files-space`，否则旧布局丢失，右侧「布局修改」工具栏也不会出现。
- 布局页的 46px 右侧留白由 `Page_Assets::has_layout()` 在服务端输出 `sakura-layout-enabled`，首屏就预留，不能等 `layout.js` 到页尾再补类，否则内容区会在加载后突然变窄。`freeLayout()` 第一次把卡片改成绝对定位时加 `is-initializing` 关闭 0.18s 过渡，否则卡片会从左上角散开。
- **`arranged` 决定加载形态**：页面级存 `arranged`（`Layout::SCHEMA = 5`）。为 1 时加载即重建自由画布（还原用户排好的样子）；为 0 时走普通文档流（无 `is-free`、无内联 `height`、无绝对坐标），页面不闪、卡片不延迟。
  - 只有**退出编辑器**才把 `arranged` 置 1 并 `persist()` —— 用户确认过的排布才值得在下次加载重建。
  - 「恢复默认」把 `arranged` 清 0（用 `resetting` 标记避免紧接着的退出又把它写回 1），刷新后回到默认流式。
  - **不要再靠推断**（顺序、`col`、单个 `h`）判断「有没有排过」：引擎每次加载都会把自己的顺序/列写回，旧数据里也残留过零散高度值，推断会把没排过的页面误判成排过，于是又闪。
- `reflow()`（`layout-refresh` / 断点切换）只在 `editing` 为真时重打包，否则一次 AJAX 重绘会把普通页面悄悄切成画布。
- 保存过的高度（`data-sakura-h`）在流式下退化为 `min-height`：保住用户设定的下限，又不会把卡片拔出文档流。
- **首屏隐藏卡片的那条 CSS 只对 `.is-free` 生效**（`layout.css` 的 `@media (min-width: 1101px)`）。历史教训：它曾经命中 `.card-grid`，而默认看板只满足「隐藏」不满足「`.is-laid` 显示」，整页卡片空白两秒才被兜底动画放出来——就是「卡片被延迟加载」。改这条规则前先确认选择器里带 `.is-free`。
- 页面脚本只用 `window.SakuraAdmin`：`ready`、`qs`、`qsa`、`on`、`delegate`、`closest`、`dataBlock`、`format`；请求用 `admin.api.ajax/post`；提示/确认/忙碌用 `admin.ui.toast/confirm/busy`。不要引入 jQuery。
- 所有可见文案走 PHP 的 `__()` 后放进 `Page_Assets::set_data()`，JS 不内嵌未翻译中文。
- 布局页里的表格/列表如果是 AJAX 渲染，渲染完成后必须 `admin.events.emit('layout-refresh')`；`layout.js` 会重新测量绝对定位画布。否则画布仍按加载行高度固定，异步内容会压到 WordPress 页脚。
- 迁移/新增页面后验证 1440/1366/1101/782/424/390/320、逐卡保存、拖拽布局与控制台；不要留下半迁移页面。

## 10. 重构守恒与控件尺寸

这一节来自 2026-11 几次后台改版的回归：整页重构时删掉了原有按钮，操作区被挪到别处，筛选框和输入框又被 `width:100%` 拉满。以后按「先守恒、再限宽、后验收」执行，不要把一次样式调整做成整页重写。下面的宽度是验收标准，不会由全局 CSS 自动套用；具体页面和组件要在父容器上实现上限。

### 10.1 重构守恒：只改表现，不删功能

- 样式重构的边界是外观和排布，不是信息架构。页面 id、tab 参数、路由、卡片 id、表单 `name` / option 键、nonce、`data-*` 绑定、按钮 `id`、JS 初始化入口都不改；确实要改时单独提交，并保留旧地址和旧 option 的兼容。
- 动手前在 1440 和 424 各截一张图，并清点当前页面的操作入口（按钮、链接、下拉、保存条）和表单控件（输入框、勾选框、单选、文本域）的数量与文案。改完用同一份清单逐项对照：操作入口数量不得减少，表单控件不得减少；确认重复或被等价控件替代的除外，但要在清单里写明替代关系。按钮文案不得悄悄换成同义但更难懂的说法。
- 不允许以「统一」「精简」「更像新版」为理由，把卡头操作、保存按钮、导出、刷新、返回等入口删掉、藏进折叠区、降级成纯文字或移到页面底部。要删或合并入口，必须同时满足：只有这一个入口重复、替代入口可见且能完成同一件事、在提交说明里写明删了什么、为什么删、替代入口在哪。
- 去重时保留一个可见入口，不要整组删掉。删除队列用卡内四个状态块替代「全部状态」下拉，但状态块和空状态的返回入口必须保留；S3 文件核对把操作移到卡头后，筛选行、搜索框和原有 JS 绑定仍要在。
- 桌面默认布局守恒：页头（眉标 → 标题 → 说明 → 操作，状态卡在右）、卡片顺序、卡头操作（右上）、卡内保存条（右下）、筛选行（卡内第一块）都保持原位。允许因文案变长、控件换行带来的高度变化，不允许无故换列、换行、把卡头按钮搬到卡内、把保存条搬到页面底部。需要调整顺序时改 DOM，不要只用 `order` 或 `grid-area` 把视觉顺序和键盘顺序拆开；布局引擎的拖拽排序是已知例外，按 `layout.js` 的焦点和键盘操作单独验证。
- 窄屏重排是唯一允许的大幅位移：≤1100 / ≤782 可以单列、换行、把动作落到标题下方，但 1440 的默认布局不能先按移动版堆叠。
- 一次提交只做一类事：布局重构不要顺手改路由、改文案、改 option 名、改组件 API；反过来，功能改动也不要夹带整页样式重写。单页重构尽量一页一个提交；共享组件改动要列出受影响页面。提交说明写清保留了什么、移动了什么、删除了什么。
- 如果页面确实需要重新设计而不是修样式，按新页面处理：先说明目标与信息架构，拿到确认，提供改版前后 1440/424 截图，不要混在「样式微调」里。

### 10.2 控件尺寸：`width:100%` 只填自己的列

全局输入框、下拉、文本域默认 `width:100%`，含义是填满所在字段列，不是填满卡片。父容器必须给上限；筛选行、工具行、字段行都不能让一个控件吃掉整行。

| 控件 | 建议宽度 | 上限 | 写法 / 说明 |
| --- | --- | --- | --- |
| 列表搜索框 | 240px | 280px | `flex: 0 1 240px; width: 240px; max-width: 280px`，不要 `flex:1` |
| 状态/类型筛选下拉 | 160px | 180px | `width:auto; min-width:140px; max-width:180px`，按最长选项定宽 |
| 短文本（名称/标题/slug/备注） | 260px | 360px | 放在两列字段网格里，只填自己的列；列更宽时加列，不拉长控件 |
| 数字（端口/容量/间隔/条数） | 112px | 140px | `max-width:140px`，不要和 URL 同宽 |
| URL / 路径 / 长文本 | 100% 列宽 | 560px | 单列长字段用 `field--full`，控件 `max-width:560px` |
| 密码 / 密钥 / Token | 100% 列宽 | 420px | 不要为了「看得全」把密钥框拉满整张卡 |
| 颜色 | 48px | 56px | 与输入框同高 38px，不要拉成一条 |
| 文本域 | 100% 列宽 | 640px | 多行说明才用；不要用文本域代替单行短文本 |
| 勾选框 / 单选框 | 18×18px | 18×18px | `flex:0 0 18px`，永远不随容器拉伸 |
| 按钮 | 内容宽 | — | 卡头小按钮 30px 高；普通按钮 36px；桌面不做整宽按钮 |

整宽按钮只允许出现在 ≤782px 的卡片保存条、弹窗底部和空状态主操作；桌面卡头、卡内和筛选行按钮一律内容宽。

补充：

- 字段网格默认两列，列宽 `minmax(0, 1fr)`；≤1100 改单列。单列时输入框可以填满卡片内容宽，但 URL/路径/密钥仍要遵守 420–560px 上限，不能一路铺到 1200px。
- 同一行里的输入框、下拉、按钮底边对齐（38 / 38 / 36），不要用 `margin-top` 假装对齐；高度由 `--g-ctl-h` / `--g-btn-h` 统一。
- 工具栏只放筛选时，控件靠左、按钮 `flex:none`，剩余空间留白；不要把搜索框 `flex:1` 撑满，也不要把下拉写成 `width:100%`。只有需要「搜索 + 右侧动作」时才用 `margin-left:auto` 把动作推到右边。
- ≤782px 工具栏可以换行，搜索/下拉可以各占一整行（此时 `width:100%` 是允许的），但必须一控件一行、页面不横向溢出；勾选框仍是 18px。
- 勾选框的外层 `label.check` 可以是整行可点区域，但勾选框本体固定 18×18，文字靠左，不要用 `justify-content:space-between` 把框和文字拉到两端。
- 多选组（格式/尺寸 chip）用独立的 `grid` 容器：桌面 4 列 `minmax(0,1fr)`、≤500px 改 2 列；chip 等宽、`min-height:40px`，勾选框 18×18；说明文字放 grid 外的 notes 块，不参与列宽计算。
- 控件宽度要和内容匹配：数字/颜色/端口短，URL/路径长；同组字段宽度不一致时用网格列对齐，不要用空格或固定 margin 凑。下拉选项过长时用省略号或 `title` 提示，不要靠不断加宽下拉解决，筛选下拉保持 180px 上限。
- 新增控件先选类型再定宽；没有合适类型时用 `custom`，不要在页面里再写一条 `width:100%` 然后靠 `max-width` 一页页补，宽度上限优先加在公共容器/组件上。

### 10.3 重构验收

- 1440 与 424 各截一张改版前后对照图；1440 检查操作入口、卡片顺序、卡头动作、保存条位置；424 检查换行后按钮仍完整、页面不横溢。
- 逐项点击改过的按钮/链接，确认 JS 绑定仍在（`id`、`data-sakura-action`、`data-*` 没有在搬 DOM 时丢掉）；保存一次表单，确认 option 键和 hidden 0 没有被改坏。
- 用 DOM 计数复核：在控制台执行 `document.querySelectorAll('.sakura-admin .page-body button, .sakura-admin .page-body a.btn, .sakura-admin .page-body select, .sakura-admin .page-body input, .sakura-admin .page-body textarea').length`，数量与改版前一致；少一个就要能说出去处。再检查可见性（`offsetParent !== null`），不要用 `display:none` 或 `visibility:hidden` 把入口藏掉来凑数量。
- 1440、1920 拉宽页面，筛选和表单控件不能跟着无限变宽；782、424、390、320 缩窄，工具栏换行、字段单列、页面 `scrollWidth == clientWidth`。
- 改完在本文件补一条规则或自检项；如果这次重构推翻旧规则，删掉旧规则而不是两份并存。

## 11. 浏览器验证流程

目标：一次加载拿到尽量多的事实，只对真实用户路径做最终验收；不要把每个假设都变成一次整页重载。

- 先静态后浏览器：`php -l`、`node --check`、CSS 花括号计数、`git diff --check` 全过后再开浏览器。同一批改动只重新加载一次（必要时带一次 cache-bust 参数），后续探针复用同一页面，不要每改一个值就刷新。
- 先隔离后流程：怀疑某个 CSS/JS 状态导致问题时，先用一次 `browser_evaluate` 在当前页面里切换/还原可疑状态，确认机制；机制确认后再走真实点击。隔离探针只用于定位，不作为最终验收。
- 最终验收至少有一次真实点击：定位元素优先 `browser_find`，需要整体结构时才 `browser_snapshot`；每次操作后重新取 ref，再用 `browser_click`。`element.click()` 不会移动焦点，滚动/焦点问题很容易被测成假通过或假失败；只能用 evaluate 时，先 `focus({ preventScroll: true })`，并把它当辅助证据。
- 一次 evaluate 批量取指标：同一次调用里返回打开前/打开中/关闭后的 `scrollY`、`document.activeElement`、dialog 是否存在、body/html 内联样式、关键元素的 `getBoundingClientRect()`、`scrollWidth/clientWidth`。不要为每个数值单独往返。
- 视口切换用 `browser_resize`，复用当前页面，不重新导航；只有服务端按宽度/UA 输出不同 HTML 或需要清缓存时才刷新。切换后等一帧再量布局。
- 在问题发生的状态验证：滚动/焦点问题先把页面滚到非零位置（如 600）并让触发元素在视口内；在顶部测等于没测。移动端问题在 390 复现，不要只在 1440 推断。
- 等真实就绪信号：AJAX 表格等 `tbody tr` 数量或目标选择器出现，不要用固定 `setTimeout(2200)` 赌加载；需要动画结束时等 `transitionend` 或两次 `requestAnimationFrame`。
- 截图和 console 只收尾各做一次：只有布局有歧义、需要给用户看结果时才截最终态；console 在完整流程结束后查一次。中间态不截图、不逐条打印。
- 验证范围按第 8 节的宽度规则选择；纯视觉小修不机械跑七宽度/全路由，涉及响应式或共享样式才加宽覆盖。
- 文档同步放在代码稳定后：一次改完对应条目和自检项，不在调试过程中反复改文档。

## 12. 自检清单

- [ ] 没有新的硬编码色相：樱色一律取自 `--g-sakura-*` / `--sakura-*`，没有页面私调的粉、红、蓝；没有位图或 SVG 背景，画布图案只来自 `base.css` 的渐变与点阵。
- [ ] 进度条（统计页平均压缩率、同步中心覆盖率与核对进度）在 1.5% 这种极小值下仍能看到一段条体，不是空白也不是一个点；条体颜色来自 `--g-fill`，轨道来自 `--g-track`。
- [ ] 所有横向留白来自 `--g-*` 令牌。
- [ ] 卡片只有一层边框，内部没有卡中卡。
- [ ] 正文不小于 13.5px，辅助信息不小于 12.5px。
- [ ] 所有勾选/单选控件都是 18px 圆点样式，勾选态为深樱底 `--g-select` + 暖白点，白点绝对居中，没有黑方框白勾或白点漂到左上角。
- [ ] 同一张卡片里的布尔项全部是勾选框，没有 `.switch` 与 `.check` 混用，也没有 `type=toggle`。
- [ ] `.page-body` 直接子块之间至少 16px（桌面 20px），KPI 网格 `gap` 使用同一令牌，没有 0 间距贴合。
- [ ] 每张表只有一个 `<thead>`，没有重复表头。
- [ ] 布局页的 `data-sakura-layout` 与 `Page_Assets::LAYOUT_PAGES` 一致，右侧「布局修改」存在且旧布局能恢复；竖排四字完整，编辑态恢复横排。
- [ ] 顶栏模块导航中心与页面内容中心偏差 ≤1px；≤1100px 已回到两行 flex 布局。
- [ ] 刷新布局页时首屏不横向跳变，卡片没有从堆叠到散开的动画；首屏 HTML 的根节点已带 `sakura-layout-enabled`。
- [ ] **布局页加载不闪、不延迟**：任意布局页 `document.querySelector('.sakura-layout-grid').className` 里没有 `is-free`、没有内联 `height`，卡片 `style` 里没有 `left/top`；所有卡片 `getComputedStyle(c).visibility === 'visible'`（不得出现空白卡片等 2s）；滚动位置加载全程不跳。已保存的高度以 `min-height` 体现，不是绝对定位。
- [ ] 「布局修改」可进入自由画布、可拖动；**退出后布局保持排好的样子，刷新后仍然保持**（`arranged=1` 会重建画布）；点「恢复默认」后刷新回到文档流。
- [ ] AJAX 表格/列表加载完成后画布重新排版，WordPress 页脚不覆盖最后一行内容。
- [ ] 提示弹窗（`.sakura-modal`）居中、可 Esc / 点遮罩 / 关闭按钮关闭、关后焦点回到触发元素（页面重载的场景靠 `return_to` 指回触发按钮，不能落到 `<body>`）；「不再提醒」持久化；同一问题没有重复的顶部提示。
- [ ] **弹窗不改变位置**：在 `scrollY=1500` 这类深位置打开弹窗，再用 X / 按钮 / Esc / 遮罩四种方式关闭，`scrollY` 必须回到原值（误差 0）；页面内容 `getBoundingClientRect().left/width` 前后一致；焦点回到触发元素但**不得把页面滚进视野**。三类弹窗（`.sakura-modal` / `.sakura-dialog` / `.sakura-media-dialog`）逐类验。
- [ ] **保存不把人弹回顶部，也不出现两段跳**：滚到卡片保存条附近点保存，弹窗出现的那一刻页面就已在原位置（锁定时 `body.style.top` 等于保存的 offset），关闭后 `scrollY` 与点击前一致（误差 0）；换到另一个页面再保存不会套用上一页位置；普通刷新不受影响（key 已消费）。
- [ ] 保存与检测结果只以提示弹窗出现：DOM 里没有 `notice-success` / `settings-error` 之类的 WordPress 原生提示，也没有同一事实的页内副本（`settings_errors()` 只由页面外壳消费一次）。
- [ ] 全站 `admin_notices` 输出走 `Feedback::admin_notice_html()`，页面里没有裸的 `notice notice-*`。
- [ ] 确认弹窗（`.sakura-dialog`）只有标题 + 一句正文 + 两个按钮；危险动作用 `.btn--danger`，取消用 ghost；Esc 可关；打开后不锁页面滚动。
- [ ] 文件核对详情打开媒体预览而不是跳媒体库：图片显示 + 下载、视频自绘控件 + 下载、其他类型只有说明 + 下载；PHP 首屏与 AJAX 重绘两处按钮一致。
- [ ] 媒体弹窗 1440 下图片四周留白 ≥20px、无内滚动条；≤782px 全屏、无横向溢出；弹窗没有 UA 黑描边/焦点环；关闭后焦点回到触发项、页面仍停在打开前的滚动位置，视频已暂停。
- [ ] 删除申请「查看文件」能看到申请内全部文件及冻结文件夹快照；多文件时左右按钮、计数、键盘 ←/→ 和横向滑动都能切换，首尾按钮禁用，下载/标题/路径随文件更新。
- [ ] 审核队列操作列是「批准 / 驳回 / 查看文件」三个按钮，对象列没有第二个「查看文件」；桌面按钮中线与状态胶囊、提交时间偏差 ≤2px；≤782px 三个按钮靠单元格右缘排布，320px 不横向溢出。
- [ ] 已保存配置的选中卡片没有黑色内描边；键盘 Tab 到卡片时仍有柔和的 `--g-select-focus` 焦点环。
- [ ] 未勾选「不再提醒」时，X / Esc / 点背景 / 「暂不处理」关掉后刷新会重新弹出；勾选后关掉刷新不再弹；旧的 `snoozed_until` 残留会被自动删除且不压制弹窗。
- [ ] 状态胶囊/页头标签语义正确。
- [ ] 页头按钮在说明下方、不与状态卡同排；所有页头按钮都有 1px 描边。
- [ ] 卡头三个动作与标题同排；≤782px 落到标题下方右对齐，按钮可换行，标题没有被挤窄。
- [ ] 改版前后操作入口（按钮/链接/保存条）和表单控件清单一致，没有误删，也没有被 `display:none` / `visibility:hidden` 藏掉；删除或合并的入口有可见替代路径和说明。
- [ ] 页头、卡片顺序、卡头动作、卡内保存条位置没有被无理由大挪；1440 默认布局仍按原信息层级，只有 ≤1100/≤782 才重排；除布局引擎拖拽排序外，DOM 顺序与视觉顺序一致。
- [ ] 列表筛选行搜索框 ≤280px、下拉 ≤180px，没有用 `flex:1` 或 `width:100%` 拉满；≤782px 才允许整行。卡头已有三个按钮时，搜索框没有被塞成第四个控件。
- [ ] 用户容量等固定列宽表格在 783–1100px 不内滚，≤782px 已重置为 `table-layout:auto` / `width:auto`；行内文件/文件夹分成两行而不是三段竖排。
- [ ] 字段控件有宽度上限：短文本 ≤360px、URL/路径 ≤560px、密钥 ≤420px、数字 ≤140px、颜色 ≤56px；勾选/单选固定 18×18，不被容器拉伸。
- [ ] 删除队列只有卡内一层状态块，没有外层同义大 KPI，也没有重复的「全部状态」下拉；文件核对的操作在卡头，筛选行在 782 以上保持一条。
- [ ] 移动端标题区换行、按钮不挤、页面不横溢。
- [ ] 页签单行完整，最后一个页签在 320px 也不被裁切。
- [ ] 窄屏内容型表格已重排为带 `data-label` 的行，没有 860px 宽的隐藏溢出。
- [ ] 审核队列 ≤782px 是「标签 | 值」紧凑两列列表，列名 12.5px 且看得清，操作行靠右且与信息区有分隔线，无操作行不显示空的「—」行。
- [ ] 每张可编辑卡片是独立表单，表单内有 `Card_Form::fields()`（单数组 option 用 `option_fields()`）标记，点保存只写本卡；未勾选的 checkbox 能保存为 0；页面没有 `.page-savebar` 或浮动保存条残留。
- [ ] 页面骨架、卡片、表单壳和保存条来自 `UI::page()` / `UI::card()`，视图没有手写这些骨架。
- [ ] 页面只加载 `assets/ui/` 的 tokens/base/components（可选 layout）和对应 `pages/*.css`，没有旧 `admin-*.css` / `userfiles-admin.*`。
- [ ] 系统设置外观项逐项保存后生效，恢复默认后根节点不再输出行内 `--g-page-max` / `--g-card-surface` / `--g-card-border-width` / `--g-radius-card`，只有自定义页面背景会输出 `background`。
- [ ] 颜色字段使用 `input[type=color]` 的组件样式，没有露出浏览器默认边框或方角。
- [ ] 没有 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`、`.app`、`.hero`、`.card-savebar`、`.page-savebar` 等旧标记。
- [ ] 浏览器验证按第 11 节执行：一次加载、批量取指标、至少一次真实点击；滚动/焦点问题在非零位置验证；验收宽度按改动范围选择，没有机械跑七宽度/全路由。
- [ ] `php -l`、`node --check`、CSS 花括号计数、`git diff --check` 通过。

## 13. 新功能页面开发清单

这一节把 2026-11 那轮改版踩过的坑收成可执行步骤。**按顺序做，每步都有可验收的判据**；跳过其中任何一条，都会以「加载时闪一下」「保存后跳顶部」「弹窗关不掉」的形式回来。

### 13.1 接入前：先把数据契约定下来

1. **页面 id 决定一切**：`Registry` 里的 tab id 同时是 `assets/ui/pages/{page}.js` 的文件名、`data-sakura-page` 的值、以及 `LAYOUT_PAGES` 的键。定错之后 CSS 作用域、资源和布局全部对不上。
2. **要拖拽布局的页面**：在 `Page_Assets::LAYOUT_PAGES` 登记 `页面 id => 布局 id`；布局 id 必须是**生产已存**的组名（如 `files-zibll` → `files-space`），改名等于清空用户布局。
3. **新增一张卡**：在 `Layout::DEFAULT_LAYOUT` 对应页面补 `order` 与 `cards` 条目。漏了不会报错，卡片会被追加到画布末尾。

### 13.2 写视图：用组件，不要手写骨架

- 页面 `UI::page()`、卡片 `UI::card()`、字段 `UI::field()`。**不要手写 `section.card > .card__head > .card__body`**，也不要在视图里重复 nonce、卡片标记和保存按钮。
- 卡片是**一个独立表单**：`UI::card( array( 'form' => array( ... ), 'save' => '保存设置' ), $body )`。不要多卡共享一个表单，也不要整页保存条。
- 需要反馈时按 5.1 的表选通道：**动作结果用 `UI::modal()`**、持续状态用 `UI::alert()` / `UI::notice()`、AJAX 小动作用 `admin.ui.toast()`。
- 新文案一律 `__()` 后塞进 `Page_Assets::set_data()`；JS 不内嵌未翻译中文。

### 13.3 加样式：只写令牌，不写新色

- 颜色只用 `tokens.css` 的 `--g-*` / `--sakura-*`。**不要新增第二种粉或第二种红**；需要多档色块就用 `--g-sakura-1..4`。
- 进度条用 `.progress` + `.progress__bar`（已带 `display:block`）。**自写进度条若换成行内元素，`width` 会被忽略、条完全不可见**——统计页曾经整条空白。
- 卡片底色与角落泛光分开写：`background-color` 放令牌、`background-image` 放 `--g-card-bloom`，否则系统设置里的自定义卡片色会被盖掉。

### 13.4 布局与滚动：这几条不满足就等于没做完

这是本轮回归最集中的地方，逐条都是实测出来的：

| 判据 | 怎么验 |
| --- | --- |
| 加载不闪、卡片不延迟 | 卡片 `visibility` 全为 `visible`；`.sakura-layout-grid` 无 `is-free`、无内联 `height`；卡片 `style` 无 `left/top` |
| 保存不跳顶部 | 滚到卡片保存条附近点保存，弹窗出现那一帧 `body.style.top` 已等于原 `scrollY`，关闭后位置误差 0 |
| 弹窗不改位置 | 深位置（如 `scrollY=1500`）用 X / 按钮 / Esc / 遮罩四种方式关闭，`scrollY` 均回到原值；焦点回触发元素但**不把页面滚进视野** |
| 页面不横溢 | `document.documentElement.scrollWidth === clientWidth`（1440 / 390 / 320 各测） |
| console 干净 | 无 error；特别注意 SVG 的 `width` / `height` 必须是单个数字，写成 `"16 16"` 会报 `Expected length` |

**机制备忘**（改之前先读，别重新发明）：

- 锁滚动用 `admin.ui.scrollLock`，**不要用 `body { overflow: hidden }`**：它会让视口变宽、整页横向抖动，Chrome 还会把页面拉回顶部。
- 保存往返（POST → 302 → 新文档）要把 `路径|page + scrollY` 存进 `sessionStorage.sakuraAdminSaveScroll`，**在自动弹窗打开之前恢复**；screen key 不含 `t`（部分 saver 重定向回来不带 tab）。
- 布局加载失败的两个历史原因：`isCustomised()` 靠推断（顺序/`col`/零散 `h`）判断「排过布局」——引擎自己会写回这些值，推断必然误判；以及首屏隐藏规则命中 `.card-grid` 却要求 `.is-laid` 才显示，导致整页空白两秒。现在由 `arranged` 显式标记决定，隐藏规则只对 `.is-free` 生效。

### 13.5 交付前

- 静态先行：`php -l` 改过的 PHP、`node --check` 改过的 JS、CSS 花括号配平、`git diff --check`。
- 浏览器实测：至少 1440 一次完整路径（保存一次、开一次弹窗、关一次），390 抽查不横溢。
- 改到共享层（`tokens` / `base` / `components` / `layout.js` / `ui.js`）时，1440 覆盖全部后台路由，390 抽查至少 3 页代表页。
- 本文件同步更新：新增规则、或推翻旧规则时**删掉旧的**，不要两份并存。
