# 后台 UI 规则

适用范围：图片优化（`sakura-tools-image`）4 页、对象存储（`sakura-tools-storage`）3 页、文件空间（`sakura-tools-files`）5 页、系统设置（`sakura-tools`）与批量处理页。后台只有一套样式栈，全部页面加载 `assets/ui/`；旧的 `admin*.css`、`admin.js`、`admin-layout.js`、`userfiles-admin.*`、`userfiles-space-admin.*` 已删除，不要再引用，也不要新造第二套皮肤。

## 1. 样式分层与加载顺序

`src/Admin/Page_Assets.php` 按以下顺序加载：

1. `assets/ui/tokens.css`：设计令牌，只有 `:root`。
2. `assets/ui/base.css`：画布、共享顶栏、模块切换、页签、页头、通用排版与 WP 管理页槽位重置。
3. `assets/ui/components.css`：卡片、表单、按钮、状态、保存条、弹窗、表格、提示等组件。
4. `assets/ui/layout.css`：12 栏卡片拖拽布局，只给登记在 `Page_Assets::LAYOUT_PAGES` 的页面加载。
5. `assets/ui/pages/{module}.css`：模块级样式；模块名取页面 id 的第一段，例如 `image-rules` → `image.css`。
6. `assets/ui/pages/{page}.css`：单个页面的补充样式，只在文件存在时加载。

脚本顺序固定为 `core.js` → `api.js` → `ui.js` →（可选）`layout.js` → `pages/{page}.js`。

规则：

- 视觉修改只写 `assets/ui/`。页面专属样式优先复用 `components.css`；确属一次性的少量规则，先放模块 stylesheet，超过约 50 行再拆 `pages/{page}.css`。
- 禁止新增粉色、天蓝色；禁止贴图、云朵 SVG、樱花 SVG。唯一强调色是石墨。
- 不要用 `!important`。若确需覆盖 WordPress 管理页槽位（`#wpcontent` / `#wpbody-content`），先用 `:has(.sakura-admin)` 限定作用域，再在评审中说明原因。
- 页面根节点由 `UI::page()` 输出 `.sakura-admin[data-sakura-page]`。不要再手写 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`、`.app` 这些旧根节点。
- WordPress 媒体库的样式独立放在 `assets/ui/wp-media/`，由 `Media_Hooks` 按屏幕单独加载，不属于后台页面布局，不参与上表顺序：`media-library.css` 只加载于 `upload.php`；`media-modal-folders.css` 在除 `upload.php` / `media-new.php` 外的后台屏幕加载（媒体弹窗可从任意页面打开，因此后台页面上看到它是正常现象）；`media-new-folders.css` 只加载于 `media-new.php`。

## 2. 设计令牌

颜色（`tokens.css`）：

| 变量 | 值 | 用途 |
| --- | --- | --- |
| `--g-ink` | `#1b1c1e` | 主文字、主按钮 |
| `--g-ink-soft` | `#34363a` | 正文、表格 |
| `--g-muted` | `#5f6165` | 说明、次级信息 |
| `--g-faint` | `#6b6c68` | 眉标、辅助信息下限 |
| `--g-line` | `#e4e1d9` | 卡片边框 |
| `--g-line-soft` | `#eeece5` | 卡片内分隔线 |
| `--g-line-strong` | `#d2cfc6` | 输入框边框 |
| `--g-paper` | `#f6f5f1` | 页面底色 |
| `--g-card` | `#ffffff` | 卡片内控件（输入、按钮）白底 |
| `--g-card-surface` | `#ffffff` | 卡片表面；系统设置可改，只作用于 `.card` |
| `--g-wash` | `#fbfaf7` | 弱底、嵌套面板 |
| `--g-accent` | `#232528` | 唯一强调色（石墨） |
| `--g-accent-soft` | `#efeee9` | 强调色弱底 |
| `--g-select` / `--g-select-dot` | `#2f3134` / `#f6f4ed` | 勾选/单选底与点 |
| `--g-ok` / `--g-ok-bg` | `#33704e` / `#edf5f0` | 成功 |
| `--g-warn` / `--g-warn-bg` | `#8a651d` / `#faf3e4` | 警告 |
| `--g-bad` / `--g-bad-bg` | `#a3463d` / `#fbedeb` | 错误、破坏性操作 |

- 页面只允许一个强调色。状态色只表达状态，不拿来当装饰；「等待配置」用中性或琥珀，不用绿色。
- 图表、donut、进度条用中性阶：`#2b2d30`、`#5f6265`、`#9a9b96`、`#dcdad3`；空数据 `#e9e7e1`。

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
- 列表卡的工具行负责筛选，不再承担主操作。主操作（重新核对、导出、补建）放卡头 `actions`，与标题同排；筛选行的搜索框约 240px、下拉约 140px，保持一条，≤782px 再换行。
- 状态块使用 `aria-current="true"` 表示当前筛选；再次点击当前状态回到全部，不另设同义下拉。若页面还有其他筛选下拉，必须与状态块同步（同一 `state` 参数）。

## 5. 页头与状态

- 页头由 `UI::page()` 输出：上方眉标（`.page-head__eyebrow`）、标题、1–2 行说明（`.page-head__lede`），说明下方是可选的页头操作（`.page-head__actions`，靠左，与说明间距 20px）；状态卡单独放右侧，不与操作按钮同排。页头底部一条 `--g-line` 分隔线。不要再使用粉蓝渐变、樱花插画或高对比大面积色块。
- 背景默认是暖白底 + 柔和云雾径向渐变（`base.css` 的 `.sakura-admin` 画布）；系统设置可以换成单一纯色，但默认值必须保持这套暖白渐变，禁止贴图。
- 顶栏状态胶囊支持 `ok / off / warn / error`：未传 tone 时是中性灰点；`ok` 绿点，`off` 灰字，`warn` 琥珀，`error` 淡红。不要所有状态都用绿点。
- 页头状态卡与页头内的小状态标签同样遵循语义，不要用绿色表示「等待配置」。

## 5.1 弹窗

- 需要用户立即处理、且忽略后会影响功能的配置缺失（如 S3 未配置），用居中 modal，不再用顶部 `notice`。同一问题不能「顶部提示 + 弹窗」重复出现。
- 复用 `.sakura-modal` 结构，配置类加 `.sakura-modal--config`：560px 宽、18px 圆角、1px `--g-line` 边框、白底、深而柔的阴影；`max-height: calc(100dvh - 32px)`，内容超高时卡片内部滚动。
- 结构固定为：图标 → 大写眉标（`.sakura-modal__kicker`）→ 标题 → 1–2 行说明 → 缺失项面板（小标 + 胶囊列表）→ 底部「不再提醒」+ 次按钮 + 主按钮。底部操作区与正文用 `--g-line-soft` 分隔。
- 配置缺失不是错误：图标、胶囊用暖灰/琥珀状态点，不用红色；红色只留给破坏性操作。
- ≤520px 时底部操作区纵向排列，「不再提醒」靠左、按钮整宽；次按钮保留描边和暖白底，不能变成无边界的一行文字。390/320 下弹窗不得横向溢出。
- 行为：配置不完整时延迟约 220ms 自动打开；`role=dialog` / `aria-modal` / `aria-hidden` 成对；打开后焦点进标题，Tab 焦点圈在弹窗内，Esc / 背景 / 关闭按钮都能关闭，关闭后焦点回到触发前元素。
- 关闭分为两档：
  - 「不再提醒」按用户持久化（`sakura_imgopt_s3_config_notice_dismissed`），勾选后不论用哪种方式关闭都永久保存，直到手动清除该 user meta。
  - 未勾选时，任何关闭方式（稍后处理、X、Esc、背景、主按钮跳转）都只延后 12 小时（`sakura_imgopt_s3_config_notice_snoozed_until` 存 Unix 时间戳）；12 小时内不再自动弹出，12 小时后配置仍不完整则再次弹出。
- 主按钮跳转下一页时保留 sessionStorage 一次性跳过，作为 AJAX 写入失败时的兜底；不能把 sessionStorage 当作长期静默，长期状态只认 user meta。
- 锚点主按钮关闭弹窗后滚动到表单并聚焦第一个空字段。
- 弹窗节点由页面根节点输出，遮罩覆盖整个视口；不要依赖旧 `.app` 的堆叠上下文。
- 打开时锁 body 滚动；`prefers-reduced-motion` 下关闭入场动画。

## 6. 控件

- 输入框、下拉、文本域：38px 高、10px 圆角、1px `--g-line-strong` 边框、白底、14px 字。文本域最小高度 88px，可纵向拉伸。
- 颜色字段用 `UI::field( 'type' => 'color' )`：与输入框同高、同边框，内层 swatch 留 1px 暖灰内描边并跟随 10px 圆角（纯白卡片底色也要能看出色块边界）；不要手写 `<input type="color">` 绕过组件。`::-webkit-color-swatch` 与 `::-moz-color-swatch` 必须拆成两条规则：合并后 Chrome 不认识 Mozilla 伪元素，会把整条规则丢弃（实测边框不生效）。
- 勾选框/单选框统一 18px 圆形，不再区分方框与圆圈。基础态为暖白底 `#fffdfa` + 1.5px `#c3c0b7` 边框；hover 边框 `#9b978d`。
- 勾选态必须改变填充，不能只换边框：统一为柔石墨底 `#2f3134` + 6px 暖白圆点 `#f6f4ed`。勾选/未勾选在 1:1 截图里必须一眼可分；禁止再出现黑色方框 + 白勾。
- 白点必须相对控件绝对居中：`::before` 用 `position:absolute; top:50%; left:50%; margin:0; transform: translate(-50%, -50%) scale(...)`。WordPress 后台 `forms.css` 的 `input[type=checkbox]:checked::before` 会残留 `margin:-3px 0 0 -4px`；若改用 grid/flex 居中而不清 margin，6px 白点会被推到左上角。`margin:0` 不是冗余声明。
- 禁用态为暖灰底/边框；`checked:disabled` 用 `#b8b5ac` 底 + 暖白点。选择控件焦点环 3px `rgba(47,49,52,.16)`。
- 选择控件放在 flex 行里时必须 `flex: none`（或 `0 0 18px`），否则在 320px 会被压成 16px 宽；宽度和高度都属于硬性 18px。
- `appearance: none` 的基础态写了白底后，`:checked` 必须用同等或更高特异性重新声明背景和边框；否则基础态会把勾选态盖掉。
- 卡片里的布尔项一律用勾选样式：`label.check` + `check__box` + `check__text`（`UI::field` 的 `type=checkbox`）。不要在卡片里使用滑动开关 `.switch` / `type=toggle`，也不要同一张卡片一半勾选框一半滑块。历史变量 `$sufm_space_switch` 只是兼容名，输出必须是 checkbox；交互确认仍通过 `data-zibll-switch` 绑定，不依赖控件外观。
- 不在页面根节点内的原生控件（如布局工具条的「显示网格」）要单独给皮肤。
- 焦点环统一为 3px `rgba(32,34,37,.09)`，边框变石墨；不要使用蓝色 outline。
- 主按钮石墨实心白字；次按钮白底描边（页头操作即使传 `quiet` 变体也不能无边框）；危险按钮只在确认场景实心 `--g-bad`。
- 表格表头 12.5px、正文 13.5px；数字列可等宽字体，但不要用蓝色。
- 多列勾选/选项网格里的说明文字必须 `grid-column: 1 / -1` 单独成行，不能被误读成某个选项的标签。

## 7. 移动端

- ≤900px：页头改单列，说明和操作按钮依次排列；按钮可以换行，不要横向滚动，也不要压住状态卡。
- ≤782px：卡片标题区换行，标题占满一行，操作按钮落到下一行并右对齐。
- 页签保持单行：`gap: clamp(10px, 2.8vw, 18px)`、字号 13px；横向滚动时两端用 mask 渐隐，不能露出半个字。验收时最后一个页签必须完整可见。
- 分段控件、日志筛选允许换行；不要用横向滚动藏按钮。
- 表格用 `.table-wrap` 内部滚动，页面本身不横溢。桌面的固定列宽在 ≤782px 必须重置为 `width:auto; min-width:0`。
- 内容型表格（审核队列、授权流水、套餐编辑器）在窄屏重排为带标签的行：数据行用 `data-label` + `::before` 显示列名，表头隐藏；空标签 `data-label=""` 的单元格保持块级，不加冒号。
- 桌面顶栏是三列 grid：品牌左、模块导航绝对居中、状态/动作右；导航中心必须等于页面内容中心，不能被左侧品牌文字宽度推偏。≤1100px 回到 flex-wrap，品牌与状态一行、模块导航整行另起。≤660px 收成「品牌 + 状态」一行；≤340px 隐藏长状态胶囊，避免挤成三行。

## 8. 常见坑

- 后台 tab 参数是 `t`，例如 `admin.php?page=sakura-tools-image&t=thumb`；不是 `tab`。截图验收时用错参数会全部落在默认页。
- 资源门控只看 `Route::current_page()`，不再有迁移白名单。新页面只要注册了路由并调用 `Assets::enqueue_page()` 就会加载 UI 栈。
- 隐藏页面（如批量处理）不在 `Registry` 里，不要调用会反查路由的 `Assets::enqueue_page()`；直接 `Page_Assets::set_data()` + `Page_Assets::enqueue( 'media-progress' )`。
- WP 媒体库样式已迁到 `assets/ui/wp-media/`；不要放回 `assets/` 根目录，也不要让它们参与后台页面布局。后台页面出现 `media-modal-folders.css` 是媒体弹窗的全局依赖，不是页面样式串场。
- 窄屏重排用的 `data-label` 要加在真正承载列名的元素上，且只加一次；`::before { content: attr(data-label) }` 不会走翻译，当前后台仅中文，新增语言时需改为视图内可见标签。
- 勾选框最容易出现「看着能点，但勾没勾一个样」：基础态选择器特异性高于 `:checked` 时，白底会把石墨勾选态盖掉；要在同一特异性补 `:checked`。
- 勾选框白点跑到左上角：先看 `::before` 的计算样式。根因通常是 WP 后台 `forms.css` 的 `checked::before` 负 margin 与 grid/flex 居中冲突；按第 6 节改成绝对定位 + `margin:0`，不要用 `!important` 硬盖。
- 支付方式少了支付宝：页面只渲染主题实际启用的通道。测试站先查 `zibll_options['pay_alipay_sdk_options']` 是否为 `null`；生产为 `official_alipay` 且 `pay_sdk_order.alipay=1`。这是主题数据差异，不要把支付宝开关硬编码进视图。
- 右侧「布局修改」消失：按顺序查 `data-sakura-layout` 属性、`Page_Assets::LAYOUT_PAGES` 登记、布局 id 是否等于生产已存组名；三者缺一都不会加载 `layout.css` 和工具栏。系统设置页的页面 id 与布局 id 都是 `system-settings`。
- 布局工具条文案竖排用「一字宽块 + 自然换行」：`width:1.1em; white-space:normal; word-break:break-all; line-height:1.34; text-align:center`，不要用 `writing-mode: vertical-rl`。部分 CJK 回退字体在这里上报的纵向步进只有约 4px，四字会叠成一团（实测标签高 4.17px）；编辑态恢复 `width:auto; white-space:nowrap; word-break:normal`。改完在 1440 和 1101 截图检查四字完整。
- 改完至少验证 1440、1366、1101、782、424、390、320 七个宽度，覆盖全部 13 个后台路由；检查 `document.documentElement.scrollWidth == clientWidth`、`.page-body` 相邻块间距 ≥16px、同一卡片无 `.switch` 与 `.check` 混用、每张表只有一个 `<thead>`，并确认 console 无报错。

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
- 反馈用 `UI::badge()`、`UI::alert()`、`UI::notice()`、`UI::empty_state()`。
- 新页面不要手写 `section.card > .card__head > .card__body` 骨架，也不要在视图里重复 nonce、卡片标记和保存按钮。特殊版式通过 `wide` / `class` 传给组件，不改组件结构。
- 组件在 `src/Admin/UI.php` 与 `src/Admin/UI/`，`Card_Form` 在 `src/Admin/Card_Form.php`，均由 PSR-4 自动加载。

### 9.2 页面接入

- 在 `src/Admin/Registry.php` 注册页面与 tab；页面 id 同时决定 CSS/JS 文件名。默认 tab 可以省略 `t`。
- 渲染前把该页需要的运行时数据交给 `Page_Assets::set_data( $page, $data )`，再调用 `Assets::enqueue_page()`。数据会进入前端 `SakuraAdmin.data`。
- 页面 CSS 命名：`assets/ui/pages/{module}.css` 是模块共享样式，`assets/ui/pages/{page}.css` 是可选页面样式；JS 只加载 `assets/ui/js/pages/{page}.js`。
- 需要卡片拖拽布局的页面，在 `Page_Assets::LAYOUT_PAGES` 登记 `页面 id => 布局 id`；布局根节点输出 `data-sakura-ready`、`data-sakura-layout`，每张卡同时带 `data-sakura-card`。系统设置页也走同一套，5 张卡（runtime / engine / checks / extensions / layout）默认为 6/6/12/6/6 跨列。
- 映射右侧的布局 id 必须是生产已存的布局组名。改页面 id 或文件名时不要顺手改布局 id：文件空间-子比专属的页面 id 是 `files-zibll`，布局 id 必须保持 `files-space`，否则旧布局丢失，右侧「布局修改」工具栏也不会出现。
- 布局页的 46px 右侧留白由 `Page_Assets::has_layout()` 在服务端输出 `sakura-layout-enabled`，首屏就预留，不能等 `layout.js` 到页尾再补类，否则内容区会在加载后突然变窄。`freeLayout()` 第一次把卡片改成绝对定位时加 `is-initializing` 关闭 0.18s 过渡，否则卡片会从左上角散开。
- 多卡组在首次落位前用 `visibility: hidden` 隐住（`is-laid` 落下后才显示），避免解析较慢时浏览器先把默认两列网格画出来；单卡组不隐藏，因为卡片本身已是 `wide`。隐藏只在桌面宽度生效，并带 2s 的 CSS 动画兜底，脚本失败或被禁用时不会永久空白。
- 页面脚本只用 `window.SakuraAdmin`：`ready`、`qs`、`qsa`、`on`、`delegate`、`closest`、`dataBlock`、`format`；请求用 `admin.api.ajax/post`；提示/确认/忙碌用 `admin.ui.toast/confirm/busy`。不要引入 jQuery。
- 所有可见文案走 PHP 的 `__()` 后放进 `Page_Assets::set_data()`，JS 不内嵌未翻译中文。
- 布局页里的表格/列表如果是 AJAX 渲染，渲染完成后必须 `admin.events.emit('layout-refresh')`；`layout.js` 会重新测量绝对定位画布。否则画布仍按加载行高度固定，异步内容会压到 WordPress 页脚。
- 迁移/新增页面后验证 1440/1366/1101/782/424/390/320、逐卡保存、拖拽布局与控制台；不要留下半迁移页面。

## 10. 自检清单

- [ ] 没有新的粉/蓝硬编码色，也没有背景图案/SVG。
- [ ] 所有横向留白来自 `--g-*` 令牌。
- [ ] 卡片只有一层边框，内部没有卡中卡。
- [ ] 正文不小于 13.5px，辅助信息不小于 12.5px。
- [ ] 所有勾选/单选控件都是 18px 圆点样式，勾选态为柔石墨底 + 暖白点，白点绝对居中，没有黑方框白勾或白点漂到左上角。
- [ ] 同一张卡片里的布尔项全部是勾选框，没有 `.switch` 与 `.check` 混用，也没有 `type=toggle`。
- [ ] `.page-body` 直接子块之间至少 16px（桌面 20px），KPI 网格 `gap` 使用同一令牌，没有 0 间距贴合。
- [ ] 每张表只有一个 `<thead>`，没有重复表头。
- [ ] 布局页的 `data-sakura-layout` 与 `Page_Assets::LAYOUT_PAGES` 一致，右侧「布局修改」存在且旧布局能恢复；竖排四字完整，编辑态恢复横排。
- [ ] 顶栏模块导航中心与页面内容中心偏差 ≤1px；≤1100px 已回到两行 flex 布局。
- [ ] 刷新布局页时首屏不横向跳变，卡片没有从堆叠到散开的动画；首屏 HTML 的根节点已带 `sakura-layout-enabled`。多卡组在慢速首次加载时也不会先露出默认两列（`is-laid` 前隐藏，禁用 JS 2s 后可见）。
- [ ] AJAX 表格/列表加载完成后画布重新排版，WordPress 页脚不覆盖最后一行内容。
- [ ] 配置类弹窗居中、可 Esc/背景关闭、焦点可回退；「不再提醒」持久化；没有重复的顶部提示。
- [ ] 「稍后处理」写入 12 小时冷却：冷却内刷新不弹，把 `snoozed_until` 改成过去时间后刷新会重新弹出。
- [ ] 状态胶囊/页头标签语义正确。
- [ ] 页头按钮在说明下方、不与状态卡同排；所有页头按钮都有 1px 描边。
- [ ] 卡头三个动作与标题同排；≤782px 落到标题下方右对齐，按钮可换行，标题没有被挤窄。
- [ ] 删除队列只有卡内一层状态块，没有外层同义大 KPI，也没有重复的「全部状态」下拉；文件核对的操作在卡头，筛选行在 782 以上保持一条。
- [ ] 移动端标题区换行、按钮不挤、页面不横溢。
- [ ] 页签单行完整，最后一个页签在 320px 也不被裁切。
- [ ] 窄屏内容型表格已重排为带 `data-label` 的行，没有 860px 宽的隐藏溢出。
- [ ] 每张可编辑卡片是独立表单，表单内有 `Card_Form::fields()`（单数组 option 用 `option_fields()`）标记，点保存只写本卡；未勾选的 checkbox 能保存为 0；页面没有 `.page-savebar` 或浮动保存条残留。
- [ ] 页面骨架、卡片、表单壳和保存条来自 `UI::page()` / `UI::card()`，视图没有手写这些骨架。
- [ ] 页面只加载 `assets/ui/` 的 tokens/base/components（可选 layout）和对应 `pages/*.css`，没有旧 `admin-*.css` / `userfiles-admin.*`。
- [ ] 系统设置外观项逐项保存后生效，恢复默认后根节点不再输出行内 `--g-page-max` / `--g-card-surface` / `--g-card-border-width` / `--g-radius-card`，只有自定义页面背景会输出 `background`。
- [ ] 颜色字段使用 `input[type=color]` 的组件样式，没有露出浏览器默认边框或方角。
- [ ] 没有 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`、`.app`、`.hero`、`.card-savebar`、`.page-savebar` 等旧标记。
- [ ] `php -l`、`node --check`、CSS 花括号计数、`git diff --check` 通过。
