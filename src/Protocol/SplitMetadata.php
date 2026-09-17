<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class SplitMetadata
{
    public const int MAXIMUM_PARTS = 1_024;

    public function __construct(
        public int $count,
        public int $id,
        public int $index,
    ) {
        if ($this->count < 2 || $this->count > self::MAXIMUM_PARTS) {
            throw new CodecException('Split count is out of range.');
        }
        if ($this->id < 0 || $this->id > 0xffff) {
            throw new CodecException('Split ID is out of range.');
        }
        if ($this->index < 0 || $this->index >= $this->count) {
            throw new CodecException('Split index is out of range.');
        }
    }
}
