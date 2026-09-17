<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use Bedriox\RakNet\Clock;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/** Canonical reliable-frame ownership, history, acknowledgement, and retry state. */
final class ReliableFrameTracker
{
    /** @var array<int, PendingReliableFrame> */
    private array $pending = [];

    private int $pendingPayloadBytes = 0;
    private readonly SentDatagramHistory $history;
    private readonly RtoEstimator $rto;
    private ?int $lastObservedTime = null;

    public function __construct(
        private readonly Clock $clock,
        private readonly ReliabilityLimits $limits,
    ) {
        $this->history = new SentDatagramHistory(
            $limits->maximumSentDatagrams,
            $limits->maximumHistoryReferences,
        );
        $this->rto = new RtoEstimator(
            $limits->initialRtoNanoseconds,
            $limits->minimumRtoNanoseconds,
            $limits->maximumRtoNanoseconds,
        );
    }

    public function track(int $reliableIndex, string $payload): CanonicalReliableFrame
    {
        Sequence24::validate($reliableIndex);
        if (isset($this->pending[$reliableIndex])) {
            throw new InvalidArgumentException('Reliable frame index is already pending.');
        }

        $payloadBytes = \strlen($payload);
        if (
            \count($this->pending) >= $this->limits->maximumTrackedFrames
            || $payloadBytes > $this->limits->maximumTrackedPayloadBytes - $this->pendingPayloadBytes
        ) {
            throw new OverflowException('Reliable frame tracking limit reached.');
        }

        $frame = new CanonicalReliableFrame($reliableIndex, $payload, $this->now());
        $this->pending[$reliableIndex] = new PendingReliableFrame($frame);
        $this->pendingPayloadBytes += $payloadBytes;

        return $frame;
    }

    /** @param list<int> $reliableIndices */
    public function recordTransmission(int $datagramSequence, array $reliableIndices): SentDatagram
    {
        $now = $this->now();
        Sequence24::validate($datagramSequence);
        if ($reliableIndices === []) {
            throw new InvalidArgumentException('A transmission requires at least one reliable frame.');
        }

        $seen = [];
        $containsRetransmission = false;
        foreach ($reliableIndices as $reliableIndex) {
            Sequence24::validate($reliableIndex);
            if (isset($seen[$reliableIndex])) {
                throw new InvalidArgumentException('A transmission cannot contain a reliable frame twice.');
            }
            $seen[$reliableIndex] = true;
            $pending = $this->pending[$reliableIndex] ?? null;
            if ($pending === null) {
                throw new InvalidArgumentException('Transmission references an unknown reliable frame.');
            }
            if ($pending->attempts >= $this->limits->maximumAttempts) {
                throw new InvalidArgumentException('Reliable frame has exhausted its transmission attempts.');
            }
            if ($now - $pending->frame->createdAtNanoseconds >= $this->limits->maximumAgeNanoseconds) {
                throw new InvalidArgumentException('Reliable frame has exceeded its maximum age.');
            }
            $containsRetransmission = $containsRetransmission || $pending->attempts > 0;
        }

        $datagram = new SentDatagram($datagramSequence, $now, $reliableIndices, $containsRetransmission);
        if ($containsRetransmission) {
            $this->history->supersedeAndAdd($datagram);
        } else {
            $this->history->add($datagram);
        }

        foreach ($reliableIndices as $reliableIndex) {
            $pending = $this->pending[$reliableIndex];
            ++$pending->attempts;
            $pending->retryQueued = false;
            $delay = $this->rto->backedOffNanoseconds($pending->attempts);
            $pending->nextRetryAtNanoseconds = $now > PHP_INT_MAX - $delay ? PHP_INT_MAX : $now + $delay;
        }

        return $datagram;
    }

    /** @return list<int> acknowledged reliable indexes */
    public function acknowledgeDatagram(int $datagramSequence): array
    {
        $now = $this->now();
        $datagram = $this->history->take($datagramSequence);
        if ($datagram === null) {
            return [];
        }

        $eligibleRttSample = !$datagram->containsRetransmission;
        $acknowledged = [];
        foreach ($datagram->reliableIndices as $reliableIndex) {
            $pending = $this->pending[$reliableIndex] ?? null;
            if ($pending === null) {
                $eligibleRttSample = false;
                continue;
            }
            if ($pending->attempts !== 1) {
                $eligibleRttSample = false;
            }
            $acknowledged[] = $reliableIndex;
        }

        if ($eligibleRttSample && $acknowledged !== [] && $now > $datagram->sentAtNanoseconds) {
            $this->rto->observeRoundTrip($now - $datagram->sentAtNanoseconds);
        }

        foreach ($acknowledged as $reliableIndex) {
            $this->removePending($reliableIndex);
        }

        return $acknowledged;
    }

    public function negativeAcknowledgeDatagram(int $datagramSequence): bool
    {
        $now = $this->now();
        $datagram = $this->history->take($datagramSequence);
        if ($datagram === null) {
            return false;
        }

        foreach ($datagram->reliableIndices as $reliableIndex) {
            $pending = $this->pending[$reliableIndex] ?? null;
            if ($pending !== null && !$pending->retryQueued) {
                $pending->nextRetryAtNanoseconds = $now;
            }
        }

        return true;
    }

    public function collectDueRetries(): RetryDecision
    {
        $now = $this->now();
        $due = [];
        $expired = [];
        $indexes = array_keys($this->pending);
        sort($indexes, SORT_NUMERIC);

        foreach ($indexes as $reliableIndex) {
            $pending = $this->pending[$reliableIndex];
            $age = $now - $pending->frame->createdAtNanoseconds;
            $retryDeadlineReached = $pending->attempts > 0 && $now >= $pending->nextRetryAtNanoseconds;
            if (
                $age >= $this->limits->maximumAgeNanoseconds
                || ($retryDeadlineReached && $pending->attempts >= $this->limits->maximumAttempts)
            ) {
                $expired[] = $reliableIndex;
                $this->removePending($reliableIndex);
                continue;
            }
            if ($retryDeadlineReached && !$pending->retryQueued) {
                $pending->retryQueued = true;
                $due[] = $pending->frame;
            }
        }

        return new RetryDecision($due, $expired);
    }

    public function pendingCount(): int
    {
        return \count($this->pending);
    }

    public function pendingPayloadBytes(): int
    {
        return $this->pendingPayloadBytes;
    }

    public function historyCount(): int
    {
        return $this->history->count();
    }

    public function historyReferenceCount(): int
    {
        return $this->history->referenceCount();
    }

    public function rtoNanoseconds(): int
    {
        return $this->rto->currentNanoseconds();
    }

    private function removePending(int $reliableIndex): void
    {
        $pending = $this->pending[$reliableIndex] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->pending[$reliableIndex]);
        $this->pendingPayloadBytes -= $pending->frame->payloadBytes();
        $this->history->removeReliableIndex($reliableIndex);
    }

    private function now(): int
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < 0 || ($this->lastObservedTime !== null && $now < $this->lastObservedTime)) {
            throw new LogicException('Reliability clock must be nonnegative and monotonic.');
        }
        $this->lastObservedTime = $now;

        return $now;
    }
}
