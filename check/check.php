<?php

declare(strict_types=1);

/**
 * 固定验收入口：validkit 的「对外保证」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                    跑全部场景，全过才退 0
 *   php check/check.php -list              列出全部场景
 *   php check/check.php --only presence    只跑一组
 *   php check/check.php --only path,count  只跑指定的几组
 *
 * 五组共 9 个场景：
 *   presence 2 —— '0'/0/0.0/false 算有值；''/null/[]/缺失键 不算有值；
 *   path     2 —— 嵌套 a.b.0.c 定位数组元素；a.*.c 展开成实际下标；
 *   strict   2 —— int 只做严格转换；regex 自动加锚点；
 *   count    1 —— min/max 对字符串按 UTF-8 字符数；
 *   errors   2 —— 同一路径多条失败全收集；路径顺序与规则定义顺序一致。
 *
 * 只依赖 PHP 8 标准库；判据完全确定，不读时间、不用随机源。失败不早退。
 * 每个场景结束都断言「期间没有产生任何 warning / notice」（不使用 @ 抑制）。
 */

require __DIR__ . '/../src/Errors.php';
require __DIR__ . '/../src/Rule.php';
require __DIR__ . '/../src/RuleSet.php';
require __DIR__ . '/../src/Path.php';
require __DIR__ . '/../src/Validator.php';

use Validkit\Validator;

// ---------------------------------------------------------------------------
// PHP 诊断收集：把 warning / notice 记下来，供每个场景末尾断言
// ---------------------------------------------------------------------------

$GLOBALS['VK_PHP_ERRORS'] = [];

function vk_severity_name(int $severity): string
{
    switch ($severity) {
        case E_ERROR:
        case E_USER_ERROR:
            return 'error';
        case E_WARNING:
            return 'warning';
        case E_USER_WARNING:
            return 'user-warning';
        case E_NOTICE:
            return 'notice';
        case E_USER_NOTICE:
            return 'user-notice';
        case E_DEPRECATED:
            return 'deprecated';
        case E_USER_DEPRECATED:
            return 'user-deprecated';
    }

    return 'diagnostic(' . $severity . ')';
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $GLOBALS['VK_PHP_ERRORS'][] = sprintf(
        '[%s] %s @ %s:%d',
        vk_severity_name($severity),
        $message,
        basename($file),
        $line
    );

    return true;
});
error_reporting(E_ALL);

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function expect_same($want, $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, var_export($want, true), var_export($got, true));
    }
}

function expect_true(bool $cond, string $what, string $expected = 'true'): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, 'false');
    }
}

function err_text(array $errors): string
{
    if ($errors === []) {
        return '{}';
    }

    $parts = [];
    foreach ($errors as $path => $messages) {
        $parts[] = $path . '=[' . implode(', ', $messages) . ']';
    }

    return '{' . implode('; ', $parts) . '}';
}

/** 断言 errors() 与期望逐条一致（键顺序也算）。 */
function expect_errors(array $want, array $got, string $what): void
{
    if ($want === $got) {
        return;
    }

    throw new CheckFailure($what, err_text($want), err_text($got));
}

/** 断言这一段校验期间没有任何 warning / notice 泄漏。 */
function expect_quiet(): void
{
    $errors = $GLOBALS['VK_PHP_ERRORS'];
    if ($errors !== []) {
        throw new CheckFailure('不得产生任何 warning / notice', '(无)', implode('; ', $errors));
    }
}

// ---------------------------------------------------------------------------
// [presence] 2
// ---------------------------------------------------------------------------

$presence = [
    tk('zero', "'0'、0、0.0、false 都算有值", function (): void {
        $v = new Validator([
            'a' => 'required',
            'b' => 'required',
            'c' => 'required',
            'd' => 'required',
        ]);

        expect_true(
            $v->validate(['a' => '0', 'b' => 0, 'c' => 0.0, 'd' => false]),
            "'0' / 0 / 0.0 / false 都应通过 required"
        );
        expect_errors([], $v->errors(), '不应产生任何错误');
        expect_quiet();
    }),

    tk('empty', "''、null、[] 与缺失键都不算有值", function (): void {
        $v = new Validator([
            'a' => 'required',
            'b' => 'required',
            'c' => 'required',
            'd' => 'required',
        ]);

        expect_true(!$v->validate(['a' => '', 'b' => null, 'c' => []]), '四个路径都该被 required 拒绝');
        expect_errors([
            'a' => ['required'],
            'b' => ['required'],
            'c' => ['required'],
            'd' => ['required'],
        ], $v->errors(), "'' / null / [] / 缺失键 都要报 required，顺序按定义");
        expect_quiet();
    }),
];

// ---------------------------------------------------------------------------
// [path] 2
// ---------------------------------------------------------------------------

$path = [
    tk('nested', '嵌套路径 a.b.0.c 定位到数组元素', function (): void {
        $v = new Validator(['a.b.0.c' => 'required|min:1']);

        expect_true($v->validate(['a' => ['b' => [['c' => 'ok']]]]), 'a.b.0.c 上的 required|min:1 应当通过');
        expect_errors([], $v->errors(), '不应产生任何错误');

        $v2 = new Validator(['user.name' => 'required']);
        expect_true(!$v2->validate(['user' => 'scalar']), '非数组的中间路径应当报 required，而不是抛错');
        expect_errors(['user.name' => ['required']], $v2->errors(), '结构化错误而不是 TypeError');
        expect_quiet();
    }),

    tk('wildcard', 'a.*.c 按实际下标展开', function (): void {
        $v = new Validator(['items.*.qty' => 'int']);

        expect_true(!$v->validate(['items' => [['qty' => '1'], ['qty' => 'x']]]), '第二个元素不是整数，应当失败');
        expect_errors(['items.1.qty' => ['int']], $v->errors(), '错误路径用实际下标 items.1.qty');
        expect_same(1, $v->value('items.0.qty'), '第一个元素应被转换成整数 1');
        expect_quiet();
    }),
];

// ---------------------------------------------------------------------------
// [strict] 2
// ---------------------------------------------------------------------------

$strict = [
    tk('cast', "int 只接受严格整数：'12' 通过，'12abc'/' 12'/'1e3' 拒绝", function (): void {
        $v = new Validator([
            'a' => 'int',
            'b' => 'int',
            'c' => 'int',
            'd' => 'int',
        ]);

        expect_true(!$v->validate(['a' => '12', 'b' => '12abc', 'c' => ' 12', 'd' => '1e3']), '只有 a 是严格整数');
        expect_same(12, $v->value('a'), "'12' 应转换成整数 12");
        expect_errors([
            'b' => ['int'],
            'c' => ['int'],
            'd' => ['int'],
        ], $v->errors(), "'12abc' / ' 12' / '1e3' 都要报 int，顺序按定义");
        expect_quiet();
    }),

    tk('anchors', 'regex 自动加锚点', function (): void {
        $v = new Validator([
            'a' => 'regex:/[0-9]+/',
            'b' => 'regex:/[0-9]+/',
            'c' => 'regex:/[0-9]+/',
        ]);

        expect_true(!$v->validate(['a' => '123', 'b' => 'abc123', 'c' => '123abc']), '只有 a 整串都是数字');
        expect_errors([
            'b' => ['regex:/[0-9]+/'],
            'c' => ['regex:/[0-9]+/'],
        ], $v->errors(), '正则必须是整串匹配，消息是规则原文');
        expect_quiet();
    }),
];

// ---------------------------------------------------------------------------
// [count] 1
// ---------------------------------------------------------------------------

$count = [
    tk('utf8', 'min/max 对字符串按 UTF-8 字符数', function (): void {
        $v = new Validator([
            'short' => 'min:3',
            'exact' => 'min:3',
            'limit' => 'max:2',
        ]);

        expect_true(
            !$v->validate(['short' => '你好', 'exact' => '你好吗', 'limit' => '你好']),
            '「你好」只有 2 个字符，不满足 short 的 min:3'
        );
        expect_errors(['short' => ['min:3']], $v->errors(), '只有 short 应当失败（exact=3 字符、limit=2 字符都刚好满足）');
        expect_quiet();
    }),
];

// ---------------------------------------------------------------------------
// [errors] 2
// ---------------------------------------------------------------------------

$errors = [
    tk('multi', '同一路径的多条失败全部收集', function (): void {
        $v = new Validator(['name' => 'required|min:5|max:1']);
        $v->validate(['name' => 'abc']);

        expect_errors(['name' => ['min:5', 'max:1']], $v->errors(), 'min:5 与 max:1 都要收集，且按规则顺序');
        expect_quiet();
    }),

    tk('order', '路径顺序与规则定义顺序一致', function (): void {
        $v = new Validator([
            'b' => 'min:9',
            'a' => 'min:9',
            'c' => 'min:9',
            'd' => 'min:9',
        ]);
        $v->validate(['a' => 'x', 'b' => 'y', 'c' => 'z', 'd' => 'w']);

        expect_errors([
            'b' => ['min:9'],
            'a' => ['min:9'],
            'c' => ['min:9'],
            'd' => ['min:9'],
        ], $v->errors(), '错误路径应按规则定义顺序 b, a, c, d（而不是数据顺序）');
        expect_quiet();
    }),
];

$GROUPS = [
    'presence' => $presence,
    'path' => $path,
    'strict' => $strict,
    'count' => $count,
    'errors' => $errors,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（presence/path/strict/count/errors）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-9s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    $GLOBALS['VK_PHP_ERRORS'] = [];

    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        $extra = $GLOBALS['VK_PHP_ERRORS'] === [] ? '' : '；PHP 诊断：' . implode('; ', $GLOBALS['VK_PHP_ERRORS']);
        printf("FAIL %s/%s  期望=%s 实际=%s（%s%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage(), $extra);
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    if ($GLOBALS['VK_PHP_ERRORS'] !== []) {
        $failed++;
        printf(
            "FAIL %s/%s  期望=(无 warning/notice) 实际=%s（%s）\n",
            $group,
            $name,
            implode('; ', $GLOBALS['VK_PHP_ERRORS']),
            '校验期间泄漏了 PHP 诊断'
        );
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);
