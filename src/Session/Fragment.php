<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

use InvalidArgumentException;

final readonly class Fragment
{
    public const int MAXIMUM_SPLIT_ID = 0xffff;
    public const int MAXIMUM_FRAGMENT_NUMBER = 0xffff_ffff;

    public function __construct(
        public int $splitId,
        public int $fragmentCount,
        public int $fragmentIndex,
        public string $payload,
    ) {
        if ($this->splitId < 0 || $this->splitId > self::MAXIMUM_SPLIT_ID) {
            throw new InvalidArgumentException('Fragment split ID must fit in an unsigned 16-bit integer.');
        }
        if ($this->fragmentCount < 2 || $this->fragmentCount > self::MAXIMUM_FRAGMENT_NUMBER) {
            throw new InvalidArgumentException('A split packet must contain between 2 and 2^32 - 1 fragments.');
        }
        if ($this->fragmentIndex < 0 || $this->fragmentIndex >= $this->fragmentCount) {
            throw new InvalidArgumentException('Fragment index must identify an element in the split packet.');
        }
        if ($this->payload === '') {
            throw new InvalidArgumentException('Fragment payload cannot be empty.');
        }
    }
}
