<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final class BinaryReader
{
    private int $offset = 0;

    public function __construct(
        private readonly string $buffer,
        int $maximumBytes,
    ) {
        $length = \strlen($this->buffer);
        if ($maximumBytes < 0 || $length > $maximumBytes) {
            throw new CodecException('Datagram exceeds the configured codec limit.');
        }
    }

    public function readByte(): int
    {
        return \ord($this->readBytes(1));
    }

    public function readUnsignedShort(): int
    {
        /** @var array{value: int} $decoded */
        $decoded = unpack('nvalue', $this->readBytes(2));

        return $decoded['value'];
    }

    public function readUnsignedInt(): int
    {
        $bytes = $this->readBytes(4);

        return (\ord($bytes[0]) << 24)
            | (\ord($bytes[1]) << 16)
            | (\ord($bytes[2]) << 8)
            | \ord($bytes[3]);
    }

    public function readUnsigned24LittleEndian(): int
    {
        $bytes = $this->readBytes(3);

        return \ord($bytes[0]) | (\ord($bytes[1]) << 8) | (\ord($bytes[2]) << 16);
    }

    public function readLong(): int
    {
        /** @var array{value: int} $decoded */
        $decoded = unpack('Jvalue', $this->readBytes(8));

        return $decoded['value'];
    }

    public function readBoolean(): bool
    {
        $value = \ord($this->readBytes(1));
        if ($value !== 0 && $value !== 1) {
            throw new CodecException('Boolean field must be encoded as zero or one.');
        }

        return $value === 1;
    }

    public function readBytes(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new CodecException('Datagram ended before the declared field length.');
        }

        $result = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $result;
    }

    public function readString(int $maximumBytes): string
    {
        $length = $this->readUnsignedShort();
        if ($length > $maximumBytes) {
            throw new CodecException('String exceeds the configured codec limit.');
        }

        return $this->readBytes($length);
    }

    public function remaining(): int
    {
        return \strlen($this->buffer) - $this->offset;
    }

    public function requireEnd(): void
    {
        if ($this->remaining() !== 0) {
            throw new CodecException('Unexpected trailing bytes in datagram.');
        }
    }
}
