<?php

declare(strict_types=1);

/**
 * 投诉里那一条的最小复现。
 *
 * 用法：php repro.php
 *
 * 场景：数量这个字段用户填的是字符串 "0"（下拉框里选 0 件 / 库存为 0 是合法值），
 * 规则是 required|int。按 README「对外保证」第 2 条，`'0'` 算「有值」。
 * 这里只跑这一段最小输入，用来核对该路径上的实际输出。
 */

require __DIR__ . '/src/Errors.php';
require __DIR__ . '/src/Rule.php';
require __DIR__ . '/src/RuleSet.php';
require __DIR__ . '/src/Path.php';
require __DIR__ . '/src/Validator.php';

use Validkit\Validator;

$validator = new Validator(['quantity' => 'required|int']);

$ok = $validator->validate(['quantity' => '0']);

echo '数据：' . var_export(['quantity' => '0'], true) . "\n";
echo '结论：' . ($ok ? '通过' : '未通过') . "\n";
echo 'errors()：' . var_export($validator->errors(), true) . "\n";
echo 'value(quantity)：' . var_export($validator->value('quantity'), true) . "\n";
