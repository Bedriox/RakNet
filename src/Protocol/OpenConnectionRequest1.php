<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class OpenConnectionRequest1
{
    public const int ID = 0x05;
    public const int IPV4_UDP_OVERHEAD = 28;
    public const int MINIMUM_MTU = 576;
    public const int MAXIMUM_MTU = 1_492;

    public function __construct(
        public int $protocolVersion,
        public int $mtu,
    ) {
        if ($this->protocolVersion < 0 || $this->protocolVersion > 0xff) {
            throw new CodecException('RakNet protocol version is out of range.');
        }

        if ($this->mtu < self::MINIMUM_MTU || $this->mtu > self::MAXIMUM_MTU) {
            throw new CodecException('Requested MTU is out of range.');
        }
    }

    public static function decode(string $datagram, int $maximumMtu): self
    {
        if ($maximumMtu < self::MINIMUM_MTU || $maximumMtu > self::MAXIMUM_MTU) {
            throw new CodecException('Maximum accepted probe MTU is out of range.');
        }

        $minimumLength = self::MINIMUM_MTU - self::IPV4_UDP_OVERHEAD;
        $maximumLength = $maximumMtu - self::IPV4_UDP_OVERHEAD;
        $length = \strlen($datagram);
        if ($length < $minimumLength || $length > $maximumLength) {
            throw new CodecException('Open Connection Request 1 has an invalid MTU-derived length.');
        }

        $reader = new BinaryReader($datagram, $maximumLength);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not Open Connection Request 1.');
        }

        self::readMagic($reader);
        $protocolVersion = $reader->readByte();
        $padding = $reader->readBytes($reader->remaining());
        if (trim($padding, "\x00") !== '') {
            throw new CodecException('Open Connection Request 1 padding must contain only zero bytes.');
        }

        return new self($protocolVersion, $length + self::IPV4_UDP_OVERHEAD);
    }

    public function encode(): string
    {
        $length = $this->mtu - self::IPV4_UDP_OVERHEAD;
        $writer = new BinaryWriter($length);
        $writer->writeByte(self::ID);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeByte($this->protocolVersion);
        $writer->writeBytes(str_repeat("\x00", $length - 18));

        return $writer->bytes();
    }

    private static function readMagic(BinaryReader $reader): void
    {
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }
    }
}
