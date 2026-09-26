# validkit —— 表单校验器

PHP 8 的小型表单校验库：用「路径 => 规则串」声明规则，对一份数组数据做校验，
把失败按 `[路径 => [消息…]]` 聚合返回。纯 PHP 标准库（`php-cli`），
无第三方依赖，不用 Composer，**不使用 mbstring 扩展**。

```php
use Validkit\Validator;

$v = new Validator([
    'user.name' => 'required|string|min:2',
    'user.age'  => 'required|int|min:0',
    'items.*.qty' => 'int',
]);

if (!$v->validate($data)) {
    print_r($v->errors());   // ['user.age' => ['int'], 'items.1.qty' => ['int']]
}
```

## 目录

```
src/             实现（Validator.php / RuleSet.php / Path.php / Rule.php / Errors.php）
tests/run.php    既有用例（16 个，自建断言 runner，不用 PHPUnit）
repro.php        投诉里那一条的最小复现
check/check.php  固定验收入口（**勿改**）
scripts/check.sh 固定验收入口的薄封装
```

## 对外保证（9 条）

下面每一条都是对外保证。**验收以 `check/check.php` 为准**，语义细节以本节为准。

1. **规则与路径**：规则以路径为键（`['user.name' => 'required|string|min:2']`），
   同一个路径上多条规则用 `|` 分隔。嵌套数组用点分路径寻址（`a.b.0.c` 表示
   `$data['a']['b'][0]['c']`，数字段是数组下标）；路径里带 `*` 时（`a.*.c`）
   对当前这一层数组的**每一项各校验一次**。
2. **`required` 的「有值」定义**：`'0'`、`0`、`0.0`、`false` 都算**有值**（通过 `required`）；
   `''`、`null`、`[]` 以及键缺失都不算有值（报错）。
3. **类型强制只做严格转换**：`int` 规则下，`'12'` → `12` 成功（`value('路径')` 拿到整数 12）；
   `'12abc'`、`' 12'`、`'1e3'` 一律**失败**。也就是只接受整数本身与「整串都是十进制数字
   （可带一个前导 `-`）」的字符串；不采用 `(int)` 的宽松语义，也不只靠 `is_numeric`。
4. **长度**：`min:n` / `max:n` 对**字符串**按 **UTF-8 字符数**（不是字节数）比较，
   对数字按数值比较。
5. **正则**：`regex:<pattern>` 的 `<pattern>` 是**带定界符的完整 PCRE**（如 `regex:/[0-9]+/`），
   匹配时**自动加锚点**（等价于 `\A … \z`）—— 因此 `regex:/[0-9]+/` 会拒绝 `abc123`。
6. **错误聚合**：`errors()` 返回 `[路径 => [消息…]]`。消息就是触发失败的**规则原文**
   （`required`、`min:5`、`int`、`regex:/[0-9]+/` …）。同一路径上的多条规则失败要
   **全部**收集（不短路）；返回数组的键按**规则的定义顺序**排列。
7. **通配展开后的路径**：`a.*.c` 这类规则展开后，错误里的路径用**实际下标**
   （`a.0.c`、`a.1.c`），不是字面的 `*`。
8. **健壮性**：任何输入（`null`、标量、空数组、深层嵌套、非数组的中间路径）都返回
   结构化结果，或抛 `ValidkitException`；不得泄漏 `TypeError`、未定义键通知或
   `ArrayAccess` 警告。
9. **纯函数**：不读文件、不发网络、不依赖时区与 `locale`；同一输入同一输出，
   错误顺序确定。

### 公开接口

```php
namespace Validkit;

final class Validator
{
    /** @param array<string, string> $rules 路径 => 规则串（规则串用 | 分隔） */
    public function __construct(array $rules);

    /** 校验整份数据；通过返回 true，否则 false。错误用 errors() 取。 */
    public function validate(array $data): bool;

    /** validate() 之后：是否已经跑过且无错误。 */
    public function passes(): bool;

    /** validate() 之后：是否已经跑过且有错误。 */
    public function fails(): bool;

    /** @return array<string, list<string>> 路径 => 消息列表 */
    public function errors(): array;

    /** 某个具体路径上的值（int 规则转换后的结果）；未校验过为 null。 */
    public function value(string $path);

    /** @return list<string> 规则里出现的路径（去重，按首次出现顺序） */
    public function paths(): array;
}

final class ValidkitException extends \Exception
{
}
```

## 怎么跑

```bash
php tests/run.php                  # 既有用例
php repro.php                      # 投诉里那一条的最小复现
bash scripts/check.sh              # 全部验收场景，全过才退 0
bash scripts/check.sh -list        # 列出全部场景
bash scripts/check.sh --only path  # 只跑一组（presence / path / strict / count / errors）
```

`check/check.php` 是**固定验收入口，不要改它**；失败不早退，一次把问题全暴露出来。

## 版本前提

- PHP 8.0 及以上，只用标准库（`php-cli`），不需要 Composer、联网、数据库或中间件；
  不使用 mbstring 扩展。
- 判据完全确定：输入写死，不依赖时间、随机源或机器速度。
