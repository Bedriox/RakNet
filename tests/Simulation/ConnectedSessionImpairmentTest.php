<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Simulation;

use Bedriox\RakNet\Connected\ConnectedSession;
use Bedriox\RakNet\Connected\ConnectedSessionLimits;
use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\AckPacket;
use Bedriox\RakNet\Protocol\BitPayload;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\EncapsulatedFrame;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\SequenceRange;
use Bedriox\RakNet\Reliability\ReliabilityLimits;
use Bedriox\RakNet\Tests\MutableClock;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class ConnectedSessionImpairmentTest extends TestCase
{
    public function testReliablePayloadsArriveExactlyOnceThroughSeededImpairments(): void
    {
        $clock = new MutableClock();
        $profile = new ImpairmentProfile(18, 55, 0, 35);
        $harness = self::harness($clock, 27_071, $profile, $profile);
        $expected = [];
        for ($index = 0; $index < 40; ++$index) {
            $payload = "reliable-$index";
            $expected[] = $payload;
            $harness->left()->queuePayload($payload, Reliability::Reliable);
        }

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 40,
            5_000,
            5,
        ), 'Seed 27071 did not deliver all reliable payloads before the virtual deadline.');
        $harness->runFor(1_000, 5);

        $actual = array_map(static fn($event): string => $event->payload, $harness->rightPayloads());
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);
        self::assertSame([], $harness->leftExpiredReliableIndices());
    }

    public function testReliableOrderedPayloadsRemainMonotonicAcrossLossDuplicationAndReordering(): void
    {
        $clock = new MutableClock();
        $profile = new ImpairmentProfile(15, 60, 0, 45);
        $harness = self::harness($clock, 98_723, $profile, $profile);
        for ($index = 0; $index < 50; ++$index) {
            $harness->left()->queuePayload(\sprintf('%03d', $index), Reliability::ReliableOrdered, 7);
        }

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 50,
            6_000,
            5,
        ), 'Seed 98723 did not drain the ordered channel before the virtual deadline.');

        self::assertSame(
            array_map(static fn(int $index): string => \sprintf('%03d', $index), range(0, 49)),
            array_map(static fn($event): string => $event->payload, $harness->rightPayloads()),
        );
        foreach ($harness->rightPayloads() as $event) {
            self::assertSame(Reliability::ReliableOrdered, $event->reliability);
            self::assertSame(7, $event->orderingChannel);
        }
    }

    public function testFragmentedOrderedPayloadReassemblesUnderImpairment(): void
    {
        $clock = new MutableClock();
        $profile = new ImpairmentProfile(12, 45, 0, 30);
        $harness = self::harness($clock, 4_242, $profile, $profile);
        $payload = str_repeat('fragmented-payload-', 400);
        $harness->left()->queuePayload($payload, Reliability::ReliableOrdered, 31);

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 1,
            6_000,
            5,
        ), 'Fragmented payload did not reassemble before the virtual deadline.');
        self::assertSame($payload, $harness->rightPayloads()[0]->payload);
        self::assertSame(31, $harness->rightPayloads()[0]->orderingChannel);
        self::assertGreaterThan(1, self::connectedTransmissionCount($harness, SessionPairHarness::LEFT_TO_RIGHT));
    }

    public function testGapProducesNackAndRecoversDroppedDatagramWhileAckClearsPeers(): void
    {
        $clock = new MutableClock();
        $seenData = 0;
        $droppedMiddleData = false;
        $dropRule = static function (string $direction, int $ordinal, string $payload) use (&$seenData, &$droppedMiddleData): bool {
            unset($ordinal);
            if (
                $direction === SessionPairHarness::LEFT_TO_RIGHT
                && \ord($payload[0]) === ConnectedDatagram::VALID_FLAG
            ) {
                ++$seenData;
                if ($seenData !== 2) {
                    return false;
                }

                $droppedMiddleData = true;

                return true;
            }

            return false;
        };
        $harness = self::harness(
            $clock,
            1,
            new ImpairmentProfile(),
            new ImpairmentProfile(),
            $dropRule,
        );
        $harness->left()->queuePayload('visible-first', Reliability::Reliable);
        $harness->left()->queuePayload('missing-middle', Reliability::Reliable);
        $harness->left()->queuePayload('visible-third', Reliability::Reliable);

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 3,
            1_000,
            5,
        ));
        $harness->runFor(500, 5);
        self::assertTrue($droppedMiddleData);
        self::assertGreaterThanOrEqual(
            1,
            self::packetIdCount($harness, SessionPairHarness::RIGHT_TO_LEFT, AcknowledgementCodec::NACK_ID),
        );
        self::assertGreaterThanOrEqual(
            1,
            self::packetIdCount($harness, SessionPairHarness::RIGHT_TO_LEFT, AcknowledgementCodec::ACK_ID),
        );
        self::assertSame([], $harness->leftExpiredReliableIndices());
    }

    public function testPermanentLossExpiresReliableFrameAfterBoundedRetries(): void
    {
        $clock = new MutableClock();
        $limits = self::limits(maximumAttempts: 3, maximumAgeNanoseconds: 1_000_000_000);
        $harness = self::harness(
            $clock,
            77,
            new ImpairmentProfile(lossPercent: 100),
            new ImpairmentProfile(),
            limits: $limits,
        );
        $harness->left()->queuePayload('never-arrives', Reliability::Reliable);
        $harness->runFor(200, 5);

        self::assertSame([], $harness->rightPayloads());
        self::assertSame([0], $harness->leftExpiredReliableIndices());
        self::assertSame(3, self::connectedTransmissionCount($harness, SessionPairHarness::LEFT_TO_RIGHT));
    }

    public function testQueueCountAndByteLimitsFailAtomicallyAndRetainAcceptedWork(): void
    {
        $clock = new MutableClock();
        $limits = self::limits(maximumQueuedFrames: 2, maximumQueuedPayloadBytes: 100);
        $harness = self::harness($clock, 5, limits: $limits);
        $harness->left()->queuePayload('aa', Reliability::Reliable);
        $harness->left()->queuePayload('bb', Reliability::Reliable);

        try {
            $harness->left()->queuePayload('c', Reliability::Reliable);
            self::fail('Queue count limit accepted a third frame.');
        } catch (OverflowException) {
        }

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 2,
            500,
            5,
        ));
        self::assertSame(
            ['aa', 'bb'],
            array_map(static fn($event): string => $event->payload, $harness->rightPayloads()),
        );

        $byteLimited = self::harness(
            new MutableClock(),
            51,
            limits: self::limits(maximumQueuedFrames: 10, maximumQueuedPayloadBytes: 4),
        );
        $byteLimited->left()->queuePayload('1234', Reliability::Reliable);
        $this->expectException(OverflowException::class);
        $byteLimited->left()->queuePayload('z', Reliability::Reliable);
    }

    public function testOutboundEffectLimitDrainsAcrossTicksWithoutDroppingQueuedFrames(): void
    {
        $clock = new MutableClock();
        $limits = self::limits(maximumOutboundDatagrams: 1, maximumOutboundBytes: 576);
        $harness = self::harness($clock, 6, limits: $limits);
        for ($index = 0; $index < 8; ++$index) {
            $harness->left()->queuePayload("bounded-$index", Reliability::Reliable);
        }

        self::assertTrue($harness->runUntil(
            static fn(SessionPairHarness $pair): bool => \count($pair->rightPayloads()) === 8,
            1_000,
            5,
        ));
        self::assertSame(8, \count($harness->rightPayloads()));
        self::assertSame([], $harness->leftExpiredReliableIndices());
    }

    public function testFragmentedQueueTrackerFailureIsAtomic(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            reliability: new ReliabilityLimits(
                maximumTrackedFrames: 2,
                initialRtoNanoseconds: 20_000_000,
                minimumRtoNanoseconds: 10_000_000,
                maximumRtoNanoseconds: 100_000_000,
            ),
        );
        $session = new ConnectedSession($clock, 576, $limits);

        try {
            $session->queuePayload(str_repeat('x', 1_200), Reliability::Reliable);
            self::fail('Tracker frame limit accepted a payload requiring three fragments.');
        } catch (OverflowException) {
        }

        $session->tick();
        self::assertSame([], $session->drainEffects()->outboundDatagrams);
    }

    public function testImpairedSessionCannotBlockIndependentHealthyPair(): void
    {
        $badClock = new MutableClock();
        $goodClock = new MutableClock();
        $bad = self::harness(
            $badClock,
            10,
            new ImpairmentProfile(lossPercent: 100),
            new ImpairmentProfile(),
        );
        $good = self::harness($goodClock, 11);
        $bad->left()->queuePayload('isolated-loss', Reliability::Reliable);
        $good->left()->queuePayload('healthy', Reliability::ReliableOrdered, 2);

        for ($step = 0; $step < 100; ++$step) {
            $bad->step(5);
            $good->step(5);
            if ($good->rightPayloads() !== []) {
                break;
            }
        }

        self::assertSame([], $bad->rightPayloads());
        self::assertSame('healthy', $good->rightPayloads()[0]->payload ?? null);
        self::assertSame(2, $good->rightPayloads()[0]->orderingChannel ?? null);
    }

    public function testSimultaneousAckAndNackDrainAcrossSingleOutboundSlot(): void
    {
        $clock = new MutableClock();
        $limits = self::limits(maximumOutboundDatagrams: 1, maximumOutboundBytes: 576);
        $session = new ConnectedSession($clock, 576, $limits);
        $session->receive(self::unreliableDatagram(0, 'zero'));
        $session->receive(self::unreliableDatagram(2, 'two'));

        $session->tick();
        $first = $session->drainEffects();
        self::assertCount(1, $first->outboundDatagrams);
        $session->tick();
        $second = $session->drainEffects();
        self::assertCount(1, $second->outboundDatagrams);

        $ids = [\ord($first->outboundDatagrams[0][0]), \ord($second->outboundDatagrams[0][0])];
        sort($ids, SORT_NUMERIC);
        self::assertSame([AcknowledgementCodec::NACK_ID, AcknowledgementCodec::ACK_ID], $ids);
    }

    public function testFullAcknowledgementAccumulatorFlushesBurstWithoutClosingSession(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            reliability: new ReliabilityLimits(maximumAcknowledgementSequences: 2),
            maximumOutboundDatagrams: 16,
            maximumOutboundBytes: 2_048,
        );
        $session = new ConnectedSession($clock, 576, $limits);
        for ($sequence = 0; $sequence < 10; ++$sequence) {
            $session->receive(self::unreliableDatagram($sequence, "burst-$sequence"));
        }
        $session->tick();
        $effects = $session->drainEffects();

        self::assertCount(10, $effects->payloads);
        self::assertNotEmpty($effects->outboundDatagrams);
        foreach ($effects->outboundDatagrams as $datagram) {
            self::assertSame(AcknowledgementCodec::ACK_ID, \ord($datagram[0]));
        }

        $session->queuePayload('still-open', Reliability::Unreliable);
        $session->tick();
        self::assertNotEmpty($session->drainEffects()->outboundDatagrams);
    }

    public function testOversizedUnreliableRejectionDoesNotLoseOrdinaryBackpressuredFrames(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            maximumApplicationPayloadBytes: 2_000,
            maximumOutboundDatagrams: 1,
            maximumOutboundBytes: 576,
            maximumFragmentsPerAssembly: 2,
        );
        $session = new ConnectedSession($clock, 576, $limits);

        try {
            $session->queuePayload(str_repeat('x', 1_200), Reliability::Unreliable);
            self::fail('Oversized unreliable fragmentation was accepted.');
        } catch (InvalidArgumentException|OverflowException) {
        }

        $session->queuePayload('first', Reliability::Unreliable);
        $session->queuePayload('second', Reliability::Unreliable);
        $session->tick();
        $first = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $first);
        self::assertSame('first', ConnectedDatagram::decode($first[0])->frames[0]->payload->bytes);

        $session->tick();
        $second = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $second);
        self::assertSame('second', ConnectedDatagram::decode($second[0])->frames[0]->payload->bytes);
    }

    public function testContinuousAckTrafficCannotStarvePendingNackWithOneOutputSlot(): void
    {
        $session = new ConnectedSession(
            new MutableClock(),
            576,
            self::limits(maximumOutboundDatagrams: 1, maximumOutboundBytes: 576),
        );
        $session->receive(self::unreliableDatagram(0, 'zero'));
        $session->receive(self::unreliableDatagram(2, 'two'));
        $session->tick();
        $first = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $first);

        $session->receive(self::unreliableDatagram(3, 'three'));
        $session->tick();
        $second = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $second);

        self::assertContains(
            AcknowledgementCodec::NACK_ID,
            [\ord($first[0][0]), \ord($second[0][0])],
            'Continuous ACK traffic starved a pending NACK for more than two drain/tick cycles.',
        );
    }

    public function testReliableInFlightWindowNeverExpandsUnderPermanentLoss(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            reliability: new ReliabilityLimits(
                maximumAttempts: 20,
                maximumAgeNanoseconds: 5_000_000_000,
                initialRtoNanoseconds: 20_000_000,
                minimumRtoNanoseconds: 10_000_000,
                maximumRtoNanoseconds: 100_000_000,
            ),
            maximumReliableDatagramsInFlight: 3,
            maximumDatagramsPerTick: 10,
        );
        $session = new ConnectedSession($clock, 576, $limits);
        for ($index = 0; $index < 10; ++$index) {
            $session->queuePayload("window-$index", Reliability::Reliable);
        }

        $reliableIndices = [];
        for ($tick = 0; $tick < 30; ++$tick) {
            $session->tick();
            foreach ($session->drainEffects()->outboundDatagrams as $bytes) {
                $datagram = ConnectedDatagram::decode($bytes);
                self::assertCount(1, $datagram->frames);
                $reliableIndex = $datagram->frames[0]->reliableIndex;
                self::assertNotNull($reliableIndex);
                $reliableIndices[$reliableIndex] = true;
            }
            $clock->advanceMilliseconds(10);
        }

        self::assertSame([0, 1, 2], array_keys($reliableIndices));
    }

    public function testAcknowledgementsOpenReliableWindowForQueuedData(): void
    {
        $session = new ConnectedSession(
            new MutableClock(),
            576,
            new ConnectedSessionLimits(maximumReliableDatagramsInFlight: 2, maximumDatagramsPerTick: 10),
        );
        for ($index = 0; $index < 5; ++$index) {
            $session->queuePayload("progress-$index", Reliability::Reliable);
        }

        $session->tick();
        $first = self::connectedDatagrams($session->drainEffects()->outboundDatagrams);
        self::assertCount(2, $first);
        $session->receive(self::acknowledge($first));
        $session->tick();
        $second = self::connectedDatagrams($session->drainEffects()->outboundDatagrams);
        self::assertCount(2, $second);
        $session->receive(self::acknowledge($second));
        $session->tick();
        $third = self::connectedDatagrams($session->drainEffects()->outboundDatagrams);
        self::assertCount(1, $third);

        $payloads = [];
        foreach ([$first, $second, $third] as $batch) {
            foreach ($batch as $datagram) {
                $payloads[] = $datagram->frames[0]->payload->bytes;
            }
        }
        self::assertSame(
            ['progress-0', 'progress-1', 'progress-2', 'progress-3', 'progress-4'],
            $payloads,
        );
    }

    public function testPerTickDataBudgetDoesNotSuppressRequiredControl(): void
    {
        $session = new ConnectedSession(
            new MutableClock(),
            576,
            new ConnectedSessionLimits(
                maximumOutboundDatagrams: 16,
                maximumOutboundBytes: 8_192,
                maximumDatagramsPerTick: 3,
            ),
        );
        for ($index = 0; $index < 10; ++$index) {
            $session->queuePayload("unreliable-$index", Reliability::Unreliable);
        }
        $session->receive(self::unreliableDatagram(0, 'inbound-zero'));
        $session->receive(self::unreliableDatagram(2, 'inbound-two'));

        $session->tick();
        $first = $session->drainEffects()->outboundDatagrams;
        self::assertSame(3, \count(self::connectedDatagrams($first)));
        self::assertContains(AcknowledgementCodec::ACK_ID, array_map(static fn(string $bytes): int => \ord($bytes[0]), $first));
        self::assertContains(AcknowledgementCodec::NACK_ID, array_map(static fn(string $bytes): int => \ord($bytes[0]), $first));

        $dataCounts = [3];
        for ($tick = 0; $tick < 3; ++$tick) {
            $session->tick();
            $dataCounts[] = \count(self::connectedDatagrams($session->drainEffects()->outboundDatagrams));
        }
        self::assertSame([3, 3, 3, 1], $dataCounts);
    }

    public function testIndependentSessionsOwnIndependentReliableWindows(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(maximumReliableDatagramsInFlight: 1, maximumDatagramsPerTick: 4);
        $left = new ConnectedSession($clock, 576, $limits);
        $right = new ConnectedSession($clock, 576, $limits);
        foreach ([$left, $right] as $session) {
            $session->queuePayload('first', Reliability::Reliable);
            $session->queuePayload('second', Reliability::Reliable);
            $session->tick();
        }

        $leftFirst = self::connectedDatagrams($left->drainEffects()->outboundDatagrams);
        $rightFirst = self::connectedDatagrams($right->drainEffects()->outboundDatagrams);
        self::assertCount(1, $leftFirst);
        self::assertCount(1, $rightFirst);

        $left->receive(self::acknowledge($leftFirst));
        $left->tick();
        $right->tick();
        self::assertCount(1, self::connectedDatagrams($left->drainEffects()->outboundDatagrams));
        self::assertSame([], $right->drainEffects()->outboundDatagrams);
    }

    private static function harness(
        MutableClock $clock,
        int $seed,
        ?ImpairmentProfile $leftToRight = null,
        ?ImpairmentProfile $rightToLeft = null,
        ?\Closure $dropRule = null,
        ?ConnectedSessionLimits $limits = null,
    ): SessionPairHarness {
        $sessionLimits = $limits ?? self::limits();

        return new SessionPairHarness(
            new ConnectedSession($clock, 576, $sessionLimits),
            new ConnectedSession($clock, 576, $sessionLimits),
            $clock,
            $seed,
            $leftToRight ?? new ImpairmentProfile(),
            $rightToLeft ?? new ImpairmentProfile(),
            $dropRule,
        );
    }

    private static function limits(
        int $maximumAttempts = 12,
        int $maximumAgeNanoseconds = 5_000_000_000,
        int $maximumQueuedFrames = 4_096,
        int $maximumQueuedPayloadBytes = 1_048_576,
        int $maximumOutboundDatagrams = 4_096,
        int $maximumOutboundBytes = 4_194_304,
    ): ConnectedSessionLimits {
        return new ConnectedSessionLimits(
            reliability: new ReliabilityLimits(
                maximumAttempts: $maximumAttempts,
                maximumAgeNanoseconds: $maximumAgeNanoseconds,
                initialRtoNanoseconds: 20_000_000,
                minimumRtoNanoseconds: 10_000_000,
                maximumRtoNanoseconds: 100_000_000,
            ),
            maximumQueuedFrames: $maximumQueuedFrames,
            maximumQueuedPayloadBytes: $maximumQueuedPayloadBytes,
            maximumOutboundDatagrams: $maximumOutboundDatagrams,
            maximumOutboundBytes: $maximumOutboundBytes,
        );
    }

    private static function packetIdCount(SessionPairHarness $harness, string $direction, int $packetId): int
    {
        return \count(array_filter(
            $harness->transmissionLog(),
            static fn(array $entry): bool => $entry['direction'] === $direction && \ord($entry['payload'][0]) === $packetId,
        ));
    }

    private static function connectedTransmissionCount(SessionPairHarness $harness, string $direction): int
    {
        return \count(array_filter(
            $harness->transmissionLog(),
            static fn(array $entry): bool => $entry['direction'] === $direction
                && \ord($entry['payload'][0]) === ConnectedDatagram::VALID_FLAG
                && !$entry['duplicate'],
        ));
    }

    private static function unreliableDatagram(int $sequence, string $payload): ConnectedDatagram
    {
        return new ConnectedDatagram(
            ConnectedDatagram::VALID_FLAG,
            $sequence,
            [new EncapsulatedFrame(Reliability::Unreliable, new BitPayload($payload, \strlen($payload) * 8))],
        );
    }

    /**
     * @param list<string> $bytes
     * @return list<ConnectedDatagram>
     */
    private static function connectedDatagrams(array $bytes): array
    {
        $datagrams = [];
        foreach ($bytes as $packet) {
            if (ConnectedDatagram::acceptsFlags(\ord($packet[0]))) {
                $datagrams[] = ConnectedDatagram::decode($packet);
            }
        }

        return $datagrams;
    }

    /** @param list<ConnectedDatagram> $datagrams */
    private static function acknowledge(array $datagrams): AckPacket
    {
        if ($datagrams === []) {
            self::fail('Cannot acknowledge an empty datagram batch.');
        }

        return new AckPacket(array_map(
            static fn(ConnectedDatagram $datagram): SequenceRange => new SequenceRange(
                $datagram->sequenceNumber,
                $datagram->sequenceNumber,
            ),
            $datagrams,
        ));
    }
}
