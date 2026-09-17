<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Protocol;

use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Protocol\ConnectedPing;
use Bedriox\RakNet\Protocol\ConnectedPong;
use Bedriox\RakNet\Protocol\ConnectionRequest;
use Bedriox\RakNet\Protocol\ConnectionRequestAccepted;
use Bedriox\RakNet\Protocol\DisconnectNotification;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\NewIncomingConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectedControlCodecTest extends TestCase
{
    public function testKnownPingPongAndRequestVectors(): void
    {
        $ping = hex2bin('000102030405060708');
        $pong = hex2bin('0301020304050607081112131415161718');
        $request = hex2bin('090102030405060708111213141516171800');
        self::assertIsString($ping);
        self::assertIsString($pong);
        self::assertIsString($request);

        self::assertSame($ping, ConnectedPing::decode($ping)->encode());
        self::assertSame($pong, ConnectedPong::decode($pong)->encode());
        self::assertSame($request, ConnectionRequest::decode($request)->encode());
        self::assertSame("\x15", DisconnectNotification::decode("\x15")->encode());
    }

    public function testConnectionRequestPreservesHighBitClientGuidWirePattern(): void
    {
        $encoded = new ConnectionRequest(-1, 1, false)->encode();

        self::assertSame(str_repeat('ff', 8), bin2hex(substr($encoded, 1, 8)));
        self::assertSame(-1, ConnectionRequest::decode($encoded)->clientGuid);
    }

    public function testKnownConnectionAcceptanceVector(): void
    {
        $internal = [new InternetAddress('127.0.0.1', 0)];
        while (\count($internal) < ConnectionRequestAccepted::INTERNAL_ADDRESS_COUNT) {
            $internal[] = new InternetAddress('0.0.0.0', 0);
        }
        $packet = new ConnectionRequestAccepted(
            new InternetAddress('192.168.1.2', 19132),
            0,
            $internal,
            0x0102030405060708,
            0x1112131415161718,
        );
        $expected = hex2bin(
            '10043f57fefd4abc0000'
            . '0480fffffe0000'
            . str_repeat('04ffffffff0000', 19)
            . '0102030405060708'
            . '1112131415161718',
        );
        self::assertIsString($expected);
        self::assertSame(166, \strlen($expected));
        self::assertSame($expected, $packet->encode());
        self::assertSame($expected, ConnectionRequestAccepted::decode($expected)->encode());
    }

    public function testKnownNewIncomingConnectionVector(): void
    {
        $internal = array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0));
        $packet = new NewIncomingConnection(
            new InternetAddress('127.0.0.1', 19132),
            $internal,
            0x0102030405060708,
            0x1112131415161718,
        );
        $expected = hex2bin(
            '130480fffffe4abc'
            . str_repeat('04ffffffff0000', 20)
            . '0102030405060708'
            . '1112131415161718',
        );
        self::assertIsString($expected);
        self::assertSame(164, \strlen($expected));
        self::assertSame($expected, $packet->encode());
        self::assertSame($expected, NewIncomingConnection::decode($expected)->encode());
    }

    public function testRetailLengthMixedAddressNewIncomingRoundTrips(): void
    {
        $internal = [new InternetAddress('2001:db8::1', 19132)];
        while (\count($internal) < NewIncomingConnection::INTERNAL_ADDRESS_COUNT) {
            $internal[] = new InternetAddress('0.0.0.0', 0);
        }
        $packet = new NewIncomingConnection(
            new InternetAddress('127.0.0.1', 19132),
            $internal,
            1,
            2,
        );

        $encoded = $packet->encode();
        self::assertSame(186, \strlen($encoded));
        $decoded = NewIncomingConnection::decode($encoded);
        self::assertSame('2001:db8::1', $decoded->internalAddresses[0]->address);
        self::assertSame($encoded, $decoded->encode());
    }

    /** @return iterable<string, array{callable(string): object, string}> */
    public static function malformedPackets(): iterable
    {
        yield 'ping' => [ConnectedPing::decode(...), hex2bin('000102030405060708') ?: ''];
        yield 'pong' => [ConnectedPong::decode(...), hex2bin('0301020304050607081112131415161718') ?: ''];
        yield 'request' => [ConnectionRequest::decode(...), hex2bin('090102030405060708111213141516171800') ?: ''];
        yield 'disconnect' => [DisconnectNotification::decode(...), "\x15"];
        yield 'accepted' => [ConnectionRequestAccepted::decode(...), self::acceptanceBytes()];
        yield 'new incoming' => [NewIncomingConnection::decode(...), self::newIncomingBytes()];
    }

    /** @param callable(string): object $decoder */
    #[DataProvider('malformedPackets')]
    public function testEveryCodecRejectsTruncationAndTrailingBytes(callable $decoder, string $valid): void
    {
        foreach (range(0, \strlen($valid) - 1) as $length) {
            try {
                $decoder(substr($valid, 0, $length));
                self::fail('Truncated control packet was accepted at length ' . $length . '.');
            } catch (CodecException) {
            }
        }

        $this->expectException(CodecException::class);
        $decoder($valid . "\x00");
    }

    private static function acceptanceBytes(): string
    {
        $internal = array_fill(0, ConnectionRequestAccepted::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0));

        return new ConnectionRequestAccepted(new InternetAddress('127.0.0.1', 1), 0, $internal, 1, 2)->encode();
    }

    private static function newIncomingBytes(): string
    {
        $internal = array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0));

        return new NewIncomingConnection(new InternetAddress('127.0.0.1', 1), $internal, 1, 2)->encode();
    }
}
