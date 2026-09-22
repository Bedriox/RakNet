<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

enum SessionCloseReason: string
{
    case RemoteDisconnect = 'REMOTE_DISCONNECT';
    case HandshakeTimeout = 'HANDSHAKE_TIMEOUT';
    case IdleTimeout = 'IDLE_TIMEOUT';
    case LocalRemoval = 'LOCAL_REMOVAL';
    case ServerClosed = 'SERVER_CLOSED';
    case TransportFailure = 'TRANSPORT_FAILURE';
}
