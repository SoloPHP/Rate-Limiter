<?php

declare(strict_types=1);

namespace Solo\RateLimiter;

use Solo\RateLimiter\Exception\RateLimiterException;
use Solo\RateLimiter\Internal\Arguments;
use Solo\RateLimiter\Internal\RedisScripts;

/**
 * Windows in Redis through phpredis. Each operation is atomic, so concurrent processes never take
 * the same last hit. A key's window lives at `$prefix . $key` and expires with the window.
 */
final readonly class RedisRateLimiter implements RateLimiter
{
    public function __construct(private \Redis $redis, private string $prefix)
    {
    }

    #[\Override]
    public function take(string $key, int $limit, int $windowSeconds, bool $renew = false): RateLimitResult
    {
        Arguments::validate($limit, $windowSeconds);

        $reply = $this->evaluate(RedisScripts::TAKE, $key, [$limit, $windowSeconds, $renew ? 1 : 0]);
        if (
            !array_is_list($reply)
            || count($reply) !== 3
            || ($reply[0] !== 0 && $reply[0] !== 1)
            || !is_int($reply[1]) || $reply[1] < 1
            || !is_int($reply[2]) || $reply[2] < 1
        ) {
            throw new RateLimiterException("Unexpected reply from the rate limit script for key \"{$key}\"");
        }

        return new RateLimitResult($reply[0] === 1, $reply[1], $reply[2]);
    }

    #[\Override]
    public function giveBack(string $key): void
    {
        if ($this->evaluate(RedisScripts::GIVE_BACK, $key, []) !== []) {
            throw new RateLimiterException("Unexpected reply from the refund script for key \"{$key}\"");
        }
    }

    #[\Override]
    public function clear(string $key): void
    {
        if ($this->evaluate(RedisScripts::CLEAR, $key, []) !== []) {
            throw new RateLimiterException("Unexpected reply from the clear script for key \"{$key}\"");
        }
    }

    /**
     * @param list<int> $args
     * @return array<mixed>
     */
    private function evaluate(string $script, string $key, array $args): array
    {
        try {
            $this->assertAtomic();
            $reply = $this->redis->eval($script, [$this->prefix . $key, ...$args], 1);
        } catch (\RedisException $e) {
            throw new RateLimiterException(
                "Redis failed to run the rate limit script for key \"{$key}\": {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (!is_array($reply)) {
            $error = $this->redis->getLastError() ?? 'no error reported';
            throw new RateLimiterException("The rate limit script failed for key \"{$key}\": {$error}");
        }

        return $reply;
    }

    private function assertAtomic(): void
    {
        if ($this->redis->getMode() !== \Redis::ATOMIC) {
            throw new RateLimiterException('The Redis connection must not be in a transaction or pipeline');
        }
    }
}
