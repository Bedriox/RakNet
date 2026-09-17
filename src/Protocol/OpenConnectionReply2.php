<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class OpenConnectionReply2
{
    public const int ID = 0x08;
    public const int IPV4_LENGTH = 35;

    public function __construct(
        public int $serverGuid,
        public InternetAddress $clientAddress,
        public int $mtu,
    ) {
        if ($this->serverGuid < 0 || $this->mtu < OpenConnectionRequest1::MINIMUM_MTU || $this->mtu > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new CodecException('Reply 2 GUID or MTU is out of range.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::IPV4_LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not Open Connection Reply 2.');
        }
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }

        $guid = $reader->readLong();
        $address = InternetAddress::decode($reader);
        $mtu = $reader->readUnsignedShort();
        if ($reader->readBoolean()) {
            throw new CodecException('Secure RakNet negotiation is not supported.');
        }
        $reader->requireEnd();

        return new self($guid, $address, $mtu);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::IPV4_LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeLong($this->serverGuid);
        $this->clientAddress->encode($writer);
        $writer->writeUnsignedShort($this->mtu);
        $writer->writeByte(0);

        return $writer->bytes();
    }
}
