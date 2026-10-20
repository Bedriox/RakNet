<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

enum ClientState
{
    case AwaitingReply1;
    case AwaitingReply2;
    case AwaitingAcceptance;
    case Ready;
    case Closed;
}
