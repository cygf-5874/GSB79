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
     * 同一路径上的失败全部收集（不短路），消息按规则的定义顺序排列。
     *
     * @param list<Rule> $rules
     * @param mixed $value
     */
    private function runRules(string $path, array $rules, $value, bool $found): void
    {
        if ($found) {
            $this->values[$path] = $value;
        }

        foreach ($rules as $rule) {
            if (!$this->apply($path, $rule, $value, $found)) {
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
            return self::hasValue($value, $found);
        }

        if (!$found) {
            return true;
        }

        switch ($name) {
            case 'string':
                return is_string($value) || is_int($value) || is_float($value);

            case 'int':
                $converted = self::strictInt($value);
                if ($converted === null) {
                    return false;
                }
                $value = $converted;
                $this->values[$path] = $value;

                return true;

            case 'min':
                $length = $this->measure($value);

                return $length !== null && $length >= $this->intParam($rule);

            case 'max':
                $length = $this->measure($value);

                return $length !== null && $length <= $this->intParam($rule);

            case 'regex':
                $params = $rule->params();
                if (!isset($params[0]) || $params[0] === '') {
                    throw new ValidkitException('regex 规则缺少 pattern');
                }

                if (!is_scalar($value)) {
                    return false;
                }

                return self::pregMatch(self::anchorPattern($params[0]), (string) $value);
        }

        throw new ValidkitException('未知规则：' . $name);
    }

    /**
     * required 的「有值」定义：键缺失、null、空串、空数组算无值；
     * `'0'`、`0`、`0.0`、`false` 都算有值。
     *
     * @param mixed $value
     */
    private static function hasValue($value, bool $found): bool
    {
        if (!$found || $value === null || $value === '' || $value === []) {
            return false;
        }

        return true;
    }

    /**
     * 严格整数转换：只接受整数本身，或整串都是十进制数字（可带一个前导 `-`）的字符串。
     * 不采用 (int) 的宽松语义，也不靠 is_numeric；其余一律返回 null（规则失败）。
     *
     * @param mixed $value
     */
    private static function strictInt($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * 值的「长度」：数字按数值，字符串按 UTF-8 字符数；
     * 数组/对象这类没有单值长度概念的输入返回 null（规则失败，不做会告警的强转）。
     *
     * @param mixed $value
     * @return int|float|null
     */
    private function measure($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->utf8Length($value);
        }

        if ($value === null || is_bool($value)) {
            return $this->utf8Length((string) $value);
        }

        return null;
    }

    /**
     * 统计 UTF-8 字符数：UTF-8 里只有字符首字节不以 10xxxxxx 开头，
     * 因此「非续接字节」数就是字符数。无需 mbstring，遇到非法字节也不报错。
     */
    private function utf8Length(string $value): int
    {
        $count = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ((ord($value[$i]) & 0xC0) !== 0x80) {
                $count++;
            }
        }

        return $count;
    }

    /** 取出规则的整数参数；缺参或非整数都属于规则本身有问题。 */
    private function intParam(Rule $rule): int
    {
        $params = $rule->params();
        if (!isset($params[0]) || preg_match('/\A-?[0-9]+\z/', $params[0]) !== 1) {
            throw new ValidkitException('规则 ' . $rule->token() . ' 需要一个整数参数');
        }

        return (int) $params[0];
    }

    /**
     * 给带定界符的完整 PCRE 自动加首尾锚点：等价于 `\A … \z`。
     * 同时校验定界符与修饰符形状，非法 pattern 直接抛 ValidkitException
     * （而不是让 preg_match 泄漏 warning）。
     */
    private static function anchorPattern(string $pattern): string
    {
        if (strlen($pattern) < 2) {
            throw new ValidkitException('非法正则（缺少定界符）：' . $pattern);
        }

        $delimiter = $pattern[0];
        if (ctype_alnum($delimiter) || $delimiter === '\\') {
            throw new ValidkitException('非法正则定界符：' . $delimiter);
        }

        $pairs = [
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
        ];

        if (isset($pairs[$delimiter])) {
            $close = $pairs[$delimiter];
            $end = strrpos($pattern, $close);
            if ($end === false || $end < 1) {
                throw new ValidkitException('非法正则（定界符不配对）：' . $pattern);
            }
        } else {
            $end = strrpos($pattern, $delimiter);
            if ($end === false || $end < 1) {
                throw new ValidkitException('非法正则（缺少结束定界符）：' . $pattern);
            }
        }

        $modifiers = substr($pattern, $end + 1);
        if ($modifiers !== '' && preg_match('/\A[A-Za-z]+\z/', $modifiers) !== 1) {
            throw new ValidkitException('非法正则修饰符：' . $modifiers);
        }

        return $pattern[0] . '\A'
            . substr($pattern, 1, $end - 1) . '\z'
            . $pattern[$end]
            . substr($pattern, $end + 1);
    }

    /**
     * preg_match 的包装：把「pattern 本身非法」转成 ValidkitException，
     * 不用 @ 抑制，也绝不向错误处理器泄漏 warning。
     */
    private static function pregMatch(string $pattern, string $subject): bool
    {
        $failure = null;

        set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
            $failure = $message;

            return true;
        });

        try {
            $matched = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        if ($failure !== null) {
            throw new ValidkitException('非法正则：' . $failure);
        }

        return $matched === 1;
    }
}
