<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Result of observing an inbound sequence or reliable index. */
enum SequenceObservation
{
    case Accepted;
    case AcceptedOutOfOrder;
    case Duplicate;
    case Stale;
    case TooFarAhead;
    case Ambiguous;

    public function isAccepted(): bool
    {
        return $this === self::Accepted || $this === self::AcceptedOutOfOrder;
    }
}
