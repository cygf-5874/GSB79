线上跑了半年的校验规则，最近开始出现「明明填了却说没填」的投诉。这是 validkit，
PHP 8 的表单校验库，只用内置函数与数组，不装 composer 包，也不用 mbstring，
用例跑 `php tests/run.php`，固定验收程序是 `php check/check.php`（别改）。

README「对外保证」一节列了 9 条；`tests/` 里 16 个用例当前全绿，但它们只喂过
ASCII 输入、扁平路径、单个规则。`repro.php` 是投诉里那一条的最小复现。

任务：把 9 条补齐，让既有用例与固定件全过。

验收：
- php tests/run.php 退出码 0；
- php check/check.php 退出码 0，9 个场景全过（presence 2 + path 2 + strict 2 + count 1 + errors 2）。

约束：
1. 不改 `check/`、不改 `repro.php`、不改既有用例的断言；已公开的方法签名不变（可新增）。
2. 不引入第三方依赖，不使用 mbstring 扩展。
3. 不改返回结构（`errors()` 的键值形状不动）。
4. 不许用 `@` 抑制错误来「消除」通知与警告。
