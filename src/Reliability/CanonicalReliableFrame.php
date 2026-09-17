<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Payload and identity retained unchanged across retransmissions. */
final readonly class CanonicalReliableFrame
{
    public function __construct(
        public int $reliableIndex,
        public string $payload,
        public int $createdAtNanoseconds,
    ) {
        Sequence24::validate($reliableIndex);
        if ($payload === '') {
            throw new InvalidArgumentException('A reliable frame payload cannot be empty.');
        }
        if ($createdAtNanoseconds < 0) {
            throw new InvalidArgumentException('Frame creation time cannot be negative.');
        }
    }

    public function payloadBytes(): int
    {
        return \strlen($this->payload);
    }
}
