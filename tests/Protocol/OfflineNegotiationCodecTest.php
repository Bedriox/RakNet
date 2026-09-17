<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\BinaryReader;
use Bedriox\RakNet\Protocol\BinaryWriter;
use Bedriox\RakNet\Protocol\IncompatibleProtocolVersion;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\OfflineMagic;
use Bedriox\RakNet\Protocol\OpenConnectionReply1;
use Bedriox\RakNet\Protocol\OpenConnectionReply2;
use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use Bedriox\RakNet\Protocol\OpenConnectionRequest2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineNegotiationCodecTest extends TestCase
{
    private const string MAGIC_HEX = '00ffff00fefefefefdfdfdfd12345678';

    public function testIpv4AddressMatchesIndependentWireVector(): void
    {
        $writer = new BinaryWriter(InternetAddress::IPV4_LENGTH);
        new InternetAddress('127.0.0.1', 19132)->encode($writer);
        self::assertSame('0480fffffe4abc', bin2hex($writer->bytes()));

        $reader = new BinaryReader(self::fromHex('0480fffffe4abc'), InternetAddress::IPV4_LENGTH);
        $address = InternetAddress::decode($reader);
        $reader->requireEnd();
        self::assertSame('127.0.0.1', $address->address);
        self::assertSame(19132, $address->port);
    }

    public function testIpv6AddressMatchesIndependentWireVector(): void
    {
        $expected = self::fromHex('0617004abc0102030420010db800000000000000000000000100000005');
        $writer = new BinaryWriter(InternetAddress::IPV6_LENGTH);
        new InternetAddress('2001:db8::1', 19132, 0x01020304, 5)->encode($writer);
        self::assertSame($expected, $writer->bytes());

        $reader = new BinaryReader($expected, InternetAddress::IPV6_LENGTH);
        $address = InternetAddress::decode($reader);
        $reader->requireEnd();
        self::assertSame('2001:db8::1', $address->address);
        self::assertSame(19132, $address->port);
        self::assertSame(0x01020304, $address->flowInfo);
        self::assertSame(5, $address->scopeId);
    }

    public function testRequest1RoundTripDerivesMtuFromDatagramLength(): void
    {
        $packet = new OpenConnectionRequest1(11, 1_400);
        $encoded = $packet->encode();
        $decoded = OpenConnectionRequest1::decode($encoded, 1_400);

        self::assertSame(1_372, \strlen($encoded));
        self::assertSame(0x05, \ord($encoded[0]));
        self::assertSame(OfflineMagic::BYTES, substr($encoded, 1, 16));
        self::assertSame(11, $decoded->protocolVersion);
        self::assertSame(1_400, $decoded->mtu);
    }

    public function testRequest1MatchesIndependentMinimumVector(): void
    {
        $expected = self::fromHex('05' . self::MAGIC_HEX . '0b') . str_repeat("\x00", 576 - 28 - 18);
        self::assertSame($expected, new OpenConnectionRequest1(11, 576)->encode());
        self::assertSame(576, OpenConnectionRequest1::decode($expected, 1_492)->mtu);
    }

    public function testReply1MatchesIndependentWireVector(): void
    {
        $packet = new OpenConnectionReply1(2, 576);
        $expected = '06' . self::MAGIC_HEX . '0000000000000002000240';
        self::assertSame($expected, bin2hex($packet->encode()));

        $decoded = OpenConnectionReply1::decode(self::fromHex($expected));
        self::assertSame(2, $decoded->serverGuid);
        self::assertSame(576, $decoded->mtu);
    }

    public function testRequest2MatchesIndependentWireVector(): void
    {
        $packet = new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, 3);
        $expected = '07' . self::MAGIC_HEX . '0480fffffe4abc02400000000000000003';
        self::assertSame($expected, bin2hex($packet->encode()));

        $decoded = OpenConnectionRequest2::decode(self::fromHex($expected));
        self::assertSame('127.0.0.1', $decoded->serverAddress->address);
        self::assertSame(19132, $decoded->serverAddress->port);
        self::assertSame(576, $decoded->mtu);
        self::assertSame(3, $decoded->clientGuid);
    }

    public function testRequest2PreservesHighBitClientGuidWirePattern(): void
    {
        $packet = new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, -1);
        $encoded = $packet->encode();

        self::assertSame(str_repeat('ff', 8), bin2hex(substr($encoded, -8)));
        self::assertSame(-1, OpenConnectionRequest2::decode($encoded)->clientGuid);
    }

    public function testReply2MatchesIndependentWireVector(): void
    {
        $packet = new OpenConnectionReply2(2, new InternetAddress('127.0.0.1', 19132), 576);
        $expected = '08' . self::MAGIC_HEX . '00000000000000020480fffffe4abc024000';
        self::assertSame($expected, bin2hex($packet->encode()));

        $decoded = OpenConnectionReply2::decode(self::fromHex($expected));
        self::assertSame(2, $decoded->serverGuid);
        self::assertSame('127.0.0.1', $decoded->clientAddress->address);
        self::assertSame(19132, $decoded->clientAddress->port);
        self::assertSame(576, $decoded->mtu);
    }

    public function testIncompatibleVersionMatchesIndependentWireVector(): void
    {
        $expected = '190b' . self::MAGIC_HEX . '0000000000000002';
        self::assertSame($expected, bin2hex(new IncompatibleProtocolVersion(11, 2)->encode()));
        $decoded = IncompatibleProtocolVersion::decode(self::fromHex($expected));
        self::assertSame(11, $decoded->protocolVersion);
        self::assertSame(2, $decoded->serverGuid);
    }

    public function testIpv4EdgeVectors(): void
    {
        foreach ([
            ['0.0.0.0', 0, '04ffffffff0000'],
            ['255.255.255.255', 65_535, '0400000000ffff'],
        ] as [$ip, $port, $hex]) {
            $writer = new BinaryWriter(InternetAddress::IPV4_LENGTH);
            new InternetAddress($ip, $port)->encode($writer);
            self::assertSame($hex, bin2hex($writer->bytes()));
            $decoded = InternetAddress::decode(new BinaryReader(self::fromHex($hex), InternetAddress::IPV4_LENGTH));
            self::assertSame($ip, $decoded->address);
            self::assertSame($port, $decoded->port);
        }
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function truncatedPackets(): iterable
    {
        $packets = [
            'request1' => new OpenConnectionRequest1(11, 576)->encode(),
            'reply1' => new OpenConnectionReply1(2, 576)->encode(),
            'request2' => new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, 3)->encode(),
            'reply2' => new OpenConnectionReply2(2, new InternetAddress('127.0.0.1', 19132), 576)->encode(),
            'incompatible' => new IncompatibleProtocolVersion(11, 2)->encode(),
        ];

        foreach ($packets as $name => $packet) {
            for ($length = 0; $length < \strlen($packet); ++$length) {
                $truncated = substr($packet, 0, $length);
                yield $name . '-at-' . $length => [static function () use ($name, $truncated): void {
                    match ($name) {
                        'request1' => OpenConnectionRequest1::decode($truncated, 1_492),
                        'reply1' => OpenConnectionReply1::decode($truncated),
                        'request2' => OpenConnectionRequest2::decode($truncated),
                        'reply2' => OpenConnectionReply2::decode($truncated),
                        'incompatible' => IncompatibleProtocolVersion::decode($truncated),
                    };
                }];
            }
        }
    }

    #[DataProvider('truncatedPackets')]
    public function testEveryTruncationOffsetIsRejected(callable $decode): void
    {
        $this->expectException(CodecException::class);
        $decode();
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function wrongIdentifiersAndMagic(): iterable
    {
        $packets = [
            'request1' => new OpenConnectionRequest1(11, 576)->encode(),
            'reply1' => new OpenConnectionReply1(2, 576)->encode(),
            'request2' => new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, 3)->encode(),
            'reply2' => new OpenConnectionReply2(2, new InternetAddress('127.0.0.1', 19132), 576)->encode(),
            'incompatible' => new IncompatibleProtocolVersion(11, 2)->encode(),
        ];

        foreach ($packets as $name => $packet) {
            foreach ([0, $name === 'incompatible' ? 2 : 1] as $offset) {
                $corrupt = substr_replace($packet, "\xaa", $offset, 1);
                yield $name . '-offset-' . $offset => [static function () use ($name, $corrupt): void {
                    match ($name) {
                        'request1' => OpenConnectionRequest1::decode($corrupt, 1_492),
                        'reply1' => OpenConnectionReply1::decode($corrupt),
                        'request2' => OpenConnectionRequest2::decode($corrupt),
                        'reply2' => OpenConnectionReply2::decode($corrupt),
                        'incompatible' => IncompatibleProtocolVersion::decode($corrupt),
                    };
                }];
            }
        }
    }

    #[DataProvider('wrongIdentifiersAndMagic')]
    public function testWrongIdentifiersAndMagicAreRejected(callable $decode): void
    {
        $this->expectException(CodecException::class);
        $decode();
    }

    /** @return iterable<string, array{int}> */
    public static function paddingOffsets(): iterable
    {
        yield 'first' => [18];
        yield 'middle' => [283];
        yield 'last' => [547];
    }

    #[DataProvider('paddingOffsets')]
    public function testRequest1RejectsNonzeroPaddingAtEveryRegion(int $offset): void
    {
        $packet = new OpenConnectionRequest1(11, 576)->encode();
        $this->expectException(CodecException::class);
        OpenConnectionRequest1::decode(substr_replace($packet, "\x01", $offset, 1), 1_492);
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function invalidMtus(): iterable
    {
        yield 'request1 low constructor' => [static function (): void {
            new OpenConnectionRequest1(11, 575);
        }];
        yield 'request1 high constructor' => [static function (): void {
            new OpenConnectionRequest1(11, 1_493);
        }];
        yield 'request1 low decoder maximum' => [static function (): void {
            OpenConnectionRequest1::decode(new OpenConnectionRequest1(11, 576)->encode(), 575);
        }];
        yield 'request1 high decoder maximum' => [static function (): void {
            OpenConnectionRequest1::decode(new OpenConnectionRequest1(11, 576)->encode(), 1_493);
        }];
        yield 'reply1 low wire' => [static function (): void {
            OpenConnectionReply1::decode(substr_replace(new OpenConnectionReply1(2, 576)->encode(), pack('n', 575), 26, 2));
        }];
        yield 'request2 high wire' => [static function (): void {
            OpenConnectionRequest2::decode(substr_replace(new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 1), 576, 2)->encode(), pack('n', 1_493), 24, 2));
        }];
        yield 'reply2 low wire' => [static function (): void {
            OpenConnectionReply2::decode(substr_replace(new OpenConnectionReply2(2, new InternetAddress('127.0.0.1', 1), 576)->encode(), pack('n', 575), 32, 2));
        }];
    }

    #[DataProvider('invalidMtus')]
    public function testInvalidMtusAreRejected(callable $decode): void
    {
        $this->expectException(CodecException::class);
        $decode();
    }

    /** @return iterable<string, array{callable(): void}> */
    public static function malformedPackets(): iterable
    {
        yield 'request 1 below minimum MTU' => [static function (): void {
            OpenConnectionRequest1::decode("\x05" . OfflineMagic::BYTES . "\x0b", 1_400);
        }];
        yield 'request 1 nonzero padding' => [static function (): void {
            $packet = new OpenConnectionRequest1(11, 576)->encode();
            OpenConnectionRequest1::decode(substr($packet, 0, -1) . "\x01", 1_400);
        }];
        yield 'request 2 trailing byte' => [static function (): void {
            $packet = new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, 3)->encode();
            OpenConnectionRequest2::decode($packet . "\x00");
        }];
        yield 'request 2 IPv6 discriminator' => [static function (): void {
            $packet = new OpenConnectionRequest2(new InternetAddress('127.0.0.1', 19132), 576, 3)->encode();
            OpenConnectionRequest2::decode(substr_replace($packet, "\x06", 17, 1));
        }];
        yield 'reply 1 security enabled' => [static function (): void {
            $packet = new OpenConnectionReply1(2, 576)->encode();
            OpenConnectionReply1::decode(substr_replace($packet, "\x01", 25, 1));
        }];
        yield 'reply 2 security enabled' => [static function (): void {
            $packet = new OpenConnectionReply2(2, new InternetAddress('127.0.0.1', 19132), 576)->encode();
            OpenConnectionReply2::decode(substr_replace($packet, "\x01", -1, 1));
        }];
    }

    #[DataProvider('malformedPackets')]
    public function testMalformedPacketsAreRejected(callable $decode): void
    {
        $this->expectException(CodecException::class);
        $decode();
    }

    private static function fromHex(string $hex): string
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            self::fail('Invalid test vector hexadecimal.');
        }

        return $bytes;
    }
}
