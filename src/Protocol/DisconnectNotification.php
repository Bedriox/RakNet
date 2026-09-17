<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class DisconnectNotification
{
    public const int ID = 0x15;
    public const int LENGTH = 1;

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Disconnect notification has an invalid packet identifier.');
        }
        $reader->requireEnd();

        return new self();
    }

    public function encode(): string
    {
        return \chr(self::ID);
    }
}
