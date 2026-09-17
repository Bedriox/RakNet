<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final class SequenceMath
{
    public const int MODULUS = 0x100_0000;
    public const int MAX = 0xff_ffff;
    public const int HALF_RANGE = 0x80_0000;

    private function __construct() {}

    public static function validate(int $value): int
    {
        if ($value < 0 || $value > self::MAX) {
            throw new CodecException('Sequence number must be an unsigned 24-bit integer.');
        }

        return $value;
    }

    public static function increment(int $value): int
    {
        return (self::validate($value) + 1) & self::MAX;
    }

    public static function forwardDistance(int $from, int $to): int
    {
        self::validate($from);
        self::validate($to);

        return ($to - $from + self::MODULUS) & self::MAX;
    }

    public static function isNewer(int $candidate, int $reference): bool
    {
        $distance = self::forwardDistance($reference, $candidate);

        return $distance > 0 && $distance < self::HALF_RANGE;
    }
}
