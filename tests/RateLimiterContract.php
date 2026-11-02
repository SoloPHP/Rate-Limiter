<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\RateLimiter\RateLimiter;

abstract class RateLimiterContract extends TestCase
{
    abstract protected function limiter(): RateLimiter;

    abstract protected function elapse(string $key, int $seconds): void;

    public function testTakesUpToTheLimitAndRefusesTheRest(): void
    {
        $limiter = $this->limiter();

        foreach ([1, 2, 3] as $used) {
            $result = $limiter->take('k', 3, 60);
            $this->assertTrue($result->accepted);
            $this->assertSame($used, $result->used);
        }

        $refused = $limiter->take('k', 3, 60);
        $this->assertFalse($refused->accepted);
        $this->assertSame(3, $refused->used);
    }

    public function testAFreshWindowReportsItsFullLength(): void
    {
        $this->assertSame(60, $this->limiter()->take('k', 3, 60)->resetIn);
    }

    public function testARefusalChangesNeitherTheCountNorTheClose(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60, renew: true);
        $this->elapse('k', 30);

        $refused = $limiter->take('k', 1, 60, renew: true);

        $this->assertFalse($refused->accepted);
        $this->assertSame(1, $refused->used);
        $this->assertGreaterThanOrEqual(1, $refused->resetIn);
        $this->assertLessThanOrEqual(30, $refused->resetIn);
        $this->assertLessThanOrEqual(30, $limiter->take('k', 1, 60, renew: true)->resetIn);
    }

    public function testAFixedWindowKeepsTheCloseItOpenedWith(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 5, 60);
        $this->elapse('k', 50);

        $resetIn = $limiter->take('k', 5, 60)->resetIn;
        $this->assertGreaterThanOrEqual(1, $resetIn);
        $this->assertLessThanOrEqual(10, $resetIn);
    }

    public function testARenewableWindowMovesItsCloseWithEveryTake(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 5, 60, renew: true);
        $this->elapse('k', 50);

        $this->assertSame(60, $limiter->take('k', 5, 60, renew: true)->resetIn);
    }

    public function testAClosedWindowStartsOver(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $this->elapse('k', 60);

        $result = $limiter->take('k', 1, 60);

        $this->assertTrue($result->accepted);
        $this->assertSame(1, $result->used);
    }

    public function testAHitGivenBackCanBeTakenAgain(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $limiter->giveBack('k');

        $this->assertTrue($limiter->take('k', 1, 60)->accepted);
        $this->assertFalse($limiter->take('k', 1, 60)->accepted);
    }

    public function testGivingBackNeverGoesBelowZero(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $limiter->giveBack('k');
        $limiter->giveBack('k');

        $this->assertSame(1, $limiter->take('k', 1, 60)->used);
        $this->assertFalse($limiter->take('k', 1, 60)->accepted);
    }

    public function testGivingBackKeepsTheClose(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 2, 60);
        $this->elapse('k', 50);
        $limiter->giveBack('k');

        $resetIn = $limiter->take('k', 2, 60)->resetIn;
        $this->assertGreaterThanOrEqual(1, $resetIn);
        $this->assertLessThanOrEqual(10, $resetIn);
    }

    public function testGivingBackToNoWindowOpensNone(): void
    {
        $limiter = $this->limiter();
        $limiter->giveBack('k');

        $this->assertSame(1, $limiter->take('k', 1, 60)->used);
        $this->assertFalse($limiter->take('k', 1, 60)->accepted);
    }

    public function testClearClosesTheWindow(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $limiter->clear('k');

        $this->assertTrue($limiter->take('k', 1, 60)->accepted);
    }

    public function testClearingAKeyWithoutAWindowIsHarmless(): void
    {
        $limiter = $this->limiter();
        $limiter->clear('k');

        $this->assertSame(1, $limiter->take('k', 1, 60)->used);
    }

    public function testKeysAreCountedApart(): void
    {
        $limiter = $this->limiter();
        $limiter->take('a', 1, 60);

        $this->assertTrue($limiter->take('b', 1, 60)->accepted);
    }

    public function testGivingBackToAnExpiredWindowOpensNone(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);
        $this->elapse('k', 60);
        $limiter->giveBack('k');

        $result = $limiter->take('k', 1, 30);
        $this->assertTrue($result->accepted);
        $this->assertSame(1, $result->used);
        $this->assertSame(30, $result->resetIn);
    }

    public function testALowerLimitAppliesToTheExistingCount(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 5, 60);
        $limiter->take('k', 5, 60);

        $result = $limiter->take('k', 1, 60);
        $this->assertFalse($result->accepted);
        $this->assertSame(2, $result->used);
    }

    public function testChangingTheDurationDoesNotMoveAFixedWindow(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 5, 60);
        $this->elapse('k', 50);

        $result = $limiter->take('k', 5, 600);
        $this->assertTrue($result->accepted);
        $this->assertGreaterThanOrEqual(1, $result->resetIn);
        $this->assertLessThanOrEqual(10, $result->resetIn);
    }

    public function testAnAcceptedRenewingHitUsesTheNewDuration(): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 5, 60);

        $this->assertSame(120, $limiter->take('k', 5, 120, renew: true)->resetIn);
    }

    public function testTheLargestSupportedArgumentsAreAccepted(): void
    {
        $result = $this->limiter()->take('k', 2_147_483_647, 2_147_483_647);
        $this->assertTrue($result->accepted);
        $this->assertSame(1, $result->used);
        $this->assertSame(2_147_483_647, $result->resetIn);
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsLeaveTheWindowUnchanged(int $limit, int $windowSeconds): void
    {
        $limiter = $this->limiter();
        $limiter->take('k', 1, 60);

        try {
            $limiter->take('k', $limit, $windowSeconds, renew: true);
            $this->fail('Invalid arguments must be rejected before changing the window.');
        } catch (\InvalidArgumentException) {
            $result = $limiter->take('k', 1, 60);
            $this->assertFalse($result->accepted);
            $this->assertSame(1, $result->used);
            $this->assertGreaterThanOrEqual(1, $result->resetIn);
            $this->assertLessThanOrEqual(60, $result->resetIn);
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidArguments(): iterable
    {
        yield 'zero limit' => [0, 60];
        yield 'negative limit' => [-1, 60];
        yield 'zero window' => [1, 0];
        yield 'negative window' => [1, -1];
        if (PHP_INT_SIZE >= 8) {
            yield 'limit above supported range' => [2_147_483_648, 60];
            yield 'window above supported range' => [1, 2_147_483_648];
            yield 'overflowing window' => [1, PHP_INT_MAX];
        }
    }
}
