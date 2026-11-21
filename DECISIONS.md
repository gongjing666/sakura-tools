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
- 两条轨道都清的是**运行期残留**，不是用户数据：定时任务、能力、`sio_*` 表、锁（`sakura_imgopt_stats_lock` / `sakura_imgopt_upgrading` / `sakura_imgopt_lock_%`）、`sakura_imgopt_` 与 `sufm_` 命名空间下的 transient（含 `_transient_timeout_`）。保留它们只会让重装后的旧锁与过期缓存继续生效。
- 外观设置 `sakura_imgopt_ui_style` 与按管理员存的提示状态 usermeta 属于设置，只随"删除数据"一起删。

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
| 版本 | meilisearch 1.55.0，单二进制约 357MB，数据目录 31MB |
| 进程 | 以 Web 用户常驻，RSS ≈736MB；仅监听 `127.0.0.1:7700` 与 `[::1]:7700` |
| 索引 | 一个站点的文章索引 41 文档（该站文章搜索在用），其余索引为空 |
| 启动 | 无 systemd unit、无 crontab 条目 —— 重启后不会自动拉起 |

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

**自托管 Meili 的部署要点**（通用，接入前先满足）：master key 不要写在进程命令行里——本机任何用户 `ps` 都能看到，应改为权限 0600 的配置文件或环境变量注入；实例若要跨机访问，前面加反向代理并配 IP 白名单，默认只监听 loopback；补一个 systemd unit 或等效守护，否则重启后搜索服务会静默消失，而依赖它的前台查询只会表现为「搜不到东西」。

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

## 上传目标指纹以「桶身份」为准，profile 行 id 不参与（1.28.1-beta-5，2026-11-16）

**背景**：同一家厂商、同一个桶、同一套凭据存成两条配置（换服务商测试后新建的那条，就是原配置的副本），切换后 130 张已同步附件的 CDN 地址全部回退成站点域名；同步中心的附件详情跟着加载站点域名；缩略图「立即测试」报「服务器返回 404，请求被拒绝。」。

**决策**：

1. **上传指纹 = 目的地**，不再包含配置行 id。`Synchronous_Uploader::target_hash_for()`（`src/S3/Synchronous_Uploader.php:496`）现在算 `sha256("v2|provider|bucket|endpoint|upload_path")`。两条配置指向同一个桶就是同一个目标；换了桶、换了 endpoint、换了对象前缀仍然是换目标。
2. **旧指纹继续被接受，不重写全站 post meta**。`target_hashes_for()`（`:519`）返回当前身份加每个已知配置行的旧写法别名（`sha256(profile_id|provider|bucket|endpoint|upload_path)`），`target_matches()`（`:554`）按集合判断。生产存量 130 张附件写的是旧值，靠别名命中，立即恢复 CDN 地址。
3. **判定点统一走 `target_matches()`**：`Storage::revert_unsynced_url()`（`src/S3/Storage.php:677`）、`Synchronous_Uploader::finalize()`（`:226`）、`Action_Worker::run()`（`:102`）与 `write_back()`（`:235`，多收一个 profile id，用于队列行所属配置已被删除的情形）、`History_Migrator::run_batch()`（`:158`）。任何一处改回 `hash_equals` 都会把同桶换配置重新判成换桶。
4. **上传侧与删除侧仍然不互比**。`Delete_Jobs::target_hash()`（`src/S3/Delete_Jobs.php:61`）继续算 `provider|bucket|endpoint|region`，语义是「这条删除任务属于哪个桶」，改了会让 `sio_s3_deletes.target_hash` 全部 drift。本次只改上传侧。
5. **预览与探测用媒体域名**。`Sync_Center_Module::preview_payload()`（`src/S3/Sync_Center_Module.php:1577`）对已同步的公开对象优先给 CDN 地址；`Probe::sample()`（`src/Thumbnail/Probe.php:377`）优先取已同步附件，并把取样 URL 经 `Storage::map_local_to_cdn()`（`src/S3/Storage.php:734`）换到 CDN 域名。命名样式 `/siothumb` 只在 CDN 域名存在，用站点域名探测必然 404。

**已知边界**：别名表只覆盖「当前站点还知道 id」的配置行。队列行里记录的 profile 已从 `s3_options.profiles` 删除时，靠 `target_matches()` 的第三个参数（队列行自己的 profile id）兜住；两者都拿不到时按换目标处理，宁可停止动作也不写错桶。

**维护动作**：改 `target_hash_for()` 的字段集合必须同时改 `target_hashes_for()` 的旧别名构造，并跑 `tests/TargetFingerprintTest.php`（6 条）与 `tests/StorageUrlGateTest.php`（含 `map_local_to_cdn` 两条）。

## 换桶后 URL 用同步索引自愈，指纹纠正不重启同步时钟（1.28.1-beta-6，2026-11-17）

**背景**：从腾讯云切到缤纷云（真正的换桶）后，130 张已同步附件的地址全部退回站点域名，而不是 `bf.nboke.com`。上一版（beta-5）只解决了「同一个桶存成两条配置」，换桶这一步仍然按「不是同一目标」把 URL 拉回本地。

**根因**：`Storage::revert_unsynced_url()`（`src/S3/Storage.php:660`）只相信附件 post meta 里的目标指纹（`_sakura_imgopt_s3_target`）。换桶后指纹必然不匹配，于是 `should_keep_local()` 判定回退；而切换配置（`Profile_Manager::switch_profile()`）根本不碰 post meta，所以没有任何路径把指纹改写成新的，URL 永远不会自愈。生产实测证明对象其实已经在缤纷云桶里：`nb_sio_sync_index` 中 130 行对该 profile 全部 `status=synced`、`seen_local=1`、`seen_remote=1`、`local_size=remote_size`，抽查 10 个 key 两个 CDN 都返回 200。

**决策**：

1. **指纹不匹配时用同步索引作证据，而不是直接回退本地**。`confirmed_in_active_bucket()`（`:711`）先走 `target_matches()` 快路径（同桶别名，零查询）；不命中且附件有本地文件时才进 `index_confirms()`（`:742`）：把该附件 `_sakura_imgopt_s3_objects` 记录的每个 key，到 `nb_sio_sync_index` 里按当前活动 profile 查行，任何一行不是 `synced` 就判不确认，全部是 `synced` 才认为对象确实已经在新桶，保留 CDN 地址。
2. **`status='synced'` 这一条就够，不再自己比大小**。`Index_Repository::reconcile()`（`src/Sync/Index_Repository.php:858`）的 UPDATE 已经保证 `synced` 蕴含 `seen_local=1 AND seen_remote=1 AND local_size=remote_size`；重复判断只会引入两套口径。
3. **确认后一次性改写指纹，把慢路径收敛回快路径**。`Sync_Status::retarget()`（`src/S3/Sync_Status.php:95`）只写 `_sakura_imgopt_s3_target`，**不动 `_sakura_imgopt_s3_synced_at`**。不能用 `mark_synced()`（`:69`）：它会刷新同步时间，界面会显示成「刚刚重新上传过」，而实际上一个字节都没上传。改写之后 `wp_get_attachment_url` 回到纯 post meta 判断，不再查库。
4. **每请求缓存 miss 与 hit 两种结论**。`Storage::$index_confirmed` 保证一张附件在一个请求里最多查一次索引；命中后因为指纹已改写，下一个请求走快路径。
5. **开关与未装索引都退回旧行为**。`Sync\Feature::enabled()` 为假（同步索引被关闭）、或 `Sync\Schema::VERSION` 与 `sakura_imgopt_sync_schema` 不一致（表没建）时 `index_confirms()` 直接返回 false，行为与 beta-5 完全一致：换桶即回退本地域名。宁可显示站点域名，也不要在没有证据时把 URL 指向可能不存在的对象。
6. **对象 key 允许多种写法**。`key_candidates()`（`:802`）接受完整对象键、去掉 `ABSPATH` 的相对路径、补上 `upload_path` 前缀的写法，因为历史数据里 `_sakura_imgopt_s3_objects` 三种形态都出现过。生产现存的是完整对象键。

**已知边界**：索引只能证明「扫描看到的云端对象」，不能证明对象可公开读取；CDN 域名本身是否可用仍由 `Probe` 那一侧验证。索引被 `Settings_Saver` 在换桶保存时 `purge_index()` 清空后，在下次扫描完成前，URL 会退回站点域名——这是有意的降级，不是回归。

**维护动作**：改索引判定必须同时跑 `tests/StorageBucketSwitchTest.php`（11 条，含「指纹改写后同步时间不变」「索引缺证据时回退本地」「索引功能关闭时回退本地」「索引未安装时不查库」）与 `tests/StorageUrlGateTest.php`（11 条）、`tests/SyncStatusTest.php`（11 条，含 `retarget()` 不刷新时间）。

## 启用开关只由配置卡片写入，保存表单不再决定 S3 开没开（1.28.1-beta-7，2026-11-17）

**背景**：「启用 S3 存储」原本是连接设置卡里的一个复选框，与「用哪一份配置」分开存放。两个控件可以各自为真，于是出现半应用状态：站点图片地址已被改写、上传却仍在暂停；或反过来，配置看着在用、地址却指向站点域名。beta-1 修换服务商覆盖、beta-6 修换桶 URL 也是同一个形状——状态没被显式建模，只能靠填表时顺手带过去。

**决策**：

1. **一张卡片就是一次操作意图**。点非活动卡片 = 切换并启用（`Profile_Manager::enable_profile()`，`src/S3/Profile_Manager.php:49`）；点当前活动卡片 = 关闭但保留 `active_profile_id`（`:62` 的 `disable()`）。三者共用私有 `activate( $id, ?bool $enabled )`（`:87`），`switch_profile()`（`:35`）只是 `$enabled = null` 的别名，保留给不接受开关语义的调用方。
2. **`Settings_Saver::save()` 不再读 `$_POST['enabled']`**，一律沿用库里旧值（`src/S3/Settings_Saver.php:237`）。保存表单只负责配置内容，启停与切换都不归它管。
3. **AJAX 就地重绘，不整页刷新**。`wp_ajax_sakura_s3_toggle_profile`（`includes/class-s3-storage-module.php:82`，处理函数 `:146`）校验 `manage_options` 与 `sakura_s3_toggle_profile` nonce 后，回传 `S3_Storage_Page::state_payload()`（`src/S3/S3_Storage_Page.php:720`）——卡片深浅、徽章、删除按钮文案、顶部状态卡一次往返全换，前端不重新取页面。
4. **保存非活动配置不动活动镜像**。`keep_live_mirror()`（`src/S3/Settings_Saver.php:145`）保留 `MIRROR_FIELDS`（`:127`：provider/bucket/space/region/endpoint/key/secret/object_acl/upload_path/upload_url_path）。这些键同时驱动上传、状态卡与附件 URL 改写，让一份没在用的配置覆盖它们等于把整站点指向没人选过的桶。
5. **连接卡回归「新增 + 编辑」**。删掉复选框后，连接卡只剩新增与回填两种用途；表单默认留空，「新增账户配置」会清空残留的 `profile_id`，避免「新增」变成「覆盖」。
6. **停用提示跟着 `enabled` 走**。`Config_Notice::should_prompt_paused()`（`src/S3/Config_Notice.php:105`）在 `is_enabled()` 为真时不再提示，卡片是唯一开关后，这条提示的语义才与界面一致。

**已知边界**：`enabled` 与 `active_profile_id` 仍然可以各自独立地写进 option（例如 CLI 直接改库），`disable()` 不清 `active_profile_id` 是刻意的——重新点回那张卡片不该要人重选桶。

**维护动作**：改开关语义必须同时跑 `tests/ProfileManagerTest.php`（含 `switch_profile()` 不改 `enabled` 的回归断言）与 `tests/ConfigNoticePausedTest.php`；`state_payload()` 增删字段要让前端 `initProfileToggle()`（`assets/ui/js/pages/storage-connection.js:579`）同步。

## 连接表单不接受空桶名：宁可退回，也不要存出半份配置（1.28.1-beta-8，2026-11-17）

**背景**：beta-7 把连接卡改成「新增配置」的空白表单后，直接点保存会拿顶层凭据存下一份没有桶名的配置，卡片永久停在「配置不完整」，还会占住 `profiles` 里的一行。

**决策**：`Settings_Saver::save()` 在 `'' === $bucket_raw` 时立即 `redirect( array( 'saved' => '0', 'error' => 'bucket' ) )`（`src/S3/Settings_Saver.php:201-202`），错误文案指向「去点已保存的配置小卡片」。空白表单的默认保存是误操作，不是半成品草稿——草稿只在**编辑**一份已有配置时才允许不完整。

**已知边界**：「活动且启用的配置必须完整」是另一道守卫（`:353`，`error=config`），两者不重叠：前者拦新增，后者拦切换/启用。

**维护动作**：放宽这条守卫等于允许 `profiles` 里出现无桶名行，届时所有按 `bucket` 过滤的渲染（`Options::saved_profiles()`）都要同步处理。

## 保存一份没在用的配置，不能把活动凭据写成明文（1.28.1-beta-9，2026-11-17）

**背景**：beta-7 加 `keep_live_mirror()` 时，回填来源是 `Options::all()`——那是解密后的视图。于是每次保存非活动配置，顶层 `key`/`secret` 都以明文写进 `s3_options`，下一次请求才由 `Credential_Cipher::migrate_legacy_option()` 重新加密。表现有两层：明文落库的窗口确实存在；即使不看安全，sodium 每次用新 nonce，顶层密文每次都变，逐字段 diff 无法判断是否真的改了凭据（beta-9 验收就是靠 diff 只有一条 `saved_at` 才证明保存没副作用）。

**决策**：`keep_live_mirror( array $stored, array $raw )` 的第二个参数改成**从 `get_option()` 取到的原始值**，密文原样搬过去，绝不经 `Options` 的解密视图。逻辑从 `save()` 内联抽成独立静态方法，便于单测（`tests/SettingsSaverTest.php` 两条用例：密文原样、缺键不回填）。

**已知边界**：这条规则只保护「非活动配置保存」这条路径；`save_behaviour()`、`Profile_Manager::activate()` 写的是各配置自己的字段，本来就不是密文视图。

**维护动作**：任何「把 option 里的值搬到另一个键」的新逻辑，来源必须是 `get_option()` 的原始数组，不是 `Options::all()`。

## 配置文件卡片直接跑连接测试，不改活动配置也不写库（1.28.1-beta-10，2026-11-17）

**背景**：已保存的配置卡底部原本只有一枚通栏「删除此配置」。想确认「这份配置还活着吗」的唯一办法是切过去、传一张图、再看结果——而切换本身会改活动配置与站点图片地址，是一个有副作用的诊断手段。

**决策**：

1. **只读探测**。`wp_ajax_sakura_s3_probe_profile`（`includes/class-s3-storage-module.php:81`，处理函数 `:366`）只收 `profile_id`，用该配置自己的密钥建客户端跑 `Connection_Probe::diagnose()`（`src/S3/Connection_Probe.php:51`），全程不切配置、不写 option、不触发上传。
2. **快照是密文，必须先过 `Options`**。`Options::profile_options()` 返回的是库里的原始快照（`key`/`secret` 仍是密文），而 `Client_Factory::make()` 直接从数组读 `key`/`secret` 去签名。因此处理函数先 `new Options( $snapshot )->all()` 解密再交给工厂——否则会把密文当 Access Key 使用。多吉云不走这一步：工厂把 `credentials` 传成闭包，临时凭据在真正签名时才取（见本文件「临时凭据必须按需续期」条）。
3. **慢但可用要单独说**。往返超过 800ms 报「能用但偏慢」，避免一次跨地域重试被读成健康；失败与缺字段分别给「找不到这份配置」与 `missing_fields_message()` 的原文。
4. **按钮是删除表单的兄弟节点，不是 `<button type="submit">`**。放表单里点测试会提交删除。样式上两按钮等分卡片宽度的两列网格（`assets/ui/pages/storage.css:156` 起），都带可见边框与底色，不用 `.btn--quiet`。

**已知边界**：探测用的是配置里存的凭据，密钥失效或桶已删除都会如实报错，但**不校验桶里是否还有本插件的对象**——那需要列举，属于核对而不是连接测试。

**维护动作**：改 `Connection_Probe::diagnose()` 的返回结构要同步前端 `initProfileProbe()`（`assets/ui/js/pages/storage-connection.js:1106`）的 tone 判定。

## 卡片动作按钮：等分两列、静止态就有边框、禁用态自己管透明度（1.28.1-beta-11 / beta-12，2026-11-17）

**背景**：两枚动作按钮原本都挂 `.btn--quiet`——透明底、透明边框、`--g-muted` 文字，读起来像说明文字而不是控件；宽度按文字收缩贴左侧，行宽 252px 里右侧空 57–82px。更麻烦的是子比主题 `admin-main.min.css` 有一条全局 `[disabled] { opacity: .6 !important }`，把「使用中不可删除」压到 60%，插件自己的 opacity 规则被 `!important` 盖掉，灰色禁用文字看起来像消失了一半。

**决策**：

1. **等分两列网格**（`assets/ui/pages/storage.css:156` 起：`grid-template-columns: minmax(0,1fr) minmax(0,1fr)`），按钮各占一半、文字居中，卡片左右不留空档。
2. **静止态就有可见边框与底色**：测试按钮 `--g-card` 底 + `--g-line-strong` 框 + `--g-ink-soft` 字（对比度 12.56），hover 才转强调色；删除按钮静止态即危险色（`--g-bad` 字 + `--g-bad-bg` 底 + `--g-bad-line` 框，5.64），hover 反白填满。**不用 `.btn--quiet` 承载需要被认出的控件**。
3. **禁用态写 `opacity: 1 !important`**（`:188` 起），覆盖主题那条全局规则——禁用的原因文案（「使用中不可删除」）必须可读，对比度 6.17。CSS 里留了注释说明为什么需要 `!important`，免得被当成冗余删掉。
4. **测试按钮必须是删除表单的兄弟节点**，不是 `<button type="submit">`，否则点测试会提交删除。

**已知边界**：主题升级若把那条 `!important` 改成更高特异度选择器，覆盖会失效；验收时量一下禁用按钮的实际对比度。

**维护动作**：这条规则已写进 `docs/开发规则/03-后台UI.md` §4，改插件卡片动作样式时对照那一节。

## 卡片点击不回填表单，表单只用于新增（1.28.1-beta-13，2026-11-17）

**背景**：配置卡片原本是一个提交表单（`sakura_s3_switch`），点一下整页往返；换成 AJAX 之后又出现新的歧义——点卡片到底是在「切换」还是「把这份配置载入下面的表单准备修改」？两种语义混在一个点击上，就会出现「我只是想切一下，表单却被填满了」。

**决策**：

1. **职责切开**：配置卡（`[data-sakura-role="profiles"]`）= 启用、停用、切换；连接卡里的 chip（`.profile-chip`）= 载入表单准备修改。点配置卡片不改写任何表单字段，实测点完 `profile_id` 仍为空。
2. **连接卡更名「添加存储配置」，kicker `NEW PROFILE`，保存按钮「保存为新配置」**（`src/S3/S3_Storage_Page.php:332-333`）。名字必须与它现在唯一的职责一致，否则「连接设置」这个名字会让人以为 S3 连接是在这里管的。
3. **服务商与其已存配置并到同一行**（`:396-413` 的 `.s3-provider-row` 两半结构）：左半是标签 + 下拉框 + 测试按钮，右半是「服务商的已保存配置」标签 + chip 列表。两半各自「标签 → 控件」竖排，标签同字号同粗细，控件落在同一条基线；chip 固定 `height: var(--g-ctl-h)` 单行显示，才能和 38px 下拉框等高。
4. **chip 列表不加 `max-height` / 内部滚动**。chip 是进入编辑的唯一入口，藏在滚动条后等于让人找不到；让它自然换行，左侧控件靠 `align-items: start` 钉在首行。
5. **没有已存配置时右列整体收起**（`.s3-provider-row.is-empty` 改单列、`hidden` 隐藏），避免右侧挂一个空标签。

**已知边界**：宽度断点里改 `grid-template-columns` 的媒体查询必须写在这条规则**之后**——同特异度下后写的赢，写在前面的会被基础规则整体覆盖，表现成「窄屏下选择框溢出自己的单元格」（beta-14 就是这么修的）。

**维护动作**：`.profile-chip` 的尺寸与 `.field__row` 里控件高度耦合（都取 `--g-ctl-h`），改任一处要在 1440 与 390 下同时看标签基线与控件等高。
## 撤回已发布的版本号时必须同时降低数据库里的版本标记（1.28.2，2026-11-17）

**背景**：这一批发版时先按 MINOR 语义发成了 1.29.0（提交 0504dd3、tag v1.29.0、Release、生产部署俱全），随后开发者拍板应走 PATCH，于是把三处版本号收敛为 1.28.2（提交 20cb55c）并重新发布。代码回退了，但生产数据库里的 `sakura_imgopt_version` 还停在 **1.29.0**。

**决策**：

1. **版本标记不跟着代码走，必须手工降下来**。`Upgrade_Guard::needs_upgrade()`（`src/Upgrade_Guard.php:55-61`）只在 `version_compare( $installed, $current, '<' )` 时返回 true。库里标记 1.29.0、代码 1.28.2 时比较为假，`Activation::run()` 再也不会执行——之后任何一次真正的升级（例如 1.28.3）都会因为「已装版本看起来更高」而跳过建表、补 option、排 cron。
2. **回滚动作清单里必须包含改标记**：`wp --allow-root option update sakura_imgopt_version <目标版本>`。这条已写进 `docs/开发规则/06-发布流程.md` §9。
3. **撤回版本号 = 删 Release + 删 tag + 改标记**，三件事一件都不能省。只删 Release 与 tag 只是止住了外部分发，站点内部的升级链是独立断的。

**已知边界**：`needs_upgrade()` 有意把「库里版本更高」当作降级（`upgrade_guard` 的注释里写明 *« A newer stored version is a downgrade and must not be rewritten »*），所以这个守卫**不会**自我纠正。它只在 `$installed` 为空时无条件返回 true，也就是说只有彻底删掉这个 option 才会强制重跑一次升级。

**维护动作**：发布前先确认版本号语义（PATCH / MINOR / MAJOR）再改三处数字——一旦 push 出 Release 就要走完整的撤回流程，代价比发版前多问一句大得多。

## 临时凭据必须按需续期，不在构造期取（1.28.2-beta-5，2026-11-18）

**背景**：多吉云只签发两小时有效的 S3 临时凭据（`POST https://api.dogecloud.com/auth/tmp_token.json`，`ttl` 上限 7200 秒，接口 80000 次/天，永久 S3 密钥只对存储量或日流量超过 10TB 的客户开放）。原来的 `Client_Factory::make()` 在构造客户端时取一次 token、塞进静态数组当永久凭据用。传一张图没事，但「全量重建 / 跨桶迁移 / 重试队列」这些真会跑过两小时的批量任务——尤其是 WP-CLI（`includes/class-s3-cli.php:45`）——会在中途突然全部失败。另一个方向的方案是挂 wp-cron 提前续期，但 WP-Cron 只在有请求时触发，命令行与低流量时段都不准，正是「最需要续期的时候不跑」。

**决策**：

1. **把 `credentials` 传成 callable，而不是数组**。`ClientResolver::_apply_credentials()`（`vendor/s3/vendor-prefixed/aws/aws-sdk-php/src/ClientResolver.php:677`）对 callable 原样放行，`Middleware::signer()`（同目录 `Middleware.php:186-190`）每次请求都会调它——这是 SDK 给出的正规挂载点，不需要我们自己起定时器。
2. **两条硬约束**：闭包必须返回 Promise（`Create::promiseFor()` / `Create::rejectionFor()`），且 `new Credentials( $key, $secret, $token, $expires )` 的第四个参数不能省。`memoize()` 不能用：它的 `REFRESH_WINDOW` 写死 60 秒（`CredentialProvider.php:63`），而我们要在剩余 30 分钟时就换。
3. **续期判定在 `S3_Doge_Cloud` 内部**：`REFRESH_MARGIN = 1800`（`includes/class-s3-doge-cloud.php:26`），`response()` `:257` 先看缓存剩余有效期，够用就直接返回、不发请求。缓存 TTL 不再像旧代码那样扣 60 秒——那等于把「安全窗口」从缓存里削掉，续期失败时连退路都没有。
4. **续期失败要能降级，不能一失败就整站传不上去**。旧凭据还没过期 → 静默继续用，只写日志；真的过期了才返回 false，并且错误码是独立的 `sakura_s3_token_expired`，文案指向「续期失败」而不是「检查 Access Key/Secret」。失败后 60 秒内不再打接口（`RETRY_BACKOFF` / `arm_backoff()`），否则一次接口抖动会被每一次签名放大。
5. **「用户在等结论」的入口要显式预检**：连接测试、配置卡探测、同步中心测试在 `make()` 之后补一次 `Client_Factory::preflight()`。惰性化的代价是构造客户端不再联网，坏密钥不会在那里暴露；不预检的话失败会被推迟到第一次真实请求，并被 `Connection_Probe` 报成「Endpoint 没有响应」。

**已知边界**：

- 预签名 URL 也走同一条凭据链（`S3Client::createPresignedRequest` 会 `getCredentials()->wait()`），所以签出的链接同样受 `REFRESH_MARGIN` 约束；但**链接一旦签出就固定**，签出后凭据过期不会让已发出的链接失效（S3 签名只校验签名时刻的凭据有效性）。
- 缓存放在 transient；站点用外部对象缓存（生产是 Redis）时它不在 `wp_options` 里，排查时要用对象缓存命令而不是翻表。
- 退避窗口是进程外的 transient，但判断与写入之间没有加锁——多进程同时发现过期时会各打一次接口。80000 次/天的额度下这个量级不需要精确互斥，`add_option()` 在 WP 里也不是严格互斥的。
- 目前只有多吉云走这条路。其他厂商若将来也改短效凭据，直接复用同一套：工厂分支 + callable + `$expires` + `S3_Credential_Error` + preflight。

**维护动作**：改 `REFRESH_MARGIN` 要同时看服务商的最小 `ttl` 与任务的最长单次停顿；改 `Credentials` 的构造参数顺序要回看 AWS SDK 的 `CredentialsInterface`，第四个才是 `$expires`；新增入口若会「让用户等一个结论」，记得补 `preflight()`。
## 切桶之后同步中心封存，直到跑完整核对（1.28.2-beta-6 / beta-7，2026-11-18）

**背景**：从多吉云点另一张配置卡片切到缤纷云，页面只给了一条左下角 toast「已启用这份配置。」，同步中心照旧显示上一个桶的 132/132、100.0% 覆盖率、0 待处理——那些数字全是旧桶的结论，而用户看不出这一点。缩略图那边的提醒更晚：要刷新一次页面，才在顶部出现一条 WordPress admin notice。

**决策**：

1. **状态落 option，不落 transient**。`s3_options['reconcile_pending']`（形如 `array( profile_id, at, bucket, from )`）。transient 会过期，页面就会在某个时刻自己假装恢复；封存必须显式解除。`Options::reconcile_pending()`（`src/S3/Options.php:271`）返回 `null` 表示没封存（老站升级也走这条），返回数组表示封存中。
2. **置位与改指向在同一次 `update_option` 里发生**。`Profile_Manager::activate()`（`src/S3/Profile_Manager.php`）在 `$moved = self::target_moved( $previous_target, $raw )` 为真时把 `reconcile_pending` 一并写进同一个 `$raw`，杜绝「新桶 + 旧数字」的中间窗口。`target_moved()`（`:311`）比对 `provider` / `bucket` / `space` / `region` / `endpoint` / `upload_path`——**后来 beta-8 按 R19.3 去掉了 `space`**，它是纯展示字段，多吉云的「存储空间名称」与真桶名毫无关系。
3. **解锁只认「两端全量」**：`Scan_Service::finalize()` 里 `if ( $scan && in_array( (string) $scan->mode, array( 'full', 'resume' ), true ) )` → `Options::clear_reconcile_pending( $profile )`。带上 `resume` 是因为 `Index_Repository::resume_scan()` 会把 mode 改写成 `'resume'`，但那一趟仍然两端全量。`clear_reconcile_pending()` 只认闸门自己记录的 profile（owner 不同直接 return）。
4. **闸门判据只有一条**：*这个动作会不会按索引里的旧数字去向桶里写或删*。禁用：刷新差异、继续核对、重试失败、批量上传/下载/删除、所有行内动作；放行：「开始完整核对」「重新核对（本地+云端）」、立即校准、toggle-index、测试连接、忽略规则保存、补建媒体库记录。前端另有一份 `GATE_ALLOWED`（`assets/ui/js/pages/storage-sync.js`），用 `Object.prototype.hasOwnProperty.call` 判断，避免原型链上的同名键误放行。
5. **服务端也要拦，不能只靠按钮 disabled**。`sakura_s3_retry` 在 `includes/class-s3-storage-module.php:501-513` 补了 409 + `sio_reconcile_required`：失败清单属于旧桶，而旧标签页手里的 nonce 仍然有效。`ajax_resume` / `ajax_refresh` / `ajax_action` 三处同样拦。
6. **切桶提醒走插件弹窗，不走 admin notice**。`S3_Storage_Page::switch_modal_html()`（`:1058-1091`）渲染一张 `OBJECT STORAGE / SWITCHED` 卡片，三条列表（缩略图改写已关 / 同步中心数据是上一个桶的 / 上传行为两开关仍按原绑定），走 AJAX 返回体的 `switchModal` 字段就地弹出；缩略图页那边同样从顶部 notice 改成 `#sakura-thumb-switch-modal`，transient 读一次即删，只弹一次。

**顺带修掉的老缺陷**：`Sync_Center_Module::ajax_scan()` 原本 `$scan_id = $this->scanner->start();` 不传 mode，而 `Scan_Service::start( $profile_id = '', $mode = 'incremental' )` 的默认值是增量——**「开始完整核对」按钮从 v1.22.8（55cb935）起一直发的是增量扫描**，不可能重建新桶全貌。现在改成 `start( '', 'full' )`。

**已知边界**：封存只覆盖「同步索引与它的展示」，不阻止上传（上传会自己写 synced 行）。也就是说封存期间新传的文件是可信的，旧数字才是不可信的。这一点由 R19.5 的「渲染不写库」配合：页面上的数字只读索引，不在渲染时偷偷改指纹。

**维护动作**：新增任何「按索引旧数字动作」的 AJAX 入口，都必须同时进 `guard_reconcile_gate()` 与前端 `GATE_ALLOWED`，并补一条服务端 409 用例；改 `target_moved()` 的字段集合要同步 `tests/ReconcileGateTest.php`（15 条，含「space 不封存 / region 仍封存」两条）。

## 桶身份只有一处定义，三档字段不许混用（1.28.2-beta-8 / beta-9，2026-11-18）

**背景**：同一件事——「这是哪个桶」——在仓库里有五套互相矛盾的定义：附件指纹 v2 用 `provider|bucket|endpoint|upload_path`、legacy 别名多一段 profile id、删除队列 `Delete_Jobs::target_hash()` 用 `provider|bucket|endpoint|region`、切桶判定 `target_moved()` 用六项（含 `space`）、绑定判定 `Options::IDENTITY_FIELDS` 用五项（含 `region`、不含 `space`）。表现是三个真实的 bug：`space` 是纯展示字段却被当成换桶（多吉云改个显示名就封存同步中心）；索引按 `profile_id` 分区，同一桶换一份配置行就再也确认不了对象；`lost_local_count()` 用 `hash_equals` 精确比对，legacy 形式存下的指纹在破坏性确认框里被漏掉、弹窗报 0。

**决策**：

1. **字段分三档**（`docs/开发规则/07-S3存储生命周期.md` R19.2）：A 档寻址 `provider|bucket|endpoint|upload_path`（决定对象在哪、URL 长什么样）；B 档可用性 `region|key|secret|object_acl`（能不能连上，不影响寻址）；C 档展示 `space|upload_url_path|name`（只给人看）。**只有 A 档能当桶身份。**
2. **`Bucket_Identity` 是唯一定义**（`src/S3/Bucket_Identity.php`）。`ADDRESS_FIELDS` / `REACH_FIELDS` / `VERSION = 'v2'`，`from_options()` / `from_profile()` / `hash()` / `fields()` / `same_as()` / `differences()`。`Synchronous_Uploader::target_hash_for()` 与 `target_hashes_for()` 委托它，**输出逐字节不变**（V28 验收：手写旧算法与委托后哈希相同）。
3. **确认走唯一入口** `Bucket_Confirmation`（`src/S3/Bucket_Confirmation.php`）：`confirms( int )` / `confirms_key( string )` / `confirms_many( array )`，顺序固定「指纹 → 索引批量 → 否定」。结果按 `(桶身份, key)` 进 `wp_cache` 组 `sakura_imgopt_bucket`，`flush()` 用 generation 计数整组退休；`Index_Repository` 惰性构造，只有真要查库时才碰 `$wpdb`。
4. **索引按桶身份分区**（Schema 1.9.0，beta-9）。`sio_sync_index` 唯一键从 `(profile_id, object_key_hash)` 改成 `(target_hash, object_key_hash)`，`sio_sync_counts` 主键改 `(target_hash, status)`。**桶是分区键，配置行只是标签**：`profile_id` 降级为「最后一次确认它的配置行」，每次 `upsert` 的 `ON DUPLICATE KEY UPDATE` 把它刷成最新写入者。过渡版（beta-8）先让 `find_many()` 接受「所有指向同一桶身份的配置行」集合，beta-9 再收拢成单值。
5. **渲染期绝不写库**。`Storage::confirmed_in_active_bucket()`（`src/S3/Storage.php:844`）原来命中索引后直接 `status->retarget()` 改 post meta，而这个函数挂在 `wp_get_attachment_url`（`:145`）与 `wp_calculate_image_srcset`（`:174`）上——**每浏览一次文章就写一次库**，生产实测指纹分布随浏览从 126/6 漂到 132/0。现在渲染只读，回填改由 `Scan_Service::finalize()` 的 `backfill_fingerprints()` 做（只在 `full` / `resume` 时进）。
6. **指纹升级成带出处的确认记录**：`_sakura_imgopt_s3_target` 保持裸字符串（旧代码继续比对），另写 `_sakura_imgopt_s3_target_meta = { hash, confirmed_at, confirmed_by: upload|scan, profile_id }`。只有两个写入者：`mark_synced()`（upload）与 `retarget()`（scan），都由私有 `write_target_info()` 生成。`synced_at` 仍然不刷新——没重传就不该假装刚同步。

**踩过的坑**：`wp_cache_get()` 未命中返回 `false`，与「缓存的否定答案」不可区分——必须按引用取 `$found` 才能分清，否则索引兜底永不触发、7 个用例一起红。`Index_Repository` 的构造函数读 `$wpdb->prefix`，任何「构造即 new」在 CLI/REST 下会 `Attempt to read property "prefix" on null`，必须惰性。

**已知边界**：`fast-search.php` 走 SHORTINIT、没有 autoloader，只能就地重写同一套哈希（`hash('sha256', implode('|', array('v2', provider, bucket, endpoint, upload_path)))`）。**改 `Bucket_Identity::hash()` 必须同步改这一处**，代码里有注释标注。

**维护动作**：改 A 档字段要同时动 `Bucket_Identity`、`fast-search.php`、`Delete_Jobs::target_hash()`（它是另一套语义，只加注释不合并）与 `tests/IndexPurgeTest.php` / `tests/StorageBucketSwitchTest.php` / `tests/ReconcileGateTest.php`；Schema 迁移的验收见 07 文档 §9 的 V28j / V28k。

## `includes/` 不搬迁：评估结论（2026-11-18，未执行）

**背景**：`includes/` 是 1.3–1.9 的兼容层，14 个类（6,740 行）加 14 个视图（6,249 行），共 12,989 行，仍在生产承重。文档长期把它当历史遗留，于是反复出现「为什么不搬到 `src/`」。这次按代码实测评估了一遍（对照 `src/` 179 文件 61,187 行；生产 PHP 共 210 文件 74,641 行）。

**决策**：不搬。若将来搬，只做「同 FQCN、仅换文件路径」（方案 A），一次搬完 14 个类；不重排子命名空间（方案 C）。

1. **外部耦合为零**。生产站 themes / mu-plugins / 其他插件里 grep `Sakura\ImageOptimizer` 与旧类名均无命中，同 FQCN 迁移不会破坏站点侧扩展代码——这是「风险不高」的主要依据。
2. **只搬类解决不了问题**。`includes/` 目录仍会留着 14 个视图与 `SAKURA_TOOLS_INC`；要彻底清场得连视图一起搬，而 8 处 src 侧按路径 `include`（`src/S3/Sync_Center_Module.php:369`、`src/S3/Url_Rewrite_Panel.php:102`、`src/S3/Cost_Panel.php:79`、`src/UserFiles/Frontend/Tab.php:164`、`src/UserFiles/Admin/Space_Page.php:225`、`src/UserFiles/Admin/Page.php:389`、`src/UserFiles/Space_Panel.php:265`、`src/UserFiles/Editor/Picker.php:44`）、`System_Settings_Module.php:101`、视图嵌套（`editor-picker-app.php` → `userfiles-tab.php`）与 4 个测试断言都得跟着动，失败面从「类加载」扩到「每个页签的渲染」。
3. **真风险集中在四处**：(a) autoloader 无 classmap，文件位置就是类契约；(b) `__DIR__` 相对视图路径 3 处（`class-image-optimizer-module.php:955` / `:1002` / `:1094`、`class-media-library-module.php:486`、`class-thumbnail-optimizer-module.php:404`）；(c) `tests/bootstrap.php` 手工 require 且不加载 autoloader，765 tests 全绿也证明不了映射；(d) PSR-4 大小写（`GD_Handler.php`、`S3_Doge_Cloud.php`）与 `bin/build-plugin-zip.sh:156` 的必需文件断言。
4. **不做方案 C**。`Backup` 类与 `Sakura\ImageOptimizer\Backup` 命名空间、`Stats` 类与 `src/Stats/` 现已同名共存，子命名空间化必须给这两个高频类改名，牵动约 65 行 src 引用与约 75 行 tests 引用，换来的只是目录观感。
5. **成本主要在文档与生成物**。`07-S3存储生命周期.md` 39 处 `includes/` 引用（含 20+ 个带行号的 `class-*.php:N`）；代码结构文档 239 处，且生成器 `docs/tools/build-code-structure.mjs:90-91` 与 `:222` 硬编码了模块标签与配色；另有 `docs/graph-preview/` 两个文件与 README / readme.txt / 本文件。代码改动半天能完，文档与验证还要一天。
6. **失败怎么暴露决定验收方式**。`Thumbnail_Manager` / `Image_Processor` / `Thumbnail_Optimizer_Module` 在 `Plugin::build_modules()` 的 always 分支，错则整站 500；`Image_Optimizer_Module` / `Media_Library_Module` / `S3_Storage_Module` 仅后台加载；`Backup` 走每日清理 cron 与恢复服务；`S3_CLI` 只在 WP_CLI 由 `src/Plugin.php:104` 加载，可能潜伏一天。所以「后台 4 个模块逐页签 + 触发一次 cron + 跑一次 WP-CLI + 前台开一个页面」是必须的。

**已知边界**：迁移不修任何现存缺陷——`includes/` 是活代码，1.28.x 仍在改 `S3_Storage_Module` / `S3_Doge_Cloud` / `Thumbnail_Optimizer_Module`；`.agents/worktrees/` 下还有 6 个副本，搬迁必须避开在途分支，否则冲突会落在 rename 上。

**维护动作**：短期守卫是「`includes/` 下不新增文件、不新增类」（CI 或 pre-commit 检查），新代码一律 `src/`；若决定执行方案 A，做法与验收按本条目第 2–6 点办，并同步更新 `01-架构与装配.md` §1、`00-索引.md` 铁律 1、`AGENTS.md` 骨架与 `bin/build-plugin-zip.sh`。


## 删除与结算：闸门按能力判定，结算收敛为一个结算点（2026-11-19，P1 已实施）

**背景**：插件在 `pre_delete_attachment`（`src/UserFiles/Media/Media_Hooks.php:557`）与 `rest_pre_dispatch`（同文件 `:668`）两处否决 WordPress 的删除，但放行条件写死成「文件来源是 `source=media`」，于是「是不是管理员」根本没参与判断：管理员删不掉前台用户上传的附件，而作者删自己的图能否通过取决于来源而不是角色。另一方面，两套结算（`finalize_deleted_attachment()` 与 `Executor::complete_purge()`）靠进程内 `$direct_deletes` 标记避免双扣，任何第三条删除路径都会让台账停在 `active`：文件没了、容量不退、没有审计。

**决策**：

1. **闸门保留，判据换掉**：放行条件从「`source=media`」改为「台账 `active` + 操作者有 `sufm_admin_delete_files` + `delete_post`」。管理员（当前唯一持有该能力的角色）可直接删任何附件，其余角色一律拒绝并引导「提交删除申请」。
2. 容量结算收敛为一个互斥点：`Files::settle_purged()`（`src/UserFiles/Repositories/Files.php:731`，`lifecycle <> 'purged'` → `purged`，`affected === 1` 为胜者）决定谁是唯一扣减者，`Quota::adjust_used()` 只对胜者调用。`Deletion::settle()` 是公开入口；`Executor::complete_purge()` 因为已经在自己开启的事务与申请单行锁里，**不**调 `settle()`（会嵌套事务、把单据状态与容量的原子性拆散），而是复用同一个互斥函数与同一条扣减规则。
3. 插件自己的删除入口收敛为一个内核 `Deletion::delete()`（审核队列、同步中心、后台按钮、CLI 全部经它）。
4. 同步中心的「删除远端」升级为「有附件就整链删」，只有解析不到附件的孤儿对象才保留仅删远端。

**理由**：① 产品规则是「管理员权限最大，其余人只能申请」，这条规则按操作者能力表达才准确，按文件来源表达必然过拦或漏拦；② 「谁能删」是产品策略，「删了怎么记账」是数据完整性，两者要分层——闸门只做准入，记账只在结算点；③ 进程内标记无法覆盖脚本、CLI 与其他插件的删除，行级条件更新才是跨进程互斥，且不需要新增表。

**代价**：插件成为删除的必经通道——作者/编辑（核心层本来能删自己的附件）、第三方插件、不带 `--user` 的 `wp post delete` 都会被拦；每新增一条删除路径都要记得带对身份或走内核。配套：管理员的后台直删入口已由 P1 满足（媒体库直删，判据即第 1 条），带理由与通知的「违规下架」动作放在 P2；CLI 文档写明 `--user`、非管理员提示指向申请流程仍待补。

**落点与验收**：`docs/开发规则/09-删除与结算.md`。**实施状态**：P1 已落地于 1.28.5-beta-1（本地提交 0e82e39）；P2 已落地于 1.28.5-beta-3 并在生产 www.nboke.com 实测通过；P3/P4 见该文档 §7。单测 `tests/DeletionSettlementTest.php`、`tests/CloudStateTest.php`。

## 删除入口不新建页面：媒体库增强（2026-11-19，已实现 P2）

**背景**：删除分期里原本有一期「后台附件管理页」（列表 + 批量删 + 下架）。提出后发现它与 WordPress 媒体库重复：媒体库已经挂着插件自己的四列（文件来源 / 所有者 / 虚拟目录 / 容量）、三个筛选（所有者 / 来源 / 删除申请）与一个批量动作（提交删除申请），再建列表页等于把分页、搜索、缩略图、权限、批量动作重写一遍，还要跟着 WP 与子比主题升级维护。

**决策**：不新建附件管理页。P2 只给媒体库补三样：①「云端状态」列（只读，数据源 `nb_sio_sync_index` 与 `nb_sio_s3_deletes`，口径沿用 07 与同步中心）；② 批量动作「违规下架（删除本地与云端）」，仅 `sufm_admin_delete_files` 可见，弹窗必填理由，走 WordPress 内核删除 + P1 结算 + `nb_sufm_events` 审计 + 作者通知；③ 可选的「处置记录」筛选（复用 `action=delete` 事件，不建页）。

**理由**：① 管理员缺的是「有理由、有审计、有通知」的处置手续，不是第二个列表；② 删除本身已经由官方内核 + P1 观察者负责，多一个入口只会多一条要维护的删除路径；③ 媒体库的列与筛选是既有扩展点，增量最小、回归面最大程度可控。

**实现补充（1.28.5-beta-3）**：① 列表表格的批量表单是 GET 提交，下架的 `reason` / `note` 必须从 `$_REQUEST` 读，只读 `$_POST` 会整批判成「未选理由」；② 通知 payload 必须带 `reason`，否则 `Notifier::body()` 的 `moderation` 分支拼不出「原因：…」；③ 远端对象真正删除后要删同步索引行（`sakura_imgopt_s3_object_deleted` → `Attachment_Indexer::forget_remote()`），否则同步中心会长期把已删对象显示成「仅云端」；④ 下架结果不能用 `admin_notices`（媒体库页面上它会被固定文件夹栏盖住，DOM 有字但看不见），改由 `sufmFolders.notice` 交给 `assets/media-folders.js` 弹 15 秒 toast 并清掉 URL 参数（1.28.5-beta-4）。

**已知边界**：媒体库的批量删除是目前唯一不需要理由的直删通道，它与 P2 的下架在审计里必须靠 `reason` 区分（`operator` vs `moderation`），界面文案也要区分，否则事后分不清「清理」与「处置」。CDN 刷新不在本期（缺多吉云刷新 API 密钥）。
## 上传目标文件夹跟随左侧目录树（2026-11-19，已修复）

**背景**：媒体库的上传器另有一个「上传目标文件夹」下拉（`assets/media-folders.js` 的 `uploadFolder`），它只在自身 `change` 事件里更新，从不理会左侧目录树的当前选中项。于是管理员在左侧点开「分类图」、再到上方虚线框拖文件时，下拉仍写着「我的文件（根目录）」，文件落进根目录——用户看到的目录与文件实际去向不一致。

**决策**：把两个控件做成**双向同步**，而不是让它们各自为政。
- 树 → 下拉：`syncUploadTarget()` 在页面初始化、`go()`（树节点点击）与 `popstate` 之后执行，把 `uploadFolder` 同步成 `folderId()`，并刷新已经挂载的下拉（媒体弹窗与内联上传器各一处）。
- 下拉 → 树：`navigateUploadTarget()` 在任一下拉的 `change` 里执行，把目录树导航到所选文件夹（`go( { sufm_owner: data.userId, sufm_folder: id } )`，选根目录时带 `sufm_folder_mode=all`），否则「我在看 A、却把文件传去 B」时两处界面会长期互相矛盾。
- **只在浏览自己名下的目录树时同步**：服务端 `assign_uploaded()` 只接受 `get_current_user_id() === post_author` 时的 `sufm_folder`（防伪造、防串号），所以浏览别人的树时下拉回到根目录，而从下拉选自己的文件夹会把树切回自己的 owner。

**理由**：① 「我在看哪个目录」与「文件要传到哪里」默认应是同一个答案，需要例外时用户仍可改下拉（`change` 后以下拉为准，直到下一次点击树）；② 服务端的授权边界不变，前端只是把默认值对齐到它本来就会接受的那个值；③ 不新增请求、不改上传协议，`sufm_folder` 一直随 multipart 发送。

**已知边界**：连续上传会一直用当前下拉值（不会每次上传后弹回树的选择）；在没有目录树的页面（如块编辑器的媒体弹窗、`media-new.php`）只有「下拉」这一侧存在，`navigateUploadTarget()` 直接返回。

## 前台文件列表：内置文件类型图标 + 显示名去扩展名（2026-11-19，已实现 1.28.5-beta-10）

**背景**：用户中心「文件管理」的列表里，有的文件名带 `.webp`、有的不带（上传行存 `display_name` 保留扩展名，媒体库收养的行用附件标题、本来就没有扩展名）；非图片文件只有一个「类型缩写」色块，PDF 与压缩包在视觉上几乎一样。用户要求：文件名统一不带扩展名（列表已有专门的位置展示类型），并给非图片文件配一套「细致、易区分」的 SVG 图标。

**决策**：① 展示层统一去掉扩展名，物理文件名与下载名一律不动（下载仍用 uploads 里的真实文件名）；只去掉**白名单内**的扩展名，避免把「扫描全能王 2026-11-18 18.58」这类标题的 `.58` 当成后缀切掉。② 图标随插件内置：从 vscode-icons（MIT）取 23 个子集放进 `assets/file-icons/`，逐个文件而不是内联 sprite——每张 SVG 自带 gradient/filter 的 id，合并进同一文档会互相借色。③ 图标判定按「扩展名 → 探测到的 mime → mime 前缀族」三级回退：文件名不可靠（上面的 `.58` 其实是 PDF），mime 才是 WordPress 从字节里看出来的事实。

**理由**：① 扩展名与图标展示的是同一条信息，重复一遍只会把名称挤窄；② 内置图标不依赖 CDN，离线与内网也一致，且统一视觉风格；③ mime 兜底解决「扩展名不认识但类型明确」的全部情形（PDF、Office、压缩包、音视频），不需要为怪异文件名堆特例。

**落点与验收**：缩略图尺寸（`.sufm .sufm-cell-thumb` 28×32）必须写在全局作用域——移动端列表把缩略图直接放在 `.sufm-row` 下，只写在 `@media (min-width:1200px)` 里会让手机上的图片按原始尺寸铺满整行（1.28.5-beta-11 修）。`src/UserFiles/Frontend/Tab.php` 的 `script_data()['fileIcons']`（带 `Assets::version()` 缓存串）；`assets/userfiles.js` 的 `extOf/displayName/iconKey/fileIconHtml/fileMark`；`assets/userfiles.css` 的 `.sufm-fileicon` 四档尺寸；`assets/file-icons/LICENSE`；单测 `tests/FileIconsTest.php`（23 个文件、SVG 结构、许可、清单一致性）。生产实测：PC 列表/网格、390 列表/网格四档，PDF 图标与图片缩略图各自正确，移动端无横向溢出，重命名弹窗预填与提示语同步更新。

**编辑器文件面板是同一套逻辑的第二份实现**：前台文章/帖子编辑页（`/newposts`、`/posts-edit/`）用的是 `assets/editor-picker-manager.js` + `assets/editor-picker.css`，它们从用户中心那一对文件派生而来，**没有共享模块**。因此 `displayName / extOf / iconKey / fileIconHtml / fileMark / ICON_BY_EXT / ICON_BY_MIME` 与 `#sufm-picker .sufm-fileicon` 的尺寸规则在两边各存一份（1.28.5-beta-12 已同步：面板列表 28×32、面板网格 44×50、大预览 44×54）；改其中一侧必须同步另一侧，否则两个入口会开始漂移。

**已知边界**：白名单外的扩展名（如 `.58`、`.bak`）照旧显示原样；没有对应 mime 也没有族前缀的未知二进制仍回落文字色块；图标只覆盖 23 类，新增类型要同时改 `Tab::file_icons()` 名单与 `FileIconsTest` 的一致性断言。

## 后台 UI V2：同步中心补上「樱前线」弧线（2026-11-20，1.28.5-beta-19）

**背景**：V2 试点落地后，设计稿里最能说明「三方是否一致」的元素——一条从左到右、带三个节点的弧线——一直没有进真实页面。票根给的是数字，弧线给的是关系：读者扫一眼就知道本地、云端、索引停在同一点。

**决策**：把视觉稿的弧线原样搬进 `src/Admin/UI/Frontline.php`（900×150 视图框、三个锚点 86/452/818、青→紫→樱→金渐变、虚线、两片静止花瓣），但**数字写在弧线下面的图例里**，不写进 SVG：真实站点的数值可能是 `12,345`，SVG 文本以固定锚点居中，第一站会直接被左边界裁掉。桌面端图例按锚点百分比绝对定位（9.6% / 50.2% / 90.9%），窄屏隐藏弧线、图例改成竖排三站 + 渐变连接线（设计稿的 `.stations` 写法）。

**理由**：① 关系用图形、数值用文本，两者各自得到最合适的载体；② 花瓣保持静止——这一页「会动的只有一个」（汽水进度条），再加飘落就会抢注意力；③ 三站不足时整条不渲染（`Frontline::render()` 里 `count($stations) !== 3` 直接 return），半条弧线会撒谎。

**边界**：这条 rail 只出现在「S3 同步中心」，没有做成通用页面头的一部分——别的页面没有「三方一致」这个语义。新增单测 `tests/FrontlineTest.php`（4 例）：三站渲染、缺站不渲染、内容转义、色调回落。

## 后台 UI V2：确认框按视觉稿重做（2026-11-20，1.28.5-beta-29）

**背景**：`SakuraAdmin.ui.confirm()` 的确认框还是 V1 的浅描边小卡（440px、8px 圆角、纯白底、危险按钮是 `--g-bad` 实色），与已经换过皮的同步中心放在一起像两个产品。开发者要求「美化弹窗样式，复用设计稿的样式」。

**决策**：把视觉稿 `docs/设计稿/s3-sync-center-sakura.html` 的 `.modal / .modal__card` 直接搬进共享组件层：`dialog` 宽 540px、26px 圆角、右下樱雾背景、很低的大投影；遮罩用原生 `::backdrop` + `rgba(74,43,58,.38)` + `blur(6px)`；标题 20px/800；后果清单改成淡粉便签（虚线分隔 + 粉方块项目符）；按钮右对齐、间距 10px；`.btn--danger` 换成视觉稿的 `140deg #ff7fa8 → #d8285c`。同一套观感也给了 `.sakura-modal`（PHP 侧提示框）：26px 圆角、樱雾、20px/800 标题、右对齐按钮。

**理由**：① 确认框是删除这类不可逆操作的最后一屏，它和页面不是一个风格时，人会怀疑自己点错了地方；② 用原生 `<dialog>` + `::backdrop` 而不是自绘遮罩，键盘陷阱与遮罩点击由浏览器负责，不引入新脚本；③ 危险按钮用渐变而不是纯色，是为了和淡粉主按钮同族——纯 `--g-bad` 在这一版的浅色环境里显得像另一套调色板。

**已知边界**：`.sakura-modal__actions` 默认改成右对齐，靠 `:has(> :first-child:not(.btn))` 保住「左边有复选框、右边是按钮」的老提示框；390 下卡片宽 356px、按钮并排不换行，清单里的「— 249.38 KB」会折行（条目是纯文本，不拆结构）。

## 后台 UI V2 试点：先在「S3 同步中心」落地（2026-11-19，1.28.5-beta-14）

**背景**：设计稿（`docs/设计稿/s3-sync-center-sakura.html`）与真实页面并排看，差距主要在两点：一是系统设置的「页面背景 / 自定义页面背景色 / 卡片背景色」会把分层樱雾和和纸卡片覆盖成一块平涂色；二是按钮与眉标仍是 V1 的深樱实底与灰色小字，和设计稿的粉调不一致。

**决策**：① 移除上述三个背景项（`Appearance::DEFAULTS` 与 `sanitize()` 一并删掉这两个键，旧站点保存过的值会被丢弃，不会再覆盖令牌），只保留页面宽度、边框粗细、圆角、阴影四个开关；② 画布改成「右上樱雾 + 左上冷光 + 底部暖粉」三层径向 + 近白基色，和设计稿的层次一致；③ 主按钮改成左→右的樱粉渐变，两个色标都压在白字 4.5:1 以上（#d23a66 = 4.67、#b02a4f = 6.36）；④ 试点页的卡片眉标改用强调色；⑤ **布局不动**——hero、状态卡、票根条、页签的位置仍是插件现有的信息架构，设计稿里的樱前线弧线属于版式改动，暂不引入。

**范围**：样式只落在 `assets/ui/{tokens,base,components}.css`，页签胶囊、票根条、汽水进度条都用 `[data-sakura-page="storage-sync"]` 限定；其他页面只继承新令牌（颜色、圆角、阴影），版式保持 V1。

**验收**：1440/390 无横向溢出；`wp plugin list` 1.28.5-beta-14；系统设置「布局设置」只剩 6 个字段（左右/上下间隔、页面宽度、边框粗细、圆角大小、边框阴影）。

**补充（2026-11-20，1.28.5-beta-15）**：删掉画布上的「花瓣微尘」层（`.sakura-admin::before` 的 7 个椭圆斑点与 `--sakura-dust` 令牌）。它在真实 1440 屏幕上被读成脏点/污渍，与设计稿的纯色渐变画布相悖；背景现在只有三层径向 + 一条线性渐变。

## 同步中心存储桶状态卡：改成实时延迟胶囊（2026-11-20，1.28.5-beta-16）

**背景**：设计稿右上角是一个「● nboke · 多吉云连接正常」的胶囊，插件现在是一张三行状态卡。用户要求把这张卡做成设计稿的样式，并让圆点真正有意义：进入页面先测一次「服务器 → 当前存储桶」的往返延迟，<200ms 绿「优秀」、200–500ms 黄「缓慢」、>500ms 红「卡顿」，停留期间定时复测。

**决策**：① 探测用 `Storage_Adapter::probe_latency()`：一次带签名的 `list_objects( '', 1 )`（max-keys=1）。选 LIST 而不是 HEAD 某个猜测的键，是因为空桶时 HEAD 会返回 404，用 404 当「健康」需要额外区分；LIST 是每次部署都已有的读权限，且一次请求就能覆盖 DNS、TLS、签名、桶策略整条链。② 走 `admin-ajax` 的 `sakura_s3_latency`（nonce `sakura_s3_sync_action`、`manage_options`、要求对象存储已启用且已配置），返回 `{ms, at}`。③ 轮询间隔 **30 秒**：一次 LIST 是轻量请求，30 秒在「够快发现抖动」与「不给桶和服务器添无谓流量」之间取中；标签页隐藏时停止，回到前台立刻补测一次。④ 前端只写「词 + 数值 + 圆点颜色」，阈值写死在 `storage-sync.js` 的 `stateFor()`，与本文档的表格一致；颜色取最近 3 次采样的中位数，避免单次抖动。⑤ 计时前先发一次不计时的 LIST 做预热：首次调用还要付短效凭据（多吉云 STS）的交换时间，冷缓存时能到数秒，把它算进「桶延迟」会让圆点无端变红。

**边界**：延迟是「这台服务器 → 桶」的往返，不代表访客到 CDN 的速度；CDN 命中率与边缘节点延迟是另一条链路，不能拿这个数字解释前台慢。探测固定做两次 LIST：第一次用来把短效凭据缓存与连接预热好（不计时），第二次才计时，否则冷缓存那一次的 2–5 秒会被误判成「桶卡顿」。

## 任务中心按设计稿复刻：气泡改成真实元素、柱子改成叠放的粗圆角柱（2026-11-20，1.28.5-beta-23）

**背景**：汽水进度条上一版用「平铺径向渐变 + `background-position` 平移」画气泡，用户反馈「不够连贯，会有明显的停顿感」。原因是图案宽度与动画位移不整除，循环点必然出现一次跳变，而所有气泡又在同一时刻归零——看起来就像整条液体卡了一下。

**决策**：① 气泡改成**真实 DOM 元素**：`.progress__fizz i` 22 颗（液面里上升）、`.progress__pop i` 7 颗（升出液面后破掉），由 `assets/ui/js/pages/storage-sync.js` 在 `admin.ready` 时注入，每颗的 left / 尺寸 / 时长 / 延迟都随机，**延迟取负值**，页面一打开它们就处在各自的相位，永远不会同时归零。② 近 7 天柱状图从「两根 8px 细柱并排」改成设计稿的「一叠粗圆角柱」：宽 100%、`max-width:26px`、`border-radius:7px`、列内 `flex-direction:column`、`gap:3px`，正常柱在下、失败柱叠上，颜色分别是 `--g-petal-1 → --g-petal-3` 与 `#ffd3a8 → #ff9f68`。③ 按钮加「悬浮抬起 1px + 投影加深」（`transition: transform .16s`），按下归位，禁用态与 `prefers-reduced-motion` 下不位移。

**理由**：① 连贯性来自「不同相位」，不是更快的动画——加快只会让停顿更频繁；② 设计稿的柱子是「一根柱子分成正常/失败两段」，细柱并排会读成两天的数据；③ 1px 抬升足够表达「被指尖碰到」，再大就会和同一行相邻按钮错位。

**边界**：主按钮的渐变**没有照抄**视觉稿那支 `#ff9dbf → #e75a8b`——它配白字只有 3.1:1，达不到 AA；实际用的是同一色相的 4.5:1 下限版本（`#d23a66 → #c7335b → #b02a4f`），把「亮」留在渐变的左端与悬浮态，把可读性留在文字上。气泡数量上限仍是 44 颗（22 + 7 + 泡沫），再多会让 24px 高的条体看起来像雪。

**验收**：`wp plugin list` 1.28.5-beta-23；生产页 `#tasks` 卡片 1440 实测 —— 气泡 22 + 7 颗、left/时长/延迟各不相同；柱子宽 26px、圆角 7px、`flex-direction:column`；主按钮 hover 计算值 `matrix(1,0,0,1,0,-1)`；`scrollWidth=1440`。

**补充（2026-11-20，1.28.5-beta-24/25）：改用视觉稿的淡粉。** 开发者反馈「按钮、柱状图、百分比条的气泡，粉色颜色太深了」。于是三处一起换成视觉稿的原色：主按钮 `140deg #ffa7c6 → #f2739f 48% → #e75a8b`（悬浮再浅一档）、柱子 `#ffb4d0 → #e75a8b`、汽水条 `#ff9ec6 → #ff5f95 52% → #ef3f79`，`--g-fill`（所有百分比条的通用填充）一并改淡。**代价**：白字压在 `#e75a8b` 上只有 ~3.4:1，13px 常规字不满足 AA（此前那版 `#d23a66 → #b02a4f` 是 4.67/6.36）。这是明确的可读性取舍，不是疏忽——若要回到 AA，最小改法是把按钮最右色标压到 `#c94370`（≈4.7），其余保持不变；这条退路已写进 `03-后台UI.md` 的按钮规范里。

## 后台 UI V2：所有提示框统一成同一副面孔（2026-11-20，1.28.5-beta-36 / beta-37）

**背景**：插件里有两套提示框——JS 的 `ui.confirm()`（原生 `<dialog>`，已按视觉稿做过）与 PHP 的 `UI::modal()`（`.sakura-modal`，V1 的「色块图标 + 居中标题 + 无遮罩」）。开发者看到同一页两种样子，要求「插件的所有弹窗提示统一改为文件核对的删除弹窗」。

**决策**：两套都收敛到同一个骨架——左上小鲸鱼、标题与正文左对齐、清单行带纸片图标与右对齐体积、结论走粉底说明条、按钮右对齐；遮罩统一成 `rgba(74,43,58,.38)` + `blur(6px)`。`tone` 参数保留（十几处调用点不动）但不再影响外观，V1 的五种色块图标与 `Modal::tone()` 一并删除；`ui.confirm()` 默认就带小鲸鱼，传 `mascot:false` 可退回纯文字。

**理由**：① 同一个动作在不同页面长得不一样，会让人怀疑是不是两个功能；② 遮罩是「先停一下再回答」的一部分，PHP 弹窗原先无遮罩可点背后，在「要删什么」这种场合是错的；③ 吉祥物只做一件事——把「这是插件的提示」和 wp-admin 自己的提示区分开；④ 两套实现共享同一只鲸鱼的 path，改一处就必须改另一处（已在注释里写死这条）。

**落点**：`assets/ui/js/ui.js`（`mascotSvg()`、`fileGlyph()`、`confirm()` 的 `note`/`items`/`mascot`）、`src/Admin/UI/Modal.php`（`head`/`copy` 结构 + `mascot()` + `close_icon()`）、`assets/ui/components.css`（`.sakura-modal__head/__mascot/__copy`、`.dialog__*`、遮罩）。

**前台两个面板也补了同一只鲸鱼（1.28.5-beta-39）**：用户中心「文件空间」的 `assets/userfiles.js` 与编辑器面板的 `assets/editor-picker-manager.js` 各有一份 `ensureMascot()`，在第一次打开 `#sufm-modal` / `#sufm-picker` 的 `<dialog>` 时把 38px 的小鲸鱼插进 `.sufm-dialog-head`（CSS：`.sufm-dialog-mascot { flex:none }` + 标题 `flex:1`）。只插一次、复用节点；两个面板的卡片与按钮仍是各自的 CSS（前台是用户界面，不需要和后台像素对齐），统一的只是「这是插件的弹窗」这个记号。

**已知边界**：`.sakura-modal` 仍是无障碍上的「浮层」而非原生 `<dialog>`（键盘陷阱靠 ui.js），若以后要 Esc/Tab 循环交给浏览器，应把它换成 `<dialog>` 而不是继续加 JS。

## 弹窗动效与遮罩：从「贴上」改成「长出来」（2026-11-20，1.28.5-beta-41）

**背景**：开发者看过别家产品后反馈「弹窗动画好古老……都没有说弹窗有遮罩，突然出现和消失」。原实现是 `dialog::backdrop` 用 `rgba(74,43,58,.38) + blur(6px)` 的灰色玻璃，卡片无进场动画、关闭是 `dialog.close()` 一帧消失。

**决策**：① 遮罩降到 `rgba(74,43,58,.14) + blur(3px) saturate(1.02)`——它只回答「这里不是正文」，不再把整页压黑；② 进场 `240ms cubic-bezier(.16,1,.3,1)`、`translateY(8px) scale(.96) → 0/1`，出场 `140ms cubic-bezier(.4,0,1,1)`、`translateY(-4px) scale(.985)`，快出慢进是因为「确认」这个动作已经结束，多留 100ms 只是拖沓；③ 关闭统一走 `closeDialog()`：加 `.is-closing` → 听 `animationend` → `dialog.close()`，并留 220ms 定时器兜底（后台标签页不派发 `animationend`，否则浮层关不掉）。

**理由**：动效的意义是解释「从哪里来、到哪里去」，`scale(.96) → 1` 说明卡片是从页面里长出来的，`translateY` 说明重心在上方；而 0.38 的灰罩会把注意力从卡片上撕开，薄雾保留上下文又不抢戏。整个改动只动 CSS 关键帧与一个 JS 辅助函数，没有引入动画库。

**边界**：`prefers-reduced-motion: reduce` 下三条动画全部 `animation: none`，`closeDialog()` 直接 `dialog.close()`；`.sakura-modal`（PHP 提示框）与原生 `<dialog>` 共用同一组曲线，但前者由 JS 管键盘陷阱，仍是独立实现。

## 子比增强模块与 Zibll 授权缓存迁移（2026-11-21，1.28.7-beta-1）

**背景**：开发者决定把 `zibll-site-enhance`（下称 zse）的功能逐步迁进樱花工具箱——zse 借用子比的 CSF 框架，扩展成本高。迁移第一步是新建「子比增强」模块，第一个迁过来的功能是 zse 性能优化里的「启用 Zibll 授权缓存」。

**决策**：新模块 id `zibll`、slug `sakura-tools-zibll`、能力 `manage_options`，目前只有一个页签 `performance`（页面 id `zibll-performance`），页签 gate 用 `License::is_zibll_theme()`，非子比主题显示 403 说明而不是空白页。设置落在 `sakura_imgopt_zibll` 数组（只有一个键 `license_cache`），缓存落在 `sakura_imgopt_zibll_license_cache`（`autoload=false`），每日复验 cron 是 `sakura_imgopt_zibll_revalidate`。真实验证只发生在三处：后台保存时、后台手动复验时、每日 cron；前台仅在缓存有效且当前请求是 GET/HEAD 的只读请求时 `remove_action( 'init', 'zib_init_add_action' )`。

**理由**：子比 8.8+ 的 Z5 加密层把授权校验挂进每个前台请求（`init:10` 的 `zib_init_add_action → ZibAut::is_aut()`），实测单次 33–47 ms，且其中大段 `eval` 无法被 OPcache 缓存。缓存的是「主题自己已经判定通过」这个结论而不是伪造授权：命中缓存只是不再重复跑同一个校验，校验结果仍然来自主题。缓存写在自己的 option 而不是读 zse 的 `zse_zibll_perf_cache`——两个插件会并存一段时间，读别人的 option 会让「停用谁」变成隐患。

**边界**：缓存有效要同时满足四件事——主题版本、`untrailingslashit(home_url('/'))`、`hash('sha256', zibll_code.'|'.post_zat_end_time)`（用 `hash_equals` 比）、以及 `post_zat_next` 到期。**不监听 `post_zat_next` 的更新**：主题自己会频繁刷新到期时间，跟着清缓存等于永远冷；只有主题切换、站点地址、`zibll_code`、`post_zat_end_time` 变化才清。验证必须看返回值（`(bool) ZibAut::is_aut()`，返回 false 就是拒绝，绝不写成功缓存）；主题在输出后 `exit` 时由 shutdown 钩子写一条错误提示、缓存不写入。旧 mu-plugin（`/wp-content/mu-plugins/zibll-performance.php`）的冲突检测曾要求先改名才能启用，2026-11-21 开发者确认该 mu-plugin 方案已弃用、不再开发，检测与页面上的展示一并移除。主题版本不在 `9.1 / 9.2` 范围内时，需要在同一次保存里勾选确认才允许启用。

**验收**：`wp option get sakura_imgopt_zibll` 只有 `license_cache`；启用后 `wp option get sakura_imgopt_zibll_license_cache` 含 `ok/theme_version/home_url/auth_fingerprint/expires_at/created_at/created_by/source`；前台只读请求里 `has_action('init','zib_init_add_action')` 为 `false`，而 `/wp-admin`、`wp-json`、带 POST 的请求里仍为 `10`；保存失败时页面弹「设置未保存」并附主题/授权返回的原因，开关自动回落到关闭。
