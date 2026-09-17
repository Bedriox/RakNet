<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

enum ConnectedControlState
{
    case AwaitingConnectionRequest;
    case AwaitingNewIncomingConnection;
    case Ready;
    case Closed;
}
