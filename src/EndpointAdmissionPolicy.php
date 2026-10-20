<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

interface EndpointAdmissionPolicy
{
    public function allows(string $remoteAddress, int $remotePort): bool;
}
