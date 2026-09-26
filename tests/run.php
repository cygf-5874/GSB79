<?php

declare(strict_types=1);

/**
 * validkit 的既有用例（自建断言 runner，不用 PHPUnit）。
 *
 * 用法：php tests/run.php
 *
 * 这一组用例当前的定位：只喂 ASCII 输入、扁平路径、单个规则 ——
 * 覆盖最顺的那条路径（必填 / 类型 / 长度 / 正则 / 错误顺序 / 重复校验）。
 * 嵌套与数组路径、通配展开、非 ASCII 长度、严格类型转换、多规则聚合等
 * 以 README「对外保证」一节为准。
 */

require __DIR__ . '/../src/Errors.php';
require __DIR__ . '/../src/Rule.php';
require __DIR__ . '/../src/RuleSet.php';
require __DIR__ . '/../src/Path.php';
require __DIR__ . '/../src/Validator.php';

use Validkit\Validator;
use Validkit\ValidkitException;

final class TestFailure extends \Exception
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

function assert_same($expected, $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new TestFailure($what, var_export($expected, true), var_export($actual, true));
    }
}

function assert_true(bool $cond, string $what): void
{
    if (!$cond) {
        throw new TestFailure($what, 'true', 'false');
    }
}

function assert_false(bool $cond, string $what): void
{
    if ($cond) {
        throw new TestFailure($what, 'false', 'true');
    }
}

/** @return array<string, list<string>> */
function errs(Validator $v): array
{
    return $v->errors();
}

$TESTS = [];

function t(string $name, callable $fn): void
{
    global $TESTS;
    $TESTS[] = [$name, $fn];
}

// ---------------------------------------------------------------------------
// 用例
// ---------------------------------------------------------------------------

t('必填规则对非空字符串通过', function (): void {
    $v = new Validator(['name' => 'required']);
    assert_true($v->validate(['name' => 'Ada']), '非空字符串应通过 required');
    assert_same([], errs($v), '不应有错误');
});

t('必填规则对空串报错', function (): void {
    $v = new Validator(['name' => 'required']);
    assert_false($v->validate(['name' => '']), '空串应被 required 拒绝');
    assert_same(['name' => ['required']], errs($v), '错误路径与消息');
});

t('必填规则对缺失键报错', function (): void {
    $v = new Validator(['name' => 'required']);
    assert_false($v->validate([]), '缺少 name 应被 required 拒绝');
    assert_same(['name' => ['required']], errs($v), '错误路径与消息');
});

t('string 规则接受字符串', function (): void {
    $v = new Validator(['name' => 'string']);
    assert_true($v->validate(['name' => 'Ada']), '字符串应通过 string');
    assert_same('Ada', $v->value('name'), 'value() 返回该路径的值');
});

t('string 规则接受整数', function (): void {
    $v = new Validator(['name' => 'string']);
    assert_true($v->validate(['name' => 42]), '整数应通过 string');
});

t('string 规则拒绝布尔', function (): void {
    $v = new Validator(['name' => 'string']);
    assert_false($v->validate(['name' => false]), '布尔不应通过 string');
    assert_same(['name' => ['string']], errs($v), '错误消息');
});

t('int 规则接受数字字符串并给出转换值', function (): void {
    $v = new Validator(['qty' => 'int']);
    assert_true($v->validate(['qty' => '42']), '数字字符串应通过 int');
    assert_same(42, $v->value('qty'), 'int 规则应把值转换成整数');
});

t('int 规则接受整数', function (): void {
    $v = new Validator(['qty' => 'int']);
    assert_true($v->validate(['qty' => 7]), '整数应通过 int');
    assert_same(7, $v->value('qty'), '值保持整数');
});

t('min 对 ASCII 字符串按长度判断', function (): void {
    $ok = new Validator(['name' => 'min:3']);
    assert_true($ok->validate(['name' => 'abc']), '长度 3 满足 min:3');
    assert_same([], errs($ok), '不应有错误');

    $bad = new Validator(['name' => 'min:3']);
    assert_false($bad->validate(['name' => 'ab']), '长度 2 不满足 min:3');
    assert_same(['name' => ['min:3']], errs($bad), '错误消息');
});

t('max 对 ASCII 字符串按长度判断', function (): void {
    $ok = new Validator(['name' => 'max:3']);
    assert_true($ok->validate(['name' => 'abc']), '长度 3 满足 max:3');

    $bad = new Validator(['name' => 'max:3']);
    assert_false($bad->validate(['name' => 'abcd']), '长度 4 超过 max:3');
    assert_same(['name' => ['max:3']], errs($bad), '错误消息');
});

t('regex 完整匹配通过', function (): void {
    $v = new Validator(['code' => 'regex:/^[a-z]+$/']);
    assert_true($v->validate(['code' => 'abc']), 'abc 匹配 ^[a-z]+$');
    assert_same([], errs($v), '不应有错误');
});

t('regex 不匹配报错', function (): void {
    $v = new Validator(['code' => 'regex:/^[a-z]+$/']);
    assert_false($v->validate(['code' => 'ab1']), 'ab1 不匹配 ^[a-z]+$');
    assert_same(['code' => ['regex:/^[a-z]+$/']], errs($v), '错误消息是规则原文');
});

t('多条路径按规则定义顺序报错', function (): void {
    $v = new Validator([
        'b' => 'required',
        'a' => 'required',
        'c' => 'required',
    ]);
    assert_false($v->validate(['a' => '', 'b' => '', 'c' => '']), '三个都该失败');
    assert_same(['b', 'a', 'c'], array_keys(errs($v)), '错误路径顺序按规则定义顺序');
});

t('全通过时 errors() 为空且 passes() 为真', function (): void {
    $v = new Validator(['name' => 'required', 'qty' => 'int']);
    assert_true($v->validate(['name' => 'Ada', 'qty' => '3']), '都满足');
    assert_false($v->fails(), 'fails() 应为 false');
    assert_true($v->passes(), 'passes() 应为 true');
    assert_same([], errs($v), '不该有错误');
});

t('重复校验会清空上一轮的错误', function (): void {
    $v = new Validator(['name' => 'required']);
    assert_false($v->validate(['name' => '']), '第一轮失败');
    assert_same(['name' => ['required']], errs($v), '第一轮的错误');
    assert_true($v->validate(['name' => 'Ada']), '第二轮通过');
    assert_same([], errs($v), '第二轮应清空上一轮错误');
});

t('未知规则抛 ValidkitException', function (): void {
    $v = new Validator(['name' => 'uuid']);
    try {
        $v->validate(['name' => 'x']);
    } catch (ValidkitException $exc) {
        assert_true(strpos($exc->getMessage(), 'uuid') !== false, '异常里带上未知规则名');

        return;
    }

    throw new TestFailure('未知规则应当抛 ValidkitException', 'ValidkitException', '未抛错');
});

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
foreach ($TESTS as [$name, $fn]) {
    try {
        $fn();
    } catch (TestFailure $exc) {
        $failed++;
        echo "FAIL {$name}  期望={$exc->expected} 实际={$exc->actual}（{$exc->getMessage()}）\n";
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        echo 'FAIL ' . $name . '  期望=正常返回 实际=' . get_class($exc) . ': ' . $exc->getMessage() . "\n";
        continue;
    }

    $passed++;
    echo "PASS {$name}\n";
}

$total = count($TESTS);
echo "结果：通过 {$passed}/{$total}\n";
exit($failed === 0 ? 0 : 1);
