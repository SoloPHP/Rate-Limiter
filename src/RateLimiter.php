<?php

declare(strict_types=1);

namespace Solo\RateLimiter;

use Solo\RateLimiter\Exception\RateLimiterException;

/**
 * A budget of hits per key within a time window. Each call changes one key's window as a single step.
 */
interface RateLimiter
{
    /**
     * Take one hit from the key's budget. A refused take changes nothing: neither the count nor the end
     * of the window.
     *
     * The window opens with its first hit and closes `$windowSeconds` later. With `$renew`, every accepted
     * hit moves the close to `$windowSeconds` from now. The count resets when the entire window expires.
     * A new limit applies to the existing count. A new duration only applies when opening or extending a window.
     *
     * @throws \InvalidArgumentException when the limit or window is outside 1..2_147_483_647
     * @throws RateLimiterException when the store fails
     */
    public function take(string $key, int $limit, int $windowSeconds, bool $renew = false): RateLimitResult;

    /**
     * Return one hit to the key's open window. The count never goes below zero, and a closed window
     * stays closed. Duplicate refunds and refunds after expiry or clear can release another request's hit.
     *
     * @throws RateLimiterException when the store fails
     */
    public function giveBack(string $key): void;

    /**
     * Close the key's window, so the next take opens a new one.
     *
     * @throws RateLimiterException when the store fails
     */
    public function clear(string $key): void;
}
