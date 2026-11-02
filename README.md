# Solo Rate Limiter

Atomic per-key rate limits for PHP: fixed or renewable windows in Redis, with an in-memory twin for tests.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/rate-limiter.svg)](https://packagist.org/packages/solophp/rate-limiter)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Atomic on Redis** — the check and the count are one Lua script, so when one hit is left, only one of the requests arriving at once gets it. A read followed by a separate write would let every request that read the same count through.
- **A refusal changes nothing** — a refused take neither counts nor moves the window, so hammering a locked key does not extend its lock.
- **Fixed or renewable windows** — a window closes a set time after its first hit or, with `renew: true`, a set time after the last hit taken, which suits a lockout that lasts from the last failure.
- **Give back** — take the hit before expensive work and return it when the outcome turns out free, so a request still running holds its place.
- **Ready for `Retry-After`** — `resetIn` is rounded up and is at least `1` while the window is open.
- **Failures are loud** — Redis errors, damaged counters and unexpected replies raise `RateLimiterException`, and the caller decides whether requests go through while the store is down.
- **In-memory twin** — `InMemoryRateLimiter` passes the same contract tests as the Redis limiter and takes a PSR-20 clock, so tests can move time.

## Installation

```sh
composer require solophp/rate-limiter
```

Requires PHP 8.3+. For Redis, enable the `redis` PHP extension and pass a connected `Redis` client in its normal, immediate execution mode. The integration suite uses Redis 7.4.

## Limit an API request

```php
use Solo\RateLimiter\RedisRateLimiter;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$limiter = new RedisRateLimiter($redis, 'app:limit:');

$result = $limiter->take('api:' . $clientIp, limit: 100, windowSeconds: 60);

if (!$result->accepted) {
    http_response_code(429);
    header('Retry-After: ' . $result->resetIn);
    return;
}

$remaining = 100 - $result->used;
```

`take()` checks the count and records an accepted hit in one atomic operation. When one hit remains, only one concurrent caller can take it. `used` includes the accepted hit. `resetIn` gives the seconds until expiry, rounded up, so a live window with 200 ms left reports `1`.

## Choose when the window expires

| Mode | When hits arrive at 0 s and 20 s in a 60 s window |
|---|---|
| Fixed (default) | Both hits expire at 60 s, measured from the first hit. |
| Renewable (`renew: true`) | Both hits expire at 80 s, measured from the last accepted hit. |

Each window holds one count. All hits expire together. Refused hits leave the count and expiry alone in both modes. For example, a login lockout can count an attempt before checking the password and clear the window after a successful login. Key it by the submitted address rather than an account ID, so an address nobody owns is locked out at the same count as a real one. For the same reason, check an unknown address against a dummy hash made with the same cost as real ones, so its refusal takes as long as a wrong password.

```php
$key = 'login:' . hash('sha256', mb_strtolower(trim($email)));
$attempt = $limiter->take($key, limit: 10, windowSeconds: 900, renew: true);
if (!$attempt->accepted) {
    throw new TooManyAttempts(retryAfter: $attempt->resetIn);
}
$passwordOk = password_verify($password, $account?->passwordHash ?? $dummyHash);
if ($account === null || !$passwordOk) {
    throw new InvalidCredentials();
}
$limiter->clear($key);
```

## Charge only the expensive outcome

Take the hit before the work, so requests running at the same time cannot all pass the check, and give it back when the outcome turns out free. A password-reset endpoint, for example, can spend its budget on emails sent rather than on forms rejected for a typo.

```php
if (!$limiter->take($key, limit: 10, windowSeconds: 3600)->accepted) {
    throw new TooManyAttempts();
}

try {
    $response = $handler->handle($request);
} catch (Throwable $e) {
    $limiter->giveBack($key);
    throw $e;
}

if ($response->getStatusCode() >= 400) {
    $limiter->giveBack($key);
}
```

Give back at most once per take, while its window is still open. The `giveBack()` notes below explain why.

## API

| Method | Behavior |
|---|---|
| `take(string $key, int $limit, int $windowSeconds, bool $renew = false): RateLimitResult` | Compares the current count to the supplied limit. The duration applies when a window opens or an accepted hit renews it. |
| `giveBack(string $key): void` | Decrements the count in the currently open window, down to zero. Keeps its original expiry. A missing or expired key is ignored. |
| `clear(string $key): void` | Deletes the current window. The next accepted hit opens a fresh one. |

`$limit` and `$windowSeconds` each accept integers from `1` to `2_147_483_647`. Both implementations throw `InvalidArgumentException` for values outside this range, before changing the window.

`giveBack()` identifies a window by key. Use it once for a hit while its original window is still active. If a slow job calls it after the key expires or is cleared and reused, it can refund another request's hit. Repeated refunds have the same risk.

## Storage and failures

`RedisRateLimiter` stores one expiring integer counter at `$prefix . $key`. Use a dedicated prefix for these counters. `take()`, `giveBack()` and `clear()` each run one Lua script. Use a connection outside `MULTI` and pipelines so each operation gets an immediate reply.

A Redis command failure, malformed counter or unexpected reply raises `Solo\RateLimiter\Exception\RateLimiterException`. If phpredis throws `RedisException`, it is available via `getPrevious()`. The caller decides how its endpoint handles a store outage.

`InMemoryRateLimiter` keeps state per instance and periodically releases expired keys. A long-running worker can call `purgeExpired()` to release them immediately and get the number removed. Inject a PSR-20 clock to control expiry in tests.

```php
use Solo\RateLimiter\InMemoryRateLimiter;

$limiter = new InMemoryRateLimiter($clock); // Omit the clock to use system time.
```

## Development

Run the full suite, including Redis and the concurrent-process test, with Docker Compose.

```sh
docker compose -f compose.test.yaml --profile test run --build --rm tests
docker compose -f compose.test.yaml --profile test down --volumes
```

With a local PHP installation, `composer test` validates the package, checks PSR-12, runs PHPStan at maximum level and runs the unit suite. Redis adapter unit tests require the `redis` extension. To run the integration suite, point it at a disposable Redis server.

```sh
composer install
composer test
REDIS_TEST_HOST=127.0.0.1 REDIS_TEST_PORT=6379 composer test-integration
```

## License

MIT. See [LICENSE](LICENSE) and the [changelog](CHANGELOG.md).
