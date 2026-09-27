<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Reliability;

use Bedriox\RakNet\Reliability\ReliabilityLimits;
use Bedriox\RakNet\Reliability\ReliableFrameTracker;
use Bedriox\RakNet\Tests\MutableClock;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class ReliableFrameTrackerTest extends TestCase
{
    public function testAcknowledgementIsIdempotentAndReleasesCanonicalState(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits());
        $tracker->track(1, 'payload');
        $tracker->recordTransmission(10, [1]);
        $clock->advanceMilliseconds(100);

        self::assertSame([1], $tracker->acknowledgeDatagram(10));
        self::assertSame([], $tracker->acknowledgeDatagram(10));
        self::assertSame(0, $tracker->pendingCount());
        self::assertSame(0, $tracker->pendingPayloadBytes());
        self::assertSame(0, $tracker->historyCount());
    }

    public function testNackMakesOnePromptRetryAvailable(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits());
        $frame = $tracker->track(1, 'payload');
        $tracker->recordTransmission(10, [1]);

        self::assertTrue($tracker->negativeAcknowledgeDatagram(10));
        self::assertFalse($tracker->negativeAcknowledgeDatagram(10));
        self::assertSame([$frame], $tracker->collectDueRetries()->due);
        self::assertSame([], $tracker->collectDueRetries()->due);
        $tracker->recordTransmission(11, [1]);
        self::assertSame(1, $tracker->historyCount());
    }

    public function testKarnRuleExcludesRetransmittedRoundTripSamples(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits());
        $tracker->track(1, 'first');
        $tracker->recordTransmission(10, [1]);
        $clock->advanceMilliseconds(100);
        $tracker->negativeAcknowledgeDatagram(10);
        $tracker->collectDueRetries();
        $tracker->recordTransmission(11, [1]);
        $clock->advanceMilliseconds(100);
        $tracker->acknowledgeDatagram(11);
        self::assertSame(100_000_000, $tracker->rtoNanoseconds());

        $tracker->track(2, 'second');
        $tracker->recordTransmission(12, [2]);
        $clock->advanceMilliseconds(50);
        $tracker->acknowledgeDatagram(12);
        self::assertSame(150_000_000, $tracker->rtoNanoseconds());
    }

    public function testRetryAttemptLimitExpiresFrameAndHistory(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits(maximumAttempts: 2));
        $tracker->track(1, 'payload');
        $tracker->recordTransmission(10, [1]);
        $clock->advanceMilliseconds(100);
        self::assertCount(1, $tracker->collectDueRetries()->due);
        $tracker->recordTransmission(11, [1]);
        $clock->advanceMilliseconds(200);

        $decision = $tracker->collectDueRetries();
        self::assertSame([], $decision->due);
        self::assertSame([1], $decision->expiredReliableIndices);
        self::assertSame([10, 11], $decision->expiredReliableFrames[0]->transmissionSequences);
        self::assertSame(0, $tracker->pendingCount());
        self::assertSame(0, $tracker->historyCount());
    }

    public function testAgeLimitExpiresEvenUnsentFrame(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits(maximumAgeNanoseconds: 100_000_000));
        $tracker->track(1, 'payload');
        $clock->advanceMilliseconds(100);

        $decision = $tracker->collectDueRetries();
        self::assertSame([1], $decision->expiredReliableIndices);
        self::assertSame([1], $decision->expiredUnsentReliableIndices);
        self::assertSame(0, $tracker->pendingCount());
    }

    public function testTransmittedExpiryIsNotReportedAsUnsent(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits(maximumAttempts: 1));
        $tracker->track(1, 'payload');
        $tracker->recordTransmission(10, [1]);
        $clock->advanceMilliseconds(100);

        $decision = $tracker->collectDueRetries();
        self::assertSame([1], $decision->expiredReliableIndices);
        self::assertSame([], $decision->expiredUnsentReliableIndices);
    }

    public function testFrameCountAndByteLimitsAreHardAndAtomic(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits(maximumTrackedFrames: 1, maximumBytes: 3));
        $tracker->track(1, 'abc');

        try {
            $tracker->track(2, 'd');
            self::fail('Expected reliable-frame capacity failure.');
        } catch (OverflowException) {
            self::assertSame(1, $tracker->pendingCount());
            self::assertSame(3, $tracker->pendingPayloadBytes());
        }
    }

    public function testRetransmissionSupersedesHistoryAtCapacity(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits(maximumDatagrams: 1, maximumReferences: 1));
        $tracker->track(1, 'a');
        $tracker->recordTransmission(10, [1]);

        $clock->advanceMilliseconds(100);
        self::assertCount(1, $tracker->collectDueRetries()->due);
        $tracker->recordTransmission(11, [1]);

        self::assertSame(1, $tracker->historyCount());
        self::assertSame(1, $tracker->historyReferenceCount());
        self::assertSame([], $tracker->acknowledgeDatagram(10));
        self::assertSame([1], $tracker->acknowledgeDatagram(11));
    }

    public function testDefaultScaleRetryWaveSupersedesHistoryWithoutGrowth(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, new ReliabilityLimits());
        for ($index = 0; $index < 2_048; ++$index) {
            $tracker->track($index, 'x');
            $tracker->recordTransmission($index, [$index]);
        }
        self::assertSame(2_048, $tracker->historyCount());

        $clock->advanceMilliseconds(500);
        $due = $tracker->collectDueRetries()->due;
        self::assertCount(2_048, $due);
        foreach ($due as $offset => $frame) {
            $tracker->recordTransmission(2_048 + $offset, [$frame->reliableIndex]);
        }

        self::assertSame(2_048, $tracker->historyCount());
        self::assertSame(2_048, $tracker->historyReferenceCount());
    }

    public function testDelayedAcknowledgementOfEarlierTransmissionCompletesRetriedFrame(): void
    {
        $clock = new MutableClock();
        $tracker = new ReliableFrameTracker($clock, self::limits());
        $tracker->track(1, 'payload');
        $tracker->recordTransmission(10, [1]);

        $clock->advanceMilliseconds(100);
        self::assertCount(1, $tracker->collectDueRetries()->due);
        $tracker->recordTransmission(11, [1]);

        self::assertSame(1, $tracker->transmittedPendingCount());
        self::assertSame(2, $tracker->historyCount());
        self::assertSame([1], $tracker->acknowledgeDatagram(10));
        self::assertSame(0, $tracker->transmittedPendingCount());
        self::assertSame(0, $tracker->pendingCount());
        self::assertSame(0, $tracker->historyCount());
        self::assertSame([], $tracker->acknowledgeDatagram(11));
    }

    private static function limits(
        int $maximumAttempts = 4,
        int $maximumAgeNanoseconds = 1_000_000_000,
        int $maximumTrackedFrames = 8,
        int $maximumBytes = 64,
        int $maximumDatagrams = 8,
        int $maximumReferences = 16,
    ): ReliabilityLimits {
        return new ReliabilityLimits(
            receiveWindowSize: 32,
            maximumAcknowledgementSequences: 32,
            maximumTrackedFrames: $maximumTrackedFrames,
            maximumTrackedPayloadBytes: $maximumBytes,
            maximumSentDatagrams: $maximumDatagrams,
            maximumHistoryReferences: $maximumReferences,
            maximumAttempts: $maximumAttempts,
            maximumAgeNanoseconds: $maximumAgeNanoseconds,
            initialRtoNanoseconds: 100_000_000,
            minimumRtoNanoseconds: 50_000_000,
            maximumRtoNanoseconds: 1_000_000_000,
        );
    }
}
