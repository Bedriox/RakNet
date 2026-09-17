<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

interface Clock
{
    public function nowNanoseconds(): int;
}
