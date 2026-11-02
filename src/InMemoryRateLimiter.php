<?php

declare(strict_types=1);

namespace Solo\RateLimiter;

use Psr\Clock\ClockInterface;
use Solo\RateLimiter\Internal\Arguments;
use Solo\RateLimiter\Internal\SystemClock;

/**
 * Windows in this object's memory, for tests and single-process tools. The contract is the one
 * RedisRateLimiter keeps; the clock decides when a window closes.
 */
final class InMemoryRateLimiter implements RateLimiter
{
    private const int MIN_PURGE_INTERVAL = 100;

    /** @var array<string, array{used: int, closesAt: \DateTimeImmutable}> */
    private array $windows = [];

    private int $operationsUntilPurge = self::MIN_PURGE_INTERVAL;

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    #[\Override]
    public function take(string $key, int $limit, int $windowSeconds, bool $renew = false): RateLimitResult
    {
        Arguments::validate($limit, $windowSeconds);

        $now = $this->clock->now();
        $window = $this->open($key, $now);

        if ($window !== null && $window['used'] >= $limit) {
            return new RateLimitResult(false, $window['used'], $this->resetIn($window['closesAt'], $now));
        }

        $used = $window === null ? 1 : $window['used'] + 1;
        $closesAt = $window === null || $renew
            ? $now->add(new \DateInterval("PT{$windowSeconds}S"))
            : $window['closesAt'];
        $this->windows[$key] = ['used' => $used, 'closesAt' => $closesAt];

        return new RateLimitResult(true, $used, $this->resetIn($closesAt, $now));
    }

    #[\Override]
    public function giveBack(string $key): void
    {
        $window = $this->open($key, $this->clock->now());

        if ($window !== null && $window['used'] > 0) {
            $this->windows[$key]['used'] = $window['used'] - 1;
        }
    }

    #[\Override]
    public function clear(string $key): void
    {
        unset($this->windows[$key]);
    }

    /**
     * Remove expired windows and return the number removed. take() and giveBack() also sweep periodically.
     */
    public function purgeExpired(): int
    {
        return $this->removeExpired($this->clock->now());
    }

    /** @return array{used: int, closesAt: \DateTimeImmutable}|null */
    private function open(string $key, \DateTimeImmutable $now): ?array
    {
        if (--$this->operationsUntilPurge === 0) {
            $this->removeExpired($now);
        }

        $window = $this->windows[$key] ?? null;

        if ($window !== null && $window['closesAt'] <= $now) {
            unset($this->windows[$key]);

            return null;
        }

        return $window;
    }

    private function removeExpired(\DateTimeImmutable $now): int
    {
        $removed = 0;
        foreach ($this->windows as $key => $window) {
            if ($window['closesAt'] <= $now) {
                unset($this->windows[$key]);
                $removed++;
            }
        }

        // Space full scans by the number of live windows so the amortized cost per call stays constant.
        $this->operationsUntilPurge = max(self::MIN_PURGE_INTERVAL, count($this->windows));

        return $removed;
    }

    private function resetIn(\DateTimeImmutable $closesAt, \DateTimeImmutable $now): int
    {
        // On 32-bit PHP the expiry can be past 2038 even when the remaining duration fits in an int.
        $seconds = (int) ($closesAt->format('U') - $now->format('U'));

        return $seconds + ($closesAt->format('u') > $now->format('u') ? 1 : 0);
    }
}
