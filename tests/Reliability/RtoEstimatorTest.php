<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Reliability;

use Bedriox\RakNet\Clock;
use Bedriox\RakNet\Reliability\ReliabilityLimits;
use Bedriox\RakNet\Reliability\ReliableFrameTracker;
use Bedriox\RakNet\Reliability\RtoEstimator;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RtoEstimatorTest extends TestCase
{
    public function testEstimatorClampsSamplesAndBackoff(): void
    {
        $estimator = new RtoEstimator(100, 50, 500);
        $estimator->observeRoundTrip(10);
        self::assertSame(50, $estimator->currentNanoseconds());
        self::assertSame(500, $estimator->backedOffNanoseconds(5));

        $estimator = new RtoEstimator(100, 50, 500);
        $estimator->observeRoundTrip(PHP_INT_MAX);
        self::assertSame(500, $estimator->currentNanoseconds());
    }

    public function testTrackerRejectsClockRegression(): void
    {
        $clock = new class implements Clock {
            public int $now = 100;

            public function nowNanoseconds(): int
            {
                return $this->now;
            }
        };
        $tracker = new ReliableFrameTracker($clock, new ReliabilityLimits());
        $tracker->track(1, 'payload');
        $clock->now = 99;

        $this->expectException(LogicException::class);
        $tracker->collectDueRetries();
    }
}
