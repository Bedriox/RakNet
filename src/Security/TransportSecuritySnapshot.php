<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Security;

final readonly class TransportSecuritySnapshot
{
    public function __construct(
        public int $receivedDatagrams,
        public int $receivedBytes,
        public int $droppedDatagrams,
        public int $rateLimitedEndpoints,
        public int $malformedDatagrams,
        public int $temporaryBlocks,
        public int $activeBlocks,
        public int $trackedAddresses,
        public int $trackedEndpoints,
    ) {}
}
