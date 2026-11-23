=== 樱花工具箱 ===
Contributors: nboke
Tags: image optimization, webp, avif, imagick, media library
Requires at least: 6.4
Tested up to: 7.1.4
Requires PHP: 8.2
Stable tag: 1.28.8-beta-12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

樱花工具箱把图片压缩、缩略图控制、媒体库整理和 S3 云存储拆成独立模块：需要的开，不需要的关，设置与数据互不影响。

== Description ==

樱花工具箱适合想自己掌控图片处理方式的站点：

* 上传图片时自动压缩，或转成 WebP / AVIF，也可以保持原格式。
* 自己决定生成哪些尺寸，不用的尺寸不再生成，节省空间。
* 处理前自动备份原图，支持单张还原；还能检测备份目录是否会被外网直接下载。
* 媒体库支持按格式筛选、批量优化，并显示处理进度。
* 可选把图片同步到云存储（支持 Cloudflare R2、MinIO、缤纷云等 S3 兼容服务）。
* 「对象存储」内置链接改写与费用估算：迁移存储域名后批量替换数据库里的旧附件链接（带账本与一键回滚），并按存储量、流量与请求量比较 10 家服务商的月费。
* 可选给缩略图地址加上尺寸参数，并让列表、侧边栏和正文分别使用指定尺寸。
* 「系统设置」页集中展示 PHP-FPM 运行参数、图像引擎与扩展状态，并可调整卡片表面色、圆角与边框，方便先确认环境再开功能。
* 用户文件空间：为投稿者提供虚拟文件夹、容量配额和上传权限，删除必须走「申请 → 管理员审核 → 后台确认执行」，媒体库可直接查看归属、容量与申请状态。

== Installation ==

1. 将 `sakura-tools` 目录上传到 `/wp-content/plugins/`。
2. 在「插件」页面启用。
3. 打开「樱花工具箱 → 系统设置 → 环境自检」确认 PHP 与图像引擎状态，再到「图片优化 → 优化规则」配置处理模式与格式。
4. 建议在「图片优化 → 备份还原」中确认备份目录不可通过 Web 直接访问。

== Frequently Asked Questions ==

= 需要安装什么图像组件？ =

优先使用 Imagick，没有就用 GD。输出 AVIF 需要服务器支持；不支持时会明确提示失败，不会假装成功。

= 备份会占用很多空间吗？ =

可以在「备份管理」里设置保留天数和容量上限，也可以手动清理。清空后，已经优化过的图片就无法还原了。

= 别人能直接下载备份的原图吗？ =

插件会在备份目录里放一个访问限制文件，但只有 Apache 服务器会读取它。Nginx、OpenResty、IIS、Caddy 和 PHP 自带服务器都不会读取，需要在站点配置里拒绝这个路径。部署后请：

* Apache：无需额外操作，限制会自动生效；
* Nginx / OpenResty：在站点配置里拒绝该路径，例如 `location ^~ /wp-content/uploads/sakura-imgopt-backup/ { deny all; }`，重载后生效；
* IIS / Caddy：同样在站点配置里拒绝这个路径。

「备份管理」页有「检测是否可被公网访问」按钮：它会真实请求备份目录里的一个文件（优先用已有备份；目录为空时写入并删除一个临时文件）。能下载就判定为暴露，返回 401/403/404/410 就判定为已保护。

= 支持多站点吗？ =

支持；每个站点各自保存设置、统计数据和上传目录。卸载时同步索引与队列表总会删除；设置、云存储密钥、统计和备份文件默认保留，只有在「备份管理 → 自动清理策略」里开启「卸载时删除数据」，才会逐站删除这些持久数据。

= 云存储的密钥怎么保存？ =

Access Key 和 Secret 会加密后存进数据库，页面和源码都不会显示。建议在站点配置里设置一个固定的加密密钥；更换加密密钥或站点安全密钥后，需要重新填写。

= 文件空间里的文件是公开的吗？ =

是。上传成功后返回的是标准附件地址（`wp_get_attachment_url()`）。云存储接管后，地址会变成存储/CDN 域名下的直链，例如 `https://bf.example.com/wp-content/uploads/2026/11/xxx.webp`。

文件名由时间戳和随机串组成，无法按规律猜出；但**拿到链接的人不需要登录就能访问**。链接可能被分享、被访问日志记录、被转发到站外，插件不会为每次访问做身份校验。建议：

* 在存储/CDN 侧开启防盗链（Referer 白名单），减少被别的站点直接引用；
* 不要把私密文件（合同、身份证件、内部资料）放进文件空间——它是给文章配图、公开附件用的；
* 需要真正私有的文件，请使用自建存储并自行配置鉴权，或暂时不要开启「上传后删除本地文件」。

= 用了云存储，主题的付费附件下载还安全吗？ =

不完全安全。zibll 等主题的附件下载按钮通常先校验购买状态和 nonce，再判断链接是否落在本站 `uploads/` 目录：

* 文件还在本地时：由 PHP 流式输出，权限校验有效；
* 文件已被云存储接管、本地副本被删除时：主题解析不到本地路径，会退化成 302 跳转到存储/CDN 直链，**购买校验实际上被绕过了**。

插件目前没有接管这个跳转。等出现「既要防直链、又要保留购买校验」的需求时再研发，方向是在主题之前拦截下载请求，由插件代理输出或签发短时有效的预签名地址；若暂时需要这层保护，请关闭「上传后删除本地文件」，让文件保留在本地。

== Changelog ==

= 1.28.8 =

* 新增：**「子比增强」模块与授权缓存**——把主题 `zib_init_add_action` 的授权校验结果缓存下来，之后的前台与后台**只读**页面请求直接跳过主题那次校验（原先每次后台翻页都要跑一次 33–47ms 的远程校验）；写操作、AJAX/REST/XML-RPC/cron、登录与 cron 端点仍然跑真实校验，缓存只由主题自己的成功校验写入，主题升级、换域名、换授权都会让它立即失效。有效期可选 小时 / 天 / 周 / 月 / 年 / 永久，复验时点对齐缓存真正到期的时刻，保存时按新有效期重排——不会「刚复验完又立刻过期」。开关首次启用只做一次真实校验，校验失败开关不会打开。
* 新增：**文件空间 → 媒体管理**页签——后台直接按账户浏览文件空间里的文件、按状态筛选、勾选批量「违规下架」，不必再跳到媒体库。下架逻辑抽成共享的 `Takedown` 服务，后台页签与前台媒体库走同一份判据、同一份审计与同一套通知，不会再出现「这边能下架、那边没反应」。
* 新增：**违规处置 P1：下架之后可以处罚上传者**——处罚表 `{prefix}sufm_sanctions` 是唯一权威（类型、期限、理由、备注、操作者），执行点在插件自己的上传入口（`Uploader::handle()`）、媒体库上传守卫、`authenticate`、`determine_current_user` 与应用密码校验五处，主题只是可选执行器，换主题也不会失效。处罚期「禁增、允许删」：用户仍然能列文件、删文件、看容量。永久处罚要回填一次登录名才生效，避免误点。权限矩阵新增第 7 列。
* 调整：**单页签模块也显示页签条**——以前只有多页签模块才有页签条，「子比增强」这类单页模块看起来像没有导航。
* 调整：**插件提示不再退化成 WordPress 原生 notice**——保存结果、连接测试等提示只走插件自己的提示弹窗，不再出现「顶部 notice + 弹窗」把同一件事说两遍。
* 调整：**前台文件管理与编辑器文件选择器统一成 Windows 资源管理器的选择模型**——选中不再靠勾选框，改成整块颜色（列表粉底 + 左侧竖线，卡片粉底 + 粉框 + 粉色文件名）；单击选中、Ctrl 单击加减选、Shift 单击连选、双击打开（编辑器里双击是**插入正文**）、触屏再点一次打开。勾选框仍保留在 DOM 里，Tab 能到、读屏能读。
* 调整：**右键菜单**——右键文件先选中它再弹菜单；多选时给批量菜单（插入所选 / 移动 / 复制链接 / 取消选择 / 申请删除，编辑器里是「插入所选」）；空白处右键给「新建文件夹 / 全选 / 刷新列表」。
* 调整：**子文件夹折叠改成看得懂的药丸**——`⌄ 子文件夹 · N · 收起/展开`，以前只有一条灰窄条加 tooltip，用户根本认不出那是折叠开关。
* 调整：**媒体管理的选中条去掉深红**——「违规下架」原本是 WordPress 的 `button-primary`，被插件样式染成 `#C7335B` 实底白字；现在改成白底 + 红细边 + 红字（6.44:1），「取消选择」也不再是原生蓝色链接。
* 调整：**下架与处罚弹窗统一**——都带左上角小鲸鱼、都在页面正中（下架弹窗此前被 `.page-body > :not(:first-child)` 的 `margin-top: 20px` 顶到距顶部 20px），主按钮换成插件的樱花淡粉渐变。
* 修复：**处罚弹窗的「取消」点不动**——`files-users.js` 调了一个不存在的 `closeSanctionDialog`，点取消只会在控制台抛 `ReferenceError`、弹窗留在原地，只有 ESC 能关。
* 修复：**WordPress 媒体库下架弹窗的「同时处罚上传者」勾选框被拉成整行蓝条**——`media-folders.css` 里两条无类型限定的 `input` 规则（`width:100%`、`min-height:44px`）命中了复选框，与 WordPress 自己的样式打架；现在只作用于文本框与按钮，勾选框回到 WordPress 原生外观。
* 修复：**文件管理顶部在「卡片 / 列表」之间切换时布局跳变**——桌面头部第二行的 `.sufm-path` 用 `flex: 1 1 auto`，宽度随内容（面包屑 + 提示 + 计数）变化，跨过临界点就把右侧工具组挤到第三行，看起来像「留出一大截空白」。改成 `flex: 1 1 0` + `min-width: 0`，提示文案改用容器查询按自身宽度收起，收起后挂 `title` 仍可读。
* 修复：**装了 Query Monitor 的站点远端删除 / 上传被拒**——`Remote_Operation_Guard` 以前见到 `wp-content/db.php` 就拒绝一切远端写入（表现为「只删了本地、云端没删」）；现在改为比对两侧数据库身份（`DATABASE()` + `@@server_id`），同库同服务器的 drop-in 放行，真正把查询路由到别处的仍然拒绝。
* 性能：**同一请求里重复的查询各收敛成一次**——同步中心首屏与失败提示共用一次最新扫描行查询、链接改写页只读一次账本聚合、文件空间「子比专属」的授权表探测在一个请求里只做一次（结果按实例 memo，写入点失效）。
* 影响范围：新增表 `{prefix}sufm_sanctions`（随插件既有建表流程创建，无需手工 SQL）、新增 option `sakura_imgopt_zibll` 与 `sakura_imgopt_zibll_license_cache`、新增模块「子比增强」与「文件空间 → 媒体管理」页签、新增 `src/Zibll/`、`src/UserFiles/Services/{Takedown,Sanction_Service}.php` 与对应测试。不改既有 option 键、不改既有钩子语义，升级无需手工迁移；停用与卸载会把新表、新 option 一并清理。

= 1.28.7 =

* 新增：**后台「计时」面板接入 Query Monitor**（`src/Support/Profiler.php`）——boot、后台页渲染、S3 上传与删除、扫描/动作/核对三条后台队列、上传处理里的各阶段都登记成 QM 的计时行，排查「这一页为什么慢」不必再靠猜。没有装 QM、或 QM 没开计时收集器时零开销（只多一次数组查找）；`lap`/`stop` 只对本类 `start` 过的名字发，避免面板里出现 "Timer not started" 噪音行。**QM 自己一次也不调 `qm/start`，空面板是它的设计不是故障。**
* 新增：**插件品牌图标**——`assets/ui/icons/sakura-mark.svg`（单色 `currentColor`、四瓣樱花 + 对焦环，viewBox 裁到实际墨迹、描边加粗到 20px 下仍可辨）。顶栏徽标换成这枚图形；WordPress 左侧菜单图标改为 `add_menu_page()` 第 6 参传 `'none'`，由 `assets/ui/admin-menu-icon.css` 用 `mask-image` + `background-color:currentColor` 绘制，自动跟随「静止 / 悬停 / 当前 / 折叠」与八套后台配色方案，不必为每种状态各写一份色值。
* 新增：**上传重命名改成可点选的占位符卡片**（「图像优化 → 命名」）——输入框 + 内置卡片 +「更多占位符」折叠区 + 实时预览；卡片是 `aria-pressed` 开关，同一个占位符只能用一次，按下的卡片整块换成樱花淡粉底 + 墨色字（白字在这个底色上只有 1.77:1）。新增 `{hash}`（内容指纹）、`{user}`（上传者）、`{wxh}`（图片尺寸）与时间族 `{yy}` / `{week}` / `{dow}` / `{ts}`，以及 `{fixed}` 自定义文本（只收 `[A-Za-z0-9_-]`、最长 24 个字符，保存时若被清洗会当场提示）。规则现在对**所有上传文件**生效，不再只处理图片：扩展名原样保留、`tar.gz` 这类复合扩展名整体保留、没有扩展名的文件保持原名；取不到值的占位符连同它一侧的连接符一起去掉，不会在文件名里留下空段。
* 新增：**系统设置页的页头与评分**——「环境评分 / 通过 / 建议 / 需要处理」四张小卡片合并成一张，环境评分进度条按分数取色（80–100 淡绿、60–80 淡黄、60 以下鲜红）。
* 调整：**所有设置页的页头统一成同步中心的样式**——标题下方那句渐变填充的副题、右下角的樱花印章改由 `src/Admin/UI/Hero.php` 给默认文案（各页面可以覆盖，不必再各写一份）；KPI 摘要条统一成「票根」造型（`.kpis--ticket`，上沿锯齿 + 撕口）。
* 调整：**品牌徽标改成樱花淡粉**——底色用 `--g-brand-petal` `#FFAACA`（浅端 `--g-petal-1`、深端 `--g-petal-2` 的 145° 渐变），墨色改 `--g-brand-ink`：白色图标压在淡粉上只有 1.77:1，必须换深色。
* 调整：**卡片眉标全站统一樱粉**（`--g-accent`）——淡粉 `#FFAACA` 当 10.5px 文字只有 1.77:1、`#FF8FB8` 2.13:1、`#E4577F` 3.47:1，都读不出来；`#C7335B` 在卡片左上 5.1:1、右上粉光最浓处 4.5:1，是唯一同时成立「粉」与「可读」的一档。图像优化页的眉标同时去掉序号，改回与其他页一致的英文名词。
* 调整：**勾选框与滑动开关重画**——勾选框是 18px 樱花淡粉圆盘 + 6px 白点，开关关闭米白 `#F6EFE2`、开启淡粉 `#FFAACA`，靠旋钮位置与 1px 内环表达状态。**同时显式压掉 WordPress 后台 `forms.css` 注入的 `margin:-3px 0 0 -4px`、`float:left`、`content:url(勾号)` 与 `:focus` 双环**——这是「生产环境样式错乱、旋钮停在 15px」的根因，不清掉会一直偏 4px。
* 调整：**已保存的存储配置卡**——「使用中」改成 145° 樱花粉渐变（`#FFC6DB → #FFAACA → #FF8FB8`），说明行与徽标换墨色族；「连接测试 / 删除」改成白底 + 一根彩色细边，静止态不动、悬停才上色；被绑定冻结的开关改用小锁 +「已开启 / 已关闭」状态词，不再只把复选框灰掉——灰掉的框只说「不能动」，说不出当前是开还是关。
* 修复：**前台文件管理弹窗里的鲸鱼图标缩成一个点**——子比主题有一条全局 `svg{width:1em;height:1em}`，而 CSS 声明优先于 `<svg width="38">` 属性，实际只剩 1em。改为 CSS 显式给尺寸（36px 徽章 + 30px 图形），后台的历史弹窗仍是 46px，不受影响。
* 影响范围：新增 `src/Support/Profiler.php`、`src/Admin/UI/Hero.php`、`assets/ui/icons/sakura-mark.svg`、`assets/ui/admin-menu-icon.css` 与 `tests/ProfilerTest.php`、`tests/UploadRenamerTest.php`；新增 option `sakura_imgopt_rename_fixed`（默认空字符串）。**不改表结构、不改既有 option 键、不改钩子语义**，升级无需迁移。

= 1.28.6 =

* 新增：**删除内核 `Deletion::delete()`**（P3）——附件删除、审核队列、媒体库「违规下架」与同步中心「删除远端」全部收敛到同一条路径：先抢占 `deleting` 状态、再删附件与本地文件、可选同步删云端并逐个校验对象是否真的消失、最后由 `Files::settle_purged()` 唯一结算（抢到锁的才退容量）。返回 `Deletion_Result`（deleted / missing / denied / partial / in_progress），批次里一条失败不再连带其他条。
* 新增：**权限矩阵卡片**（P4）——放在「文件空间 → 审核与执行」页、与「执行删除」并列：五个内置角色 × 六个插件能力；管理员的「直接删除文件」永久锁定；配置值与实际值不一致时标 ⚠；缺 `delete_others_posts` 的角色标「仅本人附件」；每次保存写 `cap_change` 审计。默认授权改为一次性（新增 option `sufm_caps_seeded`），此后矩阵是权威，不再被每次 `admin_init` 回写。
* 新增：**同步中心「删除远端」区分整链与仅云端**——对象属于用户文件空间的附件时，连带删附件、本地文件、索引行与容量；解析不到附件的对象只删云端副本。
* 新增：**后台 UI 视觉方向 V2「樱前线」在「S3 同步中心」试点**——新令牌（淡樱渐变、22px 卡片圆角、更软的阴影）、页头副题与「桜」印章、本地→云端→索引的樱前线弧线（数字落在节点上）、KPI 票根、汽水进度条（真实气泡元素各自异相位上升）、四色云端体积环、服务器→当前存储桶的延迟胶囊（进页面测一次，停留时每 30 秒复测，颜色取最近 3 次采样的中位数）。
* 调整：**按钮统一成一套**——主按钮淡樱渐变、悬浮抬起 1px；模块导航、页内页签、筛选与分页也换成同规格按钮，不再有下划线页签与胶囊。
* 调整：**所有提示框统一成同一副面孔**——鲸鱼记号 + 标题 + 一句话结论 + 清单（带类型纸片图标与体积）+ 粉底后果说明 + 右对齐按钮，配渐显渐隐的进出场动效（`prefers-reduced-motion` 下立即开关）。浮层改挂在 `.sakura-admin` 根节点下，修掉「保存后到弹窗出现有一帧两侧留白」。
* 修复：**管理员在媒体库批量删除受管附件会被自己拦住**（预检只查 `upload_files`，没看 `sufm_admin_delete_files`）。
* 修复：**页签悬浮时上边框被滚动容器裁掉**（把裁切区做成滚动容器的上下内边距，占位高度用负外边距抵回）。
* 修复：**移动端文件列表里图片按原始尺寸铺满整行**（缩略图尺寸规则改为全局作用域，不再只写在桌面媒体查询里）。
* 修复：**前台文件列表有的文件名带扩展名、有的不带**——展示层统一去掉白名单内的扩展名（tooltip 仍是完整文件名），PDF 等非图片文件改显内置 SVG 类型图标（vscode-icons MIT 子集原生 23 个，按「扩展名 → mime → mime 前缀族」三级回退）；编辑器文件面板（`/newposts`、`/posts-edit/`）同步这套图标与显示名。
* 修复：**媒体库上传器的「上传目标文件夹」与左侧目录树不同步**——双向同步：点树带过去、下拉选了带回来；浏览他人目录时目标回落到根目录（服务端只接受本人名下目录）。
* 移除：系统设置里的「页面背景 / 自定义页面背景色 / 卡片背景色」三项（旧 `page_bg`、`card_bg` 键被忽略，不再覆盖令牌），以及插件自带的「花瓣微尘」点阵背景——背景统一交给 V2 画布控制。
* 影响范围：新增 `src/UserFiles/Services/` 下 `Deletion.php`、`Deletion_Context.php`、`Deletion_Result.php`，`src/UserFiles/Admin/Capability_Matrix.php`、`src/UserFiles/Media/Cloud_State.php`、`src/Admin/UI/Frontline.php` 与 `assets/file-icons/`（23 个 SVG + LICENSE）；新增 option `sufm_caps_seeded`。**不改表结构、不改路由、不改既有 option 键与钩子语义**；`sufm_allow_attachment_delete`、`sufm_skip_remote_enqueue` 都是新增过滤器，旧代码不受影响。

= 1.28.4 =

* 修复：**前台上传的图片在文件空间里显示上传前的原始文件名**。台账 `display_name` 原来在 `wp_handle_upload()` 之前就按 `$_FILES` 里的原名算出来，而上传重命名（`Upload_Renamer` 挂在 `wp_handle_upload_prefilter`）和图片格式转换都发生在 `wp_handle_upload()` 内部，于是同一张图在媒体库显示重命名后的新名（附件标题后来被回写）、在文件空间显示原始哈希名。现在上传成功后按最终落盘文件名重算台账显示名与附件标题，两处一致；上传失败时仍回落到原名，错误提示不受影响。
* 修复：**多吉云临时凭据请求被 WordPress 的 URL 校验在发包前拒掉**。`S3_Doge_Cloud::request_credentials()` 用的是 `wp_safe_remote_post`，它会先跑 `wp_http_validate_url()`，而该函数把 `198.18.0.0/15` 判为非法（benchmarking 网段）——Clash / mihomo 的 fake-IP 模式恰好把域名解析到这一段，请求于是变成「URL 无效」，界面显示「多吉云接口没有返回可解析的响应，请稍后重试」，上传结果只落在本地、没进存储桶。改用 `wp_remote_post`（目标地址是硬编码的厂商接口，不需要 SSRF 校验），并把 `WP_Error` 的原文带进错误信息，以后同类问题能直接看到真实原因。
* 影响范围：`src/UserFiles/Services/Uploader.php`（新增 `display_name()` / `title_for()` 两个私有静态方法）、`includes/class-s3-doge-cloud.php`（新增 `$last_transport_error`）、`tests/bootstrap.php`（补 `sanitize_file_name` 桩）与新增的 `tests/UserFilesUploadNameTest.php`。**无表结构、无 option、无钩子变化**，升级不需要迁移；`docs/开发规则/07-S3存储生命周期.md` 的 R17 行号随之重指，并记下这次 fake-IP 事故的成因，避免有人把这行改回 `wp_safe_remote_post`。

= 1.28.3 =

* 新增：**存储生命周期十九条规则落地（R1–R19）**，并写进 `docs/开发规则/07-S3存储生命周期.md`。第一次成功上传后记录绑定快照（厂商 / 桶 / 端点 / 地域 / 云端路径五项），此后改动任一项即视为换桶，服务端直接拒绝写库并返回 `error=locked`，不再弹窗放行；高风险锁定（上传后删本地）解绑必须全部删除后重来。上传行为两项（上传后删本地、不上传缩略图）在绑定后整卡冻结，改 POST 也不生效。
* 新增：**切桶之后同步中心封存，跑完一次完整核对才解锁**。切桶与写入封存标记在同一次 `update_option` 内完成，页面不会出现「新桶 + 旧数字」的中间态；封存标记落 option 而不是 transient，过一段时间不会自己变回正常。闸门只拦「会按旧索引去向桶里写或删」的动作（刷新差异、继续核对、重试失败、批量上传/下载/删除），放行只读与「开始完整核对」「立即校准」「测试连接」「忽略规则」「补建媒体库记录」。三个服务端端点同时返回 409 `sio_reconcile_required`，旧标签页也拦得住。
* 新增：**切桶改用插件弹窗提醒，不再只有左下角一行成功提示**。切换成功后当场弹出三件事：缩略图地址改写已关闭、同步中心的数据仍是上一个桶的、上传行为两个开关仍按原绑定；按钮直达同步中心。原来那条要在刷新后才出现的顶部红字通告已删除。
* 修复：**「开始完整核对」按钮此前一直发的是增量扫描**。`Sync_Center_Module::ajax_scan()` 自 1.22.8 起调用 `Scan_Service::start()` 时没传 mode，于是走默认增量，永远无法在新桶上重建全貌——这也让 1.28.3 的封存解锁条件不可能满足。现在固定发 `full`。
* 修复：**缩略图能力探测不再联网**。删掉了「抽样 HEAD 请求验证服务商能不能吃尺寸后缀」的逻辑与它缓存的判定结果，改由 `Provider_Registry` 静态声明能力；缩略图页不再因为外部网络慢而卡住，也不会把一次失败的探测永久当成结论。切换存储目标时缩略图改写总开关自动关闭并给一次性提示。
* 修复：**从云端下载回本地的附件补写两处状态**。此前只落了文件，附件在同步中心仍是 pending，用户文件空间的已用容量也少算一份；现在下载落库后同时回写附件同步状态与配额账本。
* 修复：**多吉云的临时凭据按需续期**。多吉云只签发两小时有效的 S3 临时凭据，原来的实现在建客户端时取一次就当永久用，跨两小时的批量任务（全量重建、重试队列、WP-CLI）会在中途集体失败。现在每次请求按需取用与续期。
* 调整：**桶身份只有一处定义，字段按三档分开**。此前同一件事有五套互相矛盾的「桶身份」定义（附件指纹、旧版指纹、删除队列指纹、切桶判定、绑定判定），字段集合各不相同。现在统一成 `Bucket_Identity`（寻址：厂商 / 桶 / 端点 / 云端路径）与 `Bucket_Confirmation`（唯一的存在性确认入口），`region` 归入可用性字段、`space` 与图片地址前缀只做展示，都不再参与寻址判定——同一桶换一行配置不会误判成换桶，多吉云换个空间名也不会误触发封存。
* 修复：**对账索引改按存储桶分区，同桶多份配置共享一套索引**。`sio_sync_index` 与 `sio_sync_counts` 的主键与唯一键改为按桶身份（`UNIQUE KEY target_object(target_hash, object_key_hash)`），Schema 升到 1.9.0 并自动迁移：旧行按各自配置回填桶身份、同一桶同一对象的重复行合并、计数表重建。切换配置行不再让云端明明存在的对象被判成「未确认」而把 URL 全量退回站点域名；删掉一份共享同桶其他配置本会保留的索引。
* 修复：**渲染附件 URL 时不再写数据库**。此前每次命中索引确认都会回写一次附件指纹与同步时间，于是同一批附件的「指纹属于哪个桶」会随浏览不断变化。现在浏览只读，指纹回填只在完整核对结束时做一次；附件指纹本身逐字节不变，无需重传。
* 修复：**批量写入对账索引的占位符错位**。`Index_Repository::upsert()` 的每个元组有 12 个占位符但列清单有 13 列，配置行标签被拼在 VALUES 列表之外，MariaDB 直接报语法错——任何批量写入（完整核对、上传回写、CLI 扫描）都会失败。已修，并补了一个校验「每行各带自己的标签」的测试。
* 修复：**`wp sakura-sync scan --full` 不再被 WP-CLI 拒绝**。命令实现里读了 `--full` 但帮助声明里漏了这一项，于是命令行只能发增量、无法触发完整核对。
* 影响范围：新增 `src/S3/{Bucket_Identity,Bucket_Confirmation,S3_Credential_Error}.php`、`src/UserFiles/Services/Media_Adoption.php` 与 6 个测试文件；删除 `src/Thumbnail/{Probe,Provider_Status}.php`；改动集中在 `src/S3/`（Options、Profile_Manager、Settings_Saver、Storage、Sync_Status、Synchronous_Uploader、S3_Storage_Page、Sync_Center_Module、Client_Factory、Credential_Cipher、Delete_Jobs、Upload_Observer、Error_Mapper、Cost_* 除外）、`src/Sync/`（Schema、Index_Repository、Scan_Service、Action_Worker、Attachment_Adopter、CLI）、`src/Thumbnail/`、`src/UserFiles/Services/`、`includes/`（class-s3-storage-module、class-thumbnail-optimizer-module、views/s3-sync-center、views/thumb-settings）、`fast-search.php` 与 `assets/ui/`。**数据库需要迁移**：`sio_sync_index` 与 `sio_sync_counts` 的键结构改为按桶身份分区，升级后首次请求自动执行（先导出备份），已同步的附件无需重传、指纹逐字节不变；`s3_options` 新增 `reconcile_pending` 与 `binding` 两个键，缺省不影响旧站点。新增钩子 1 条（`wp_calculate_image_srcset`）、删除 1 条（`admin_post_sakura_thumb_probe`），数据表数量不变（24 张）。
= 1.28.2 =

* 修复：**切换存储服务商时不再覆盖原配置**。连接设置卡隐藏提交的 profile_id 是当前活动配置，服务端只判断「另存为新配置 / id 为空 / id 不存在」三件事，没有厂商判定，于是从多吉云切到腾讯云再保存，原多吉云配置被整条重写成腾讯云。现在服务商一变就强制新建一份配置，前端把「另存为新配置」勾上并禁用，同时在卡片里说明这次保存会新建、原配置保持不动。生产实测：保存后配置 3 份变 4 份，原配置内容零改动。
* 修复：**换桶之后附件 URL 不再永久退回站点域名**。旧判定只认附件 post meta 里的目标指纹，换桶必然不匹配，而切换配置从不改写 post meta，于是已上传的附件全部退回站点域名。现在指纹不匹配时会再查同步索引：该附件记录的全部对象键在当前活动配置下都是已同步状态，就认定云端确实有这份文件，把指纹一次性纠正到当前桶，并保持同步时间不变（不伪装成刚重新上传）。生产实测：切到多吉云 129/129 附件 URL 跟着换到 doge.nboke.com，切回缤纷云 129/129 回到 bf.nboke.com，同步时间全程未变。
* 修复：**腾讯云 COS 的桶名不再被账号级 APPID 挤成 `sakura-c…4331`**。腾讯云桶名格式是「自定义名-APPID」，末尾那串数字同一账号下所有桶都相同，与「这是哪个桶」无关。现在展示与自动命名的配置名会先剥掉这串后缀，只缩略自定义部分（`sakura-clover`）；状态卡、配置卡、同步中心的页头与健康表、费用估算一致生效，完整桶名仍保留在 title 里。其它厂商不受影响。
* 修复：**同一存储桶的两份配置不再互相判为换了桶**。目标指纹原本把配置行 id 也算了进去，同一厂商 + 同一桶 + 同一路径的两份配置指纹不同，切换时会被判成换桶而触发 URL 回退。现在指纹只认「厂商|桶|endpoint|路径」，配置行 id 不参与；旧形态指纹仍能匹配，不需要重传。
* 修复：**保存一份没在用的配置不再把活动凭据写成明文**。保存非活动配置时会沿用顶层镜像字段，早期实现取自解密后的视图，于是每次保存都把 key/secret 明文写进 option，下一次请求才重新加密。现在镜像字段从库里原始值搬运，密文原样保留；生产实测保存前后顶层密文零变化，逐字段 diff 只有该配置的 saved_at。
* 修复：**连接表单不再接受空桶名**。连接卡改成「新增配置」的空白表单后，直接点保存会存下一份没有桶名的配置，卡片永久停在「配置不完整」。现在空桶名直接退回并提示去点已保存的配置小卡片。
* 调整：**「启用 S3 存储」从连接设置卡移到配置卡片**。开关不再是一个独立复选框，而是点卡片本身：点非活动卡片＝切换并启用（卡片变深色、徽章「使用中」、图片地址前缀同步更新），再点当前卡片＝关闭但保留这份配置（卡片变浅、徽章「已停用」）。切换走 AJAX 就地重绘，不整页刷新，也不改写连接表单里的字段。没有配置时在结构上就不可能开着 S3。保存表单不再提交 `enabled`，一律沿用库里已有值。
* 新增：**配置卡片上的「连接测试」**。每张已保存的配置卡片底部多了按钮，点一下就用该配置自己的密钥做一次只读探测并给出往返耗时，超过 800 毫秒提示偏慢；不切配置、不写库、不触发上传。删掉了原来唯一能验证配置是否还活着的做法（切过去、传张图、再看结果）。
* 调整：**配置卡片底部两个动作按钮等分两列、静止态就有边框**。原来两枚按钮都是透明底透明边框的弱化样式，读起来像说明文字，宽度按文字收缩贴在左侧。现在「连接测试」用中性色带框、「删除此配置」保持危险色，禁用态（当前使用中不可删除）单独覆盖主题的全局 `[disabled] { opacity: .6 }`，文字保持可读。
* 调整：**「连接设置」卡更名「添加存储配置」，服务商与其已保存配置并入同一行**。左半边是服务商下拉与测试连接按钮，右半边是「这份服务商的已保存配置」小卡片，点小卡片才把配置载入表单准备修改；点下方的大卡片只负责启用、停用与切换，不再回填表单。没有已保存配置时右半边整体收起。窄屏下两半改为上下堆叠，下拉框不再溢出单元格。
* 调整：**换服务商的提示不再教错恢复方式**。原提示说「想继续用原来的配置，把服务商切回去即可」，但切回服务商时表单里仍是新商家的桶、地域与密钥，再点保存只会再新建一份。现在提示改为去点上方已保存配置的小卡片。
* 修复：**锁定的「另存为新配置」复选框看起来没勾上**。已勾选且禁用的复选框被主题的禁用底色覆盖成灰底，与「这次保存会新建一份配置」的提示自相矛盾；现在补了 `:checked:disabled` 的选中态样式。
* 影响范围：改动集中在 `src/S3/`（Settings_Saver、Profile_Manager、S3_Storage_Page、Storage、Sync_Status、Bucket_Label、Options、Config_Notice）、`src/Sync/`、`includes/class-s3-storage-module.php`、`assets/ui/js/pages/storage-connection.js`、`assets/ui/pages/storage.css` 与 `assets/ui/components.css`。不改数据表结构与 option 键名，不新增 cron 与钩子；旧的附件目标指纹仍然有效，升级后无需重传或重扫，已保存的多份配置保持原样。

= 1.28.1 =

* 修复：**移除误导性的「S3 对象权限是私有」缩略图警告**。1.28.0 已从连接设置移除对象 ACL 控件；Settings::conflicts() 仍会读取旧的 object_acl=private 并提示「访客会收到 403」，但实测自定义域名能否匿名访问由存储空间策略 / CDN 决定，该提示不成立。现在不再产生 private_acl 警告，对象 ACL 的底层兼容值继续保留，不影响既有上传。
* 影响范围：仅 src/Thumbnail/Settings.php 与 tests/ThumbnailConflictsTest.php；不改路由、数据表、option 键名与钩子语义。

= 1.28.0 =

* 新增：**对象存储「链接改写」页**，用于存储服务迁移后批量替换数据库里残留的旧附件域名。支持扫描预览、按内容/主题数据分组执行、12 秒分片续跑；每行改写前把原值记进 `{prefix}sio_url_rewrites` 账本，可一键回滚；改写后又被手动改过的行标为「冲突」并跳过，不会覆盖新内容。同时处理纯文本、序列化数组与 JSON 里的域名，域名边界严格匹配（`bf.example.com.evil.test` 不会命中）。生产实测把 `bf.nboke.com` 换成 `doge.nboke.com`：`posts.post_content` 17 行、`posts.guid` 130 行、`postmeta` 3 行、`termmeta` 6 行、`zibll_options` 11 行、`zibll_options_backup` 101 行，合计 268 行；回滚与冲突保护均已验证。
* 新增：**对象存储「费用估算」页**，按存储量、月流量、读写请求、图片处理量、缓存命中率与 HTTPS 占比，比较多吉云、缤纷云、阿里云 OSS、腾讯云 COS、七牛云 KODO、又拍云、百度云 BOS、Cloudflare R2、Backblaze B2 与 MinIO 自建共 10 种方案的月费。单价内置自各家官方价格页（`PRICED_AT = 2026-11-16`），累进阶梯计算，纯本地运算：不联网、不读厂商账单、不写数据库。
* 调整：**对象存储连接设置卡改为单列全宽、字段两列排布**，取消「基础设置 / 高级设置」分级与折叠，并移除「文件访问权限（对象 ACL）」选项——实测对象 ACL 不能决定自定义域名能否匿名访问，最终可见性由存储空间策略或 CDN 决定，继续暴露该选项只会误导。云端路径前缀与图片地址前缀仍在同一张卡内，保存按钮固定在卡片底部。连接卡不再提交 `object_acl` 后，`Settings_Saver::object_acl_for_save()` 会保留被编辑档案或当前活动配置里的旧值，不会把已有的 `private` 静默重置成 `public-read`。
* 调整：**服务商下拉只展示当前可用的 7 家**（Cloudflare R2、Backblaze B2、MinIO、腾讯云 COS、阿里云 OSS、缤纷云、多吉云），七牛、百度、又拍移入隐藏列表；已有旧档案仍能正常读取与切换，不会被删除。
* 修复：**多吉云 / 腾讯云 COS 的批量删除失败**。这两家对 `DeleteObjects` 要求 `Content-MD5`，缺失时返回 `InvalidRequest: Missing required header for this request: Content-MD5`，此前整批删除会被判为「结果不确定」。现在捕获该错误后自动退化为逐对象 `DeleteObject`，其余异常仍按原逻辑上报，不会把失败伪装成成功。生产实测删除两个测试对象后列表剩余 0，远端操作守卫的 inflight / uncertain 均为 0。
* 调整：**多吉云 / 腾讯云 COS 的分片上传不再比较最终复合 ETag**。这两家的分片 ETag 不是标准 S3 复合值，严格比较会把已经上传成功的 20MB 以上文件误判为失败；现在仍校验对象大小与各分片 ETag，只跳过最终复合值比较。可通过 `sakura_imgopt_verify_multipart_etag` 过滤器改回。
* 质量：PHPCS 从 37 条 warning 清零到 **0 error / 0 warning**（未关闭任何安全检查，动态 SQL 占位符逐处加带理由的豁免），全量 **623 tests / 2,940 assertions / 6 skipped**，PHPStan level 5 无错误。
* 影响范围：新增 `src/S3/{Link_Rewriter,Url_Rewrite_Repository,Url_Rewrite_Service,Url_Rewrite_Panel,Cost_Estimator,Cost_Panel}.php`、`includes/views/{storage-url-rewrite,storage-cost}.php`、`assets/ui/js/pages/{storage-rewrite,storage-cost}.js`、`assets/ui/pages/{storage-rewrite,storage-cost}.css`；存储模块新增 `rewrite`、`cost` 两个页签。新增 1 张表 `{prefix}sio_url_rewrites` 与 2 个 option（`sakura_imgopt_url_rewrite_schema`、`sakura_imgopt_url_rewrite_state`），激活时自动建表，卸载时一并清理。**不改既有路由、option 键名与钩子语义**；连接页去掉的 ACL 控件不再提交，旧配置值仍被保留。

= 1.27.6 =

* 修复：**同步中心整页按钮失效、顶部多出一条 688px 空白带**。后台通知条的外层 `<div>` 带着 `sakura-admin` 这个页面根类名（`src/Admin/UI/Feedback.php:76`），而 `assets/ui/js/core.js` 的 `root()` 用 `document.querySelector('.sakura-admin')` 取第一个节点——`admin_notices` 在正文之前渲染，于是每个页面控制器拿到的「根节点」其实是通知条本身，`data-sakura-page` 对不上就整段 `return`，按钮、筛选、分页、轮询全都没绑上事件（同类判断 12 处：`ui.js:13`、`files-settings.js:16`、`image-backup.js:41`、`storage-deletes.js:114`、`files-zibll.js:102` 与 `:183`、`files-users.js:16`、`files-queue.js:18`、`files-log.js:16`、`storage-connection.js:344`）。`root()` 现在优先取带 `[data-sakura-page]` 的那个节点；`assets/ui/components.css` 给 `.sakura-admin-notice-root` 补一组重置（`min-height: 0`、`background: none`、`::before { content: none }`），抵掉 `base.css` 里本该只属于页面的 `min-height: calc(100vh - 32px)` 与四层径向渐变。通知条高度从 688px 回到 48px，同步中心「待上传」筛选、批量操作条与详情弹窗恢复。
* 修复：**对象存储「测试连接」不再只说「请检查服务商、地域、Endpoint、存储桶和凭据」**。新增 `src/S3/Connection_Probe.php`：先 `HeadBucket`，失败再用 `ListObjectsV2`（`MaxKeys=1`）读回真实 XML 错误码，所以「桶存在但无权列举」也能判定为可达；返回的 160 字以内原因会原样带到页面上，凭据在离开方法前先做脱敏。同时 `src/S3/Error_Mapper.php` 补三类端点错配（`PathStyleDomainForbidden` / `PermanentRedirect` / `AuthorizationHeaderMalformed`），文案直指「把管理 API 地址换成 S3 Endpoint」和「存储桶实际所在地域不一致」。
* 新增：**多吉云可以按存储空间名称反查 SDK 参数**。`src/S3/Doge_Cloud_Resolver.php` 读 `/auth/tmp_token.json` 响应里的 `data.Buckets[]`，用空间名匹配出 `s3Bucket` 与 `s3Endpoint`；`includes/class-s3-doge-cloud.php` 补 `last_error()` / `get_buckets()` 并把多吉云写在响应体里的错误（HTTP 仍是 200）连 `code` 一起判出来。连接配置页右侧的「获取 SDK 参数」按钮由此可用，失败时提示「多吉云接口返回错误（code N）：…」而不是静默。
* 修复：**改配置时不再偷偷新建一份 profile**。`src/S3/Settings_Saver.php` 的 `profile_id_for_save()` 去掉了 `$provider` / `$bucket` / `$endpoint` 三个「判断是否换了目标」的参数，一律原地更新原 profile；真换了目标就把旧配置记进 `retired_targets` 并清掉该 profile 的索引行，跳转带 `moved=1`。凭据判定改为与正在编辑的那份比较（此前比的是活动 profile，改非活动配置时会把凭据清空）；密钥/Secret 留空时直接沿用已存密文，不再解密再加密，加密循环加 `is_encrypted()` 防止二次加密。
* 修复：**同步中心搜索只认完整路径，输入裸文件名搜不到**。此前服务端把 `x8BnM.webp` 补成 `{upload_path}/x8BnM.webp` 再做前缀匹配。新增 `src/Sync/Search_Term.php` 让同步中心与 `fast-search.php` 共用同一条规范化规则；筛选时多返回 `filtered_total`，界面显示「筛选自 N 项」。顺带新增 `src/S3/Sync_Center_Module.php` 的 `ajax_preview()`（nonce `sakura_s3_sync_preview`），详情预览按需拉取而不是整页预载。
* 新增：**多吉云缩略图**。命名样式走路径段（`a.jpg/720wm`），参数模式改用 `imageView2/1/w/{w}/h/{h}`（多吉云忽略裸 `?w=`）。新增 `src/Thumbnail/Provider_Registry.php` 把「谁能用哪种语法」集中到一处，`src/Thumbnail/Providers/Doge.php` 落地多吉云，`src/Thumbnail/Style_Suffix.php` 负责识别与剥离路径段样式。`Decorator` / `Theme_Cache` / `Probe` 的「已优化」「去重」「样图」判断同时认这两种写法。注意：多吉云的宽高参数走 query，CDN 加速域名的缓存规则要关掉「忽略参数」，否则同一张图会被缓存成同一份；设置页已加这条提示。
* 新增：**媒体库的缩略图改为走云存储，没有就回落原图**，不再为后台预览单独生成本地小图。只挂 `image_downsize` 一个过滤器同时覆盖网格与列表两种视图；列表请求的是 `array( 60, 60 )` 这种无名像素框，按 `thumbnail → medium → medium_large → large` 取第一个已配置的尺寸名，报给浏览器的尺寸仍是 60×60，布局不变。生产实测网格 79 张里 73 张走云渲染、6 张回落、0 破图；列表 20 行 17 / 3 / 0。
* 修复：**SVG 与 GIF 不该被套缩略图**。这两条格式规则此前只写在 `src/Thumbnail/Decorator.php` 的前台路径里，媒体库新路径没复制，于是 SVG 被拼成 `.svg/siothumb`（对象存储返回 400，页面上就是破图），GIF 被转成静态 WebP（动图冻在第一帧）。现在提取为共享的 `src/Thumbnail/Format_Rule.php`，前后台共用同一份判据：SVG 恒跳过，GIF 仅在「允许处理 GIF」开启时才处理。
* 调整：**存储页版面**。删掉重复的「测试连接」卡片（按钮已并入服务商那一行，走表单里当前填写的参数、不保存也不上传）；「最后错误」块从跨 2 列改成 `grid-column: 1 / -1` 铺满整行，并补上此前缺失的 `.kpi__value--text`（长错误文本此前继承 21px 字号）；与输入框、下拉并排的按钮统一 `height: var(--g-ctl-h)`，边框高度一致（此前输入框 38px、`.btn` 36px、`.btn--small` 30px 三种混排）。存储空间名称过长时显示为「前缀…后缀」，完整值放进 `title`。
* 修复：后台提示条的 `<div>` 不再承载页面级的 `min-height` 与背景层；`.sakura-admin` 的类名本身没有改动（`components.css` 里有 367 处选择器依赖它）。
* 测试与文档：新增 `tests/ConnectionProbeTest.php`、`tests/DogeCloudResolverTest.php`、`tests/Thumbnail/AdminThumbnailsTest.php`、`tests/Thumbnail/DogeTest.php`、`tests/Thumbnail/ProviderRegistryTest.php`、`tests/Thumbnail/StyleSuffixTest.php`、`tests/SearchTermTest.php`、`tests/IndexRowPayloadTest.php`、`tests/BucketLabelTest.php`、`tests/S3ClientFactoryTest.php`、`tests/DogeCloudCredentialsTest.php`，全量 **570 tests / 2,608 assertions / 6 skipped**（1.27.5 是 470 / 2,411）；`tests/bootstrap.php` 补齐 `wp_safe_remote_post`、`wp_remote_retrieve_body`、`wp_json_encode`、`wp_attachment_is_image`、`wp_get_attachment_url`、`get_attached_file` 等桩。`docs/开发规则/03-后台UI.md` §6、§10.2、§12 补「并排控件的边框高度必须一致」的规则、CSS 片段与验收方法；代码结构文档重新生成为 1.27.6。
* 影响范围：新增 `src/S3/{Bucket_Label,Connection_Probe,Doge_Cloud_Resolver}.php`、`src/Sync/Search_Term.php`、`src/Thumbnail/{Admin_Thumbnails,Format_Rule,Provider_Registry,Style_Suffix}.php` 与 `src/Thumbnail/Providers/Doge.php`；改动 `src/S3/`（Options、Profile_Manager、S3_Storage_Page、Settings_Saver、Sync_Center_Module、Connection_Tester、Error_Mapper、Client_Factory）、`src/Sync/`（Index_Repository、Scan_Service）、`src/Thumbnail/`（Decorator、Probe、Rendition、Settings、Theme_Cache）、`src/Admin/`（Layout、UI/Page）、`includes/`（class-s3-doge-cloud、class-s3-storage-module、class-thumbnail-optimizer-module、views/thumb-settings、views/s3-sync-center）、`fast-search.php` 与 `assets/ui/`。**不改数据表结构、不改路由、不改 option 键名与既有钩子语义**；`sakura_imgopt_s3_profiles` 里新增一个可为空的 `space` 字段（缺省为空，按存储空间名称回填），旧配置照常读取。

= 1.27.5 =
* **后台切页不再「糊一下」**：WordPress 7.0 起给整个后台启用了跨文档 View Transitions，每次点模块或页签都会把新旧两页**整页**交叉淡入淡出 250ms。本插件的后台是密集表格与卡片，两层半透明叠在一起就是文字互相穿透：慢放截图里旧页标题压在新页标题上，侧边栏在内容已经落定之后还在变色。
* 插件页面现在退出这套过渡——核心没有 PHP 开关，只能用 CSS `@view-transition { navigation: none }`——改由自己控制切换：新文档硬切，只有主内容列 180ms 淡入并上浮 6px；模块切换条、页签条与侧边栏完全不参与动画，不会再闪。
* 顺带更快：命名为 `view-transition-name` 的元素会让浏览器额外拍快照并单独分组渲染，实测**净增约 15ms**；完全不快照反而比默认过渡快约 30ms（点击到 load 完成：456ms → 422ms 均值，5 轮）。
* **点击即时反馈**：WordPress 引导一次后台请求约需 300ms，这段时间浏览器一直停在旧页面，看起来像没点中。现在点击模块或页签的同一帧，页面顶部会亮起一条 2px 樱色扫光条，新文档加载出来时自然消失；`prefers-reduced-motion` 下改为整条常亮、不扫光，仍然给出「已收到」的信号。
* 为什么不用预取：Speculation Rules 在登录态后台被响应头 `Cache-Control: no-store` 挡住，浏览器不会发出预取请求（实测悬停目标 3 秒，服务器日志零新增请求），这条路不可行，因此没有引入。
* 影响范围：`assets/ui/base.css`（新增「Page change」段，`prefers-reduced-motion` 块追加一条规则）、`assets/ui/js/core.js`（新增点击反馈，懒建进度条节点）。不改 PHP、数据表、路由、option 键与钩子语义。

= 1.27.4 =
* 修复：卸载后仍残留四类数据，现在全部清干净。此前「删除数据」勾选后，外观设置 `sakura_imgopt_ui_style` 无人删除；两把锁（`sakura_imgopt_stats_lock`、`sakura_imgopt_upgrading`）与对象存储的 `sakura_imgopt_lock_sio_ul_*` 也留着；transient 前缀表只覆盖 `sakura_queue_` 与 `sakura_imgopt_doge_token_` 两项，于是升级错误提示、监控提示、缩略图探测复位、`sufm_embed_page` 这些一年后才自然过期的缓存条目一直留在 `wp_options` 里；`sakura_imgopt_s3_config_notice_dismissed` / `_snoozed_until` 两条 usermeta 同样没人删。
* 现在卸载会删掉外观设置项；两把命名固定的锁与 `sakura_imgopt_lock_%` 前缀的锁一并删除（锁是 option，不是 transient）；transient 扫描扩到 `_transient_sakura_imgopt_` / `_transient_sufm_` 两个命名空间（含对应的 `_transient_timeout_`）；并补删上述两条 usermeta。
* 顺带：`sufm_delete_tick` 是带批次 id 的单次事件，`wp_clear_scheduled_hook` 清不掉，卸载路径现在改用 `wp_unschedule_hook` 处理它，不再依赖「用户一定会先停用」这个前提。
* 修复：停用后 `sakura_imgopt_sync_calibrate` 与 `sakura_imgopt_sync_calibrate_now` 仍留在定时任务里。这两个事件由校准器自己排期，不经过激活流程，停用清单漏了它们，于是插件已停用但每月仍有一次校准被触发。现已补入 `Deactivation`。
* 测试：`tests/bootstrap.php` 补齐此前缺失的 cron、多站点与角色桩，以及一个伪 `$wpdb`；新增 7 个用例覆盖卸载清理（含多站点逐站遍历）、transient 前缀扫描、锁与配置提示残留、停用清空 17 个 hook。全部用例都验证过「换回旧代码即失败」。
* 验证方式：在隔离克隆站（同一份数据库副本、独立表前缀）跑两个卸载轨道；「删除数据」轨用旧代码残留 13 行、新代码 0 行，「保留数据」轨设置项全部保留、临时数据与锁全部清空。
* 影响范围：`src/Uninstaller.php`、`src/Deactivation.php`、`tests/bootstrap.php`、`tests/UninstallerTest.php`、`tests/DeactivationTest.php`。不改数据表结构、路由、option 键名与既有钩子语义。

= 1.27.3 =
* 修复：「空间套餐」的订单图标字段被撑成两行。此前选择器是一块 112×96 的虚线方块（96×96 缩略图 + 竖排「选择图标」），行高由缩略图决定，右侧 URL 输入框被顶到卡片上沿、下面留一大块空白，整行约 150px。现在改成与输入框同规格的 chip：高 `--g-ctl-h`（38px）、同边框 `--g-line-strong`、同圆角 `--g-radius-sm`、同 inset 高光，缩略图缩到 28×28（圆角 6px），「选择图标」文字保留，图标与输入框底边对齐，整行从约 150px 降到 38px（说明文字另起一行）。
* 图标字段的 URL 输入框加上 `max-width: 560px`，符合「路径/长文本 ≤560px」的规则，不再随卡片一路拉宽；≤782px 单列时 chip 用 `justify-self: start` 保持内容宽，不做整条横条。
* 顺带修一个通用 bug：`.btn` 的 `display` 覆盖了浏览器默认的 `[hidden]` 规则，于是「移除」按钮被 JS 置为 hidden 后仍在页面上占 30px 高、把输入框撑开。新增 `.sakura-admin .btn[hidden] { display: none; }`。
* 影响范围：`assets/ui/pages/files-zibll.css`（`.zibll-icon-field` 段重写）、`assets/ui/components.css`（`.btn[hidden]`）、`includes/views/userfiles-space-admin.php`（预览缩略图 `width`/`height` 96→28）、`docs/后台UI规则.md`（10.2 补 chip 规则）。不改 JS 钩子、数据、option 键、路由与钩子语义。

= 1.27.2 =
* **提示弹窗改成无遮罩卡片**：.sakura-modal 不再是「全屏遮罩 + 居中卡」，遮罩节点、`--g-overlay` 铺色和 `backdrop-filter: blur(3px)` 全部删除；外层只剩一个透明的 `position: fixed` 居中容器（`pointer-events: none`），卡片自己 `pointer-events: auto`，页面照常可滚、可点、可读。确实需要模态语义的地方（二次确认、媒体预览）仍用原生 `<dialog>` + `showModal()`，各自保留 `::backdrop`。
* **修掉反复出现的「打开弹窗页面闪一下」**：此前弹窗一打开就调 `scrollLock.lock()`，把 body 改成 `position: fixed` + `top: -scrollY` 钉住，文档当帧塌成视口高、滚动条消失、整页重排；同时 `html.sakura-scroll-locked { overflow: hidden }` 又挤掉滚动条让视口变宽 15px，居中列横向抖一下。现在这套滚动锁整体删除——弹窗不覆盖页面，也就没有可锁的滚动；打开前后 `scrollY`、`getBoundingClientRect()` 与视口宽度都保持不变。
* **自动弹窗不再排队等 180ms / 220ms**：延迟打开意味着浏览器先画完一整页没有弹窗的内容，再补一张卡上去。现在 `initModals()` 与对象存储两个页面控制器都在脚本执行时同步打开，并给根节点打上 `data-sakura-modal-instant`，卡片属于第一帧。
* **动效只留透明度**：入场淡入 140ms、关闭淡出 110ms，去掉上浮 10px / 缩放 0.985 / 图标过冲弹跳；`data-sakura-modal-instant` 与 `prefers-reduced-motion` 下无入场动画。卡片本来就在正确位置，「飞进来」只是让用户等它。
* 无障碍随之调整：提示弹窗不再写 `aria-modal="true"`（没有遮罩就不是模态），也不再做焦点陷阱；`role="dialog"`、`aria-labelledby`、`hidden` / `aria-hidden` 成对切换、Esc 与关闭按钮可关、关闭后焦点 `focus({ preventScroll: true })` 回到触发元素，这些保留。二次确认的 `::backdrop` 去掉了 `blur(2px)`，只留色调。
* **保存过的页面再弹提示不会再跳位置**：`restoredOffset`（保存往返恢复的滚动值）此前从不清空，于是保存过一次之后，用户滚到别处再点开任何提示，页面都会被拽回上次保存时的位置——在无遮罩方案下，这就是最容易被当成「又闪了一下」的那种跳动。现在 `admin.ready()` 在首帧结束后立即清空它：恢复值只用一次，之后的弹窗一个像素都不动滚动位置。
* 影响范围：`assets/ui/js/ui.js`（删 scrollLock，新增 hideModal，openModal/initModals 重写）、`assets/ui/components.css`（Prompt modal 段重写、删 `.sakura-modal__backdrop` 与 `html.sakura-scroll-locked`）、`assets/ui/js/pages/storage-sync.js` 与 `storage-connection.js`（不再锁滚动、同步打开）、`src/Admin/UI/Modal.php` 与 `src/S3/Config_Notice.php`（不再输出遮罩节点）、`tests/ConfigNoticePausedTest.php` 的关闭点计数、`docs/后台UI规则.md` 的弹窗条款。不改数据、路由、option 键与钩子语义。

= 1.27.1 =
* 修复：移动端卡片被挤成一条竖条。1.27.0 把行号、起始列和跨列数写成**内联** `grid-row` / `grid-column`，内联声明优先级高于任何样式表，于是 ≤1100px 的单列规则失效，卡片仍按 12 栅格定位，在手机上一张 6 栏卡只剩半屏宽。现在改为内联 `--g-card-row` / `--g-card-col` / `--g-card-span` 三个自定义属性，由 `components.css` 读取；断点里把两个方向都置为 `auto`，卡片正常纵向堆叠。
* 修复：桌面端同一行里较矮的卡片下方留出大片空白。行高由该行最高的卡决定，而此前用 `align-items: start` 让矮卡贴顶，于是旁边空出一块画布——系统设置、对象存储、同步中心都能看到。改为 `align-items: stretch`，同一行卡片等高，两栏一行读起来是一整块内容。
* 优化：消除半满行。此前有 10 处「一行只放了一张 6 栏卡」，右侧整片空白（系统设置的布局卡、同步中心的核对口径卡、图片优化的批量卡等）。按阅读顺序重新打包，并把后面紧跟整宽卡、注定落单的卡片改为整宽，现在 13 个页面**每一行都恰好填满**。
* 影响范围：`src/Admin/Layout.php` 的行列表、`src/Admin/UI/Card.php` 的输出方式、`components.css` 的 `.card-grid` 与响应式断点。不改 PHP 逻辑、数据、路由与钩子。
= 1.27.0 =
* **移除卡片拖拽布局引擎**：后台卡片改为服务端确定的静态排布。此前的位置来自一个 JS 引擎——它把每张卡改成绝对定位并重写文档高度，于是长页面会跳动、卡片会晚一帧出现、首屏还曾整页空白两秒。现在每张卡的行号、起始列和跨列数由 `Layout::PAGES` 声明，直接写成 `grid-row` / `grid-column`，浏览器首帧即最终形态。
* 一并删掉：`layout.js`、`layout.css`（约 2600 行）、右侧「布局修改」工具栏、拖拽与缩放、`LAYOUT_PAGES` / `has_layout()` / `sakura-layout-enabled` 与 46px 预留留白、`Layout` 的保存端点与按用户存的 `_sakura_imgopt_layout` 元数据。卡片排布将成为出厂设置，不再随用户变化。
* 默认排布取测试环境确认过的版本：系统设置、图片四页、对象存储三页、文件空间五页共 13 个页面全部按该版本固定。行号显式声明，因为视图按代码顺序输出卡片、未必等于阅读顺序；只靠列宽会让 CSS 按 DOM 顺序打包而留下空洞。
* **提示弹窗加入动画**：入场 220ms 上浮 8–10px 并淡入，遮罩 180ms 交叉淡入，图标延后 60ms 带轻微过冲落定；关闭 150ms 反向播放，`animationend` 后才真正隐藏（另有定时器兜底）。全部在 `prefers-reduced-motion` 下关闭。动画期间滚动位置与焦点返回逻辑不受影响。
* 布局测试重写：新增「卡片不得越出 12 栅格」「同行卡片不得重叠」「`order` 与 `cards` 必须描述同一批卡」以及「顺序必须与视图渲染顺序一致」四项，从视图文件直接读卡片 id，避免再次错位。
* 《后台 UI 规则》删去全部布局引擎相关描述，写明静态排布契约：为什么行号要显式、为什么不允许再有「加载后用 JS 改布局」的机制、如何给新页面登记排布。
* 影响范围：`assets/ui/`（删两个文件、改两个）、`src/Admin/`（`Layout`、`Card`、`Page_Assets`、`Page`、`Plugin`）、`tests/LayoutTest.php` 与 UI 规则文档。不改数据表、路由、option 键与钩子语义。
= 1.26.2 =
* 修复：对象存储「监控与告警」卡片里三个数字输入框（失败率阈值 / 最少尝试次数 / 冷却时间）在标签折行不一致时起点错位。根因是 `.field` 只是普通 block，标签多一行就把自己的控件往下推；现在同一网格行的字段等高，控件改为贴底对齐，标签随便折行也不影响输入框对齐。
* 修复：同一组三个输入框此前被塞进字段网格的单个半宽列里，桌面 1440 下每个输入框只有 72px 宽。现在整行铺满（162px），并在 `≤1100` 正常回落单列。
* 影响范围：`components.css` 的 `.field`、`storage.css` 的 `.monitor-fields`、`Monitoring_Panel.php` 的容器类；不改数据、不改路由。
= 1.26.1 =
* 修复：1.26.0 的默认布局顺序与部分页面的实际渲染顺序不一致——对象存储把「上传指标」排在「连接测试」之前、同步中心把「备注」排在「核对/日志」之前、文件空间少算了「支付方式」与「套餐」两张卡。只影响「恢复默认」和首次安装时的初始排布，不改用户已保存的布局。
* 修复：同步中心顶部失败提示的状态列写法恒真/恒假（`'bad' === $notice[0]` 永远不成立，改成 `'error'` 后永远成立），静态分析报 alwaysFalse / alwaysTrue。这一列本来就是死的，已删除，失败提示统一用 bad 样式。
* 新增回归测试 `test_default_order_matches_markup_render_order`：直接从视图文件里读出每页卡片的渲染顺序，与 `Layout::DEFAULT_LAYOUT` 比对。以后再改默认布局，只要与页面渲染顺序不符就会在发布前失败，而不是悄悄给用户重排页面。
* 影响范围：`Layout` 默认值、若干提示的输出写法、测试；不改路由、option 键、钩子语义与数据表结构。
= 1.26.0 =
* 配色重做：插件叫「樱花工具箱」，后台却一直是石墨色。现在整栈换成一套樱色令牌——暖樱白画布 + 顶部两层樱雾 + 纯 CSS 生成的「樱尘」点阵（不用任何图片或 SVG），卡片是白底加角落樱粉泛光，主按钮换成深樱 #9d3452（亮粉配白字达不到 4.5:1，不适合长时间阅读），进度条是浅樱轨道 + 由深到浅渐变，扇形统计图用同一色相的四级（#8f2f4d / #c25e7d / #e79fb3 / #f3d8dd）。所有文字/底色组合实测对比度 ≥4.5:1。
* 顺手修掉三个真实缺陷：① 全插件百分比进度条一直是 0 宽——条体是 span 且没写 display，行内元素忽略 width，统计页「平均压缩率 70.8%」其实整条空白；② 最强那层樱雾按百分比定位，在 5500px 高的页面上被推到视口上方 880px，等于永远看不见，改成顶部按像素锚定；③ 统计页重置按钮与设置页取色器的预览值还是旧石墨色。
* 反馈统一：保存与检测结果一律走居中提示弹窗（新增 UI::modal()，ok/bad/warn/info/neutral 五色图标、440px 紧凑版），页内只保留持续状态说明，AJAX 小动作用右下角 toast。WordPress 原生 notice 全部改为插件样式；顺带修掉「设置已保存」同时出现在弹窗和原生 notice 的重复。
* 位置稳定：弹窗打开/关闭不再改变用户位置——锁滚动从 body{overflow:hidden}（会挤掉滚动条、整页横向抖动）换成 position:fixed + 负偏移，关闭时精确还原 scrollY 并让焦点回到触发元素但不把页面滚进视野；保存往返（POST→302→新文档）记住并恢复滚动位置，screen key 不含 tab（部分 saver 重定向不带 t）。
* 布局重做：布局页加载不再闪烁、卡片不再延迟出现。加载一律走文档流，自由画布只在「布局修改」里启用；退出编辑器用显式的 arranged 标记（schema 5）记住「这页排过」，下次加载还原；「恢复默认」清掉标记回到流式。同时修掉首屏隐藏规则误命中 .card-grid 导致整页空白两秒的问题。
* 默认布局更新：把本次调好的卡片排布固化为出厂默认（缩略图 / 对象存储 / 同步中心 / 系统设置 / 备份还原 / 文件空间）。升级时旧布局（schema<5 且未自行排过）自动迁移到新默认，不覆盖用户自己排过的看板。
* 文档：《后台 UI 规则》重写配色与弹窗两章，新增 §13「新功能页面开发清单」，把这些判据（加载不闪、保存不跳顶部、弹窗不改位置、不横溢、console 干净）写成可验收的步骤。
* 影响范围：assets/ui/ 样式与脚本、src/Admin/UI/Modal.php（新增）、若干视图的反馈通道、Layout 默认值。不改路由、option 键、钩子语义与数据表结构。
= 1.25.9 =
* 修复：对象存储的三类提示弹窗（未配置 / 已停用 / 需要首次核对）此前整张卡都左对齐，560px 宽的卡片里正文却只占 368px，右边空出一大块，看着像排版坏了。根因不是对齐，而是正文那条 `max-width: 46ch` —— 中文一行只排到约 23 个字就折行。
* 排版改为「图标、眉标、标题居中，说明与底部操作区保持原样」：图标由 `inline-flex` 改成 `display:flex` 并用 `margin:0 auto` 居中（inline 级盒子会忽略 auto 外边距，只加 margin 图标仍停在左边）；眉标与标题加 `text-align:center`；正文去掉行宽上限，在 512px 的内容宽里自然折行。
* 实测（1403 / 520 / 390 / 320 四种视口）：图标、眉标、标题的中心与卡片中心完全重合（1403px 下均为 702px），正文仍左对齐、按钮仍两端对齐，卡片高度 291px 不变，四档视口都没有横向溢出。
* 把这条排版约定写进《后台 UI 规则》§5.1：居中头部、左对齐正文、正文不要加 `max-width: 46ch` 这类行宽上限，并说明 inline 级盒子会被 auto margin 忽略这个坑。
* 影响范围只有 storage.css 的 4 条规则与 UI 规则文档；不改 PHP、不改数据表、不改钩子，具名钩子仍为 209。

= 1.25.8 =
* 修复：同步中心和存储配置此前没有真正一一对应。数据本来就按配置 id 隔离，但「原地改桶名」会把指向新桶的配置写成同一条，于是同步中心拿着旧桶的计数、环图与健康卡显示在新名字下面，而且因为它「看起来有核对记录」，未核对引导弹窗也不会出现。现在服务商、存储桶、服务地址任一变化都会保存为一份新配置，旧配置与它的核对记录原样保留；保存后页面会明说「已保存为新的存储配置」。
* 停用即冻结：S3 总开关关掉后，同步中心的核对、刷新、停止、继续、重试、测试连接、切换同步记录、忽略规则保存，以及行内上传 / 下载 / 删除与批量上传 / 下载 / 删除全部置灰，只留「连接设置」这类能带你回到开关的入口。页面顶部和弹窗都会说明原因；筛选、搜索、翻页、看详情、导出 CSV、清除选择仍可用——这些不碰存储桶，禁掉只会妨碍排查。前端对 AJAX 重画出来的行与批量栏同样套用冻结态，强行点也不会发出请求。
* 删除已保存的配置会连带清空它名下的同步数据：此前只清索引、计数与任务，核对记录与事件日志会永远留在库里，界面上再也找不到能清掉它的地方（同步中心按配置读数据，配置没了就没人读得到）。现在五张表一起清，删除确认与 CLI 的 `sync purge` 提示都写明了这一点。
* 同步中心右上角状态卡改为显示「当前存储桶」+ 桶名，副行是「服务商 · 状态」。此前那里显示的是「连接状态 / 已停用」，与连接配置页的桶名各说各话；同一服务商可以存多份配置，只有桶名能说明当前看的是哪一个。
* 影响范围限于对象存储三屏与同步数据清理；不改数据表结构、REST 路由与既有钩子语义，具名钩子仍为 209。

= 1.25.7 =
* 修复：关掉提示弹窗（X、Esc、点背景、次按钮）后刷新同步中心，弹窗照样出现——此前把「关掉」当成「处理过」，静默 12 小时，于是既不修也不再问，页面继续显示停用/未配置却没人被提醒。
* 现在只有勾选「不再提醒我」才会持久静默；未勾选时任何关闭方式都不写库，条件还在就下次进页面继续弹。
* 次按钮文案由「稍后处理」改为「暂不处理」，因为它不再代表「12 小时后再问」。核对引导弹窗同样补上了「不再提醒我」，此前它只能靠关掉来消失。
* 连接配置页的同类弹窗同步改规则，两处行为一致。
* 1.25.6 及更早版本写入的 `sakura_imgopt_s3_config_notice_snoozed_until` 已废弃：升级后读到残留时间戳会直接删除，不压制弹窗；接口收到旧的 `snooze=1` 也只清理、不再延期。
* 影响范围只有提示弹窗的关闭规则与其文案；不改数据表、REST 路由、nonce 与既有钩子语义，具名钩子仍为 209。

= 1.25.6 =
* 修复：S3 连接信息填全但总开关关掉时，同步中心把状态显示成「配置不完整」，右上角还跟着「缤纷云 · 覆盖率 1.5%」——一边说配置坏了，一边又报出覆盖率，两句互相打脸。现在这种状态显示「已停用」，副标题是「缤纷云 · 连接信息完整，开关关闭」，健康卡里的「配置可用 / 配置不完整」同样改成三值。
* 新增：同一状态下进入同步中心会弹出「对象存储已停用」提示，说明新图不会同步到云端、媒体地址也不会被接管、下面的数字停留在停用之前，主按钮直接去连接配置页打开开关；仍带「不再提醒我」与「稍后处理」（关闭即静默 12 小时）。
* 三种提示互斥，优先级从高到低：缺字段 → 已停用 → 需要首次核对。此前「已配置」的判断把总开关也算进去了，开关一关就既不算已配置、也不算未核对，两种弹窗同时消失，页面只剩一行小字，用户看不出是漏了设计还是坏了。
* 停用期间仍然允许手动核对——核对只读取云端清单，不上传、不改媒体地址，把它禁掉只会让人没法确认桶里还剩什么。所以本次只补提示与文案，没有收窄权限。
* 影响范围只有同步中心的连接状态文案与提示弹窗；不改数据表、REST 路由、nonce 与既有钩子语义，具名钩子仍为 209。

= 1.25.5 =
* 新增：同步中心每月自动校准一次云端清单。此前那些数字只在手动点「核对」时才更新，站点放几个月不动，卡片就会一直描述一个早就不存在的桶。
* 做法：新增定时任务「每月（Sakura 云端校准）」，到点自动发起一次增量核对（本地侧按目录修改时间跳过没变的目录，云端照常列举清单），跑完写回同一张扫描表，页面照旧只读本地记录。若因 WP-Cron 只在有访问时才推进而错过，同步中心页面加载时发现数据超过 30 天会顺手补排一次，两条路径共用同一个处理函数。
* 新增：S3 未配置时，同步中心也会弹出配置提醒（与连接配置页共用同一套标记、同一份「不再提醒 / 12 小时后再说」偏好），主按钮直接跳到连接配置页；此前这个弹窗只在连接配置页出现，从同步中心进去只看到一行警告。
* 新增：已配置但一次核对都没跑过时，进同步中心会弹出引导，说明这些数字来自核对写入的索引、首次核对会读取云端清单，主按钮就地发起核对；核对完成页面刷新后弹窗自然不再出现。
* 新增：数据超过 30 天未校准时，同步中心顶部给出一条 inline 提示和「立即校准」按钮，说明期间的增删不会体现在统计里。
* 弹窗按后台 UI 规则重做：560px 居中卡片、标题自动聚焦、Tab 焦点锁在弹窗内、Esc / 点背景 / 关闭按钮都能关、打开时锁页面滚动、窄屏（≤520px）按钮纵向排布且整宽，390px 与 320px 不产生横向溢出。
* 影响范围只有「对象存储 → 同步中心」的提示与定时任务；不改数据表结构、REST 路由与既有钩子语义。新增一个类（`Sync\Sync_Calibrator`），具名钩子由 204 增至 209。

= 1.25.4 =
* 修复：「对象存储 → 同步中心 → 云端体积分布」在没做过完整核对时静默显示全 0，圆环整块灰掉、图例全 0.0%，看起来像插件坏了。
* 原因：这张卡上的每个数字都只来自最近一次完整核对写进扫描表的那一行（云端对象数、字节数、图片 / 视频 / 压缩包分类计数）。没有扫描记录时全部取 0，代码里没有任何回退数据源；单张上传只会更新同步状态计数，不会改写扫描行，所以上传了文件这张卡也不会变。
* 现在卡片会按实际状态说话：从未核对显示「还没有核对记录」并给出「开始完整核对」按钮（点一下就地发起核对，跑完自动刷新）；核对进行中显示「正在核对」；上次中断显示「上次核对没有跑完」并给出「继续核对」；已核对但桶里确实没有对象时保留 0% 的环并说明「上传后需要再核对一次」。底部的本地 / 云端 / 差三栏在所有状态下都保留。
* 核对完成时多一行说明，写明数据来自哪一次核对（例如「数据来自 11-13 00:27 的完整核对」），并说明单张上传不会改变占比、重新核对才会更新。
* 顺带把「索引为空」后台日志的措辞由「开始对账」改成与按钮一致的「开始完整核对」。
* 影响范围只有同步中心的这一张卡片与一条日志文案；不改数据表、REST 路由与钩子语义，具名钩子仍为 204。

= 1.25.3 =
* 改进：把「对象存储」三屏（连接配置 / 同步中心 / 删除队列）的卡片统一成一套可自由拖动的画布。此前连接页只有配置、行为、测试三张卡在布局组里，凭据档案、上传统计与监控卡落在组外，没有拖动把手也拖不动；删除队列页则完全没有布局组。
* 现在连接页六张卡、删除队列页一张卡都在各自的布局组内，工具栏的「布局修改」一开即可拖动排序、换列、改宽度，松手后保存到用户元数据，刷新与跨页均保留，点「恢复默认」回到标准排布。
* 同时清理了默认布局里的历史残留：`migrate` 卡在 1.22.8 之后已不存在于页面，却仍留在默认顺序中，导致新用户首次访问与点「恢复默认」得到两种排布；现已删除，并让「恢复默认」的结果与首次访问完全一致（凭据档案 / 行为 / 连接 / 测试 / 上传统计 / 监控）。
* 新增卡片时不再一律追加到末尾，而是按声明位置就近插入，声明在首位的卡片会落在最前。
* 修复：后台提示里的 `#sakura-s3-save-form` 锚点现在指向连接卡片（`#sakura-s3-connection`）。这个 id 过去挂在只包三张卡的网格上，画布合并后会滚到任意一张卡，目标不明确。
* 影响范围只有对象存储页的布局元数据与后台脚本；不改数据表、REST 路由与钩子语义，具名钩子仍为 204。

= 1.25.2 =
* 修复：「文件核对」卡片点筛选后样式错乱。点「待上传 / 失败 / 仅云端 / 内容不一致 / 已同步」把行数从 7 行变成 1 行后，卡片高度不变、剩下的高度被摊成行间距，卡片上下出现大片空白；反过来从 1 行切回多行时，卡片不重新测量，会直接压住下面的「处理日志」卡片。
* 原因有两层。卡片正文 `.card__body` 是 grid 容器，没设 `align-content`，grid 行默认 `stretch` 会把卡片多余的高度平摊到工具栏、表格和分页之间；同时宽屏（≥1101px）下卡片排版在绝对定位的像素画布上，`layout.js` 把量到的高度写成卡片的 `min-height`，只在首次布局、窗口缩放、断点切换和拖拽落位时重算，而异步换行从来没有通知它重新测量。
* 修法：`storage.css` 给「对象存储」页的 `.card__body` 加 `align-content: start`，行只占自己需要的高度；`storage-sync.js` 在表格换行、日志级别筛选和「显示更多」之后发一次 `layout-refresh`，让画布重新测量卡片与分组高度。
* 实测（1403px 与 1600px 视口）：工具栏高度恒为 38px；「待上传 / 失败 / 仅云端 / 内容不一致」卡片 409px、「全部 / 已同步」886px，与下方卡片间距恒为 18px（修复前出现过 459px 重叠）。900px 单列模式下卡片随内容自然伸缩，没有 `min-height` 残留。日志卡级别筛选时分组高度 2853 ↔ 2881 跟随变化，页脚不再被压住。
* 影响范围只有「对象存储 → 文件核对」与「处理日志」两张卡片；同一页的连接、行为、测试三张卡片高度与溢出量在修复前后完全一致。本次不改数据表、REST 路由与钩子语义，具名钩子仍为 204。

= 1.25.1 =
* 修复：后台切页卡顿。原因是 WordPress 6.9 起在每个后台页无条件加载命令面板（`wp-commands` / `wp-core-commands`），把 React、区块组件与数据层整套拖进页面；本插件页面本身不依赖它们，却在为这几十个脚本付出代价。
* 插件页面（`sakura-tools`、`sakura-tools-image`、`sakura-tools-storage`、`sakura-tools-files` 及其全部页签，加上隐藏的媒体处理进度页）在 `admin_enqueue_scripts` 优先级 100 上移除 `wp-commands`、`wp-core-commands` 脚本与 `wp-commands` 样式；命令面板的 ⌘K 入口由核心按脚本是否入队判断，摘掉后自然消失。
* 其他后台页面（仪表盘、插件列表、文章编辑器等）不受影响：区块编辑器 `wp-edit-post` 显式依赖 `wp-commands`，全站移除以编辑器失效为代价，因此只对插件页面生效。
* 实测（本地容器，无 CPU 节流，真实点击页签）：单页传输 1,061 KB → 76–85 KB，加载的 `/wp-includes/js/dist/` 脚本 45 → 3 个，`DOMContentLoaded` 2.9–3.1 s → 0.6–0.8 s，点击切页 3.0–3.6 s → 0.7–1.4 s。
* 版本号同步到 1.25.1；本次不改数据表、REST 路由、钩子语义与选项结构，只加一条 `admin_enqueue_scripts` 回调，具名钩子由 203 变为 204。

= 1.25.0 =
* 后台界面整体重构：图片优化、对象存储、文件空间与系统设置四组共 13 个页签统一到一套 UI 栈，外观、控件尺寸、间距与响应式断点由 `assets/ui/` 的令牌驱动，不再每页各写一份 CSS。
* 新增 UI 内核：`src/Admin/UI.php` 与 `src/Admin/UI/{Page,Card,Field,Feedback}.php` 负责页面骨架、卡片、表单控件与提示；`src/Admin/{Card_Form,Appearance,Page_Assets,Route}.php` 负责按卡片保存、外观令牌注入、按页加载资源与路由判定。视图只声明内容，样式不再散落在模板里。
* 新增「系统设置」页，替代原「基础设置」：运行时、图像引擎、环境自检、扩展、布局五张卡片；布局卡可调整卡片表面色、圆角（0–40 px）与边框（0–6 px），保存后作用于全部插件页面。顶级菜单地址仍是 `sakura-tools`，旧链接继续可用。
* 删除旧后台资源栈：`assets/admin.css`、`admin.js`、`admin-ext.css`、`admin-layout.css`、`admin-shell.css`、`admin-unify.css`、两个内联 SVG，以及 `userfiles-admin.{css,js}`、`userfiles-space-admin.{css,js}`，共 12 个文件；同时删除 `includes/views/basic-settings.php`。资源加载改由 `src/Admin/Page_Assets.php` 按页白名单输出，非插件页面不再加载这些 CSS/JS。
* 设置改为按卡片保存：每张卡片独立提交与提示，改一项不必重填整页；页头操作按钮移到说明文字下方，异步表格刷新后重新计算布局，不再出现首屏卡片闪烁。
* 附件核对行新增媒体预览弹窗：图片按需加载并可在弹窗内下载（本地文件走 `admin-post` + nonce，云端对象走 10 分钟预签名地址），视频使用自绘控件播放（原生控件在黑底上不可用），压缩包与其他类型只提供下载并显示文件信息；弹窗打开时保留页面滚动位置，舞台底色改为与插件一致的暖白。
* 删除队列：文件浏览入口移入操作列，可直接按行查看该请求涉及的媒体与容量；窄屏收紧表格与筛选项，去掉重复的筛选控件。
* 统一选择控件与选中态，补「对象存储未配置」提示对话框（选择「稍后」后 12 小时内不再打扰），修正移动端间距与旧版蓝色强调色残留。
* 文档：新增 `docs/后台UI规则.md`（令牌表、组件用法、响应式与验收口径）与 `docs/后台UI重构方案.md`（分阶段迁移记录）；代码结构文档与图谱同步说明更新到 1.25.0。
* 本次不改数据表、REST 路由与已注册钩子的语义；后台新增 `admin_post_sakura_s3_media_download`、`admin_post_sakura_sufm_download`、`wp_ajax_sakura_s3_config_notice` 三个处理器，另挂 `admin_print_footer_scripts` 输出提示对话框。升级后首次进入后台即生效，不需要重新保存设置。
* 规模：生产 PHP 189 文件 / 65,108 行 / 174 个类与接口 / 203 个注册钩子 / 23 张自定义表；图谱 191 节点 / 861 连线。全量测试 441 tests / 1,327 assertions / 5 skipped，PHPStan level 5 与 PHPCS 均无错误。

= 1.24.4 =
* 修复：覆盖升级（不经过插件启用流程）时不再漏跑激活例程；首次前台请求会检测版本并补创建表、排程与索引回填。
* 升级检测使用原子锁；同版本或降级时跳过，失败只记录 transient 并触发 `sakura_imgopt_upgrade_failed`，不阻塞站点，下次请求继续重试。
* 激活例程改为最后写入版本号：建表或排程失败时，下次请求仍会重跑。
* 文档与图谱：修正钩子、类、测试与文件计数，补扫后具名钩子由 191 修正为 199。

= 1.24.3 =
* 修复：开启「卸载时删除数据」后，卸载会按 UserFiles\Schema 清空全部 15 张 sufm 表，不再残留文件名搜索 token 表 sufm_file_tokens。
* 回归测试：新增由 Schema::tables() 驱动的卸载删表测试，覆盖 file_tokens。
* 本次不改 REST、钩子、cron 或数据结构，只改卸载清理路径与测试。

= 1.24.2 =
* 对象存储/Sync 运维：新增只读 wp sakura-s3 health，汇总上传、删除队列、远端守卫与同步作业的 16 项指标。
* 对象存储/Sync 运维：wp sakura-s3 remote-operations 新增 count 与 --limit/--offset 分页；uncertain 仍需人工确认后 release。
* Prometheus：新增 delete_dead、delete_stale、remote_uncertain、sync_jobs_dead 四个只读 gauge，读取失败时降级为 0。
* 上传指标：不自动裁剪事件表；补回滚回归测试，确认旧版写 option 不会造成重复计数。

= 1.24.1 =
* 文件面板：用户中心与编辑页都支持 Shift 连选、Ctrl/⌘ 点选、Ctrl/⌘A 全选与 Esc 清空；网格视图新增「全选 / 取消全选」按钮。
* 文件面板：Shift 范围只加不减并跳过锁定文件；编辑页的插入条会随选区重建数量与顺序，不再与勾选状态脱节。
* 修复 Shift/Ctrl 点击复选框时浏览器默认勾选状态覆盖选区的问题；列表行复选框固定为 14px 方框，圆形样式只保留给网格卡。
* 本次只改前端、视图与文案，不改 REST、数据库、cron 或 PHP 类方法。

= 1.24.0 =
* 空间扩容：支付方式改为后台「支付方式」卡片显式勾选，默认只启用余额；未勾选的外部渠道不会出现在前台，也不能通过过滤器重新启用。积分入口改为独立开关，关闭后隐藏入口并拒绝兑换。
* 空间扩容：每日 paid-no-grant 对账改为租约加指数退避（15 分钟、30 分钟、1 小时、2 小时、4 小时、6 小时），成功才写 24 小时水位；失败保留上次成功时间，告警每天最多一次。
* 空间扩容：新增 wp sufm grants 审计命令，默认 dry-run，加 --apply 才回填历史 order: 授权的订单字段；只写订单号、渠道、金额与积分，不改容量、有效期、状态和 grant_key。
* 空间扩容：refund_failed 仍不自动重试，需人工核对订单与用户后处理；本次没有放宽退款幂等边界。

= 1.23.0 =
* 编辑页文件面板：拖动文件时显示跟手标签与落点提示；文件可直接拖入正文，按拖动位置插入；多选会一次插入多个文件。
* 编辑页文件面板：右键菜单新增「插入」，可把当前文件插入到编辑器光标处；文件夹拖拽仍只用于移动。
* 编辑页文件面板：修复批量操作条归位时报 NotFoundError 的既有问题；编辑器不可用或拖拽取消时给出提示并复位。
* 用户中心的文件管理界面保持不变，本次交互只对编辑页生效。

= 1.22.9 =
* 版本号与常量统一为 1.22.9。
* 空间扩容支付收口：默认只开放余额支付；余额与积分订单按渠道分别记账，退款按渠道补偿并支持重试，新增每日 paid-no-grant 对账；面板在现金或积分不可用时自动降级。
* S3 上传指标改为追加式事件表，失败上传也会被记录，并发写入不再丢样本。
* 代码规范：为 4 条含占位符的后台文案补充译者注释；目录 SQL 异常消息补一条窄范围 PHPCS 例外（异常仅被捕获并写日志），全量 PHPCS 报错清零。

= 1.20.11 =
* 媒体库弹窗：从任意后台页面（如空间套餐的订单图标）打开媒体库时，「上传到文件夹」不再是无样式的原生标签＋下拉框，改为居中的目标文件夹选择器（文件夹图标、浅色卡片、自定义下拉箭头、键盘焦点态），并补上此前缺失的弹窗样式资源。

= 1.20.10 =
* 文件空间：工具栏新增「容量详情」，弹窗展示总容量、已用/可用、每一项容量来源；限时容量标注到期日期与剩余天数，30 天内到期高亮提醒。
* 文件空间：授权记录补充到期时间戳与剩余天数，前台与后台共用同一套到期判断。

= 1.20.9 =
* S3 删除队列：建队时记录存储目标指纹，之后改过 bucket/endpoint 的任务会被拒绝并报错，不再误删新目标里的同名对象。
* S3 删除队列：唯一键加入配置 ID，两个配置下的同名对象不再互相覆盖。
* S3 下载：校验 Content-Length，被截断的下载不再落盘；发布改用独占创建，不会覆盖下载期间出现的本地文件。
* 文件空间：停止批次会释放对象锁并把未完成明细置为 stopped，目录不再被永久锁死。
* 文件空间：删除申请明细补 attachment_id 索引（见 1.20.3）。
* 后台：非插件页面不再加载公共样式与布局脚本（此前所有后台页都会加载约 100KB CSS）。
* 后台：用户容量列表改为一次聚合查询，不再按用户数成倍增加查询。
* 同步中心 CSV：单元格做公式注入防护；超过 20 万行时在文件末尾写入 #truncated 标记。
* 扩容：下架的套餐不能再通过旧 ID 下单。
* 停用插件时一并清理同步、动作与扫描的定时任务。
* 版本号与常量统一为 1.20.9。

= 1.20.3 =
* 文件空间：修复前台上传在附件被媒体库自动纳入后返回 409 的问题；上传失败时彻底回滚附件、账本行与配额。
* 文件空间：转换后的目标文件名改为原子占位，同名上传不再互相覆盖（含关闭「重命名文件」时）。
* 文件空间：配额账本 commit/release 检查影响行数并置于事务内，失败不再重复计费或泄漏预留字节。
* 文件空间：删除执行器按 item 行做状态校验，重复执行只生效一次；artifacts 清理并入同一事务。
* 文件空间：删除申请按对象加锁（`pending_request_id=0`），并校验后代目录锁与执行时的锁归属。
* 文件空间：补上 `sufm_delete_tick` 监听器，确认后约 1 秒开始执行，不再等下一分钟。
* 文件空间：删除申请明细表补 `attachment_id` 索引，媒体库列表不再全表扫描。
* 文件空间：全站容量预算接入配额判定；积分兑换按期限计价，不再能用 0 天换永久扩容。
* 文件空间：幂等键为空时直接拒绝，不再退化成每用户常量键导致永久 409。
* 后台：用户容量 / 审核与执行 / 最近操作三个 tab 改为需要执行权限，审核员不再看到不可用的执行面板。
* 目录整理：去掉按租户锁全表文件与目录行的写法，单次移动不再阻塞同用户的其它上传。
* 开发：composer 增加 `Nboke\UserFiles\` 自动加载映射；后台 Page 构造器只声明实际使用的依赖。

= 1.18.49 =
* 前端文件管理：打开文件页时，空间概览与文件列表同时请求，不再等前者返回才发后者；上传完成后的刷新同样并行。
* 前端文件管理：目录列表与文件计数合成一条查询，进入文件夹少一次聚合；文件夹是否存在改由面包屑解析，省掉单独的校验查询。
* 前端文件管理：空间概览的四个计数（文件、文件夹、待审、已批准）由四条查询合并为一条；配额快照改为先读后写，账本行已存在时不再每次页面浏览都执行一次 INSERT IGNORE。
* 前端文件管理：文件列表只取实际渲染的列，不再把清单版本等记账字段随每行带出。
* 前端文件管理：栅格卡片启用 `content-visibility`，屏幕外的卡片跳过布局与绘制；缩略图加上 `decoding="async"`，解码不阻塞主线程。

= 1.18.48 =
* 前台文件管理：文件夹图标改为与后台媒体库同一套图形，两侧不再一个画 CSS 方块、一个画 SVG；切换文件夹样式与配色时，前台和后台一起变。
* 前台文件管理：文件夹样式同样支持 Windows 11 与 macOS Finder 两套外观——Windows 实心文件夹配琥珀色，Finder 描边文件夹配中性灰；选择配色后 Finder 仍是空心描边，配色只改线条颜色。
* 前台文件管理：网格视图里的文件夹缩略图底色改为中性浅灰，颜色交给图标本身，不再与配色打架；深色主题同步适配。

= 1.18.47 =
* 文件管理后台：新增「文件夹样式」卡片，可在 Windows 11 与 macOS Finder 两套外观之间切换，默认 Windows 11；切换后媒体库左侧文件夹树立即改观（Windows 实心圆角文件夹 + 蓝色选中行，Finder 描边文件夹 + 更轻的层次）。
* 文件管理后台：新增文件夹图标配色，可选择琥珀 / 湖蓝 / 青竹 / 紫藤 / 海棠 / 石墨，或「跟随样式」；配色只作用于图标，标题文字保持中性，避免整棵树花色压过内容。
* 文件管理后台：样式卡片是独立表单，保存它不会重置配额与上传策略；保存策略页也不会覆盖样式选择。

= 1.18.46 =
* 媒体库文件夹：右键任意文件夹（或用键盘 Shift+F10）就能打开操作菜单，和前端文件管理一致；鼠标悬停时右侧的「⋯」按钮仍然可用。
* 媒体库文件夹：操作菜单收紧了行高与内边距，五项从 274px 缩到 162px，不再是每项 56px 的按钮堆叠；桌面每项 30px，手机保持 44px 触控高度。
* 媒体库文件夹：菜单会跟随鼠标位置弹出，靠近屏幕底部时自动向上翻转；点击菜单项、按 Esc、滚动页面或缩放窗口都会收起，打开菜单的那一行保持高亮。

= 1.18.45 =
* 媒体库：左侧「用户文件夹」栏改成一条通到底的白色列——此前是浮在 WordPress 灰底上的圆角卡片，左边留一条 20px 灰缝一直到屏幕底部，看起来像分割线没铺满。现在面板从顶部工具栏下方开始、铺满左侧留白，只用一条右边界线划分。
* 媒体库：文件夹栏的横向位置改为跟随 WordPress 菜单宽度（宽屏菜单展开时贴菜单右侧，窄屏折叠成图标条时随之左移），修掉了 961–1199px 之间文件夹栏压在菜单下面、搜索框被遮住的问题。

= 1.18.44 =
* 媒体库：附件详情弹窗左上角不再出现「上传目标文件夹」控件——此前选择器锚定在弹窗的内容区，而附件详情复用了同一个容器，于是把上传控件插进了详情栏。现在只附到真正的上传面板（`.upload-ui`）。
* 媒体库：「指定归属」不再需要先猜操作顺序——进入「未分配媒体」后，选择工具条常驻并提示下一步，空列表时也会说明这个视图是做什么的，不必先勾选才能看到入口。

= 1.18.43 =
* 媒体库：跨账号转交不再被「没有制品清单」的旧记录挡住——旧版上传/补登的媒体行只有计费金额、没有逐文件清单，过去转给别的账号一律报错。现在首次转交会从磁盘重建清单，并要求字节数与账本完全一致才放行（不一致仍然拒绝），成功后把清单写入数据库、清单版本升到 1，之后转交直接读记录。
* 媒体库：转交成功后返回结果新增 `promoted` 字段，列出这一次被补齐清单的附件。
* 媒体库：「未分配媒体」视图里的选择工具条按钮从「移动到」改为「指定归属」，说明这一步是把无主媒体分配给某个账号。
* 前端文件管理：未纳管附件补登时同时写入逐文件清单（清单版本 1），补登出来的记录和正常上传一样可以跨账号转交；磁盘与账本对不上时仍保持「未证明」状态，不会被误转交。
* 媒体库：附件的插件元数据（图片优化记录、S3 副本标记、浏览量等）不再否决首次纳管——此前任何一个带这类元数据的站点，首次纳管会永久失败。

= 1.18.42 =
* 前端文件管理：未纳管附件（从来没进过账本的附件）不再绕过配额与删除守卫——删除前、上传前都会先把该账号名下"有文件但无账本记录"的附件补登进账本，再按 source 判定。
* 前端文件管理：附件归属只认 WordPress 记录的 `post_author`，不再读表单里的 `sufm_owner`；不经插件上传选择器的上传也会被纳管到作者自己的根目录。
* 媒体库上传：配额判断前先补登积压的未纳管附件，超额文件在上传前就被拒绝。

= 1.18.41 =
* 媒体库上传：接入 WordPress 官方 `wp_handle_upload_prefilter`，上传前就按插件设置检查文件类型白名单、单文件上限与剩余空间，超额/违规文件直接拒绝，不再先落盘再算账。
* 媒体库上传：被拒绝的文件在进度面板里显示为红色「上传失败」，不再误报「上传完成」；同一批里既有成功又有失败时，标题按面板实际失败行判定。
* 前端文件管理的上传管道（`Uploader::in_pipeline()`）走同一过滤器时自动放行，避免已被服务端校验过的 REST 上传被重复判定。

= 1.18.40 =
* 前端文件管理：删除申请明细的附件名/文件夹名联表查询加上所有者条件，申请状态锁与解锁也限定在申请所属账号内，避免任何"按 id 直读"路径越界（纵深防御，此前上游已校验故未被利用）。
* 前端文件管理：目录重命名、移动、删除、排序的落库语句统一带 owner_id 条件；撤回申请时按申请所有者释放锁，不再按请求号全域释放。
* 插件升级时自动补授「管理自己的文件空间」「申请删除自己的文件」两项能力，避免新站点普通用户 403。
* 媒体库附件摘要：非本人附件只在具备「整理媒体目录」能力时显示所有者与虚拟目录，编辑不再能看到他人文件的归属信息。

= 1.18.39 =
* 媒体库：「上传到文件夹」下拉重新设计——浅灰圆角外壳、8px 圆角白底控件、自定义箭头，去掉媒体库页里刺眼的分隔线与原生直角框；上传媒体页、内联上传器、媒体弹窗三处一致。
* 媒体库：删除图片后弹出「已删除 N 个文件。」提示；部分失败时说明有几个没能删除，并自动刷新网格与左侧目录计数。

= 1.18.38 =
* 媒体库：管理员可以直接删除「来源 = 媒体库」的图片（与 WordPress 原生删除一致），删除后账本标记为已清除、配额同步退还、并记录一条 media_delete 事件。
* 用户中心上传的文件仍受保护：从媒体库删除会被拦下并提示改用「提交删除申请」。

= 1.18.37 =
* 媒体库：上传改为右侧上传进度面板，逐个文件显示文件名、百分比与进度条，并在标题处显示「已完成 / 总数」；完成后自动刷新媒体库网格与左侧目录计数，新上传的图片会立即出现。
* 上传页（上传媒体）：同样使用进度面板，替代原来只有一句总结的浮层提示。

= 1.18.36 =
* 媒体库：修复「上传到文件夹」下拉一闪而过——此前请求管理员目录接口时未带 owner_id，返回的文件夹树为空，前端随即隐藏；现改为走当前用户的文件夹树接口。
* 媒体库：上传新附件默认纳入用户文件账本（根目录或所选文件夹），不再全部堆在「未分配媒体」；根目录也是合法目标。
* 媒体库：上传时显示「正在上传 N 个文件…」「N 个文件已上传完成。」或失败原因，媒体库网格页的内联上传器也支持选择目标文件夹。

= 1.18.35 =
* 前台文件管理：文件/文件夹可直接拖拽进目标文件夹（拖到文件夹行、文件夹磁贴或面包屑即可移动，文件夹拖拽同样支持），拖拽时显示目标高亮与拖动角标，锁定对象拒绝投放。
* 前台文件管理：文件/文件夹支持右键菜单（打开、重命名、移动到、复制链接、下载、申请删除），菜单在光标处弹出并自动避让视口；可用 Shift+F10 / 菜单键打开，方向键切换、Esc 关闭。

= 1.18.34 =
* 前台文件管理：桌面端文件列表改为卡内滚动（约一屏高、表头吸顶），页面高度不再随文件数增长；行菜单改为视口定位，滚动时自动收起。手机与平板布局不变。

= 1.18.33 =
* 前台文件管理：桌面端（≥1200px）改为「一张卡、两条命令带、一张对齐的表」——空间卡折叠进工具栏容量条、类型与搜索合并为顶部命令带、文件夹与文件共用 5 列表格（名称/大小/修改时间/操作），网格统一 5 列 4:3；手机与平板布局不变。
* 前台文件管理：文件夹行显示「N 个文件」，REST 列表补 `count` 字段（单条 SQL 批量统计）。

= 1.18.32 =
* 媒体库：移除强制 media-frame 高度，修复手机端网格只显示首屏 4 张、无法继续滚动加载。


= 1.18.31 =
* 媒体库文件夹面板：改为竖向分隔线 + 圆形方向按钮收起/展开（对齐 FileBird），列表自适应铺满。


= 1.18.30 =
* 媒体库：修复右侧空白（清除 WP 浮动工具区影响），列表改为自适应铺满宽度；文件夹面板改版为 FileBird 密度并保留多用户分组。


= 1.18.29 =
* 媒体库文件夹面板：对齐 FileBird 的密度与层级样式（蓝色主操作、工具栏重命名/删除/子文件夹、紧凑行高、激活态高亮），面板占满整列并保留收起/展开。


= 1.18.28 =
* 媒体库：文件夹面板铺满整列，新增收起 / 展开按钮，状态记忆在本地。


= 1.18.28 =
* 媒体库与前台文件空间共用目录；用户根按站点成员分页展示，包含空用户，并区分用户根、未归类和未分配附件。
* 网格和列表支持单选、多选拖放及「移动到」；同用户只改目录，跨用户转交或首次纳管先预览并确认，再统一更新归属与额度。
* 目录、来源、用户、申请状态和具体申请按交集筛选，保留 WordPress 原生分页、搜索和附件详情。
* 桌面目录树独立滚动；手机改为抽屉式目录，保留图片区域与触控操作空间。

= 1.18.27 =
* 媒体库：全页上传器、编辑器媒体弹窗均可选择「上传到文件夹」，上传后自动归入并计入容量。
* 媒体库：文件夹计数显示子树合计；支持一次导入 FileBird 文件夹树与归类（`wp sufm import-filebird`）。


= 1.18.26 =
* 媒体库：上传到指定文件夹（全页上传器与媒体弹窗）；支持一次导入 FileBird 文件夹（`wp sufm import-filebird`）。


= 1.18.23 =
* 媒体库新增「虚拟文件夹」侧栏，与前台文件空间共用 sufm 目录；支持按用户/文件夹筛选、新建/重命名、拖拽移动，并在启用时接管第三方文件夹面板。


= 1.18.22 =
* 同步容量会把媒体库既有附件纳入账本（source=media），计入容量与文件数；删除仍走申请审核。


= 1.18.21 =
* 用户容量卡补充说明：容量只统计文件空间上传的文件；清理已删除用户的遗留账本行。


= 1.18.20 =
* 新增主题上传限制同步：读取子比主题「上传限制」写入插件设置，保存插件时自动写回主题；主题触发限制时改为 429 并给出可操作提示。


= 1.18.19 =
* 修复用户中心首次点击「文件管理」时 AJAX 切换后内容空白、需刷新才显示的问题：tab 内容按主题约定包进 ajax-item / ajax-pag 结构。


= 1.18.18 =
* 修复子比主题站内信返回数组不含 id 时通知被标记失败的问题，改为使用写入行 id 作为回执。


= 1.18.17 =
* 并发上传时容量预留改为按用户账本串行，修复多通道同时上传被误报“可用空间不足”。


= 1.18.16 =
* 超额用户前台常驻提示并禁用上传；新增「同步容量」增量扫描媒体库，按 ID 游标分批、带时间预算，可在后台查看进度。


= 1.18.15 =
* 用户容量表头「单独容量（MB）」与输入列一起右对齐。


= 1.18.14 =
* 用户容量卡：新增「重置容量」「同步容量」，单独容量列右对齐并去除多余表头。


= 1.18.13 =
* 文件管理页：「用户容量」改为自适应表格（使用率进度条 + 行内容量与保存），审核队列、用户容量、最近操作统一复用每页 25/50/100 与页码分页。
* 「文件管理」菜单移到「S3 同步中心」之后，后续菜单项按开发顺序追加。


= 1.18.12 =
* 文件夹卡片的操作按钮回到右上角，与文件卡片一致，悬停显形。


= 1.18.11 =
* 文件夹卡片的「⋯」补上操作菜单：重命名、申请删除。


= 1.18.10 =
* 文件夹卡片去掉与 CSS 绘制文件夹重复的黑色小图标。


= 1.18.9 =
* 前台「删除申请」列表重新排版：左侧申请编号/状态/理由/对象，右侧日期、数量与操作；对象状态用彩色胶囊，已撤回申请弱化显示。


= 1.18.8 =
* 后台执行删除第二步：未勾选时禁用预览按钮并显示提示。

= 1.18.7 =
* 修复：卡片视图左上角勾选框与卡片圆角不对齐；现在与右上角菜单同尺寸、同内边距，悬停/选中时显示。

= 1.18.6 =
* 优化：文件列表改为以图标为基准的紧凑行——勾选框与图标垂直居中，右侧两行分别显示文件名与大小/时间/类型。
* 修复：列表行内的操作菜单改为浮层展开，不再撑高整行。

= 1.18.5 =
* 修复：删除申请空状态点“回到全部文件”后，工具栏（上传/新建文件夹/搜索）被隐藏且不恢复。

= 1.18.4 =
* 修复：用户中心「文件管理」在正常页面渲染时被执行了内联脚本，导致一直停在“正在加载空间…”。
* 修复：主题 AJAX 打开文件 tab 时，脚本现在会等待挂载点出现后再初始化。
* 优化：用户中心页面预加载文件空间资源，首次点击 tab 不再空白。

= 1.18.3 =
* 修复审计 F01–F12：删除快照与租约授权、远端产物校验、预算续跑、配额原子结算、幂等键隔离、限流原子化、审核通知与投递 CAS、任务越权、目录移动与后代清理、AJAX 首屏资产、媒体库单申请筛选。


= 1.18.2 =
* 文件管理：容量类设置改为 MB 输入与显示（默认单人容量、单文件上限、每日上传量、全站预算、用户单独容量），保存时仍以字节存储。

= 1.18.1 =
* 文件管理：前台两条提示合并到同一行，左对齐与右对齐、同一基线，避免上下错位。

= 1.18.0 =
* 文件管理：新增总开关，默认关闭；关闭时不注册前台入口、REST 与媒体库列，避免非子比主题站点报错。
* 文件管理：前台入口改为主题适配器（子比用户中心 / 通用 [sufm_files] 短代码），后台显示当前主题与检测到的入口。
* 前台：账号归属提示移到空间卡片右下角。

= 1.17.2 =
* 文件管理：统一全页输入框、下拉框与文本域样式，与上传策略区的控件保持一致。
* 文件管理：把「执行删除」重排为 选择范围 → 核对范围 → 确认执行 三步，补上已选数量、步骤锁定状态与回车确认。
* 静态资源版本号改为 mtime+size，避免 nginx open_file_cache 读到旧样式。

= 1.17.1 =
* 文件管理页：修复卡片区未闭合导致的左侧错位；卡片默认改为自动流式排布，异步加载后重新测量高度，不再出现内部滚动条。
* 媒体库：新增「文件来源 / 所有者 / 虚拟目录 / 容量」列紧跟文件名列，列表可横向滚动；受管附件的批量删除改为先拦截并给出说明（此前会误报“已删除”）。
* 前台文件管理：删除申请视图隐藏上传工具条；申请明细状态改为中文（已清理等）；静态资源按修改时间+大小版本化，避免浏览器缓存旧样式。
* 设置表单修正为提交到 options.php，容量策略可正常保存。

= 1.17.0 =
* 新增「文件管理」模块（REST 命名空间 `sufm/v1`）：虚拟目录、个人容量配额、受限上传、批量改名/移动。
* 删除改为审批制：用户提交删除申请并确认引用风险，管理员逐条批准/驳回/撤销；只有勾选已批准项、预览范围并输入「删除」后才建立批次，由后台 worker 逐产物核实后清理并返还容量。
* 后台新增「文件管理」页，沿用网格卡片布局（队列、执行、用户额度、设置、审计日志五张卡，可拖动排序与调整宽高）。
* 媒体库新增「来源 / 所有者 / 虚拟目录 / 容量 / 申请状态」列与筛选，列表与网格视图共用精确结果集；直接删除受管附件会被拦截。
* 用户中心新增「文件管理」标签页，样式与交互贴合 Zibll 主题，删除申请带固定引用提示与二次确认。


= 1.16.1 =
* 修复：上传并发数大于 1 时，并行上传分支没有真正派发请求，附件会被标记为「远程结果待核对」，媒体库继续使用本地域名并提示未同步。
* 修复：S3 删除补偿任务在 cron 以空参数触发时发生类型错误，远程删除队列可能一直不执行。

= 1.16.0 =
* 新安装默认套用当前站点的网格卡片布局（图像优化、缩略图、S3、同步中心、备份管理五页的排序、宽度、高度与位置），全局卡片间隔默认 20 px；已有用户保存的布局与间隔不受影响。
* 修复卸载时 `sio_sync_operations` 表未删除的问题；开启「卸载时删除数据」后，同时清理每个用户的卡片布局 usermeta。

= 1.15.2 =
* 调整：布局修改面板里「恢复默认布局」移到最左，「布局保存」移到最右，两者之间保留「显示网格」，减少误点重置。

= 1.15.1 =
* 调整：基础设置里的「卡片间隔」改为一行两列，数值输入填满列宽，提示文案压缩为一行；窄屏自动回退单列。

= 1.15.0 =
* 基础设置页新增「卡片间隔」全局设置：左右间隔与上下间隔可分别调整（0–48 px），保存后作用于所有带卡片布局的插件页面。
* 布局修改悬浮面板移除两项间隔滑块，只保留显示网格与恢复默认布局；已保存的卡片排序、宽高不受影响。
* 间隔改为服务端输出的 CSS 变量，不再由前端逐页写入；旧版保存的每页间隔值会被忽略。

= 1.14.0 =
* 新增「基础设置」首模块，作为插件默认页：展示 PHP 运行方式、内存/上传限制、OPcache、WordPress 与数据库版本，以及 Imagick/GD、WebP/AVIF 支持情况。
* 「图像优化」移到 `sakura-tools-optimize`，顶级菜单地址 `sakura-tools` 留给基础设置；其余模块地址不变。
* 基础设置页为只读检测，不写 php.ini、不发起 AJAX；保存设置与前台行为不受影响。

= 1.13.2 =
* 修复：保存缩略图设置时同时清理子比主题的 `post_multi_thumbnail` 组，社区列表图不再停留在旧尺寸。

= 1.13.1 =
* 插件更名为「樱花工具箱」，目录与主文件改为 `sakura-tools`，后台顶级菜单同步改名。
* 后台各模块地址统一为 `sakura-tools-*`，旧地址失效，请从菜单重新进入。
* 设置数据、选项键、Cron 与过滤器名称保持不变，升级后无需重新配置。

= 1.13.0 =
* 设置页新增「按位置指定尺寸」卡片：文章缩略图、侧边栏、正文图片可以分别指定使用哪个尺寸；留空则继续跟随主题请求。
* 侧边栏请求了偏大尺寸时按场景改用指定尺寸；主题已缓存的缩略图地址会按当前场景重写，切换设置后无需手动清缓存。
* 正文图片可以单独指定尺寸；指定的尺寸不可用或会放大时，自动回退到原来的 large/medium 规则。

= 1.12.2 =
* 修复卡片高度保存后刷新回退的问题：显式调整过的高度写入独立的 h 字段，未调整的卡片继续跟随内容高度；旧 minH 仍忽略。

= 1.12.1 =
* 插件作者更新为「星晴」。
* 修复卡片高度只能增大、无法回缩到内容高度的问题：旧布局保存的 minH 不再作为最小高度。
* 远端删除补偿改为独立 Worker，停用旧后台上传队列与相关 Cron。
* 新增远端操作准入账本，并发 PUT/DELETE 冲突改为显式阻断并保留人工核对/释放入口。
* 新装站点不再创建历史上传队列表；卸载时清理旧表与迁移标记，避免遗留。

= 1.12.0 =
* S3 上传改为在本次上传请求内完成：文件上传后立即校验，成功才标记同步，失败保留本地并在媒体库显示失败原因
* 移除「上传时机」同步/后台模式与后台首传队列；历史待传附件改为人工补传，不再在后台自动上传
* 设置页新增「云端传输并发数」（1-4，默认 1）；HTTP 传输环境不支持并行时自动降级并在页面说明
* 同步中心：动作队列记录目标指纹与文件版本，原子领取 + 租约恢复，避免配置切换后写错存储桶
* 失败附件保持本地地址，正文与缩略图不再指向尚未同步的云端地址
* 历史附件迁移改为逐批同步上传并校验，始终保留本地文件

= 1.11.0 =
* 布局编辑：拖动时显示落位预览，超出视口自动滚动，卡片落位带滑动动画
* 拉伸宽度/高度改为实时预览
= 1.10.9 =
* 修复自由画布在拉动浏览器窗口后不重算的问题：窗口变宽/变窄时卡片宽度、位置与高度会重新计算，不再出现“越拉越小、右侧留一大片空白”。

= 1.10.8 =
* 卡片改为自由画布：可放到任意列的空白处（例如短卡下方的留白），不再被迫与旁边卡片同高。

= 1.10.7 =
* 移动端修正：下拉框与输入框不再出现文字被裁切（统一 40px 最小高度、1.5 行高、右侧留出箭头空间）。
* 移动端修正：尺寸表、设置表改为“逐行卡片”布局，表头文字变成每行的小标签，不再出现整列竖排或表头与内容分离。
* 移动端修正：页面大标题（如「樱花工具箱」）行高与间距调整，标题与说明文字不再挤在一起。

= 1.10.6 =
* 缩略图优化页合并为一套 12 列布局：总开关、生效位置、尺寸规格、高级设置、连接测试、14 天统计、回滚、缓存清理共 8 张卡片可在同一块画布内拖动排序与拉伸，不再分成左右两套网格。
* 表单结构保持不变：卡片虽然被移动到画布上，提交时仍归属「缩略图优化」设置表单，保存与连接测试都正常。

= 1.10.5 =
* 修复布局引擎被改动后出现的错位：恢复「列跨度 + 最小高度」模型，卡片宽度与实际列数一致，向下拉伸会真正变高。
* 移动端自动忽略桌面保存的列宽与高度，按单列重新排列，避免卡片出现大片空白或错位。
* 窗口宽度跨过 1100px 时自动回流（桌面↔手机无需刷新）。

= 1.10.4 =
* 布局工具条改为靠右下角悬浮，不再居中遮挡页面中部；展开时向左延伸。

= 1.10.3 =
* 布局辅助线改为编辑态常显：进入「布局修改」后显示，退出即隐藏，不再计时消失。
* 拉伸卡片时的虚线边框改为停止操作 5 秒后自动消失，并修复退出编辑后虚线框残留的问题。
* 布局工具条改为底部横向短条（间隔滑杆、显示网格、恢复默认并排），减少对页面内容的遮挡。

= 1.10.2 =
* 布局按钮改为「布局修改 / 布局保存」两段式：只有进入编辑模式后才能拖动、拉伸卡片。
* 拉伸时出现的对齐网格会在停止操作 5 秒后自动隐藏，方便回看整体效果。
* 网格默认开启；关闭后编辑时不再显示辅助线。

= 1.10.1 =
* 新增：S3 同步中心也支持卡片拖动排序、拉伸与间隔设置。
* 新增：「布局」面板里的「显示网格」开关，以及拖动高度时的对齐辅助线——松手前会吸附到同一行相邻卡片的底边。
* 修复：保存布局时改用实际设置的卡片高度，不再把行高重复写入。

= 1.10.0 =
* 新增：图像优化、缩略图优化、备份管理、S3 存储页的卡片可以自由拖动排序、左右/上下拉伸，内容随尺寸自适应。
* 新增：右下角「布局」按钮可统一设置卡片之间的左右、上下间隔（0–48 像素），并支持一键恢复默认布局。
* 调整：布局按登录用户分别保存；没有 JavaScript 或窄屏（≤1100 像素）时自动保持原来的堆叠布局。

= 1.9.9 =
* 调整：上传方式不再放在「图像优化」页，改为「S3 存储」页自己的「上传时机」卡片；仍是站点级设置，不跟着某个存储桶保存。
* 调整：「S3 存储」页重新排版：上传时机与上传行为并排，连接设置整行显示，底部并排放连接测试与历史附件迁移，减少空白。
* 调整：上传方式用下拉框选择，默认「同步上传」，圆点开关不再让文字上下跳动。
* 兼容：升级后继续使用原来的选择；没有设置过则按同步上传处理。

= 1.9.8 =
* 调整：上传方式从「S3 存储」页移到「图像优化 → 云存储上传」，作为站点级设置，不再保存到某个存储桶的配置里。
* 调整：上传方式改为下拉框，默认「同步上传」，避免圆点开关切换时文字跳动。
* 兼容：如果之前设置过异步上传，升级后仍保留你的选择；没有设置过则按同步上传处理。

= 1.9.7 =
* 改进：图片上传完成后，在返回页面的同一请求里立刻开始上传（先让页面返回，再在后台处理），不再等下一次 WP-Cron 定时任务。以前要等几十秒，现在通常几秒内完成。
* 兼容：不支持 fastcgi_finish_request 的环境（命令行、部分 PHP 运行方式）仍按原来的定时任务处理；可用过滤器 sakura_imgopt_upload_after_response 关闭这一行为。
* 说明：单张图片实际上传约 1 秒；此前的等待主要来自定时任务的间隔，而不是网速。

= 1.9.6 =
* 修正：1.9.1–1.9.5 的发布包里有 2 个文件权限是 600（只有打包用户可读），部分主机安装后网站进程读不到，图片处理会失败并提示 Failed opening required。本版发布包已统一为 644/755。
* 如果你已经安装旧包并遇到图片不压缩：升级后重新在媒体库对受影响的图片执行一次「批量优化」即可，原图备份不会重复创建。

= 1.9.5 =
* 改进：自动把文章里的本地图片地址替换为云端地址时，会在文章的附加数据里保存「云端地址 → 本地地址」对照表；即使站点关闭了文章修订，也能撤销这次替换。
* 新增命令：wp sakura-s3 restore-content <文章ID> 撤销单篇文章的替换（加 --dry-run 只预览）；wp sakura-s3 restore-content --all 处理所有保存过对照表的文章。
* 说明：每篇文章最多保留 100 条对照；只覆盖文章正文。

= 1.9.4 =
* 新增：异步上传期间保存或发布文章时，文章里写入的本地图片地址会在云端上传完成后自动替换为云端地址（已发布文章同样处理，原内容保留在修订版本里）。
* 替换只发生在真正包含该图片地址的文章中，可使用过滤器 sakura_imgopt_rewrite_content_urls 关闭。
* 说明：页面构建器把图片地址存在自己的数据表或元数据里时，不在自动替换范围内。

= 1.9.3 =
* 新增：云存储页新增「上传方式」，可选择后台异步上传（默认）或同步上传；同步上传会在保存图片时当场传完，媒体库和同步中心立刻显示云端状态，图片很大时会明显变慢，失败仍会自动转后台重试。

= 1.9.2 =
* 修复：新上传的图片在上传完成前就显示成云存储域名，几十秒内打不开；现在上传期间先用本地地址显示，确认云端已有文件后再切换。
* 修复：刚上传完成的文件在「同步中心」里仍显示「仅在本地」，需要手动扫描；现在上传成功就写入核对记录并更新统计。

= 1.9.1 =
* 修复：同步中心按「最近」排序时分页重复（第二页仍是第一页内容）。
* 修复：上传后校验失败会误删刚上传的文件；现在保留文件并写明原因。
* 修复：手动同步历史附件可能删掉本地原图，与页面说明不一致；现在始终保留。
* 修复：还原原图后，旧格式的云端文件在替换完成前就被删除；现在等替换完成后再清理。
* 修复：切换云存储配置后进行中的任务可能传到新存储；现在按任务创建时的配置执行。
* 修复：原图备份失败时仍可能继续压缩；现在会中止并提示原因。
* 修复：卸载时残留的云存储配置与测试记录。
* 优化：保存设置不再默认重启 OPcache；统计改为每请求合并写入；大图可按体积或分辨率直接放行。
* 优化：后台文案改为更易懂的说法，并修正与实际行为不一致的说明。
* 安全：导出表格公式中和、告警地址私网拦截、凭据字符清洗、去掉模板 extract。

= 1.9.0 =
* 新增「压缩后不得变大」保护（M-08）：处理后体积未减小时保留原图，并在统计里记为「跳过」。
* Imagick 与 GD 均先写临时文件、比较体积后再原子替换；GD 路径不再直接覆盖原文件。
* 统计新增「跳过」计数与状态展示；上传与批量处理遇到跳过时会清理本次多余的原图备份。
* 可用 filter `sakura_imgopt_keep_original_when_larger` 关闭该保护（默认 true）。

= 1.8.4 =
* 相邻卡片之间统一 24px 间距，S3 页的“连接测试/历史迁移/上传队列”独立卡片不再贴边。
* 底部保存按钮与卡片之间增加 22px 留白，不再贴着卡片下边框。
* 无数据与接口变更。

= 1.8.3 =
* hero 与首个卡片之间固定 28px 间距，卡片边框不再贴在一起。
* 瀑布列间距、宽卡与下一行间距统一到 24px。
* 无数据与接口变更。

= 1.8.2 =
* 设置卡片改为瀑布式（masonry）布局：每张卡按实际高度放入较短的列，不再被网格行拉出大片空白。
* 宽卡（缩略图尺寸表）仍独占一行；窄屏自动回到单列。
* 无数据与接口变更。

= 1.8.1 =
* 设置卡片最多两列，列间距 20px，窄屏切换为单列；不再把三张卡片硬挤在一行。
* 两列表单的 label 列收窄到 150px，输入框保持 100% 宽度，减少折行。
* 统一后台提示条样式，保存提示不再像一条突兀的空白带。
* 无数据与接口变更。

= 1.8.0 =
* 样式与脚本合并为单一 assets/admin.css、assets/admin.js，删除独立的 s3-sync-center 资源。
* 后台页面统一使用 .sakura-sync-app 框架：品牌栏、hero、卡片、按钮、表单表格共用一套组件。
* 图像优化、缩略图优化、优化统计、备份管理、S3 存储全部改为卡片式紧凑布局；媒体批量处理页同步。
* S3 存储页重排为「服务商 / 连接与上传策略 / 连接测试 / 历史迁移 / 队列吞吐 / 监控告警」卡片。
* 无数据、接口或行为变更。

= 1.7.1 =
* 后台页头统一：S 标 +「樱花工具箱 / 页面名」+ 状态 pill + 快捷入口，与 S3 同步中心一致。
* 各页主操作前置到 hero 动作区：保存设置、查看统计、重置统计、打开同步中心等。
* 新增共享 partial `includes/views/page-topbar.php`；窄屏自动隐藏路径文字并换行。
* 仅界面调整，无数据/行为变更。

= 1.7.0 =
* 统一模块命名：后台页面模块改为 `*_Module` / `*_Page`（如 `Image_Optimizer_Module`、`S3_Storage_Module`、`Sync_Center_Module`），旧类名保留别名。
* 后台子菜单顺序调整为「图像优化 → 缩略图优化 → 优化统计 → 备份管理 → S3 存储 → S3 同步中心」。
* 所有插件后台页面统一最大内容宽度 1600px 并居中；同步中心不再独占全宽，六个页面的内容区左右边界一致。
* 统一背景选择器与页面容器间距，带鱼屏下内容不再被拉满。
* 无数据/行为变更；旧类名兼容，升级无需迁移。

= 1.6.9 =
* 文件对账表新增多选：表头全选仅当前页、最多 10 项；选中后出现「批量上传 / 批量下载 / 批量删除远端」工具条。
* 批量操作按行状态过滤（仅待上传/失败可上传，仅云端可下载，有云端副本可删除）；不满足条件的行由服务端跳过并返回数量。
* 批量上传包含云端已存在对象时提示覆盖；服务端仍强制 `confirm=REPLACE`，否则 409 且不入队。
* 删除确认从「勾选」升级为输入 `DELETE`（大小写敏感）；批量与单条共用，批量弹窗显示对象数量。
* 后端批量删除要求 `confirm=DELETE`，逐 key 校验，全部不可执行时 400 且不入队；新增 `action_batch_*` 事件。
* 测试 179/507；PHPStan 0。

= 1.6.8 =
* 对账加入内容校验：单段 ETag 与本地 MD5 比较，两者不一致标记「校验差异」并调整计数，匹配后自动恢复。
* 上传成功时把本地 MD5 写入索引，避免后台重复读取；扫描检测到大小/时间/ETag 变化会重置校验状态。
* 新增后台校验任务（每 5 分钟 20 秒，按游标续跑、GET_LOCK 防并发）与 `wp sakura-sync verify` 命令（--reset/--once/--seconds/--limit）。
* 多段上传、SSE-KMS 等 ETag 不可比的对象标记「不可校验」，不误报；同尺寸篡改可被检出。
* 表结构 1.5.0（只加列）；测试 179/507；PHPStan 0。

= 1.6.7 =
* 「最近 N 天」筛选改走 `recent_at` 派生列与 `(profile_id, recent_at, object_key_hash)` 索引，1M 行从秒级/全表扫描降到 0.3–2.1 ms。
* days 过滤启用时按最近修改排序（默认视图顺序不变），复合游标翻页；「最近变化」改为本地/云端 mtime 的较大值。
* 未回填完成前自动回退旧 OR 条件；后台事件与 CLI rebuild 分批回填，卸载清理 ready option/cron。
* 快速搜索端点与仓库查询保持同一排序与游标规则。
* 测试 176/495；PHPStan 0。

= 1.6.6 =
* 搜索、筛选与翻页改走 SHORTINIT 快速端点：端到端从约 137 ms 降到约 6 ms；失败自动回退 admin-ajax。
* 新增 30 分钟签名令牌（用户 + profile + 过期时间），端点二次校验 administrator 角色，只读元数据。
* 新增令牌单测；筛选结果、游标与仓库查询逐项一致。
* 测试 174/487；PHPStan 0。

= 1.6.5 =
* 新增 Prometheus 只读指标端点（令牌 + Bearer），暴露积压、失败率、耗时、死信、最后错误与最后告警时间。
* 新增 Webhook 告警：死信/失败率阈值、最少尝试数、冷却期；设置页可保存、测试、轮换令牌。
* 核对并补齐 Uninstaller：清理指标、告警、Prometheus 令牌 option、告警 transient 与 cron。
* 测试 168/473；PHPStan 0。

= 1.6.4 =
* 新增上传队列可观测性：积压、今日成功/失败率、平均/最慢耗时、今日上传量、最后错误时间与消息。
* S3 设置页新增“上传队列与吞吐”卡片，30 秒自动刷新，也可手动刷新；新增 `wp sakura-sync stats`。
* Worker 每轮汇总指标（每个文件一次计时，每轮一次 option 写），按 UTC 保留 14 天日桶。
* 测试 156/441；PHPStan 0。

= 1.6.3 =
* 历史迁移改为“只扫描、不入请求内上传”：500 个附件/页、20 秒预算、游标持久化，可中断续跑；100k 附件从约 3.3 万次请求降到数百次。
* 新增 `wp sakura-s3 migrate`（`--batch-size/--seconds/--reset/--limit/--once/--dry-run`），支持系统 cron 分批。
* 迁移与上传队列共用 `Upload_Jobs`，按附件+Key 去重；迁移请求不再受 S3 网络/凭据错误影响。
* 迁移加 `GET_LOCK` 互斥，并发请求返回 409；页面显示阶段/进度并支持重新开始。
* 测试 152/416；PHPStan 0。

= 1.6.2 =
* 上传后做一致性校验：单段上传前计算 MD5 并随请求发送 `Content-MD5`，上传后 `HeadObject` 回读 `ContentLength` 与 `ETag`，分片按 part MD5 计算期望 ETag；不一致时删除坏对象并标记失败。
* 校验失败进入上传队列退避，本地文件保留；Worker 最多重试 8 次后进入死信。
* 新增 `sakura_imgopt_verify_upload`、`sakura_imgopt_upload_content_md5` 兼容开关，适配 ETag 非 MD5 或拒绝 Content-MD5 的存储。
* 新增 `UploadVerifyTest` 与截断故障注入测试；MinIO 验证 2 KB/17 MB 两条上传路径。

= 1.6.1 =
* 远端删除失败不再静默丢失：新增 `sio_s3_deletes` 持久删除队列，删除前先记录对象 Key，失败由后台 Worker 按 60×2^n 退避补删，第 8 次进入死信。
* 删除附件时先清上传队列再入删除队列；上传成功会清除同名待删任务，避免误删重新上传的对象。
* S3 设置页“重试”同时重置删除死信；后台 notice 提示可能继续计费的孤儿对象；卸载清理删除队列表与 schema。
* 新增 `DeleteJobsTest`；MinIO 验证成功/失败/死信/重排四条路径。

= 1.6.0 =
* 首传改为持久队列：新增 `sio_s3_uploads` 表与每分钟 Worker，请求不再同步等待 S3。
* 附件上传、元数据更新统一入队；删除附件时同步清理队列行。
* 失败按 60×2^n 退避，最多 8 次后停止重试并标记「已停止重试」；看门狗每 5 分钟回收超过 10 分钟仍未入队的 pending 附件。
* 管理端「重试」同时重置队列死信；卸载清理队列表、schema 与定时任务。
* 新增 `UploadJobsTest`；MinIO 端到端验证入队→上传→对象存在、失败退避与第 8 次死信、看门狗回收。

= 1.5.0 =
* 动作队列：>50 MB 上传与批量动作后台执行，指数退避，8 次后 dead。
* 扫描失败自动重试 3 次（10 s / 20 s），fatal 错误直接失败。
* “刷新差异”改为轻量重算（不列举 S3），并新增每日自动重算。
* 索引回滚开关：停用后回退到 30 分钟兼容快照，表与数据保留。
* 健康卡新增本轮指标与队列死信；管理员 notice 提示失败扫描/死信/配置错误。
* 响应式与首屏复核；PHPUnit 135、PHPStan 0。

= 1.4.1 =
* 同步中心搜索：`object_key` 顺序 keyset + `profile_key(profile_id, object_key)` 索引，百万行首页 0.16–0.65 ms、深层 0.47–1.09 ms、无命中 0.16–0.20 ms；索引体积 649 MB/100 万。
* 附件双写：新增 `Attachment_Indexer`，媒体上传/元数据更新/删除即时写索引并增量更新计数；`ignored`、`failed` 行保持人工状态。
* 本机 MinIO 百万级验证：播种 100 万对象 301.6 s，全量扫描 90.16 s / 峰值 107.8 MB / 0 重复；真实 bucket 未再调用。
* 修复：二进制 sha256 经 `$wpdb->prepare('%s')` 被 MariaDB 判为非法数据（改 `UNHEX(%s)`）；删除附件时关联数组值被误当 key 导致索引行残留；`ignored` 本地-only 行随附件删除清理。

= 1.4.0 =
* 同步中心：持久差异索引（`sio_sync_index`/`scans`/`counts`/`jobs`/`events`）取代 30 分钟 transient 快照；keyset 分页、状态计数表、云对象类型预聚合，百万行页面查询 <1 ms。
* 扫描：本地确定性 DFS + 相对路径断点续跑；云端 ListObjectsV2 1000/页续传；20–30 s tick 时间预算、GET_LOCK 互斥、取消/继续、失败保留游标；前端 400 ms 轮询与运行中自动续接。
* 性能：100 万云端对象建索引 74.6 s（mock）、峰值 107.8 MB；1M 行 keyset 首页 0.5–0.95 ms，扫描结束自动 `ANALYZE TABLE` 避免旧统计全表扫。
* 运维：新增 `wp sakura-sync scan|status|rebuild|cleanup|purge`；每日清理事件/扫描/死任务；卸载清理索引表与 schema option。
* 迁移：Schema 1.1.0（kind 列 + `profile_kind` 索引、扫描类型计数），旧行分批回填 kind；`Inventory` 快照下线（仅保留 `format_bytes`）。
* 取舍：1M 索引约 607 MB（含 kind 索引）；搜索限定前缀（1M 命中约 1.3 s、未命中约 2.6 s）；对账首版只比 size。

= 1.3.3 =
* 新增「S3 同步中心」：本地/云端对账、任务与队列健康、体积分布、六类差异筛选、同步日志；支持行内上传/下载/删除远端，删除需二次确认。
* i18n：后台设置/备份/统计/OSS/S3/同步中心全部可见文案进入 `sakura-imgopt` 文本域；JS 动态文案改为 PHP 注入；POT 增至 651 条。
* P2 质量项：统一延迟上限、设置保存提示、PHPStan/CI、资源按页加载、OSS 通用选择器、POT、临时文件清理与权限规范。
* 十、代码质量：页面渲染拆分为 view、补充 PHPUnit 测试、readme/LICENSE。
* 架构：入口拆为 `src/Plugin` 组合根与 `src/Activation`/`src/Deactivation`/`Hook_Loader`，后台模块按需加载；7 个模块统一 `Module` 接口与集中钩子注册。
* 架构：Phase 2B 引入 `Backup_Repository`、`Engine_Manager`、`Restore_Service`，`Image_Processor`/`Media_Library` 改为构造注入；PHPUnit 增至 27 tests / 51 assertions。
* 架构：Phase 2C 增加统计/文件系统仓储、OSS `Url_Rewriter`、后台 `Assets`、S3 `Client_Factory`；PHPUnit 增至 38 tests / 77 assertions。
* 架构：Phase 2D 备份/统计视图改为仓储注入，OSS `Html_Filter` 与 S3 `Upload_Queue` 拆出；PHPUnit 增至 40 tests / 79 assertions。
* 架构：Phase 2E S3 `Object_Store`/`Migration_Service` 拆出，上传/删除/历史迁移服务化；PHPUnit 增至 45 tests / 107 assertions。
* 架构：Phase 2F 增加本地 S3 兼容端点集成测试（默认跳过，设置 `SAKURA_S3_ENDPOINT` 后运行）。
* 架构：Phase 2G 修复第三方 S3 校验和兼容、批量删除回退与错误码映射；集成测试默认跳过，设置 `SAKURA_S3_ENDPOINT` 后运行。
* 架构：深度优化拆分 `Image_Processor` 为 `File_Processor`/`Upload_Handler`，新增 S3 `Options`/`Storage_Adapter` 实例；PHPUnit 增至 60 tests / 149 assertions。
* 架构：移除 `S3_Storage` 静态门面，改由 `S3\Storage` 实例模块与 `Storage_Adapter` 驱动；`Upload_Handler` 再拆为重命名/备份/元数据服务；PHPUnit 增至 63 tests / 151 assertions。
* 架构：`S3_Settings` 457→102 行，拆为页面渲染、保存、连接测试、历史迁移四个服务；`Upload_URL` 接管媒体 URL 生命周期；PHPUnit 增至 64 tests / 155 assertions。
* WordPress 规范：卸载改为默认保留数据 + 显式「卸载时删除数据」开关；媒体批量/AJAX 改为 `upload_files` + 逐附件 `edit_post`；激活/停用只做默认值、版本与 cron；S3 运行时接管媒体 URL；新增 `src/Uninstaller`、POT 258 条、CI PHP 8.4。
* 依赖：AWS SDK 经 Strauss 前缀化为 `Sakura\Vendor\Aws`（`vendor/s3/vendor-prefixed`），移除未前缀 SDK，避免与其他插件冲突。
* UI：后台蓝/白/淡粉二次元主题，云朵/樱花背景，统一按钮四态、圆角阴影、响应式表单与进度页；主设置页等宽双列自适应卡片、全插件统一圆形白点复选框；业务逻辑不变。
* i18n/设置边界：新增 `Settings_Repository` 统一 option 读取与归一；进度页 JS、媒体库/日志/OSS 标签全部进入翻译函数；POT 349 条；PHPUnit 74 tests / 184 assertions。
* S3 可靠性：附件级同步状态与对象 Key 记录；删除只删本插件记录的 Key；失败任务持久化、5 分钟 cron/指数退避/手动重试；媒体库状态列与后台同步面板；16 MB 及以上 multipart。PHPUnit 99 tests / 265 assertions；连接本地 S3Proxy 时 273 assertions。
* S3 配置守卫：Provider/Bucket/Key/Secret/Endpoint 统一校验；启用但配置不完整时阻止图片上传、CLI、历史迁移与手动重试，并在设置页说明缺失项；`Requires PHP` 修正为 8.2。
* 安全：S3 Key/Secret 使用 Sodium secretbox/AES-256-GCM 加密存入数据库，页面与源码不回显；支持 `SAKURA_TOOLS_CREDENTIAL_KEY`，旧明文自动迁移。
* S3：支持保存多个服务商配置；服务商下拉框标注「已保存/未保存」，点击已保存卡片即可切换活动配置，无需重填 Key/Secret；旧单配置自动迁移为 profile。
* S3：profile 改为独立 ID，同一服务商可保存多个账户；对象访问权限、本地文件夹、媒体 URL 前缀随配置保存/切换；支持配置名称、另存为新配置、删除已保存配置；活动配置不可删除，切换不改全局策略或文章历史 URL。
* S3 同步中心：后台页面展示本地/云端对账、差异筛选、任务中心、连接健康、体积分布与日志；支持只读扫描、CSV 导出、行内上传/下载/删除远端（删除需二次确认）。
* S3 同步中心：P1 改为 `sio_sync_index/scans/counts/jobs/events` 索引表与 keyset 分页，扫描分批写库、可断点续跑；P2 增加 WP-Cron Scan Worker，页面关闭后仍可继续。

= 1.3.2 =
* 备份失败硬失败、卸载清理、GD AVIF 修复、批量还原域名守卫、动图/ICC/统计/资源护栏等修复。

= 1.3.1 =
* 初始公开版本。
