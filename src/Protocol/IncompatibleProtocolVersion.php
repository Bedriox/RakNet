<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class IncompatibleProtocolVersion
{
    public const int ID = 0x19;
    public const int LENGTH = 26;

    public function __construct(public int $protocolVersion, public int $serverGuid)
    {
        if ($this->protocolVersion < 0 || $this->protocolVersion > 0xff || $this->serverGuid < 0) {
            throw new CodecException('Protocol version or server GUID is out of range.');
        }
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeByte($this->protocolVersion);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeLong($this->serverGuid);

        return $writer->bytes();
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not an incompatible protocol response.');
        }
        $protocolVersion = \ord($reader->readBytes(1));
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }
        $serverGuid = $reader->readLong();
        $reader->requireEnd();

        return new self($protocolVersion, $serverGuid);
    }
}
