<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\Clock;

final class MutableClock implements Clock
{
    public function __construct(private int $nowNanoseconds = 0) {}

    public function advanceMilliseconds(int $milliseconds): void
    {
        $this->nowNanoseconds += $milliseconds * 1_000_000;
    }

    public function nowNanoseconds(): int
    {
        return $this->nowNanoseconds;
    }
}
