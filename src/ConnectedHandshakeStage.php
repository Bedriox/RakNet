<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

enum ConnectedHandshakeStage: string
{
    case AwaitingConnectionRequest = 'AWAITING_CONNECTION_REQUEST';
    case AwaitingNewIncomingConnection = 'AWAITING_NEW_INCOMING_CONNECTION';
}
