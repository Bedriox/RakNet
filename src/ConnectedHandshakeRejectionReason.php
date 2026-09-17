<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

enum ConnectedHandshakeRejectionReason: string
{
    case MalformedDatagram = 'MALFORMED_DATAGRAM';
    case InvalidEnvelope = 'INVALID_ENVELOPE';
    case InvalidControlPayload = 'INVALID_CONTROL_PAYLOAD';
    case Timeout = 'TIMEOUT';
}
