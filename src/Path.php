<?php

namespace Validkit;

/**
 * 规则路径：把 `a.b.0.c` 这样的点分路径映射到数据上。
 *
 * 一条规则用一个路径做键；`expand()` 负责把它展开成若干「具体路径」，
 * 每个具体路径调用一次校验。路径段是标识符（`[A-Za-z_][A-Za-z0-9_]*`）、
 * 十进制数字下标，或通配符 `*`；遇到通配符时对当前这一层数组的每一项各展开一次。
 */
final class Path
{
    /**
     * 展开规则路径：返回一组具体路径（每条对应一次校验）。
     *
     * 没有通配符时返回它自己（即使路径在数据上缺失，required 也要报错）；
     * 通配符所在层不是数组（含缺失）时，该支没有可展开的具体路径；
     * 路径形状本身不合法时抛 ValidkitException。
     *
     * @return list<string>
     */
    public static function expand(string $pattern, array $data): array
    {
        $segments = explode('.', $pattern);
        if (!in_array('*', $segments, true)) {
            foreach ($segments as $segment) {
                if ($segment === '*') {
                    continue;
                }
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1
                    && preg_match('/^[0-9]+$/', $segment) !== 1) {
                    throw new ValidkitException('非法路径段：' . $segment);
                }
            }

            return [$pattern];
        }

        foreach ($segments as $segment) {
            if ($segment === '*') {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) === 1
                || preg_match('/^[0-9]+$/', $segment) === 1) {
                continue;
            }

            throw new ValidkitException('非法路径段：' . $segment);
        }

        return self::walk($segments, 0, $data, []);
    }

    /**
     * 从第 $index 段开始向下展开。
     *
     * @param list<string> $segments
     * @param list<string> $prefix 已经走过的具体段（不含 `*`）
     * @return list<string>
     */
    private static function walk(array $segments, int $index, $cursor, array $prefix): array
    {
        if ($index === count($segments)) {
            return [implode('.', $prefix)];
        }

        $segment = $segments[$index];

        if ($segment !== '*') {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return [];
            }

            return self::walk($segments, $index + 1, $cursor[$segment], array_merge($prefix, [$segment]));
        }

        if (!is_array($cursor)) {
            return [];
        }

        $paths = [];
        foreach ($cursor as $key => $child) {
            $part = is_int($key) ? (string) $key : $key;
            foreach (self::walk($segments, $index + 1, $child, array_merge($prefix, [$part])) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * 按点分路径取值；`$found` 标记路径是否落在数据上。
     *
     * 中间路径不是数组或键缺失都算没找到（不产生任何通知/警告）。
     *
     * @param array<string, mixed> $data
     * @return mixed
     */
    public static function get(array $data, string $path, bool &$found)
    {
        $found = false;
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        $found = true;

        return $cursor;
    }
}
