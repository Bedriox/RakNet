<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;
use OverflowException;

/** Bounded, idempotent ACK/NACK state with deterministic compaction. */
final class AcknowledgementAccumulator
{
    /** @var array<int, true> */
    private array $acknowledgements = [];

    /** @var array<int, true> */
    private array $negativeAcknowledgements = [];

    public function __construct(private readonly int $maximumSequences)
    {
        if ($maximumSequences < 1 || $maximumSequences >= Sequence24::HALF_RANGE) {
            throw new InvalidArgumentException('Acknowledgement capacity must be positive and below half the sequence space.');
        }
    }

    public function acknowledge(int $sequence): void
    {
        Sequence24::validate($sequence);
        unset($this->negativeAcknowledgements[$sequence]);
        $this->insert($this->acknowledgements, $sequence);
    }

    public function negativeAcknowledge(int $sequence): void
    {
        Sequence24::validate($sequence);
        if (isset($this->acknowledgements[$sequence])) {
            return;
        }
        $this->insert($this->negativeAcknowledgements, $sequence);
    }

    public function acknowledgementCount(): int
    {
        return \count($this->acknowledgements);
    }

    public function negativeAcknowledgementCount(): int
    {
        return \count($this->negativeAcknowledgements);
    }

    /** @return list<SequenceRange> */
    public function drainAcknowledgements(int $maximumRanges = PHP_INT_MAX, int $maximumRecordBytes = PHP_INT_MAX): array
    {
        return self::drain($this->acknowledgements, $maximumRanges, $maximumRecordBytes);
    }

    /** @return list<SequenceRange> */
    public function drainNegativeAcknowledgements(int $maximumRanges = PHP_INT_MAX, int $maximumRecordBytes = PHP_INT_MAX): array
    {
        return self::drain($this->negativeAcknowledgements, $maximumRanges, $maximumRecordBytes);
    }

    /** @param array<int, true> $target */
    private function insert(array &$target, int $sequence): void
    {
        if (isset($target[$sequence])) {
            return;
        }
        if (\count($this->acknowledgements) + \count($this->negativeAcknowledgements) >= $this->maximumSequences) {
            throw new OverflowException('Acknowledgement accumulation limit reached.');
        }
        $target[$sequence] = true;
    }

    /**
     * Numeric sorting intentionally emits separate ranges on either side of
     * the 24-bit wrap boundary, matching the range codec representation.
     *
     * @param array<int, true> $sequences
     * @return list<SequenceRange>
     */
    private static function compact(array $sequences): array
    {
        if ($sequences === []) {
            return [];
        }

        $values = array_keys($sequences);
        sort($values, SORT_NUMERIC);
        $ranges = [];
        $start = $values[0];
        $end = $start;
        foreach (\array_slice($values, 1) as $value) {
            if ($value === $end + 1) {
                $end = $value;
                continue;
            }
            $ranges[] = new SequenceRange($start, $end);
            $start = $end = $value;
        }
        $ranges[] = new SequenceRange($start, $end);

        return $ranges;
    }

    /**
     * @param array<int, true> $sequences
     * @return list<SequenceRange>
     */
    private static function drain(array &$sequences, int $maximumRanges, int $maximumRecordBytes): array
    {
        if ($maximumRanges < 1 || $maximumRecordBytes < 4) {
            throw new InvalidArgumentException('Drain limits must allow at least one acknowledgement record.');
        }

        $selected = [];
        $usedBytes = 0;
        foreach (self::compact($sequences) as $range) {
            $recordBytes = $range->start === $range->end ? 4 : 7;
            if (\count($selected) >= $maximumRanges || $recordBytes > $maximumRecordBytes - $usedBytes) {
                break;
            }
            $selected[] = $range;
            $usedBytes += $recordBytes;
        }
        foreach ($selected as $range) {
            for ($sequence = $range->start; $sequence <= $range->end; ++$sequence) {
                unset($sequences[$sequence]);
            }
        }

        return $selected;
    }
}
