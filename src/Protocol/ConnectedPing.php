<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class ConnectedPing
{
    public const int ID = 0x00;
    public const int LENGTH = 9;

    public function __construct(public int $timestamp)
    {
        if ($this->timestamp < 0) {
            throw new CodecException('Connected-ping timestamp must be nonnegative.');
        }
    }

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Connected ping has an invalid packet identifier.');
        }
        $packet = new self($reader->readLong());
        $reader->requireEnd();

        return $packet;
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeLong($this->timestamp);

        return $writer->bytes();
    }
}
