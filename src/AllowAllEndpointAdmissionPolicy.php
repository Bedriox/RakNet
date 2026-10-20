<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

final readonly class AllowAllEndpointAdmissionPolicy implements EndpointAdmissionPolicy
{
    public function allows(string $remoteAddress, int $remotePort): bool
    {
        return true;
    }
}
