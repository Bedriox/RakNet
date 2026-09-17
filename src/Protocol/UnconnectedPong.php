<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class UnconnectedPong
{
    public const int ID = 0x1c;
    public const int HEADER_LENGTH = 35;
    /** Keeps the complete UDP response at or below 387 bytes. */
    public const int MAXIMUM_STATUS_BYTES = 352;

    public function __construct(
        public int $timestamp,
        public int $serverGuid,
        public string $status,
    ) {
        if ($this->timestamp < 0 || $this->serverGuid < 0) {
            throw new CodecException('Pong timestamp and server GUID must be nonnegative 63-bit integers.');
        }

        if (\strlen($this->status) > self::MAXIMUM_STATUS_BYTES) {
            throw new CodecException('Pong status exceeds the configured limit.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::HEADER_LENGTH + self::MAXIMUM_STATUS_BYTES);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Datagram is not an unconnected pong.');
        }

        $timestamp = $reader->readLong();
        $serverGuid = $reader->readLong();
        if (!hash_equals(OfflineMagic::BYTES, $reader->readBytes(OfflineMagic::LENGTH))) {
            throw new CodecException('Invalid RakNet offline message magic.');
        }

        $status = $reader->readString(self::MAXIMUM_STATUS_BYTES);
        $reader->requireEnd();

        return new self($timestamp, $serverGuid, $status);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::HEADER_LENGTH + self::MAXIMUM_STATUS_BYTES);
        $writer->writeByte(self::ID);
        $writer->writeLong($this->timestamp);
        $writer->writeLong($this->serverGuid);
        $writer->writeBytes(OfflineMagic::BYTES);
        $writer->writeString($this->status, self::MAXIMUM_STATUS_BYTES);

        return $writer->bytes();
    }
}
