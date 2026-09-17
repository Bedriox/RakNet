<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Reliability;

use Bedriox\RakNet\Reliability\ReceiveSequenceWindow;
use Bedriox\RakNet\Reliability\ReliableMessageWindow;
use Bedriox\RakNet\Reliability\Sequence24;
use Bedriox\RakNet\Reliability\SequenceObservation;
use Bedriox\RakNet\Reliability\SequenceRelation;
use PHPUnit\Framework\TestCase;

final class SequenceWindowTest extends TestCase
{
    public function testSequenceMathWrapAndAmbiguousHalfRange(): void
    {
        self::assertSame(0, Sequence24::increment(Sequence24::MAX));
        self::assertSame(3, Sequence24::forwardDistance(Sequence24::MAX - 1, 1));
        self::assertSame(SequenceRelation::Newer, Sequence24::relation(Sequence24::MAX, 0));
        self::assertSame(SequenceRelation::Older, Sequence24::relation(0, Sequence24::MAX));
        self::assertSame(SequenceRelation::Ambiguous, Sequence24::relation(0, Sequence24::HALF_RANGE));
    }

    public function testWindowFindsWrappedGapAndAcceptsItOutOfOrder(): void
    {
        $window = new ReceiveSequenceWindow(8);
        self::assertSame(SequenceObservation::Accepted, $window->observe(Sequence24::MAX - 1)->observation);

        $advanced = $window->observe(0);
        self::assertSame(SequenceObservation::Accepted, $advanced->observation);
        self::assertSame([Sequence24::MAX], $advanced->missingSequences);
        self::assertSame(SequenceObservation::AcceptedOutOfOrder, $window->observe(Sequence24::MAX)->observation);
        self::assertSame(SequenceObservation::Duplicate, $window->observe(Sequence24::MAX)->observation);
    }

    public function testHugeForwardJumpDoesNotAllocateOrAdvance(): void
    {
        $window = new ReceiveSequenceWindow(32);
        $window->observe(10);
        $before = $window->retainedCount();

        $result = $window->observe(1_000_000);
        self::assertSame(SequenceObservation::TooFarAhead, $result->observation);
        self::assertSame([], $result->missingSequences);
        self::assertSame($before, $window->retainedCount());
        self::assertSame(10, $window->highest());
    }

    public function testAmbiguousAndStaleSequencesAreRejectedWithoutStateGrowth(): void
    {
        $window = new ReceiveSequenceWindow(8);
        $window->observe(0);
        self::assertSame(SequenceObservation::Ambiguous, $window->observe(Sequence24::HALF_RANGE)->observation);
        for ($sequence = 1; $sequence < 8; ++$sequence) {
            $window->observe($sequence);
        }
        self::assertSame(SequenceObservation::Stale, $window->observe(Sequence24::MAX)->observation);
        self::assertLessThanOrEqual(8, $window->retainedCount());
    }

    public function testReliableMessageWindowDeduplicatesAcrossWrap(): void
    {
        $window = new ReliableMessageWindow(16);
        self::assertTrue($window->observe(Sequence24::MAX)->isAccepted());
        self::assertTrue($window->observe(0)->isAccepted());
        self::assertSame(SequenceObservation::Duplicate, $window->observe(Sequence24::MAX));
        self::assertLessThanOrEqual(16, $window->retainedCount());
    }

    public function testLongSequentialRunRetainsOnlyTheBoundedWindow(): void
    {
        $window = new ReceiveSequenceWindow(2_048);
        for ($sequence = 0; $sequence < 100_000; ++$sequence) {
            self::assertSame(SequenceObservation::Accepted, $window->observe($sequence)->observation);
        }

        self::assertSame(2_048, $window->retainedCount());
        self::assertSame(99_999, $window->highest());
    }
}
