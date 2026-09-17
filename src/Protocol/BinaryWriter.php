<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final class BinaryWriter
{
    private string $buffer = '';

    public function __construct(private readonly int $maximumBytes)
    {
        if ($this->maximumBytes < 0) {
            throw new CodecException('Codec limit cannot be negative.');
        }
    }

    public function writeByte(int $value): void
    {
        if ($value < 0 || $value > 0xff) {
            throw new CodecException('Byte value is out of range.');
        }

        $this->writeBytes(\chr($value));
    }

    public function writeUnsignedShort(int $value): void
    {
        if ($value < 0 || $value > 0xffff) {
            throw new CodecException('Unsigned short value is out of range.');
        }

        $this->writeBytes(pack('n', $value));
    }

    public function writeUnsignedInt(int $value): void
    {
        if ($value < 0 || $value > 0xffff_ffff) {
            throw new CodecException('Unsigned integer value is out of range.');
        }

        $this->writeBytes(pack('N', $value));
    }

    public function writeUnsigned24LittleEndian(int $value): void
    {
        if ($value < 0 || $value > 0xff_ffff) {
            throw new CodecException('Unsigned 24-bit value is out of range.');
        }

        $this->writeBytes(\chr($value & 0xff) . \chr(($value >> 8) & 0xff) . \chr(($value >> 16) & 0xff));
    }

    public function writeLong(int $value): void
    {
        $this->writeBytes(pack('J', $value));
    }

    public function writeBytes(string $value): void
    {
        if (\strlen($this->buffer) + \strlen($value) > $this->maximumBytes) {
            throw new CodecException('Encoded datagram exceeds the configured codec limit.');
        }

        $this->buffer .= $value;
    }

    public function writeString(string $value, int $maximumBytes): void
    {
        $length = \strlen($value);
        if ($length > $maximumBytes || $length > 0xffff) {
            throw new CodecException('String exceeds the configured codec limit.');
        }

        $this->writeUnsignedShort($length);
        $this->writeBytes($value);
    }

    public function bytes(): string
    {
        return $this->buffer;
    }
}
