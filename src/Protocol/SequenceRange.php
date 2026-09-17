<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class SequenceRange
{
    public const int MAXIMUM_SPAN = 8_192;

    public function __construct(
        public int $start,
        public int $end,
    ) {
        SequenceMath::validate($this->start);
        SequenceMath::validate($this->end);
        if ($this->end < $this->start) {
            throw new CodecException('Acknowledgement ranges cannot wrap or descend.');
        }
        if ($this->span() > self::MAXIMUM_SPAN) {
            throw new CodecException('Acknowledgement range exceeds the represented-span limit.');
        }
    }

    public function span(): int
    {
        return $this->end - $this->start + 1;
    }

    public function isSingle(): bool
    {
        return $this->start === $this->end;
    }
}
