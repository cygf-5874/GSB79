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
    /**
     * 展开规则路径：返回一组具体路径（每条对应一次校验）。
     *
     * 不带 `*` 的路径原样返回（即使数据里没有，也要留给 required 判缺失）；
     * 带 `*` 的段对当前这一层数组的每一项各展开一次，具体路径用实际下标。
     * `*` 落在非数组（或缺失）的位置上时无法展开，该分支不产生路径。
     *
     * @return list<string>
     */
    public static function expand(string $pattern, array $data): array
    {
        $paths = [];
        self::walk(explode('.', $pattern), 0, $data, '', $paths);

        return $paths;
    }

    /**
     * @param list<string> $segments
     * @param mixed $cursor
     * @param list<string> $paths
     */
    private static function walk(array $segments, int $index, $cursor, string $built, array &$paths): void
    {
        if ($index >= count($segments)) {
            $paths[] = $built;

            return;
        }

        $segment = $segments[$index];

        if ($segment === '*') {
            if (!is_array($cursor)) {
                return;
            }
            foreach ($cursor as $key => $value) {
                self::walk($segments, $index + 1, $value, self::join($built, (string) $key), $paths);
            }

            return;
        }

        $next = is_array($cursor) && array_key_exists($segment, $cursor) ? $cursor[$segment] : null;
        self::walk($segments, $index + 1, $next, self::join($built, $segment), $paths);
    }

    private static function join(string $built, string $segment): string
    {
        return $built === '' ? $segment : $built . '.' . $segment;
    }

    /**
     * 按点分路径取值；`$found` 标记路径是否落在数据上。
     *
     * 数字段就是数组下标（`a.b.0.c` 里的 `0`）；中间任何一段不是数组或键缺失，
     * 都记为「未找到」并返回 null，不抛错、不产生通知。
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
            if (!array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        $found = true;

        return $cursor;
    }
}
