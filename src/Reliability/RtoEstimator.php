<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Integer RFC-style smoothed RTT and bounded retransmission timeout. */
final class RtoEstimator
{
    private ?int $smoothedRttNanoseconds = null;
    private ?int $rttVariationNanoseconds = null;
    private int $rtoNanoseconds;

    public function __construct(
        int $initialRtoNanoseconds,
        private readonly int $minimumRtoNanoseconds,
        private readonly int $maximumRtoNanoseconds,
    ) {
        if (
            $minimumRtoNanoseconds < 1
            || $minimumRtoNanoseconds > $initialRtoNanoseconds
            || $initialRtoNanoseconds > $maximumRtoNanoseconds
        ) {
            throw new InvalidArgumentException('RTO values must satisfy 0 < minimum <= initial <= maximum.');
        }
        $this->rtoNanoseconds = $initialRtoNanoseconds;
    }

    public function observeRoundTrip(int $sampleNanoseconds): void
    {
        if ($sampleNanoseconds < 1) {
            throw new InvalidArgumentException('Round-trip sample must be positive.');
        }

        $sampleNanoseconds = min($sampleNanoseconds, $this->maximumRtoNanoseconds);
        if ($this->smoothedRttNanoseconds === null) {
            $this->smoothedRttNanoseconds = $sampleNanoseconds;
            $this->rttVariationNanoseconds = intdiv($sampleNanoseconds, 2);
        } else {
            $variation = $this->rttVariationNanoseconds ?? 0;
            $difference = abs($this->smoothedRttNanoseconds - $sampleNanoseconds);
            $variation += intdiv($difference - $variation, 4);
            $this->smoothedRttNanoseconds += intdiv($sampleNanoseconds - $this->smoothedRttNanoseconds, 8);
            $this->rttVariationNanoseconds = $variation;
        }

        $variation = $this->rttVariationNanoseconds ?? 0;
        $room = $this->maximumRtoNanoseconds - $this->smoothedRttNanoseconds;
        $candidate = $variation > intdiv($room, 4)
            ? $this->maximumRtoNanoseconds
            : $this->smoothedRttNanoseconds + 4 * $variation;
        $this->rtoNanoseconds = max($this->minimumRtoNanoseconds, min($candidate, $this->maximumRtoNanoseconds));
    }

    public function currentNanoseconds(): int
    {
        return $this->rtoNanoseconds;
    }

    public function backedOffNanoseconds(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Transmission attempt must be positive.');
        }

        $value = $this->rtoNanoseconds;
        for ($current = 1; $current < $attempt && $value < $this->maximumRtoNanoseconds; ++$current) {
            $value = $value > intdiv($this->maximumRtoNanoseconds, 2)
                ? $this->maximumRtoNanoseconds
                : $value * 2;
        }

        return $value;
    }
}
