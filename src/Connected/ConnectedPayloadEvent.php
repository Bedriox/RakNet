<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

/** Immutable application payload delivered by a connected session. */
final readonly class ConnectedPayloadEvent
{
    public function __construct(
        public string $payload,
        public Reliability $reliability,
        public ?int $orderingChannel,
    ) {
        if ($payload === '') {
            throw new InvalidArgumentException('A delivered payload cannot be empty.');
        }
    }
}
