<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Reliability\ExpiredReliableFrame;
use InvalidArgumentException;

/** Immutable effects produced since the previous drain. */
final readonly class ConnectedSessionEffects
{
    /**
     * @param list<ConnectedPayloadEvent> $payloads
     * @param list<string> $outboundDatagrams
     * @param list<int> $expiredReliableIndices
     * @param list<int> $expiredUnsentReliableIndices
     * @param list<ExpiredReliableFrame> $expiredReliableFrames
     */
    public function __construct(
        public array $payloads,
        public array $outboundDatagrams,
        public array $expiredReliableIndices,
        public array $expiredUnsentReliableIndices,
        public int $priorityOutboundDatagramCount,
        public array $expiredReliableFrames = [],
    ) {
        if ($priorityOutboundDatagramCount < 0 || $priorityOutboundDatagramCount > \count($outboundDatagrams)) {
            throw new InvalidArgumentException('Priority outbound datagram count is outside the effect batch.');
        }
    }
}
