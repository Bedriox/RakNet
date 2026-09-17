<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class ControlOutboundPayload
{
    public function __construct(
        public string $payload,
        public Reliability $reliability,
        public int $orderingChannel = 0,
    ) {
        if ($this->payload === '') {
            throw new InvalidArgumentException('Control payload cannot be empty.');
        }
        if ($this->orderingChannel < 0 || $this->orderingChannel > 31) {
            throw new InvalidArgumentException('Control ordering channel is out of range.');
        }
    }
}
