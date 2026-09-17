<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\AckPacket;
use Bedriox\RakNet\Protocol\NackPacket;
use Bedriox\RakNet\Protocol\SequenceRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcknowledgementCodecTest extends TestCase
{
    public function testAckGoldenVector(): void
    {
        $packet = new AckPacket([new SequenceRange(0x010203, 0x010203), new SequenceRange(4, 6)]);
        $expected = 'c000020103020100040000060000';
        self::assertSame($expected, bin2hex($packet->encode()));
        $decoded = AckPacket::decode(self::fromHex($expected));
        self::assertSame(0x010203, $decoded->ranges[0]->start);
        self::assertSame(4, $decoded->ranges[1]->start);
        self::assertSame(6, $decoded->ranges[1]->end);
    }

    public function testNackGoldenVector(): void
    {
        $packet = new NackPacket([new SequenceRange(7, 7)]);
        self::assertSame('a0000101070000', bin2hex($packet->encode()));
        self::assertSame(7, NackPacket::decode(self::fromHex('a0000101070000'))->ranges[0]->start);
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function invalidPackets(): iterable
    {
        yield 'empty ranges' => [static function (): void {
            new AckPacket([]);
        }];
        yield 'descending range' => [static function (): void {
            new SequenceRange(2, 1);
        }];
        yield 'range over span cap' => [static function (): void {
            new SequenceRange(0, 8_192);
        }];
        yield 'wrong ACK ID' => [static function (): void {
            AckPacket::decode("\xa0\x00\x01\x01\x00\x00\x00");
        }];
        yield 'wrong NACK ID' => [static function (): void {
            NackPacket::decode("\xc0\x00\x01\x01\x00\x00\x00");
        }];
        yield 'zero records' => [static function (): void {
            AckPacket::decode("\xc0\x00\x00");
        }];
        yield 'record count over cap' => [static function (): void {
            AckPacket::decode("\xc0\x00\xd5");
        }];
        yield 'invalid record flag' => [static function (): void {
            AckPacket::decode("\xc0\x00\x01\x02\x00\x00\x00");
        }];
        yield 'represented span overflow' => [static function (): void {
            AckPacket::decode("\xc0\x00\x02\x00\x00\x00\x00\xff\x1f\x00\x01\x00\x20\x00");
        }];
        yield 'trailing byte' => [static function (): void {
            AckPacket::decode("\xc0\x00\x01\x01\x00\x00\x00\x00");
        }];
    }

    #[DataProvider('invalidPackets')]
    public function testInvalidPacketsAreRejected(callable $decode): void
    {
        $this->expectException(CodecException::class);
        $decode();
    }

    public function testRecordAndRepresentedSequenceCaps(): void
    {
        $records = array_fill(0, AcknowledgementCodec::MAXIMUM_RECORDS, new SequenceRange(1, 1));
        self::assertCount(AcknowledgementCodec::MAXIMUM_RECORDS, AckPacket::decode(new AckPacket($records)->encode())->ranges);

        $this->expectException(CodecException::class);
        new AckPacket([...$records, new SequenceRange(2, 2)]);
    }

    public function testEveryAckByteTruncationIsRejected(): void
    {
        $packet = self::fromHex('c000020103020100040000060000');
        for ($length = 0; $length < \strlen($packet); ++$length) {
            try {
                AckPacket::decode(substr($packet, 0, $length));
                self::fail('Truncated ACK accepted at ' . $length);
            } catch (CodecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryNackByteTruncationIsRejected(): void
    {
        $packet = self::fromHex('a0000101070000');
        for ($length = 0; $length < \strlen($packet); ++$length) {
            try {
                NackPacket::decode(substr($packet, 0, $length));
                self::fail('Truncated NACK accepted at ' . $length);
            } catch (CodecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function fromHex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            self::fail('Invalid test vector.');
        }

        return $bytes;
    }
}
