<?php

namespace Validkit;

/**
 * 规则集合：把 `['路径' => '规则串|规则串']` 拆成有序条目，并保留**定义顺序**。
 *
 * 同一个路径上写多条规则时用 `|` 分隔，例如 `'required|string|min:2'`。
 */
final class RuleSet
{
    /** @var list<array{0: string, 1: Rule}> */
    private array $entries = [];

    public static function fromArray(array $rules): self
    {
        $set = new self();

        foreach ($rules as $path => $spec) {
            $path = (string) $path;
            foreach (explode('|', (string) $spec) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $set->entries[] = [$path, Rule::parse($token)];
            }
        }

        return $set;
    }

    /** @return list<array{0: string, 1: Rule}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * 按路径归组，保留路径首次出现的顺序。
     *
     * @return list<array{path: string, rules: list<Rule>}>
     */
    public function byPath(): array
    {
        $groups = [];
        foreach ($this->entries as [$path, $rule]) {
            if (!array_key_exists($path, $groups)) {
                $groups[$path] = ['path' => $path, 'rules' => []];
            }
            $groups[$path]['rules'][] = $rule;
        }

        return array_values($groups);
    }

    /** @return list<string> 规则里出现的路径（去重，保持首次出现顺序） */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->entries as [$path, $_rule]) {
            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
