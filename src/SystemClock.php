<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

final class SystemClock implements Clock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
