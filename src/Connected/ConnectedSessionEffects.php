<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

/** Immutable effects produced since the previous drain. */
final readonly class ConnectedSessionEffects
{
    /**
     * @param list<ConnectedPayloadEvent> $payloads
     * @param list<string> $outboundDatagrams
     * @param list<int> $expiredReliableIndices
     */
    public function __construct(
        public array $payloads,
        public array $outboundDatagrams,
        public array $expiredReliableIndices,
    ) {}
}
