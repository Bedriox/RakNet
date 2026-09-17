<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Bounded history metadata; payload bytes remain owned by canonical frames. */
final readonly class SentDatagram
{
    /** @param list<int> $reliableIndices */
    public function __construct(
        public int $sequence,
        public int $sentAtNanoseconds,
        public array $reliableIndices,
        public bool $containsRetransmission,
    ) {
        Sequence24::validate($sequence);
        if ($sentAtNanoseconds < 0 || $reliableIndices === []) {
            throw new InvalidArgumentException('A sent datagram requires a nonnegative time and at least one reliable frame.');
        }

        $seen = [];
        foreach ($reliableIndices as $index) {
            Sequence24::validate($index);
            if (isset($seen[$index])) {
                throw new InvalidArgumentException('A datagram cannot reference the same reliable frame twice.');
            }
            $seen[$index] = true;
        }
    }
}
