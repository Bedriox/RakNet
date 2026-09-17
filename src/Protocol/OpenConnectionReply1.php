<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class OpenConnectionReply1
{
    public const int ID = 0x06;
    public const int LENGTH = 28;

    public function __construct(
        public int $serverGuid,
        public int $mtu,
    ) {
        if ($this->serverGuid < 0 || $this->mtu < OpenConnectionRequest1::MINIMUM_MTU || $this->mtu > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new CodecException('Reply 1 GUID or MTU is out of range.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not Open Connection Reply 1.');
        }
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }

        $guid = $reader->readLong();
        if ($reader->readBoolean()) {
            throw new CodecException('Secure RakNet negotiation is not supported.');
        }
        $mtu = $reader->readUnsignedShort();
        $reader->requireEnd();

        return new self($guid, $mtu);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeLong($this->serverGuid);
        $writer->writeByte(0);
        $writer->writeUnsignedShort($this->mtu);

        return $writer->bytes();
    }
}
