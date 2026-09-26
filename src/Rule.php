<?php

namespace Validkit;

/**
 * 一条规则：名字 + 参数 + 原文。
 *
 * 原文（`token()`）就是这条规则的定义串本身，例如 `required`、`min:5`、
 * `regex:/[0-9]+/`；它同时被用作对外暴露的错误消息。
 */
final class Rule
{
    private string $name;

    /** @var list<string> */
    private array $params;

    private string $token;

    /**
     * @param list<string> $params
     */
    private function __construct(string $name, array $params, string $token)
    {
        $this->name = $name;
        $this->params = $params;
        $this->token = $token;
    }

    public static function parse(string $token): self
    {
        $token = trim($token);
        if ($token === '') {
            throw new ValidkitException('规则串不能为空');
        }

        $colon = strpos($token, ':');
        if ($colon === false) {
            return new self($token, [], $token);
        }

        $name = substr($token, 0, $colon);
        $argument = substr($token, $colon + 1);

        // regex 的参数是整段正则，里面的逗号属于 pattern，不能按逗号切。
        $params = $name === 'regex' ? [$argument] : explode(',', $argument);

        return new self($name, $params, $token);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function params(): array
    {
        return $this->params;
    }

    public function token(): string
    {
        return $this->token;
    }

    /** 是否是「存在性」规则（决定路径缺失时是否放行）。 */
    public function isPresence(): bool
    {
        return $this->name === 'required';
    }
}
