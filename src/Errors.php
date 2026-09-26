<?php

namespace Validkit;

/**
 * 规则本身有问题（未知规则、无法使用的正则等）时抛出。
 */
final class ValidkitException extends \Exception
{
}

/**
 * 错误聚合器：内部就是 `[路径 => [消息…]]` 这张表。
 *
 * `add()` 只在路径第一次出现时登记键，因此 `all()` 的键顺序 = 首次出错顺序，
 * 也就是规则的定义顺序。
 */
final class Errors
{
    /** @var array<string, list<string>> */
    private array $messages = [];

    public function add(string $path, string $message): void
    {
        if (!array_key_exists($path, $this->messages)) {
            $this->messages[$path] = [];
        }

        $this->messages[$path][] = $message;
    }

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->messages);
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->messages);
    }

    public function isEmpty(): bool
    {
        return $this->messages === [];
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}
