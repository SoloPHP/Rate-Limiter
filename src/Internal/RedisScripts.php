<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Internal;

/** @internal */
final class RedisScripts
{
    private const string READ_WINDOW = <<<'LUA'
        local stored = redis.call('GET', KEYS[1]) or '0'
        local used = tonumber(stored)
        if not string.match(stored, '^%d+$') or used > 2147483647 or tostring(used) ~= stored then
            return redis.error_reply('Invalid rate limit counter')
        end
        local ttl = redis.call('PTTL', KEYS[1])

        LUA;

    public const string TAKE = self::READ_WINDOW . <<<'LUA'
        if used >= tonumber(ARGV[1]) then
            if ttl < 0 then
                return redis.error_reply('Rate limit window has no expiry')
            end
            return {0, used, math.max(1, math.ceil(ttl / 1000))}
        end

        used = used + 1
        if ttl < 0 or ARGV[3] == '1' then
            redis.call('SET', KEYS[1], used, 'EX', ARGV[2])
            ttl = tonumber(ARGV[2]) * 1000
        else
            redis.call('INCR', KEYS[1])
        end
        return {1, used, math.max(1, math.ceil(ttl / 1000))}
        LUA;

    public const string GIVE_BACK = self::READ_WINDOW . <<<'LUA'
        if ttl == -1 then
            return redis.error_reply('Rate limit window has no expiry')
        end
        if used > 0 then
            redis.call('DECR', KEYS[1])
        end
        return {}
        LUA;

    public const string CLEAR = <<<'LUA'
        redis.call('DEL', KEYS[1])
        return {}
        LUA;
}
