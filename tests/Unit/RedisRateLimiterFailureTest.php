<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Solo\RateLimiter\Exception\RateLimiterException;
use Solo\RateLimiter\RateLimiter;
use Solo\RateLimiter\RedisRateLimiter;

final class RedisRateLimiterFailureTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded');
        }
    }

    /** @return iterable<string, array{\Closure(RateLimiter): mixed}> */
    public static function operations(): iterable
    {
        yield 'take' => [static fn (RateLimiter $limiter) => $limiter->take('k', 1, 60)];
        yield 'giveBack' => [static fn (RateLimiter $limiter) => $limiter->giveBack('k')];
        yield 'clear' => [static fn (RateLimiter $limiter) => $limiter->clear('k')];
    }

    /** @param \Closure(RateLimiter): mixed $operation */
    #[DataProvider('operations')]
    public function testRedisExceptionsPreserveTheirCause(\Closure $operation): void
    {
        $redis = $this->redis();
        $cause = new \RedisException('Connection lost');
        $redis->expects($this->once())->method('eval')->willThrowException($cause);

        try {
            $operation(new RedisRateLimiter($redis, 'test:'));
            $this->fail('A Redis error must raise the limiter exception');
        } catch (RateLimiterException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testAFailedScriptReportsTheRedisError(): void
    {
        $redis = $this->redis();
        $redis->expects($this->once())->method('eval')->with($this->anything(), ['test:k'], 1)->willReturn(false);
        $redis->method('getLastError')->willReturn('NOPERM');

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('NOPERM');

        (new RedisRateLimiter($redis, 'test:'))->clear('k');
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedReplies(): iterable
    {
        yield 'failed script' => [false];
        yield 'no reply' => [null];
        yield 'missing fields' => [[1, 1]];
        yield 'extra fields' => [[1, 1, 60, 0]];
        yield 'associative fields' => [['accepted' => 1, 'used' => 1, 'resetIn' => 60]];
        yield 'invalid accepted flag' => [[2, 1, 60]];
        yield 'negative count' => [[1, -1, 60]];
        yield 'empty count' => [[1, 0, 60]];
        yield 'no expiry' => [[0, 1, -1]];
        yield 'zero expiry' => [[0, 1, 0]];
        yield 'non-integer count' => [[1, '1', 60]];
        yield 'non-integer expiry' => [[1, 1, '60']];
    }

    #[DataProvider('malformedReplies')]
    public function testTakeRejectsMalformedReplies(mixed $reply): void
    {
        $redis = $this->redis();
        $redis->expects($this->once())->method('eval')->willReturn($reply);

        $this->expectException(RateLimiterException::class);

        (new RedisRateLimiter($redis, 'test:'))->take('k', 1, 60);
    }

    /** @return iterable<string, array{\Closure(RateLimiter): mixed}> */
    public static function emptyReplyOperations(): iterable
    {
        yield 'giveBack' => [static fn (RateLimiter $limiter) => $limiter->giveBack('k')];
        yield 'clear' => [static fn (RateLimiter $limiter) => $limiter->clear('k')];
    }

    /** @param \Closure(RateLimiter): mixed $operation */
    #[DataProvider('emptyReplyOperations')]
    public function testOperationsWithoutAResultRejectAnUnexpectedReply(\Closure $operation): void
    {
        $redis = $this->redis();
        $redis->expects($this->once())->method('eval')->willReturn([1]);

        $this->expectException(RateLimiterException::class);

        $operation(new RedisRateLimiter($redis, 'test:'));
    }

    /** @return iterable<string, array{\Closure(RateLimiter): mixed, string}> */
    public static function queuedOperations(): iterable
    {
        foreach (['transaction', 'pipeline'] as $mode) {
            foreach (self::operations() as $name => [$operation]) {
                yield "{$mode}: {$name}" => [$operation, $mode];
            }
        }
    }

    /** @param \Closure(RateLimiter): mixed $operation */
    #[DataProvider('queuedOperations')]
    public function testQueuedClientsAreRejectedBeforeSendingACommand(\Closure $operation, string $mode): void
    {
        $redis = $this->redis($mode === 'transaction' ? \Redis::MULTI : \Redis::PIPELINE);
        $redis->expects($this->never())->method('eval');

        $this->expectException(RateLimiterException::class);

        $operation(new RedisRateLimiter($redis, 'test:'));
    }

    private function redis(int $mode = \Redis::ATOMIC): \Redis&MockObject
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('getMode')->willReturn($mode);

        return $redis;
    }
}
