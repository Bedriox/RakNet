<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Simulation;

use InvalidArgumentException;

final readonly class ImpairmentProfile
{
    public function __construct(
        public int $lossPercent = 0,
        public int $duplicationPercent = 0,
        public int $minimumDelayMilliseconds = 0,
        public int $maximumDelayMilliseconds = 0,
    ) {
        if ($this->lossPercent < 0 || $this->lossPercent > 100) {
            throw new InvalidArgumentException('Loss percentage must be between 0 and 100.');
        }
        if ($this->duplicationPercent < 0 || $this->duplicationPercent > 100) {
            throw new InvalidArgumentException('Duplication percentage must be between 0 and 100.');
        }
        if ($this->minimumDelayMilliseconds < 0) {
            throw new InvalidArgumentException('Minimum delay cannot be negative.');
        }
        if ($this->maximumDelayMilliseconds < $this->minimumDelayMilliseconds) {
            throw new InvalidArgumentException('Maximum delay cannot be below minimum delay.');
        }
    }
}
