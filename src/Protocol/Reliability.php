<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

enum Reliability: int
{
    case Unreliable = 0;
    case UnreliableSequenced = 1;
    case Reliable = 2;
    case ReliableOrdered = 3;
    case ReliableSequenced = 4;
    case UnreliableWithAckReceipt = 5;
    case ReliableWithAckReceipt = 6;
    case ReliableOrderedWithAckReceipt = 7;

    public function hasReliableIndex(): bool
    {
        return match ($this) {
            self::Reliable,
            self::ReliableOrdered,
            self::ReliableSequenced,
            self::ReliableWithAckReceipt,
            self::ReliableOrderedWithAckReceipt => true,
            default => false,
        };
    }

    public function hasSequenceIndex(): bool
    {
        return $this === self::UnreliableSequenced || $this === self::ReliableSequenced;
    }

    public function hasOrdering(): bool
    {
        return match ($this) {
            self::UnreliableSequenced,
            self::ReliableOrdered,
            self::ReliableSequenced,
            self::ReliableOrderedWithAckReceipt => true,
            default => false,
        };
    }
}
