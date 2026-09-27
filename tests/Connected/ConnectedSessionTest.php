<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Connected;

use Bedriox\RakNet\Connected\ConnectedSession;
use Bedriox\RakNet\Connected\ConnectedSessionLimits;
use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\AckPacket;
use Bedriox\RakNet\Protocol\BitPayload;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\EncapsulatedFrame;
use Bedriox\RakNet\Protocol\NackPacket;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\SequenceRange;
use Bedriox\RakNet\Protocol\SplitMetadata;
use Bedriox\RakNet\Reliability\ReliabilityLimits;
use Bedriox\RakNet\Tests\MutableClock;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ConnectedSessionTest extends TestCase
{
    public function testReliablePayloadRoundTripsAndAcknowledgementStopsRetry(): void
    {
        $clock = new MutableClock();
        $sender = new ConnectedSession($clock, 1_400);
        $receiver = new ConnectedSession($clock, 1_400);

        $sender->queuePayload('hello', Reliability::Reliable);
        $sender->tick();
        $senderEffects = $sender->drainEffects();
        $outbound = $senderEffects->outboundDatagrams;
        self::assertCount(1, $outbound);
        self::assertSame(0, $senderEffects->priorityOutboundDatagramCount);

        $receiver->receiveBytes($outbound[0]);
        $receiver->receiveBytes($outbound[0]);
        $received = $receiver->drainEffects()->payloads;
        self::assertCount(1, $received);
        self::assertSame('hello', $received[0]->payload);

        $receiver->tick();
        $receiverEffects = $receiver->drainEffects();
        $control = $receiverEffects->outboundDatagrams;
        self::assertCount(1, $control);
        self::assertSame(1, $receiverEffects->priorityOutboundDatagramCount);
        $sender->receiveBytes($control[0]);
        $clock->advanceMilliseconds(2_000);
        $sender->tick();
        self::assertSame([], $sender->drainEffects()->outboundDatagrams);
    }

    public function testReliableOrderedFragmentedPayloadIsReassembledAndDelivered(): void
    {
        $clock = new MutableClock();
        $sender = new ConnectedSession($clock, 576);
        $receiver = new ConnectedSession($clock, 576);
        $payload = str_repeat('x', 2_000);

        $sender->queuePayload($payload, Reliability::ReliableOrdered, 3);
        $sender->tick();
        $datagrams = $sender->drainEffects()->outboundDatagrams;
        self::assertGreaterThan(1, \count($datagrams));
        foreach (array_reverse($datagrams) as $datagram) {
            $receiver->receiveBytes($datagram);
        }

        $events = $receiver->drainEffects()->payloads;
        self::assertCount(1, $events);
        self::assertSame($payload, $events[0]->payload);
        self::assertSame(3, $events[0]->orderingChannel);
    }

    public function testNackPromptsExactFrameRetransmission(): void
    {
        $clock = new MutableClock();
        $session = new ConnectedSession($clock, 1_400);
        $session->queuePayload('retry-me', Reliability::ReliableOrdered, 1);
        $session->tick();
        $original = $session->drainEffects()->outboundDatagrams[0];
        $originalPacket = ConnectedDatagram::decode($original);

        $session->receive(new \Bedriox\RakNet\Protocol\NackPacket([
            new SequenceRange($originalPacket->sequenceNumber, $originalPacket->sequenceNumber),
        ]));
        $session->tick();
        $effects = $session->drainEffects();
        $retry = ConnectedDatagram::decode($effects->outboundDatagrams[0]);

        self::assertNotSame($originalPacket->sequenceNumber, $retry->sequenceNumber);
        self::assertEquals($originalPacket->frames[0], $retry->frames[0]);
        self::assertSame(0, $effects->priorityOutboundDatagramCount);
    }

    public function testUnsupportedReliabilityIsRejectedForSendAndIgnoredForReceive(): void
    {
        $clock = new MutableClock();
        $session = new ConnectedSession($clock, 1_400);
        $this->expectException(InvalidArgumentException::class);
        $session->queuePayload('no', Reliability::UnreliableSequenced);
    }

    public function testClearReleasesStateAndClosesSession(): void
    {
        $session = new ConnectedSession(new MutableClock(), 1_400);
        $session->queuePayload('pending', Reliability::Reliable);
        $session->clear();
        self::assertSame([], $session->drainEffects()->outboundDatagrams);

        $this->expectException(LogicException::class);
        $session->tick();
    }

    public function testAckObjectCanBeReceivedDirectly(): void
    {
        $clock = new MutableClock();
        $session = new ConnectedSession($clock, 1_400);
        $session->queuePayload('payload', Reliability::Reliable);
        $session->tick();
        $sequence = ConnectedDatagram::decode($session->drainEffects()->outboundDatagrams[0])->sequenceNumber;
        $session->receive(new AckPacket([new SequenceRange($sequence, $sequence)]));
        $clock->advanceMilliseconds(2_000);
        $session->tick();
        self::assertSame([], $session->drainEffects()->outboundDatagrams);
    }

    public function testUnsupportedInboundModeFailsClosedBeforeAcknowledgement(): void
    {
        foreach (
            [
                Reliability::UnreliableSequenced,
                Reliability::ReliableSequenced,
                Reliability::UnreliableWithAckReceipt,
                Reliability::ReliableWithAckReceipt,
                Reliability::ReliableOrderedWithAckReceipt,
            ] as $mode
        ) {
            $session = new ConnectedSession(new MutableClock(), 1_400);
            $frame = new EncapsulatedFrame(
                $mode,
                new BitPayload('x', 8),
                $mode->hasReliableIndex() ? 0 : null,
                $mode->hasSequenceIndex() ? 0 : null,
                $mode->hasOrdering() ? 0 : null,
                $mode->hasOrdering() ? 0 : null,
            );
            try {
                $session->receive(new ConnectedDatagram(0x80, 0, [$frame]));
                self::fail('Unsupported inbound reliability was accepted.');
            } catch (UnexpectedValueException) {
                self::assertSame([], $session->drainEffects()->outboundDatagrams);
                $this->expectClosed($session);
            }
        }
    }

    public function testNonByteAlignedReliablePayloadFailsClosed(): void
    {
        $session = new ConnectedSession(new MutableClock(), 1_400);
        $frame = new EncapsulatedFrame(Reliability::Reliable, new BitPayload("\x80", 1), 0);

        try {
            $session->receive(new ConnectedDatagram(0x80, 0, [$frame]));
            self::fail('Non-byte payload was accepted.');
        } catch (UnexpectedValueException) {
            self::assertSame([], $session->drainEffects()->outboundDatagrams);
            $this->expectClosed($session);
        }
    }

    public function testFragmentAssemblyCapacityFailsClosed(): void
    {
        $limits = new ConnectedSessionLimits(maximumActiveAssemblies: 1);
        $session = new ConnectedSession(new MutableClock(), 1_400, $limits);
        $session->receive(new ConnectedDatagram(0x80, 0, [new EncapsulatedFrame(
            Reliability::Reliable,
            new BitPayload('a', 8),
            0,
            split: new SplitMetadata(2, 1, 0),
        )]));

        $this->expectException(OverflowException::class);
        $session->receive(new ConnectedDatagram(0x80, 1, [new EncapsulatedFrame(
            Reliability::Reliable,
            new BitPayload('b', 8),
            1,
            split: new SplitMetadata(2, 2, 0),
        )]));
    }

    public function testOrderedBufferCapacityFailsClosed(): void
    {
        $limits = new ConnectedSessionLimits(maximumOrderedPayloadsPerChannel: 1);
        $session = new ConnectedSession(new MutableClock(), 1_400, $limits);
        foreach ([1 => 'one', 2 => 'two'] as $index => $payload) {
            try {
                $session->receive(new ConnectedDatagram(0x80, $index, [new EncapsulatedFrame(
                    Reliability::ReliableOrdered,
                    new BitPayload($payload, \strlen($payload) * 8),
                    $index,
                    orderingIndex: $index,
                    orderingChannel: 0,
                )]));
            } catch (OverflowException) {
                self::assertSame(2, $index);
                $this->expectClosed($session);

                return;
            }
        }
        self::fail('Ordered capacity exhaustion did not fail closed.');
    }

    public function testRetrySupersedesSaturatedHistory(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(reliability: new ReliabilityLimits(
            maximumSentDatagrams: 1,
            maximumHistoryReferences: 1,
        ), maximumReliableDatagramsInFlight: 1);
        $session = new ConnectedSession($clock, 1_400, $limits);
        $session->queuePayload('retry', Reliability::Reliable);
        $session->tick();
        $first = ConnectedDatagram::decode($session->drainEffects()->outboundDatagrams[0]);

        $clock->advanceMilliseconds(500);
        $session->tick();
        $second = ConnectedDatagram::decode($session->drainEffects()->outboundDatagrams[0]);
        self::assertNotSame($first->sequenceNumber, $second->sequenceNumber);
        self::assertEquals($first->frames[0], $second->frames[0]);
    }

    public function testSplitIdsAreLeasedAcrossWrap(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            fragmentLifetimeNanoseconds: 1_000_000,
            maximumOutboundSplitLeases: 2,
        );
        $session = new ConnectedSession($clock, 576, $limits, initialSplitId: 0xffff);
        $session->queuePayload(str_repeat('a', 600), Reliability::Reliable);
        $session->queuePayload(str_repeat('b', 600), Reliability::Reliable);
        try {
            $session->queuePayload(str_repeat('c', 600), Reliability::Reliable);
            self::fail('Split lease capacity was not enforced.');
        } catch (OverflowException) {
        }

        $session->tick();
        $ids = [];
        $lastSequence = 0;
        foreach ($session->drainEffects()->outboundDatagrams as $bytes) {
            $datagram = ConnectedDatagram::decode($bytes);
            $lastSequence = $datagram->sequenceNumber;
            $split = $datagram->frames[0]->split;
            if ($split !== null) {
                $ids[$split->id] = true;
            }
        }
        self::assertSame([0xffff, 0], array_keys($ids));

        $session->receive(new AckPacket([new SequenceRange(0, $lastSequence)]));
        $clock->advanceMilliseconds(1);
        $session->tick();
        $session->drainEffects();
        $session->queuePayload(str_repeat('c', 600), Reliability::Reliable);
    }

    public function testWireAndOutputLimitsAreValidatedAtConstruction(): void
    {
        try {
            new ConnectedSessionLimits(reliability: new ReliabilityLimits(maximumAcknowledgementSequences: 8_193));
            self::fail('Oversized acknowledgement capacity was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new ConnectedSessionLimits(maximumOutboundBytes: 7);
    }

    public function testOutboundDatagramAndReliableIndexesWrapIndependently(): void
    {
        $session = new ConnectedSession(
            new MutableClock(),
            1_400,
            initialDatagramSequence: 0xff_ffff,
            initialReliableIndex: 0xff_ffff,
        );
        $session->queuePayload('maximum', Reliability::Reliable);
        $session->queuePayload('zero', Reliability::Reliable);
        $session->tick();
        $outbound = $session->drainEffects()->outboundDatagrams;

        $first = ConnectedDatagram::decode($outbound[0]);
        $second = ConnectedDatagram::decode($outbound[1]);
        self::assertSame(0xff_ffff, $first->sequenceNumber);
        self::assertSame(0xff_ffff, $first->frames[0]->reliableIndex);
        self::assertSame(0, $second->sequenceNumber);
        self::assertSame(0, $second->frames[0]->reliableIndex);
    }

    public function testControlRangesArePacketizedWithoutCrossingRecordOrMtuLimits(): void
    {
        $session = new ConnectedSession(new MutableClock(), 1_492);
        $frame = new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('x', 8));
        for ($sequence = 0; $sequence < 440; $sequence += 2) {
            $session->receive(new ConnectedDatagram(0x80, $sequence, [$frame]));
        }

        $session->tick();
        $acknowledged = 0;
        $missing = 0;
        foreach ($session->drainEffects()->outboundDatagrams as $packet) {
            self::assertLessThanOrEqual(1_464, \strlen($packet));
            if (\ord($packet[0]) === AcknowledgementCodec::ACK_ID) {
                $ranges = AckPacket::decode($packet)->ranges;
                $acknowledged += array_sum(array_map(static fn(SequenceRange $range): int => $range->span(), $ranges));
            } else {
                $ranges = NackPacket::decode($packet)->ranges;
                $missing += array_sum(array_map(static fn(SequenceRange $range): int => $range->span(), $ranges));
            }
            self::assertLessThanOrEqual(AcknowledgementCodec::MAXIMUM_RECORDS, \count($ranges));
        }
        self::assertSame(220, $acknowledged);
        self::assertSame(219, $missing);
    }

    public function testOversizedUnreliableIsRejectedAndUnsplitBackpressureIsLossless(): void
    {
        $limits = new ConnectedSessionLimits(maximumOutboundDatagrams: 1, maximumOutboundBytes: 1_400);
        $session = new ConnectedSession(new MutableClock(), 576, $limits);
        try {
            $session->queuePayload(str_repeat('x', 542), Reliability::Unreliable);
            self::fail('Fragmented unreliable payload was accepted.');
        } catch (InvalidArgumentException) {
        }

        $session->queuePayload('first', Reliability::Unreliable);
        $session->queuePayload('second', Reliability::Unreliable);
        $session->tick();
        $session->tick();
        $first = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $first);
        self::assertSame('first', ConnectedDatagram::decode($first[0])->frames[0]->payload->bytes);

        $session->tick();
        $second = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $second);
        self::assertSame('second', ConnectedDatagram::decode($second[0])->frames[0]->payload->bytes);
    }

    public function testInboundUnreliableFragmentationFailsClosed(): void
    {
        $session = new ConnectedSession(new MutableClock(), 1_400);
        $frame = new EncapsulatedFrame(
            Reliability::Unreliable,
            new BitPayload('x', 8),
            split: new SplitMetadata(2, 1, 0),
        );

        $this->expectException(UnexpectedValueException::class);
        $session->receive(new ConnectedDatagram(0x80, 0, [$frame]));
    }

    public function testReliableWindowRetainsQueuedDataUntilAcknowledgement(): void
    {
        $limits = new ConnectedSessionLimits(
            maximumReliableDatagramsInFlight: 2,
            maximumDatagramsPerTick: 10,
        );
        $session = new ConnectedSession(new MutableClock(), 1_400, $limits);
        foreach (['one', 'two', 'three'] as $payload) {
            $session->queuePayload($payload, Reliability::Reliable);
        }

        $session->tick();
        $first = $session->drainEffects()->outboundDatagrams;
        self::assertCount(2, $first);
        $acknowledgedSequence = ConnectedDatagram::decode($first[0])->sequenceNumber;
        $session->receive(new AckPacket([new SequenceRange($acknowledgedSequence, $acknowledgedSequence)]));
        $session->tick();
        $resumed = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $resumed);
        self::assertSame('three', ConnectedDatagram::decode($resumed[0])->frames[0]->payload->bytes);
    }

    public function testPermanentLossRetriesWithoutExceedingReliableWindow(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            maximumReliableDatagramsInFlight: 4,
            maximumDatagramsPerTick: 100,
        );
        $session = new ConnectedSession($clock, 1_400, $limits);
        for ($index = 0; $index < 10; ++$index) {
            $session->queuePayload("lost-$index", Reliability::Reliable);
        }

        $session->tick();
        self::assertCount(4, $session->drainEffects()->outboundDatagrams);
        foreach ([500, 1_000, 2_000] as $milliseconds) {
            $clock->advanceMilliseconds($milliseconds);
            $session->tick();
            self::assertLessThanOrEqual(4, \count($session->drainEffects()->outboundDatagrams));
        }
    }

    public function testDelayedAcknowledgementReleasesOneReliableWindowSlotAfterRetry(): void
    {
        $clock = new MutableClock();
        $limits = new ConnectedSessionLimits(
            maximumReliableDatagramsInFlight: 2,
            maximumDatagramsPerTick: 10,
        );
        $session = new ConnectedSession($clock, 1_400, $limits);
        foreach (['one', 'two', 'three'] as $payload) {
            $session->queuePayload($payload, Reliability::Reliable);
        }

        $session->tick();
        $initial = $session->drainEffects()->outboundDatagrams;
        self::assertCount(2, $initial);
        $firstSequence = ConnectedDatagram::decode($initial[0])->sequenceNumber;

        $clock->advanceMilliseconds(500);
        $session->tick();
        self::assertCount(2, $session->drainEffects()->outboundDatagrams);

        $session->receive(new AckPacket([new SequenceRange($firstSequence, $firstSequence)]));
        $session->tick();
        $resumed = $session->drainEffects()->outboundDatagrams;
        self::assertCount(1, $resumed);
        self::assertSame('three', ConnectedDatagram::decode($resumed[0])->frames[0]->payload->bytes);
    }

    public function testPerTickDataBudgetDoesNotConsumeControlCapacity(): void
    {
        $limits = new ConnectedSessionLimits(
            maximumOutboundDatagrams: 16,
            maximumDatagramsPerTick: 3,
        );
        $session = new ConnectedSession(new MutableClock(), 1_400, $limits);
        $frame = new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('inbound', 56));
        $session->receive(new ConnectedDatagram(0x80, 0, [$frame]));
        for ($index = 0; $index < 10; ++$index) {
            $session->queuePayload("queued-$index", Reliability::Unreliable);
        }

        $session->tick();
        $outbound = $session->drainEffects()->outboundDatagrams;
        self::assertCount(4, $outbound);
        self::assertSame(AcknowledgementCodec::ACK_ID, \ord($outbound[0][0]));
        self::assertSame(
            ['queued-0', 'queued-1', 'queued-2'],
            array_map(
                static fn(string $bytes): string => ConnectedDatagram::decode($bytes)->frames[0]->payload->bytes,
                \array_slice($outbound, 1),
            ),
        );
    }

    private function expectClosed(ConnectedSession $session): void
    {
        try {
            $session->tick();
            self::fail('Session remained open after a fatal admission failure.');
        } catch (LogicException) {
        }
    }
}
