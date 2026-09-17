<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class OpenConnectionRequest2
{
    public const int ID = 0x07;
    public const int IPV4_LENGTH = 34;

    public function __construct(
        public InternetAddress $serverAddress,
        public int $mtu,
        public int $clientGuid,
    ) {
        if ($this->mtu < OpenConnectionRequest1::MINIMUM_MTU || $this->mtu > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new CodecException('Request 2 MTU is out of range.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::IPV4_LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not Open Connection Request 2.');
        }
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }

        $address = InternetAddress::decode($reader);
        $mtu = $reader->readUnsignedShort();
        $guid = $reader->readLong();
        $reader->requireEnd();

        return new self($address, $mtu, $guid);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::IPV4_LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeBytes(OfflineMagic::BYTES);
        $this->serverAddress->encode($writer);
        $writer->writeUnsignedShort($this->mtu);
        $writer->writeLong($this->clientGuid);

        return $writer->bytes();
    }
}
