<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class UnconnectedPing
{
    public const int ID = 0x01;
    public const int OPEN_CONNECTIONS_ID = 0x02;
    public const int LENGTH = 33;

    public function __construct(
        public int $timestamp,
        public int $clientGuid,
    ) {
        if ($this->timestamp < 0) {
            throw new CodecException('Ping timestamp must be nonnegative.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::LENGTH);
        $identifier = $reader->readByte();
        if ($identifier !== self::ID && $identifier !== self::OPEN_CONNECTIONS_ID) {
            throw new CodecException('Datagram is not an unconnected ping.');
        }

        $timestamp = $reader->readLong();
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }

        $clientGuid = $reader->readLong();
        $reader->requireEnd();

        return new self($timestamp, $clientGuid);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeLong($this->timestamp);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeLong($this->clientGuid);

        return $writer->bytes();
    }
}
