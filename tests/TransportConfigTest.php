<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\TransportConfig;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransportConfigTest extends TestCase
{
    public function testDefaultsAreSafeAndUsable(): void
    {
        $config = new TransportConfig();

        self::assertSame('0.0.0.0', $config->bindAddress);
        self::assertSame(19_132, $config->port);
        self::assertSame(1_400, $config->maximumTransmissionUnit);
        self::assertSame(1_024, $config->maximumSessions);
        self::assertSame(1_024, $config->maximumPendingHandshakes);
        self::assertSame(5_000, $config->handshakeTimeoutMilliseconds);
        self::assertSame(10, $config->connectedSessionMaintenanceIntervalMilliseconds);
        self::assertSame(4_096, $config->maximumReceivedPayloads);
        self::assertSame(2_097_152, $config->maximumReceivedPayloadBytes);
        self::assertSame(4_096, $config->maximumPendingOutboundDatagrams);
        self::assertSame(4_194_304, $config->maximumPendingOutboundBytes);
        self::assertSame(65_535, $config->maximumSessionEvents);
        self::assertSame(1_024, $config->maximumHandshakeDiagnosticEvents);
        self::assertSame(4_194_304, $config->socketReceiveBufferBytes);
        self::assertSame(4_194_304, $config->socketSendBufferBytes);
    }

    /** @return iterable<string, array{Closure(): TransportConfig}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty address' => [fn(): TransportConfig => new TransportConfig(bindAddress: '')];
        yield 'negative port' => [fn(): TransportConfig => new TransportConfig(port: -1)];
        yield 'large port' => [fn(): TransportConfig => new TransportConfig(port: 65_536)];
        yield 'small mtu' => [fn(): TransportConfig => new TransportConfig(maximumTransmissionUnit: 575)];
        yield 'large mtu' => [fn(): TransportConfig => new TransportConfig(maximumTransmissionUnit: 1_493)];
        yield 'no sessions' => [fn(): TransportConfig => new TransportConfig(maximumSessions: 0)];
        yield 'too many sessions' => [fn(): TransportConfig => new TransportConfig(maximumSessions: 65_536)];
        yield 'no pending handshakes' => [fn(): TransportConfig => new TransportConfig(maximumPendingHandshakes: 0)];
        yield 'too many pending handshakes' => [fn(): TransportConfig => new TransportConfig(maximumPendingHandshakes: 65_536)];
        yield 'short handshake timeout' => [fn(): TransportConfig => new TransportConfig(handshakeTimeoutMilliseconds: 99)];
        yield 'long handshake timeout' => [fn(): TransportConfig => new TransportConfig(handshakeTimeoutMilliseconds: 60_001)];
        yield 'no connected-session maintenance interval' => [fn(): TransportConfig => new TransportConfig(connectedSessionMaintenanceIntervalMilliseconds: 0)];
        yield 'long connected-session maintenance interval' => [fn(): TransportConfig => new TransportConfig(connectedSessionMaintenanceIntervalMilliseconds: 51)];
        yield 'no received payloads' => [fn(): TransportConfig => new TransportConfig(maximumReceivedPayloads: 0)];
        yield 'too many received payloads' => [fn(): TransportConfig => new TransportConfig(maximumReceivedPayloads: 65_536)];
        yield 'no received payload bytes' => [fn(): TransportConfig => new TransportConfig(maximumReceivedPayloadBytes: 0)];
        yield 'too many received payload bytes' => [fn(): TransportConfig => new TransportConfig(maximumReceivedPayloadBytes: 67_108_865)];
        yield 'no outbound datagrams' => [fn(): TransportConfig => new TransportConfig(maximumPendingOutboundDatagrams: 0)];
        yield 'too many outbound datagrams' => [fn(): TransportConfig => new TransportConfig(maximumPendingOutboundDatagrams: 65_536)];
        yield 'outbound bytes below minimum datagram' => [fn(): TransportConfig => new TransportConfig(maximumPendingOutboundBytes: 547)];
        yield 'outbound bytes below configured MTU budget' => [fn(): TransportConfig => new TransportConfig(maximumTransmissionUnit: 1_492, maximumPendingOutboundBytes: 1_463)];
        yield 'too many outbound bytes' => [fn(): TransportConfig => new TransportConfig(maximumPendingOutboundBytes: 67_108_865)];
        yield 'event capacity below session capacity' => [fn(): TransportConfig => new TransportConfig(maximumSessions: 2, maximumSessionEvents: 1)];
        yield 'too many lifecycle events' => [fn(): TransportConfig => new TransportConfig(maximumSessionEvents: 65_536)];
        yield 'no handshake diagnostic events' => [fn(): TransportConfig => new TransportConfig(maximumHandshakeDiagnosticEvents: 0)];
        yield 'too many handshake diagnostic events' => [fn(): TransportConfig => new TransportConfig(maximumHandshakeDiagnosticEvents: 65_536)];
        yield 'small receive socket buffer' => [fn(): TransportConfig => new TransportConfig(socketReceiveBufferBytes: 65_535)];
        yield 'large receive socket buffer' => [fn(): TransportConfig => new TransportConfig(socketReceiveBufferBytes: 67_108_865)];
        yield 'small send socket buffer' => [fn(): TransportConfig => new TransportConfig(socketSendBufferBytes: 65_535)];
        yield 'large send socket buffer' => [fn(): TransportConfig => new TransportConfig(socketSendBufferBytes: 67_108_865)];
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(Closure $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }
}
