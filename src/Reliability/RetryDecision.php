<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Immutable frames claimed for retry plus frames expired in the same pass. */
final readonly class RetryDecision
{
    /**
     * @param list<CanonicalReliableFrame> $due
     * @param list<int> $expiredReliableIndices
     */
    public function __construct(
        public array $due,
        public array $expiredReliableIndices,
    ) {}
}
