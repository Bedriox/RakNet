<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

final readonly class SessionOpenedEvent
{
    public function __construct(public SessionInfo $session) {}
}
