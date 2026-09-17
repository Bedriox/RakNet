<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\OfflineMagic;
use Bedriox\RakNet\Protocol\UnconnectedPing;
use Bedriox\RakNet\Protocol\UnconnectedPong;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnconnectedDiscoveryCodecTest extends TestCase
{
    public function testPingMatchesIndependentWireVector(): void
    {
        $expected = hex2bin('01000000000000000100ffff00fefefefefdfdfdfd123456780000000000000002');
        self::assertIsString($expected);

        self::assertSame($expected, new UnconnectedPing(1, 2)->encode());
        self::assertSame(1, UnconnectedPing::decode($expected)->timestamp);
        self::assertSame(2, UnconnectedPing::decode($expected)->clientGuid);
    }

    public function testPingOpenConnectionsMatchesIndependentWireVector(): void
    {
        $expected = hex2bin('02000000000000000100ffff00fefefefefdfdfdfd123456780000000000000002');
        self::assertIsString($expected);

        $decoded = UnconnectedPing::decode($expected);
        self::assertSame(1, $decoded->timestamp);
        self::assertSame(2, $decoded->clientGuid);
    }

    public function testPongMatchesIndependentWireVector(): void
    {
        $expected = hex2bin('1c0000000000000001000000000000000200ffff00fefefefefdfdfdfd12345678000141');
        self::assertIsString($expected);

        self::assertSame($expected, new UnconnectedPong(1, 2, 'A')->encode());
        self::assertSame('A', UnconnectedPong::decode($expected)->status);
    }

    public function testPingRoundTripPreservesTimestampAndGuid(): void
    {
        $ping = new UnconnectedPing(1_726_000_123_456, 4_294_967_297);
        $encoded = $ping->encode();
        $decoded = UnconnectedPing::decode($encoded);

        self::assertSame(UnconnectedPing::LENGTH, \strlen($encoded));
        self::assertSame(UnconnectedPing::ID, \ord($encoded[0]));
        self::assertSame(OfflineMagic::BYTES, substr($encoded, 9, OfflineMagic::LENGTH));
        self::assertSame($ping->timestamp, $decoded->timestamp);
        self::assertSame($ping->clientGuid, $decoded->clientGuid);
    }

    public function testPongRoundTripPreservesFields(): void
    {
        $pong = new UnconnectedPong(123_456, 987_654, 'MCPE;Bedriox;0;0.0.0;0;20;987654;PHP;Creative;1;19132;19133');
        $encoded = $pong->encode();
        $decoded = UnconnectedPong::decode($encoded);

        self::assertSame(UnconnectedPong::ID, \ord($encoded[0]));
        self::assertSame(OfflineMagic::BYTES, substr($encoded, 17, OfflineMagic::LENGTH));
        self::assertSame($pong->timestamp, $decoded->timestamp);
        self::assertSame($pong->serverGuid, $decoded->serverGuid);
        self::assertSame($pong->status, $decoded->status);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPings(): iterable
    {
        $valid = new UnconnectedPing(42, 84)->encode();

        yield 'empty' => [''];
        yield 'truncated' => [substr($valid, 0, -1)];
        yield 'trailing byte' => [$valid . "\x00"];
        yield 'wrong identifier' => ["\x03" . substr($valid, 1)];
        yield 'wrong magic' => [substr_replace($valid, "\x01", 9, 1)];
    }

    #[DataProvider('malformedPings')]
    public function testMalformedPingIsRejected(string $payload): void
    {
        $this->expectException(CodecException::class);
        UnconnectedPing::decode($payload);
    }

    public function testOversizedStatusIsRejected(): void
    {
        $this->expectException(CodecException::class);
        new UnconnectedPong(1, 2, str_repeat('a', UnconnectedPong::MAXIMUM_STATUS_BYTES + 1));
    }

    public function testHighBitClientGuidRoundTripsAsSignedPhpInteger(): void
    {
        $encoded = new UnconnectedPing(1, -1)->encode();

        self::assertSame(str_repeat('ff', 8), bin2hex(substr($encoded, -8)));
        self::assertSame(-1, UnconnectedPing::decode($encoded)->clientGuid);
    }

    public function testNegativeServerGuidIsRejected(): void
    {
        $this->expectException(CodecException::class);
        $this->expectExceptionMessage('nonnegative 63-bit');
        new UnconnectedPong(1, -1, 'status');
    }

    public function testMaximumSignedIntegerGuidRoundTrips(): void
    {
        $ping = UnconnectedPing::decode(new UnconnectedPing(PHP_INT_MAX, PHP_INT_MAX)->encode());
        self::assertSame(PHP_INT_MAX, $ping->timestamp);
        self::assertSame(PHP_INT_MAX, $ping->clientGuid);

        $pong = UnconnectedPong::decode(new UnconnectedPong(PHP_INT_MAX, PHP_INT_MAX, 'status')->encode());
        self::assertSame(PHP_INT_MAX, $pong->timestamp);
        self::assertSame(PHP_INT_MAX, $pong->serverGuid);
    }
}
