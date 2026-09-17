<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

final class OfflineMagic
{
    public const string BYTES = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
    public const int LENGTH = 16;

    private function __construct() {}
}
