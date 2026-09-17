<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

final readonly class SessionClosedEvent
{
    public function __construct(public SessionInfo $session, public SessionCloseReason $reason) {}
}
