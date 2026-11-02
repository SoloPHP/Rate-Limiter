<?php

declare(strict_types=1);

namespace Solo\RateLimiter;

final readonly class RateLimitResult
{
    /**
     * @param bool $accepted whether the hit was taken
     * @param int  $used     hits counted in the window, this one included when it was taken
     * @param int  $resetIn  seconds until the window closes, rounded up (at least 1 for a live window)
     */
    public function __construct(
        public bool $accepted,
        public int $used,
        public int $resetIn,
    ) {
    }
}
