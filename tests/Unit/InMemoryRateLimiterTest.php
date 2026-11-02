<?php

declare(strict_types=1);

namespace Solo\RateLimiter\Tests\Unit;

use Solo\RateLimiter\InMemoryRateLimiter;
use Solo\RateLimiter\RateLimiter;
use Solo\RateLimiter\Tests\RateLimiterContract;
use Solo\RateLimiter\Tests\Support\FrozenClock;

final class InMemoryRateLimiterTest extends RateLimiterContract
{
    private FrozenClock $clock;
    private InMemoryRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->limiter = new InMemoryRateLimiter($this->clock);
    }

    protected function limiter(): RateLimiter
    {
        return $this->limiter;
    }

    protected function elapse(string $key, int $seconds): void
    {
        $this->clock->advance($seconds);
    }

    public function testRunsOnTheSystemClockWithoutOne(): void
    {
        $limiter = new InMemoryRateLimiter();
        $limiter->take('k', 1, 60);

        $this->assertFalse($limiter->take('k', 1, 60)->accepted);
    }

    public function testAWindowLastsItsFullDurationIncludingFractionalSeconds(): void
    {
        $this->clock->advanceMicroseconds(900_000);
        $this->assertSame(1, $this->limiter->take('k', 1, 1)->resetIn);
        $this->clock->advanceMicroseconds(100_000);

        $result = $this->limiter->take('k', 1, 1);
        $this->assertFalse($result->accepted);
        $this->assertSame(1, $result->resetIn);

        $this->clock->advanceMicroseconds(899_999);
        $this->assertFalse($this->limiter->take('k', 1, 1)->accepted);
        $this->clock->advanceMicroseconds(1);

        $result = $this->limiter->take('k', 1, 1);
        $this->assertTrue($result->accepted);
        $this->assertSame(1, $result->used);
        $this->assertSame(1, $result->resetIn);
    }

    public function testAFixedWindowPreservesItsFractionalExpiry(): void
    {
        $this->clock->advanceMicroseconds(900_000);
        $this->limiter->take('k', 2, 1);
        $this->clock->advanceMicroseconds(100_000);

        $this->assertSame(1, $this->limiter->take('k', 2, 1)->resetIn);
        $this->clock->advanceMicroseconds(899_999);
        $this->assertFalse($this->limiter->take('k', 2, 1)->accepted);
        $this->clock->advanceMicroseconds(1);

        $this->assertTrue($this->limiter->take('k', 2, 1)->accepted);
    }

    public function testARenewableWindowPreservesItsFractionalExpiry(): void
    {
        $this->limiter->take('k', 2, 1, renew: true);
        $this->clock->advanceMicroseconds(900_000);

        $this->assertSame(1, $this->limiter->take('k', 2, 1, renew: true)->resetIn);
        $this->clock->advanceMicroseconds(100_000);
        $this->assertFalse($this->limiter->take('k', 2, 1, renew: true)->accepted);
        $this->clock->advanceMicroseconds(899_999);
        $this->assertFalse($this->limiter->take('k', 2, 1, renew: true)->accepted);
        $this->clock->advanceMicroseconds(1);

        $this->assertTrue($this->limiter->take('k', 2, 1, renew: true)->accepted);
    }

    public function testARefundPreservesTheOriginalFractionalExpiry(): void
    {
        $this->clock->advanceMicroseconds(900_000);
        $this->limiter->take('k', 2, 1);
        $this->clock->advanceMicroseconds(100_000);
        $this->limiter->giveBack('k');

        $result = $this->limiter->take('k', 2, 1);
        $this->assertSame(1, $result->used);
        $this->assertSame(1, $result->resetIn);
        $this->clock->advanceMicroseconds(900_000);

        $this->assertSame(1, $this->limiter->take('k', 2, 1)->used);
    }

    public function testTheRemainingSecondsAreRoundedUp(): void
    {
        $this->limiter->take('k', 1, 60);
        $this->clock->advanceMicroseconds(1);
        $this->assertSame(60, $this->limiter->take('k', 1, 60)->resetIn);
        $this->clock->advanceMicroseconds(999_999);
        $this->assertSame(59, $this->limiter->take('k', 1, 60)->resetIn);
        $this->clock->advanceMicroseconds(58_999_999);
        $this->assertSame(1, $this->limiter->take('k', 1, 60)->resetIn);
    }

    public function testTheWindowDurationIsUnaffectedByDaylightSavingTime(): void
    {
        $now = (new \DateTimeImmutable('2026-10-25T00:30:00Z'))
            ->setTimezone(new \DateTimeZone('Europe/Kyiv'));
        $clock = new FrozenClock($now);
        $limiter = new InMemoryRateLimiter($clock);

        $this->assertSame(3600, $limiter->take('k', 1, 3600)->resetIn);
        $clock->advance(3599);
        $this->assertFalse($limiter->take('k', 1, 3600)->accepted);
        $clock->advance(1);

        $this->assertTrue($limiter->take('k', 1, 3600)->accepted);
    }

    public function testPurgingRemovesOnlyExpiredWindows(): void
    {
        $this->clock->advanceMicroseconds(900_000);
        $this->limiter->take('expired', 1, 1);
        $this->limiter->take('active', 1, 60);
        $this->clock->advanceMicroseconds(999_999);

        $this->assertSame(0, $this->limiter->purgeExpired());
        $this->clock->advanceMicroseconds(1);
        $this->assertSame(1, $this->limiter->purgeExpired());
        $this->assertSame(0, $this->limiter->purgeExpired());
        $this->assertFalse($this->limiter->take('active', 1, 60)->accepted);
        $this->assertTrue($this->limiter->take('expired', 1, 1)->accepted);
    }

    public function testTakingOtherKeysEventuallyPurgesExpiredWindows(): void
    {
        $this->limiter->take('expired', 1, 1);
        $this->clock->advance(1);

        for ($i = 0; $i < 100; $i++) {
            $this->limiter->take('active:' . $i, 1, 60);
        }

        $this->assertSame(0, $this->limiter->purgeExpired());
        $this->assertFalse($this->limiter->take('active:0', 1, 60)->accepted);
    }

    public function testRefundingOtherKeysEventuallyPurgesExpiredWindows(): void
    {
        $this->limiter->take('expired', 1, 1);
        $this->limiter->take('active', 1, 60);
        $this->clock->advance(1);

        for ($i = 0; $i < 100; $i++) {
            $this->limiter->giveBack('missing:' . $i);
        }

        $this->assertSame(0, $this->limiter->purgeExpired());
        $this->assertFalse($this->limiter->take('active', 1, 60)->accepted);
    }
}
