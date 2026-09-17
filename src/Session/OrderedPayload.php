<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

use InvalidArgumentException;

final readonly class OrderedPayload
{
    public const int CHANNEL_COUNT = 32;
    public const int MAXIMUM_ORDER_INDEX = 0xff_ffff;

    public function __construct(
        public int $channel,
        public int $orderIndex,
        public string $payload,
    ) {
        if ($this->channel < 0 || $this->channel >= self::CHANNEL_COUNT) {
            throw new InvalidArgumentException('Ordering channel must be between 0 and 31.');
        }
        if ($this->orderIndex < 0 || $this->orderIndex > self::MAXIMUM_ORDER_INDEX) {
            throw new InvalidArgumentException('Order index must fit in an unsigned 24-bit integer.');
        }
        if ($this->payload === '') {
            throw new InvalidArgumentException('Ordered payload cannot be empty.');
        }
    }
}
