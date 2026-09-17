<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Simulation;

use InvalidArgumentException;

final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        if ($seed < 0 || $seed > 0x7fff_ffff) {
            throw new InvalidArgumentException('Simulation seed must fit in a nonnegative 31-bit integer.');
        }

        $this->state = $seed;
    }

    public function chance(int $percentage): bool
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new InvalidArgumentException('Chance percentage must be between 0 and 100.');
        }

        return $percentage === 100 || ($percentage !== 0 && $this->next(100) < $percentage);
    }

    public function between(int $minimum, int $maximum): int
    {
        if ($minimum < 0 || $maximum < $minimum) {
            throw new InvalidArgumentException('Random range must be nonnegative and ordered.');
        }
        if ($minimum === $maximum) {
            return $minimum;
        }

        return $minimum + $this->next($maximum - $minimum + 1);
    }

    private function next(int $modulus): int
    {
        $this->state = ($this->state * 1_103_515_245 + 12_345) & 0x7fff_ffff;

        return $this->state % $modulus;
    }
}
