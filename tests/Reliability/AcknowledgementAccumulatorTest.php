<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Reliability;

use Bedriox\RakNet\Reliability\AcknowledgementAccumulator;
use Bedriox\RakNet\Reliability\Sequence24;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class AcknowledgementAccumulatorTest extends TestCase
{
    public function testAccumulationIsIdempotentAndCompactsRanges(): void
    {
        $pending = new AcknowledgementAccumulator(16);
        foreach ([4, 2, 3, 3, 9] as $sequence) {
            $pending->acknowledge($sequence);
        }

        self::assertSame(4, $pending->acknowledgementCount());
        $ranges = $pending->drainAcknowledgements();
        self::assertSame([[2, 4], [9, 9]], array_map(
            static fn($range): array => [$range->start, $range->end],
            $ranges,
        ));
        self::assertSame([], $pending->drainAcknowledgements());
    }

    public function testWrapIsSplitIntoNumericWireRanges(): void
    {
        $pending = new AcknowledgementAccumulator(8);
        foreach ([Sequence24::MAX - 1, Sequence24::MAX, 0, 1] as $sequence) {
            $pending->negativeAcknowledge($sequence);
        }

        self::assertSame([[0, 1], [Sequence24::MAX - 1, Sequence24::MAX]], array_map(
            static fn($range): array => [$range->start, $range->end],
            $pending->drainNegativeAcknowledgements(),
        ));
    }

    public function testAcknowledgementCancelsPendingNegativeAcknowledgement(): void
    {
        $pending = new AcknowledgementAccumulator(2);
        $pending->negativeAcknowledge(42);
        $pending->negativeAcknowledge(42);
        $pending->acknowledge(42);
        $pending->negativeAcknowledge(42);

        self::assertSame(1, $pending->acknowledgementCount());
        self::assertSame(0, $pending->negativeAcknowledgementCount());
    }

    public function testCombinedHardLimitRejectsNewStateWithoutLosingExistingState(): void
    {
        $pending = new AcknowledgementAccumulator(2);
        $pending->acknowledge(1);
        $pending->negativeAcknowledge(2);

        try {
            $pending->acknowledge(3);
            self::fail('Expected acknowledgement capacity failure.');
        } catch (OverflowException) {
            self::assertSame(1, $pending->acknowledgementCount());
            self::assertSame(1, $pending->negativeAcknowledgementCount());
        }
    }

    public function testBoundedDrainRetainsUnencodedRanges(): void
    {
        $pending = new AcknowledgementAccumulator(512);
        for ($sequence = 0; $sequence < 440; $sequence += 2) {
            $pending->acknowledge($sequence);
        }

        self::assertCount(77, $pending->drainAcknowledgements(212, 77 * 4));
        self::assertSame(143, $pending->acknowledgementCount());
        self::assertCount(143, $pending->drainAcknowledgements(212, 1_000));
        self::assertSame(0, $pending->acknowledgementCount());
    }
}
