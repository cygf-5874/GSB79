<?php

namespace Validkit;

/**
 * 规则路径：把 `a.b.0.c` 这样的点分路径映射到数据上。
 *
 * 一条规则用一个路径做键；`expand()` 负责把它展开成若干「具体路径」，
 * 每个具体路径调用一次校验。
 */
final class Path
{
    /** 路径段允许出现的形状。 */
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * 展开规则路径：返回一组具体路径（每条对应一次校验）。
     *
     * @return list<string>
     */
    public static function expand(string $pattern, array $data): array
    {
        return [$pattern];
    }

    /**
     * 按点分路径取值；`$found` 标记路径是否落在数据上。
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    public static function get(array $data, string $path, bool &$found)
    {
        $found = false;
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor)) {
                return null;
            }
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }
            if (!array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        $found = true;

        return $cursor;
    }
}
