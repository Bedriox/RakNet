<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Inclusive numeric range that never crosses the 24-bit wrap point. */
final readonly class SequenceRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        Sequence24::validate($start);
        Sequence24::validate($end);
        if ($end < $start) {
            throw new InvalidArgumentException('A compacted sequence range cannot cross the numeric wrap boundary.');
        }
    }
}
