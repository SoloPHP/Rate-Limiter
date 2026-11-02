<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Internal;

use Psr\Clock\ClockInterface;

/** @internal */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
