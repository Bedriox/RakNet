<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

enum OrderedDeliveryStatus
{
    case Buffered;
    case Delivered;
    case Duplicate;
    case Stale;
    case RejectedConflict;
    case RejectedCapacity;
}
