<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Protocol\Reliability;

/** @internal Metadata pinned for a byte-aligned split assembly. */
final readonly class SplitContext
{
    public function __construct(
        public int $count,
        public Reliability $reliability,
        public ?int $orderingIndex,
        public ?int $orderingChannel,
        public int $expiresAtNanoseconds,
    ) {}

    public function matches(
        int $count,
        Reliability $reliability,
        ?int $orderingIndex,
        ?int $orderingChannel,
    ): bool {
        return $this->count === $count
            && $this->reliability === $reliability
            && $this->orderingIndex === $orderingIndex
            && $this->orderingChannel === $orderingChannel;
    }
}
