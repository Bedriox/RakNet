<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Immutable frames claimed for retry plus frames expired in the same pass. */
final readonly class RetryDecision
{
    /**
     * @param list<CanonicalReliableFrame> $due
     * @param list<int> $expiredReliableIndices
     * @param list<int> $expiredUnsentReliableIndices
     * @param list<ExpiredReliableFrame> $expiredReliableFrames
     */
    public function __construct(
        public array $due,
        public array $expiredReliableIndices,
        public array $expiredUnsentReliableIndices,
        public array $expiredReliableFrames = [],
    ) {}
}
