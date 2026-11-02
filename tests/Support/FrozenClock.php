<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Tests\Support;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now = new \DateTimeImmutable('2026-01-01 00:00:00'))
    {
    }

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->move("+{$seconds} seconds");
    }

    public function advanceMicroseconds(int $microseconds): void
    {
        $this->move("+{$microseconds} microseconds");
    }

    private function move(string $interval): void
    {
        $this->now = $this->now->setTimezone(new \DateTimeZone('UTC'))
            ->modify($interval)
            ->setTimezone($this->now->getTimezone());
    }
}
