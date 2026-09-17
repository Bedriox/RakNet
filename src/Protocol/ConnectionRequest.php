<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class ConnectionRequest
{
    public const int ID = 0x09;
    public const int LENGTH = 18;

    public function __construct(
        public int $clientGuid,
        public int $requestTimestamp,
        public bool $security,
    ) {
        if ($this->requestTimestamp < 0) {
            throw new CodecException('Connection-request timestamp must be nonnegative.');
        }
    }

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Connection request has an invalid packet identifier.');
        }
        $packet = new self($reader->readLong(), $reader->readLong(), $reader->readBoolean());
        $reader->requireEnd();

        return $packet;
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::LENGTH);
        $writer->writeByte(self::ID);
        $writer->writeLong($this->clientGuid);
        $writer->writeLong($this->requestTimestamp);
        $writer->writeByte($this->security ? 1 : 0);

        return $writer->bytes();
    }
}
