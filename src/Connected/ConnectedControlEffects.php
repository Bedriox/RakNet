<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\SessionCloseReason;

final readonly class ConnectedControlEffects
{
    /** @param list<ControlOutboundPayload> $outboundPayloads */
    public function __construct(
        public array $outboundPayloads = [],
        public bool $deliverApplicationPayload = false,
        public bool $becameReady = false,
        public ?SessionCloseReason $closeReason = null,
    ) {}
}
