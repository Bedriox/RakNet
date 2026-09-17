<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Immutable outcome of receive-window processing. */
final readonly class ReceiveSequenceResult
{
    /** @param list<int> $missingSequences */
    public function __construct(
        public SequenceObservation $observation,
        public array $missingSequences = [],
    ) {}
}
