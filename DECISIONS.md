# 文件管理模块：实现决定（DECISIONS）

方案来源：`/root/WordPress/user-files-design/`（README、docs/PROPOSAL.md、docs/SECURITY-DELETE.md、plugin-blueprint/DECISIONS.md、STRUCTURE.md）。本文件只记录落地时真正需要拍板、且以后改代码时必须遵守的决定；与方案冲突时以方案为准，新增项在文末单列。

## 1. 数据归属（对应方案 §12 决策 1）

- 文件身份 = WordPress attachment；虚拟目录只改变展示归属，不改磁盘路径、不改 URL。
- 只纳管 `sufm_files` 中有行、且 `source='userfiles'` 的附件；不按 `post_author` 收编任何已有媒体。
- 表全部 `{$wpdb->prefix}sufm_*`，schema 版本存 `sufm_schema_version`，`dbDelta` 在 `admin_init` / 激活 / WP-CLI 时幂等安装。

## 2. 权限（决策 2）

| 能力 | 谁 | 能做什么 |
| --- | --- | --- |
| `sufm_manage_own_files` | subscriber 及以上 | 上传、查看自己的空间、未锁对象改名/移动、新建目录 |
| `sufm_request_delete_own_files` | subscriber 及以上 | 提交/查看/撤回本人 `pending_review` 申请 |
| `sufm_admin_review_deletions` | administrator | 批准、驳回、撤销批准、标记需核实 |
| `sufm_admin_delete_files` + 每附件 `delete_post` | administrator | 选已批准项、预览范围、二次确认建批次、停止批次 |

写接口一律 `capability → nonce（cookie + X-WP-Nonce）→ 输入白名单`；`owner` 只由服务端 `get_current_user_id()` 决定，请求体里的 owner/approved/job/path 字段直接忽略。

## 3. 失败语义（决策 3、4）

- 上传：先 `Quota::reserve`，失败即中止；`wp_handle_upload` 或建 attachment 失败 → 删临时文件/附件并 `release` 预留，不留下半条记录。
- 删除：逐产物执行，单产物失败记 item `failed`，请求收口 `partial_failed`；已删产物不可撤销，界面明示"已完成部分无法撤回"。
- 通知投递失败只写 outbox 重试，不回滚业务状态。

## 4. 幂等（决策 5）

- 上传幂等键 `upload:{key}`；删除申请幂等键 `idempotency_key`；批次 job `dedupe_key = batch:{id}:{hash 前 24}`。
- 重复幂等键返回原结果，不重复扣额、不重复建 job。

## 5. 任务上下文与领取（决策 6）

- `sufm_jobs.context_json` 存 `{batch_id, requests:[{id,revision}]}`；worker 每次执行/重试前重读 `approval_revision` 与状态，不信任"当前配置"。
- 领取用 `UPDATE … WHERE id=%d AND state='queued'` 并要求影响 1 行；`fence+1`、`lease_until=+180s`；watchdog 把过期 `running` 改回 `queued`；`attempts` 达上限转 `dead`。

## 6. 删除证据与返额（决策 7）

- 返额只在"逐产物核实清理完成"后发生：路径必须 `realpath` 在 `wp_upload_dir()['basedir']` 内、非符号链接；`wp_delete_file` 后 `clearstatcache()` 再断言不存在；附件必须 `get_post()` 为空；随后事务内 `used_bytes -= charged_bytes` 并 `lifecycle='purged'`。
- 目录申请先删子树文件，`recursive_files()` 为空才置目录 `lifecycle='deleted'`（`active_slot=id` 释放唯一键）。
- 批准、撤销、停止都不返额；`stopped` 批次保留未完成项，可重新走审核。

## 7. 开关与默认值（决策 11 / §0.5）

- 唯一默认值来源：`Nboke\UserFiles\Settings::DEFAULTS`（通过 `Settings::defaults()` 暴露）；激活、界面、REST `upload-policy` 都读它。
- 删除策略没有开关：固定"申请 → 审核 → 确认执行"，无自动批准、无到期删除。
- 前台零成本：`init` 上只注册轻量钩子；模板与 `userfiles.css/js` 只在用户中心 `files` tab 或其实时请求里加载。

## 8. 卸载（决策 9）

- 默认保留：`sufm_*` 表、`sufm_settings`、用户额度 usermeta、映射与未完成申请都不删，便于原样重装。
- 只有站点管理员显式勾选"卸载时删除数据"（`sakura_imgopt_delete_data_on_uninstall=1`）才删设置、额度与 `sufm_*` 表；实体文件仍只会由审批后的执行器删除。

## 9. 通知（决策 10）

- outbox 表 `sufm_notifications`，按 `r{request}:{status}` 去重；投递优先 Zibll `ZibMsg::add()`，没有主题适配器时标记 `no_adapter`，不阻塞业务。
- 通知只写"申请编号 + 状态 + 条数"，不含路径、对象 key、凭证。

## 10. 实现增补（相对方案新增，需评审）

- `sufm_delete_batches`：方案把"批次"作为 REST 资源（`/admin/delete-batches`）和审批代次的载体，但没有给表；这里落成独立表，存 `snapshot_hash`、`approval_revision`、计数与停批时间。
- `sufm_rate_limits`：方案 §11.2 要求"日受理台账与频率计数持久化"，用 `(owner_id,bucket,period_key)` 唯一键 + `count/bytes` 实现，替代瞬态。
- 引用提示版本：`Settings::NOTICE_VERSION = '2026-10-31'`，申请必须带当前版本号并显式确认，服务端记录 `reference_notice_version/reference_confirmed_at`。

## 11. 运行时验证（决策 12）

- 已实测（2026-11-01）：上传 → 申请 → 批准 → 预览 → 二次确认 → `wp sufm tick` → 附件与磁盘文件消失、`used_bytes` 归零、批次 `completed`；泄漏的预留由 lease 回收。
- 单测覆盖纯函数（设置清洗、表名、状态机常量）；浏览器/接口复核见交付说明。

## 增补：功能开关与主题入口（2026-11-01）

`enabled`（`sufm_settings[enabled]`，默认 false）是模块总开关，唯一默认值在 `Settings::DEFAULTS`。

| 开关 | 默认 | 读取方 | 控制范围 | 关闭后的表现 |
|---|---|---|---|---|
| `enabled` | false | `Module::register_hooks`、`Admin\Page`、`Job`/CLI tick | REST `sufm/v1`、媒体库四列与三道筛选、Zibll 用户中心标签、`[sufm_files]` 短代码、`sufm_tick` 定时任务 | 后台仍显示「文件管理」页与开关（便于重新启用）；前台入口、REST、媒体库列、定时任务全部不注册；tick 直接返回 |

改动开关时必须：保存后 `Module::schedule()` 会在下一次 `init` 取消或重建 `sufm_tick`；`Page::flush_entry_cache()` 清掉短代码页缓存。关闭不会删除数据、不会解锁进行中的申请，重新启用后队列继续。

### 主题入口扩展

入口不写死在模块里，统一走 `Nboke\UserFiles\Frontend\Provider`：

- `Providers\Zibll`：`get_stylesheet()/get_template()` 为 `zibll` 且存在 `zib_get_user_center_url()` 时启用；负责 `user_ctnter_main_tabs_array`、`zib_user_center_page_sidebar_button_2_args`、`main_user_tab_content_files`。
- `Providers\Generic`：任何主题的兜底入口，注册 `[sufm_files]` 短代码；资产只在包含短代码的单页加载；入口 URL 通过缓存查找首个包含短代码的已发布页面。
- 新主题 = 新增一个实现 `Provider` 的类，并在 `Entry::providers()` 里排在 Generic 之前；也可用 `sufm_frontend_provider` 过滤器整体替换。

`Entry::status()` 把当前主题名、样式目录、选中的入口与入口 URL 交给后台页渲染；关闭状态下前台不会实例化任何主题函数。

## 2026-11-02 · 媒体库既有附件纳入账本（方案 B）

- 同步容量（Sync_Service）会把媒体库中尚未登记的附件按 `post_author` 登记为 `sufm_files.source = media`，计入容量与文件数；已登记文件只核对字节数。
- 纳入后删除仍需「申请 → 审核 → 执行」：执行器对 `source = media` 走无快照分支（以附件自身为冻结范围，删除后核验、退额），媒体库直接删除被 `pre_delete_attachment` 拦截。
- 无作者（`post_author = 0`）的附件不纳入，因为没有可归属的额度。
- 来源列与筛选区分「用户文件空间」和「媒体库导入」，避免把两种来源混为一谈。

## 媒体库虚拟文件夹（1.18.23，FileBird 融合）

- 数据源：媒体库左侧树直接读 `sufm_folders` / `sufm_files`，与前台用户中心同一套记录；一个文件只属于一个文件夹，按 `owner_id` 隔离。
- 接管：`admin_body_class` 加 `sufm-tree-active`，CSS 隐藏 `#filebird-root` 等第三方面板；可用过滤器 `sufm_media_folders_takeover` 关闭。
- 操作：点击树节点只做一次导航（`sufm_owner` + `sufm_folder` 同时写入）；新建/重命名走 `POST/PATCH /sufm/v1/admin/folders`；拖拽移动走 `POST /sufm/v1/admin/folders/move`，只接受当前用户可管理的文件。
- 不做：不迁移 FileBird 的 `fbv` 数据；不复制更多级文件夹到媒体库之外；不改变前台已上传文件的归属。

## 媒体库文件夹复刻（1.18.25）

- `sufm_folders` 增加 `sort_order`（schema 1.2.0），同级按 10 递增，支持拖拽排序；文件夹可拖到另一个文件夹内实现嵌套。
- 目录操作：新建/重命名/删除（内容上浮到上一层，文件不删）/重排/改父级，全部走 `admin/folders*`，桥接前台同一套记录。
- 面板交互对齐 FileBird：展开折叠（localStorage 记忆）、右键与「⋯」菜单、搜索高亮并自动展开命中分支、路径条与清除筛选、文件夹拖拽（前/内/后三区）、媒体列表多选拖拽。
- 不做：不迁移 `fbv` 数据；删除文件夹不删除文件；不接管媒体上传弹窗的文件夹选择。

## 媒体库上传归属（1.18.26–1.18.27）

- 全页上传器（`media-new.php`）与编辑器媒体弹窗都提供「上传到文件夹」，随 multipart 发送 `sufm_folder` / `sufm_owner`。
- 服务端 `add_attachment` 校验：文件夹属于当前用户、附件作者等于当前用户；未登记附件先 `Sync_Service::adopt()` 再 `Files::move()`，并按字节调整 `sufm_usage`。
- FileBird 迁移走 `wp sufm import-filebird`（`--dry-run` 可预演）；只导入文件夹与归类，不复制 FileBird 设置；非法字符替换为 `·`。
- 媒体库树计数显示子树合计，前端仍按直接归属显示文件列表。

## 统一媒体库目录（1.18.28，2026-11-02）

以下决定覆盖本文件早期同主题的实现描述，方案依据为 `/root/WordPress/media-library-design/PLAN.md`。

1. **数据归属**：继续使用 `sufm_folders/files/usage`；用户根是本站成员的投影节点 `owner:{id}`，`folder_id=0`，不插入实体目录或消耗目录数。空用户也显示，注销遗留根不可接收转交。
2. **权限**：目录管理 `sufm_manage_media_folders`，跨用户/首次纳管另需 `sufm_transfer_media_files`；每附件重验 `edit_post`。REST 仍要求 cookie/nonce，普通用户不能指定其它 owner。新增能力通过版本化管理员授权升级，避免依赖重新激活。
3. **失败处理**：同用户批量移动、转交及纳管整批成功或整批回滚；不截断超限输入，不把混批失败伪装成部分成功。复杂远程/优化器附件无法证明纳管字节时拒绝并保留原附件。
4. **原子性**：按固定顺序锁账本、文件及相关目录，重验源与目标祖先冻结、版本、目标空间（含预留）及文件数；事务内同步 files owner/folder/version、post_author 与两侧已用空间。通知失败不回滚提交结果。
5. **幂等**：跨用户和首次纳管使用持久 `sufm_media_operations`，将 actor、输入 hash、操作键和结果绑定；重放返回原结果，键冲突拒绝，不重复扣额。
6. **执行上下文**：移动在有界 REST 事务内完成，不新建移动 cron；通知状态存操作记录并重试，不在事务内调用主题或外部 I/O。
7. **删除边界**：本次移动不删除附件或制品，不改磁盘、URL、GUID、S3 key/profile 或已有存储 manifest；原有删除申请、审核与执行链继续生效。待删对象与相关祖先保持冻结。
8. **升级**：以 schema 版本幂等新增操作记录表；不自动重扫既有媒体、不自动转交现有附件，也不执行 FileBird 迁移。用户根来自本站成员分页，目录展开按用户懒加载。
9. **停用/卸载**：无新增移动队列需要清理；默认保留操作记录与原有账本。现有显式删除插件数据策略应包含新操作记录表，实体文件仍不自动删除。
10. **外部依赖**：移动不调用云存储；可证明的未纳管本地制品首次纳管需显式确认。复杂 offload/缺失本地制品保留现状并给出可操作错误。
11. **开销与开关**：继续读取唯一 `enabled` 开关及 `sufm_media_folders_takeover`；仅媒体库加载面板，手机使用抽屉。筛选通过关联 EXISTS 与原生查询组合，无 3000 附件 ID 截断；原生日期、搜索和 MIME 条件保留。
12. **验证**：发布前验证独立 WordPress 的根目录、多选移动、转交/纳管预览与重放、冻结/超额/权限失败、组合筛选、原生附件详情、桌面及手机布局；所有夹具清理后再作生产只读检查，具体结果随交付记录。

| 开关/选择 | 默认/来源 | 读取方与依赖 | 变更或失效时 |
|---|---|---|---|
| enabled | `Settings::DEFAULTS` | 原有模块入口、媒体库查询和资产 | 关闭保留账本与审批冻结，不产生移动 |
| sufm_media_folders_takeover | 原有过滤器默认值 | 当前管理员媒体库布局 | 关闭面板接管，不写第三方目录数据 |
| 当前范围/来源/申请/日期 | URL 或原生 media query | SQL 交集，原生分页 | 空交集返回零项，不扩大到全部 |
| transfer snapshot/hash | 每次服务器预览 | 事务内版本、作者、冻结、额度再验证 | 过期或变化返回冲突，需刷新预览 |
| notification adapter | 当前主题适配器 | 提交后的操作通知 | 降级/重试状态可见，移动结果不回滚 |


## 媒体库上传目标修复（1.18.36，2026-11-02）

问题：媒体库「上传到文件夹」下拉先出现再消失；管理员上传的附件默认落进「未分配媒体」；上传后看不到结果提示。

1. **根因**：`assets/media-new-folders.js` 与 `media-modal-folders.js` 调 `GET /admin/folders` 时未带 `owner_id`，后端 `Folders::admin_page()` 的 `$this->admin_tree( $ids, $owner_id > 0 )` 因此不加载目录树，所有 owner 的 `tree` 为空；前端判定「没有文件夹」后把刚插入的下拉 `hidden=true`。同时这些脚本依赖 `sufm_manage_media_folders`（管理员能力），普通用户即便带 owner_id 也会 403。
2. **新接口**：`GET /sufm/v1/folders`（权限 `can_use_files` → `sufm_manage_own_files`）返回当前登录用户自己的 `tree/files/rooted`，供三处上传器共用；不再要求管理员能力，也不接受任意 owner 参数。
3. **根目录是合法目标**：`Media_Hooks::assign_uploaded()` 从 `empty($_POST['sufm_folder'])` 改为 `isset(...)`，`folder_id=0` 走 `Sync_Service::adopt()` 把附件纳入账本（folder 0），仅 `folder_id>0` 时校验文件夹并 `Files::move()`。这样「不选文件夹」不再等于「不纳管」。
4. **提示**：上传器绑 `FilesAdded/FileUploaded/UploadComplete/Error`，用 `.sufm-upload-toast`（media-new）与 `.sufm-feedback`（upload.php）显示进度、完成与失败；不覆盖 WP 原生 `#media-items` 成功条目与 `#media-upload-error`，两套提示并存。
5. **网格页内联上传器**：`upload.php` 的 `.uploader-inline .upload-ui` 也注入「上传目标文件夹」，与媒体弹窗共用 `fillTargets()`；注入用存在性判断防重复。列表模式不加载 `.media-frame`，选择器只在网格模式或弹窗中出现。
6. **验证边界**：e2e 站验证 media-new 指定文件夹/根目录、upload.php 网格内联上传、媒体弹窗三处；生产临时管理员会话只读验证下拉 11 项（根目录 + 10 个真实文件夹）与三次上传落位（folder 9/0/10），测试附件与账本记录随后删除，`sufm_usage` 回算到与 active 账本一致（35,874,649 字节）。

## 媒体库上传进度与自动刷新（1.18.37，2026-11-03）

问题：媒体库上传完成后网格不出现新图，多图上传没有进度列表，用户无法判断是否成功。

1. **原生状态不可见**：WP 的 `wp.media.view.UploaderStatus` 只渲染在媒体弹窗的侧栏，`AttachmentsBrowser.createUploader()` 对网格页内联上传器传 `status:false`；`wp.Uploader.queue` 也没有被媒体库的 `library` observe，`fileUploaded()` 结束时会 `queue.reset()`。因此网格页既无进度列表、也不会自动把新附件插入 collection。
2. **进度面板**：插件自己在 `body` 上渲染 `.sufm-upload-progress`（右下角固定、可折叠关闭），逐文件列出 `文件名 / 百分比 / 进度条`，标题处显示「已完成 / 总数」，颜色区分进行中（粉）、完成（绿）、失败（红）。media-new 与 upload.php 共用同一视觉。
3. **自动刷新**：`UploadComplete` 后 350ms 调 `refreshMedia(false)`（网格用 `wp.media.frames.browse.state().get('library').more({reset:true})`，列表用 fetch 替换表格），再 `load(false)` 刷新左侧目录计数；刷新只在全部上传完成后触发一次，不为每个文件重复请求。
4. **不抢原生反馈**：WP 原生 `#media-items` 条目与 `#media-upload-error` 继续显示，插件面板与 toast 并存；失败时面板标题变红并保留 9 秒，成功时 4 秒后自动收起。
5. **验证**：e2e 网格页 3 文件限速上传，面板显示 `2 / 3` 与逐条 100%，上传后 `.attachment` 20→22、左侧「全部媒体」计数同步；media-new 3 文件上传显示 `3 / 3` 且 toast「3 个文件已上传完成。」；媒体弹窗与网格选择器根目录文案统一为「我的文件（根目录）」。

## 媒体库直接删除（1.18.38，2026-11-03）

问题：管理员在媒体库勾选图片点「永久删除」，确认框过了但图片还在——`pre_delete_attachment` 一律拦截。

1. **根因**：`Media_Hooks::guard_delete()` 用 `Files::is_managed()` 判断，而它只看 `get_by_attachment()` 是否存在且非 purged，不区分来源。媒体库同步进来的附件全部是 `source = media`，于是被当成「用户文件」一并锁死；`preflight_bulk_delete()` 同样用 `is_managed()`。
2. **分源放行**：只有 `source = media`、`lifecycle = active`、无待处理申请、当前用户同时具备 `sufm_admin_delete_files` 与 `delete_post` 时才放行，并把这个附件 ID 记进 `$direct_deletes`。`userfiles` 来源仍返回 `false` —— 这条路必须走申请 → 审核 → 执行。
3. **删除后的账本收尾**：`pre_delete_attachment` 发生在文件与 meta 仍在时，退额不能放这里。改为监听 `deleted_post`，只处理本次放行过的 ID：删 `artifacts` 行、`Quota::adjust_used()` 扣回 `charged_bytes`、`Files::set_lifecycle(..., 'purged', 'deleted')`，并写一条 `action = media_delete / result = purged / safe_detail = direct` 的事件。三步包在一个事务里，避免出现「附件没了、额度没退」。
4. **列表模式同步**：`preflight_bulk_delete()` 的拦截条件从 `is_managed()` 改成 `is_managed_attachment()`（只认 `userfiles`），这样媒体列表的批量删除与网格删除行为一致；被拦时仍跳 `sufm_delete_blocked` 并给出「请让文件所有者提交删除申请」的提示。
5. **执行器不受影响**：它通过 `sufm_allow_attachment_delete` 过滤器先放行，再自己完成清除，与这条直接删除路径互不重叠，不会重复退额。
6. **验证**：e2e 站网格删除 `media` 源附件后附件消失、账本 `lifecycle = purged`、`sufm_usage` 减少、事件新增一条；把同一条改成 `userfiles` 源后网格删除返回失败、列表批量删除跳 `sufm_delete_blocked` 并显示提示。生产临时管理员会话删除 1 张（附件 280），账本 `lifecycle = purged`、`storage_state = deleted`、配额从 40,015,641 降到 39,760,281（正好 -255,360）、事件 1 条，`used_bytes` 与 active 账本之和保持一致。

## 上传目标选择器重设计 + 删除反馈（1.18.39，2026-11-03）

问题：媒体库内联上传器里的「上传到文件夹」下拉沿用 WordPress 后台原生的灰底、直角 select，夹在白底虚线拖拽区中间像一块补丁；另外管理员能删掉图片了，但页面不给任何反馈，不知道删成功没有。

1. **控件本体**：不用原生外观。`appearance: none` + 自绘 SVG 箭头（`data:image/svg+xml` 内联，不额外发请求），8px 圆角、1px `#e3e6ea` 浅边、白底、32px 高、右内边距 30px 给箭头留位。
2. **容器**：外圈换成 `#f8f9fb` 的 12px 圆角浅灰底 + 极淡边框，与 WordPress 后台卡片接近，去掉原来那条粉色左边线——它把注意力全吸到控件上，反而更突兀。三处（上传媒体页 / 媒体库内联上传器 / 编辑器媒体弹窗）共用同一套规则，只按 `body.upload-php` 与页面本身区分作用域。
3. **对齐**：内联上传器的 `.upload-ui` 是 `text-align:center` 的容器，控件直接插进去会悬在中间。加一层 `.sufm-inline-folder-bar`（`display:flex;justify-content:flex-start;text-align:left`）让它贴左，与下方拖拽提示的居中排版不冲突。
4. **焦点与悬停**：`focus` 用粉色描边 + 3px 半透明外环（与前台 `.sufm` 的焦点风格一致），`hover` 只加深边框，不做位移，避免在拖拽区里抖动。
5. **删除反馈**：WordPress 媒体网格删除走 `action=delete-post`，成功返回 `1`、被拦返回 `0`。插件在 `jQuery.ajaxSuccess` 上按这个动作计数，420ms 内合并成一次提示：全成功「已删除 N 个文件。」，有失败「已删除 %d 个文件，%s 个未能删除（可能是用户中心上传的文件）。」，随后刷新网格与左侧目录计数。合并窗口是为了多选批量删除时不要弹 N 次。
6. **验证**：e2e 站三处控件实测 `border-radius: 8px`、`appearance: none`、容器 `12px` + `rgb(248,249,251)`；生产管理员会话删除一张临时测试图（附件 283）后 toast 显示「已删除 1 个文件。」，账本 `lifecycle = purged`、事件 `media_delete/purged`、`used_bytes` 与 active 账本之和的差值为 0。

## 跨账户边界复核（1.18.40，2026-11-03）

在 1.18.39 上做了一轮前端「文件管理」的跨账户安全复核（代码通读 + 独立子智能体审计 + e2e 三账号实测）。

**结论**：用户侧 35 条 `sufm/v1` 路由的 owner 全部取自会话，读 SQL 强制 `owner_id=%d`，写路径在仓储层二次校验归属；删除申请经 `plan()` 逐目标 `get($owner,$id)`、`approve()` 按 item 落库 owner 重取、`purge_file()` 以 `request_id + object_version + attachment_id` 三重锁，未发现可被普通订阅者利用的跨账户读写。用户两条硬要求（不显示他人文件、申请列表不含他人文件）在 REST 层成立。

**本轮补的纵深防御**（均为"上游已校验、此处再收紧"）：

1. `Requests::items()` 的 `file_name`/`folder_name` 联表补 `AND f.owner_id=i.owner_id`（同理 folders）。实测把 item 篡改指向他人文件后，名字返回 `null` 而不泄漏。
2. `Requests::create()` 的 `pending_request_id` 锁写入补 `owner_id=%d`；`unlock()` 增加 `?int $owner_id` 并在三个调用点传 `$request->owner_id`，未传时从 `delete_requests` 读 owner，永不按请求号全域释放。实测他人行上的伪造锁在撤回后保持不变。
3. `Folders` 的 `rename_locked` / `move_locked` / `delete_locked` / `reorder_locked` 落库条件统一带 `owner_id`（此前只有 `id`）。
4. `Module::maybe_install()` 补调 `Capabilities::grant()`：角色是站点选项而非 schema 版本，新装站点此前不会补授 `sufm_manage_own_files` / `sufm_request_delete_own_files`，普通用户会 403。grant() 幂等。
5. `Media_Hooks::add_summary_field()`：非本人附件只在具备 `sufm_manage_media_folders` 时才渲染「所有者 / 虚拟目录」摘要，`editor` 不再能通过媒体库附件详情看到他人文件归属。

**验证**：e2e 用 alice(3)/bob(4) 双账号跑越权矩阵（读目录、改文件、改目录、移入他人目录、自己的 `/folders` 树、`/admin/*` 403）全部 404/403；自己的重命名/移动/新建目录/申请/撤回仍 200；`php -l` 四文件通过、`phpunit --filter UserFiles` 18 tests / 80 assertions OK。生产未改数据。

## 媒体库上传前置校验（1.18.41，2026-11-03）

1. **为什么不是"上传完再读库"**：记账确实可以事后做（`assign_uploaded()` 已经在用 `get_post()->post_author`、`Sync_Service::adopt()` 用真实文件大小），但**拒绝**超额/超类型的文件无法事后补——`add_attachment` 触发时文件已落盘、附件行已存在、`media_handle_upload()` 已把成功返回给浏览器。所以校验必须挂在落盘之前，用的就是 WordPress 官方的 `wp_handle_upload_prefilter`（`wp-admin/includes/file.php` 里 `apply_filters( "{$action}_prefilter", $file )`），plupload 的 `async-upload.php` 与 `media_handle_upload()` 全部经过它，不需要改上传链路。
2. **与主题共存**：zibll 自己也在同一过滤器上挂了两条（`zib_brush_limit_upload` 优先级 99、`zib_upload_disallow_unlisted_filetype` 优先级 0）。我们的 `guard_media_upload()` 用优先级 50 居中，只写 `$file['error']`，不打断其它回调。
3. **放行条件**：`Settings::enabled()` 未开、无登录用户、无 `upload_files`/`sufm_manage_own_files`（导入、cron、CLI 场景）、`$file['error']` 已有值、`Uploader::in_pipeline()` 为真（前台 REST 上传管道已经校验过配额与类型，避免二次判定）——命中任意一条直接原样返回。
4. **三条拒绝**：类型不在 `allowed_types` 白名单、`$size > max_file_bytes`、剩余空间为 0 或不足，分别给中文提示，`$file['error']` 会被 `async-upload.php` 原样回显。
5. **面板不能误报**：被拒绝的上传仍然返回 HTTP 200（`success:false` 或 `<pre>` 包裹的错误 HTML），plupload 依旧触发 `UploadComplete`。两个前端脚本各加 `uploadAccepted( response )`（判 `success === false` 或匹配 `media-upload-error|error-div`），失败行标红计入 `uploadFailed`；`UploadComplete` 时按面板里 `.sufm-upload-item.is-error` 的**实际行数**决定标题与颜色，因此"先失败后成功"的一批仍显示「上传失败」。
6. **验证**：e2e 把 `max_file_bytes` 临时改成 4096 传 481KB PNG → 未产生附件、`#media-items` 显示「单个文件不能超过 4 KB。」、网格面板「上传失败 0 / 1」；先拒一个再传一个 600B gif → 面板「上传失败 1 / 2」且原生列表里同时有错误项与成功项。

## 未纳管附件补漏（1.18.42，2026-11-03）

用户指出：「未纳管附件（从来没进过账本的）依旧可以绕过配额，因为 guard_delete 与配额都对『查不到记录』放行」。

**缺口是怎么来的**（四处叠加，每一处单独看都"合理"）：

1. `Media_Hooks::guard_delete()` 的开头是 `if ( ! $file || 'purged' === $file['lifecycle'] ) { return $delete; }`——把"账本里没有"当成"不归我管"，直接放行。
2. `Media_Hooks::assign_uploaded()` 开头 `if ( ! isset( $_POST['sufm_folder'] ) ) { return; }`——只有经过插件上传选择器的上传才会被纳管；REST `/wp/v2/media`、主题的媒体弹窗、选择器未注入的页面全部漏掉。
3. `Sync_Service::tick()` 虽然能把孤儿附件补登（`adopt()` + `adjust_used()`），但只在 `sufm_sync_state.state === 'running'` 时推进，而 `running` 需要手动 `start()`；没同步过的站点，积压永远不补。
4. `Quota::snapshot()` 的 `available` 只按账本算——未纳管附件的字节根本不参与，于是"实际占满磁盘、配额显示全空"。

**修法（一律"先补登、再判定"，不新增后台任务）**：

1. `assign_uploaded()` 去掉 `sufm_folder` 早退，改为：`Uploader::in_pipeline()` 时早退（前台 REST 管道自己记账，避免 UNIQUE 抢写）；归属**只认 `$post->post_author`**，不再读表单里的 `sufm_owner`（表单可伪造，post_author 是 WordPress 记录的）；只有当前用户就是作者且带了 `sufm_folder` 时才把文件放进所选目录，否则留在根目录；最后无条件 `adopt_unmanaged()`。
2. 新增 `private adopt_unmanaged( int $attachment_id )`：无账本行时调 `Sync_Service::adopt()`（它用真实文件大小算 `charged_bytes`）并 `Quota::adjust_used( owner, charged )`，返回账本行或 `null`。
3. 新增 `public adopt_owner_backlog( int $owner_id, int $limit = 50 ): int`：`LEFT JOIN {files} f ON f.attachment_id = p.ID WHERE p.post_author = %d AND f.id IS NULL`，逐条 `adopt_unmanaged()`，返回补登条数。`limit` 防止一次请求里补几千条。
4. `guard_delete()` 的第一行改为 `$file = $this->adopt_unmanaged( $post_id );`——"查不到记录"不再等于"放行"，而是先纳管再按 source 判定：media 源按 1.18.38 的规则处理，userfiles 源仍走申请→审核→执行。
5. `guard_media_upload()` 在 `Quota::snapshot()` 之前先 `adopt_owner_backlog( $owner )`，让积压占用计入剩余空间。

**为什么不顺手把 `Sync_Service::tick()` 改成自动跑**：`tick()` 是 keyset 全表扫描（`BATCH = 200`、`budget 600ms`），在大站上每分钟跑一遍会变成常态化的慢查询；而"上传/删除这两个真正需要准确的时刻"各补一次 50 条就够了——配额是软约束，积压迟早会被这两个入口吸收。

**验证（e2e，`wp eval-file` 直接调服务层）**：

1. 用 `$wpdb` 直插 `posts` + 真实文件造出「有文件、无账本行」的附件（绕过 `add_attachment` 钩子）→ `adopt_owner_backlog(1)` 返回 `1`，账本 `charged_bytes = 8192`，`used_bytes` 4166 → 12358，`SUM(charged_bytes)` 与 `used_bytes` 一致。
2. 把配额设成 `used_before_adopt + 4196`（忽略积压时 4KB 文件刚够）→ 补登 8KB 后 `available = 0`，`guard_media_upload()` 返回 `文件空间已用满，请先删除部分文件或联系管理员扩容。`——补登前同样的上传会放行。
3. subscriber（alice，无 `upload_files`）对自己未纳管的附件调 `guard_delete()` → 修前 `true`（放行），修后 `false`，且账本被补登为 `source = media / lifecycle = active`。
4. 已纳管附件的正常路径未受影响：`phpunit --filter UserFiles` 全绿，`php -l` 通过。

**遗留**：`Sync_Service::finish()` 仍会在同步结束后按 `SUM(charged_bytes)` 重写 `used_bytes`，如果站点在两次同步之间删过文件，`used_bytes` 与账本会有小幅漂移（e2e 实测 owner 1 漂移 392 字节，来自 1.18.38 之前历史数据）。这是既有行为，不在本次范围内。

## 媒体归属转交的清单证明（1.18.43，2026-11-03）

用户问「我可以在后台自己文件设置归属吗？」——入口一直是有的：媒体库左侧「用户文件」树里选中文件 → 底部「移动到…」→ 选目标账号 → 二次确认，后端是 `Media_Mover::execute()` 的原子事务（同时改 `post_author`、账本归属、`used_bytes`，并写 `sufm_events`）。但生产上这个按钮对几乎所有媒体都点不动，卡在两道闸门：

1. **元数据白名单**：`Adoption_Manifest::build()` 原来「不在白名单就 `return error()`」。白名单只有 7 个键，而生产 124 张附件里 `fb_filesize`(118)、`sakura_imgopt_log`(117)、`_sakura_imgopt_backup`(117)、`_sakura_imgopt_s3_*`(6 个, 各 27)、`views`(1) 全都不在里面——**任何装了图像优化/S3/浏览量插件的站点，首次纳管永久失败**，附件永远停在「WordPress 原库」。
2. **转交要求清单**：`Media_Mover::plan()` 的跨账号分支要求 `manifest_version >= 1` + 有 `sufm_artifacts` 行 + `SUM(logical_bytes) === charged_bytes` + 全部 `cleanup_state='present'`。生产 129 条账本里 `source=media` 的 127 条 **`manifest_version` 全是 0**（历史上传与 1.18.42 的补登都只记金额、不记清单），`sufm_artifacts` 只有 2 个 userfiles 文件共 9 行。于是「转给别人」= 100% 报 `sufm_manifest_unproven`。

**为什么不能简单放开**：`charged_bytes` 是配额扣费依据。如果清单字节数与账本不一致仍然放行，就等于允许"账本写 1KB、磁盘 100MB"的文件免费转手，配额从此不可信。所以放宽的只是**是否愿意去磁盘核对**，不是**核对的结果**。

**修法**：

1. `Adoption_Manifest`：白名单提为 `private const OWNED_META`，语义改为"本插件理解的、参与哈希的键"；删除"外键即否决"的循环。新增 `REMOTE_PREFIX = '_sakura_imgopt_s3_'` 与 `is_remote_marker()`，这类键表示"有异地副本"，只影响展示，不改变计费字节（生产实测 27 个 S3 附件本地文件全在，`s3_local_missing = 0`）。符号链接、越出 uploads 目录、派生尺寸缺失仍然是硬错误——这些是真实的文件系统异常。（2026-11-08 清理：`REMOTE_PREFIX` / `is_remote_marker()` 始终无调用，已删除；此处保留决策记录。）
2. `Media_Mover::plan()`：`$proven` 成立（有清单且字节吻合）走原路；无清单但有 `charged_bytes` 时**从磁盘重建**清单，并要求 `array_sum(logical_bytes) === charged_bytes` 才继续，不等仍报 `sufm_manifest_unproven`；已存在清单但字节对不上时直接拒绝，绝不覆盖——「账本与磁盘打架」必须停下。
3. `Media_Mover::execute()`：重建成功的项在转交事务内把 artifacts 落库、`manifest_version` 升到 1，结果里返回 `promoted` 列表。这样第二次转交读记录即可，不必每次重算哈希。
4. `Sync_Service::adopt()` 改为补登后立即 `record_artifacts()`：新方法同样要求磁盘字节与 `charged_bytes` 完全一致，一致才写清单并把版本升到 1，否则保持 0（不证明就不放行）。1.18.42 补登出来的「二等公民」行从此也能跨账号转交。
5. 前端：「未分配媒体」视图的选择工具条按钮由「移动到」改为「指定归属」，措辞对准这一步真正在做的事（把无主媒体分配给某个账号）；其余视图不变。

**验证**：

1. 生产只读：`source=media AND manifest_version<1` 共 122 行，用新 manifest 重建后 **122/122 字节数与 `charged_bytes` 完全一致、0 错误**——说明"重建补全"在本站是安全的，不会因放宽而放过漂移。
2. 生产实跑（附件 234，`post_author=0` 的无人认领 webp 74826B）：预览从报错变为 `incoming=74826`；执行后 `adopted=[234] transferred=[234]`、`post_author` 0→1、账本新行 `manifest_version=1`、artifacts 1 行、`used_bytes` 与 `SUM(charged_bytes)` 漂移 0。
3. e2e：直接插一条「有账本、`manifest_version=0`、无 artifacts」的旧式记录 → 转交成功且返回 `promoted=[111]`、`manifest_version` 变 1、artifacts 1 行 5000 字节与 `charged_bytes` 相等；随后反向再预览一次直接读记录，不再报错。
4. e2e：`adopt()` 补登 → `manifest_version=1` + artifacts 写入（3000 字节吻合）；故意声明一个不存在的派生尺寸再补登 → `manifest_version` 保持 0、artifacts 0 行、不可转交（`transferable=no`），确认漂移仍然被挡住。
5. `phpunit`：388 tests / 1089 assertions，5 skipped（原有跳过项）；`php -l` 六文件全绿。

**遗留**：`post_author = 0` 的孤儿附件在 1.18.42 下仍不会被自动补登（没有账号可归属、没有配额可扣），需要管理员先用「指定归属」显式转交——这正是本次入口存在的意义。

## 媒体库两处界面缺陷（1.18.44，2026-11-03）

用户反馈：「『指定归属』这个设置项在哪里啊，没看见；还有这个打开附件详情，左上角为什么会有上传的样式？」

**缺陷一：附件详情里混进了「上传目标文件夹」**

- 现象：打开单张附件的详情，左栏「附件详情」标题下面挂着一行浅灰胶囊「上传目标文件夹 / 我的文件（根目录）」。
- 根因：`assets/media-folders.js` 的 `uploadPicker()` 用 `modal.querySelector( '.media-toolbar' ) || modal.querySelector( '.media-frame-content' )` 找宿主。真正的上传弹窗里确实有 `.media-toolbar`；但 WordPress 的附件详情（`attachment-details` 视图）复用了同一个 `.media-frame-content`，内部没有 `.media-toolbar`，于是回退分支命中，把 `label.sufm-modal-folder` 插进了详情左栏。
- 为什么不能在打开详情时"补救"：选择器在**点击卡片之前**就已经挂载好了（实测点卡片那一瞬间 picker 计数已经是 1），事后移除会让上传面板下次打开时缺少控件。必须在**找宿主**这一步就只认真正的上传容器。
- 修法：`var modalHost = modal && modal.querySelector( '.upload-ui' );`——`.upload-ui` 是上传面板独有的类，附件详情不会出现。改完实测 `.attachment-details .sufm-modal-folder` 由 1 → 0，`.uploader-inline .sufm-inline-folder` 仍为 1（栅格页内联上传不受影响）。

**缺陷二：找不到「指定归属」**

- 它的真实身份是**批量操作按钮**，不是设置项：`makeToolbar()` 里 `var assigning = unassignedView(); button( assigning ? t( 'assignOwner' ) : t( 'moveTo' ), … )`。出现条件有两个——①URL 带 `sufm_unassigned=1`（左侧树的「未分配媒体」）；②已勾选至少一个文件（`updateSelection()` 里 `toolbar.hidden = n === 0`）。而「未分配媒体」在新站通常是空的（新上传都会归到作者名下），空列表连卡片都没有，自然无从勾选，于是这条路径等于死路。
- 修法：在未分配视图里把工具条**常驻**，并用占位文案说明下一步；空列表时补一段说明。具体是 `toolbar.hidden = n === 0 && ! idle;`（`idle` 即未分配视图），计数标签在无选择时显示 `assignHint`，按钮 `disabled` 直到真的勾了东西，取消按钮也在无选择时隐藏。新增 `unassignedNotice()`：未分配视图且 `.no-media` 存在时插入 `p.sufm-unassigned-note`。
- 新增两条文案（`Media_Hooks` 的 strings）：`assignHint`「勾选下方媒体，再点「指定归属」把它们分给某个账号」、`unassignedNote`「这里列出还没有归属账号的媒体。勾选要分配的文件，点上方「指定归属」，选择目标账号即可。」

**走过的弯路**：一度加过 `detailsOpen()`（判 `wp.media.frame.state().id === 'edit-attachment'`）来在详情打开时隐藏工具条，理由是"别挡住属性栏"。实测把栅格模式的选择条也藏了——栅格卡片点一下就进详情态，等于选择功能失效。已回退，`readme` 里那句也一并删掉。教训：**选择条不能按 frame 状态隐藏**，frame 状态描述的是模态框在干什么，不是页面上有没有选中项。

**验证（生产浏览器，1600×1000）**：

1. 附件详情：`wp.media.frame.setState('edit-attachment')` 后 `.attachment-details .sufm-modal-folder` = **0**。
2. 内联上传：`.uploader-inline .sufm-inline-folder` = **1**，控件仍在。
3. 栅格勾选：`.attachment .check` 点 1 个 → 工具条 `hidden:false`、`已选 1 项`；点 2 个 → `已选 2 项`。
4. 列表勾选：勾 1 个 → `hidden:false`、`已选 1 项`、按钮「移动到」、取消按钮可见；勾 2 个 → `已选 2 项`。
5. 未分配视图（`?sufm_unassigned=1`，当前为空）：工具条 `hidden:false`、文案为 `assignHint`、按钮「指定归属」`disabled:true`、取消按钮隐藏、空态说明存在。
6. 全程 `pageerror` 为空。

**运维注意**：nginx 给 `.js` 发 `cache-control: max-age=2592000`，改完刷新可能仍拿旧文件（旧版会报 `Unexpected end of input`）。插件的 `?ver=` 已带 `1.18.44.<filemtime>.<filesize>`，正常刷新即自动换新；手工复核时需带 cache-busting 参数。

## 媒体库文件夹栏：一条通到底的列（1.18.45，2026-11-03）

用户反馈：「竖线左侧的背景颜色要么填充满要么取消使用WP原本的背景色」——截图里树面板是白色圆角卡片，左边留了一条 20px 的 WordPress 灰底缝隙，一直通到屏幕底部。

**两个选项，选了「填满」**：

1. 填满：把面板左侧那 20px 也涂白，分割线成为唯一的分界。
2. 取消背景色：让面板与 WP 灰底同色。

不选第 2 条是因为树里靠浅色底区分层级（悬停 `#f0f0f1`、选中 `#e8f0fe`），底色一旦与灰底同色，这些状态就糊在一起了。填满保留了原来的白色面板语言，只是把缝一起吃掉。

**改法**：

```css
body.upload-php.sufm-tree-active #sufm-tree { top: var(--wp-admin--admin-bar--height, 32px); bottom: 0; border: 0; border-right: 1px solid #dcdfe4; border-radius: 0; padding-top: 16px; }
body.upload-php.sufm-tree-active #sufm-tree::before { content: ""; position: absolute; top: 0; bottom: 0; right: 100%; width: 20px; background: #fff; }
```

三处细节：

1. `::before` 而不是给 `#wpcontent` 加背景——面板是 `position: fixed`，伪元素跟着它走，折叠/展开/切页都不用额外同步。
2. `top` 从写死 48px 改成 `var(--wp-admin--admin-bar--height, 32px)`，面板正好从工具栏下沿开始，上方不留灰条。
3. `@media (max-width: 782px)` 里 `::before { display: none }`——手机上树是贴左边缘的抽屉，没有缝可填。

**顺带修掉一个真实缺陷**：横向偏移原来在两处各写一遍（宽屏 `left:180px`、中屏 `left:56px`），而 961–1199px 区间菜单还是展开的 160px 宽，面板却已经移到 56px——被菜单压住，搜索框和「新文件夹」按钮都被遮。现在提成一个变量：

```css
body.upload-php.sufm-tree-active { --sufm-tree-left: 180px; }
body.upload-php.sufm-tree-active.folded { --sufm-tree-left: 56px; }
@media (min-width: 783px) and (max-width: 960px) { body.upload-php.sufm-tree-active { --sufm-tree-left: 56px; } }
```

960px 是 WordPress 侧栏自动折叠的分界（`auto-fold`），所以变量跟着它切。折叠把手、展开按钮、`.sufm-tree-collapsed` 的定位全部改用这个变量，删掉了四条写死 180/56 的规则。

**验证（生产浏览器）**：1600 / 1280 / 1100 / 1000 / 960 / 900 / 820 / 783 八个宽度下，面板左边缘与菜单右边缘的间隔**恒为 20px**（菜单 160px 时面板 left=180，菜单 36px 时 left=56），`::before` 宽度 20px；像素采样确认原灰缝位置已是纯白，面板从 y=32 起到底部连续；折叠态把手落在面板左缘、展开按钮同轴；390px 抽屉 `::before` 为 `none`、x=0 展开正常。

**已知**：面板宽度在所有桌面宽度下都是 300px。1150 行附近的 `@media (min-width:783px) and (max-width:1199px)` 里原本有个 `--sufm-tree-width:232px`，但被后面的 density 块重新声明成 300px，是**死代码**，本次已删并加注释说明；窄屏想要更窄的列，改 density 块那一处即可。

## 媒体库文件夹菜单：右键呼出 + 收紧行高（1.18.46，2026-11-03）

用户反馈：「现在的多功能选项显示大小不合理，占用太多空间和空白，其次优化成和前端一样的鼠标悬浮在对应文件夹上右键就能展开多功能选项」。

**为什么每项 56px**：菜单项是 `button( label, run, a[2] ? 'is-danger' : '' )` 造出来的，而 `button()` 这个工具函数默认套 WordPress 的 `.button` 类。`.button` 有约 26px 的上下内边距，782px 断点里还有一条 `min-height:44px`，两者叠加把每行推到 56px。五项 = 274px 的一整块，看起来像五个按钮而不是一张菜单。

**为什么不是简单缩小 padding**：根子是**用按钮的视觉语言表达菜单项**。菜单项是标签列表，应该只有文字行高与悬停底色；按钮才有边框、内边距、阴影。所以给菜单项换了独立的类 `.sufm-menu-item`，明确 `border:0; background:none; box-shadow:none; min-height:30px; padding:5px 9px; font-size:13px; line-height:20px`，不再继承 `.button` 的外观。删除项保留 `is-danger` 的红色文字，并补了浅红悬停底，避免只剩红字没有反馈。菜单容器 `min-width` 从 170px 收到 156px，实测 168px（五项里最长的是「新建子文件夹」）。移动端 `@media(max-width:782px)` 里把 `.sufm-menu-item` 单独提回 `min-height:44px; padding:10px 9px; font-size:14px`，触控目标不缩水。

**右键怎么做**：`menuFromRow( node, x, y, actions )` 是唯一入口，三条路都走它——

1. 行上的 `⋯` 按钮：锚在按钮下缘（`bottom + 4`）。
2. `contextmenu` 事件：`event.preventDefault()` 后在光标处打开。
3. 键盘：行上有焦点时 `ContextMenu` 键或 `Shift+F10`，在行内偏移 14px 处打开；菜单打开后焦点落在第一项，`Esc` 关闭。

`placeMenu( x, y )` 负责夹取边界：水平方向 `Math.max(8, Math.min(x, innerWidth - w - 8))`，垂直方向若 `y + h > innerHeight - 8` 就翻到光标上方 `y - h`。菜单是 `position:fixed`，所以滚动（捕获阶段）与 resize 都要收起——否则菜单会浮在离它所属行很远的地方。

**踩过的坑**：`openMenuAt()` 原来第一行调 `closeMenu()`，而 `closeMenu()` 在 1.18.46 里还负责移除行的 `is-menu-open` 高亮。于是 `menuFromRow()` 先加高亮、紧接着 `openMenuAt()` 又把它清掉，右键后那一行没有任何视觉反馈。修法是把 `closeMenu()` 提到调用方（`menuFromRow` 已经先调了），`openMenuAt()` 只负责建 DOM。**顺序敏感的副作用要放在同一条链上，不能让被调函数顺手清理调用方的状态。**

**验证（生产 1600×950 / 390×844）**：

1. `⋯` 入口：菜单 168×**162**px，五项各 **30px**（原 184×274，每项 56px）。
2. 右键文件夹行：菜单在光标处打开、5 项、所属行 `is-menu-open` 高亮 `rgb(240,240,241)`、`⋯` 按钮 `opacity:1`。
3. 底部翻转：靠近视口下缘打开时 `top=831 bottom=903`（视口 950），未溢出。
4. 收起：`Esc` 后菜单与高亮都清空；滚动树容器 / 触发 window scroll 后菜单数归 0。
5. 键盘：行聚焦后 `Shift+F10` 打开 2 项菜单（用户节点）。
6. 手机 390px：菜单项 `min-height:44px`、字号 14px、菜单右缘 382 < 视口 390，不出界。
7. 全程 `pageerror` 为空；`node --check` 通过。

## 文件夹样式与配色（1.18.47，2026-11-03）

用户要求：「后台页面新增一个卡片，能选择虚拟文件夹样式，先做一套 Windows 11 和苹果 Mac 的，默认 Windows 11 样式，然后媒体库那边虚拟文件夹的图标颜色能自定义」。

**为什么是「样式类 + 一组 CSS 变量」而不是两套模板**：树是同一份 DOM（`row()` 逐节点渲染，`icon()` 用同一条 `<path>` 画文件夹）。如果按样式切换 DOM，就得维护两份渲染逻辑和两套拖拽/右键/键盘绑定。改成在 `body` 上挂 `sufm-tree-style-windows` / `sufm-tree-style-mac` 和 `sufm-accent-<key>`，样式只改颜色与几何变量：`--sufm-folder-ink`（描边）、`--sufm-folder-fill`（填充）、行高与圆角。切换即改 class，无需重建 DOM、不丢选中态。

**Windows 11 与 Finder 的差别不是换个颜色**：
- Windows：实心文件夹（`fill` 有值）、`border-radius:5px`、选中行 `#e8f0fe` 配 `#12457f` 文字——资源管理器的「浅蓝行」。
- Finder：描边文件夹（`fill:none`）、`border-radius:6px`、行高 34px（比 Windows 的 38px 紧一档）、选中行 `#e8ecf2` 配中性深灰——访达的「灰蓝行」。
- 两者的选中行都**不重染图标**：资源管理器里选中的文件夹图标颜色并不变，重染会跟用户选的主题色打架。

**为什么 Mac 样式强制空心**：最初让 `fill` 变量随 accent 一起变，结果「Finder + 湖蓝」变成实心蓝文件夹，与访达观感不符。修法是在文件末尾再声明一次 `body.upload-php.sufm-tree-active.sufm-tree-style-mac{--sufm-folder-fill:none}`——**最后一条声明赢**，这样 accent 只改描边色。

**配色只作用于图标**：`--sufm-folder-ink` 只喂给 `.sufm-node-ico`，标题文字仍是 `--sufm-ink`。理由是文件夹树里文字密度高，整行跟着变色会让「找文件」变成「读彩虹」。

**保存互不干扰**（这是本次真正的坑）：
1. 样式卡片是独立 `<form>`，只提交 `_partial=1` 与 `tree_style`、`folder_accent` 两个字段。`Settings::sanitize()` 见到 `_partial` 时先 `get_option()` 读回已存值再 `array_merge`，否则 `register_setting` 会把整份设置**重置成默认值**——配额、白名单、开关全被抹掉。e2e 已验证：先存 `quota=123456789` + `allowed_types=[image/png]`，再用 `_partial` 只提交样式，保存后配额与白名单原样保留。
2. 反向也要成立：策略表单提交的是全量字段，会把 `tree_style`/`folder_accent` 一起写回默认值。所以在策略表单里补了两个 hidden 字段带上当前样式值。
3. 非法值兜底：`tree_style` 不在 `[windows, mac]` 内回落到 Windows；`folder_accent` 不在白名单内回落 `''`（跟随样式）。

**验证**：
1. e2e 单元级：`defaults()['tree_style'] = windows`、`folder_accent = ''`；`_partial` 保存保留配额与白名单；`gnome`/`rainbow` 被拒绝并回落；`accent_choices()` 七项。
2. 生产浏览器：样式卡片渲染正常（两张预览卡、七个色板，默认勾选 Windows + 跟随样式）；点 macOS + 湖蓝保存后，`body` 类变为 `sufm-tree-style-mac sufm-accent-blue`，图标 `stroke: rgb(59,125,216)` / `fill: none`（空心）、行高 36px；切到 Windows + 琥珀后图标 `stroke: rgb(200,147,43)` / `fill: rgb(247,220,154)`、行高 38px，选中行仍保持琥珀图标不被重染；保存策略页后样式未被覆盖。无 `pageerror`。
3. 生产设置已恢复为默认（`tree_style=windows`、`folder_accent=''`），其余策略值（`enabled=1`、`quota=536870912`、`max_file_bytes=52428800`、9 个白名单）未受影响。

## 前台与后台文件夹样式统一（1.18.48，2026-11-03）

用户要求：「将前台的虚拟文件夹样式与后台媒体库虚拟文件夹样式统一吧」。

**问题不是「颜色不一样」，是两条绘制路线**：后台媒体库的树用 `media-folders.js` 的 `icon()` 画一条 SVG 路径（`M3 7V5a2 2 0 0 1 2-2h5l3 4h6…`），颜色来自 `--sufm-folder-ink` / `--sufm-folder-fill`；前台用户中心用的是纯 CSS 拼的文件夹——`.sufm-folder-art` 一个橙色渐变方块 + `::before` 一个 Tab 形状 + `::after` 一条高光横线。1.18.47 里新加的样式与配色只改了后台那一侧，所以前台看起来仍是「原版橙色文件夹」。

**修法：一个图形、一套变量、两个宿主类**。

1. 前台改用同一个 SVG 路径。`assets/userfiles.js` 新增 `folderArt()`，返回的 `<span class="sufm-folder-art">` 里装 `<svg viewBox="0 0 24 24"><path d="…"/></svg>`——路径字符串与 `media-folders.js` 的 `icon()` **逐字一致**，这样两边画的是同一个形状，而不是"看起来差不多"。原来三处 `'<span class="sufm-folder-art" aria-hidden="true"></span>'`（列表卡、桌面行、网格缩略图）统一走 `folderArt()`。
2. 前台 CSS 去掉 `::before`/`::after` 那套拼图，改为：
   ```css
   .sufm { --sufm-folder-ink: #c8932b; --sufm-folder-fill: #f7dc9a; }
   .sufm .sufm-folder-art svg { fill: var(--sufm-folder-fill); stroke: var(--sufm-folder-ink); stroke-width: 1.6; stroke-linejoin: round; }
   ```
   并补齐 `.sufm-style-mac` 与六个 `.sufm-accent-*` 变量组，色值与后台 `media-folders.css` 完全对齐（琥珀 `#c8932b/#f7dc9a`、湖蓝 `#3b7dd8/#cfe0f7`…）。
3. 后台的 class 挂在 `body`（`sufm-tree-style-mac` / `sufm-accent-blue`），前台挂在 `#sufm-app`（`sufm-style-mac` / `sufm-accent-blue`）——两处作用域互不干扰，但读的是同一个 `Settings::tree_style()` / `Settings::folder_accent()`。`Tab::content()` 把两个值塞进视图数据，模板拼出 `$app_classes`。

**为什么前台不直接复用 `media-folders.css`**：那份样式全部以 `body.upload-php.sufm-tree-active` 为前缀，是媒体库专用；前台是卡片式布局，`.sufm-folder` 在两边是完全不同的盒子。共用的是**变量名与色值**，不是选择器——把选择器强行合并会让两边互相污染。

**顺带修的两处**：
1. 网格缩略图底色原来是 `linear-gradient(160deg, #fff5e6, #ffe9c9)`，专门为橙色文件夹调的；换成任意配色后底色会和图标打架。改成 `var(--sufm-soft)`（中性浅灰），颜色交给图标本身；深色主题从 `#3a3327→#443a28` 的暖褐改为 `#33353f`。
2. 尺寸从"CSS 方块的比例"改成"图标盒子"：卡片 26×26、桌面行 20×20、网格缩略图 44×44、移动端 24×24。原来那些 `::before` 的偏移量全部删除。

**Mac 样式仍强制空心**：前台同样在 accent 规则之后再声明一次 `.sufm.sufm-style-mac{--sufm-folder-fill:none}`，并配 `stroke-width:1.4`（比 Windows 的 1.6 细）。与后台 1.18.47 的处理方式一致——**后声明赢**，配色只改描边。

**验证**：
1. 生产前台 `?user_center=files`：`#sufm-app` 类为 `sufm sufm-style-windows`（默认），8 个文件夹图标全部渲染为 SVG，`stroke rgb(200,147,43)` / `fill rgb(247,220,154)`；切到网格视图，缩略图内图标 44×44、同样的颜色。
2. 手动切 `mac-blue` / `windows-rose` / `mac`：分别得到 `rgb(59,125,216)/none`、`rgb(212,84,127)/rgb(248,215,226)`、`rgb(138,143,152)/none`——空心/实心与配色都符合预期。
3. 往返验证：把设置改成 macOS + 湖蓝后，前台 `#sufm-app` 类是 `sufm-style-mac sufm-accent-blue`、后台 `body` 类是 `sufm-tree-style-mac sufm-accent-blue`，两边图标计算样式**完全相同**（`stroke rgb(59,125,216)` / `fill none`）。改回默认后生产设置已恢复 `tree_style=windows`、`folder_accent=''`。
4. `phpunit` 388 tests / 1089 assertions（5 skipped）；`php -l` 与 `node --check` 通过；无 `pageerror`。

## 前端文件管理的读写预算（1.18.49，2026-11-03）

用户要求「检查文件管理的前端 UI 性能，在尽量少用数据库的前提下优化数据展示与操作流畅性」。

**先测量，再动手**（生产，`rest_do_request` + `$wpdb->num_queries`）：

| 路由 | 改前 | 改后 |
| --- | --- | --- |
| `GET /sufm/v1/items` | 5 条查询 / 1.3–1.6ms | **3 条** |
| `GET /sufm/v1/summary` | 6 条查询 / 8.7ms | **2 条** / 0.7ms |
| `GET /sufm/v1/items?folder_id=N` | 5 条 | 4 条（多一次面包屑） |

**五处改动，每一处都对着一条具体的浪费**：

1. **`Quota::snapshot()` 先读后写**。原来无条件 `ensure()`，即每次页面浏览都对 `sufm_usage` 执行一次 `INSERT IGNORE` —— 读请求写库，既多一条语句、又把账本行弄脏给并发读者。改成先 `SELECT`，只有行确实不存在时才补建并重读。`ensure()` 本身保留，因为写路径（`reserve()`/`adjust_used()`）仍然需要它先建行。
2. **目录列表与计数合一**。新增 `Folders::children_with_counts()`：`LEFT JOIN` 一个按 `folder_id` 分组的子查询，一次拿到子目录和各自的文件数。原来的两步（`children()` + `Files::counts_in_folders()`）合成一步，`items` 的响应字段不变（`folders[].count`）。
3. **去掉独立的存在性校验**。`items()` 原先先 `Folders::get()` 验 404，再 `breadcrumb()` 取路径 —— 后者本来就会逐级 `get()`。改成只走 `breadcrumb()`，链为空即 404（根目录不参与校验）。语义等价：被删/不属于自己的目录仍然 `sufm_folder_missing`。
4. **概览计数合并**。`summary()` 的四个 `COUNT(*)`（文件、文件夹、待审、已批准）来自三张表但互不依赖，合成一条带四个标量子查询的语句，用 `overview_counts()` 封装。
5. **列表只取该取的列**。`Files::query()` 的 `SELECT f.*` 收成 `f.id,f.folder_id,f.display_name,f.charged_bytes,f.pending_request_id,f.created_at` 加 `p.post_mime_type,p.guid` —— REST 转换器只读这些字段，清单版本、来源、存储状态都被无谓地拉过网络。

**前端流畅性**：

1. **首屏两个请求并行**。`refresh()` 与启动流程原来是 `loadSummary().then(loadItems)`，两次往返串起来；两者读不同表、互不依赖，改成 `Promise.all`。
2. **`content-visibility: auto`** 加到 `.sufm-file-card`，配 `contain-intrinsic-size: auto 168px`。首屏 40 张卡片（该站平均 326KB/张原图）不再全部参与布局与绘制，滚到才渲染；声明内在尺寸是为了滚动条不跳。
3. **`decoding="async"`** 加到栅格与桌面行两处 `<img>`，解码移到主线程之外，避免大图解码卡住滚动与点击。

**为什么不顺手生成缩略图**：抽样附件的 `_wp_attachment_metadata['sizes']` 是空数组——该站在上传阶段就不生成任何派生尺寸（缩略图模块把尺寸全关了）。前端因此没有更小的图可换。要真正减流量得先恢复派生尺寸生成，那是另一个决定（涉及磁盘与备份），不在本次「少用数据库」的范围里，已在结论中告知用户。

**EXPLAIN 观察**：根目录列表（`folder_id=0`）走 `PRIMARY` 全索引扫描 62 行（`Using where`），因为 `lifecycle='active'` 不在复合索引前缀里但排序按 `f.id DESC`；进入具体文件夹时命中 `owner_folder_lifecycle`（`ref`，4 行）。数据量在这个量级（130 行）时两者都远低于 1ms，暂不重建索引——复合索引 `(owner_id,folder_id,lifecycle,id)` 已存在，只是优化器对根目录这条更愿意用主键顺序免排序。

**验证**：`items` 3 条 / `summary` 2 条，四类过滤（根目录、搜索、类型、分页）与文件夹视图、越界 `cursor`、不存在的 `folder_id`（仍 404 `sufm_folder_missing`）全部通过，计数与直接 SQL 逐项一致；浏览器实测 `/user/files`：列表 40 行、栅格 48 卡（含 8 个文件夹）、`content-visibility: auto`、`decoding: async`、右键菜单可开、搜索可用、无 `pageerror`；`phpunit` 388 tests / 1089 assertions（5 skipped）全绿。

## 搜索索引：mime_group 冗余 + 二元组倒排（schema 1.5.0 / 1.6.0）

用户要求「先把 mime_group 冗余掉（分类页去 JOIN）→ 再上 bigram 倒排做搜索」。两件事分开上线，每一步都先用同构 bench 表（`nb_bench_files` / `nb_bench_posts`，5 万行/用户，MariaDB 12.3.2，测完 DROP）量过再写代码。

**先测量**（5 万行、单用户、全库搜索）：

| 方案 | 耗时 | 结论 |
| --- | --- | --- |
| 现状 `display_name LIKE '%x%'` | 50–81ms | 基线 |
| `display_name` 加覆盖索引 | 80ms | 无效，前导通配走不了索引 |
| 派生表 `GROUP BY file_id HAVING COUNT(*)=N` | 217ms | 更慢（派生表约 57 万行） |
| 上述 + `FORCE INDEX (PRIMARY)` | 159ms | 仍慢 |
| 派生表前置 + `STRAIGHT_JOIN` | 78.6ms | 与 LIKE 持平 |
| FULLTEXT + 二元组转十六进制（绕最小词长） | 125–144ms | 更慢，弃用 |
| **稀有 token 预筛 + 单 token join** | **0.2–5ms** | 采用 |

1. **`mime_group` 落列（1.5.0）**。分类筛选原来要 JOIN `wp_posts` 读 `post_mime_type`，加 `mime_group varchar(16)` 与 `owner_group_lifecycle(owner_id,lifecycle,mime_group,id)` 后只听本表；`Files::mime_group_for()` 是唯一推导入口（`image/`、`video/`、`audio/` 用前缀，文档/压缩走 `type_groups()` 白名单，其余 `other`）。
2. **倒排表而不是 FULLTEXT**。MariaDB 没有 ngram 解析器，InnoDB FULLTEXT 的最小词元长度是 3，2 字中文二元组会被直接丢掉；把二元组转十六进制确实能塞进去，但实测比 LIKE 还慢（FTS + filesort 开销）。
3. **先探针、再决定走不走倒排**。`SELECT token,COUNT(*) ... GROUP BY token` 全量计数要 43–48ms，比 LIKE 还贵；改成有界探针 `SELECT COUNT(*) FROM (SELECT 1 FROM tokens WHERE token=%s LIMIT 1500) p`（约 0.75ms/token，最多探 4 个），只有命中「稀有」（<1500 条 posting）才用倒排，否则直接 LIKE。
4. **LIKE 始终保留**。二元组 AND 命中会有假阳性（`扫描X全能` 同时含 `扫描/描全/全能`），所以倒排只负责缩小候选集，`display_name LIKE '%原词%'` 仍作精确校验；候选集很小，代价可忽略，换来的是结果永远和以前一致。
5. **readiness 护栏**。`token_index_ready()` 要求 schema 版本一致且 `MAX(sufm_files.id) <= sufm_tokens_after`（水位选项），不满足就整条搜索退回 LIKE —— 批量导入或旁路写入的行不会「藏起来」搜不到。
6. **回填与扫尾**。install 期分批（20 × 500），`sufm_tick` 每分钟再扫一轮（水位法，每行只处理一次），`wp sufm backfill` 一次跑完 mime_group + tokens；`set_lifecycle('purged')` 同时删该文件的 token；uninstall 清 `sufm_backfill_pending` / `sufm_tokens_pending` / `sufm_tokens_after`，表由 `Schema::tables()` 统一 drop。
7. **写入路径**。`create()` 与 `rename()` 内联同步 token（`sync_tokens()` 先删后插、`INSERT IGNORE`）；`Media_Mover` 等纯 update 路径不重复实现，靠 tick 扫尾覆盖。

**验证**：`Schema::maybe_install()` 幂等（1.6.0、`after=165`）；`nb_sufm_file_tokens` 2498 行 / 134 个文件全部有 token；REST 冒烟 `扫描`→1（深层 `测试文件夹` 内）、`21`→4、`jpg`→1、`jjpg`→0（错误组合被 LIKE 滤掉）、`a`→5、`2026`→5，每次 2–3 条 SQL；前端 `/user/files` 与写作面板搜索均正常，面包屑显示 `全部文件 / 搜索「…」` 且文件夹区隐藏。最坏情况（无稀有 token）与改前同价，另加 ≤3ms 探针成本。

## 「全部文件」= 根目录；搜索与分类才跨目录

**历史**：先按「整个文件柜」实现过一版。用户随后反馈：把文件整理进虚拟文件夹后，「全部文件」仍然把它们列出来，「整理看起来根本没生效」（2026-11-07）。当次改回根目录语义。

**定案**

1. **根视图只列根目录**。`items()` 的 `scope` 只在「有搜索」或「有分类」时为 `all`；根视图与进入子目录一样，只查 `folder_id` 的 active 文件。归档进文件夹的文件从根列表消失，这是整理生效的唯一信号。
2. **搜索/分类跨整个文件柜**。一次搜索要能找到深层文件，所以这两种请求一律 `all_folders`，并且不返回文件夹卡片；列表项带 `folder` 字段，前端追加文件夹名（`.sufm-in-folder`）。
3. **数字说真话**。根视图首屏的 `total` 取 `Files::count( $owner, 0 )`（当前目录总数，`cursor = 0` 时才查），前端显示「71 个文件」而不是「已加载 40 个」；翻页、筛选、子目录都不付这笔 COUNT。
4. **面包屑不重复**。根视图不追加 scope chip；搜索时显示 `全部文件 / 搜索「…」`，分类时显示分类名。
5. **文件夹是归档，不是副本**。一个文件只能在一个文件夹里；移动就是移动，根目录与文件夹不会同时出现同一条记录。

## 不接入 MeiliSearch 做文件检索（2026-11-07 评估，暂缓）

用户提问「服务器上装的 MeiliSearch 能不能加速文件检索」。结论：**能接，但现在不接**；先记录实测与触发条件，真有需求再启动。

**本机实例实测**（只读探测，未改任何配置）：

| 项 | 实测 |
| --- | --- |
| 版本 | meilisearch 1.55.0，`/www/wwwroot/MeiliSearch/meilisearch`（357MB），`data.ms` 31MB |
| 进程 | `www` 用户常驻，RSS ≈736MB；仅监听 `127.0.0.1:7700` 与 `[::1]:7700` |
| 对外 | nginx `other_meilisearch.conf` 以 `ms.77il.cn` 反代到 7700（带 SSL），公网可达、靠 master key 鉴权 |
| 索引 | `www_nboke_com_posts` 41 文档（站内文章搜索在用）；`demo_77il_cn_posts`、`www_77il_cn_posts` 均 0 文档 |
| 启动 | `/etc/systemd/system` 无对应 unit、root crontab 无条目 —— 重启后不会自动拉起 |

**语义实测（关键）**：Meili 对中文做分词，只在「整词 + 前缀」层匹配，**不支持任意子串**。

| 查询 | 结果 | 含义 |
| --- | --- | --- |
| `媒体工具` | 2 条 | 分词有效 |
| `Saku` | 1 条 | 前缀匹配有效 |
| `体工` | **0 条** | 词中间片段搜不到 |

文件名是 `20261106-233155-aQ4zj.webp` 这类机器生成串，用户常搜中间片段（`Q4zj`、`5KV`）。要在 Meili 上支持，只能自己把名字预切成二元组塞进字段——等于把现有 bigram 索引搬进另一个进程再加一层 HTTP，收益为零。

**速度账**：现状 `bigram 倒排 + 稀有 token 预筛` 是 0.2–5ms（最坏退化 LIKE 50–80ms/5 万行）；Meili 引擎 0–1ms，但从 PHP 发 HTTP 端到端约 2–4ms。**同一量级，换过去最多打平。**

1. **不接入的第一理由是多一个真相源**。`sufm_files` 现在是唯一真相；接入就得在 `create / rename / set_lifecycle(purged) / 移动` 每个写路径同步，并重建「同步落后」的水位——和 tokens 回填同类工程，但要多维护一个外部服务（还无 systemd 守护）。
2. **多租户过滤是安全边界**。每个用户只能搜到自己的文件，必须 `filterableAttributes: [owner_id]` 且每次查询带 filter；漏一次即越权。
3. **必须有降级路径**。Meili 不可用时不能让搜索变空，沿用 `token_index_ready()` 的思路：探活失败自动回退现有 SQL。
4. **密钥不能出现在前端**。前端只走 `sufm/v1`，key 只存在于服务端；索引里只放 `id / display_name / folder / mime_group / owner_id`，不放大字段。

**将来的触发条件**（任一成立再启动，届时按派生缓存实现）：

- 要搜**正文内容**（PDF 抽取文本、OCR）——这是 LIKE 与二元组都做不到的；
- 需要**错字容忍 / 拼音**检索；
- 单站文件量到 **20 万+**，SQL 退化路径不可接受；
- 想做**统一全站搜索**（文章 + 帖子 + 文件 + 页面），复用同一实例。

接入时的正确姿势：DB 仍是唯一真相，Meili 只是派生缓存；`wp sufm reindex` 全量重建且可重复执行；同步水位可观测；服务不可用时自动回退 SQL；owner 过滤在服务端强制。

**顺带记一条运维待办（未执行）**：master key 目前写在进程命令行里（`ps` 本机可见），叠加 `ms.77il.cn` 公网反代，建议后续改为 0600 配置文件或环境变量注入，并给该 vhost 加 IP 白名单 / 仅内网；同时补一个 systemd unit 或宝塔守护，避免重启后搜索服务静默消失。

## 空间扩容支付收口与对账（2026-11-08，2026-11-09 补齐）

**背景**：空间扩容订单原来走 Zibll 的 `payment_order_success`，`Space_Store::grant_from_order()` 把 `source`/`channel` 固定写成 `'points'`，并把 `order_id`/`order_num`/`price`/`points` 清零。现金（含余额）购买因此被记成积分兑换，既污染订单/收入字段，也会占用 `points_used_bytes()` 的积分日限额。与此同时，主题对所有支付方式共用一个成功钩子，插件无法从钩子上区分余额与外部扫码。

**决策**：

1. **空间扩容只开放后台勾选的支付方式，默认仅余额**。默认值在 `Space_Settings::defaults()` 的 `allowed_methods = ['balance']`（`src/UserFiles/Space_Settings.php:71`）；`get()`（`:170-171`）对已存值整体覆盖，避免 `array_merge` 把数字索引追加成两项；`allowed_methods()`（`:265`）读取并规范化该设置；`payment_methods()`（`Space_Store.php:194`）末尾经 `filter_payment_methods()`（`:249`）与主题通道取交集，`purchase()`（`:311`）再用过滤后的 map 做服务端硬校验（`:347-351` 返回 `sufm_space_method_missing`）。`sufm_space_allowed_payment_methods` 过滤器只能继续收窄，不能重新启用未勾选通道；外部扫码必须由管理员在「支付方式」卡片勾选，风险自担。
2. **订单发放按渠道记账**。`normalise_order()`（`:999`）解析 `points`（`other.points` 优先，`pay_detail.points` 次之，积分渠道可用 `round(price)` 回退）与 `pay_detail`；`grant_ledger_fields()`（`:846`）输出 `source/channel/order_id/order_num/price/points`；`grant_from_order()`（`:881`）参数化 INSERT（`:948-966`）。余额单写 `purchase/balance` 且订单字段齐全；积分单写 `points/points` 且保留积分；其他渠道写 `purchase/<pay_type>`。
3. **保留防重复发放**：积分直兑会创建 `sufm_space_points` 订单并触发同一个成功钩子；该订单的 `plan('points')` 不存在、`bytes=0`，`grant_from_order()` 返回 `false`，不会重复发放。任何改写都必须保留这条断言。
4. **退款按订单渠道补偿钱包**。`order_refunded`（`Space_Store::register_hooks()`，`:3014`）→ `on_order_refunded()`（`:1268`）先把匹配 grant 原子置 `refund_pending`，再释放配额，然后 `settle_refund()`（`:1407`）：`balance` 加 `order_price`，`points` 加积分（缺失时 `round(price)`），其他渠道只释放授权。成功置 `refunded`；失败 `attempts+1`，满 5 次置 `refund_failed`。重试由 `retry_refunds()`（`:1672`）在 tick 中执行，先用条件 UPDATE 抢占订单，避免两个 worker 重复加钱。
5. **退款查重使用主题 meta 路由**。`wallet_refund_recorded()`（`:1632`）优先 `zib_get_user_meta()`，检查同 `order_num`、`type='space_expand_refund'`、`value>0` 的记录，覆盖「钱包已加钱、grant 状态未写回」的崩溃窗口；主题记录最多 50 条且无唯一键，这是弱幂等边界。
6. **每日自动对账改为租约 + 指数退避**。`maybe_reconcile()`（`:1838`）先读 `reconcile_state()`（`:1932`），未到 `next_attempt` 直接返回；到点先写租约（`last_attempt=now`、`next_attempt=now+900`）再调用 `reconcile(true,100)`（`:2173`）。成功且 `missing<=healed` 且没有更多页时写 `last_success`、`failures=0`、`next_attempt=now+86400`；成功但仍有更多页时 15 分钟后继续排空；失败或 `ready=false` 时 `failures+1`，按 `RECONCILE_BACKOFF`（`:48`，900/1800/3600/7200/14400/21600 秒）取 `reconcile_delay()`（`:1995`），封顶 6 小时。`save_reconcile_state()`（`:1971`）把五项状态写回 option `sufm_space_reconcile_last`；旧的标量时间戳会迁移为 `last_attempt=旧值`、`next_attempt=旧值+86400`。`space.reconcile.alert` 每天最多一条，写 `last_alert`。手动 REST 对账保留。
7. **面板空态**。`Space_Panel::build_data()` 用 `cash_ready = 现金套餐非空 && 支付方式非空`（`src/UserFiles/Space_Panel.php:137`）与 `points_ready = points_state.enabled && 积分套餐非空`（`:141`）；两者都 false 才不渲染。无现金时默认积分，`confirmPurchase()` 在积分模式下不建现金单。
8. **支付方式与积分入口由后台显式开关**。管理员在「支付方式」卡片勾选主题通道（默认仅 `balance`），全部取消勾选即关闭现金收银台；`Space_Settings::sanitize()`（`:386`）用 `allowed_methods_present` 区分「全部取消」与「表单未提交该字段」，`sanitize_methods()`（`:770`）负责去空去重。积分入口由独立的 `points[enabled]` 控制，`points_enabled()`（`:280`）读取开关，`points_state()`（`Space_Store.php:3050`）同时要求开关打开且单价大于 0，`redeem_points()`（`:3221`）在关闭时返回 `sufm_points_off`。不能再靠清空积分套餐或把单价设为 0 来「关闭」积分。
9. **历史订单授权只审计、按需回填订单字段**。`audit_legacy_order_grants()`（`:2326`）只查 `grant_key LIKE 'order:%'` 且 `order_id=0` 或 `order_num=''` 的行，逐行交给 `audit_legacy_order_grant()`（`:2414`）；校验订单存在、已支付、owner 一致、商品前缀 `sufm_space_`，再按当前渠道规则重算字段。默认 dry-run；`wp sufm grants --apply` 才写入，且只更新 `order_id/order_num/price/points/source/channel/updated_at`，绝不改 `bytes/expires_at/state/label/grant_key/meta`。找不到订单/订单未支付/owner 不符/商品不符/字段不一致分别返回 `missing_order`/`order_not_paid`/`owner_mismatch`/`not_space_product`/`key_mismatch`，不做猜测。
10. **`refund_failed` 仍人工处理，不自动复活**。退款重试只处理 `refund_pending` 且 `attempts<5`（`:1672`）；第 5 次失败置 `refund_failed` 并写 `space.grant.refund_failed`（`:1522`）。主题订单已置 `status=-2`，不会再次触发 `order_refunded`。自动复活需要订单级互斥和钱包幂等键；当前主题钱包没有唯一键，强行自动可能重复加钱，因此保留人工确认入口。

**测试与运行证据**：

- 2026-11-08 单测 `SpaceStorePaymentTest` / `SpaceStoreRefundTest` / `SpaceRulesTest` = 20 tests / 69 assertions；余额退款 100→130（+30），重复回调不再加钱；积分退款 520→610（+90）；崩溃窗口预写退款记录后不重复加钱；外部 `alipay` 只释放授权；失败重试 `refund_pending/attempts=1` 恢复后到 `refunded/attempts=2`，连续 5 次失败置 `refund_failed`；面板四态 8251 / 6408 / 6195 / `null`。
- 2026-11-09 支付设置 UI：1440 宽两列，1024/782 宽单列，无横向溢出；只提交积分开关时价格保留（777/11/22 实测），全部取消现金后 `allowed_methods=[]`，前台无现金入口；恢复后 `['balance']`。
- 2026-11-09 对账运行期：旧标量 `1794152631` 迁移为 `recon_next=1794239031`；成功后 `next=+86400`；同进程临时重命名 grants 表制造失败，`failures=1`、`next=+900`、`last_alert` 写入且立即再 tick 被租约挡住；第二次失败 `failures=2`、`next=+1800`、无第二条告警。
- 2026-11-09 历史回填：测试站合成 `order:9900000000000000001` + grant id18，dry-run 返回 `pending`，`--apply` 返回 `filled`，库中变为 `purchase/balance/order_id=21/order_num=9900000000000000001/price=30.00/points=0`，`bytes/state/label` 不变；第二次运行 0 candidates；`points:` 授权不被选中。测试数据与 option 已清理。
- 2026-11-09 质量门：PHPUnit 423 tests / 1257 assertions / 5 skipped；PHPStan `[OK] No errors`；PHPCS 177 files 0 errors / 0 warnings。
- 生产只读核对：`nb_sufm_space_grants` 4 行，其中 `order:` 行字段完整、2 行 `points:` 是直兑回退无主题订单；当前待回填现金行为 0，因此部署后只跑 dry-run。

**已知边界**：

- 预充值余额仍由主题的支付宝/微信充值产生，站点仍是收款方；退款、发票、消费者权益与资金合规责任不因「空间只收余额」消失。本决策是支付入口收口，不是零风险。
- 外部渠道退款不代付，必须在主题/支付平台完成；插件只释放授权。
- 主题 `balance_record`/`points_record` 最多 50 条且无唯一键，查重可能被挤出；不是强幂等。
- `refund_failed` 不会自动复活，需要人工处理；`wp sufm grants` 默认 dry-run，历史回填必须由操作员确认。
- 对账失败按 15 分钟到 6 小时退避，不保证立即重试；告警每天最多一条。旧的标量水位迁移后会先等一个 24 小时窗口。
- 历史回填只处理 `order:` 且订单字段缺失的行；`points:` 直兑没有主题订单，保持原样。
- 积分入口由 `points[enabled]` 独立控制，但已售出的积分授权不受关闭影响。

**维护动作**：改支付/发放/退款/对账代码必须同步 `tests/Services/SpaceStorePaymentTest.php`、`SpaceStoreRefundTest.php`、`SpaceStoreReconcileTest.php`、`tests/SpaceSettingsTest.php`，跑 `phpunit`/`phpstan`/`phpcs`，并按《图谱同步说明》重扫图谱。
