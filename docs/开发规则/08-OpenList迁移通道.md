# 08 OpenList 迁移通道

这一页记录本站点上的 **OpenList** 实例，以及「把已经上传到 A 桶的对象搬到 B 桶」这件事怎么借它完成。**它不是上传路径**——上传永远走 WordPress 媒体库与插件自己的 `Object_Store`；OpenList 只在换桶时当一次性的搬运工。

> 相邻篇：换桶在生命周期里只允许一条路径，见 [07-S3存储生命周期](07-S3存储生命周期.md) §6；新增一家存储厂商见 [02-新增功能](02-新增功能.md) §12；option 的读写与卸载见 [04-数据与存储](04-数据与存储.md)。
> 本页的 API 事实全部在 **2026-11-17** 用本机实例实测（OpenList v4.2.4 / 插件 1.28.1-beta-6）；上游改版后以实测为准并顺手修正本页。
> 本页**不含任何明文密钥**。账号密码走运维侧凭据文件与记忆，不进仓库。

## §0 边界：它是迁移通道，不是存储后端

| 问题 | 答案 |
|---|---|
| 插件上传附件时是否经过 OpenList？ | **不经过。** 上传仍由 `Object_Store`（`src/S3/Object_Store.php`）直连存储桶。 |
| 插件是否依赖 OpenList 才能工作？ | **不依赖。** OpenList 挂了只影响「换桶迁移」这一个动作，日常上传、同步中心、缩略图全部照常。 |
| 为什么不自己做桶到桶复制？ | 跨厂商没有服务端复制：`x-cos-copy-source` / OSS 的 `x-oss-copy-source` 只能表达「一个桶 + 一个键」，带不了第二个 endpoint 与第二套凭据。插件内部也没有 `CopyObject` 调用点，只能「下载到磁盘 → 再上传」中转。 |
| 那 OpenList 凭什么能跨厂商搬？ | 它在**服务端**同时挂载源与目标（自己持有两套凭据），流式读源写目标，站点只发一个 `/api/fs/copy` 然后轮询结果。带宽与内存都不经过 PHP。 |

一句话：**OpenList 把「跨厂商复制」这件事从插件里挪到了一个常驻服务上**，插件只负责发起与观测。

## §1 本机部署事实

| 项 | 值 |
|---|---|
| 版本 | `v4.2.4 (Commit: 84ecda3)`，Frontend v4.2.4，Build 2026-07-23 13:32:17 +0000（`GET /api/public/settings` 的 `data.version`） |
| 进程 | systemd 单元 `openlist.service`，`WorkingDirectory=/opt/openlist`、`ExecStart=/opt/openlist/openlist server`、`KillMode=process` |
| 二进制 | `/opt/openlist/openlist`（约 130 MB） |
| 数据目录 | `/opt/openlist/data/`（`data.db` = sqlite3、`config.json`、`log/`、`temp/`），权限 `drwx------ root` |
| 监听 | `0.0.0.0:5244`（`config.json` 的 `scheme.address`、`http_port=5244`、`https_port=-1`、`force_https=false`） |
| 本机地址 | `http://127.0.0.1:5244` |
| 公网地址 | `https://file.nboke.com`（nginx 反代，`proxy_pass http://127.0.0.1:5244`，`proxy_read_timeout 600s`） |
| 令牌有效期 | `config.json` 的 `token_expires_in = 48`（小时） |
| 管理员账号 | `3031379629@qq.com`（`x_users` id=1，role=2）。**密码不写在本页**，见运维凭据文件与记忆 |
| 普通账号 | `nboke`（id=4，role=0，permission=32767），只够做 `/api/fs/*` |

事故排查先看这两处：

```bash
systemctl status openlist --no-pager | head -20
tail -n 100 /opt/openlist/data/log/log.log
```

`data.db` 是唯一状态源（挂载、用户、设置都在里面）。**动它之前先停服务并整库备份**：

```bash
systemctl stop openlist
cp -a /opt/openlist/data/data.db /root/openlist-data-$(date +%Y%m%d-%H%M%S).db
systemctl start openlist
```

> 机器上没有 `sqlite3` CLI，查库用 `python3 -c "import sqlite3; ..."`。库里的表：`x_users`（账号）、`x_storages`（挂载）、`x_task_items`（任务队列的持久化位，实际恒为空，见 §5.4）、`x_setting_items`、`x_meta`、`x_search_nodes`、`x_sharing_dbs`、`x_ssh_public_keys`。**没有 `x_tasks` 表**——任务只在内存里。

## §2 认证与权限

### §2.1 令牌

```http
POST /api/auth/login
Content-Type: application/json

{"username": "...", "password": "..."}
```

响应 `data.token`。之后每个请求带：

```http
Authorization: <token>
```

**不要加 `Bearer ` 前缀**——加了会返回 `{"code":401,"message":"token is invalidated"}`。这是踩过的坑，不是笔误。

令牌 48 小时过期。迁移任务应在一次脚本生命周期内完成，不要跨天续用旧 token。

### §2.2 role 决定能做什么

| 端点组 | role=0（普通用户） | role=2（管理员） |
|---|---|---|
| `/api/fs/*`（list / get / put / mkdir / remove / copy） | ✅ 可用 | ✅ 可用 |
| `/api/admin/storage/*`（建/删/列挂载） | ❌ `{"code":403,"message":"You are not an admin"}` | ✅ 可用 |
| `/api/admin/driver/*`（驱动清单与字段定义） | ❌ 403 | ✅ 可用 |
| `/api/task/copy/undone`、`/done` | ✅ 可用 | ✅ 可用 |

**关键结论：搬运不需要管理员权限，建/删临时挂载才需要。** 因此插件侧要么持有管理员令牌（能自动建挂载），要么要求运维预先手工挂好两个目录、插件只做 `fs/copy` 与轮询。

## §3 挂载管理 API

迁移的准备工作就是**临时建两个挂载**：源桶一个、目标桶一个，搬完立刻删。

### §3.1 建挂载

```http
POST /api/admin/storage/create
```

```json
{
  "mount_path": "/_mig_src",
  "order": 0,
  "remark": "sakura-tools migration",
  "cache_expiration": 0,
  "web_proxy": false,
  "webdav_policy": "302_redirect",
  "down_proxy_url": "",
  "disable_proxy_sign": false,
  "order_by": "",
  "order_direction": "",
  "extract_folder": "",
  "disable_index": false,
  "enable_sign": false,
  "driver": "S3",
  "addition": "{\"root_folder_path\":\"/\",\"bucket\":\"...\"}"
}
```

两个必须记住的点：

1. **`addition` 是 JSON 字符串，不是对象。** 传对象会得到 `HTTP 200` + `{"code":400,"message":"model.Storage.Addition: ReadString: expects \" or n, but found {, error found in #10 byte of ..."}`——它把这个字段当 Go 的 `json.RawMessage` 读，所以必须自己 `json.dumps()` 一次。
2. **重复的 `mount_path` 不会报 HTTP 错误**，而是 `HTTP 200` + `{"code":500,"message":"failed create storage in database: constraint failed: UNIQUE constraint failed: x_storages.mount_path (2067)","data":{"id":0}}`。注意 `data.id` **存在且为 0**，所以判断成功必须看 `body.code == 200`，不能看 `data.id` 有没有。

删除：

```http
POST /api/admin/storage/delete?id=<id>
```

`mount_path` 有 UNIQUE 约束，所以每次迁移必须先删旧挂载（或换一个带随机后缀的路径，如 `/_mig_src_1731`）。

### §3.2 驱动与字段

`GET /api/admin/driver/names` 返回 92 个驱动名，迁移相关的两个：`S3`、`Doge`。

`GET /api/admin/driver/info?driver=S3`（`?driver=Doge` 的 `additional` 字段**完全相同**）返回 18 个字段：

| 字段 | 说明 |
|---|---|
| `root_folder_path` | 挂载在桶内的起点，一般 `/` |
| `bucket` | 桶名 |
| `endpoint` | 服务地址（带 `https://`） |
| `region` | 地域，部分厂商必填 |
| `access_key_id` / `secret_access_key` | 凭据 |
| `session_token` | 临时凭据才填，长期 AK/SK 留空 |
| `custom_host` / `enable_custom_host_presign` | 自定义域名与签名，迁移用不到 |
| `sign_url_expire` | **签名有效期（秒）。Doge 驱动必须 > 0**，见 §3.4 |
| `placeholder` | 占位文件名，留空 |
| `force_path_style` | 路径风格。**MinIO 必须为 `true`**，见 §3.3 |
| `list_object_version` | `v1` / `v2`，本项目一律 `v2` |
| `remove_bucket` | 留 `false`（否则删挂载可能动桶本身） |
| `add_filename_to_disposition` | 留 `false` |
| `enable_direct_upload` / `direct_upload_host` | 走客户端直传时需要，迁移不用 |
| `user_agent` | 留空 |

### §3.3 各家实测结论（本站四份配置）

| 配置 | 驱动 | 关键 `addition` | 结果 |
|---|---|---|---|
| 缤纷云 `bitiful/nboke` | `S3` | 默认路径风格 | ✅ 列表正常（根下有 `wp-content`） |
| 腾讯云 `tencent/sakura-clover-1301234331` | `S3` | 默认路径风格 | ✅ 列表正常（当前为空桶） |
| 多吉云 `dogecloud/doss-v2-…` | `Doge` | `sign_url_expire: 600` | ✅ 列表正常，复制成功 |
| 本地 MinIO `minio/nboke-minio-test` | `S3` | **`force_path_style: true`** | ✅ 列表正常 |

MinIO 用虚拟主机风格时的报错长这样，看到它就该去开路径风格：

```
failed get objs: failed to list objs: RequestError: send request failed
caused by: Get "http://nboke-minio-test.<...>:19010/?delimiter=%2F&list-type=2&prefix=": dial tcp ...
```

另外 MinIO 在本机 `127.0.0.1:19010`，只有 OpenList 跑在同一台机器上才连得上——这一点决定了「本地 MinIO 可以参与本站迁移」纯粹是因为同机。

### §3.4 Doge 驱动的隐藏前提：`sign_url_expire`

多吉云用长期 AK/SK（`session_token` 留空）时**列表能过，复制会失败**，任务 `state=7`，`error` 是：

```
failed get [...]: failed get link: InvalidPresignExpireError: presigned URL requires an expire duration greater than 0
```

补上 `"sign_url_expire": 600` 后同一复制 `state=2`、`total_bytes` 正确、目标侧列出同名同尺寸文件。

**插件侧生成 Doge 挂载时必须显式写入 `sign_url_expire`（建议 600）。**

## §4 `/api/fs/*` 契约

### §4.1 列目录

```http
POST /api/fs/list
{"path": "/wp-content/uploads/2026/11", "page": 1, "per_page": 100}
```

响应 `data.content[]`（每项字段实测为 `name` / `size` / `is_dir` / `modified` / `created` / `type` / `hash_info` / `hashinfo` / `sign` / `thumb`）与 `data.total`、`data.write`、`data.provider`。`raw_url` 不在 list 里，只有 `POST /api/fs/get` 才返回。

- **空目录的 `content` 是 `null`，不是空数组。**
- `per_page` 无硬上限：`per_page=2000` 时返回 `min(total, 2000)`。判断是否取完要用返回的 `total`，不要靠「返回条数 < per_page」。

### §4.2 复制（核心）

```http
POST /api/fs/copy
{"src_dir": "/_mig_src/wp-content/uploads/2026/11", "dst_dir": "/_mig_dst/wp-content/uploads/2026/11", "names": ["a.webp", "b.webp"]}
```

成功：`{"code":200,"message":"Successfully created N copy task(s)","data":{"tasks":[{"id":"...","state":0,...}]}}`。

**`/api/fs/copy` 是异步的**：返回只表示任务入队，实际搬运在服务端后台跑。

错误形态（都是 `HTTP 200`，必须判 `body.code`）：

| 场景 | `body.code` | message |
|---|---|---|
| 目标已存在同名文件 | 403 | `file [a.webp] exists` |
| 源对象不存在 | 500 | `failed to get src object: failed to get obj: object not found` |

→ **迁移前必须先对目标做一次 `fs/list` 求差集**，否则整批任务会因为个别已存在的文件失败，反复重跑也不会前进。

其他有用的事实：

- **`dst_dir` 必须已经存在。** 复制到不存在的目标目录会直接失败：`{"code":500,"message":"failed to get dst dir: failed to get obj: object not found"}`（文件与目录一样）。所以要自己先 `POST /api/fs/mkdir` 把目标目录建出来。**会自建父目录的是 `PUT /api/fs/put`（上传），不是 `fs/copy`**——这两件事不要混。
- 复制**目录**时返回的是一条**壳任务**：提交瞬间 `state=0`，随后 `state=2` + `status="src object is dir, added all copy tasks of objs"` + `progress=0` + `total_bytes=0`。

> ⚠️ **壳任务的 `state=2` 只代表「子任务都派发出去了」，不代表文件搬完。** 实测一次目录复制：壳任务 1 秒内就 `state=2`，而真正的文件搬运是另一条独立 id 的子任务（`status=uploading`、`total_bytes=5`）。**若只看壳任务就宣布成功，会在文件还没搬完时就进入收尾（删旧配置 → 新增目标配置 → 启用），站点直接指向一个空桶。** 正确做法：提交前快照 `done` 的 id 集合，之后把「新出现的所有任务」一起结算，直到它们全部 `state=2` 或 `7`。

### §4.3 其余端点

| 端点 | 说明 |
|---|---|
| `POST /api/fs/get` | 文件元信息，`raw_url` 形如 `http://127.0.0.1:5244/p/OPenList/...`（服务端内网地址，站点侧不可直接当 CDN 用）；对目录也返回 200，但 `raw_url` 是空串 |
| `PUT /api/fs/put` | 上传原始字节，路径放请求头 `File-Path: /a/b.txt` |
| `POST /api/fs/mkdir` | 建目录 |
| `POST /api/fs/remove` | `{"dir": "/x", "names": ["a", "b"]}` |

## §5 任务队列：唯一可信的是 `state`

### §5.1 两个队列

```http
GET /api/task/copy/undone   → {"code":200,"data":[...]}
GET /api/task/copy/done     → {"code":200,"data":[...]}
```

任务字段：`id`、`name`、`creator`、`creator_role`、`state`、`status`、`progress`、`start_time`、`end_time`、`total_bytes`、`error`。

### §5.2 `state` 语义（实测四种）

| state | 含义 |
|---|---|
| 0 | 刚入队 |
| 1 | 运行中 |
| 2 | **成功** |
| 7 | **失败**（`error` 里有原文） |

**`status` 文案不可信**：成功任务的 `status` 也可能是 `uploading`。判结果只看 `state`。

### §5.3 端点可用性（踩过的坑）

| 想做的事 | 结论 |
|---|---|
| 查单个任务 | 只能 `POST /api/task/copy/info?tid=<id>`；**`GET` 同一 URL 返回 SPA 的 index.html**。tid 不存在时 `{"code":404,"message":"task not found"}` |
| 清空队列 | `POST /api/task/copy/clear/done`、`/clear/succeeded` **不存在**，返回 SPA HTML |
| retry / cancel / delete | 端点存在（假 tid 回 404），但语义未验证，迁移不要依赖 |

### §5.4 队列只在内存里

`data.db` 的 `x_task_items` 表只有 `(key, persist_data)` 两列，四行（`copy` / `move` / `download` / `transfer`）的 `persist_data` 全是 `[]`。**OpenList 重启，任务队列即丢。**

因此可靠的做法只有一条：

> **在提交任何复制任务之前，先快照一遍 `/api/task/copy/done` 的 id 集合（记作 `baseline`）。之后只认「done 里新出现、且不在 baseline 里」的 id。**

插件侧还必须自己落盘「本次迁移已提交的文件清单」，因为队列丢了就再也问不出谁搬过。

## §6 插件侧迁移的五步

```
0. 前置：关闭「启用 S3 存储」（否则 07 §6 的路径不允许换桶）
1. 准备：为源配置与目标配置各建一个临时挂载（/_mig_src_<rand>、/_mig_dst_<rand>）
2. 求差：递归 list 目标桶，得到「目标已有的 key 集合」；源文件 − 目标文件 = 待搬列表
3. 搬运：按目录分批 POST /api/fs/copy；提交前快照 done 的 id 集合
4. 观测：轮询 done，按 state 结算；失败项单独重试，已存在项跳过
5. 收尾：删除旧配置（解绑）→ 新增目标配置并保存 → 打开「启用 S3 存储」→ 跑一次完整核对 → finally 删两个临时挂载
```

几个必须写进实现的细节：

- **临时挂载路径带随机后缀**，避免上一次迁移残留触发 UNIQUE 约束。
- **`finally` 里删挂载**，哪怕搬运失败也不能留下持有凭据的挂载。
- **不要并发建/删挂载**：`mount_path` 全局唯一，并发会互相撞。
- **先把目标目录建好再复制**：`fs/copy` 不会替你创建 `dst_dir`（§4.2），每批之前按源目录结构 `fs/mkdir`。
- **壳任务不算完成**（§4.2）：目录复制要等「done 里新出现的全部任务」都结算，别只盯提交时返回的那一个 id。
- **搬完再动配置**。R5 在暂停态仍然禁止切换配置与新增配置，所以顺序只能是：暂停 → 搬 → 删旧配置（解绑）→ 新增目标配置 → 启用。**中途唯一能改的就是桶里的对象**；顺序反了会出现「URL 已指向新桶，但对象还在旧桶」的整站裂图窗口。
- **切换后跑一次完整核对**，让 `nb_sio_sync_index` 里的 `seen_remote` 与对象重新对齐（见 07 §5 与 beta-6 的自愈逻辑 `src/S3/Storage.php:686`）。

伪代码（凭据来自 `Options::profile_options()`，**不要**从 option 里读明文）：

```php
$ol = new OpenList_Client( $base_url, $token );            // Authorization 不加 Bearer
$ids = array();
try {
	$src = $ol->create_mount( '/_mig_src_' . $rand, $source_profile );
	$dst = $ol->create_mount( '/_mig_dst_' . $rand, $target_profile );
	$baseline = $ol->done_task_ids();                     // 提交前快照，见 §5.4
	$plan     = $ol->diff( $src, $dst );                   // 目标 fs/list 求差集，已存在的跳过
	foreach ( $plan as $dir => $names ) {
		$ol->mkdir( $dst . $dir );                          // dst_dir 不会自动创建，见 §4.2
		$ids = array_merge( $ids, $ol->copy( $src . $dir, $dst . $dir, $names ) );
	}
	$result = $ol->settle( $baseline );                    // 结算 done 里「新出现的全部任务」，见 §4.2
} finally {
	$ol->delete_mount( $src );
	$ol->delete_mount( $dst );
}
```

## §7 失败、回滚与断点续跑

| 现象 | 处理 |
|---|---|
| 个别文件 `state=7` | 记下 `error` 原文，单独重试；不要整批重跑（已存在项会 403） |
| 大批 `403 file exists` | 说明差集没算对（比如目标已有但 list 分页没取完），补全 `total` 分页后重算 |
| OpenList 中途重启 | 队列丢失，**已提交但未确认的项状态不可知**；用「目标 list 重新求差」代替，不依赖队列 |
| 凭据 401 | token 48 小时过期，重新 `/api/auth/login` |
| 站点无法访问 OpenList | 迁移不可用，但上传/同步中心不受影响；走「下载到磁盘 → 再上传」的老路（慢，但插件自己能做） |

回滚：迁移过程**只读源桶、只写目标桶**，源桶对象不会被删；而且此时 `active_profile_id` 仍指向源配置（R5 不允许中途切换）。所以真要放弃，**重新打开「启用 S3 存储」就回到原样**，连配置都不用动。目标桶里的副本当作垃圾留着（`07` §10 明确不做旧桶清点）。

## §8 与 07 的关系

07 §6 规定换桶的唯一路径是「关 S3 → 删配置 → 新增配置」。OpenList 迁移是这条路径上的**施工工具**，不是替代：

- 它不解除绑定，也不允许在绑定态换桶（R5 仍然生效）。
- 正确顺序是：先关 S3（进入暂停态），**在删除旧配置之前**用 OpenList 把对象搬到新桶，然后删旧配置、新增新配置、开 S3、跑核对。
- 因为 R5 只在绑定态生效，暂停态才是唯一能操作桶的窗口——这也解释了为什么迁移脚本必须以「`enabled=false`」为前置断言。

## §9 验收清单

前四项在 2026-11-17 已经用本机实例跑过（标记 ✅），剩下的是落代码时要补的。

- ✅ **V1** 用本站真实两家桶（多吉云 → 缤纷云）搬一个文件：`fs/copy` 返回 `code=200`，轮询 **2.0 s** 后 `state=2`、`total_bytes=81408` 与源一致，目标 `fs/list` 里同名同尺寸。
- ✅ **V2** 临时挂载全部删除：`GET /api/admin/storage/list` 只剩 `/115` 与 `/OPenList`。
- ✅ **V3** 重复 `mount_path` 建挂载返回 `code=500` 且 `data.id=0`，据此判定失败。
- ✅ **V4** 目标已存在同名文件时 `fs/copy` 立即返回 `code=403 file [...] exists`（不入队、不产生 `state=7` 噪音），插件侧应先求差集再提交。
- **V5** 迁移期间产物不落 PHP 内存：处理大文件时 `memory_get_peak_usage()` 不随文件增大而上涨。
- **V6** 迁移结束后按 `07` §6 收尾（删旧配置 → 新增目标配置 → 启用），附件 URL 全部指向新桶域名；同步中心跑一次完整核对后 `seen_remote` 与目标桶对象数一致。

## §10 明确不做

| 不做 | 原因 |
|---|---|
| 把 OpenList 当上传后端 | 上传必须留在一个可控、可复现、失败可重试的路径上；引入常驻外部服务会把「传一张图」变成「两个系统都得活着」 |
| 自动定时迁移 | 迁移是一次性的运维动作，绑进 cron 只会在没人看着的时候动桶 |
| 依赖 `retry` / `cancel` / `clear` 端点 | 未验证语义，上游也可能随时改 |
| 在插件里实现 OpenList 的驱动适配层 | 迁移只需要 `S3` 与 `Doge` 两个驱动，够用即可 |
| 记录迁移历史到新表 | 首次落地的版本只做「一次迁移一次脚本」，等真的需要审计再加（届时须同时进 `Uninstaller`） |
