<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Immutable diagnostic describing a reliable frame at its expiry boundary. */
final readonly class ExpiredReliableFrame
{
    /** @param list<int> $transmissionSequences */
    public function __construct(
        public int $reliableIndex,
        public int $attempts,
        public int $ageNanoseconds,
        public int $payloadBytes,
        public bool $retryQueued,
        public int $nextRetryAtNanoseconds,
        public array $transmissionSequences = [],
    ) {}
}
