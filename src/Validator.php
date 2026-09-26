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
                break;
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
            return !empty($value);
        }

        if (!$found) {
            return true;
        }

        switch ($name) {
            case 'string':
                return is_string($value) || is_int($value) || is_float($value);

            case 'int':
                $value = (int) $value;
                $this->values[$path] = $value;

                return true;

            case 'min':
                return $this->measure($value) >= (int) $rule->params()[0];

            case 'max':
                return $this->measure($value) <= (int) $rule->params()[0];

            case 'regex':
                return preg_match($rule->params()[0], (string) $value) === 1;
        }

        throw new ValidkitException('未知规则：' . $name);
    }

    /**
     * 值的「长度」：数字按数值，字符串按长度。
     *
     * @param mixed $value
     * @return int|float
     */
    private function measure($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return strlen((string) $value);
    }
}
