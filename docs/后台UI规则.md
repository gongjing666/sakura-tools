# 后台 UI 规则

适用范围：图片优化（`sakura-tools-image`）4 页、对象存储（`sakura-tools-storage`）3 页、文件空间（`sakura-tools-files`）5 页、系统自检（`sakura-tools`）与批量处理页。后台只有一套样式栈，全部页面加载 `assets/ui/`；旧的 `admin*.css`、`admin.js`、`admin-layout.js`、`userfiles-admin.*`、`userfiles-space-admin.*` 已删除，不要再引用，也不要新造第二套皮肤。

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
| `--g-card` | `#ffffff` | 卡片 |
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
| `--g-radius-card` | 18px（卡片） |
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
| `--g-page-max` | 1480px | 内容最大宽度 |
| `--g-card-pad-x` | `clamp(17px, 2.1vw, 24px)` | 卡片左右内边距 |
| `--g-card-pad-top` | `clamp(18px, 1.9vw, 22px)` | 卡片顶部 |
| `--g-card-pad-bottom` | `clamp(18px, 2vw, 24px)` | 卡片底部 |
| `--g-stack` | `clamp(16px, 1.6vw, 20px)` | 页头与内容之间、页内顶层区块之间的堆叠 |
| `--g-grid-gap-x` / `--g-grid-gap-y` | 20px（系统页可设 0–48px） | 桌面多列卡片网格的左右 / 上下间隔 |
| `--g-space-1` … `--g-space-6` | 6 / 10 / 14 / 18 / 24 / 32px | 组件内部节奏 |

硬性下限：

- 文字到卡片边框至少 16px；嵌套面板内至少 12px。
- 相邻卡片之间至少 16px；卡片网格读取系统页保存的 `Layout::gaps()`，由 `Page_Assets` 写成 `.sakura-admin` 的 `--g-grid-gap-x` / `--g-grid-gap-y` 行内变量。
- 同一条竖直内容线：卡片标题/正文、KPI、页头状态卡、页面级面板都使用 `--g-card-pad-x`；只有卡片内部的嵌套框降一级。
- 不允许再引入 13/15/17/19px 这类一次性内边距；嵌套框统一用 `--g-space-3`，行内上下用 `--g-space-2`，紧凑网格用 `--g-space-2`。
- 页面上下留白用 `--g-app-top` / `--g-app-bottom`，不要在断点里写死 18/20/60。
- 页面不允许横向滚动（`scrollWidth == innerWidth`）。表格放不下时只能让 `.table-wrap` 内部横向滚动。
- `.page-body` 的直接子块（KPI 行、卡片网格、独立卡片）统一由 `base.css` 的 `.sakura-admin .page-body > :not(:first-child) { margin-top: var(--g-stack); }` 拉开。`:not(:first-child)` 的特异性是故意的，用来压过 `components.css` 后面 `.card { margin: 0 }` 的复位；不要改回 `* + *`，否则独立卡片又会和上一块贴在一起。验收时相邻块间距桌面 20px、窄屏 16px，不能出现 0。
- KPI 网格自身也用 `--g-stack` 作 `gap`；不要在单页 CSS 再给 `.kpis` 加 `margin-bottom`，否则会与上面的堆叠叠加成双倍。
- 自适应是重排，不是按设备写死。除极窄屏（≤360px）外，不要为某个机型单独写断点；这个断点只允许给确实放不下的控件做紧凑化。

## 4. 边框与层级

- 一张卡片只有一层可见边框：`1px var(--g-line)` + 18px 圆角 + `--g-shadow-card`。
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
- 只读卡、纯操作卡（清理、探测、刷新、删除队列）不传 `form` / `save`，动作按钮留在卡内的 `.card__actions` 或 `.card__savebar`，不要再造页面级操作条。
- 以「行/项」为保存单位的列表卡（如用户容量）把保存按钮放在对应行内，这是唯一例外。
- 不再使用整页 `.page-savebar`，也不再使用浮动保存条。新增页面直接照本节写，不要回到「多卡共享一个表单、按钮提交整组」的旧模式。

### 4.2 表格与分组

- 一张表只允许一个 `<thead>`。不要把同一组数据 `array_chunk()` 成两张表各自带表头；等级加成曾因此出现两遍「等级 / 门槛积分 / 加成（MB）」。
- 需要分栏或分组时，用单表 + 分组行，或两个不带 `<thead>` 的面板并排；只有打印或固定表头场景才重复表头，且必须是有意为之。

## 5. 页头与状态

- 页头由 `UI::page()` 输出：上方眉标（`.page-head__eyebrow`）、标题、1–2 行说明（`.page-head__lede`），右侧一张状态卡；底部一条 `--g-line` 分隔线。不要再使用粉蓝渐变、樱花插画或高对比大面积色块。
- 背景只允许暖白底 + 柔和云雾径向渐变（`base.css` 的 `.sakura-admin` 画布）；禁止贴图。
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
- 勾选框/单选框统一 18px 圆形，不再区分方框与圆圈。基础态为暖白底 `#fffdfa` + 1.5px `#c3c0b7` 边框；hover 边框 `#9b978d`。
- 勾选态必须改变填充，不能只换边框：统一为柔石墨底 `#2f3134` + 6px 暖白圆点 `#f6f4ed`。勾选/未勾选在 1:1 截图里必须一眼可分；禁止再出现黑色方框 + 白勾。
- 白点必须相对控件绝对居中：`::before` 用 `position:absolute; top:50%; left:50%; margin:0; transform: translate(-50%, -50%) scale(...)`。WordPress 后台 `forms.css` 的 `input[type=checkbox]:checked::before` 会残留 `margin:-3px 0 0 -4px`；若改用 grid/flex 居中而不清 margin，6px 白点会被推到左上角。`margin:0` 不是冗余声明。
- 禁用态为暖灰底/边框；`checked:disabled` 用 `#b8b5ac` 底 + 暖白点。选择控件焦点环 3px `rgba(47,49,52,.16)`。
- 选择控件放在 flex 行里时必须 `flex: none`（或 `0 0 18px`），否则在 320px 会被压成 16px 宽；宽度和高度都属于硬性 18px。
- `appearance: none` 的基础态写了白底后，`:checked` 必须用同等或更高特异性重新声明背景和边框；否则基础态会把勾选态盖掉。
- 卡片里的布尔项一律用勾选样式：`label.check` + `check__box` + `check__text`（`UI::field` 的 `type=checkbox`）。不要在卡片里使用滑动开关 `.switch` / `type=toggle`，也不要同一张卡片一半勾选框一半滑块。历史变量 `$sufm_space_switch` 只是兼容名，输出必须是 checkbox；交互确认仍通过 `data-zibll-switch` 绑定，不依赖控件外观。
- 不在页面根节点内的原生控件（如布局工具条的「显示网格」）要单独给皮肤。
- 焦点环统一为 3px `rgba(32,34,37,.09)`，边框变石墨；不要使用蓝色 outline。
- 主按钮石墨实心白字；次按钮白底描边；危险按钮只在确认场景实心 `--g-bad`。
- 表格表头 12.5px、正文 13.5px；数字列可等宽字体，但不要用蓝色。
- 多列勾选/选项网格里的说明文字必须 `grid-column: 1 / -1` 单独成行，不能被误读成某个选项的标签。

## 7. 移动端

- ≤782px：卡片标题区换行，标题占满一行，操作按钮落到下一行并右对齐。
- 页签保持单行：`gap: clamp(10px, 2.8vw, 18px)`、字号 13px；横向滚动时两端用 mask 渐隐，不能露出半个字。验收时最后一个页签必须完整可见。
- 分段控件、日志筛选允许换行；不要用横向滚动藏按钮。
- 表格用 `.table-wrap` 内部滚动，页面本身不横溢。桌面的固定列宽在 ≤782px 必须重置为 `width:auto; min-width:0`。
- 内容型表格（审核队列、授权流水、套餐编辑器）在窄屏重排为带标签的行：数据行用 `data-label` + `::before` 显示列名，表头隐藏；空标签 `data-label=""` 的单元格保持块级，不加冒号。
- 顶栏在 ≤660px 收成「品牌 + 状态」一行，模块导航另起一行；≤340px 隐藏长状态胶囊，避免挤成三行。

## 8. 常见坑

- 后台 tab 参数是 `t`，例如 `admin.php?page=sakura-tools-image&t=thumb`；不是 `tab`。截图验收时用错参数会全部落在默认页。
- 资源门控只看 `Route::current_page()`，不再有迁移白名单。新页面只要注册了路由并调用 `Assets::enqueue_page()` 就会加载 UI 栈。
- 隐藏页面（如批量处理）不在 `Registry` 里，不要调用会反查路由的 `Assets::enqueue_page()`；直接 `Page_Assets::set_data()` + `Page_Assets::enqueue( 'media-progress' )`。
- WP 媒体库样式已迁到 `assets/ui/wp-media/`；不要放回 `assets/` 根目录，也不要让它们参与后台页面布局。后台页面出现 `media-modal-folders.css` 是媒体弹窗的全局依赖，不是页面样式串场。
- 窄屏重排用的 `data-label` 要加在真正承载列名的元素上，且只加一次；`::before { content: attr(data-label) }` 不会走翻译，当前后台仅中文，新增语言时需改为视图内可见标签。
- 勾选框最容易出现「看着能点，但勾没勾一个样」：基础态选择器特异性高于 `:checked` 时，白底会把石墨勾选态盖掉；要在同一特异性补 `:checked`。
- 勾选框白点跑到左上角：先看 `::before` 的计算样式。根因通常是 WP 后台 `forms.css` 的 `checked::before` 负 margin 与 grid/flex 居中冲突；按第 6 节改成绝对定位 + `margin:0`，不要用 `!important` 硬盖。
- 支付方式少了支付宝：页面只渲染主题实际启用的通道。测试站先查 `zibll_options['pay_alipay_sdk_options']` 是否为 `null`；生产为 `official_alipay` 且 `pay_sdk_order.alipay=1`。这是主题数据差异，不要把支付宝开关硬编码进视图。
- 右侧「布局修改」消失：按顺序查 `data-sakura-layout` 属性、`Page_Assets::LAYOUT_PAGES` 登记、布局 id 是否等于生产已存组名；三者缺一都不会加载 `layout.css` 和工具栏。
- 改完至少验证 1440、1366、1101、782、424、390、320 七个宽度，覆盖全部 13 个后台路由；检查 `document.documentElement.scrollWidth == clientWidth`、`.page-body` 相邻块间距 ≥16px、同一卡片无 `.switch` 与 `.check` 混用、每张表只有一个 `<thead>`，并确认 console 无报错。

## 9. 组件层与静态资源

### 9.1 PHP 组件

- 页面用 `UI::page( $args, $body )`。`$args`：`id`（页面 id，写入 `data-sakura-page`）、`module`、`tab`、`eyebrow`、`title`、`lede`、`status`、`actions`、`chrome`。
- 卡片用 `UI::card( $args, $body )`。`$args`：`id`、`kicker`、`title`、`description`、`actions`、`wide`、`class`、`anchor`、`form`、`save`。
- 表单用 `form` 参数：
  - `id`：表单 id，默认 `sakura-card-form-{card}`；
  - `action`：默认 `options.php`；自定义 saver 传 `admin-post.php`；
  - `option_group`：传给 `wp_create_nonce( $group . '-options' )` 的组名；
  - `markers`：`fields`（默认）、`option_fields`、`none`；
  - `option`：`markers=option_fields` 时的 option 名；
  - `hidden`：额外 hidden 字段。
- 字段用 `UI::field( $args )`，类型支持 `text/number/url/password/email/select/textarea/checkbox/radio/toggle/hidden/custom`；复杂控件用 `custom` + `control` 回调，只外包标签与间距。
- `toggle` 仅为历史兼容保留；后台卡片不要使用，布尔项统一 `checkbox`（见第 6 节）。
- 反馈用 `UI::badge()`、`UI::alert()`、`UI::notice()`、`UI::empty_state()`。
- 新页面不要手写 `section.card > .card__head > .card__body` 骨架，也不要在视图里重复 nonce、卡片标记和保存按钮。特殊版式通过 `wide` / `class` 传给组件，不改组件结构。
- 组件在 `src/Admin/UI.php` 与 `src/Admin/UI/`，`Card_Form` 在 `src/Admin/Card_Form.php`，均由 PSR-4 自动加载。

### 9.2 页面接入

- 在 `src/Admin/Registry.php` 注册页面与 tab；页面 id 同时决定 CSS/JS 文件名。默认 tab 可以省略 `t`。
- 渲染前把该页需要的运行时数据交给 `Page_Assets::set_data( $page, $data )`，再调用 `Assets::enqueue_page()`。数据会进入前端 `SakuraAdmin.data`。
- 页面 CSS 命名：`assets/ui/pages/{module}.css` 是模块共享样式，`assets/ui/pages/{page}.css` 是可选页面样式；JS 只加载 `assets/ui/js/pages/{page}.js`。
- 需要卡片拖拽布局的页面，在 `Page_Assets::LAYOUT_PAGES` 登记 `页面 id => 布局 id`；布局根节点输出 `data-sakura-ready`、`data-sakura-layout`，每张卡同时带 `data-sakura-card`。
- 映射右侧的布局 id 必须是生产已存的布局组名。改页面 id 或文件名时不要顺手改布局 id：文件空间-子比专属的页面 id 是 `files-zibll`，布局 id 必须保持 `files-space`，否则旧布局丢失，右侧「布局修改」工具栏也不会出现。
- 页面脚本只用 `window.SakuraAdmin`：`ready`、`qs`、`qsa`、`on`、`delegate`、`closest`、`dataBlock`、`format`；请求用 `admin.api.ajax/post`；提示/确认/忙碌用 `admin.ui.toast/confirm/busy`。不要引入 jQuery。
- 所有可见文案走 PHP 的 `__()` 后放进 `Page_Assets::set_data()`，JS 不内嵌未翻译中文。
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
- [ ] 布局页的 `data-sakura-layout` 与 `Page_Assets::LAYOUT_PAGES` 一致，右侧「布局修改」存在且旧布局能恢复。
- [ ] 配置类弹窗居中、可 Esc/背景关闭、焦点可回退；「不再提醒」持久化；没有重复的顶部提示。
- [ ] 「稍后处理」写入 12 小时冷却：冷却内刷新不弹，把 `snoozed_until` 改成过去时间后刷新会重新弹出。
- [ ] 状态胶囊/页头标签语义正确。
- [ ] 移动端标题区换行、按钮不挤、页面不横溢。
- [ ] 页签单行完整，最后一个页签在 320px 也不被裁切。
- [ ] 窄屏内容型表格已重排为带 `data-label` 的行，没有 860px 宽的隐藏溢出。
- [ ] 每张可编辑卡片是独立表单，表单内有 `Card_Form::fields()`（单数组 option 用 `option_fields()`）标记，点保存只写本卡；未勾选的 checkbox 能保存为 0；页面没有 `.page-savebar` 或浮动保存条残留。
- [ ] 页面骨架、卡片、表单壳和保存条来自 `UI::page()` / `UI::card()`，视图没有手写这些骨架。
- [ ] 页面只加载 `assets/ui/` 的 tokens/base/components（可选 layout）和对应 `pages/*.css`，没有旧 `admin-*.css` / `userfiles-admin.*`。
- [ ] 没有 `.sakura-sync-app`、`.sky-layer`、`.sakura-layer`、`.app`、`.hero`、`.card-savebar`、`.page-savebar` 等旧标记。
- [ ] `php -l`、`node --check`、CSS 花括号计数、`git diff --check` 通过。
