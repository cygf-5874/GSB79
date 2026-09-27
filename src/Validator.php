<?php

namespace Validkit;

/**
 * 表单校验器：按「规则路径 => 规则串」的定义顺序逐条校验一份数据。
 *
 * 用法：
 *
 *   $v = new Validator(['user.name' => 'required|string|min:2']);
 *   if (!$v->validate($data)) {
 *       print_r($v->errors());
 *   }
 *
 * 规则的语义与边界以 README「对外保证」一节为准。
 */
final class Validator
{
    private RuleSet $rules;

    private Errors $errors;

    /** @var array<string, mixed> 具体路径 => 最近一次校验时该路径上的值 */
    private array $values = [];

    private bool $validated = false;

    public function __construct(array $rules)
    {
        $this->rules = RuleSet::fromArray($rules);
        $this->errors = new Errors();
    }

    /**
     * 校验整份数据：通过返回 true，否则 false；错误随后用 errors() 取。
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): bool
    {
        $this->errors->clear();
        $this->values = [];
        $this->validated = true;

        foreach ($this->rules->byPath() as $group) {
            foreach (Path::expand($group['path'], $data) as $path) {
                $found = false;
                $value = Path::get($data, $path, $found);
                $this->runRules($path, $group['rules'], $value, $found);
            }
        }

        return $this->errors->isEmpty();
    }

    public function passes(): bool
    {
        return $this->validated && $this->errors->isEmpty();
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors->all();
    }

    /**
     * 某个具体路径上的值：类型规则（如 int）转换后的结果，未校验过则为 null。
     *
     * @return mixed
     */
    public function value(string $path)
    {
        return array_key_exists($path, $this->values) ? $this->values[$path] : null;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->rules->paths();
    }

    // -----------------------------------------------------------------------

    /**
     * 对某个具体路径依次跑它名下的规则。
     *
     * @param list<Rule> $rules
     * @param mixed $value
     */
    private function runRules(string $path, array $rules, $value, bool $found): void
    {
        $current = $value;
        if ($found) {
            $this->values[$path] = $current;
        }

        foreach ($rules as $rule) {
            if (!$this->apply($path, $rule, $current, $found)) {
                $this->errors->add($path, $rule->token());
            }
        }
    }

    /**
     * 跑一条规则。
     *
     * @param mixed $value 按引用传入，类型规则可以就地转换
     */
    private function apply(string $path, Rule $rule, &$value, bool $found): bool
    {
        $name = $rule->name();

        if ($name === 'required') {
            return $found && $value !== '' && $value !== null && $value !== [];
        }

        if (!$found) {
            return true;
        }

        switch ($name) {
            case 'string':
                return is_string($value) || is_int($value) || is_float($value);

            case 'int':
                if (is_int($value)) {
                    return true;
                }
                if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
                    $value = (int) $value;
                    $this->values[$path] = $value;

                    return true;
                }

                return false;

            case 'min':
                return $this->measure($value) >= $this->limit($rule);

            case 'max':
                return $this->measure($value) <= $this->limit($rule);

            case 'regex':
                return $this->regexMatches($rule, $value);
        }

        throw new ValidkitException('未知规则：' . $name);
    }

    /**
     * min / max 的数值参数；缺失或不是数字属于规则本身写错了。
     *
     * @return int|float
     */
    private function limit(Rule $rule)
    {
        $params = $rule->params();
        if ($params === [] || !is_numeric($params[0])) {
            throw new ValidkitException('规则缺少数值参数：' . $rule->token());
        }

        return $params[0] + 0;
    }

    /**
     * 正则规则：pattern 是带定界符的完整 PCRE，匹配时自动加 \A … \z 锚点。
     * 模式本身不可用（缺定界符、语法错误、非法修饰符）时抛 ValidkitException。
     *
     * @param mixed $value
     */
    private function regexMatches(Rule $rule, $value): bool
    {
        $params = $rule->params();
        if ($params === [] || $params[0] === '') {
            throw new ValidkitException('regex 规则缺少模式：' . $rule->token());
        }

        if (!is_scalar($value) && $value !== null) {
            return false;
        }

        $pattern = self::anchor($params[0]);

        $invalid = false;
        set_error_handler(static function () use (&$invalid): bool {
            $invalid = true;

            return true;
        });
        try {
            $result = preg_match($pattern, (string) $value);
        } finally {
            restore_error_handler();
        }

        if ($invalid || $result === false) {
            throw new ValidkitException('无法使用的正则：' . $params[0]);
        }

        return $result === 1;
    }

    /**
     * 给带定界符的 pattern 包上整串锚点：`\A(?: … )\z`，修饰符原样保留。
     */
    private static function anchor(string $pattern): string
    {
        $delimiter = $pattern[0];
        if (ctype_alnum($delimiter) || ctype_space($delimiter) || $delimiter === '\\') {
            throw new ValidkitException('正则缺少定界符：' . $pattern);
        }

        $pairs = ['(' => ')', '{' => '}', '[' => ']', '<' => '>'];
        $closer = $pairs[$delimiter] ?? $delimiter;

        $end = strrpos($pattern, $closer);
        if ($end === false || $end === 0) {
            throw new ValidkitException('正则缺少结束定界符：' . $pattern);
        }

        $body = substr($pattern, 1, $end - 1);
        $modifiers = substr($pattern, $end + 1);

        return $delimiter . '\A(?:' . $body . ')' . '\z' . $closer . $modifiers;
    }

    /**
     * 值的「长度」：数字按数值，字符串按 UTF-8 字符数，数组按元素个数。
     *
     * @param mixed $value
     * @return int|float
     */
    private function measure($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return self::utf8Length($value);
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_bool($value) || $value === null) {
            return strlen((string) $value);
        }

        throw new ValidkitException('无法测量的值类型：' . gettype($value));
    }

    /**
     * UTF-8 字符数（不依赖 mbstring）；非法 UTF-8 退化为字节数。
     */
    private static function utf8Length(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);

        return $count === false ? strlen($value) : $count;
    }
}
