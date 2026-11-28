# A8-1 代码级对照说明

- `before-beta26.json`：加载 `/www/wwwroot/demo.77il.cn/wp-content/plugins/sakura-tools`（beta-26）后，对同一组数组输入调用 `Settings::sanitize()` 与 `Space_Settings::get()` 的结果。
- `after-beta27.json`：加载工作区 beta-27 源码的同一调用。
- harness 源码：`/tmp/beta27-a81-harness.php`（一次性脚本，未入库；逻辑见上级 README 的输入说明）。
- 两组输出均由 `/www/server/php/84/bin/php` 生成，`set_error_handler` 捕获全部 E_WARNING/E_USER_WARNING。