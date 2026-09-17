<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class ConnectedPong
{
    public const int ID = 0x03;
    public const int LENGTH = 17;

    public function __construct(public int $pingTimestamp, public int $pongTimestamp)
    {
        if ($this->pingTimestamp < 0 || $this->pongTimestamp < 0) {
            throw new CodecException('Connected-pong timestamps must be nonnegative.');
        }
    }

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Connected pong has an invalid packet identifier.');
        }
        $packet = new self($reader->readLong(), $reader->readLong());
        $reader->requireEnd();

        return $packet;
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeLong($this->pingTimestamp);
        $writer->writeLong($this->pongTimestamp);

        return $writer->bytes();
    }
}
