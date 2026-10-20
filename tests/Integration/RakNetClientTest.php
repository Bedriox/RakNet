<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Integration;

use Bedriox\RakNet\ClientConfig;
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\OpenConnectionReply1;
use Bedriox\RakNet\Protocol\OpenConnectionReply2;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\RakNetClient;
use Bedriox\RakNet\TransportConfig;
use PHPUnit\Framework\TestCase;

final class RakNetClientTest extends TestCase
{
    public function testLoopbackClientConnectsAndExchangesOrderedPayloads(): void
    {
        $server = DiscoveryServer::bind(
            new TransportConfig(bindAddress: '127.0.0.1', port: 0),
            123_456,
            new DiscoveryStatus('MCPE;Test;0;0;0;0;0;123456;Test;Survival;1;0;0;'),
        );
        $client = RakNetClient::connect(new ClientConfig('127.0.0.1', $server->localPort()), 654_321);

        try {
            for ($attempt = 0; $attempt < 2_000 && !$client->isReady(); ++$attempt) {
                $client->poll();
                $server->poll();
                usleep(500);
            }
            self::assertTrue($client->isReady());
            self::assertSame(1, $server->readySessionCount());

            $serverSocket = new \ReflectionProperty($server, 'socket')->getValue($server);
            self::assertInstanceOf(\Socket::class, $serverSocket);
            foreach ([
                new OpenConnectionReply1(123_456, 1_400)->encode(),
                new OpenConnectionReply2(123_456, new InternetAddress('127.0.0.1', $client->localPort()), 1_400)->encode(),
            ] as $lateReply) {
                self::assertSame(
                    \strlen($lateReply),
                    socket_sendto($serverSocket, $lateReply, \strlen($lateReply), 0, '127.0.0.1', $client->localPort()),
                );
            }
            $client->poll();
            self::assertTrue($client->isReady());

            $client->sendPayload('client-to-server');
            for ($attempt = 0; $attempt < 2_000 && $server->drainReceivedPayloads() === []; ++$attempt) {
                $client->poll();
                $server->poll();
                usleep(500);
            }
            $server->sendPayload('127.0.0.1', $client->localPort(), 'server-to-client', Reliability::ReliableOrdered);
            for ($attempt = 0; $attempt < 2_000; ++$attempt) {
                $server->poll();
                $client->poll();
                $received = $client->drainReceivedPayloads();
                if ($received !== []) {
                    self::assertSame('server-to-client', $received[0]->payload);

                    return;
                }
                usleep(500);
            }
            self::fail('Client did not receive the server payload.');
        } finally {
            $client->close();
            $server->close();
        }
    }
}
