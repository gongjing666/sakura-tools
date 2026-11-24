# 13 PHP 代码规范

本页管 **PHP 这一侧怎么写**：语言特性能用哪些、哪些禁用、8.3/8.4 的写法在 8.2 上怎么办、提交前怎么自查。**不管**三道关怎么跑（见 [05-测试与质量](05-测试与质量.md)）、不管目录与装配（见 [01-架构与装配](01-架构与装配.md)）、不管 option / SQL / 数据落点的细节（见 [04-数据与存储](04-数据与存储.md) 与 [02-新增功能](02-新增功能.md)）。

- 兼容基线：**PHP 8.2 – 8.4**。下限写在插件头 `sakura-tools.php:8` 的 `Requires PHP: 8.2`。
- 生产实际：`/www/server/panel/vhost/nginx/www.nboke.com.conf` 指向 `php-84`；同机 `/www/server/php/` 下 `83`、`84` 两套 FPM 并存（2026-11-24 核对）。开发机 CLI 为 8.4.28。
- 官方依据（本篇所有版本差异只从这里来，不凭印象）：
  - PHP 手册：[8.2 迁移](https://www.php.net/manual/en/migration82.php)（[弃用](https://www.php.net/manual/en/migration82.deprecated.php)）、[8.3 迁移](https://www.php.net/manual/en/migration83.php)（[弃用](https://www.php.net/manual/en/migration83.deprecated.php)）、[8.4 迁移](https://www.php.net/manual/en/migration84.php)（[弃用](https://www.php.net/manual/en/migration84.deprecated.php)）
  - php-src 的 UPGRADING 原文：[PHP-8.2](https://github.com/php/php-src/blob/PHP-8.2/UPGRADING)、[PHP-8.3](https://github.com/php/php-src/blob/PHP-8.3/UPGRADING)、[PHP-8.4](https://github.com/php/php-src/blob/PHP-8.4/UPGRADING)
- 一句话：**语法写到 8.2 为止，运行按 8.4 验干净；8.3/8.4 的新 API 默认不用，非用不可就带回落。**

## 0. 先记住四条前提

1. **语法超版本不是「少个功能」，是整站白屏。** PHP 先编译后执行，8.3 的语法在 8.2 上是 `ParseError`，同一文件里的 `if ( PHP_VERSION_ID >= 80300 )` 救不了自己。所以「语法」和「API」必须分开管：语法硬禁，API 可守卫。
2. **弃用不是「以后再说」。** 生产跑的就是 8.4，弃用提示会进 `debug.log`，而部署验收要求「debug.log 无新增条目」（AGENTS.md §5）。8.4 的弃用项按「现在就改」处理。
3. **不为版本分叉业务。** 同一份代码在不同站点跑出不同行为，是排障灾难。`PHP_VERSION_ID` 只用于「新 API 可选能力」，必须有回落、有注释、有测试。
4. **下限只升不降，由开发者拍板。** 想用 8.3 语法，等于把 `Requires PHP` 提到 8.3 并同步 WP.org 与文档；不要偷偷用。

## 1. 版本矩阵：什么能用

### 1.1 8.2（下限）：可用

| 特性 | 写法 | 备注 |
|---|---|---|
| readonly 类 | `readonly class Foo {}` | 所有属性隐式 readonly；与 final 搭配 |
| DNF 类型 | `( Countable&Iterator )\|null` | 括号内交集 + 外层联合 |
| 独立类型 | `true` / `false` / `null` | 只能出现在联合类型或返回类型 |
| trait 常量 | `trait T { const X = 1; }` | |
| enum 常量表达式取值 | `Foo::Bar->value` 出现在 const 表达式里 | |
| 敏感参数 | `#[\SensitiveParameter]` | 异常栈里对参数打码 |
| 随机扩展 | `Random\Randomizer`、`Random\Engine\*` | 新代码优先于 `mt_rand()` |

8.2 起**不要写**（已弃用，8.4 上会报）：

- 动态属性：声明属性；确实要给别人的对象挂数据时用 `WeakMap`。`#[\AllowDynamicProperties]` 只在维护旧类时保留，新类不写。
- `"${var}"` / `"${expr}"` 插值：用 `"{$var}"`。
- `utf8_encode()` / `utf8_decode()`：用 `mb_convert_encoding()`。
- 字符串 callable `"self::method"`、`array( 'self', 'method' )` 等：用 `array( $this, 'method' )` 或 first-class callable。
- mbstring 的 QPrint / Base64 / Uuencode / HTML-ENTITIES「编码」：用对应专用函数。

### 1.2 8.3：语法禁用，API 默认不用

**禁用语法**（8.2 解析失败）：

- typed class constants：`const string FOO = 'x';`
- `#[\Override]`
- 动态类常量：`Foo::{$name}`
- static 变量的任意表达式初始化（`static $x = foo();`）
- readonly 匿名类
- trait 方法加 `final`
- clone 期间重初始化 readonly 属性

**API 默认不用**（要用必须 `function_exists` / `method_exists` 守卫 + 回落，见 §2.2）：`json_validate()`、`mb_str_pad()`、`str_increment()` / `str_decrement()`、`Randomizer::getBytesFromString()` / `nextFloat()` / `getFloat()`、`ReflectionMethod::createFromMethodName()`、`DatePeriod::createFromISO8601String()`。

**8.3 弃用（现在就改）**：

- 无参 `get_class()` / `get_parent_class()`：改 `get_class( $this )` 或 `static::class`。
- 对空 / 非数字字符串用 `++` / `--`：数字用 `(int)` 运算；不要对字符串自增。
- `assert_options()` 与 `ASSERT_*` 常量。
- `mb_strimwidth()` 负宽度。
- `ReflectionProperty::setValue()` 只传一个参数：静态属性显式传 `null` 作第一参。
- `dba_fetch()` 把连接当第三参（我们不用 dba）。
- `MT_RAND_PHP`、带两个参数的 `ldap_connect()`、`Phar::setStub( resource, len )`。

### 1.3 8.4：语法禁用，API 默认不用，弃用必改

**禁用语法**（8.2 解析失败）：

- property hooks（`public string $x { get => ...; set => ...; }`）
- 非对称可见性（`private(set)` / `protected(set)`）
- 无括号链式：`new Foo()->bar()`，写 `( new Foo() )->bar()`
- `#[\Deprecated]` 用在自己代码上：用 docblock `@deprecated`

**API 默认不用**：`array_find()` / `array_find_key()` / `array_any()` / `array_all()`、`mb_trim()` / `mb_ltrim()` / `mb_rtrim()`、`mb_ucfirst()` / `mb_lcfirst()`、`http_get_last_response_headers()` / `http_clear_last_response_headers()`、`RoundingMode` 枚举、`request_parse_body()`、lazy objects 的反射方法、`BcMath\Number`、`Dom\HTMLDocument` / `Dom\XMLDocument`。

**8.4 弃用（生产必改，按优先级）**：

1. **隐式 nullable 参数。** `function f( string $x = null )` 在 8.4 报：
   `Deprecated: f(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead`
   写法：`function f( ?string $x = null )`。**现状 0 处**（243 个文件 / 2508 个函数声明全扫过，2026-11-24）。
2. **CSV 的 `$escape` 默认值。** `fputcsv()` / `fgetcsv()` / `str_getcsv()` / `SplFileObject::fputcsv()` 等同名方法不传 `$escape` 会报：
   `Deprecated: fputcsv(): the $escape parameter must be provided as its default value will change`
   **现状 0 处**（2026-11-24 修完 7 处：`includes/class-s3-cli.php` 2 处、`src/S3/Delete_Queue_Panel.php` 2 处、`src/S3/Sync_Center_Module.php` 3 处；全部是导出侧 CSV，仓库里没有 `fgetcsv()` 读回路径）。
   写法：五个参数全部显式给出，`$escape` 传空串（RFC 4180，也是 PHP 9 的默认值；不再使用专有的反斜杠转义）。由 `composer lint:compat` 守护（§6.3）。
3. **`E_STRICT` 常量与错误级别已移除**：代码里不要引用（现状 0 处）。
4. 其余 8.4 弃用项（现状均 0 处，新代码同样禁写）：`trigger_error( ..., E_USER_ERROR )`、`lcg_value()`、`mysqli_ping/kill/refresh`、`session_set_save_handler()` 多参、`stream_context_set_option()` 双参、`xml_set_object()`、`DatePeriod` 字符串构造、`SplFileObject` CSV 默认 escape。

### 1.4 8.4 行为变化：不报错，但结果会变

这些不是弃用，升级后不刷日志，但同一输入可能得到不同输出。**涉及的地方要抽样核对**。

| 变化 | 官方口径 | 对本插件 |
|---|---|---|
| `round()` 重写 | 修正舍入实现与精度：`round( 0.49999999999999994 )` 旧版 `1.0`、8.4 为 `0.0`；`round( 4503599627370495.5 )` 旧版返回原值、8.4 为 `4503599627370496`；负数精度生效 | **60 处**。集中在 `src/S3/Cost_Estimator.php`（报价）与 `src/S3/Upload_Metrics.php`（速率 / 体积展示）；PNG 质量映射 `includes/class-gd-handler.php:140` 也用。展示值可能差一个最小单位，报价类要核对边界 |
| `number_format()` 负精度 | 8.3 起负数 `$decimals` 真正生效（此前被忽略） | 现有调用全传正数，无影响 |
| `range()` | 8.3 起非法 step / 非数值边界抛 `ValueError`、发 `E_WARNING` | 0 处 |
| `unserialize()` | 8.2 起结构校验更严；8.3 把 `E_NOTICE` 升为 `E_WARNING`；8.4 校验 `allowed_classes` 类型 | 2 处直接调用都带 `array( 'allowed_classes' => false )`（`src/S3/Link_Rewriter.php:187`、`src/S3/Link_Rewriter.php:240`），其余走 `maybe_unserialize()`。新代码保持这个写法 |
| `array_sum()` / `array_product()` | 8.3 起不可转数值的元素发警告 | 只用于数字数组；来自请求 / DB 时先 `array_map( 'intval', … )` |
| `strtolower()` / `strtoupper()` | 8.2 起只做 ASCII 大小写，不再受 locale 影响 | 93 + 21 处；中文场景无影响，但不要指望它处理非 ASCII 大小写（用 `mb_strtolower()`） |
| `str_split( '' )` | 8.2 起返回 `[]`（此前 `['']`） | 不要假设空串会得到 `['']` |
| GD 输出函数 | 8.4 起非法 quality / size 抛异常（此前静默） | `includes/class-gd-handler.php:52` 已 `max( 1, min( 100, absint( … ) ) )`，不会触发；新增输出格式时仍要 clamp |
| `password_hash()` | 8.4 起 bcrypt 默认 cost 10 → 12 | 插件不存密码（0 处）；不要在插件里自算用户密码 hash |
| 上传 / 临时文件名 | 8.4 起比旧版长 13 字节 | 不要写死临时文件名长度或按固定长度截断 |
| `PHP_DEBUG` / `PHP_ZTS` | 8.4 起是 bool | 用 `=== true`，不要用 `1 ===` |
| `is_resource()` | 8.4 起 DBA / ODBC / SOAP 的连接是对象 | 本仓库 10 处都是文件句柄，不受影响 |
| PCRE2 10.44 | `{,3}` 从字面量变成量词；UCP 模式部分字符类语义变化 | 代码里 0 处 `{,n}`；正则若来自用户输入，8.4 下行为可能变 |
| `exit` / `die` | 8.4 起更像函数：可当 callable、受 strict_types 影响、非法类型抛 `TypeError` | 我们不用 `exit` 做业务（见 §4） |

## 2. 向下兼容的三种办法（按优先级）

### 2.1 兼容写法（首选）

能用一个 8.2 写法表达清楚的，绝不加版本分支。

| 8.3 / 8.4 写法 | 我们在 8.2 上怎么写 |
|---|---|
| `public const string NAME = 'x';` | `public const NAME = 'x';` + `/** @var string */`（PHPStan 5 认 docblock） |
| `#[\Override]` | 不写；靠 `@see` 与测试覆盖父类契约 |
| `json_validate( $s )` | `json_decode( $s, true, $depth, JSON_THROW_ON_ERROR )` + `try/catch`；或 `json_decode(); JSON_ERROR_NONE === json_last_error()`。**必须判 `json_last_error()`，不能判返回值**：合法 JSON `null` 也返回 `null` |
| `array_find( $a, $fn )` | `foreach ( $a as $v ) { if ( $fn( $v ) ) { return $v; } } return null;`。不要 `array_filter( ... )[0]`，键不连续会 undefined offset |
| `array_any()` / `array_all()` | `foreach` + 布尔；或 `array_filter` 后判空（注意 `0` / `'0'` 是假值） |
| `mb_trim( $s )` | `preg_replace( '/^\s+\|\s+$/u', '', $s )` |
| `mb_ucfirst( $s )` | `mb_strtoupper( mb_substr( $s, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $s, 1, null, 'UTF-8' )` |
| property hooks | 普通属性 + 显式 `get_x()` / `set_x()`（本仓库现有风格） |
| `private(set) string $x` | `private string $x` + `public function x(): string` |
| `new Foo()->bar()` | `( new Foo() )->bar()` |
| `#[\Deprecated]` | docblock `@deprecated` |
| `RoundingMode::HalfUp` | `PHP_ROUND_HALF_UP` |
| `http_get_last_response_headers()` | `wp_remote_get()` 返回的 `array( 'headers' => … )`；不要用 `$http_response_header` |
| `request_parse_body()` | `$_POST` / `$_FILES` + `wp_unslash()`；REST 交给 WP |
| `Dom\HTMLDocument` | 不用 DOM（本仓库 0 处）；解析 HTML 用 `WP_HTML_Tag_Processor`（WP 6.2+） |
| `str_increment()` | 数字用 `(int)` 运算；不要给字符串做自增 |

### 2.2 能力守卫（次选）

新 API 确实明显更好（性能 / 正确性），且回落代价可接受时：

```php
if ( function_exists( 'json_validate' ) ) {
	$ok = json_validate( $json, 512 );
} else {
	json_decode( $json, true, 512 );
	$ok = JSON_ERROR_NONE === json_last_error();
}
```

规则：

- **必须写回落**，不许「有就用、没有就跳过」的半截逻辑。
- **必须有注释**说明为什么不用兼容写法（性能收益、行为差异）。
- **必须能被测试**：把守卫封进静态 helper（例如 `Support\Compat::json_validate()`），业务代码只调 helper；测试直接测 helper，8.3+ 分支在本机 8.4 可跑，8.2 分支用 `function_exists` 替身或显式 skip 并注明。
- 守卫只用于**函数 / 类 / 常量存在性**，不要用它探测语法。

### 2.3 版本判断（最后手段）

`PHP_VERSION_ID` 只在「无法用兼容写法、也无法用能力守卫」时用：

```php
// 8.4 才有 Random\Randomizer::getFloat()；这里只要 [0,1) 的浮点，
// 用 random_int() 拼出来更简单，所以不走版本判断。
```

规则：

- 不用于业务分支（同一站点不同请求行为不同）。
- 不用于语法（见 §2.4）。
- 必须注释「为什么不能用兼容写法」。
- 常量比较用 `PHP_VERSION_ID`（`80200` / `80300` / `80400`），不要字符串比较 `PHP_VERSION`。

### 2.4 为什么语法不能靠版本判断

PHP 的执行顺序是「整文件编译 → 执行」。8.3 的 typed constant 出现在文件里，8.2 在编译阶段就抛 `ParseError`，此时任何 `if ( PHP_VERSION_ID >= 80300 )` 都还没开始执行。所以：

- **语法特性**：硬禁。只有把下限提到 8.3 才能用。
- **运行时 API**（函数 / 类 / 方法 / 常量）：可以用 `function_exists` 等守卫。
- **行为变化**：既不能禁也不能守卫，只能改代码或接受差异，并测。

## 3. 语言与类型写法

这一节是 house style，不是通用最佳实践。**现有代码怎么写的，新代码跟着写；要改风格先改全仓**，不要在一个文件里另起一套。

### 3.1 文件头与 strict_types

每个 `src/` 下的文件：

```php
<?php
/**
 * 一句话说明这个类干什么。
 *
 * @package Sakura_Image_Optimizer
 */

declare( strict_types=1 );

namespace Sakura\ImageOptimizer\Admin;
```

- 新文件**必须**带 `declare( strict_types=1 );`，且放在 namespace 之前。
- 现有覆盖：`src/` 213 个文件里 177 个已声明，36 个没有（`src/Zibll/` 全部、`src/Sync/` 与 `src/S3/` 的一部分、`src/UserFiles/` 与 `src/Admin/` 的一部分）。**不要批量补**：strict_types 会改变参数强制转换行为，给旧文件补等于一次行为变更，必须单独提交并跑三道关。改到哪个文件、确认测试过了，再顺手补。
- `includes/` 的 30 个旧类只有 3 个声明，保持原样；它们是兼容层，不做风格迁移。
- 直访守卫：`src/` 里 202/213 有 `defined( 'ABSPATH' )` 守卫。新文件照写。
- 类文件不 `exit`、不输出、不产生副作用；钩子注册一律走 `register_hooks()`（见 [01-架构与装配](01-架构与装配.md)）。

### 3.2 类型

- **参数与返回值都标类型**；没有返回值写 `: void`；必然抛异常或退出的写 `: never`（现有 `src/S3/Connection_Tester.php:89`）。
- 可空写 `?string`，不要 `string|null`（两者等价，但全仓统一前者）。联合类型按窄到宽排列：`string|false`。
- 数组必须给形状，PHPStan level 5 靠它：

```php
/**
 * @param array<string, array{ bytes: int, mime: string }> $files
 * @return list<string>
 */
public function keys( array $files ): array {
```

- 边界进来的 `mixed`（`$wpdb` 行、`json_decode()` 结果、`$_POST`）在进入业务层前收窄：`is_array()` / `is_string()` / `(int)` 显式转换。
- strict_types 下不做隐式转换：`'12' + 1` 会抛 `TypeError`。要数字就 `(int) $value`，要字符串就 `(string) $value`。
- PHPStan 报类型错时，先改签名或补 docblock，**不要加 `ignoreErrors`**；确需豁免按 [05-测试与质量](05-测试与质量.md) §3 写理由并限定 path。

### 3.3 类与属性

- **默认 `final`**（现有 203 个 `final class`）。确实要给测试替身或扩展点继承时才去掉；去掉时在类 docblock 里写一句为什么。
- 一个文件一个类，类名 = 文件名（PSR-4）。现有两种命名并存（`Image_Payload` 与 `S3_Credential_Error`），新类沿用所在目录的既有风格，**不要为改名而改名**。
- **属性必须声明**（8.2 起动态属性弃用）。不要在 `__construct()` 里 `$this->foo = ...` 一个没声明的属性。
- readonly 属性用于不可变值对象（现有 `src/Thumbnail/Rendition.php:60-66` 的构造器提升 + readonly）。带状态、要 `set_*()` 的对象不要 readonly。
- 构造器属性提升（8.0）可以用，但**模块类仍然「构造函数只赋值、不挂钩子」**（[01-架构与装配](01-架构与装配.md) §2）；提升只是少写几行，不是绕过契约的理由。
- 静态属性只放无请求状态的常量级数据（表名、映射表）；请求态放实例或 `wp_cache`，否则多站点/长驻进程会串。
- 常量优先于魔法值：`private const MAX_ATTEMPTS = 3;`。类常量类型声明是 8.3 语法，**不要写**。

### 3.4 控制流

- `match` 可用（现有 60 处），但必须穷尽或带 `default`；**不要用 `match` 的表达式做副作用**（写库、发请求、改全局）。
- 早返回，嵌套不超过 3 层。`if` 里能 `return` 就不要 `else`。
- `??` 只处理 `null`。`WP_Error` 不是 `null`，`$result ?? $fallback` 不会替你兜错误，必须 `is_wp_error()`。
- 比较一律 `===` / `!==`；`in_array()` 第三参必须 `true`（现有调用全部符合）；`array_search()` 同理。
- `empty()` 只用于「可能未定义」；判 `0` / `'0'` 用 `isset()` + 严格比较。
- `foreach` 用引用后必须 `unset( $value )`，否则下一轮复用变量会写坏数据。
- 循环里不查库、不发 HTTP。批量走 `IN (...)` 一次查（占位符规则见 §5），或交给现有 worker/队列。

### 3.5 字符串、正则与多字节

- 插值用 `"{$var}"`；复杂拼接用 `sprintf()`（现有 347 处，跟着用）。
- 正则：`preg_*` 的返回值必须分别判 `false`（失败）与 `0`（不匹配），两者不是一回事；拼用户输入先 `preg_quote()`；分隔符统一 `/`。
- 中文 / emoji 一律 `mb_*` 或 `preg_*` 加 `/u`：字符数用 `mb_strlen()`，字节数才用 `strlen()`。不要用 `substr()` 截 UTF-8。
- 大小写：`strtolower()` 自 8.2 起只做 ASCII；非 ASCII 用 `mb_strtolower()`。
- 路径用 `wp_normalize_path()` 与 `/`；不要手拼 `DIRECTORY_SEPARATOR`（Windows 由 WP 统一）。

### 3.6 全局符号

- 新全局函数 / 常量 / 类必须带前缀：`sakura_imgopt`、`sakura_tools`、`sufm`、`sakura`、`Nboke`（PHPCS `PrefixAllGlobals` 的白名单，见 `phpcs.xml.dist`）。优先写成命名空间类的方法，不新增全局函数。
- 例外只有 `fast-search.php`：SHORTINIT、没有 autoloader，只能手写查询与哈希（F14）；改桶身份算法时这一处必须同步改。

## 4. 错误与异常

### 4.1 边界返回 WP_Error，内部抛异常

- **WP 边界**（钩子回调、REST、AJAX、CLI 命令入口）返回 `WP_Error`（现有 188 处）或 `wp_send_json_error()`（现有 51 处）；不要把异常抛进 WP 核心。
- **服务内部**抛异常（现有 40 处），由调用方决定转 `WP_Error`、重试还是中止。
- 自定义异常：`final class Xxx_Error extends \RuntimeException`，放对应模块目录。现有两个：`src/S3/S3_Credential_Error.php:24`、`src/UserFiles/Services/Media_Move_Failure.php:21`。异常消息给开发者看（英文或中文均可），**用户可见文案在边界用 `__( '…', 'sakura-imgopt' )` 翻译**。
- 异常要带上下文：对象 key、profile id、attachment id。不要 `throw new \RuntimeException( 'failed' )`。

### 4.2 捕获

- `catch` 必须处理：转 `WP_Error`、重试、或记录后重抛。**禁止空 catch**。
- `catch ( \Throwable $e )` 只允许出现在边界最外层（REST / AJAX / CLI / 上传管线）；服务内部按具体异常类型捕获。
- 不要用异常做正常控制流（例如用 `try/catch` 探测函数是否存在——用 `function_exists()`）。
- `finally` 用于释放锁、删临时文件；现有租约 / 临时文件模式见 [04-数据与存储](04-数据与存储.md) §7。

### 4.3 不要用的

- `trigger_error()`：8.4 起传 `E_USER_ERROR` 已弃用。要提醒开发者用 `_doing_it_wrong()`，要中止请求用 `wp_die()`。
- `die()` / `exit()`：只允许 `uninstall.php`、`fast-search.php` 的直访守卫。8.4 起 `exit` 更像函数（可当 callable、受 strict_types 影响），不要把它传来传去。
- `@` 抑制：只允许文件系统探针，且必须 `set_error_handler()` 后还原（现有 7 处 set_error_handler）。PHPCS 已关掉 `NoSilencedErrors`，所以评审要看理由。
- `error_log()` 刷生产日志：调试用 Query Monitor（[01-架构与装配](01-架构与装配.md) §2）；持久错误交给 WP 的 `debug.log`。
- 不要自己 `echo` 错误页：后台用 `wp_die()`，前台返回 `WP_Error` / HTTP 状态。

### 4.4 失败要可观测

- 队列 / worker 的失败写状态表（[09-删除与结算](09-删除与结算.md) 的 `active → deleting → purged`、同步中心状态），不要只写日志。
- 重试要有上限与退避；异常不要吞掉重试计数。
- 计数 / 指标写进现有 Metrics（[07-S3存储生命周期](07-S3存储生命周期.md) 的上传指标），不要新起一套。

## 5. 与 WordPress / 本仓库的交叉点

- **超全局**：`$_GET` / `$_POST` / `$_REQUEST` 必须 `wp_unslash()` + 按类型 sanitize（`sanitize_text_field()` / `absint()` / `wp_kses_post()`）；不要出现 `$_POST['x']` 直接进业务。
- **nonce / capability**：每个写操作 `check_admin_referer()` 或 `check_ajax_referer()` + `current_user_can()`；REST 用 `permission_callback`（[02-新增功能](02-新增功能.md) §5）。
- **SQL**：表名只走各 Schema 的 `table()`；值只走 `$wpdb->prepare()`；**占位符数量与参数数量一一对应**。F13 的教训：13 列对 12 个占位符，只有把参数 `substitute()` 回 SQL 的测试才抓到（[05-测试与质量](05-测试与质量.md)）。
- **option**：业务代码不直接 `get_option()`（铁律 9），走 `Settings_Repository`；新增 option 同时进 `Uninstaller`（铁律 6）。
- **i18n**：用户可见文案 `__( '…', 'sakura-imgopt' )`；异常消息不翻译；不要拼接可翻译字符串（用 `sprintf` + 完整句式）。
- **时间**：`time()` / `gmdate()`；不要 `date()` 依赖站点时区（现存 1 处旧代码，不要新增）。
- **文件**：走现有 `File_System` 抽象；`is_resource()` 只用于文件句柄；临时文件名用 `random_bytes()`，不要新增 `uniqid()`（现存 3 处旧代码）。
- **序列化**：option 只存标量 / 数组，不 `serialize()` 对象；`unserialize()` 必须 `array( 'allowed_classes' => false )`（现有 `src/S3/Link_Rewriter.php:187` / `:240` 就是标准写法），其余优先 `maybe_unserialize()`。
- **图片**：GD / Imagick 输出质量必须 clamp（8.4 起非法值抛异常）；格式能力用 `function_exists( 'imageavif' )` 判断，不要假设。
- **HTTP**：用 `wp_remote_*`（超时、重试、代理由 WP 管），不要裸 `curl_*`（现有 0 处）；S3 SDK 走 `src/S3/Object_Store.php` 是既定例外。

## 6. 让静态检查兜住版本

### 6.1 现在谁管什么（2026-11-24 实测）

| 检查 | 实际行为 | 管什么 |
|---|---|---|
| `composer phpstan` | `phpstan.neon` 已写 `phpVersion: 80200`，本地按 8.2 的函数 / 类表判断 | 8.3+ 的 API 在本地也会报 unknown；当前 0 处，`[OK] No errors` |
| `composer lint:strict` | `WordPress-Extra`（`phpcs.xml.dist`），只管 WP 惯例与安全 | 8.3 语法、8.4 弃用都不管 |
| `composer lint:compat` | `phpcs-compat.xml.dist` + PHPCompatibility，`testVersion 8.2-8.4` | 版本相关的语法 / 函数 / 弃用；当前 0 error / 0 warning |
| `php -l` | 只查语法；本机只有 8.3 / 8.4（`/www/server/php/` 下只有 `83`、`84`） | 不能证明 8.2 可解析 |
| GitHub Actions `tests.yml` | matrix **8.2 / 8.3 / 8.4** 跑 PHPUnit | 只有被测试真正加载到的文件才会在 8.2 下解析；WP-CLI、视图、Zibll 桥等覆盖不到的文件仍可能带 8.3 语法漏到生产 |

本地能给出「8.2 写、8.4 跑」结论的是 `composer lint:compat`；CI 的 8.2 job 仍是最后防线。

### 6.2 PHPStan 固定平台版本（已落地）

`phpstan.neon` 的 `parameters` 里：

```neon
    phpVersion: 80200
```

本地 CLI 是 8.4，不写这一行 PHPStan 会按 8.4 的函数表判断，8.3/8.4 才有的函数在本地不会报「未定义」。加完实测 `[OK] No errors`（当前 0 处 8.3+ API，没有误伤 `stubs/` 里的 WP 函数桩）。

### 6.3 PHPCompatibility（已接入，锁 10.0.0-alpha2）

版本选择：

- 稳定线 **9.3.5（2019-12-27）** 只认识到 PHP 7.4，接了等于假安全感。
- **10.0.0-alpha3（2026-11-17）** 有 8.3/8.4 的 sniff，但要求 `squizlabs/php_codesniffer: ^4.0.2`；本仓库是 phpcs 3.13.6 + WPCS 3.2（WPCS 3.x 只支持 phpcs 3.x），换 4.0 要连 WPCS 一起动。
- **10.0.0-alpha2（2025-11-28）** 要求 `phpcs ^3.13.3 || ^4.0`、`phpcsutils ^1.1.2`，与现状兼容，且已含 8.3/8.4 的 sniff（`RemovedImplicitlyNullableParamSniff`、`RemovedProprietaryCSVEscapingSniff`、`NewClassMemberAccessWithoutParenthesesSniff`、`RemovedTriggerErrorLevelSniff`、`RemovedXmlSetHandlerCallbackUnsetSniff` 等）。

落地物：

- `composer.json` 的 dev 依赖：`phpcompatibility/php-compatibility: 10.0.0-alpha2`（锁在这个 alpha；升级前先看 alpha3 的 phpcs 4 依赖）。
- `phpcs-compat.xml.dist`：扫 `sakura-tools.php`、`uninstall.php`、`fast-search.php`、`src/`、`includes/`；排除 `vendor/`、`node_modules/`、`assets/`、`tests/`、`docs/`；`testVersion 8.2-8.4`（8.2 是语法下限、8.4 是生产运行时，8.4 才出现的弃用也能抓到）。
- `composer.json` 的 scripts：`"lint:compat": "phpcs --standard=phpcs-compat.xml.dist"`。

基线 **0 error / 0 warning**（2026-11-24）。第一次跑出 7 条 `RemovedProprietaryCSVEscaping.DeprecatedParamNotPassed`，都是 `fputcsv()` 没显式传 `$escape`，已按 §1.3 第 2 条修完。

装不上时先看 composer 版本：本机 `/usr/bin/composer` 是 2.0.14（2021），`composer-plugin-api` 2.0.0 满足不了 `dealerdirect/phpcodesniffer-composer-installer` 的 `^2.2`，会直接报 Problem 1/2；换新版 `composer.phar`（2.11.2）装。

### 6.4 过渡期的提交前自查

`composer lint:compat` 已能自动抓版本问题；下面四条 grep 用来看「哪些文件碰了版本边界」：

```bash
cd /root/work/sakura-tools

# 1) 8.3/8.4 独有语法（命中即必须改）
grep -rnE '#\[\\\\?Override\]|readonly[[:space:]]+class|(private|protected|public)[[:space:]]*\([[:space:]]*set[[:space:]]*\)|const[[:space:]]+[A-Za-z_\\\\]+[[:space:]]+[A-Z_]+[[:space:]]*=' src includes

# 2) 8.3/8.4 独有函数（默认不用；用了必须有守卫）
grep -rnE '\b(json_validate|mb_str_pad|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|array_find|array_find_key|array_any|array_all|str_increment|str_decrement)[[:space:]]*\(' src includes

# 3) 隐式 nullable（命中后人工看类型前有没有问号）
grep -rnE 'function[^)]*\$[A-Za-z_]+[[:space:]]*=[[:space:]]*null' src includes

# 4) CSV 未传 escape（命中即改，规则见 §1.3 第 2 条）
grep -rnE '\b(fputcsv|fgetcsv|str_getcsv)[[:space:]]*\(' src includes
```

人工看两处结果：`grep -rn 'exit(\|die(' src includes | grep -v wp_die`，以及搜索双引号字符串里的美元大括号插值（用编辑器搜即可，不要用会展开变量的 shell 写法）。

注意：grep 是提示不是判定——第 3 条会把 `?string $x = null` 也命中（那是正确写法）。最终以 `composer lint:compat`、CI 的 8.2 job 与代码评审为准。

## 7. 现状盘点（2026-11-24 实测）

扫描范围：`src/`、`includes/`、`sakura-tools.php`、`uninstall.php`、`fast-search.php`。方法：`token_get_all()` 一次性脚本统计函数声明与参数，配合 grep 统计调用；脚本未入库，结论是**快照**，改完代码按 §6.4 重跑。

| 项目 | 实测 | 结论 |
|---|---|---|
| PHP 文件 / 函数声明 | 243 / 2508（`src/` 213、`includes/` 30） | — |
| 隐式 nullable 参数 | **0** | 8.4 弃用为零；新代码保持 |
| 8.3 / 8.4 独有语法 | 0 | 无 typed constants、无 `#[\Override]`、无 property hooks、无非对称可见性、无 `new Foo()->` |
| 8.3 / 8.4 独有 API | 0（`vendor/` 里 symfony polyfill 不算） | 不用守卫也安全；新增时必须走 §2.2 |
| CSV 未显式传 escape | **0**（2026-11-24 修完 7 处） | 由 `composer lint:compat` 守护，见 §1.3 第 2 条 |
| `strict_types` 覆盖 | `src/` 177/213；`includes/` 3/30 | 新文件必须；旧文件不批量补 |
| `final class` / `readonly` 属性 | 203 / 17 | 默认 final、值对象 readonly 是现状 |
| `match` / `fn` / `?->` / `enum` / `never` | 60 / 38 / 0 / 0 / 5 | match 与箭头函数已常用；enum 未用 |
| `WP_Error` / `throw` / 自定义异常 | 188 / 40 / 2 | 边界返回错误对象、内部抛异常已是现状 |
| `round()` / `range()` / `unserialize()` | 60 / 0 / 2（两处直接调用都带 `allowed_classes => false`） | 8.4 round 重写要抽样核对 |
| `php -l` 全仓 | 0 语法错误（PHP 8.4.28） | 只能证明 8.4 可解析 |
| 生产 / CI / 本地 PHP | 生产 8.4；CI matrix 8.2/8.3/8.4，phpstan CI 固定 8.2；本地 8.4.28 | 本地最容易漏 8.3+ API |

## 8. 提交自检

- [ ] 没有 8.3/8.4 独有的**语法**（对照 §1.2 / §1.3 禁用清单；CI 的 8.2 job 通过）。
- [ ] 8.4 弃用为 0：没有隐式 nullable；`fputcsv/fgetcsv/str_getcsv` 显式传 `$escape`；没有 `E_STRICT`；`composer lint:compat` 0 error / 0 warning。
- [ ] 新文件有 `declare( strict_types=1 );`、namespace、类 docblock、`ABSPATH` 守卫；类名 = 文件名。
- [ ] 参数 / 返回值都有类型，数组有 shape docblock；没有新增 `ignoreErrors`。
- [ ] 没有新增 `exit` / `die` / `@` / `trigger_error()` / `error_log()`；`catch` 不为空，异常带上下文。
- [ ] 新 API 若属 8.3+：有 `function_exists` / `method_exists` 守卫、回落、注释、测试（§2.2）。
- [ ] 8.4 行为变化影响面已核对（`round()`、`strtolower()`、`unserialize()` 等，见 §1.4）。
- [ ] WP 交叉点：`wp_unslash` + sanitize、nonce / capability、`prepare()` 占位符数量、i18n、`time()/gmdate()`、序列化白名单（§5）。
- [ ] 三道关 + `php -l` + `git diff --check`（[05-测试与质量](05-测试与质量.md)）。
- [ ] 只改 `.md` 时不动版本号、不写 changelog、不跑代码测试（AGENTS.md §5.1）。

## 附录 A　官方变更摘要（按版本）

只列 Core / Standard 里会影响插件写法的条目；Intl、SOAP、ODBC、PCRE 内部细节用到时查原文。官方章节名：1. Backward Incompatible Changes、2. New Features、4. Deprecated Functionality、6. New Functions、8. Removed Extensions and SAPIs。

### A.1 PHP 8.2

| 类别 | 条目 |
|---|---|
| 新增 | readonly 类；DNF 类型；`true` / `false` / `null` 独立类型；trait 常量；enum 常量表达式取值；`#[\SensitiveParameter]`；Random 扩展；`curl_upkeep()`；`mysqli_execute_query()`；`memory_reset_peak_usage()`；`ini_parse_quantity()`；`openssl_cipher_key_length()`；`ReflectionFunction::isAnonymous()`；`ReflectionMethod::hasPrototype()` |
| 弃用 | 动态属性；部分字符串 callable；双引号里的 `${var}` / `${expr}` 插值；mbstring 的 QPrint / Base64 / Uuencode / HTML-ENTITIES 编码；`utf8_encode()` / `utf8_decode()`；`SplFileInfo::_bad_state_ex()` |
| 不兼容 | `strtolower()` / `strtoupper()` 只做 ASCII；`str_split( '' )` 返回 `[]`；`glob()` 受 open_basedir 时返回空数组；`str*cmp` / `str*pos` 返回 -1/0/1；`iterator_to_array()` / `iterator_count()` 接受 `iterable`；`random_bytes()` / `random_int()` 抛 `Random\RandomException`；`unserialize()` 结构校验更严；`DateTime::createFromImmutable()` / `createFromMutable()` 返回 `static` |
| 移除 | 无（该章节为空） |

### A.2 PHP 8.3

| 类别 | 条目 |
|---|---|
| 新增（语法） | typed class constants；`#[\Override]`；`C::{$name}` 动态类常量；static 变量任意表达式初始化；clone 内重初始化 readonly；readonly 匿名类；trait 方法 `final`；魔法方法闭包支持命名参数 |
| 新增（API） | `json_validate()`；`mb_str_pad()`；`str_increment()` / `str_decrement()`；`Randomizer::getBytesFromString()` / `nextFloat()` / `getFloat()`；`ReflectionMethod::createFromMethodName()`；`DatePeriod::createFromISO8601String()`；`stream_context_set_options()`；DOM 若干方法；`class_alias()` 支持内部类 |
| 弃用 | 无参 `get_class()` / `get_parent_class()`；字符串 `++` / `--`；`assert_options()` 与 `ASSERT_*`；`mb_strimwidth()` 负宽；`ReflectionProperty::setValue()` 单参；`dba_fetch()` 第三参；`MT_RAND_PHP`；`ldap_connect()` 两参；`Phar::setStub( resource, len )`；`SQLite3::enableExceptions( false )`；`ZipArchive::FL_RECOMPRESS` |
| 不兼容 | `range()` 抛 `ValueError` / 发 `E_WARNING`；`array_sum()` / `array_product()` 对不可转数值发警告；`number_format()` 负精度生效；`unserialize()` 的 `E_NOTICE` 升为 `E_WARNING`；`str_getcsv()` 末字段空串；`proc_open()` 参数校验；`highlight_string()` 输出结构；`file()` flags 校验；`ReflectionClass::getStaticProperties()` 不再可空；trait 静态属性重新声明；空数组负索引 |
| 移除 | `opcache.consistency_checks`（INI，不是扩展） |

### A.3 PHP 8.4

| 类别 | 条目 |
|---|---|
| 新增（语法） | property hooks；非对称可见性；`new Foo()->bar()`；`#[\Deprecated]`；lazy objects；`exit` / `die` 函数化 |
| 新增（API） | `array_find()` / `array_find_key()` / `array_any()` / `array_all()`；`mb_trim()` / `mb_ltrim()` / `mb_rtrim()`；`mb_ucfirst()` / `mb_lcfirst()`；`http_get_last_response_headers()` / `http_clear_last_response_headers()`；`RoundingMode`；`request_parse_body()`；`BcMath\Number`；`Dom\HTMLDocument` / `Dom\XMLDocument`；`DateTime[Immutable]::createFromTimestamp()` / `getMicrosecond()` / `setMicrosecond()`；`XMLReader::fromString()` 等 |
| 弃用 | 隐式 nullable；CSV 的 `$escape` 默认值；`E_STRICT`；`trigger_error( …, E_USER_ERROR )`；单下划线类名；0 的负数次幂；`lcg_value()`；`mysqli_ping/kill/refresh`；`session_set_save_handler()` 多参；5 个 session INI；`stream_context_set_option()` 双参；`xml_set_object()`；`DatePeriod` 字符串构造；`CURLOPT_BINARYTRANSFER`；`DOM_PHP_ERR`；hash 选项类型；`ReflectionMethod` 单参构造；`SplFixedArray::__wakeup()`；`unserialize()` 的大写 S 标签；`SplFileObject` 与 CSV 三函数的 escape；`SoapServer::addFunction( int )` |
| 不兼容 | `exit` / `die` 行为；`PHP_DEBUG` / `PHP_ZTS` 变 bool；`E_STRICT` 移除；扩展类常量变 typed；DBA / ODBC / SOAP 资源变对象；GD 非法参数抛异常；`round()` 重写与精度；`str_getcsv()` 抛 `ValueError`；`unserialize()` 校验 `allowed_classes`；`GMP` 类 final；DOM 新类与旧类差异；SimpleXML 迭代；`password_hash()` bcrypt cost；临时文件名长度；PCRE2 10.44；`mb_substr()` 非法字符串索引语义；XML handler 签名 |
| 移除 | IMAP / OCI8 / PDO_OCI / PSpell 移出核心；`DOMImplementation::getFeature()`；4 个 mysqli 常量；`MYSQLI_SET_CHARSET_DIR` |

### A.4 维护方式

PHP 8.5 发布后，按同样的四步更新本篇：拉 UPGRADING 原文 → 在 §1 加一行「8.5：语法禁用 / API 默认不用 / 弃用必改」→ 在附录 A 加一张表 → 重跑 §7 的盘点并把日期改成新的。**不要只改文首的版本范围而不改矩阵**，那会让读者以为 8.5 已经可用。

## 附录 B　复现命令

```bash
# 1) 拉官方 UPGRADING（php-src 官方仓库，与 php.net 手册同源）
mkdir -p /tmp/phpver && cd /tmp/phpver
for v in 8.2 8.3 8.4; do
  curl -sSL "https://raw.githubusercontent.com/php/php-src/PHP-$v/UPGRADING" -o "UPGRADING-$v.txt"
done

# 2) 看 8.4 的正文两章（开头 3–16 行是目录，不要用标题匹配；行号以本次下载为准）
sed -n '19,278p' UPGRADING-8.4.txt    # 1. Backward Incompatible Changes
sed -n '480,625p' UPGRADING-8.4.txt   # 4. Deprecated Functionality

# 3) 复现两条 8.4 弃用文案（需要 PHP 8.4）
php -d error_reporting=-1 -d display_errors=1 -r 'function t(string $x = null) {}'
php -d error_reporting=-1 -d display_errors=1 -r '$f = fopen("php://memory", "w"); fputcsv($f, array("a"));'
```

最后一条输出应为 `the $escape parameter must be provided as its default value will change`；如果没输出，说明当前 CLI 不是 8.4，或 `error_reporting` 被 ini 覆盖。
