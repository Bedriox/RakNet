<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Bounded duplicate and forward-gap tracking for inbound datagrams. */
final class ReceiveSequenceWindow
{
    private ?int $highest = null;

    /** @var array<int, true> */
    private array $received = [];

    public function __construct(private readonly int $windowSize)
    {
        if ($windowSize < 2 || $windowSize >= Sequence24::HALF_RANGE) {
            throw new InvalidArgumentException('Receive window must be between 2 and 8388607 sequences.');
        }
    }

    public function observe(int $sequence): ReceiveSequenceResult
    {
        Sequence24::validate($sequence);
        if ($this->highest === null) {
            $this->highest = $sequence;
            $this->received[$sequence] = true;

            return new ReceiveSequenceResult(SequenceObservation::Accepted);
        }

        $relation = Sequence24::relation($this->highest, $sequence);
        if ($relation === SequenceRelation::Same) {
            return new ReceiveSequenceResult(SequenceObservation::Duplicate);
        }
        if ($relation === SequenceRelation::Ambiguous) {
            return new ReceiveSequenceResult(SequenceObservation::Ambiguous);
        }
        if ($relation === SequenceRelation::Newer) {
            $distance = Sequence24::forwardDistance($this->highest, $sequence);
            if ($distance >= $this->windowSize) {
                return new ReceiveSequenceResult(SequenceObservation::TooFarAhead);
            }

            $missing = [];
            for ($offset = 1; $offset < $distance; ++$offset) {
                $missing[] = Sequence24::increment($this->highest, $offset);
            }
            $previousHighest = $this->highest;
            for ($offset = 0; $offset < $distance; ++$offset) {
                $expired = ($previousHighest - ($this->windowSize - 1) + $offset) & Sequence24::MAX;
                unset($this->received[$expired]);
            }
            $this->highest = $sequence;
            $this->received[$sequence] = true;

            return new ReceiveSequenceResult(SequenceObservation::Accepted, $missing);
        }

        $age = Sequence24::forwardDistance($sequence, $this->highest);
        if ($age >= $this->windowSize) {
            return new ReceiveSequenceResult(SequenceObservation::Stale);
        }
        if (isset($this->received[$sequence])) {
            return new ReceiveSequenceResult(SequenceObservation::Duplicate);
        }

        $this->received[$sequence] = true;

        return new ReceiveSequenceResult(SequenceObservation::AcceptedOutOfOrder);
    }

    public function highest(): ?int
    {
        return $this->highest;
    }

    public function retainedCount(): int
    {
        return \count($this->received);
    }

}
