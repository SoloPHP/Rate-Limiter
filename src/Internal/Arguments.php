<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Internal;

/** @internal */
final class Arguments
{
    // Keep both backends within the same portable, exactly representable integer range.
    private const int MAX_VALUE = 2_147_483_647;

    public static function validate(int $limit, int $windowSeconds): void
    {
        if ($limit < 1 || $limit > self::MAX_VALUE) {
            throw new \InvalidArgumentException(
                'The limit must be between 1 and ' . self::MAX_VALUE . ", {$limit} given",
            );
        }

        if ($windowSeconds < 1 || $windowSeconds > self::MAX_VALUE) {
            throw new \InvalidArgumentException(
                'The window must be between 1 and ' . self::MAX_VALUE . " seconds, {$windowSeconds} given",
            );
        }
    }
}
