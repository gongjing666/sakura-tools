# 验收脚本

给「必须跑进真 WordPress 才看得出来」的断言用的。单元测试（`composer test`）跑在手写 shim 上，`$wpdb` 是替身，所以凡是依赖真实 SQL、真实钩子顺序、真实 session 的东西都得在这里验。

## 怎么跑

```bash
cd /path/to/wordpress
wp eval-file /path/to/this/dir/p1-lifecycle.php --allow-root
```

脚本会按 `$argv[1]` → 环境变量 `SUFM_SITE` → `/root/sio-e2e/site` 的顺序找站点根目录。**不要指向生产站**——这些脚本会 `DELETE` 自己的测试行，虽然是有针对性的，但没有理由在生产上跑。

要一个干净的站点：

```bash
cd /root/sio-e2e/site && php -S 127.0.0.1:18080
```

## 约定

每个脚本自带 `chk( $label, $ok, $detail )` 计数，最后打印 `==== N passed, M failed ====` 并以失败数退出。看一行就够了：

```bash
wp eval-file p1-lifecycle.php --allow-root | grep -E "FAIL|===="
```

**三个坑，写新脚本前先读**（都在这两轮里真踩过）：

1. **`wp eval-file` 在方法作用域里 `eval()`。** 文件顶层变量不是全局的，辅助函数里 `global $foo` 拿到的是空值。要么全部内联在一层作用域，要么只用 `$GLOBALS['x']`。
2. **`check()` 里的 `global $pass, $fail` 同样失效。** 计数要写 `$GLOBALS['p']`，否则永远打印 0/0。
3. **`wipe()` 必须把审计表一起清掉。** 只清业务表时，上一轮的 `events` 还在，下一轮断言「恰好 1 条」就会读到 2 条。`p1-lifecycle.php` 的 `$wipe` 就是踩过这个才补上 `DELETE FROM events` 的。

## 已有的

| 脚本 | 覆盖 | 用例数 |
|---|---|---|
| `p1-rest.php` | 处罚的三个 REST endpoint（表单数据 / 创建 / 撤销）、永久确认门（缺 `confirm` → 400）、`admin_users` 行的 `sanction` 与 `can_sanction`、无能力者与自我处罚被拒 | 39 |
| `p1-lifecycle.php` | 懒到期（读时翻状态、立刻解除拦截）、`sufm_tick` 收尾（审计 + 到期通知 + 水位 + 幂等）、`Schema::VERSION`、卸载清单、`warn` 不写 `ends_at`、撤销后立刻放行 | 23 |

两份都对应 `docs/开发规则/11-违规处置与用户处罚.md` 的 §11 验收清单；P1 的其余条目是浏览器点出来的（下架弹窗、用户页签、1440/390），没有脚本化。
