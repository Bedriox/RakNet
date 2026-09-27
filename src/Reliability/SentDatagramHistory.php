<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;
use OverflowException;

/** Count- and reference-bounded sent-datagram index. */
final class SentDatagramHistory
{
    /** @var array<int, SentDatagram> */
    private array $datagrams = [];

    /** @var array<int, array<int, true>> */
    private array $sequencesByReliableIndex = [];

    private int $referenceCount = 0;

    public function __construct(
        private readonly int $maximumDatagrams,
        private readonly int $maximumReferences,
    ) {
        if ($maximumDatagrams < 1 || $maximumReferences < $maximumDatagrams) {
            throw new InvalidArgumentException('History limits must be positive and allow one reference per datagram.');
        }
    }

    public function assertCanAdd(int $sequence, int $references): void
    {
        Sequence24::validate($sequence);
        if ($references < 1) {
            throw new InvalidArgumentException('A sent datagram requires at least one reliable-frame reference.');
        }
        if (isset($this->datagrams[$sequence])) {
            throw new InvalidArgumentException('Sent datagram sequence is already tracked.');
        }
        if (\count($this->datagrams) >= $this->maximumDatagrams || $references > $this->maximumReferences - $this->referenceCount) {
            throw new OverflowException('Sent datagram history limit reached.');
        }
    }

    public function add(SentDatagram $datagram): void
    {
        $references = \count($datagram->reliableIndices);
        $this->assertCanAdd($datagram->sequence, $references);
        $this->datagrams[$datagram->sequence] = $datagram;
        $this->referenceCount += $references;
        foreach ($datagram->reliableIndices as $reliableIndex) {
            $this->sequencesByReliableIndex[$reliableIndex][$datagram->sequence] = true;
        }
    }

    /**
     * Atomically retires older references for retransmitted frames and adds
     * their new canonical datagram record.
     */
    public function supersedeAndAdd(SentDatagram $datagram): void
    {
        $targets = array_fill_keys($datagram->reliableIndices, true);
        $affectedSequences = [];
        foreach ($datagram->reliableIndices as $reliableIndex) {
            foreach ($this->sequencesByReliableIndex[$reliableIndex] ?? [] as $sequence => $_) {
                $affectedSequences[$sequence] = true;
            }
        }

        $removedReferences = 0;
        $removedDatagrams = 0;
        foreach ($affectedSequences as $sequence => $_) {
            $existing = $this->datagrams[$sequence];
            $removedFromDatagram = 0;
            foreach ($existing->reliableIndices as $reliableIndex) {
                if (isset($targets[$reliableIndex])) {
                    ++$removedFromDatagram;
                }
            }
            $removedReferences += $removedFromDatagram;
            if ($removedFromDatagram === \count($existing->reliableIndices)) {
                ++$removedDatagrams;
            }
        }

        $existingAtNewSequence = $this->datagrams[$datagram->sequence] ?? null;
        if ($existingAtNewSequence !== null) {
            $allRemoved = true;
            foreach ($existingAtNewSequence->reliableIndices as $reliableIndex) {
                if (!isset($targets[$reliableIndex])) {
                    $allRemoved = false;
                    break;
                }
            }
            if (!$allRemoved) {
                throw new InvalidArgumentException('Sent datagram sequence is already tracked.');
            }
        }

        if (
            \count($this->datagrams) - $removedDatagrams >= $this->maximumDatagrams
            || \count($datagram->reliableIndices) > $this->maximumReferences - ($this->referenceCount - $removedReferences)
        ) {
            throw new OverflowException('Sent datagram history limit reached.');
        }

        foreach ($datagram->reliableIndices as $reliableIndex) {
            $this->removeReliableIndex($reliableIndex);
        }
        $this->add($datagram);
    }

    public function take(int $sequence): ?SentDatagram
    {
        Sequence24::validate($sequence);
        $datagram = $this->datagrams[$sequence] ?? null;
        if ($datagram === null) {
            return null;
        }

        unset($this->datagrams[$sequence]);
        $this->referenceCount -= \count($datagram->reliableIndices);
        foreach ($datagram->reliableIndices as $reliableIndex) {
            unset($this->sequencesByReliableIndex[$reliableIndex][$sequence]);
            if (($this->sequencesByReliableIndex[$reliableIndex] ?? []) === []) {
                unset($this->sequencesByReliableIndex[$reliableIndex]);
            }
        }

        return $datagram;
    }

    public function removeReliableIndex(int $reliableIndex): void
    {
        Sequence24::validate($reliableIndex);
        $sequences = array_keys($this->sequencesByReliableIndex[$reliableIndex] ?? []);
        foreach ($sequences as $sequence) {
            $datagram = $this->datagrams[$sequence];
            $remaining = array_values(array_filter(
                $datagram->reliableIndices,
                static fn(int $index): bool => $index !== $reliableIndex,
            ));
            if (\count($remaining) === \count($datagram->reliableIndices)) {
                continue;
            }
            --$this->referenceCount;
            if ($remaining === []) {
                unset($this->datagrams[$sequence]);
                continue;
            }
            $this->datagrams[$sequence] = new SentDatagram(
                $datagram->sequence,
                $datagram->sentAtNanoseconds,
                $remaining,
                $datagram->containsRetransmission,
            );
        }
        unset($this->sequencesByReliableIndex[$reliableIndex]);
    }

    public function count(): int
    {
        return \count($this->datagrams);
    }

    public function referenceCount(): int
    {
        return $this->referenceCount;
    }

    /** @return list<int> */
    public function sequencesForReliableIndex(int $reliableIndex): array
    {
        Sequence24::validate($reliableIndex);

        return array_map('intval', array_keys($this->sequencesByReliableIndex[$reliableIndex] ?? []));
    }
}
