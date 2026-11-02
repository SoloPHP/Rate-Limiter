<?php

declare(strict_types=1);

use Solo\RateLimiter\RedisRateLimiter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
[, $host, $port, $prefix, $startAt] = $arguments;

$redis = new Redis();
$redis->connect($host, (int) $port);
if (microtime(true) < (float) $startAt) {
    time_sleep_until((float) $startAt);
}

echo (new RedisRateLimiter($redis, $prefix))->take('race', 5, 60)->accepted ? '1' : '0';
