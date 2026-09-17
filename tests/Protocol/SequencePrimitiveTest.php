<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\BinaryReader;
use Bedriox\RakNet\Protocol\BinaryWriter;
use Bedriox\RakNet\Protocol\SequenceMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SequencePrimitiveTest extends TestCase
{
    public function testUInt24LittleEndianGoldenVector(): void
    {
        $writer = new BinaryWriter(3);
        $writer->writeUnsigned24LittleEndian(0x123456);
        self::assertSame('563412', bin2hex($writer->bytes()));

        $reader = new BinaryReader("\x56\x34\x12", 3);
        self::assertSame(0x123456, $reader->readUnsigned24LittleEndian());
        $reader->requireEnd();
    }

    /** @return iterable<string, array{int}> */
    public static function invalidUInt24(): iterable
    {
        yield 'negative' => [-1];
        yield 'too large' => [0x100_0000];
    }

    #[DataProvider('invalidUInt24')]
    public function testUInt24WriterRejectsOutOfRange(int $value): void
    {
        $writer = new BinaryWriter(3);
        $this->expectException(CodecException::class);
        $writer->writeUnsigned24LittleEndian($value);
    }

    public function testUInt24ReaderRejectsEveryTruncation(): void
    {
        for ($length = 0; $length < 3; ++$length) {
            try {
                new BinaryReader(substr("\x56\x34\x12", 0, $length), 3)->readUnsigned24LittleEndian();
                self::fail('Truncated uint24 was accepted at length ' . $length);
            } catch (CodecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSequenceMathWrapsAndOrdersSafely(): void
    {
        self::assertSame(0, SequenceMath::increment(SequenceMath::MAX));
        self::assertSame(3, SequenceMath::forwardDistance(SequenceMath::MAX - 1, 1));
        self::assertTrue(SequenceMath::isNewer(0, SequenceMath::MAX));
        self::assertFalse(SequenceMath::isNewer(SequenceMath::MAX, 0));
        self::assertFalse(SequenceMath::isNewer(SequenceMath::HALF_RANGE, 0));
        self::assertFalse(SequenceMath::isNewer(7, 7));
    }

    public function testSequenceMathRejectsOutOfRange(): void
    {
        $this->expectException(CodecException::class);
        SequenceMath::forwardDistance(-1, 0);
    }
}
