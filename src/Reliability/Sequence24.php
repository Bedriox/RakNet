<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Wrap-safe arithmetic for scalar 24-bit indexes. */
final class Sequence24
{
    public const int MAX = 0xff_ffff;
    public const int MODULUS = 0x100_0000;
    public const int HALF_RANGE = 0x80_0000;

    public static function validate(int $value): int
    {
        if ($value < 0 || $value > self::MAX) {
            throw new InvalidArgumentException('A 24-bit sequence must be between 0 and 16777215.');
        }

        return $value;
    }

    public static function increment(int $value, int $amount = 1): int
    {
        self::validate($value);
        if ($amount < 0 || $amount >= self::HALF_RANGE) {
            throw new InvalidArgumentException('Sequence increment must be nonnegative and below half the sequence space.');
        }

        return ($value + $amount) & self::MAX;
    }

    public static function forwardDistance(int $from, int $to): int
    {
        self::validate($from);
        self::validate($to);

        return ($to - $from) & self::MAX;
    }

    public static function relation(int $reference, int $candidate): SequenceRelation
    {
        $distance = self::forwardDistance($reference, $candidate);

        return match (true) {
            $distance === 0 => SequenceRelation::Same,
            $distance === self::HALF_RANGE => SequenceRelation::Ambiguous,
            $distance < self::HALF_RANGE => SequenceRelation::Newer,
            default => SequenceRelation::Older,
        };
    }
}
