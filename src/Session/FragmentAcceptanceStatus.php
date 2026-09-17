<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

enum FragmentAcceptanceStatus
{
    case Partial;
    case Completed;
    case Duplicate;
    case RejectedConflict;
    case RejectedCapacity;
}
