<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\BinaryReader;
use Bedriox\RakNet\Protocol\BinaryWriter;
use Bedriox\RakNet\Protocol\BitPayload;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\EncapsulatedFrame;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\SplitMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

final class ConnectedDatagramCodecTest extends TestCase
{
    public function testUnreliableFrameGoldenVector(): void
    {
        $frame = new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('A', 8));
        self::assertSame('00000841', bin2hex(self::encodeFrame($frame)));
        $decoded = self::decodeFrame("\x00\x00\x08A");
        self::assertSame(Reliability::Unreliable, $decoded->reliability);
        self::assertSame('A', $decoded->payload->bytes);
    }

    public function testReliableOrderedFrameGoldenVector(): void
    {
        $frame = new EncapsulatedFrame(
            Reliability::ReliableOrdered,
            new BitPayload('A', 8),
            reliableIndex: 0x010203,
            orderingIndex: 0x040506,
            orderingChannel: 7,
        );
        $expected = '6000080302010605040741';
        self::assertSame($expected, bin2hex(self::encodeFrame($frame)));
        $decoded = self::decodeFrame(self::fromHex($expected));
        self::assertSame(0x010203, $decoded->reliableIndex);
        self::assertSame(0x040506, $decoded->orderingIndex);
        self::assertSame(7, $decoded->orderingChannel);
    }

    public function testSplitReliableFrameGoldenVector(): void
    {
        $frame = new EncapsulatedFrame(
            Reliability::Reliable,
            new BitPayload('A', 8),
            reliableIndex: 0x010203,
            split: new SplitMetadata(2, 0x1234, 1),
        );
        $expected = '5000080302010000000212340000000141';
        self::assertSame($expected, bin2hex(self::encodeFrame($frame)));
        $decoded = self::decodeFrame(self::fromHex($expected));
        self::assertNotNull($decoded->split);
        self::assertSame(2, $decoded->split->count);
        self::assertSame(0x1234, $decoded->split->id);
        self::assertSame(1, $decoded->split->index);
    }

    public function testConnectedDatagramGoldenVector(): void
    {
        $packet = new ConnectedDatagram(
            0x80,
            0x010203,
            [new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('A', 8))],
        );
        $expected = '8003020100000841';
        self::assertSame($expected, bin2hex($packet->encode()));
        $decoded = ConnectedDatagram::decode(self::fromHex($expected));
        self::assertSame(0x010203, $decoded->sequenceNumber);
        self::assertCount(1, $decoded->frames);
    }

    public function testExactNonBytePayloadLength(): void
    {
        $payload = new BitPayload("\xff\x80", 9);
        $frame = new EncapsulatedFrame(Reliability::Unreliable, $payload);
        self::assertSame('000009ff80', bin2hex(self::encodeFrame($frame)));
        self::assertSame(9, self::decodeFrame(self::encodeFrame($frame))->payload->bitLength);
    }

    public function testConfiguredBoundariesAreAccepted(): void
    {
        self::assertSame(
            BitPayload::MAXIMUM_BITS,
            new BitPayload(str_repeat("\x00", 1_492), BitPayload::MAXIMUM_BITS)->bitLength,
        );
        self::assertSame(
            SplitMetadata::MAXIMUM_PARTS - 1,
            new SplitMetadata(SplitMetadata::MAXIMUM_PARTS, 0xffff, SplitMetadata::MAXIMUM_PARTS - 1)->index,
        );

        $frame = new EncapsulatedFrame(
            Reliability::ReliableOrdered,
            new BitPayload('A', 8),
            reliableIndex: 0,
            orderingIndex: 0,
            orderingChannel: EncapsulatedFrame::MAXIMUM_ORDERING_CHANNELS - 1,
        );
        self::assertSame(EncapsulatedFrame::MAXIMUM_ORDERING_CHANNELS - 1, $frame->orderingChannel);
    }

    /** @return iterable<string, array{Reliability, ?int, ?int, ?int, ?int}> */
    public static function reliabilityModes(): iterable
    {
        yield 'unreliable' => [Reliability::Unreliable, null, null, null, null];
        yield 'unreliable sequenced' => [Reliability::UnreliableSequenced, null, 2, 3, 4];
        yield 'reliable' => [Reliability::Reliable, 1, null, null, null];
        yield 'reliable ordered' => [Reliability::ReliableOrdered, 1, null, 3, 4];
        yield 'reliable sequenced' => [Reliability::ReliableSequenced, 1, 2, 3, 4];
        yield 'unreliable receipt' => [Reliability::UnreliableWithAckReceipt, null, null, null, null];
        yield 'reliable receipt' => [Reliability::ReliableWithAckReceipt, 1, null, null, null];
        yield 'reliable ordered receipt' => [Reliability::ReliableOrderedWithAckReceipt, 1, null, 3, 4];
    }

    #[DataProvider('reliabilityModes')]
    public function testEveryReliabilityModeRoundTrips(
        Reliability $reliability,
        ?int $reliableIndex,
        ?int $sequenceIndex,
        ?int $orderingIndex,
        ?int $orderingChannel,
    ): void {
        $frame = new EncapsulatedFrame(
            $reliability,
            new BitPayload('A', 8),
            $reliableIndex,
            $sequenceIndex,
            $orderingIndex,
            $orderingChannel,
        );

        self::assertSame($reliability, self::decodeFrame(self::encodeFrame($frame))->reliability);
    }

    public function testReliabilityRejectsValueOutsideThreeBitMode(): void
    {
        $this->expectException(ValueError::class);
        Reliability::from(8);
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function invalidValues(): iterable
    {
        yield 'empty payload' => [static function (): void {
            new BitPayload('', 0);
        }];
        yield 'payload length mismatch' => [static function (): void {
            new BitPayload('AA', 8);
        }];
        yield 'nonzero unused bits' => [static function (): void {
            new BitPayload("\xff\x81", 9);
        }];
        yield 'payload over bit cap' => [static function (): void {
            new BitPayload(str_repeat("\x00", 1_493), BitPayload::MAXIMUM_BITS + 1);
        }];
        yield 'split count one' => [static function (): void {
            new SplitMetadata(1, 0, 0);
        }];
        yield 'split count over cap' => [static function (): void {
            new SplitMetadata(1_025, 0, 0);
        }];
        yield 'split ID negative' => [static function (): void {
            new SplitMetadata(2, -1, 0);
        }];
        yield 'split ID over uint16' => [static function (): void {
            new SplitMetadata(2, 0x1_0000, 0);
        }];
        yield 'split index negative' => [static function (): void {
            new SplitMetadata(2, 0, -1);
        }];
        yield 'split index equals count' => [static function (): void {
            new SplitMetadata(2, 0, 2);
        }];
        yield 'missing reliable index' => [static function (): void {
            new EncapsulatedFrame(Reliability::Reliable, new BitPayload('A', 8));
        }];
        yield 'forbidden reliable index' => [static function (): void {
            new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('A', 8), reliableIndex: 1);
        }];
        yield 'missing sequence index' => [static function (): void {
            new EncapsulatedFrame(Reliability::UnreliableSequenced, new BitPayload('A', 8), orderingIndex: 1, orderingChannel: 0);
        }];
        yield 'forbidden sequence index' => [static function (): void {
            new EncapsulatedFrame(Reliability::Reliable, new BitPayload('A', 8), reliableIndex: 1, sequenceIndex: 1);
        }];
        yield 'missing ordering index' => [static function (): void {
            new EncapsulatedFrame(Reliability::ReliableOrdered, new BitPayload('A', 8), reliableIndex: 1, orderingChannel: 0);
        }];
        yield 'forbidden ordering index' => [static function (): void {
            new EncapsulatedFrame(Reliability::Reliable, new BitPayload('A', 8), reliableIndex: 1, orderingIndex: 1);
        }];
        yield 'missing ordering channel' => [static function (): void {
            new EncapsulatedFrame(Reliability::ReliableOrdered, new BitPayload('A', 8), reliableIndex: 1, orderingIndex: 1);
        }];
        yield 'ordering channel over cap' => [static function (): void {
            new EncapsulatedFrame(Reliability::ReliableOrdered, new BitPayload('A', 8), reliableIndex: 1, orderingIndex: 1, orderingChannel: 32);
        }];
        yield 'datagram invalid flags' => [static function (): void {
            new ConnectedDatagram(0xc0, 0, [new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('A', 8))]);
        }];
        yield 'datagram invalid wire flags' => [static function (): void {
            ConnectedDatagram::decode("\xc0\x00\x00\x00");
        }];
        yield 'datagram empty frames' => [static function (): void {
            new ConnectedDatagram(0x80, 0, []);
        }];
        yield 'datagram over frame cap' => [static function (): void {
            $frame = new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('A', 8));
            new ConnectedDatagram(0x80, 0, array_fill(0, ConnectedDatagram::MAXIMUM_FRAMES + 1, $frame));
        }];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(callable $factory): void
    {
        $this->expectException(CodecException::class);
        $factory();
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFrames(): iterable
    {
        yield 'reserved low flag' => ["\x01\x00\x08A"];
        yield 'zero bits' => ["\x00\x00\x00"];
        yield 'oversized bits' => ["\x00\xff\xff"];
        yield 'bad partial padding' => ["\x00\x00\x09\xff\x81"];
        yield 'trailing becomes truncated frame' => ["\x80\x00\x00\x00\x00\x00\x08A\x00"];
    }

    #[DataProvider('malformedFrames')]
    public function testMalformedFramesAndDatagramsAreRejected(string $bytes): void
    {
        $this->expectException(CodecException::class);
        if ($bytes[0] === "\x80") {
            ConnectedDatagram::decode($bytes);
        } else {
            self::decodeFrame($bytes);
        }
    }

    public function testEveryDatagramByteTruncationIsRejected(): void
    {
        $packet = self::fromHex('800302016000080302010605040741');
        for ($length = 0; $length < \strlen($packet); ++$length) {
            try {
                ConnectedDatagram::decode(substr($packet, 0, $length));
                self::fail('Truncated datagram accepted at ' . $length);
            } catch (CodecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEverySplitFrameByteTruncationIsRejected(): void
    {
        $frame = self::fromHex('5000080302010000000212340000000141');
        for ($length = 0; $length < \strlen($frame); ++$length) {
            try {
                self::decodeFrame(substr($frame, 0, $length));
                self::fail('Truncated split frame accepted at ' . $length);
            } catch (CodecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOversizedConnectedDatagramIsRejectedBeforeParsing(): void
    {
        $this->expectException(CodecException::class);
        ConnectedDatagram::decode(str_repeat("\x80", ConnectedDatagram::MAXIMUM_BYTES + 1));
    }

    private static function encodeFrame(EncapsulatedFrame $frame): string
    {
        $writer = new BinaryWriter(ConnectedDatagram::MAXIMUM_BYTES);
        $frame->encode($writer);

        return $writer->bytes();
    }

    private static function decodeFrame(string $bytes): EncapsulatedFrame
    {
        $reader = new BinaryReader($bytes, ConnectedDatagram::MAXIMUM_BYTES);
        $frame = EncapsulatedFrame::decode($reader);
        $reader->requireEnd();

        return $frame;
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
