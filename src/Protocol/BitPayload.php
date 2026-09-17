<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class BitPayload
{
    public const int MAXIMUM_BITS = 11_936;

    public function __construct(
        public string $bytes,
        public int $bitLength,
    ) {
        if ($this->bitLength < 1 || $this->bitLength > self::MAXIMUM_BITS) {
            throw new CodecException('Payload bit length is out of range.');
        }

        $expectedBytes = intdiv($this->bitLength + 7, 8);
        if (\strlen($this->bytes) !== $expectedBytes) {
            throw new CodecException('Payload byte length does not match its exact bit length.');
        }

        $unusedBits = (8 - ($this->bitLength & 7)) & 7;
        if ($unusedBits !== 0 && (\ord($this->bytes[$expectedBytes - 1]) & ((1 << $unusedBits) - 1)) !== 0) {
            throw new CodecException('Unused low payload bits must be zero.');
        }
    }
}
