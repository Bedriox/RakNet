<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Integration;

use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\Exception\TransportException;
use Bedriox\RakNet\Protocol\UnconnectedPing;
use Bedriox\RakNet\Protocol\UnconnectedPong;
use Bedriox\RakNet\Tests\MutableClock;
use Bedriox\RakNet\TransportConfig;
use PHPUnit\Framework\TestCase;
use Socket;

final class DiscoveryServerTest extends TestCase
{
    private const int SERVER_GUID = 8_675_309;
    private const string STATUS = 'MCPE;Bedriox Test;999;1.99.0;0;20;8675309;Loopback;Creative;1;19132;19133;';

    private ?DiscoveryServer $server = null;
    private ?Socket $client = null;

    protected function tearDown(): void
    {
        $this->server?->close();
        if ($this->client instanceof Socket) {
            socket_close($this->client);
        }
    }

    public function testLoopbackDiscoveryPreservesTimestampAndAdvertisesStatus(): void
    {
        $this->startServer();
        $pong = $this->exchangePing(777, 123);

        self::assertSame(777, $pong->timestamp);
        self::assertSame(self::SERVER_GUID, $pong->serverGuid);
        self::assertStringStartsWith('MCPE;Bedriox Test;', $pong->status);
        self::assertStringEndsWith(';', $pong->status);
    }

    public function testIndependentRawSocketVector(): void
    {
        $this->startServer();
        $timestamp = 0x0102030405060708;
        $clientGuid = 0x1112131415161718;
        $offlineMagic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request = "\x01" . pack('J', $timestamp) . $offlineMagic . pack('J', $clientGuid);

        self::assertSame(33, \strlen($request));
        self::assertSame(33, socket_sendto($this->client(), $request, 33, 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));

        $response = $this->receiveWhenReadable(500_000);
        self::assertIsString($response);
        self::assertLessThanOrEqual(387, \strlen($response));
        self::assertSame(0x1c, \ord($response[0]));
        self::assertSame(pack('J', $timestamp), substr($response, 1, 8));
        self::assertSame(pack('J', self::SERVER_GUID), substr($response, 9, 8));
        self::assertSame($offlineMagic, substr($response, 17, 16));

        $length = unpack('n', substr($response, 33, 2));
        if ($length === false) {
            self::fail('Raw response did not contain a status length.');
        }
        $status = substr($response, 35);
        self::assertSame($length[1], \strlen($status));
        self::assertSame('MCPE;Bedriox Test;999;1.99.0;0;20;8675309;Loopback;Creative;1;19132;19133;', $status);
    }

    public function testMaximumOpaqueStatusPreservesExactResponseAmplificationBound(): void
    {
        $payload = str_repeat('x', DiscoveryStatus::MAXIMUM_PAYLOAD_BYTES);
        $this->server = DiscoveryServer::bind(
            new TransportConfig(bindAddress: '127.0.0.1', port: 0),
            self::SERVER_GUID,
            new DiscoveryStatus($payload),
        );
        $timestamp = 0x0102030405060708;
        $clientGuid = 0x1112131415161718;
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request = "\x01" . pack('J', $timestamp) . $magic . pack('J', $clientGuid);

        $response = $this->rawExchange($request);

        self::assertSame(387, \strlen($response));
        self::assertSame("\x1c" . pack('J', $timestamp) . pack('J', self::SERVER_GUID) . $magic . pack('n', 352) . $payload, $response);
    }

    public function testNegativeServerGuidIsRejectedIndependentlyOfOpaqueStatus(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('nonnegative');
        DiscoveryServer::bind(
            new TransportConfig(bindAddress: '127.0.0.1', port: 0),
            -1,
            new DiscoveryStatus(self::STATUS),
        );
    }

    public function testMalformedAndUnknownDatagramsAreIgnoredSafely(): void
    {
        $this->startServer();
        $client = $this->client();

        foreach (["\xff", str_repeat("\x00", UnconnectedPing::LENGTH), substr(new UnconnectedPing(1, 2)->encode(), 0, -1)] as $payload) {
            self::assertSame(\strlen($payload), socket_sendto($client, $payload, \strlen($payload), 0, '127.0.0.1', $this->server()->localPort()));
        }

        self::assertSame(3, $this->awaitHandled(3));
        self::assertFalse($this->receiveWhenReadable(20_000));

        $pong = $this->exchangePing(778, 124);
        self::assertSame(778, $pong->timestamp);
    }

    public function testClosedDiscoveryPeerDoesNotPoisonSharedSocketOnWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Winsock reports late UDP port-unreachable responses through WSAECONNRESET.');
        }

        $this->startServer();
        $abandonedClient = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$abandonedClient instanceof Socket) {
            self::fail('Unable to create abandoned loopback UDP client.');
        }

        $request = new UnconnectedPing(776, 122)->encode();
        self::assertSame(
            \strlen($request),
            socket_sendto($abandonedClient, $request, \strlen($request), 0, '127.0.0.1', $this->server()->localPort()),
        );
        socket_close($abandonedClient);

        self::assertSame(1, $this->awaitHandled(1));
        usleep(10_000);
        self::assertSame(0, $this->server()->poll());

        $pong = $this->exchangePing(778, 124);
        self::assertSame(778, $pong->timestamp);
    }

    public function testPingOpenConnectionsReceivesPongWhenCapacityIsAvailable(): void
    {
        $this->startServer();
        $payload = "\x02" . substr(new UnconnectedPing(1, 2)->encode(), 1);
        self::assertSame(33, socket_sendto($this->client(), $payload, 33, 0, '127.0.0.1', $this->server()->localPort()));

        self::assertSame(1, $this->awaitHandled(1));
        $response = $this->receiveWhenReadable(500_000);
        self::assertIsString($response);
        $pong = UnconnectedPong::decode($response);
        self::assertSame(1, $pong->timestamp);
        self::assertSame(self::SERVER_GUID, $pong->serverGuid);
    }

    public function testPingOpenConnectionsIsIgnoredWhenServerIsFull(): void
    {
        $this->startServer();
        $this->server()->updateDiscoveryStatus(new DiscoveryStatus(self::STATUS, false));
        $payload = "\x02" . substr(new UnconnectedPing(1, 2)->encode(), 1);
        self::assertSame(33, socket_sendto($this->client(), $payload, 33, 0, '127.0.0.1', $this->server()->localPort()));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertFalse($this->receiveWhenReadable(20_000));
    }

    public function testOneHundredSequentialLoopbackPings(): void
    {
        $this->startServer();

        for ($index = 0; $index < 100; ++$index) {
            $pong = $this->exchangePing(10_000 + $index, 20_000 + $index);
            self::assertSame(10_000 + $index, $pong->timestamp);
            self::assertSame(self::SERVER_GUID, $pong->serverGuid);
        }
    }

    public function testRawOfflineNegotiationEstablishesBoundedSession(): void
    {
        $this->startServer();
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $mtu = 576;
        $clientGuid = 123_456_789;
        $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", $mtu - 28 - 18);

        $reply1 = $this->rawExchange($request1);
        self::assertSame(28, \strlen($reply1));
        self::assertSame("\x06" . $magic, substr($reply1, 0, 17));
        self::assertSame(pack('J', self::SERVER_GUID), substr($reply1, 17, 8));
        self::assertSame("\x00", $reply1[25]);
        self::assertSame(pack('n', $mtu), substr($reply1, 26, 2));
        self::assertSame(1, $this->server()->pendingHandshakeCount());

        // Embedded 1.2.3.4:9 is deliberately unrelated; replies must use the observed UDP endpoint.
        $serverAddress = "\x04\xfe\xfd\xfc\xfb\x00\x09";
        $request2 = "\x07" . $magic . $serverAddress . pack('n', $mtu) . pack('J', $clientGuid);
        $reply2 = $this->rawExchange($request2);

        self::assertSame(35, \strlen($reply2));
        self::assertSame("\x08" . $magic, substr($reply2, 0, 17));
        self::assertSame(pack('J', self::SERVER_GUID), substr($reply2, 17, 8));
        self::assertSame("\x04\x80\xff\xff\xfe", substr($reply2, 25, 5));
        self::assertSame(pack('n', $this->clientPort()), substr($reply2, 30, 2));
        self::assertSame(pack('n', $mtu), substr($reply2, 32, 2));
        self::assertSame("\x00", $reply2[34]);
        self::assertSame(0, $this->server()->pendingHandshakeCount());
        self::assertSame(1, $this->server()->sessionCount());

        $session = $this->server()->sessionFor('127.0.0.1', $this->clientPort());
        self::assertNotNull($session);
        self::assertSame($clientGuid, $session->clientGuid);
        self::assertSame($mtu, $session->mtu);
        self::assertSame(11, $session->rakNetProtocolVersion);

        // A lost Reply 2 can be recovered without creating a duplicate session.
        self::assertSame($reply2, $this->rawExchange($request2));
        self::assertSame(1, $this->server()->sessionCount());
    }

    public function testMaximumProbeIsAcceptedAndClampedToConfiguredMtu(): void
    {
        $this->startServer();
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request = "\x05" . $magic . "\x0b" . str_repeat("\x00", 1_492 - 28 - 18);
        self::assertSame(1_464, \strlen($request));

        $response = $this->rawExchange($request);
        self::assertSame(28, \strlen($response));
        self::assertSame(pack('n', 1_400), substr($response, 26, 2));
        self::assertSame(1, $this->server()->pendingHandshakeCount());
    }

    public function testUnsupportedProtocolGetsBoundedIncompatibleVersionReply(): void
    {
        $this->startServer();
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request = "\x05" . $magic . "\x0a" . str_repeat("\x00", 576 - 28 - 18);
        $response = $this->rawExchange($request);

        self::assertSame(26, \strlen($response));
        self::assertSame("\x19\x0b" . $magic . pack('J', self::SERVER_GUID), $response);
        self::assertSame(0, $this->server()->pendingHandshakeCount());
        self::assertSame(0, $this->server()->sessionCount());
    }

    public function testRequest2WithoutRequest1IsIgnored(): void
    {
        $this->startServer();
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $serverAddress = "\x04\x80\xff\xff\xfe" . pack('n', $this->server()->localPort());
        $request = "\x07" . $magic . $serverAddress . pack('n', 576) . pack('J', 55);

        self::assertSame(34, socket_sendto($this->client(), $request, 34, 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertFalse($this->receiveWhenReadable(20_000));
        self::assertSame(0, $this->server()->sessionCount());
    }

    public function testRequest2CannotIncreaseNegotiatedMtu(): void
    {
        $this->startServer();
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
        $this->rawExchange($request1);

        $serverAddress = "\x04\x80\xff\xff\xfe" . pack('n', $this->server()->localPort());
        $request2 = "\x07" . $magic . $serverAddress . pack('n', 1_400) . pack('J', 55);
        self::assertSame(34, socket_sendto($this->client(), $request2, 34, 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertFalse($this->receiveWhenReadable(20_000));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(1, $this->server()->pendingHandshakeCount());
    }

    public function testPendingHandshakeExpiresUsingInjectedMonotonicClock(): void
    {
        $clock = new MutableClock();
        $status = new DiscoveryStatus(self::STATUS);
        $config = new TransportConfig(bindAddress: '127.0.0.1', port: 0, handshakeTimeoutMilliseconds: 100);
        $this->server = DiscoveryServer::bind($config, self::SERVER_GUID, $status, 11, $clock);
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $this->rawExchange("\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18));
        self::assertSame(1, $this->server()->pendingHandshakeCount());

        $clock->advanceMilliseconds(100);
        self::assertSame(0, $this->server()->pendingHandshakeCount());
    }

    public function testPendingHandshakeCapacityIgnoresNewEndpoint(): void
    {
        $status = new DiscoveryStatus(self::STATUS);
        $config = new TransportConfig(bindAddress: '127.0.0.1', port: 0, maximumPendingHandshakes: 1);
        $this->server = DiscoveryServer::bind($config, self::SERVER_GUID, $status);
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
        $this->rawExchange($request);

        $second = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$second instanceof Socket) {
            self::fail('Unable to create second loopback client.');
        }

        try {
            self::assertTrue(socket_set_nonblock($second));
            self::assertSame(\strlen($request), socket_sendto($second, $request, \strlen($request), 0, '127.0.0.1', $this->server()->localPort()));
            self::assertSame(1, $this->awaitHandled(1));
            $read = [$second];
            $write = null;
            $except = null;
            self::assertSame(0, socket_select($read, $write, $except, 0, 20_000));
            self::assertSame(1, $this->server()->pendingHandshakeCount());
        } finally {
            socket_close($second);
        }
    }

    public function testEstablishedSessionCapacityIgnoresSecondEndpoint(): void
    {
        $status = new DiscoveryStatus(self::STATUS);
        $config = new TransportConfig(bindAddress: '127.0.0.1', port: 0, maximumSessions: 1);
        $this->server = DiscoveryServer::bind($config, self::SERVER_GUID, $status);
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
        $address = "\x04\xfe\xfd\xfc\xfb\x00\x09";

        $this->rawExchange($request1);
        $this->rawExchange("\x07" . $magic . $address . pack('n', 576) . pack('J', 100));
        self::assertSame(1, $this->server()->sessionCount());

        $second = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$second instanceof Socket) {
            self::fail('Unable to create second loopback client.');
        }

        try {
            self::assertTrue(socket_set_nonblock($second));
            self::assertIsString($this->exchangeOnSocket($second, $request1));
            $request2 = "\x07" . $magic . $address . pack('n', 576) . pack('J', 200);
            self::assertFalse($this->exchangeOnSocket($second, $request2, 20_000));
            self::assertSame(1, $this->server()->sessionCount());
        } finally {
            socket_close($second);
        }
    }

    public function testSessionRemovalRecoversCapacityAndAllowsGuidReuse(): void
    {
        $status = new DiscoveryStatus(self::STATUS);
        $config = new TransportConfig(bindAddress: '127.0.0.1', port: 0, maximumSessions: 1);
        $this->server = DiscoveryServer::bind($config, self::SERVER_GUID, $status);
        self::assertIsString($this->rawHandshake($this->client(), 700));
        $firstPort = $this->clientPort();
        self::assertSame(1, $this->server()->sessionCount());
        self::assertTrue($this->server()->removeSession('127.0.0.1', $firstPort));
        self::assertFalse($this->server()->removeSession('127.0.0.1', $firstPort));
        self::assertSame(0, $this->server()->sessionCount());

        $second = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$second instanceof Socket) {
            self::fail('Unable to create replacement loopback client.');
        }
        try {
            self::assertTrue(socket_set_nonblock($second));
            self::assertIsString($this->rawHandshake($second, 700));
            self::assertSame(1, $this->server()->sessionCount());
        } finally {
            socket_close($second);
        }
    }

    public function testSessionRemovalRejectsInvalidEndpoint(): void
    {
        $this->startServer();
        $this->expectException(TransportException::class);
        $this->server()->removeSession('not-an-ip', 0);
    }

    public function testGuidCollisionAndSameEndpointReplacementAreIgnored(): void
    {
        $this->startServer();
        self::assertIsString($this->rawHandshake($this->client(), 800));
        $firstPort = $this->clientPort();

        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
        $reply1 = $this->rawExchange($request1);
        self::assertSame(pack('n', 576), substr($reply1, 26, 2));
        self::assertSame(0, $this->server()->pendingHandshakeCount());

        $address = "\x04\xfe\xfd\xfc\xfb\x00\x09";
        $replacement = "\x07" . $magic . $address . pack('n', 576) . pack('J', 801);
        self::assertFalse($this->exchangeOnSocket($this->client(), $replacement, 20_000));
        self::assertSame(800, $this->server()->sessionFor('127.0.0.1', $firstPort)?->clientGuid);

        $second = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$second instanceof Socket) {
            self::fail('Unable to create colliding loopback client.');
        }
        try {
            self::assertTrue(socket_set_nonblock($second));
            self::assertIsString($this->exchangeOnSocket($second, $request1));
            $collision = "\x07" . $magic . $address . pack('n', 576) . pack('J', 800);
            self::assertFalse($this->exchangeOnSocket($second, $collision, 20_000));
            self::assertSame(1, $this->server()->sessionCount());
        } finally {
            socket_close($second);
        }
    }

    public function testCloseClearsPendingSessionsAndGuidIndex(): void
    {
        $this->startServer();
        self::assertIsString($this->rawHandshake($this->client(), 900));

        $second = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$second instanceof Socket) {
            self::fail('Unable to create pending loopback client.');
        }
        try {
            self::assertTrue(socket_set_nonblock($second));
            $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
            $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
            self::assertIsString($this->exchangeOnSocket($second, $request1));
            self::assertSame(1, $this->server()->sessionCount());
            self::assertSame(1, $this->server()->pendingHandshakeCount());

            $this->server()->close();
            self::assertSame(0, $this->server()->sessionCount());
            self::assertSame(0, $this->server()->pendingHandshakeCount());
            self::assertNull($this->server()->sessionFor('127.0.0.1', $this->clientPort()));
        } finally {
            socket_close($second);
        }
    }

    public function testCloseIsIdempotent(): void
    {
        $this->startServer();
        $this->server()->close();
        $this->server()->close();

        $this->expectExceptionMessage('closed');
        $this->server()->poll();
    }

    public function testOpaqueStatusAndOpenConnectionsPolicyCanBeUpdatedWithoutChangingGuid(): void
    {
        $this->startServer();
        $updated = 'application-owned opaque status';
        $this->server()->updateDiscoveryStatus(new DiscoveryStatus($updated, false));
        $pong = $this->exchangePing(1, 2);
        self::assertSame(self::SERVER_GUID, $pong->serverGuid);
        self::assertSame($updated, $pong->status);

        $payload = "\x02" . substr(new UnconnectedPing(3, 4)->encode(), 1);
        self::assertSame(33, socket_sendto($this->client(), $payload, 33, 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertFalse($this->receiveWhenReadable(20_000));
    }

    public function testFailedBindSurfacesErrorCleansUpAndAllowsLaterBind(): void
    {
        $status = new DiscoveryStatus(self::STATUS);

        try {
            DiscoveryServer::bind(new TransportConfig(bindAddress: '999.0.0.1', port: 0), self::SERVER_GUID, $status);
            self::fail('Invalid bind address unexpectedly succeeded.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('bind', $exception->getMessage());
        }

        $this->server = DiscoveryServer::bind(new TransportConfig(bindAddress: '127.0.0.1', port: 0), self::SERVER_GUID, $status);
        self::assertGreaterThan(0, $this->server()->localPort());
    }

    public function testPollBatchIsBounded(): void
    {
        $this->startServer();
        $this->expectException(TransportException::class);
        $this->server()->poll(4_097);
    }

    private function startServer(): void
    {
        $status = new DiscoveryStatus(self::STATUS);
        $this->server = DiscoveryServer::bind(new TransportConfig(bindAddress: '127.0.0.1', port: 0), self::SERVER_GUID, $status);

        self::assertSame('127.0.0.1', $this->server()->localAddress());
        self::assertGreaterThan(0, $this->server()->localPort());
    }

    private function client(): Socket
    {
        if (!$this->client instanceof Socket) {
            $client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$client instanceof Socket) {
                self::fail('Unable to create loopback UDP client.');
            }
            self::assertTrue(socket_set_nonblock($client));
            $this->client = $client;
        }

        return $this->client;
    }

    private function exchangePing(int $timestamp, int $clientGuid): UnconnectedPong
    {
        $payload = new UnconnectedPing($timestamp, $clientGuid)->encode();
        self::assertSame(\strlen($payload), socket_sendto($this->client(), $payload, \strlen($payload), 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));

        $response = $this->receiveWhenReadable(500_000);
        self::assertIsString($response);

        return UnconnectedPong::decode($response);
    }

    private function rawExchange(string $request): string
    {
        $response = $this->exchangeOnSocket($this->client(), $request);
        self::assertIsString($response);

        return $response;
    }

    private function rawHandshake(Socket $socket, int $clientGuid): string|false
    {
        $magic = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";
        $request1 = "\x05" . $magic . "\x0b" . str_repeat("\x00", 576 - 28 - 18);
        $reply1 = $this->exchangeOnSocket($socket, $request1);
        self::assertIsString($reply1);
        $address = "\x04\xfe\xfd\xfc\xfb\x00\x09";
        $request2 = "\x07" . $magic . $address . pack('n', 576) . pack('J', $clientGuid);

        return $this->exchangeOnSocket($socket, $request2);
    }

    private function exchangeOnSocket(Socket $socket, string $request, int $timeoutMicroseconds = 500_000): string|false
    {
        self::assertSame(\strlen($request), socket_sendto($socket, $request, \strlen($request), 0, '127.0.0.1', $this->server()->localPort()));
        self::assertSame(1, $this->awaitHandled(1));
        $read = [$socket];
        $write = null;
        $except = null;
        $selected = socket_select($read, $write, $except, 0, $timeoutMicroseconds);
        if ($selected === false) {
            self::fail('Unable to select loopback client socket.');
        }
        if ($selected === 0) {
            return false;
        }

        return socket_read($socket, 1_492, PHP_BINARY_READ);
    }

    private function clientPort(): int
    {
        $address = '';
        $port = 0;
        self::assertTrue(socket_getsockname($this->client(), $address, $port));
        self::assertIsInt($port);

        return $port;
    }

    private function awaitHandled(int $expected): int
    {
        $handled = 0;
        $deadline = hrtime(true) + 500_000_000;
        while ($handled < $expected && hrtime(true) < $deadline) {
            $handled += $this->server()->poll($expected - $handled);
            if ($handled < $expected) {
                usleep(100);
            }
        }

        return $handled;
    }

    private function receiveWhenReadable(int $timeoutMicroseconds): string|false
    {
        $read = [$this->client()];
        $write = null;
        $except = null;
        if (socket_select($read, $write, $except, 0, $timeoutMicroseconds) !== 1) {
            return false;
        }

        return socket_read($this->client(), 1_492, PHP_BINARY_READ);
    }

    private function server(): DiscoveryServer
    {
        if (!$this->server instanceof DiscoveryServer) {
            self::fail('Loopback discovery server has not been started.');
        }

        return $this->server;
    }
}
