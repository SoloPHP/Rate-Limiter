<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Solo\RateLimiter\Exception\RateLimiterException;
use Solo\RateLimiter\RateLimiter;
use Solo\RateLimiter\RedisRateLimiter;
use Solo\RateLimiter\Tests\RateLimiterContract;

final class RedisRateLimiterTest extends RateLimiterContract
{
    private string $host;
    private int $port;
    private string $prefix;
    private \Redis $redis;

    protected function setUp(): void
    {
        $host = getenv('REDIS_TEST_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('Set REDIS_TEST_HOST and REDIS_TEST_PORT to run the Redis tests');
        }
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded');
        }

        $this->host = $host;
        $this->port = (int) (getenv('REDIS_TEST_PORT') ?: 6379);
        $this->prefix = 'solo-rate-limiter-test:' . bin2hex(random_bytes(6)) . ':';
        $this->redis = new \Redis();
        $this->redis->connect($this->host, $this->port);
    }

    protected function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
    }

    protected function limiter(): RateLimiter
    {
        return new RedisRateLimiter($this->redis, $this->prefix);
    }

    protected function elapse(string $key, int $seconds): void
    {
        $left = $this->redis->ttl($this->prefix . $key) - $seconds;
        if ($left > 0) {
            $this->redis->expire($this->prefix . $key, $left);
        } else {
            $this->redis->del($this->prefix . $key);
        }
    }

    public function testAWindowLivesUnderThePrefix(): void
    {
        $this->limiter()->take('k', 1, 60);

        $this->assertSame('1', $this->redis->get($this->prefix . 'k'));
        $this->assertSame(60, $this->redis->ttl($this->prefix . 'k'));
    }

    public function testAWindowWithoutAnExpiryGetsOne(): void
    {
        $this->redis->set($this->prefix . 'k', '3');

        $this->assertSame(60, $this->limiter()->take('k', 5, 60)->resetIn);
    }

    public function testADamagedWindowRaisesTheLimiterException(): void
    {
        $this->redis->set($this->prefix . 'k', 'not a number');

        $this->expectException(RateLimiterException::class);

        $this->limiter()->take('k', 1, 60);
    }

    public function testAFullWindowWithoutAnExpiryRaisesTheLimiterException(): void
    {
        $this->redis->set($this->prefix . 'k', '3');

        $this->expectException(RateLimiterException::class);

        try {
            $this->limiter()->take('k', 3, 60);
        } finally {
            $this->assertSame('3', $this->redis->get($this->prefix . 'k'));
            $this->assertSame(-1, $this->redis->ttl($this->prefix . 'k'));
        }
    }

    public function testARefusalRoundsAPartialSecondUp(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $this->redis->pExpire($this->prefix . 'k', 900);

        $result = $limiter->take('k', 1, 60);

        $this->assertFalse($result->accepted);
        $this->assertSame(1, $result->resetIn);
        $this->assertLessThanOrEqual(900, $this->redis->pttl($this->prefix . 'k'));
    }

    public function testAFixedTakeRoundsUpAndPreservesTheExactExpiry(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 3, 60);
        $this->redis->pExpire($this->prefix . 'k', 1900);

        $result = $limiter->take('k', 3, 60);

        $this->assertTrue($result->accepted);
        $this->assertSame(2, $result->resetIn);
        $this->assertLessThanOrEqual(1900, $this->redis->pttl($this->prefix . 'k'));
    }

    /** @return iterable<string, array{string, \Closure(RateLimiter): mixed}> */
    public static function damagedCounters(): iterable
    {
        $operations = [
            'take' => static fn (RateLimiter $limiter) => $limiter->take('k', 5, 60),
            'giveBack' => static fn (RateLimiter $limiter) => $limiter->giveBack('k'),
        ];
        foreach (['-1', '1.5', '2147483648', 'not a number'] as $counter) {
            foreach ($operations as $name => $operation) {
                yield "{$name}: {$counter}" => [$counter, $operation];
            }
        }
    }

    /** @param \Closure(RateLimiter): mixed $operation */
    #[DataProvider('damagedCounters')]
    public function testDamagedCountersAreRejectedWithoutMutation(string $counter, \Closure $operation): void
    {
        $this->redis->set($this->prefix . 'k', $counter, ['ex' => 60]);
        $this->expectException(RateLimiterException::class);

        try {
            $operation($this->limiter());
        } finally {
            $this->assertSame($counter, $this->redis->get($this->prefix . 'k'));
        }
    }

    public function testAnInvalidWindowNeverCreatesAPersistentCounter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->limiter()->take('k', 1, PHP_INT_SIZE >= 8 ? PHP_INT_MAX : -1);
        } finally {
            $this->assertFalse($this->redis->get($this->prefix . 'k'));
        }
    }

    public function testConcurrentProcessesNeverTakeMoreThanTheLimit(): void
    {
        $startAt = (string) (microtime(true) + 1.0);
        $processes = [];
        $pipes = [];
        for ($i = 0; $i < 20; $i++) {
            $command = [
                PHP_BINARY,
                dirname(__DIR__) . '/Support/take.php',
                $this->host,
                (string) $this->port,
                $this->prefix,
                $startAt,
            ];
            $processes[$i] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }

        $taken = 0;
        foreach ($processes as $i => $process) {
            $this->assertIsResource($process);
            $output = (string) stream_get_contents($pipes[$i][1]);
            $errors = (string) stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $this->assertSame(0, proc_close($process), $errors);
            $taken += (int) $output;
        }

        $this->assertSame(5, $taken);
    }
}
