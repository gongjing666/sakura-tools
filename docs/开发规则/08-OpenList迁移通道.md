# 08 OpenList 迁移通道

这一页记录本站点上的 **OpenList** 实例，以及「把已经上传到 A 桶的对象搬到 B 桶」这件事怎么借它完成。**它不是上传路径**——上传永远走 WordPress 媒体库与插件自己的 `Object_Store`；OpenList 只在换桶时当搬运工，平时以**常驻镜像挂载**的形式跟着插件的存储配置走（§4）。

> 相邻篇：换桶在生命周期里只允许一条路径，见 [07-S3存储生命周期](07-S3存储生命周期.md) §6；新增一家存储厂商见 [02-新增功能](02-新增功能.md) §12；option 的读写与卸载见 [04-数据与存储](04-数据与存储.md)。
> 本页的 API 事实全部在 **2026-11-17** 用本机实例实测（OpenList v4.2.4 / 插件 1.28.1-beta-6）；上游改版后以实测为准并顺手修正本页。
> 本页**不含任何明文密钥**。账号密码走运维侧凭据文件与记忆，不进仓库。
> **状态：规范已定，代码未实现。** 页面上的「常驻镜像挂载」「随配置增删同步」「迁移入口仅多配置开放」都还是设计（§4、§7），落地前插件里没有任何 OpenList 相关代码。

## §0 边界：它是迁移通道，不是存储后端

| 问题 | 答案 |
|---|---|
| 插件上传附件时是否经过 OpenList？ | **不经过。** 上传仍由 `Object_Store`（`src/S3/Object_Store.php`）直连存储桶。 |
| 插件是否依赖 OpenList 才能工作？ | **日常不依赖。** OpenList 挂了只影响「换桶迁移」这一个动作，上传、同步中心、缩略图全部照常；**保存存储配置也绝不受它影响**（见 §4.7）。 |
| 为什么不自己做桶到桶复制？ | 跨厂商没有服务端复制：`x-cos-copy-source` / OSS 的 `x-oss-copy-source` 只能表达「一个桶 + 一个键」，带不了第二个 endpoint 与第二套凭据。插件内部也没有 `CopyObject` 调用点，只能「下载到磁盘 → 再上传」中转。 |
| 那 OpenList 凭什么能跨厂商搬？ | 它在**服务端**同时挂载源与目标（自己持有两套凭据），流式读源写目标，站点只发一个 `/api/fs/copy` 然后轮询结果。带宽与内存都不经过 PHP。 |
| 挂载是临时的还是常驻的？ | **常驻。** 每份插件配置在 OpenList 上有一份长期镜像挂载，随配置增删（§4）。迁移时直接用，不现场建。 |
| 谁能建、谁能删挂载？ | **只有插件自己**，且只碰 `/sakura-tools/` 命名空间下带自己归属标记的挂载（§4.1）。别人的挂载一律只读。 |
| 什么情况下能用迁移？ | **至少两份已保存配置。** 只有一份配置时迁移入口整块置灰（§7.1）。 |

三条边界规则，写代码前先记住：

1. **上传路径不引入 OpenList。** 引入常驻外部服务会把「传一张图」变成「两个系统都得活着」。
2. **挂载常驻但可随时重建。** 它只是「怎么读那个桶」的一份缓存描述，真正的数据在桶里，删了挂载什么都不会丢——所以对账失败永远不是紧急事故。
3. **删挂载不等于删桶。** 插件只会调 `storage/delete?id=` 删**挂载记录**，从不删桶、从不删对象。桶里的东西要清只能去厂商控制台。

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

> 机器上没有 `sqlite3` CLI，查库用 `python3 -c "import sqlite3; ..."`。库里的表：`x_users`（账号）、`x_storages`（挂载，**常驻镜像就存在这里，重启不丢**）、`x_task_items`（任务队列的持久化位，实际恒为空，见 §6.4）、`x_setting_items`、`x_meta`、`x_search_nodes`、`x_sharing_dbs`、`x_ssh_public_keys`。**没有 `x_tasks` 表**——任务只在内存里。

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

→ **迁移前必须先对目标做一次 `fs/list` 求差集**，否则整批任务会因为个别已存在的文件失败，反复重跑也不会前进。

其他有用的事实：

- **`dst_dir` 必须已经存在。** 复制到不存在的目标目录会直接失败（见上表）。所以要自己先 `POST /api/fs/mkdir` 把目标目录建出来。**会自建父目录的是 `PUT /api/fs/put`（上传），不是 `fs/copy`**——这两件事不要混。
- 复制**目录**时返回的是一条**壳任务**：提交瞬间 `state=0`，随后 `state=2` + `status="src object is dir, added all copy tasks of objs"` + `progress=0` + `total_bytes=0`。

> ⚠️ **壳任务的 `state=2` 只代表「子任务都派发出去了」，不代表文件搬完。** 实测一次目录复制：壳任务 1 秒内就 `state=2`，而真正的文件搬运是另一条独立 id 的子任务（`status=uploading`、`total_bytes=5`）。**若只看壳任务就宣布成功，会在文件还没搬完时就进入收尾（删旧配置 → 切到目标配置 → 启用），站点直接指向一个空桶。** 正确做法：提交前快照 `done` 的 id 集合，之后把「新出现的所有任务」一起结算，直到它们全部 `state=2` 或 `7`。

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

### §6.4 队列只在内存里

`data.db` 的 `x_task_items` 表只有 `(key, persist_data)` 两列，四行（`copy` / `move` / `download` / `transfer`）的 `persist_data` 全是 `[]`。**OpenList 重启，任务队列即丢。**

因此可靠的做法只有一条：

> **在提交任何复制任务之前，先快照一遍 `/api/task/copy/done` 的 id 集合（记作 `baseline`）。之后只认「done 里新出现、且不在 baseline 里」的 id。**

插件侧还必须自己落盘「本次迁移已提交的文件清单」，因为队列丢了就再也问不出谁搬过。

这份清单存进独立 option（例如 `sakura_imgopt_openlist_migrate`，`array( 'source' => id, 'target' => id, 'started_at' => 时间戳, 'keys' => array( ... ), 'done' => array( ... ) )`），并且**必须同时进 `Uninstaller` 的清理清单**——它是站点级状态，不属于任何一份存储配置，删配置时不能把它一起清掉。

## §7 迁移：只有多配置才开放

### §7.1 入口条件（硬门槛）

| 条件 | 不满足时 |
|---|---|
| **已保存的完整配置 ≥ 2** | 迁移卡整块 `disabled`，`title` 写「只有一份存储配置时没有可迁移的目标。迁移是把对象从 A 桶搬到 B 桶，至少需要两份配置。」 |
| OpenList 已接通且管理员凭据可用 | 卡片可见，按钮 `disabled`，提示「OpenList 未就绪」 |
| 源与目标都 `is_configured()` 为真 | 下拉里把不完整的配置 `disabled` 掉 |

**前端置灰只是提示，服务端必须同样拒绝**：配置数 `< 2` 时迁移动作返回 `error=not_enough_profiles`。把门槛只写在前端等于没写。

**为什么必须 ≥ 2**：迁移的收尾是「删掉源配置 → 切到目标配置」。07 的 R5 只在 `binding` 非空时禁止切换；删掉源配置后 `binding` 已经清空，切换才被放行。**如果只有一份配置，删掉它之后没有任何可切换的目标**，站点会退化成 S0 空态——那不是迁移，是自毁入门。

### §7.2 步骤（用常驻挂载）

```text
0. 前置：关闭「启用 S3 存储」（否则 07 §6 的路径不允许换桶）
1. 对账：确保源与目标的镜像挂载都是最新且探活通过（§4.4）
2. 求差：递归 list 目标桶，得到「目标已有的 key 集合」；源文件 − 目标文件 = 待搬列表
3. 搬运：按目录 fs/mkdir → 分批 POST /api/fs/copy；提交前快照 done 的 id 集合
4. 观测：结算「done 里新出现的全部任务」；失败项单独重试，已存在项跳过
5. 收尾：删源配置（解绑）→ 切到目标配置 → 打开「启用 S3 存储」→ 跑一次完整核对
```

挂载在 §4 里已经常驻好了，这一步只是**核对一下它是最新的**，不建也不删。

几个必须写进实现的细节：

- **迁移代码里不要出现建挂载 / 删挂载**。哪怕出于「保险」把两个镜像在迁移前后删掉，也会让下次对账把它们建回来（`storage/create` 要走一次数据库写 + 一次驱动初始化），白白制造抖动和 500 噪音。
- **先把目标目录建好再复制**：`fs/copy` 不会替你创建 `dst_dir`（§5.2）。
- **壳任务不算完成**（§5.2）：目录复制要等「done 里新出现的全部任务」都结算。
- **搬完再动配置**。R5 在暂停态仍然禁止切换配置与新增配置，所以顺序只能是：暂停 → 搬 → 删源配置（解绑）→ 切到目标配置 → 启用。**中途唯一能改的就是桶里的对象**。
- **切换后跑一次完整核对**，让 `nb_sio_sync_index` 里的 `seen_remote` 与对象重新对齐（见 07 §5 与 `src/S3/Storage.php:686` 的自愈逻辑）。

伪代码（凭据来自 `Options::profile_options()`，**不要**从 option 里读明文）：

```php
$ol = new OpenList_Client( $state['url'], $token );        // Authorization 不加 Bearer
$src = $ol->mount_path( $source_id );                      // /sakura-tools/prof_src
$dst = $ol->mount_path( $target_id );                      // /sakura-tools/prof_dst
$ol->reconcile( $profiles );                               // §4.3，幂等
$baseline = $ol->done_task_ids();                          // 提交前快照，见 §6.4
$plan     = $ol->diff( $src, $dst );                       // 目标 fs/list 求差集
foreach ( $plan as $dir => $names ) {
	$ol->mkdir( $dst . $dir );                             // dst_dir 不会自动创建，见 §5.2
	$ol->copy( $src . $dir, $dst . $dir, $names );
}
$result = $ol->settle( $baseline );                        // 结算新出现的全部任务
```

## §8 失败、回滚与断点续跑

| 现象 | 处理 |
|---|---|
| 个别文件 `state=7` | 记下 `error` 原文，单独重试；不要整批重跑（已存在项会 403） |
| 大批 `403 file exists` | 说明差集没算对（比如目标已有但 list 分页没取完），补全 `total` 分页后重算 |
| OpenList 中途重启 | 队列丢失，**已提交但未确认的项状态不可知**；用「目标 list 重新求差」代替，不依赖队列 |
| 凭据 401 | token 48 小时过期，重新 `/api/auth/login` |
| 站点无法访问 OpenList | 迁移不可用，但上传 / 同步中心 / 保存配置都不受影响 |

回滚：迁移过程**只读源桶、只写目标桶**，源桶对象不会被删；而且此时 `active_profile_id` 仍指向源配置（R5 不允许中途切换）。所以真要放弃，**重新打开「启用 S3 存储」就回到原样**，连配置都不用动。目标桶里的副本当作垃圾留着（07 §10 明确不做旧桶清点）。

## §9 与 07 的关系

07 §6 规定换桶的唯一路径是「关 S3 → 删配置 → 新增/切换配置 → 开 S3」。OpenList 迁移是这条路径上的**施工工具**，不是替代：

- 它不解除绑定，也不允许在绑定态换桶（R5 仍然生效）。
- 正确顺序是：先关 S3（进入 S3 暂停态），**在删除旧配置之前**用 OpenList 把对象搬进目标桶，然后删旧配置（此时 `binding` 清空）、切到目标配置、打开 S3、跑完整核对。
- 因为 R5 只在 `binding` 非空时生效，暂停态才是唯一能操作桶的窗口——这也解释了为什么迁移脚本必须以「`enabled=false`」为前置断言。
- **迁移入口只在 ≥2 份配置时开放**（§7.1）。只有一份配置的站点应当走 07 §6 的「新增配置」路线，而不是迁移。

## §10 验收清单

前六项在 2026-11-17 已经用本机实例跑过（标记 ✅），剩下的是落代码时要补的。

- ✅ **V1** 用本站真实两家桶（多吉云 → 缤纷云）搬一个文件：`fs/copy` 返回 `code=200`，轮询 **2.0 s** 后 `state=2`、`total_bytes=81408` 与源一致，目标 `fs/list` 里同名同尺寸。
- ✅ **V2** 建挂载 / 改挂载（整对象 + 字符串 addition）/ 删挂载三条路径均为 `code:200`，`storage/list` 的 total 回到 2（只剩 `/115` 与 `/OPenList`）。
- ✅ **V3** 重复 `mount_path` 建挂载返回 `code=500` 且 `data.id=0`，据此判定失败。
- ✅ **V4** 目标已存在同名文件时 `fs/copy` 立即返回 `code=403 file [...] exists`（不入队、不产生 `state=7` 噪音），插件侧应先求差集再提交。
- ✅ **V5** 凭据热改：错误 AK/SK 建的挂载 `status` 仍是 `work`、`fs/list` 报 403；`storage/update` 换成正确凭据后 `fs/list` 立刻 200。**证明 update 可以代替重建。**
- ✅ **V6** 归属标记往返：`remark` 写 `sakura-tools:{"profile": "...", "name": "缤纷云 · nboke"}`，`storage/list` 原样取回（含中文与空格）。
- **V7** 新增一份配置后保存，`storage/list` 里立刻多出 `/sakura-tools/<新 id>`，且 `remark` 指向它；重跑一次对账不产生第二个挂载（幂等）。
- **V8** 删除一份配置后，对应挂载消失；把 OpenList 停掉再删配置，保存照常成功，`sakura_imgopt_openlist` 的 `state` 记录错误，迁移按钮禁用。
- **V9** 只有一份配置时迁移卡 `disabled`，直接构造迁移请求返回 `error=not_enough_profiles`。
- **V10** 迁移期间产物不落 PHP 内存：处理大文件时 `memory_get_peak_usage()` 不随文件增大而上涨。
- **V11** 迁移结束后按 §7.2 的第 5 步收尾，附件 URL 全部指向新桶域名；同步中心跑一次完整核对后 `seen_remote` 与目标桶对象数一致。
- **V12** 别人的挂载（例如 `/115`、`/OPenList`）在全量对账中纹丝不动：既不被 update，也不被 delete。

## §11 明确不做

| 不做 | 原因 |
|---|---|
| 把 OpenList 当上传后端 | 上传必须留在一个可控、可复现、失败可重试的路径上；引入常驻外部服务会把「传一张图」变成「两个系统都得活着」 |
| 定时（cron）对账 | 会在没人看着的时候动外部服务的挂载表；改为「配置增删改时同步 + 打开页面时对账 + 手工同步按钮」 |
| 自动定时迁移 | 迁移是一次性的运维动作，绑进 cron 只会在没人看着的时候动桶 |
| 删除插件不认识的挂载 | 归属判据之外的东西一律只读（§4.1）；误删别人挂载是不可逆越权 |
| 依赖 `retry` / `cancel` / `clear` 端点 | 未验证语义，上游也可能随时改 |
| 在插件里实现 OpenList 的驱动适配层 | 迁移只需要 `S3` 与 `Doge` 两个驱动，够用即可 |
| 记录迁移历史到新表 | 迁移的可观测性靠「目标桶 list 求差 + 任务队列」，够用；等真的需要审计再加（届时须同时进 `Uninstaller`） |
| 单配置站点开放迁移入口 | 见 §7.1：没有目标可切，删掉源配置就退化成 S0 |
