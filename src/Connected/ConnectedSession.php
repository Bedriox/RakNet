<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Clock;
use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\AckPacket;
use Bedriox\RakNet\Protocol\BitPayload;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\EncapsulatedFrame;
use Bedriox\RakNet\Protocol\NackPacket;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\SequenceRange as ProtocolSequenceRange;
use Bedriox\RakNet\Protocol\SplitMetadata;
use Bedriox\RakNet\Reliability\AcknowledgementAccumulator;
use Bedriox\RakNet\Reliability\ReceiveSequenceWindow;
use Bedriox\RakNet\Reliability\ReliableFrameTracker;
use Bedriox\RakNet\Reliability\ReliableMessageWindow;
use Bedriox\RakNet\Reliability\Sequence24;
use Bedriox\RakNet\Reliability\SequenceRange;
use Bedriox\RakNet\Session\Fragment;
use Bedriox\RakNet\Session\FragmentAcceptanceStatus;
use Bedriox\RakNet\Session\FragmentReassembler;
use Bedriox\RakNet\Session\OrderedDeliveryBuffer;
use Bedriox\RakNet\Session\OrderedDeliveryStatus;
use Bedriox\RakNet\Session\OrderedPayload;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use UnexpectedValueException;

/**
 * Deterministic single-owner connected RakNet state machine.
 *
 * Socket ownership and application-protocol decoding deliberately remain out
 * of this class. Callers feed packets and drain immutable effects.
 */
final class ConnectedSession
{
    private const int IPV4_UDP_OVERHEAD = 28;
    private const int MINIMUM_IPV4_MTU = 576;

    private readonly ConnectedSessionLimits $limits;
    private readonly int $udpPayloadBudget;
    private ?ReceiveSequenceWindow $receiveWindow;
    private ?ReliableMessageWindow $reliableWindow;
    private ?AcknowledgementAccumulator $acknowledgements;
    private ?FragmentReassembler $reassembler;
    private ?OrderedDeliveryBuffer $orderedBuffer;
    private ?ReliableFrameTracker $tracker;

    /** @var array<int, EncapsulatedFrame> */
    private array $outboundFrames = [];
    private int $outboundFrameBytes = 0;
    /** @var array<int, int> */
    private array $retryQueue = [];
    /** @var array<int, true> */
    private array $retryQueued = [];
    /** @var array<int, EncapsulatedFrame> */
    private array $reliableEnvelopes = [];
    /** @var array<int, SplitContext> */
    private array $splitContexts = [];
    /** @var array<int, array{remaining: array<int, true>, expiresAtNanoseconds: int}> */
    private array $outboundSplitLeases = [];
    /** @var array<int, int> */
    private array $splitLeaseByReliableIndex = [];
    /** @var list<ConnectedPayloadEvent> */
    private array $payloadEffects = [];
    private int $payloadEffectBytes = 0;
    /** @var list<string> */
    private array $outboundEffects = [];
    private int $outboundEffectBytes = 0;
    /** @var list<int> */
    private array $expiredEffects = [];
    /** @var array<int, int> */
    private array $nextOrderingIndices;
    private int $nextDatagramSequence;
    private int $nextReliableIndex;
    private int $nextSplitId;
    private ?int $lastObservedTime = null;
    private bool $closed = false;
    private bool $preferNegativeAcknowledgement = false;
    private int $dataDatagramsEmittedThisTick = 0;

    /** @param array<int, int> $initialOrderingIndices */
    public function __construct(
        private readonly Clock $clock,
        int $negotiatedMtu,
        ?ConnectedSessionLimits $limits = null,
        int $initialDatagramSequence = 0,
        int $initialReliableIndex = 0,
        int $initialSplitId = 0,
        array $initialOrderingIndices = [],
    ) {
        if ($negotiatedMtu < self::MINIMUM_IPV4_MTU || $negotiatedMtu > ConnectedDatagram::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('Negotiated IPv4 MTU must be between 576 and 1492 bytes.');
        }
        Sequence24::validate($initialDatagramSequence);
        Sequence24::validate($initialReliableIndex);
        if ($initialSplitId < 0 || $initialSplitId > 0xffff) {
            throw new InvalidArgumentException('Initial split ID must fit in an unsigned 16-bit integer.');
        }

        $this->limits = $limits ?? new ConnectedSessionLimits();
        $this->udpPayloadBudget = $negotiatedMtu - self::IPV4_UDP_OVERHEAD;
        $this->nextDatagramSequence = $initialDatagramSequence;
        $this->nextReliableIndex = $initialReliableIndex;
        $this->nextSplitId = $initialSplitId;
        $this->nextOrderingIndices = array_fill(0, OrderedPayload::CHANNEL_COUNT, 0);
        foreach ($initialOrderingIndices as $channel => $index) {
            if ($channel < 0 || $channel >= OrderedPayload::CHANNEL_COUNT) {
                throw new InvalidArgumentException('Initial ordering channel is out of range.');
            }
            $this->nextOrderingIndices[$channel] = Sequence24::validate($index);
        }

        $reliability = $this->limits->reliability;
        $this->receiveWindow = new ReceiveSequenceWindow($reliability->receiveWindowSize);
        $this->reliableWindow = new ReliableMessageWindow($reliability->receiveWindowSize);
        $this->acknowledgements = new AcknowledgementAccumulator($reliability->maximumAcknowledgementSequences);
        $this->reassembler = new FragmentReassembler(
            $clock,
            $this->limits->fragmentLifetimeNanoseconds,
            $this->limits->maximumActiveAssemblies,
            $this->limits->maximumFragmentsPerAssembly,
            $this->limits->maximumBytesPerAssembly,
            $this->limits->maximumAggregateFragmentBytes,
        );
        $this->orderedBuffer = new OrderedDeliveryBuffer(
            $this->limits->maximumOrderedPayloadsPerChannel,
            $this->limits->maximumOrderedBytesPerChannel,
        );
        $this->tracker = new ReliableFrameTracker($clock, $reliability);
    }

    public function receiveBytes(string $bytes): void
    {
        $this->ensureOpen();
        $length = \strlen($bytes);
        if ($length < 1 || $length > $this->udpPayloadBudget) {
            throw new InvalidArgumentException('Inbound datagram exceeds the negotiated UDP payload budget.');
        }

        $packet = match (\ord($bytes[0])) {
            AcknowledgementCodec::ACK_ID => AckPacket::decode($bytes),
            AcknowledgementCodec::NACK_ID => NackPacket::decode($bytes),
            default => ConnectedDatagram::decode($bytes),
        };
        $this->receive($packet);
    }

    public function receive(ConnectedDatagram|AckPacket|NackPacket $packet): void
    {
        $this->ensureOpen();
        if (\strlen($packet->encode()) > $this->udpPayloadBudget) {
            throw new InvalidArgumentException('Inbound packet exceeds the negotiated UDP payload budget.');
        }
        if ($packet instanceof AckPacket) {
            $this->processAcknowledgement($packet->ranges, false);

            return;
        }
        if ($packet instanceof NackPacket) {
            $this->processAcknowledgement($packet->ranges, true);

            return;
        }
        $this->processConnectedDatagram($packet);
    }

    public function queuePayload(
        string $payload,
        Reliability $reliability,
        int $orderingChannel = 0,
    ): void {
        $this->ensureOpen();
        $payloadBytes = \strlen($payload);
        if ($payloadBytes < 1 || $payloadBytes > $this->limits->maximumApplicationPayloadBytes) {
            throw new InvalidArgumentException('Application payload length is out of range.');
        }
        if (!$this->isSupported($reliability)) {
            throw new InvalidArgumentException('Reliability mode is not supported by this connected session.');
        }
        if ($orderingChannel < 0 || $orderingChannel >= OrderedPayload::CHANNEL_COUNT) {
            throw new InvalidArgumentException('Ordering channel must be between 0 and 31.');
        }

        $orderIndex = null;
        if ($reliability === Reliability::ReliableOrdered) {
            $orderIndex = $this->nextOrderingIndices[$orderingChannel];
        }
        $previousReliableIndex = $this->nextReliableIndex;
        $previousSplitId = $this->nextSplitId;
        $frames = $this->createOutboundFrames($payload, $reliability, $orderIndex, $orderingChannel);
        if (
            \count($frames) > $this->limits->maximumQueuedFrames - \count($this->outboundFrames)
            || $payloadBytes > $this->limits->maximumQueuedPayloadBytes - $this->outboundFrameBytes
        ) {
            $this->nextReliableIndex = $previousReliableIndex;
            $this->nextSplitId = $previousSplitId;
            throw new OverflowException('Outbound frame queue limit reached.');
        }
        $reliableFrameCount = $reliability->hasReliableIndex() ? \count($frames) : 0;
        if (
            $reliableFrameCount > $this->limits->reliability->maximumTrackedFrames - $this->tracker()->pendingCount()
            || ($reliableFrameCount > 0
                && $payloadBytes > $this->limits->reliability->maximumTrackedPayloadBytes - $this->tracker()->pendingPayloadBytes())
        ) {
            $this->nextReliableIndex = $previousReliableIndex;
            $this->nextSplitId = $previousSplitId;
            throw new OverflowException('Reliable frame tracking limit reached.');
        }

        foreach ($frames as $frame) {
            if ($frame->reliableIndex !== null) {
                $this->tracker()->track($frame->reliableIndex, $frame->payload->bytes);
                $this->reliableEnvelopes[$frame->reliableIndex] = $frame;
            }
            $this->outboundFrames[] = $frame;
        }
        $split = $frames[0]->split ?? null;
        if ($split !== null) {
            $this->leaseOutboundSplit($split->id, $frames);
        }
        $this->outboundFrameBytes += $payloadBytes;
        if ($reliability === Reliability::ReliableOrdered) {
            $this->nextOrderingIndices[$orderingChannel] = Sequence24::increment($orderIndex);
        }
    }

    public function tick(): void
    {
        $this->ensureOpen();
        $this->dataDatagramsEmittedThisTick = 0;
        $this->pruneSplitContexts();
        $this->reassembler()->expire();

        $decision = $this->tracker()->collectDueRetries();
        if (\count($decision->expiredReliableIndices) > $this->limits->reliability->maximumTrackedFrames - \count($this->expiredEffects)) {
            $this->clear();
            throw new OverflowException('Undrained reliable-expiry effect limit reached; session was cleared.');
        }
        foreach ($decision->expiredReliableIndices as $index) {
            unset($this->reliableEnvelopes[$index], $this->retryQueued[$index]);
            $this->releaseReliableSplitLease($index);
            $this->expiredEffects[] = $index;
        }
        if ($decision->expiredReliableIndices !== []) {
            $expired = array_fill_keys($decision->expiredReliableIndices, true);
            $this->outboundFrames = array_values(array_filter(
                $this->outboundFrames,
                static fn(EncapsulatedFrame $frame): bool => $frame->reliableIndex === null || !isset($expired[$frame->reliableIndex]),
            ));
            $this->retryQueue = array_values(array_filter(
                $this->retryQueue,
                static fn(int $index): bool => !isset($expired[$index]),
            ));
            $this->recountOutboundFrameBytes();
        }
        foreach ($decision->due as $frame) {
            $index = $frame->reliableIndex;
            if (!isset($this->reliableEnvelopes[$index], $this->retryQueued[$index])) {
                $this->retryQueue[] = $index;
                $this->retryQueued[$index] = true;
            }
        }

        $this->flushControlPackets();
        $this->flushRetries();
        $this->flushOutboundFrames();
    }

    public function drainEffects(): ConnectedSessionEffects
    {
        // Ownership of every outbound datagram transfers to the adapter. A
        // transient send failure must retain and retry the entire UDP payload;
        // a partial UDP write or permanent failure must close the session.
        $effects = new ConnectedSessionEffects(
            $this->payloadEffects,
            $this->outboundEffects,
            $this->expiredEffects,
        );
        $this->payloadEffects = [];
        $this->payloadEffectBytes = 0;
        $this->outboundEffects = [];
        $this->outboundEffectBytes = 0;
        $this->expiredEffects = [];

        return $effects;
    }

    public function clear(): void
    {
        if ($this->closed) {
            return;
        }
        $this->reassembler?->clear();
        $this->orderedBuffer?->clear();
        $this->receiveWindow = null;
        $this->reliableWindow = null;
        $this->acknowledgements = null;
        $this->reassembler = null;
        $this->orderedBuffer = null;
        $this->tracker = null;
        $this->outboundFrames = [];
        $this->retryQueue = [];
        $this->retryQueued = [];
        $this->reliableEnvelopes = [];
        $this->splitContexts = [];
        $this->outboundSplitLeases = [];
        $this->splitLeaseByReliableIndex = [];
        $this->payloadEffects = [];
        $this->outboundEffects = [];
        $this->expiredEffects = [];
        $this->outboundFrameBytes = $this->payloadEffectBytes = $this->outboundEffectBytes = 0;
        $this->closed = true;
    }

    private function processConnectedDatagram(ConnectedDatagram $datagram): void
    {
        try {
            try {
                [$receiveWindow, $acknowledgements, $result] = $this->previewDatagramAdmission($datagram->sequenceNumber);
            } catch (OverflowException) {
                $before = $this->acknowledgements()->acknowledgementCount()
                    + $this->acknowledgements()->negativeAcknowledgementCount();
                $this->flushControlPackets();
                $after = $this->acknowledgements()->acknowledgementCount()
                    + $this->acknowledgements()->negativeAcknowledgementCount();
                if ($after >= $before) {
                    throw new OverflowException('Acknowledgement state cannot admit the inbound datagram.');
                }
                [$receiveWindow, $acknowledgements, $result] = $this->previewDatagramAdmission($datagram->sequenceNumber);
            }

            if (!$result->observation->isAccepted()) {
                $this->receiveWindow = $receiveWindow;
                $this->acknowledgements = $acknowledgements;

                return;
            }

            foreach ($datagram->frames as $frame) {
                $this->processFrame($frame);
            }
            $this->receiveWindow = $receiveWindow;
            $this->acknowledgements = $acknowledgements;
        } catch (OverflowException|UnexpectedValueException $exception) {
            $this->clear();
            throw $exception;
        }
    }

    /** @return array{ReceiveSequenceWindow, AcknowledgementAccumulator, \Bedriox\RakNet\Reliability\ReceiveSequenceResult} */
    private function previewDatagramAdmission(int $sequence): array
    {
        $receiveWindow = clone $this->receiveWindow();
        $acknowledgements = clone $this->acknowledgements();
        $result = $receiveWindow->observe($sequence);
        if ($result->observation->isAccepted() || $result->observation->name === 'Duplicate') {
            $acknowledgements->acknowledge($sequence);
        }
        if ($result->observation->isAccepted()) {
            foreach ($result->missingSequences as $missing) {
                $acknowledgements->negativeAcknowledge($missing);
            }
        }

        return [$receiveWindow, $acknowledgements, $result];
    }

    private function processFrame(EncapsulatedFrame $frame): void
    {
        if (!$this->isSupported($frame->reliability)) {
            throw new UnexpectedValueException('Unsupported inbound reliability mode closed the connected session.');
        }
        if (($frame->payload->bitLength & 7) !== 0) {
            throw new UnexpectedValueException('Non-byte-aligned inbound payload closed the connected session.');
        }
        if ($frame->split !== null && $frame->reliability === Reliability::Unreliable) {
            throw new UnexpectedValueException('Inbound unreliable fragmentation is not supported.');
        }
        if ($frame->reliableIndex !== null && !$this->reliableWindow()->observe($frame->reliableIndex)->isAccepted()) {
            return;
        }

        if ($frame->split === null) {
            $this->deliver($frame->payload->bytes, $frame->reliability, $frame->orderingIndex, $frame->orderingChannel);

            return;
        }
        if ($frame->split->count > $this->limits->maximumFragmentsPerAssembly) {
            throw new OverflowException('Inbound split count exceeds the configured assembly limit.');
        }

        $split = $frame->split;
        $context = $this->splitContexts[$split->id] ?? null;
        if ($context === null) {
            if (\count($this->splitContexts) >= $this->limits->maximumActiveAssemblies) {
                throw new OverflowException('Inbound fragment assembly capacity was exhausted.');
            }
            $now = $this->now();
            $expiresAt = $now > PHP_INT_MAX - $this->limits->fragmentLifetimeNanoseconds
                ? PHP_INT_MAX
                : $now + $this->limits->fragmentLifetimeNanoseconds;
            $context = new SplitContext(
                $split->count,
                $frame->reliability,
                $frame->orderingIndex,
                $frame->orderingChannel,
                $expiresAt,
            );
            $this->splitContexts[$split->id] = $context;
        } elseif (!$context->matches($split->count, $frame->reliability, $frame->orderingIndex, $frame->orderingChannel)) {
            $this->reassembler()->remove($split->id);
            unset($this->splitContexts[$split->id]);
            throw new UnexpectedValueException('Conflicting split metadata closed the connected session.');
        }

        $acceptance = $this->reassembler()->acceptDetailed(
            new Fragment($split->id, $split->count, $split->index, $frame->payload->bytes),
        );
        if ($acceptance->status === FragmentAcceptanceStatus::Completed && $acceptance->payload !== null) {
            unset($this->splitContexts[$split->id]);
            $this->deliver($acceptance->payload, $context->reliability, $context->orderingIndex, $context->orderingChannel);
        } elseif ($acceptance->status === FragmentAcceptanceStatus::RejectedCapacity) {
            unset($this->splitContexts[$split->id]);
            throw new OverflowException('Fragment reassembly capacity was exhausted.');
        } elseif ($acceptance->status === FragmentAcceptanceStatus::RejectedConflict) {
            unset($this->splitContexts[$split->id]);
            throw new UnexpectedValueException('Conflicting fragment data closed the connected session.');
        }
    }

    private function deliver(string $payload, Reliability $reliability, ?int $orderIndex, ?int $channel): void
    {
        if ($reliability === Reliability::ReliableOrdered) {
            if ($orderIndex === null || $channel === null) {
                return;
            }
            $result = $this->orderedBuffer()->acceptDetailed(new OrderedPayload($channel, $orderIndex, $payload));
            if ($result->status === OrderedDeliveryStatus::RejectedCapacity) {
                throw new OverflowException('Ordered-delivery capacity was exhausted.');
            }
            if ($result->status === OrderedDeliveryStatus::RejectedConflict) {
                throw new UnexpectedValueException('Conflicting ordered payload closed the connected session.');
            }
            foreach ($result->payloads as $ordered) {
                $this->appendPayloadEffect(new ConnectedPayloadEvent($ordered, $reliability, $channel));
            }

            return;
        }
        $this->appendPayloadEffect(new ConnectedPayloadEvent($payload, $reliability, null));
    }

    private function appendPayloadEffect(ConnectedPayloadEvent $event): void
    {
        $bytes = \strlen($event->payload);
        if (
            \count($this->payloadEffects) >= $this->limits->maximumDeliveredPayloads
            || $bytes > $this->limits->maximumDeliveredPayloadBytes - $this->payloadEffectBytes
        ) {
            $this->clear();
            throw new OverflowException('Undrained delivered-payload effect limit reached; session was cleared.');
        }
        $this->payloadEffects[] = $event;
        $this->payloadEffectBytes += $bytes;
    }

    /** @param list<ProtocolSequenceRange> $ranges */
    private function processAcknowledgement(array $ranges, bool $negative): void
    {
        foreach ($ranges as $range) {
            for ($sequence = $range->start; $sequence <= $range->end; ++$sequence) {
                if ($negative) {
                    $this->tracker()->negativeAcknowledgeDatagram($sequence);
                    continue;
                }
                foreach ($this->tracker()->acknowledgeDatagram($sequence) as $index) {
                    unset($this->reliableEnvelopes[$index], $this->retryQueued[$index]);
                    $this->releaseReliableSplitLease($index);
                }
            }
        }
        if (!$negative && $this->retryQueue !== []) {
            $this->retryQueue = array_values(array_filter(
                $this->retryQueue,
                fn(int $index): bool => isset($this->reliableEnvelopes[$index]),
            ));
        }
    }

    /** @return list<EncapsulatedFrame> */
    private function createOutboundFrames(
        string $payload,
        Reliability $reliability,
        ?int $orderIndex,
        int $channel,
    ): array {
        $baseHeader = 3 + ($reliability->hasReliableIndex() ? 3 : 0) + ($reliability->hasOrdering() ? 4 : 0);
        $unsplitCapacity = $this->udpPayloadBudget - 4 - $baseHeader;
        $splitCapacity = $unsplitCapacity - 10;
        if (\strlen($payload) > $unsplitCapacity && $reliability === Reliability::Unreliable) {
            throw new InvalidArgumentException('Unreliable payloads that require fragmentation are not supported.');
        }
        $chunks = \strlen($payload) <= $unsplitCapacity ? [$payload] : str_split($payload, $splitCapacity);
        if (\count($chunks) > $this->limits->maximumFragmentsPerAssembly) {
            throw new OverflowException('Payload requires too many fragments.');
        }
        $splitId = \count($chunks) > 1 ? $this->nextAvailableSplitId() : null;
        $nextReliable = $this->nextReliableIndex;
        $frames = [];
        foreach ($chunks as $index => $chunk) {
            $reliableIndex = $reliability->hasReliableIndex() ? $nextReliable : null;
            if ($reliableIndex !== null) {
                if (isset($this->reliableEnvelopes[$reliableIndex])) {
                    throw new OverflowException('Reliable index space collided with a pending frame.');
                }
                $nextReliable = Sequence24::increment($nextReliable);
            }
            $frames[] = new EncapsulatedFrame(
                $reliability,
                new BitPayload($chunk, \strlen($chunk) * 8),
                $reliableIndex,
                null,
                $orderIndex,
                $reliability->hasOrdering() ? $channel : null,
                $splitId === null ? null : new SplitMetadata(\count($chunks), $splitId, $index),
            );
        }
        $this->nextReliableIndex = $nextReliable;
        if ($splitId !== null) {
            $this->nextSplitId = ($splitId + 1) & 0xffff;
        }

        return $frames;
    }

    /** @param list<EncapsulatedFrame> $frames */
    private function leaseOutboundSplit(int $splitId, array $frames): void
    {
        $remaining = [];
        foreach ($frames as $frame) {
            if ($frame->reliableIndex !== null) {
                $remaining[$frame->reliableIndex] = true;
                $this->splitLeaseByReliableIndex[$frame->reliableIndex] = $splitId;
            }
        }
        if ($remaining === []) {
            throw new LogicException('Only reliable outbound fragments may lease split IDs.');
        }
        $this->outboundSplitLeases[$splitId] = [
            'remaining' => $remaining,
            'expiresAtNanoseconds' => PHP_INT_MAX,
        ];
    }

    private function releaseReliableSplitLease(int $reliableIndex): void
    {
        $splitId = $this->splitLeaseByReliableIndex[$reliableIndex] ?? null;
        if ($splitId === null) {
            return;
        }
        unset($this->splitLeaseByReliableIndex[$reliableIndex]);
        $lease = $this->outboundSplitLeases[$splitId] ?? null;
        if ($lease === null) {
            return;
        }
        unset($lease['remaining'][$reliableIndex]);
        if ($lease['remaining'] === []) {
            $now = $this->now();
            $lease['expiresAtNanoseconds'] = $now > PHP_INT_MAX - $this->limits->fragmentLifetimeNanoseconds
                ? PHP_INT_MAX
                : $now + $this->limits->fragmentLifetimeNanoseconds;
        }
        $this->outboundSplitLeases[$splitId] = $lease;
    }

    private function nextAvailableSplitId(): int
    {
        $this->pruneOutboundSplitLeases();
        if (\count($this->outboundSplitLeases) >= $this->limits->maximumOutboundSplitLeases) {
            throw new OverflowException('Outbound split-ID lease limit reached.');
        }
        $candidate = $this->nextSplitId;
        for ($attempt = 0; $attempt < 0x1_0000; ++$attempt) {
            if (!isset($this->outboundSplitLeases[$candidate])) {
                return $candidate;
            }
            $candidate = ($candidate + 1) & 0xffff;
        }
        throw new OverflowException('No outbound split ID is available.');
    }

    private function pruneOutboundSplitLeases(): void
    {
        $this->pruneOutboundSplitLeasesAt($this->now());
    }

    private function flushControlPackets(): void
    {
        $acknowledgements = $this->acknowledgements();
        while ($acknowledgements->acknowledgementCount() > 0 || $acknowledgements->negativeAcknowledgementCount() > 0) {
            $hasAcknowledgements = $acknowledgements->acknowledgementCount() > 0;
            $hasNegativeAcknowledgements = $acknowledgements->negativeAcknowledgementCount() > 0;
            $sendAcknowledgement = !$hasNegativeAcknowledgements
                || ($hasAcknowledgements && !$this->preferNegativeAcknowledgement);
            if (!$this->appendNextControlPacket($acknowledgements, $sendAcknowledgement)) {
                return;
            }
            if ($hasAcknowledgements && $hasNegativeAcknowledgements) {
                $this->preferNegativeAcknowledgement = !$this->preferNegativeAcknowledgement;
            }
        }
    }

    private function appendNextControlPacket(AcknowledgementAccumulator $acknowledgements, bool $ack): bool
    {
        $preview = clone $acknowledgements;
        $ranges = $ack
            ? $preview->drainAcknowledgements(AcknowledgementCodec::MAXIMUM_RECORDS, $this->udpPayloadBudget - 3)
            : $preview->drainNegativeAcknowledgements(AcknowledgementCodec::MAXIMUM_RECORDS, $this->udpPayloadBudget - 3);
        if ($ranges === []) {
            return false;
        }
        $wireRanges = array_map(
            static fn(SequenceRange $range): ProtocolSequenceRange => new ProtocolSequenceRange($range->start, $range->end),
            $ranges,
        );
        $encoded = ($ack ? new AckPacket($wireRanges) : new NackPacket($wireRanges))->encode();
        if (!$this->canAppendOutbound(1, \strlen($encoded))) {
            return false;
        }

        $ack
            ? $acknowledgements->drainAcknowledgements(AcknowledgementCodec::MAXIMUM_RECORDS, $this->udpPayloadBudget - 3)
            : $acknowledgements->drainNegativeAcknowledgements(AcknowledgementCodec::MAXIMUM_RECORDS, $this->udpPayloadBudget - 3);
        $this->appendOutbound($encoded);

        return true;
    }

    private function flushRetries(): void
    {
        while (
            $this->dataDatagramsEmittedThisTick < $this->limits->maximumDatagramsPerTick
            && ($key = array_key_first($this->retryQueue)) !== null
        ) {
            $index = $this->retryQueue[$key];
            $frame = $this->reliableEnvelopes[$index] ?? null;
            if ($frame === null) {
                unset($this->retryQueue[$key]);
                unset($this->retryQueued[$index]);
                continue;
            }
            $encoded = new ConnectedDatagram(ConnectedDatagram::VALID_FLAG, $this->nextDatagramSequence, [$frame])->encode();
            if (!$this->canAppendOutbound(1, \strlen($encoded))) {
                return;
            }
            $this->tracker()->recordTransmission($this->nextDatagramSequence, [$index]);
            $this->appendOutbound($encoded);
            ++$this->dataDatagramsEmittedThisTick;
            $this->nextDatagramSequence = Sequence24::increment($this->nextDatagramSequence);
            unset($this->retryQueue[$key]);
            unset($this->retryQueued[$index]);
        }
    }

    private function flushOutboundFrames(): void
    {
        while (
            $this->dataDatagramsEmittedThisTick < $this->limits->maximumDatagramsPerTick
            && ($key = array_key_first($this->outboundFrames)) !== null
        ) {
            $frame = $this->outboundFrames[$key];
            if (
                $frame->reliableIndex !== null
                && $this->tracker()->historyCount() >= $this->limits->maximumReliableDatagramsInFlight
            ) {
                return;
            }
            $encoded = new ConnectedDatagram(ConnectedDatagram::VALID_FLAG, $this->nextDatagramSequence, [$frame])->encode();
            if (!$this->canAppendOutbound(1, \strlen($encoded))) {
                return;
            }
            if ($frame->reliableIndex !== null) {
                try {
                    $this->tracker()->recordTransmission($this->nextDatagramSequence, [$frame->reliableIndex]);
                } catch (OverflowException) {
                    return;
                }
            }
            unset($this->outboundFrames[$key]);
            $this->outboundFrameBytes -= \strlen($frame->payload->bytes);
            $this->appendOutbound($encoded);
            ++$this->dataDatagramsEmittedThisTick;
            $this->nextDatagramSequence = Sequence24::increment($this->nextDatagramSequence);
        }
    }

    private function appendOutbound(string $datagram): void
    {
        if (!$this->canAppendOutbound(1, \strlen($datagram))) {
            throw new OverflowException('Undrained outbound effect limit reached.');
        }
        $this->outboundEffects[] = $datagram;
        $this->outboundEffectBytes += \strlen($datagram);
    }

    private function canAppendOutbound(int $count, int $bytes): bool
    {
        return $count <= $this->limits->maximumOutboundDatagrams - \count($this->outboundEffects)
            && $bytes <= $this->limits->maximumOutboundBytes - $this->outboundEffectBytes;
    }

    private function pruneSplitContexts(): void
    {
        $now = $this->now();
        $this->pruneOutboundSplitLeasesAt($now);
        foreach ($this->splitContexts as $id => $context) {
            if ($context->expiresAtNanoseconds <= $now) {
                $this->reassembler()->remove($id);
                unset($this->splitContexts[$id]);
            }
        }
    }

    private function pruneOutboundSplitLeasesAt(int $now): void
    {
        foreach ($this->outboundSplitLeases as $splitId => $lease) {
            if ($lease['remaining'] === [] && $lease['expiresAtNanoseconds'] <= $now) {
                unset($this->outboundSplitLeases[$splitId]);
            }
        }
    }

    private function recountOutboundFrameBytes(): void
    {
        $this->outboundFrameBytes = 0;
        foreach ($this->outboundFrames as $frame) {
            $this->outboundFrameBytes += \strlen($frame->payload->bytes);
        }
    }

    private function isSupported(Reliability $reliability): bool
    {
        return $reliability === Reliability::Unreliable
            || $reliability === Reliability::Reliable
            || $reliability === Reliability::ReliableOrdered;
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('Connected session is closed.');
        }
    }

    private function now(): int
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < 0 || ($this->lastObservedTime !== null && $now < $this->lastObservedTime)) {
            throw new LogicException('Connected-session clock must be nonnegative and monotonic.');
        }
        $this->lastObservedTime = $now;

        return $now;
    }

    private function receiveWindow(): ReceiveSequenceWindow
    {
        return $this->receiveWindow ?? throw new LogicException('Connected session is closed.');
    }

    private function reliableWindow(): ReliableMessageWindow
    {
        return $this->reliableWindow ?? throw new LogicException('Connected session is closed.');
    }

    private function acknowledgements(): AcknowledgementAccumulator
    {
        return $this->acknowledgements ?? throw new LogicException('Connected session is closed.');
    }

    private function reassembler(): FragmentReassembler
    {
        return $this->reassembler ?? throw new LogicException('Connected session is closed.');
    }

    private function orderedBuffer(): OrderedDeliveryBuffer
    {
        return $this->orderedBuffer ?? throw new LogicException('Connected session is closed.');
    }

    private function tracker(): ReliableFrameTracker
    {
        return $this->tracker ?? throw new LogicException('Connected session is closed.');
    }
}
