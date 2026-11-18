# 08 OpenList 迁移通道

这一页记录本站点上的 **OpenList** 实例，以及「把已经上传到 A 桶的对象搬到 B 桶」这件事怎么借它完成。**它不是上传路径**——上传永远走 WordPress 媒体库与插件自己的 `Object_Store`；OpenList 只在换桶时当搬运工，平时以**常驻镜像挂载**的形式跟着插件的存储配置走（§4）。

> 相邻篇：换桶在生命周期里只允许一条路径，见 [07-S3存储生命周期](07-S3存储生命周期.md) §6；新增一家存储厂商见 [02-新增功能](02-新增功能.md) §12；option 的读写与卸载见 [04-数据与存储](04-数据与存储.md)。
> 本页的 API 事实分两批取证：**2026-11-17** 用本机实例实测（当时 v4.2.4），**2026-11-18** 按本机 v4.2.9 与上游源码逐条复核（`/api/fs/copy` 的合并语义、任务队列持久化、跨挂载的元数据损失）；插件侧基线 1.28.3。上游改版后以实测为准并顺手修正本页。
> 本页**不含任何明文密钥**。账号密码走运维侧凭据文件与记忆，不进仓库。
> **状态：规范已定，代码未实现（截至 1.28.3）。** 页面上的「常驻镜像挂载」「随配置增删同步」「迁移入口仅多配置开放」都还是设计（§4、§7），落地前插件里没有任何 OpenList 相关代码。

## §0 边界：它是迁移通道，不是存储后端

| 问题 | 答案 |
|---|---|
| 插件上传附件时是否经过 OpenList？ | **不经过。** 上传仍由 `Object_Store`（`src/S3/Object_Store.php`）直连存储桶。 |
| 插件是否依赖 OpenList 才能工作？ | **日常不依赖。** OpenList 挂了只影响「换桶迁移」这一个动作，上传、同步中心、缩略图全部照常；**保存存储配置也绝不受它影响**（见 §4.7）。 |
| 为什么不自己做桶到桶复制？ | 跨厂商没有服务端复制：`x-cos-copy-source` / OSS 的 `x-oss-copy-source` 只能表达「一个桶 + 一个键」，带不了第二个 endpoint 与第二套凭据。插件内部也没有 `CopyObject` 调用点，只能「下载到磁盘 → 再上传」中转。 |
| 那 OpenList 凭什么能跨厂商搬？ | 它在**服务端**同时挂载源与目标（自己持有两套凭据），流式读源写目标，站点只发一个 `/api/fs/copy` 然后轮询结果。带宽与内存都不经过 PHP。 |
| 挂载是临时的还是常驻的？ | **常驻。** 每份插件配置在 OpenList 上有一份长期镜像挂载，随配置增删（§4）。迁移时直接用，不现场建。 |
| 谁能建、谁能删挂载？ | **只有插件自己**，且只碰 `/sakura-tools/` 命名空间下带自己归属标记的挂载（§4.1）。别人的挂载一律只读。 |
| 什么情况下能用迁移？ | 分两种：**走 OpenList 通道**（本文 §7）需要**至少两份已保存配置**，只有一份时迁移入口整块置灰（§7.1）；**插件原生上传**（`wp sakura-s3 migrate`）不需要第二份配置，它就是 07 §6 换完配置之后的补传（§7.2 路线 A）。 |

三条边界规则，写代码前先记住：

1. **上传路径不引入 OpenList。** 引入常驻外部服务会把「传一张图」变成「两个系统都得活着」。
2. **挂载常驻但可随时重建。** 它只是「怎么读那个桶」的一份缓存描述，真正的数据在桶里，删了挂载什么都不会丢——所以对账失败永远不是紧急事故。
3. **删挂载不等于删桶。** 插件只会调 `storage/delete?id=` 删**挂载记录**，从不删桶、从不删对象。桶里的东西要清只能去厂商控制台。

## §1 本机部署事实

| 项 | 值 |
|---|---|
| 版本 | `v4.2.9 (Commit: 0497ee8)`，Frontend v4.2.9，Build 2026-10-25 05:29:12 +0000，Go 1.27.3（`/opt/openlist/openlist version`；`GET /api/public/settings` 的 `data.version` 同值） |
| 进程 | systemd 单元 `openlist.service`，`WorkingDirectory=/opt/openlist`、`ExecStart=/opt/openlist/openlist server`、`KillMode=process` |
| 二进制 | `/opt/openlist/openlist`（约 130 MB） |
| 数据目录 | `/opt/openlist/data/`（`data.db` = sqlite3、`config.json`、`log/`、`temp/`），权限 `drwx------ root` |
| 监听 | `0.0.0.0:5244`（`config.json` 的 `scheme.address`、`http_port=5244`、`https_port=-1`、`force_https=false`） |
| 本机地址 | `http://127.0.0.1:5244` |
| 公网地址 | `https://file.nboke.com`（nginx 反代，`proxy_pass http://127.0.0.1:5244`，`proxy_read_timeout 600s`） |
| 令牌有效期 | `config.json` 的 `token_expires_in = 48`（小时） |
| 管理员账号 | `3031379629@qq.com`（`x_users` id=1，role=2）。**密码不写在本页**，见运维凭据文件与记忆 |
| 普通账号 | `nboke`（id=4，role=0，permission=32767），只够做 `/api/fs/*` |
| 插件占用的命名空间 | `/sakura-tools/`（虚拟路径，不是挂载本身；不要手工往里放别的东西） |

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

> 机器上没有 `sqlite3` CLI，查库用 `python3 -c "import sqlite3; ..."`。库里的表：`x_users`（账号）、`x_storages`（挂载，**常驻镜像就存在这里，重启不丢**）、`x_task_items`（任务队列的持久化位，当前恒为空——因为 `config.json` 的 `tasks.copy.task_persistant` 是 `false`，见 §6.4）、`x_setting_items`、`x_meta`、`x_search_nodes`、`x_sharing_dbs`、`x_ssh_public_keys`。**没有 `x_tasks` 表**——任务默认只在内存里（打开持久化后才有落点）。

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

令牌 48 小时过期，过期后的响应同样是 `code:401 token is invalidated`。迁移任务应在一次执行周期内完成，不要跨天续用旧 token。

### §2.2 role 决定能做什么

| 端点组 | role=0（普通用户） | role=2（管理员） |
|---|---|---|
| `/api/fs/*`（list / get / put / mkdir / remove / copy） | ✅ 可用 | ✅ 可用 |
| `/api/admin/storage/*`（建 / 改 / 查 / 删挂载） | ❌ `{"code":403,"message":"You are not an admin"}` | ✅ 可用 |
| `/api/admin/driver/*`（驱动清单与字段定义） | ❌ 403 | ✅ 可用 |
| `/api/task/copy/undone`、`/done` | ✅ 可用 | ✅ 可用 |

**关键结论：搬运本身不需要管理员权限；建、改、删常驻挂载才需要。** 所以插件若要做到「新增配置即自动挂载」，必须持有管理员凭据。

### §2.3 插件侧凭据怎么存

| 方案 | 结论 |
|---|---|
| 存管理员用户名 + 密码 | **采用。** 用与 S3 密钥同一套 `Credential_Cipher`（`src/S3/Credential_Cipher.php`）加密后存进**独立 option** `sakura_imgopt_openlist`，明文不落库、不进日志、不回显到页面；卸载时进 `Uninstaller` 清理清单 |
| 只存 token | 不采用。48 小时就要人工重新登录，迁移功能会变成「两天可用一次」 |
| 用普通账号 | 不采用。role=0 建不了挂载，等于自动同步整个做不了 |

登录得到的 token 缓存进 transient（键里带 `url + 账号` 的 hash，TTL 取 48 小时与配置值中较小者但每次用前先探活），遇到 401 就重新登录一次再重试，最多一次。

**为什么不复用 `s3_options`**：那份 option 由 `Settings_Saver` 按「一张卡一个 option group 切片」的约定整体重写（`src/Admin/Card_Form.php:112` 的 `guard_options()` 与之配套），混进 OpenList 字段会让「保存一张卡清空另一张」的老问题复发；而且 OpenList 凭据与某一份存储配置无关，它是站点级设置。

## §3 挂载管理 API

### §3.1 建挂载

```http
POST /api/admin/storage/create
```

```json
{
  "mount_path": "/sakura-tools/prof_9463a17e7884",
  "order": 0,
  "remark": "sakura-tools:{\"profile\":\"prof_9463a17e7884\",\"name\":\"缤纷云 · nboke\"}",
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

三个必须记住的点：

1. **`addition` 是 JSON 字符串，不是对象。** 传对象会得到 `HTTP 200` + `{"code":400,"message":"model.Storage.Addition: ReadString: expects \" or n, but found {, error found in #10 byte of ..."}`——它把这个字段当 Go 的 `json.RawMessage` 读，所以必须自己 `json_encode()` 一次。
2. **重复的 `mount_path` 不会报 HTTP 错误**，而是 `HTTP 200` + `{"code":500,"message":"failed create storage in database: constraint failed: UNIQUE constraint failed: x_storages.mount_path (2067)","data":{"id":0}}`。注意 `data.id` **存在且为 0**，所以判断成功必须看 `body.code == 200`，不能看 `data.id` 有没有。
3. **`code != 200` 也可能已经落库。** 字段类型错时返回 `{"code":500,"message":"failed init storage but storage is already created: ...","data":{"id":30}}`——`data.id` 是**真实存在**的挂载 id，它已经写进 `x_storages`、`status` 里塞着错误原文。这种情况必须自己 `storage/delete` 清理，否则会留下一个永久报错的空壳，还占着 `mount_path` 的 UNIQUE 位置。

### §3.2 查挂载

```http
GET /api/admin/storage/list     → data.content[]、data.total
GET /api/admin/storage/get?id=N → data（单条）
```

`storage/list` 的每一项字段：`id`、`mount_path`、`order`、`driver`、`status`、`addition`、`remark`、`disabled`、`disable_index`、`enable_sign`、`web_proxy`、`webdav_policy` 等。

- **`status` 正常是字符串 `work`**；初始化失败时它里面直接是错误原文（可能很长）。
- **`addition` 在这里是 JSON 字符串**（string），可以直接 `json_decode()`。

### §3.3 改挂载

```http
POST /api/admin/storage/update
```

body 是**完整的 Storage 对象**——最稳的写法是 `GET storage/get?id=N` 拿回来、改要改的字段、整条发回去。

| 试过的错法 | 结果 |
|---|---|
| 只发 `{id, remark}` | `{"code":400,"message":"Key: 'Storage.MountPath' Error:Field validation for 'MountPath' failed on the 'required' tag"}` |
| 把 `addition` 改成对象 | `{"code":400,"message":"model.Storage.Addition: ReadString: expects \" or n, but found {, ..."}` |
| 整对象 + 未改动的字符串 `addition` | `code:200`，且 `addition` 逐字段不变——**可以安全地无操作回写** |

**`mount_path` 可以改**（`/sakura-tools/_n1` → `/sakura-tools/_n1ren` 实测 200），但插件不需要这个能力：路径由 `profile_id` 决定，配置改名不会改路径。

### §3.4 删挂载

```http
POST /api/admin/storage/delete?id=<id>
```

成功返回 `{"code":200,"message":"success","data":null}`。

**删除是幂等的，但幂等要靠自己实现**：删一个不存在的 id 返回的是 `{"code":500,"message":"failed get storage: record not found"}`，不是 200。对账逻辑里必须把这条 message 当成「已经删掉了」，否则每次对账都会把「本来就没了」记成一次失败。

`mount_path` 有 UNIQUE 约束，所以「要在一个已被占用的路径上建挂载」只有两种可能：要么那是自己的旧镜像（先 update 或 delete），要么是别人的挂载（报冲突，**绝不越权删除**，见 §4.1）。

### §3.5 驱动与字段

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
| `sign_url_expire` | **签名有效期（秒），必须是 JSON 数字**。Doge 驱动必须 > 0，见 §3.7 |
| `placeholder` | 占位文件名，留空 |
| `force_path_style` | 路径风格。**MinIO 必须为 `true`**，见 §3.6 |
| `list_object_version` | `v1` / `v2`，本项目一律 `v2` |
| `remove_bucket` | **必须 `false`**（否则删挂载可能动桶本身） |
| `add_filename_to_disposition` | 留 `false` |
| `enable_direct_upload` / `direct_upload_host` | 走客户端直传时需要，迁移不用 |
| `user_agent` | 留空 |

> **类型是硬校验。** `sign_url_expire` 写成字符串 `"600"` 会让 `storage/create` 直接失败（`s3.Addition.SignURLExpire: readUint64: unexpected character`），而且挂载已经落库需要手工清（§3.1 第 3 条）。

### §3.6 各家实测结论（本站四份配置）

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

阿里云 OSS、Cloudflare R2、Backblaze B2 走 `S3` 驱动 + 默认字段，**尚未实测**；第一次接入时要按 §3.2 的 `status` 与一次 `fs/list` 确认，不要假定可用。

### §3.7 Doge 驱动的隐藏前提：`sign_url_expire`

多吉云用长期 AK/SK（`session_token` 留空）时**列表能过，复制会失败**，任务 `state=7`，`error` 是：

```
failed get [...]: failed get link: InvalidPresignExpireError: presigned URL requires an expire duration greater than 0
```

补上 `"sign_url_expire": 600`（数字）后同一复制 `state=2`、`total_bytes` 正确、目标侧列出同名同尺寸文件。

**插件侧生成 Doge 挂载时必须显式写入 `sign_url_expire`（建议 600）。**

## §4 常驻镜像挂载（核心）

插件的每一份**完整**存储配置，在 OpenList 上都有一份**常驻挂载**作为镜像：配置存在，挂载就在；配置新增，挂载跟着出现；配置改了凭据，挂载跟着更新；配置删了，挂载跟着消失。

这样做的理由只有一个，但很关键：**迁移时不需要现场建挂载**。建挂载要写数据库、要读凭据、失败要回滚、还要处理 UNIQUE 冲突，全是即时错误来源；改成常驻镜像之后，对账是**幂等、可重试、失败不影响插件**的后台动作。

### §4.1 命名与归属

| 项 | 规则 |
|---|---|
| `mount_path` | `/sakura-tools/<profile_id>`，例如 `/sakura-tools/prof_9463a17e7884` |
| `remark` | `sakura-tools:` + JSON `{"profile":"prof_xxx","name":"缤纷云 · nboke"}` |
| **归属判据** | `mount_path` 以 `/sakura-tools/` 开头 **且** `remark` 以 `sakura-tools:` 开头，**两者同时成立**才算自己的 |
| 越界处理 | 不同时成立的挂载一律视为「别人的」，**只读、不写、永不删除**；需要同一路径时报告冲突由人工处理 |

`remark` 实测能原样往返（含中文与空格）：写进去 `sakura-tools:{"profile": "prof_test1234567890", "name": "缤纷云 · nboke"}`，`storage/list` 拿回来一字不差。所以「插件存的配置名」可以顺带当挂载的显示名。

父路径 `/sakura-tools` **不需要预先存在**：直接在 `/sakura-tools/prof_xxx` 建挂载可以成功，`/sakura-tools` 也会自动能被 `fs/list` 列出子项。反过来说，**把最后一个镜像删掉之后 `fs/list /sakura-tools` 会返回 `{"code":500,"message":"failed get storage: storage not found"}`**——那是正常的，这个虚拟路径不是挂载本身，不要据此判断「挂载还在」。

### §4.2 驱动映射

| 插件 `provider` | OpenList `driver` | `addition` 关键项 | 实测 |
|---|---|---|---|
| `bitiful` / `tencent` / `aliyun` / `cloudflare` / `backblaze` | `S3` | 默认字段 | 缤纷云、腾讯云 ✅；其余未测 |
| `minio` | `S3` | `force_path_style: true` | ✅（仅同机可达） |
| `dogecloud` | `Doge` | `sign_url_expire: 600` | ✅ |

三条硬规则（都踩过）：

1. `sign_url_expire` 必须是 **JSON 数字**，不是字符串（§3.5）。
2. `addition` 必须是**单层** `json_encode` 的结果，不要二次编码。
3. `session_token` 留空、`remove_bucket` 必须 `false`、`list_object_version` 用 `v2`。

### §4.3 对账算法

对账只做三件事：缺的建、变的改、多的删。

```text
desired = { profile_id → addition }   来自所有 Options::is_configured() 为真的配置
actual  = { profile_id → {id, addition} }   来自 storage/list 里 remark 以 sakura-tools: 开头的挂载

for id in desired:
    if id not in actual:      create → 记下新 id
    elif 字段有变化:           update（整对象，见 §3.3）
for id in actual - desired:   delete（配置已被删除，见 §3.4 的幂等约定）
```

四条实现要求：

- **幂等。** 同样的输入跑多少次，OpenList 里的结果都一样；不能出现「跑第二次又多一个挂载」。
- **比字段，不比字符串。** `addition` 解码后逐字段比对——键顺序、空白都可能不同，整体字符串比较会误判成「变了」，然后每轮都无谓 update。
- **不完整的配置跳过。** `Options::is_configured()` 为假的配置不落地：半张表建出来的挂载只会永远拖着 500 错误。
- **探测 ≠ 对账。** 对账只保证「挂载记录存在且字段正确」；凭据到底能不能用，要看一次 `fs/list`（见 §4.5）。

### §4.4 什么时候同步

| 时机 | 动作 | 怎么做到不阻塞 |
|---|---|---|
| 新增 / 编辑配置保存成功后 | 同步这一份 | 跳转已发出后在 `shutdown` 里做，单次 HTTP 超时 2 秒，失败只记状态 |
| 删除配置后 | 删对应挂载 | 同上；**删挂载失败不回滚配置删除**（07 R8 第 7 步） |
| 打开迁移页 | 全量对账 + 探活 | 页面本来就慢一点，可以直接做 |
| 点「同步挂载」按钮 | 全量对账 + 探活 | 同上，结果就地渲染 |

**不做定时对账**（理由见 §11）。所以「实时同步」的准确含义是：**配置的每一次增删改都会触发对账**，而 OpenList 侧被人手工改坏的情况要等到下次迁移页打开或手工点同步才会被发现。

**存量配置怎么补上**：功能上线前就存在的配置（本站现在是 4 份）不会自己长出挂载。补齐点是「第一次打开迁移页」或「升级后第一次保存任一配置」——两者都走全量对账，一次补齐，不需要另写迁移脚本。第一次跑完要对结果做一次人工确认（`storage/list` 的 total 应该从 2 变成 6）。

### §4.5 配置改密钥：update，不重建

实测：先用错误的 AK/SK 建挂载，`storage/create` 照样返回 `code:200`、`status` 照样是 `work`，错误要等到 `fs/list` 才暴露：

```
failed get objs: failed to list objs: InvalidAccessKeyId: The access key ID you provided
does not exist in our records. status code: 403, request id: ...
```

用 `storage/update` 把凭据换成正确的，`code:200`，紧接着 `fs/list` 就正常了。

两条结论：

1. **改 `key` / `secret` 只需 update**，不用删挂载重建，也不会中断正在跑的搬运任务。
2. **`status=work` 不代表凭据可用。** 唯一的健康判据是做一次 `fs/list`。所以对账之后要对每个镜像做一次浅层 `fs/list`（`per_page=1`）当探活，把结果记进状态里给迁移页显示。

### §4.6 配置被删除：孤儿挂载

删配置后要删挂载。如果那一刻 OpenList 不可用，就会留下**孤儿挂载**：

- 它持有的是已经作废的凭据，读不出东西（`fs/list` 会 500）。
- 它占着 `/sakura-tools/<profile_id>` 这个路径，如果之后有人手工建了一份**同 id** 的配置（正常不会，`new_profile_id()` 是随机的），建挂载会撞 UNIQUE。
- 它**不构成数据风险**：删挂载不删桶，桶里的对象一直在。

处理方式：下次对账时 `actual - desired` 会自动把它清掉；迁移页顶部把孤儿挂载列出来并提供「清理孤儿挂载」按钮。

### §4.7 失败与降级（硬约束）

> **OpenList 不可用绝不能挡住保存存储配置。**

保存配置是本地动作，OpenList 是外部服务。对账失败时的正确行为是：

1. 保存照常成功、页面照常跳转、结果弹窗照常显示「云存储设置已保存」。
2. 对账错误写进 option `sakura_imgopt_openlist` 的 `state` 键（`array( 'at' => 时间戳, 'action' => 'create|update|delete|probe', 'profile' => id, 'error' => 原文 )`）。凭据与状态同住一个 option，共用一个 `Uninstaller` 条目。
3. 迁移页顶部显示「上次同步失败：<原文>」并提供「重试」。
4. **降级不是消失**：迁移入口可以打开，但「开始迁移」按钮 `disabled`，提示「OpenList 未就绪」。

| 场景 | 插件表现 |
|---|---|
| OpenList 连不上 / 502 | 配置照常保存；状态记错误；迁移按钮禁用 |
| 管理员凭据失效（401） | 重新登录一次；仍 401 则状态记「凭据失效」，迁移按钮禁用 |
| 建挂载撞 UNIQUE | 用自己的就 update，是别人的就报冲突（§4.1），不删 |
| 建挂载返回 `code!=200` 但带了 id | 按 §3.1 第 3 条删掉这个半成品，把错误记进状态 |
| 探活 403 | 挂载在、凭据错；提示「配置里的密钥可能已失效」，不影响迁移以外的一切 |

## §5 `/api/fs/*` 契约

### §5.1 列目录

```http
POST /api/fs/list
{"path": "/sakura-tools/prof_xxx/wp-content/uploads/2026/11", "page": 1, "per_page": 100}
```

响应 `data.content[]`（每项字段实测为 `name` / `size` / `is_dir` / `modified` / `created` / `type` / `hash_info` / `hashinfo` / `sign` / `thumb`）与 `data.total`、`data.write`、`data.provider`。`raw_url` 不在 list 里，只有 `POST /api/fs/get` 才返回。

- **空目录的 `content` 是 `null`，不是空数组。**
- `per_page` 无硬上限：`per_page=2000` 时返回 `min(total, 2000)`。判断是否取完要用返回的 `total`，不要靠「返回条数 < per_page」。
- `per_page < 1`（含不传）时**返回该目录的全部条目**：`internal/model/req.go:13-20` 把小于 1 的值置成 `MaxInt`，`fsread.go` 的 `pagination()` 直接切片。目录很大时单次请求会明显变慢，要控响应体就显式传 `per_page` 并用 `page` 翻页。
- S3 挂载的 `listV1` / `listV2` 在驱动内部按 continuation token 循环、按 `/` 分隔（`drivers/s3/util.go:165`），所以**一次 `fs/list` 拿到的就是该目录的完整列表**，不是 S3 的一个分页。插件侧 `Object_Store::list_page()`（`src/S3/Object_Store.php:542`）才需要 `next_token` 续翻。
- **点前缀对象被隐藏**：名字等于占位名（`drivers/s3/util.go:104-110`，默认 `.openlist`）的条目不返回，除非请求带 `S3ShowPlaceholder`。桶里确实有这些对象——路线 C 搬完会在目标每个目录留下一个，收尾要单独删（§7.5 C 路线第 7 条）。

### §5.2 复制（核心）

```http
POST /api/fs/copy
{"src_dir": "/sakura-tools/prof_src/wp-content/uploads/2026/11", "dst_dir": "/sakura-tools/prof_dst/wp-content/uploads/2026/11", "names": ["a.webp", "b.webp"]}
```

成功：`{"code":200,"message":"Successfully created N copy task(s)","data":{"tasks":[{"id":"...","state":0,...}]}}`。

**`/api/fs/copy` 是异步的**：返回只表示任务入队，实际搬运在服务端后台跑。

错误形态（都是 `HTTP 200`，必须判 `body.code`）：

| 场景 | `body.code` | message |
|---|---|---|
| 目标已存在同名文件 | 403 | `file [a.webp] exists` |
| 源对象不存在 | 500 | `failed to get src object: failed to get obj: object not found` |
| 目标目录不存在 | 500 | `failed to get dst dir: failed to get obj: object not found` |

→ 不带任何开关时，**目标已存在同名项就会 403**，整批任务因个别文件失败而反复重跑也不会前进。下面三种开关可以绕开 403，但**求差集仍是必需的第一步**：它是「哪些能跳过、哪些必须覆盖、哪些不敢覆盖」的唯一依据。

#### 目标已存在时怎么提交（2026-11-18 按 v4.2.9 源码核对）

`/api/fs/copy` 不只「已存在就 403」这一种行为。`MoveCopyReq` 带四个开关（`server/handles/fsmanage.go`），逐个 `names` 元素处理：

| 想要的语义 | 请求里带 | 实际行为 |
|---|---|---|
| 目标已有这个**顶层项**就跳过 | `"skip_existing": true` | 只跳顶层同名项，**不递归**；里面的文件不会被补齐 |
| 目标已有同名**目录**、把里面缺的补上 | `"merge": true` | 逐层列目标目录、按名字跳过已存在的项（`FileTransferTask` 的 `existedObjs`）；**不比较大小、不比较 ETag** |
| 确定要覆盖清单内的对象 | `"overwrite": true` | 跳过存在性检查，最终 `op.Put` 覆盖同名 key；清单外的对象不动 |
| 断点续跑／整棵树再搬一遍 | `"merge": true` | 幂等：已在的全跳过，缺的补齐 |

判定顺序（`server/handles/fsmanage.go` 的 `FsCopy`，逐 `names` 元素）：

~~~text
若 overwrite=false：
  目标有同名项？
    ├─ 没有 ─────────────────────────→ 提交复制任务
    └─ 有 ─┬─ !skip_existing && !merge → 403 file [x] exists（**整个请求中止**，不是这一项）
           ├─ !merge 或 同名项是文件 ──→ 跳过这一项（置空，不递归）
           └─ merge 且 同名项是目录 ──→ 走 fs.Merge（递归，逐层再按名字跳过）
若 overwrite=true：跳过存在性检查，直接 fs.Copy（目录则递归覆盖）
~~~

不检查存在性的代价与边界（同一段代码，写实现时必须照做）：

- **`overwrite` 不要当默认值。** 目标桶里被别人写过的同名对象会被静默替换，且换不回来（源桶还在，但目标上的那一版没了）。
- **`merge` 不会修「同名不同内容」。** 它只按名字判存在，所以「源 81408 B、目标 70000 B」这种冲突带 `merge` 提交后目标仍是旧的。要修只能 `overwrite` 打明确清单，或者用插件重传（§7.4）。
- **`skip_existing` 与 `merge` 同时给等于 `skip_existing` 生效**（先命中顶层的存在性判定），不要混用。
- 每个 `names` 元素是一个独立任务；目录任务会递归派生子任务，所以 `/done` 里会出现一批你没提交过的 id（见 §5.2 末尾的壳任务警告与 §6.5 结算）。

#### 跨挂载复制会丢什么（v4.2.9 源码核对）

「同一个挂载内复制」和「源挂载 → 目标挂载」走的是两条完全不同的路径（`internal/fs/copy_move.go` 的 `transfer()`）：只有 `srcStorage.GetStorage() == dstStorage.GetStorage()`（**同一条挂载记录**，不是同 provider、也不是同桶名）才走原生 `op.Copy`（S3 侧是 `CopyObject`，`MetadataDirective` 默认 COPY，Content-Type / ETag / 自定义元数据都保留）。

跨挂载时改为「读源 → 流式上传到目标」（`stream.NewSeekableStream` + `op.Put`），于是：

| 丢的东西 | 后果 | 谁能修 |
|---|---|---|
| 源对象的 `Content-Type` | 由目标**文件名扩展名**重猜（`pkg/utils/file.go:170` 的 `GetMimeType`，查不到就 `application/octet-stream`） | 只有插件重传（`Object_Store::upload_file` 用 `wp_check_filetype`） |
| 对象级 ACL（`x-amz-acl`） | `drivers/s3/driver.go:200` 的 `Put` 不带 ACL → 落成桶默认策略；私有桶直接 403 | 桶策略（§7.3 ②）或插件重传 |
| `Cache-Control` / `Content-Disposition` / 自定义元数据 | 不写入目标 | 桶级默认或插件重传 |
| 单一 ETag | 目标 >5 MB 的对象走分片上传，ETag 变成 `"...-N"`，不再是 32 位 MD5 | 无法修；插件 `verify` 会把它标 `unverifiable` |
| 目录结构本身 | 每个目录多一个 `.openlist` 占位对象 | `fs/remove`（§7.5 C 路线第 7 条） |

→ 这就是 §7.2 把「插件原生上传」排在「OpenList 直搬」前面的技术原因：**搬得动 ≠ 搬得对**。

#### 其余已确认的行为

- **`dst_dir` 必须已经存在。** 复制到不存在的目标目录会直接失败（见上表）。所以要自己先 `POST /api/fs/mkdir` 把目标目录建出来。**会自建父目录的是 `PUT /api/fs/put`（上传），不是 `fs/copy`**——这两件事不要混。
- 复制**目录**时返回的是一条**壳任务**：提交瞬间 `state=0`，随后 `state=2` + `status="src object is dir, added all copy tasks of objs"` + `progress=0` + `total_bytes=0`。

> ⚠️ **壳任务的 `state=2` 只代表「子任务都派发出去了」，不代表文件搬完。** 实测一次目录复制：壳任务 1 秒内就 `state=2`，而真正的文件搬运是另一条独立 id 的子任务（`status=uploading`、`total_bytes=5`）。**若只看壳任务就宣布成功，会在文件还没搬完时就进入收尾（删旧配置 → 切到目标配置 → 启用），站点直接指向一个空桶。** 正确做法：提交前快照 `done` 的 id 集合，之后把「新出现的所有任务」一起结算，直到它们全部 `state=2` 或 `7`（判定顺序与重试语义见 §6.5）。

### §5.3 其余端点

| 端点 | 说明 |
|---|---|
| `POST /api/fs/get` | 文件元信息，`raw_url` 形如 `http://127.0.0.1:5244/p/...`（服务端内网地址，站点侧不可直接当 CDN 用）；对目录也返回 200，但 `raw_url` 是空串 |
| `PUT /api/fs/put` | 上传原始字节，路径放请求头 `File-Path: /a/b.txt`；会自动建父目录 |
| `POST /api/fs/mkdir` | 建目录 |
| `POST /api/fs/remove` | `{"dir": "/x", "names": ["a", "b"]}` |

## §6 任务队列：唯一可信的是 `state`

### §6.1 两个队列

```http
GET /api/task/copy/undone   → {"code":200,"data":[...]}
GET /api/task/copy/done     → {"code":200,"data":[...]}
```

任务字段：`id`、`name`、`creator`、`creator_role`、`state`、`status`、`progress`、`start_time`、`end_time`、`total_bytes`、`error`。

### §6.2 `state` 语义（实测四种）

| state | 含义 |
|---|---|
| 0 | 刚入队 |
| 1 | 运行中 |
| 2 | **成功** |
| 7 | **失败**（`error` 里有原文） |

**`status` 文案不可信**：成功任务的 `status` 也可能是 `uploading`。判结果只看 `state`。

### §6.3 端点可用性（踩过的坑）

| 想做的事 | 结论 |
|---|---|
| 查单个任务 | 只能 `POST /api/task/copy/info?tid=<id>`；**`GET` 同一 URL 返回 SPA 的 index.html**。tid 不存在时 `{"code":404,"message":"task not found"}` |
| 清空队列 | `POST /api/task/copy/clear/done`、`/clear/succeeded` **不存在**，返回 SPA HTML。队列只能等它自己自然消失 |
| retry / cancel / delete | 三个端点都**存在**（2026-11-17 复测：假 tid 一律 `{"code":404,"message":"task not found"}`，说明路由命中、只是找不到任务），但真任务上的语义未验证，迁移不要依赖 |

### §6.4 队列默认不持久化

队列持久化的开关是 `config.json` 的 `tasks.copy.task_persistant`（键名常量 `conf.Tasks.Copy.TaskPersistant`，默认 `false`；`internal/bootstrap/task.go:25` 据此决定是否接 `db.GetTaskDataFunc("copy", ...)` 落库，`internal/db/tasks.go:25`）。本站现在 7 处 `task_persistant` 全是 `false`（`/opt/openlist/data/config.json:78-108`），所以：

- `data.db` 的 `x_task_items` 四行（`copy` / `move` / `download` / `transfer`）`persist_data` 都是 `[]`——**这是开关的结果，不是「队列天生不落盘」的证据**。
- **OpenList 重启，未完成的任务队列即丢。** 已提交但还没结算的任务，重启后既查不到也补不回来。
- 打开持久化后落点的仍是这四类任务；`transfer` 是跨挂载复制的队列名（`/api/task/transfer/*`），排查跨挂载慢或失败时要看它，不是 `copy`。

因此可靠的做法只有一条：

> **在提交任何复制任务之前，先快照一遍 `/api/task/copy/done` 的 id 集合（记作 `baseline`）。之后只认「done 里新出现、且不在 baseline 里」的 id。**

插件侧还必须自己落盘「本次迁移已提交的文件清单」，因为队列丢了就再也问不出谁搬过。

这份清单存进独立 option（例如 `sakura_imgopt_openlist_migrate`，`array( 'source' => id, 'target' => id, 'started_at' => 时间戳, 'keys' => array( ... ), 'done' => array( ... ) )`），并且**必须同时进 `Uninstaller` 的清理清单**——它是站点级状态，不属于任何一份存储配置，删配置时不能把它一起清掉。

### §6.5 结算：什么时候算「搬完了」

| 端点 | 返回什么 |
|---|---|
| `GET /api/task/copy/undone` | 未完成：`pending` / `running` / `canceling` / `errored` / `failing` / `waiting_retry` / `before_retry` |
| `GET /api/task/copy/done` | 终态：`canceled` / `failed` / `succeeded` |

结算顺序（缺一步就会误判）：

1. **提交前**快照 `done` 的 id 集合，记作 `baseline`（§6.4）。
2. 轮询 `undone` 直到**为空**——不是等壳任务，壳任务派发完就 `state=2` 了（§5.2）。
3. 读 `done`，只看**不在 `baseline` 里的 id**。
4. 这些新 id 里 `failed` / `canceled` 为 0 → 这一批算完；不为 0 → 记 `error` 原文，**重新 `fs/list` 求差后只重提差集**，不要整批重跑。

几条容易踩的：

- `errored` / `failing` 的任务会**自动重试**（`MaxRetry=2`）并留在 `undone` 里，所以「`undone` 非空」不等于失败，等它退避重试完。**不要自己重提**，否则同一批对象出现两份任务。
- 轮询从 2 s 起指数退避到 30 s；总超时按「源对象总字节 ÷ 目标写入带宽 ÷ 并发」估，别按固定 60 s 判超时。
- 复制并发由 `copy_task_threads_num` 控制（常量 `conf.TaskCopyThreadsNum`，`internal/conf/const.go:171`，默认 5，后台「任务设置」里改）。调大之前先确认源桶读带宽与目标桶写带宽撑得住，否则只是把失败率提上去。
- `/api/task/copy/info?tid=` 只能 `POST`，`undone` / `done` 用 `GET`（§6.3）。
- OpenList 中途重启过：队列已丢，`undone` 与 `done` 都不能作为进度依据，**以「重新 list 目标、重新求差」为准**。

## §7 迁移方案：先选路线，再定参数

> 本章是**做法**，不是代码。能把对象放进目标桶的路径有三条：插件原生上传（`wp sakura-s3 migrate`）、同步中心（下载 / 上传 / 删除）、以及本文这套 OpenList 通道（代码未实现）。三条路都能搬，但**只有插件原生上传同时保住 Content-Type、对象 ACL、MD5 回读校验与索引记账**。选错路线不会报错，只会让「迁移成功」变成一句谎话。

### §7.1 迁移卡入口（只约束 OpenList 通道）

| 条件 | 不满足时 |
|---|---|
| **已保存的完整配置 ≥ 2** | 迁移卡整块 `disabled`，`title` 写「只有一份存储配置时没有可迁移的目标。迁移是把对象从 A 桶搬到 B 桶，至少需要两份配置。」 |
| OpenList 已接通且管理员凭据可用 | 卡片可见，按钮 `disabled`，提示「OpenList 未就绪」 |
| 源与目标都 `is_configured()` 为真 | 下拉里把不完整的配置 `disabled` 掉 |

**前端置灰只是提示，服务端必须同样拒绝**：配置数 `< 2` 时迁移动作返回 `error=not_enough_profiles`。把门槛只写在前端等于没写。

**为什么必须 ≥ 2**：迁移的收尾是「删掉源配置 → 切到目标配置」。删掉源配置后才算解绑，绑定期间切过去只会换「用哪份配置」而不会搬任何对象。**如果只有一份配置，删掉它之后没有任何可切换的目标**，站点会退化成 S0 空态——那不是迁移，是自毁入门。

这条门槛只约束「插件代你调 OpenList」的路线（C/D）。**路线 A 不受它约束**：路线 A 走 07 §6 的换桶路径，先删源配置再新增目标配置，全程只有一份配置，也不需要 OpenList。

### §7.2 路线选择：三个问题定优先级

先回答三个问题，它们是选择过程而不是结论：

1. **本地是否持有每个对象的副本？** 站点 `no_local_saving` 为 `false`，且 `wp-content/uploads` 没被清理过。
2. **能否接受一次「关 S3 → 切配置」的窗口？** 窗口里新上传只落本地、既有媒体 URL 回落站点域名（`Storage::should_keep_local()`，`src/S3/Storage.php:882`），响应式尺寸候选被过滤（`filter_srcset()`，`src/S3/Storage.php:729`）——**页面不会 404，只是降级**。
3. **目标桶是否需要「元数据也对」？** 指 Content-Type、对象 ACL、能用本地 MD5 校验。需要就排除 OpenList 直搬。

| 优先级 | 路线 | 数据路径 | 适用 | 代价 / 风险 |
|---|---|---|---|---|
| **P0（默认）** | **A 插件原生上传** | 本地 → 目标桶（`Object_Store::upload_file`） | 本地完整、能接受切换窗口 | 需先删源配置（07 §6）；窗口内新上传只落本地；只付目标入流量 |
| P1 | **B 先补齐本地** | 源桶 → 本地（同步中心下载）→ 再走 A | 本地缺一部分（曾开 `no_local_saving`、或本地被清理） | 多一份源桶出流量与磁盘；下载会走 R13/R14 记账 |
| P2 | **C OpenList 直搬** | 源桶 → OpenList → 目标桶 | 本地没有副本，也不打算要 | 丢 Content-Type / 对象 ACL / 自定义元数据；>5 MB 变分片 ETag；目标每个目录多一个 `.openlist`；只能靠大小与抽样校验 |
| P3 | **D OpenList 落到本地** | 源桶 → 本地（Local 挂载） | 目标是本地磁盘，或想先把本地补齐（再走 A） | 与 B 同级，且本地覆盖写非原子、不写插件索引 |
| **禁止** | **E OpenList 本地 → 目标桶** | 本地 → Local 挂载 → S3 挂载 | 排障、临时验证 | 绕开插件记账与指纹；Content-Type 靠扩展名猜；ACL 丢失；无法用索引对账 |
| **禁止** | **F 让 OpenList 当上传后端** | — | 永远 | 见 §11 第一条 |

决策树：

~~~text
本地是否有每个对象的副本？
├─ 有 ──→ 能否接受一次切换窗口？
│         ├─ 能 ──→ P0（A）。本站默认答案。
│         └─ 不能（必须在线完成）──→ P2 预填 + P0 补差（§7.5 混合）
└─ 没有（或只有一部分）
          ├─ 缺的这部分以后还要从媒体库访问吗？
          │   ├─ 要 ──→ P1（B）：先把本地补齐，再 P0
          │   └─ 不要 ──→ P2（C）
          └─ 目标根本不是 S3 桶（本地磁盘）──→ P3（D）
~~~

**P0 为什么是默认**：上传由 `Object_Store` 完成，它设 `ContentType`（`wp_check_filetype`）、带对象 ACL（`object_acl`，默认 `public-read`）、单段带 `Content-MD5`、默认 HEAD 回读校验 ETag＝MD5（`src/S3/Object_Store.php:74`）；失败重试与断点续跑由 `History_Migrator` 的 `sakura_imgopt_s3_migrate_state`（`src/S3/History_Migrator.php:21`）负责；上传成功后附件状态、索引、用户文件空间账本（R13/R14）都由插件自己的代码写回。**P0 的代价只有一个：它需要先把配置切到目标**，而切配置要按 07 §6 删源配置。

**P0 什么时候必须放弃**：

- `binding['no_local_saving']='true'`（高风险锁定，07 R10）：本地没有副本，P0 无米下锅 → P1 或 P2。
- 本地附件被清理、`_wp_attached_file` 指向不存在的文件 → 先 P1。
- 本地磁盘装不下「源桶全量 + 现有本地」（B/D 需要）→ P2。
- 只想尽快让桶可用、源桶出流量不心疼、也不需要本地副本 → P2（不下载到本地，最快）。

> 一句话结论：**「本地有附件」时，OpenList 不该参与。** 用户设想的「先 OpenList 下载多吉云到本地再传腾讯云」＝ B＋A 的组合，比 A 多付一份源桶出流量和一份磁盘，换来的只是「本地又存了一份已有副本」；「OpenList 读本地媒体库传腾讯云」＝ E，明确禁止。真正值得用 OpenList 的是「本地没有、也不想要本地副本」的 P2。

### §7.3 全新目标桶怎么设计

新桶最容易出两类事故：迁移完成后图片 403（权限 / 防盗链），或迁移后 CDN 变慢、计费翻倍（版本 / 生命周期 / 回源）。按六组定，每组都有对应验收（§10）。

**① 身份字段（与 `Options::IDENTITY_FIELDS` 对齐，`src/S3/Options.php:133`）**

| 字段 | 新桶取值 | 为什么 |
|---|---|---|
| `provider` | `tencent` | 决定 SDK 分支与 multipart ETag 的例外名单（`src/S3/Object_Store.php:134-143`） |
| `bucket` | 带 appid 后缀的桶名（`name-1250000000`） | 腾讯云要求；写错是 `NoSuchBucket` |
| `endpoint` | `https://cos.<region>.myqcloud.com` | **写法必须与保存值一致**：`endpoint` 是身份字段，改写法等于换桶 |
| `region` | 与源桶同地域（多吉云在 `ap-chengdu`） | 同地域少一次跨地域回源 / 上传的延迟；`region` 参与 R4 的换桶判定，**但不进对象指纹**（见下） |
| `upload_path` | **与源完全一致**：`wp-content/uploads` | 对象 key ＝ `upload_path` ＋ 相对路径；改了它所有 key、URL、索引指纹一起变（R19），这是「最贵的那个字段」 |
| `force_path_style` | `false` | 与 §3.6 的 tencent 样本一致 |

其余驱动字段照 §3.5：`list_object_version=v2`、`remove_bucket=false`；`sign_url_expire` 只在多吉云需要，腾讯云不需要。

> **两套字段清单不要混**：R4 冻结与「算不算换桶」用五项（`Options::IDENTITY_FIELDS`，`src/S3/Options.php:133`）；写进 post meta / 队列 / 删除队列的**对象指纹**只用四项（`Bucket_Identity::ADDRESS_FIELDS`，`src/S3/Bucket_Identity.php:38`，版本前缀 `v2`），`region` 不在里面。所以「改 `region`」＝换桶（要按 07 §6 重来一遍），但已同步附件的指纹不会失效、不用重传；反过来，动 `upload_path` 会让全部指纹作废，是最贵的一次改动。

**② 权限：默认公有读**

- 开「公有读私有写」（桶 ACL `public-read`）。原因不是省事：**OpenList 跨挂载 PUT 不带对象 ACL**（`drivers/s3/driver.go:200` 只设 `ContentType`），路线 C 搬过去的对象在私有桶里一律 403；插件自己上传的对象虽然按 `object_acl` 单独授权，但把权限寄托在「每个对象都带 ACL」上，任何一次路线 C 的补搬都会破坏它。
- 备选：保持私有桶 ＋ 桶策略只放行该前缀的 `GetObject`（更严格，但要多验一次 CDN 回源身份）。
- **关防盗链**（或把站点域名、CDN 加速域名、`file.nboke.com` 一起加白）：图片外链与媒体库预览都不带 Referer，开了白名单就是全站 403。
- CDN：回源到桶默认域名、回源协议 HTTPS、缓存键忽略查询串（附件 URL 常带 `?ver=`）。

**③ 路径与域名**

- `upload_path` 不要换前缀（上面已说），也不要用 `.`（根）——那是另一套 URL 归一逻辑（`normalize_root_url()`，`src/S3/Storage.php:968`）。
- **公网域名保持不变**（本站 `https://bf.nboke.com/wp-content/uploads`）：只切 CDN 回源，`upload_url_path` 不动，正文里的图片地址不用重写，`srcset` 基址跟着 `wp_get_upload_dir()` 走也不用动。
- 真要换域名，顺序是：改 `upload_url_path` → Url_Rewrite 规则（R12）→ 正文 / 摘要 / 自定义字段批量替换 → CDN 预热 → 抽样验证。这一步与「搬对象」是两件事，能不做就别做。
- 桶必须空，或只用你这一个前缀：迁移前 `fs/list` 目标前缀确认 `total=0`（§7.5 的「空桶校验」）。与别的站点共用前缀，会让 §7.4 的「同名不同内容」从理论风险变成数据事故。

**④ 版本与生命周期**

| 配置 | 取值 | 理由 |
|---|---|---|
| 版本控制 | **关闭** | 开着的话每次覆盖写都留历史版本，容量与费用不可控 |
| 生命周期 · 未完成分片 | 1 天后删除 | OpenList 跨挂载上传走分片（s3manager，5 MiB 一片），中断会留碎片；插件上传 >16 MB 也走分片 |
| 生命周期 · 转低频 / 归档 | **不要配** | CDN 回源命中归档层会先取回（延迟 ＋ 取回费），图片站等于全站变慢 |
| 生命周期 · 过期删除 | **不要配** | 与「媒体库是唯一事实源」冲突 |
| 存储桶复制 | **不要开** | 那是异地容灾，会把迁移变成持续计费的双写 |

**⑤ 迁移前校验（空桶最容易掩盖挂错）**

1. `POST /api/fs/list` 目标挂载的 `<upload_path>` 目录：200、`total=0`（或把已有对象纳入 §7.4 差集）。
2. `POST /api/fs/get` 一个不存在的 key → 必须报 `object not found`（说明读路径通，不是挂到了别的桶）。
3. 写探针：往目标前缀写一个 1 字节对象再删（`PUT /api/fs/put` ＋ `POST /api/fs/remove`，或控制台手建手删），确认写权限；用 `curl -I` 取一次，看 `200` 与 `Content-Type`。
4. `POST /api/admin/storage/list`（或 `get`）确认 `addition` 里的 `bucket` / `endpoint` / `region` 与插件里保存的配置**逐字一致**（§3.5：`addition` 是 JSON 字符串，字段名拼错不报错，只会让驱动退化成默认值）。
5. 目标挂载 `status=work` 不代表凭据可用（§3.6 的实测结论）：一定要跑第 1、3 步真实读写。

**⑥ 记录基线（迁移后逐项对齐）**

~~~bash
wp post list --post_type=attachment --format=count        # 附件数
find wp-content/uploads -type f | wc -l                   # 本地文件数（含子尺寸）
wp sakura-sync stats                                      # 索引行数（按 status 分）
~~~

源桶对象数 / 总字节用 OpenList 递归 `fs/list` 汇总，或迁移后用 `wp sakura-sync scan --full` 的 `summary`；目标桶基线应为 0。

### §7.4 目标桶已有部分相同文件怎么迁

先立一条判定顺序：**权威顺序是「本地文件 > 源桶 > 目标桶」**。理由：插件判断「已同步」用本地 MD5 与桶 ETag（`Verify_Service`），本地是唯一能算出哈希的基准；本地没有时源桶才是原始；目标桶里的任何版本都只是「上一次尝试的产物」，不能反客为主。

把两侧清单（源 S、目标 T，都是 `key → size`）分成四类：

| 分类 | 判定 | 默认动作 | 说明 |
|---|---|---|---|
| `S\T` | 目标没有 | 搬 | 正常增量 |
| `S∩T` 且 size 相同 | 视为已存在 | 跳过 | **大小相同不代表内容相同**：内容正确性交给 `wp sakura-sync verify`（比 `local_md5` 与 32 位 ETag） |
| `S∩T` 且 size 不同 | 冲突 | 按权威顺序覆盖（默认源覆盖目标） | 只对这一张清单用 `overwrite=true` |
| `T\S` | 目标多出 | **不删** | 记入孤儿清单；切到目标后 `scan` 会把它标成 `remote`，清不清由人决定（07 §10 明确不做自动清点） |

**OpenList 自己判不了内容**：`merge` 只按文件名跳过已存在项（`internal/fs/copy_move.go` 的 `FileTransferTask` merge 分支），`overwrite` 直接 `Put` 覆盖；两种情况都不比 size，更不比 ETag。所以「靠再搬一遍把内容修对」行不通——同名项会被 merge 跳过，除非显式给覆盖清单。

四种提交方式怎么选：

| 参数组合 | 语义 | 用在 |
|---|---|---|
| 不传任何参数 | 目标有同名项（文件或目录都算）→ `403 file [x] exists`，**整个请求中止** | **只在确认目标为空时用**，用来暴露「挂错桶」 |
| `merge=true` | 按名字合并、跳过目标已存在的同名文件；目标同名项是目录时递归 | **默认的幂等提交方式**（断点续跑、目标已有部分对象） |
| `skip_existing=true` | 只把同名项**整项跳过**，不递归、不补齐；只在 `merge=false` 时与「不传」有区别 | 单层目录补缺；**不要**用它代替 merge |
| `overwrite=true` | 跳过存在性检查，直接 `Put` 覆盖 | 只对「冲突清单」用；整棵树覆盖会把迁移期间新上传的对象一起打回旧版本 |

**切到目标之后的正规校验与修复**（插件自己有链路，不要另写脚本）：

1. `wp sakura-sync scan --full`：把目标桶对象写进 `nb_sio_sync_index`，行状态由对账 SQL 给出（`src/Sync/Index_Repository.php:999-1007`）：本地与远端都在且 size 相同 → `synced`；size 不同 → `mismatch`；只有远端 → `remote`；只有本地 → `pending`。**它只比大小。**
2. `wp sakura-sync verify --seconds=60`：对已同步的行比本地 MD5 与远端 ETag；只有 32 位 hex 的 ETag 可比，分片（>5 MB）标 `unverifiable`（`src/Sync/Checksums.php`、`src/Sync/Verify_Service.php`）。
3. 修：
   - 有本地文件 → `wp sakura-s3 migrate` 只重传「未被 `Bucket_Confirmation` 确认」的附件（它只认索引里 `status=synced` 且桶 hash 匹配的行，`src/S3/Bucket_Confirmation.php`）。
   - 只想修一批 key → 同步中心批量上传（`keys` ≤ 200，云端已存在要输入 `REPLACE`，`src/S3/Sync_Center_Module.php:790`）。上传是 `PUT` 覆盖，会带上正确的 `ContentType` / ACL / `Content-MD5`。
   - 本地没有、内容又可疑 → 只能从源重取（路线 C 对单个 key `overwrite`），或接受并记入遗留清单。
4. **禁止的修复**：整棵树再 `copy` 一遍。`merge` 会全部跳过（白跑），`overwrite` 会全部覆盖（把迁移窗口里新上传的对象打回源版本）。

三个具体例子（都以 `wp-content/uploads/2026/11/a.webp` 为例）：

| 源 | 目标 | 结果 |
|---|---|---|
| 81408 B | 无 | merge 搬过去；scan 后 `synced` |
| 81408 B | 81408 B（内容其实不同） | merge 跳过；scan 判 `synced`（同大小）；**只有 verify 能抓出 `mismatch`** → 同步中心上传 `REPLACE` |
| 81408 B | 70000 B | 冲突；单独一批 `overwrite=true` 覆盖；scan 后 `synced` |

### §7.5 四条路线的手册

#### A 路线（P0，本站默认）：插件原生上传

**本站场景（多吉云 → 腾讯云，本地有附件）就是这条。** 前提：`no_local_saving` 为 `false`、本地 `uploads` 完整、能接受一次切换窗口。

冻结前的检查（此时源配置还启用着，命令才有意义）：

~~~bash
wp sakura-s3 migrate --dry-run --limit=1   # 配置可用性；skipped 计数＝没有本地文件的附件数
wp post list --post_type=attachment --format=count
find wp-content/uploads -type f | wc -l
wp sakura-sync stats
~~~

`--dry-run` 的读数怎么用：输出 `Scanned x/y | uploaded u | skipped s | failed f`，其中 `s` 就是「附件在、本地文件不在」的数量。**s 不为 0 时不要走 A**，先走 P1 把本地补齐，否则这些附件迁过去只有元数据没有文件。

同时把源配置抄下来（回滚要用）：`provider / bucket / endpoint / region / space / upload_path / upload_url_path`，以及 Access Key 末四位与密钥的存放位置。插件不回显密钥，删除配置后只能从厂商控制台或运维记录找回。

步骤：

| # | 动作 | 断言 / 说明 |
|---|---|---|
| 0 | 备份 DB；`find wp-content/uploads -type f > /root/uploads-before.txt` | 迁移前后能逐个对 file 清单 |
| 1 | **冻结**：点当前配置卡 → 关闭 S3（`enabled=false`） | 新图只落本地、URL 是站点域名；确认缩略图改写总开关已关（R16） |
| 2 | **切配置**（07 §6）：删源配置 → 新增腾讯云配置（§7.3）→ 点目标卡、首次上传成功 → `wp sakura-sync scan --full` | 清掉 R18 闸门；`active_profile_id`＝目标；既有媒体回落站点域名（`should_keep_local()`＝true），`srcset` 候选被过滤 |
| 3 | `wp sakura-s3 migrate --batch-size=500 --seconds=30`，跑到 `done` | 断点续跑靠 option `sakura_imgopt_s3_migrate_state`（`cursor` / `target_hash`，换桶自动重置）；失败项入 `failed`，重跑即可，已确认的会 `skipped` |
| 4 | `wp sakura-sync verify --seconds=60`，再 `wp sakura-sync scan --full` | 无 `mismatch`；`synced`数 = 附件对象数；`unverifiable` 只应出现在 >5 MB 对象上 |
| 5 | **切 CDN 回源到新桶** | 先 `curl -I` 抽样 ≥10 个对象（原件、子尺寸、`.webp` 各一）确认 `200` 与 `Content-Type`，再切；切完清缓存 |
| 6 | 观察期 ≥ 1 天 | 看 `debug.log` 有无 `sakura_s3_*` 报错、同步中心数字是否回零、抽样访问 |
| 7 | 观察期 ≥ 7 天后删源桶 | 插件不提供删桶；删之前确认没有对象只在源桶（第 4 步的 `remote` 行应为 0） |

**窗口影响（要写进运维公告）**：冻结后新上传只落本地、URL 回落站点域名（正常显示）；切配置后、CDN 回源切换前，**已上传到目标的对象**通过 CDN 仍回源到源桶，同 key 命中，外部访问无感。真正的例外只有一种：迁移前就从未成功上传到源桶、迁移中才补传到目标的本地文件——它们在 CDN 切回源之前会 404。把第 3、5 步排在一起，窗口能压到分钟级。

#### B 路线（P1）：先用同步中心把本地补齐，再走 A

适用：`migrate --dry-run` 的 `skipped` 不为 0，但缺的不多。

1. **不要动配置**（源配置还是活动配置）。
2. 同步中心先跑一次核对（`scan`），让「远端有、本地没有」的对象进索引（`remote` / 本地缺失）。
3. 同步中心批量下载：`keys` ≤ 200／次，`seconds` 3–60。下载走 `Sync_Actions::download()`（`src/S3/Sync_Actions.php:70`），**本地已存在会直接拒绝覆盖**（`sakura_s3_local_exists`），正好是「只补缺」的语义；成功后写回 `synced`、`Attachment_Adopter`、用户文件空间账本（R13/R14）。
4. 下载完用第 0 步的 file 清单核对数量，再走 A。

注意：OpenList 也能做「源挂载 → Local 挂载」，但那会**覆盖**本地同名文件（`drivers/local/driver.go:426` 直接 os.Create 截断写，非原子），而且不写插件记账——补缺请用同步中心，不要用 OpenList。

#### C 路线（P2）：OpenList 源桶 → 目标桶直搬

前提：本地没有副本（或不需要），源与目标各有一份插件配置、挂载已在 §4 常驻、探活通过。

1. 按 §7.3 建好目标桶，跑完「空桶校验」。
2. **求差**（§7.4）：递归 `fs/list` 两侧，得到 `missing / same / conflict / extra` 四类。空桶可以跳过求差，但**必须**先用 `fs/list` 确认 `total=0`（挂错桶时这里才会暴露）。
3. **建目录**：自顶向下逐层 `fs/mkdir`。`fs/copy` 不会创建 `dst_dir`，缺一个目录就是 500（§5.2）。
4. **提交**：每批一个目录、`names` ≤ 200；`missing` 用 `merge=true`，`conflict` 单独一批 `overwrite=true`；提交前快照 `/api/task/copy/done` 的 id 集合（§6.4）。
5. **结算**：轮询 `/api/task/copy/undone` 直到为空；再读 `/done` 里新出现的 id，分类 `succeeded` / `failed` / `canceled`，失败的按 `error` 原文处理（§6.5）。
6. **收尾**：冻结 → 再求一次差并补搬（冻结后源不再长）→ 删源配置 → 切目标 → `scan --full` → 有本地就再跑 `migrate` 补差 → `verify` → 切 CDN 回源 → 观察 → 删源桶。
7. **目标桶卫生**（路线 C 独有）：
   - `.openlist` 占位对象：跨挂载写入会在**目标每个目录**留一个占位对象（`internal/op/fs.go:622` 的 `Put` 先 `MakeDir`；`drivers/s3/driver.go:160` 用空 body 写 `<dir>/.openlist`）。OpenList 的 `fs/list` 会隐藏它（`drivers/s3/util.go:104-110`），但插件的 `list_page` 不过滤点文件，`scan` 会把它当远端对象。收尾时逐目录 `fs/remove` 删掉（`names: [".openlist"]`）。
   - Content-Type：目标对象的 `Content-Type` 由目标文件名扩展名重猜（`internal/stream/stream.go` → `pkg/utils/file.go:170` 的 `GetMimeType`），源对象头里的值丢失；`.avif` 这类新扩展名在系统 mime 表缺失时会落成 `application/octet-stream`。抽样 `curl -I` 看，不对就按 §7.4 第 3 条用插件重传（只有插件上传能把它改对）。
   - 对象 ACL：跨挂载 `Put` 不设 ACL，走桶默认（央企/私有桶就是 403）。见 §7.3 ②。

#### D 路线（P3）：OpenList 落到本地（或「预填 ＋ 补差」混合）

- 目标是本地磁盘，或想先把本地补齐：手工建一个 Local 挂载（**不要**放在 `/sakura-tools/` 命名空间下，那里是插件自己的），把源挂载 `copy` 过去。注意本地驱动：`Put` 非原子、`Copy` 对符号链接按链接复制（`drivers/local/driver.go:339` / `:426`），同名即覆盖。
- **混合（大站最短窗口）**：先用 C 在线预填目标（插件仍指源，用户无感）→ 冻结 → 补搬差集 → 切配置 → 用 A 把本地新增文件补进目标 → `scan --full` + `verify` → 切 CDN 回源。它把「目标已有对象」这件事交给 OpenList，把「正确性与记账」留给插件。

### §7.6 收尾顺序（所有路线共用）

~~~text
① 冻结（关 S3）                      ← 让源不再增长，复制面对静态快照
② 对象搬 / 传完（目标 list 计数 = 源清单）
③ 切配置（07 §6：删源 → 新增/切换目标 → 首次上传成功）
④ scan --full（清 R18 闸门，让索引与目标桶对齐）
⑤ 有本地：migrate 补差（A）
⑥ verify（MD5 vs 32hex ETag），修 mismatch
⑦ 切 CDN 回源 + 抽样 HTTP 校验（状态、Content-Type、大小）
⑧ 观察 ≥ 1 天
⑨ 删源桶（≥ 7 天，且 scan 后 remote 行数为 0）
~~~

每一步的「不满足就不要往下走」：

- ② 之前不切配置（否则新上传写进目标，而 CDN 还在源桶，且 migrate 的差集一直在变）。
- ③ 之后必须先跑 ④：`s3_options['reconcile_pending']` 升起时同步中心封存，批量修复会 409 `sio_reconcile_required`（R18）。
- ⑥ 有 `mismatch` 就不要切 CDN。
- ⑦ 抽样失败（403/404/Content-Type 不对）就退回 ⑥，不要靠「等 CDN 缓存过期」。

### §7.7 求差与提交（可实现的伪代码）

~~~php
// 路线 C：源挂载 → 目标挂载。凭据来自 Options::profile_options()，不要从 option 里读明文。
$ol  = new OpenList_Client( $state['url'], $token );    // Authorization 不加 Bearer
$src = $ol->mount_path( $source_id ) . '/' . $prefix;    // /sakura-tools/prof_src/wp-content/uploads
$dst = $ol->mount_path( $target_id ) . '/' . $prefix;
$ol->reconcile( $profiles );                             // §4，幂等

$plan = $ol->diff( $src, $dst );   // 递归 list 两侧：[ dir => [ name => 'missing' | 'same' | 'conflict' ] ]
foreach ( $plan as $dir => $entries ) {
    $ol->mkdir( $dst . $dir );     // 自顶向下；dst_dir 不存在会 500，见 §5.2
    $missing  = array_keys( $entries, 'missing', true );
    $conflict = array_keys( $entries, 'conflict', true );
    if ( $missing )  { $ol->copy( $src . $dir, $dst . $dir, $missing,  array( 'merge' => true ) ); }
    if ( $conflict ) { $ol->copy( $src . $dir, $dst . $dir, $conflict, array( 'overwrite' => true ) ); }
}
~~~

几条必须写进实现的细节：

- **提交前快照 `baseline`，提交后只认「新出现的 id」**（§6.4 / §6.5）；壳任务的 `state=2` 只代表子任务派发完（§5.2）。
- 挂载在 §4 常驻好了，迁移代码里**不要**建挂载 / 删挂载。
- `diff` 必须用 `total` 判完（§5.1 的 `per_page` 语义：不传 `per_page` 返回整目录，但目录很大时单次很慢；分批用 `page`）。
- 每批只处理一个目录，失败定位便宜；目录任务会递归派生子任务，别把整站一次提交。

## §8 失败、回滚与断点续跑

| 现象 | 路线 | 处理 |
|---|---|---|
| 个别 `state=7` 失败 | C | 记 `error` 原文；重新求差后单独重提，**不要整批重跑** |
| 大批 `403 file [x] exists` | C | 提交没带 `merge`，或差集没取全（`total` 分页）；带 `merge=true` 重提 |
| `failed to get dst dir`（500） | C | 目标目录没建：补 `fs/mkdir`（自顶向下）后重提 |
| OpenList 中途重启 | C | 队列默认不持久化（§6.4），已提交未确认的任务状态不可知；**用「目标 list 重新求差」代替队列**，插件侧也不依赖队列 |
| Doge 挂载 403 / `ExpiredToken` | C | Doge 驱动的临时凭据约两小时一换，长窗口会撞；用 `storage/update` 换凭据（§3.6）后重提 |
| 插件侧 `sakura_s3_token_expired` | A / B | R17 自动续期失败：查 `api.dogecloud.com` 出口与退避窗口，重试即可；源桶对象没被删 |
| `unverifiable` 比例高 | 全部 | >5 MB 分片对象本就不比最终 ETag；抽样 `curl -I` 看大小与 Content-Type |
| 目标 CDN 403 | C | 桶不是公有读 / 对象 ACL 没继承 / 防盗链（§7.3 ②） |
| 目标图片 404 | A / C | CDN 回源还指着旧桶，或对象 key 的 `upload_path` 前缀不一致 |
| `mismatch` 修不掉 | A | 同步中心批量上传要输 `REPLACE`；先确认本地文件确实是权威版本（本地也可能是坏的） |

**回滚（分路线，共同前提：迁移只读源桶、只写目标桶，任何一步都不要在源桶上做 `remove` / `move`）**：

- **A**：源桶对象从未删，目标桶里是副本。回滚 ＝ 关 S3 → 删目标配置 → 重新新增源配置（密钥从备份）→ 打开 S3 → `scan --full`。冻结期新上传只落本地，重开 S3 后跑 `migrate` 会补回源桶，所以期间产生的附件也能复原。
- **C（未进入收尾）**：源桶对象没删、配置没删，直接重新打开「启用 S3 存储」就回到原样；冻结期新上传补跑 `migrate` 传回源桶。目标桶里的副本当垃圾留着。
- **C / D 已进入收尾**（源配置已删）：只能按 A 的回滚方式重建源配置。

> 旧版本这里写过「此时 `active_profile_id` 仍指向源配置（R5 不允许中途切换）」——**R5 并不是「不允许中途切换」**（见 §9）。配置之所以还是源，是因为我们根本没点过切换；「暂停态是唯一能操作桶的窗口」也是错的：桶是厂商的，OpenList 写它不看插件开关。冻结的理由只有一个：**让复制面对一个静态的源**。

## §9 与 07 的关系

07 §6 规定换桶的唯一路径是「关 S3 → 删配置 → 新增/切换配置 → 开 S3」。本文的各条路线都在这条路径的**两侧**工作：

| 本文做的事 | 在 07 里的位置 |
|---|---|
| 路线 C（OpenList 直搬） | 「关 S3」之后、「删源配置」之前的暂停态：配置与绑定都还在，OpenList 只写目标桶 |
| 路线 A（插件原生上传） | 就是 07 §6 换完配置之后的「把本地补进新桶」，OpenList 完全不参与 |
| 路线 B / D（经本地补齐） | 在冻结之前做（源配置还要用来下载），属于 A 的前置 |

四条纠正（旧版本写错的地方）：

1. **R5 的语义不是「不允许中途切换」。** 普通锁定（`binding['no_local_saving']='false'`）允许切换，URL 降级到站点域名；只有高风险锁定（`='true'`）拒绝切换并返回 `error=locked`（`Profile_Manager::switch_refused_by_binding()`，`src/S3/Profile_Manager.php:343`）。迁移期间我们没点切换，所以配置没变——这是「没切」，不是「不许切」。
2. **暂停态不是「唯一能操作桶的窗口」。** 桶的写权限只取决于厂商凭据，与插件 `enabled` 无关。真正的约束是 07 的配置切换门槛与 R18 闸门。
3. **高风险锁定下路线 C 的收尾要换写法。** R10 要求删绑定配置时配置表里只剩它，而目标配置本身就是「其余的配置」——所以「搬完 → 切到目标配置」这条思路走不通，真实顺序是「搬完 → 删光全部配置（含目标配置）→ 重新新增目标配置 → 开 S3 → 完整核对」（07 §6 已写明这处例外）。同参数重建的配置不会重传：桶里对象还在，指纹按厂商 / 桶 / endpoint / 路径算，重建后一致。高风险锁定通常伴随 `no_local_saving='true'`（本地无副本）→ 只能走 P1 / P2，不能走 P0。
4. **R18 闸门必须先开。** 切到目标后 `reconcile_pending` 升起，同步中心封存；只有完整核对（`--full` 或 `--resume` 跑完）才清。所以 A 路线第 2 步之后、C 路线收尾之后，都要先 `wp sakura-sync scan --full`，否则批量修复会 409 `sio_reconcile_required`。

其他相关规则：R13 / R14 只认插件自己的下载写回（OpenList 搬进本地的文件插件不知道，之后必须 `scan` 重建索引）；R16 切桶关缩略图改写；R17 多吉云临时凭据；R19 桶身份：**换桶判定**看五项（`provider / bucket / endpoint / region / upload_path`），**对象指纹**只看四项（不含 `region`），两套清单见 §7.3 ①。

## §10 验收清单

前六项（✅）是 2026-11-17 在本机 **v4.2.4** 实测的；2026-11-18 升级到 v4.2.9 后未逐条复测，但只涉及端点与字段，结论不受影响，复测有出入以复测为准。**V13 起是 2026-11-18 按 v4.2.9 源码核对补的，标 ⏳ 的尚未实测**——落代码时逐条跑一遍，跑过的改成 ✅。

- ✅ **V1** 用本站真实两家桶（多吉云 → 缤纷云）搬一个文件：`fs/copy` 返回 `code=200`，轮询 **2.0 s** 后 `state=2`、`total_bytes=81408` 与源一致，目标 `fs/list` 里同名同尺寸。
- ✅ **V2** 建挂载 / 改挂载（整对象 ＋ 字符串 addition）/ 删挂载三条路径均为 `code:200`，`storage/list` 的 total 回到 2（只剩 `/115` 与 `/OPenList`）。
- ✅ **V3** 重复 `mount_path` 建挂载返回 `code=500` 且 `data.id=0`，据此判定失败。
- ✅ **V4** 目标已存在同名文件时 `fs/copy` 立即返回 `code=403 file [...] exists`（不入队、不产生 `state=7` 噪音），插件侧应先求差集再提交，或用 `merge` / `skip_existing` 显式跳过（§5.2）。
- ✅ **V5** 凭据热改：错误 AK/SK 建的挂载 `status` 仍是 `work`、`fs/list` 报 403；`storage/update` 换成正确凭据后 `fs/list` 立刻 200。**证明 update 可以代替重建。**
- ✅ **V6** 归属标记往返：`remark` 写 `sakura-tools:{"profile": "...", "name": "缤纷云 · nboke"}`，`storage/list` 原样取回（含中文与空格）。
- **V7** 新增一份配置后保存，`storage/list` 里立刻多出 `/sakura-tools/<新 id>`，且 `remark` 指向它；重跑一次对账不产生第二个挂载（幂等）。
- **V8** 删除一份配置后，对应挂载消失；把 OpenList 停掉再删配置，保存照常成功，`sakura_imgopt_openlist` 的 `state` 记录错误，迁移按钮禁用。
- **V9** 只有一份配置时迁移卡 `disabled`，直接构造迁移请求返回 `error=not_enough_profiles`。
- **V10** 迁移期间产物不落 PHP 内存：处理大文件时 `memory_get_peak_usage()` 不随文件增大而上涨。
- **V11** 迁移结束后按 §7.6 第 ③ 步收尾，附件 URL 全部指向新桶域名；同步中心跑一次完整核对后 `seen_remote` 与目标桶对象数一致。
- **V12** 别人的挂载（例如 `/115`、`/OPenList`）在全量对账中纹丝不动：既不被 update，也不被 delete。
- ⏳ **V13（空桶校验）** 目标前缀 `total≠0` 时迁移拒绝开始并提示「目标桶不空」；`total=0` 且 `fs/get` 不存在 key 报 `object not found` 时才允许提交第一批。
- ⏳ **V14（merge 幂等）** 同一批 `names` 带 `merge=true` 连续提交两次：第二次全部跳过、返回 `code=200`、`/done` 新增里没有 `failed`；目标对象数不变。
- ⏳ **V15（skip_existing 只跳顶层）** 目标已有同名**目录**时，`skip_existing=true` 仍把整个顶层项跳过（不递归合并）；同一场景改成 `merge=true` 才逐层补齐。
- ⏳ **V16（overwrite 只打清单）** `overwrite=true` 只覆盖清单内的 key：清单外对象的 `last-modified` 与 ETag 不变（用 `fs/get` 前后对比）。
- ⏳ **V17（缺目录 500）** 不 `mkdir` 直接 `fs/copy` → 500 `failed to get dst dir`；补建目录后成功。
- ⏳ **V18（跨挂载元数据损失）** 同一个 81408 B 的 `a.webp`：C 路线搬过去，目标 `Content-Type` 按扩展名重猜、对象无独立 ACL；A 路线传同一张，`Content-Type` 与 `object_acl` 都是插件设置的值。两条路各自 `fs/get` 对照。
- ⏳ **V19（.openlist 占位）** C 路线搬完，目标桶每个目录出现 `.openlist`；`fs/list` 不显示；逐目录 `fs/remove` 后 `fs/list` 与插件 `list_page`（`src/S3/Object_Store.php:542`）都不再出现。
- ⏳ **V20（>5 MB 分片）** C 路线搬一个 >5 MB 文件：插件 `verify` 标 `unverifiable`；A 路线传同一个（<16 MB 走单段），`verify` 可判 `matched`。
- ⏳ **V21（路线 A 端到端）** 多吉云 → 腾讯云：`migrate` 输出 `uploaded` ＝ 附件数 − `skipped`（本地完整时 `skipped` 应为 0）；`verify` 无 `mismatch`；`scan --full` 后 `synced` ＝ 目标对象数；CDN 回源切换后抽样 200。
- ⏳ **V22（部分重叠端到端）** 目标预置「同名同大小」「同名异大小」「目标多出」各 ≥1：走 C＋`merge` 后，异大小保持不动（merge 不修）、多出的不被删；再按 §7.4 的覆盖清单 ＋ 同步中心 `REPLACE` 修好，`verify` 干净。

## §11 明确不做

| 不做 | 原因 |
|---|---|
| 把 OpenList 当上传后端 | 上传必须留在一个可控、可复现、失败可重试的路径上；引入常驻外部服务会把「传一张图」变成「两个系统都得活着」 |
| 定时（cron）对账 | 会在没人看着的时候动外部服务的挂载表；改为「配置增删改时同步 ＋ 打开页面时对账 ＋ 手工同步按钮」 |
| 自动定时迁移 | 迁移是一次性的运维动作，绑进 cron 只会在没人看着的时候动桶 |
| 删除插件不认识的挂载 | 归属判据之外的东西一律只读（§4.1）；误删别人挂载是不可逆越权 |
| 依赖 `retry` / `cancel` / `clear` 端点 | 未验证语义，上游也可能随时改 |
| 在插件里实现 OpenList 的驱动适配层 | 迁移只需要 `S3` 与 `Doge` 两个驱动，够用即可 |
| 记录迁移历史到新表 | 可观测性靠「目标桶求差 ＋ 任务队列」，够用；真要审计再加（届时须同时进 `Uninstaller`） |
| 单配置站点开放迁移入口 | 见 §7.1：OpenList 通道没有目标可切；这类站点应先按 07 §6 新增一份配置，或直接走路线 A |
| 用 OpenList 修对象的 ACL / Content-Type | 修复的正确工具是插件重传（`PUT` 带 `ContentType` / ACL）；在通道里再养一份「修补逻辑」等于第二套存储实现 |
| 自动清理目标桶的 `.openlist` | 它只在选 C 路线时产生、每个目录一个；一次 `fs/list` ＋ `fs/remove` 脚本足够，做成插件任务还要维护递归与忽略规则 |
| 逐对象内容比对 | `Verify_Service` 只对 32 位 hex ETag 生效；>5 MB 分片对象没有可比 ETag，深度比对要整对象下载 |
| 把「目标已有同名文件」当失败 | `merge` 的语义就是跳过；当失败会让断点续跑永远跑不完 |

## §12 速查：场景 → 路线

| 场景 | 路线 | 关键动作 |
|---|---|---|
| 多吉云 → 腾讯云，本地有全部附件（本站现状） | P0 / A | 07 §6 切配置 → `wp sakura-s3 migrate --batch-size=500 --seconds=30` → `wp sakura-sync verify --seconds=60` → 切 CDN 回源 |
| 同上，但本地缺一部分 | P1 / B → A | 同步中心批量下载补本地（拒绝覆盖＝只补缺）→ A |
| 全新腾讯云桶、本地没附件、只要桶能用 | P2 / C | §7.3 建桶 → 求差 → `mkdir` → `merge` copy → 结算 → 收尾（含 `.openlist` 清理与抽样） |
| 目标桶已有上一轮搬了一半 | P2 / C 续跑 | `merge=true` 重提 ＋ §7.4 四分类 |
| 目标桶有同名但内容不同 | 覆盖清单 | `overwrite=true` 只打清单 → `verify` |
| 大站、要求几乎不停机 | P2 预填 ＋ P0 补差 | 在线 C 预填 → 冻结 → 补差 → 切配置 → A 补差 → 切 CDN |
| 只想临时看桶里有什么 | — | `curl` 或 OpenList UI；**不要**为此在插件里建配置 |
