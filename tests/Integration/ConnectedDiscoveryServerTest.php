<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Integration;

use Bedriox\RakNet\ConnectedHandshakeRejectionReason;
use Bedriox\RakNet\ConnectedHandshakeStage;
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\Exception\TransportException;
use Bedriox\RakNet\Protocol\AckPacket;
use Bedriox\RakNet\Protocol\BitPayload;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\EncapsulatedFrame;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\SequenceRange;
use Bedriox\RakNet\SessionClosedEvent;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\RakNet\Tests\MutableClock;
use Bedriox\RakNet\TransportConfig;
use PHPUnit\Framework\TestCase;
use Socket;

final class ConnectedDiscoveryServerTest extends TestCase
{
    private const int SERVER_GUID = 8_675_309;
    private const string MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    private ?DiscoveryServer $server = null;
    private ?Socket $client = null;
    private MutableClock $clock;

    protected function tearDown(): void
    {
        $this->server?->close();
        if ($this->client instanceof Socket) {
            socket_close($this->client);
        }
    }

    public function testHighBitClientGuidCompletesOnlineHandshake(): void
    {
        $this->startServer();
        $this->handshake(clientGuid: -1);

        self::assertSame(1, $this->server()->readySessionCount());
        self::assertSame(-1, $this->server()->sessionFor('127.0.0.1', $this->clientPort())?->clientGuid);
    }

    public function testIndependentRawUnreliableIngressIsDrainedWithObservedEndpoint(): void
    {
        $this->startServer();
        $this->handshake();
        $payload = 'raw-ingress';
        $datagram = "\x80\x02\x00\x00\x00" . pack('n', \strlen($payload) * 8) . $payload;

        self::assertSame(18, \strlen($datagram));
        $this->sendToServer($datagram);
        self::assertSame(1, $this->awaitHandled(1));
        $received = $this->server()->drainReceivedPayloads();

        self::assertCount(1, $received);
        self::assertSame('127.0.0.1', $received[0]->remoteAddress);
        self::assertSame($this->clientPort(), $received[0]->remotePort);
        self::assertSame($payload, $received[0]->payload);
        self::assertSame(Reliability::Unreliable, $received[0]->reliability);
        self::assertNull($received[0]->orderingChannel);
        self::assertSame([], $this->server()->drainReceivedPayloads());
    }

    public function testIndependentRawNonzeroConnectedDataFlagIsDispatched(): void
    {
        $this->startServer();
        $this->handshake();
        $payload = 'continuous-send';
        $datagram = "\x84\x02\x00\x00\x00" . pack('n', \strlen($payload) * 8) . $payload;

        $this->sendToServer($datagram);
        self::assertSame(1, $this->awaitHandled(1));
        $received = $this->server()->drainReceivedPayloads();
        self::assertCount(1, $received);
        self::assertSame($payload, $received[0]->payload);
        self::assertSame(Reliability::Unreliable, $received[0]->reliability);
    }

    public function testServerReliableEgressStopsRetryingAfterClientAck(): void
    {
        $this->startServer();
        $this->handshake();
        $this->server()->sendPayload('127.0.0.1', $this->clientPort(), 'server-egress', Reliability::Reliable);

        self::assertSame(0, $this->server()->poll());
        $bytes = $this->receiveWhenReadable(500_000);
        self::assertIsString($bytes);
        $datagram = ConnectedDatagram::decode($bytes);
        self::assertSame('server-egress', $datagram->frames[0]->payload->bytes);
        self::assertNotNull($datagram->frames[0]->reliableIndex);

        $ack = new AckPacket([new SequenceRange($datagram->sequenceNumber, $datagram->sequenceNumber)]);
        $this->sendToServer($ack->encode());
        self::assertSame(1, $this->awaitHandled(1));
        $this->clock->advanceMilliseconds(1_000);
        self::assertSame(0, $this->server()->poll());
        self::assertFalse($this->receiveWhenReadable(20_000));
    }

    public function testReliableDuplicateIsDeliveredExactlyOnce(): void
    {
        $this->startServer();
        $this->handshake();
        $payload = 'exactly-once';
        $datagram = "\x80\x02\x00\x00\x40" . pack('n', \strlen($payload) * 8) . "\x02\x00\x00" . $payload;

        $this->sendToServer($datagram);
        self::assertSame(1, $this->awaitHandled(1));
        $this->sendToServer($datagram);
        self::assertSame(1, $this->awaitHandled(1));

        $received = $this->server()->drainReceivedPayloads();
        self::assertCount(1, $received);
        self::assertSame($payload, $received[0]->payload);
        self::assertSame(Reliability::Reliable, $received[0]->reliability);
    }

    public function testConnectedBytesFromUnknownEndpointAreIgnored(): void
    {
        $this->startServer();
        $payload = "\x80\x00\x00\x00\x00\x00\x08x";
        $this->sendToServer($payload);

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame([], $this->server()->drainReceivedPayloads());
        self::assertFalse($this->receiveWhenReadable(20_000));
    }

    public function testRemovalAndCloseClearConnectedEnginesAndPayloadEffects(): void
    {
        $this->startServer();
        $this->handshake();
        self::assertSame(1, $this->server()->connectedSessionCount());
        self::assertTrue($this->server()->removeSession('127.0.0.1', $this->clientPort()));
        self::assertSame(0, $this->server()->connectedSessionCount());

        $this->sendToServer("\x80\x02\x00\x00\x00\x00\x08x");
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame([], $this->server()->drainReceivedPayloads());

        $this->handshake(clientGuid: 778);
        $this->sendToServer("\x80\x02\x00\x00\x00\x00\x08y");
        self::assertSame(1, $this->awaitHandled(1));
        self::assertCount(1, $this->server()->drainReceivedPayloads());
        $this->server()->sendPayload('127.0.0.1', $this->clientPort(), 'queued', Reliability::Reliable);
        $this->server()->close();

        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->connectedSessionCount());
        self::assertSame([], $this->server()->drainReceivedPayloads());
    }

    public function testNegotiatedMtuRejectsOversizedIngressAndBoundsFragmentedEgress(): void
    {
        $this->startServer();
        $this->handshake();
        $this->sendToServer("\x80" . str_repeat("\x00", 548));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame([], $this->server()->drainReceivedPayloads());
        self::assertSame(0, $this->server()->sessionCount());
        $this->handshake();

        $valid = "\x80\x02\x00\x00\x00\x00\x08v";
        $this->sendToServer($valid);
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame('v', $this->server()->drainReceivedPayloads()[0]->payload ?? null);

        $this->drainClientSocket();
        $this->server()->sendPayload('127.0.0.1', $this->clientPort(), str_repeat('z', 1_200), Reliability::Reliable);
        self::assertSame(0, $this->server()->poll());
        $outbound = $this->drainClientSocket();
        self::assertGreaterThan(1, \count($outbound));
        foreach ($outbound as $datagram) {
            self::assertLessThanOrEqual(548, \strlen($datagram));
            self::assertSame(ConnectedDatagram::VALID_FLAG, \ord($datagram[0]));
        }
    }

    public function testGlobalReceivedPayloadLimitFailsClosedAtomically(): void
    {
        $this->startServer(new TransportConfig(
            bindAddress: '127.0.0.1',
            port: 0,
            maximumReceivedPayloads: 1,
            maximumReceivedPayloadBytes: 32,
        ));
        $this->handshake();
        $datagram = new ConnectedDatagram(ConnectedDatagram::VALID_FLAG, 2, [
            new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('a', 8)),
            new EncapsulatedFrame(Reliability::Unreliable, new BitPayload('b', 8)),
        ]);
        $this->sendToServer($datagram->encode());

        try {
            $this->awaitHandled(1);
            self::fail('Global received-payload limit did not fail closed.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('queue limit', $exception->getMessage());
        }
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->connectedSessionCount());
        self::assertSame([], $this->server()->drainReceivedPayloads());
    }

    public function testApplicationPayloadBeforeConnectedHandshakeFailsClosed(): void
    {
        $this->startServer();
        $this->offlineHandshake();
        $this->sendToServer("\x80\x00\x00\x00\x00\x00\x08x");

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame([], $this->server()->drainReceivedPayloads());
        $this->assertHandshakeRejection(
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::InvalidControlPayload,
            Reliability::Unreliable,
            null,
            0x78,
        );
    }

    public function testConnectedHandshakeTimeoutReleasesOfflineSession(): void
    {
        $this->startServer(new TransportConfig(bindAddress: '127.0.0.1', port: 0, handshakeTimeoutMilliseconds: 100));
        $this->offlineHandshake();
        self::assertSame(1, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());

        $this->clock->advanceMilliseconds(100);
        self::assertSame(0, $this->server()->poll());
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame([], $this->server()->drainSessionEvents());
    }

    public function testPreReadyHandshakeTimeoutEmitsStageDiagnosticWithoutLifecycleEvent(): void
    {
        $this->startServer(new TransportConfig(bindAddress: '127.0.0.1', port: 0, handshakeTimeoutMilliseconds: 100));
        $this->beginConnectedHandshake();
        self::assertSame(1, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());

        $this->clock->advanceMilliseconds(100);
        self::assertSame(0, $this->server()->poll());

        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame([], $this->server()->drainSessionEvents());
        $batch = $this->server()->drainHandshakeDiagnostics();
        self::assertSame(0, $batch->droppedEventCount);
        self::assertCount(1, $batch->events);
        self::assertSame(
            ConnectedHandshakeStage::AwaitingNewIncomingConnection,
            $batch->events[0]->stage,
        );
        self::assertSame(ConnectedHandshakeRejectionReason::Timeout, $batch->events[0]->reason);
        self::assertSame('127.0.0.1', $batch->events[0]->remoteAddress);
        self::assertSame($this->clientPort(), $batch->events[0]->remotePort);
        self::assertNull($batch->events[0]->datagramId);
        self::assertNull($batch->events[0]->controlPacketId);
        self::assertNull($batch->events[0]->payloadLength);
        self::assertNull($batch->events[0]->reliability);
        self::assertNull($batch->events[0]->orderingChannel);
    }

    public function testEmptyConnectedFrameAndMalformedControlFailClosed(): void
    {
        $this->startServer();
        $this->handshake();
        $this->sendToServer("\x80\x02\x00\x00\x00\x00\x00");
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame([], $this->server()->drainReceivedPayloads());

        $this->handshake(clientGuid: 778);
        $malformedPing = "\x00" . pack('J', 1) . "\x00";
        $this->sendToServer("\x80\x02\x00\x00\x00" . pack('n', \strlen($malformedPing) * 8) . $malformedPing);
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
    }

    public function testRawConnectedPingAndDisconnectLifecycle(): void
    {
        $this->startServer();
        $this->handshake();
        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionOpenedEvent::class, $events[0]);

        $this->sendToServer("\x80\x02\x00\x00\x00\x00\x48\x00\x00\x00\x00\x00\x00\x00\x00\x63");
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(1, $this->server()->sessionCount());
        self::assertSame([], $this->server()->drainReceivedPayloads());
        $pong = $this->findOutboundPayload(0x03);
        self::assertIsString($pong);
        self::assertSame('0300000000000000630000000000000000', bin2hex($pong));

        $this->sendToServer($this->orderedDatagram(3, 2, 2, "\x15"));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionClosedEvent::class, $events[0]);
        self::assertSame(SessionCloseReason::RemoteDisconnect, $events[0]->reason);
        self::assertFalse($this->server()->removeSession('127.0.0.1', $this->clientPort()));
    }

    public function testSilentReadySessionTimesOutAndReleasesEndpoint(): void
    {
        $this->startServer(new TransportConfig(
            bindAddress: '127.0.0.1',
            port: 0,
            sessionIdleTimeoutMilliseconds: 5_000,
            sessionPingIntervalMilliseconds: 1_000,
        ));
        $this->handshake();
        self::assertCount(1, $this->server()->drainSessionEvents());

        $this->clock->advanceMilliseconds(1_000);
        self::assertSame(0, $this->server()->poll());
        self::assertIsString($this->findOutboundPayload(0x00));
        self::assertSame(1, $this->server()->readySessionCount());

        $this->clock->advanceMilliseconds(4_000);
        self::assertSame(0, $this->server()->poll());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame(0, $this->server()->sessionCount());
        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionClosedEvent::class, $events[0]);
        self::assertSame(SessionCloseReason::IdleTimeout, $events[0]->reason);
        self::assertSame([], $this->server()->drainHandshakeDiagnostics()->events);
    }

    public function testLostAcceptanceIsReliablyRetransmittedAndRequestReplayIsBounded(): void
    {
        $this->startServer();
        $this->offlineHandshake();
        $request = "\x09" . pack('J', 777) . pack('J', 1_234) . "\x00";
        $this->sendToServer($this->orderedDatagram(0, 0, 0, $request));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertIsString($this->findOutboundPayload(0x10));

        // Drop the first acceptance and drive the deterministic retransmission timer.
        $this->clock->advanceMilliseconds(1_000);
        self::assertSame(0, $this->server()->poll());
        self::assertIsString($this->findOutboundPayload(0x10));

        // A replay with new RakNet indices is consumed and produces one bounded acceptance.
        $this->sendToServer($this->orderedDatagram(1, 1, 1, $request));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertIsString($this->findOutboundPayload(0x10));
        self::assertSame([], $this->server()->drainReceivedPayloads());
        self::assertSame(0, $this->server()->readySessionCount());
    }

    public function testHandshakeControlOnWrongReliabilityFailsClosed(): void
    {
        $this->startServer();
        $this->offlineHandshake();
        $request = "\x09" . pack('J', 777) . pack('J', 1_234) . "\x00";
        $this->sendToServer($this->unreliableDatagram(0, $request));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        $this->assertHandshakeRejection(
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            Reliability::Unreliable,
            null,
            0x09,
        );
    }

    public function testRetailReliableConnectionRequestEnvelopeIsAccepted(): void
    {
        $this->startServer();
        $this->offlineHandshake();
        $request = "\x09" . pack('J', 777) . pack('J', 1_234) . "\x00";
        $this->sendToServer($this->reliableDatagram(0, 0, $request));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertIsString($this->findOutboundPayload(0x10));
        self::assertSame(0, $this->server()->readySessionCount());
    }

    public function testIdentifierOnlyNewIncomingOpensSessionExactlyOnce(): void
    {
        $this->startServer();
        $this->handshake(newIncoming: "\x13");

        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionOpenedEvent::class, $events[0]);

        $this->sendToServer($this->orderedDatagram(2, 2, 2, "\x13ignored-duplicate-body"));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame([], $this->server()->drainSessionEvents());
        self::assertSame(1, $this->server()->readySessionCount());
    }

    public function testNewIncomingOnWrongReliabilityEnvelopeFailsClosed(): void
    {
        $this->startServer();
        $this->beginConnectedHandshake();
        $this->sendToServer($this->unreliableDatagram(1, "\x13"));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame([], $this->server()->drainSessionEvents());
        $this->assertHandshakeRejection(
            ConnectedHandshakeStage::AwaitingNewIncomingConnection,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            Reliability::Unreliable,
            null,
        );
    }

    public function testNewIncomingOnWrongOrderingChannelFailsClosed(): void
    {
        $this->startServer();
        $this->beginConnectedHandshake();
        $this->sendToServer($this->orderedDatagram(1, 1, 0, "\x13", 1));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame([], $this->server()->drainSessionEvents());
        $this->assertHandshakeRejection(
            ConnectedHandshakeStage::AwaitingNewIncomingConnection,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            Reliability::ReliableOrdered,
            1,
        );
    }

    public function testOutOfStateNewIncomingIsolatedAndDiagnosedWithoutStoppingServer(): void
    {
        $this->startServer();
        $this->offlineHandshake();
        $this->sendToServer($this->orderedDatagram(0, 0, 0, "\x13ignored-body"));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        $this->assertHandshakeRejection(
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::InvalidControlPayload,
            Reliability::ReliableOrdered,
            0,
        );

        $this->handshake(clientGuid: 778, newIncoming: "\x13");
        self::assertSame(1, $this->server()->readySessionCount());
    }

    public function testDiagnosticSaturationDropsExcessWithoutPreventingLaterValidClient(): void
    {
        $this->startServer(new TransportConfig(
            bindAddress: '127.0.0.1',
            port: 0,
            maximumHandshakeDiagnosticEvents: 1,
        ));

        $this->offlineHandshake(clientGuid: 777);
        $this->sendToServer($this->orderedDatagram(0, 0, 0, "\x13first-rejection"));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());

        $this->offlineHandshake(clientGuid: 778);
        $this->sendToServer($this->orderedDatagram(0, 0, 0, "\x13second-rejection"));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());

        $this->handshake(clientGuid: 779, newIncoming: "\x13");
        self::assertSame(1, $this->server()->readySessionCount());
        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionOpenedEvent::class, $events[0]);

        $batch = $this->server()->drainHandshakeDiagnostics();
        self::assertCount(1, $batch->events);
        self::assertSame(1, $batch->droppedEventCount);
        self::assertSame(
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            $batch->events[0]->stage,
        );
        self::assertSame(
            ConnectedHandshakeRejectionReason::InvalidControlPayload,
            $batch->events[0]->reason,
        );
        self::assertSame(0x13, $batch->events[0]->controlPacketId);
    }

    public function testNewIncomingFromUnknownEndpointIsIgnored(): void
    {
        $this->startServer();
        $this->sendToServer($this->orderedDatagram(0, 0, 0, "\x13"));

        self::assertSame(1, $this->awaitHandled(1));
        self::assertSame(0, $this->server()->sessionCount());
        self::assertSame(0, $this->server()->readySessionCount());
        self::assertSame([], $this->server()->drainSessionEvents());
        self::assertFalse($this->receiveWhenReadable(20_000));
    }

    public function testLifecycleQueueOverflowRemovesSessionDeterministically(): void
    {
        $this->startServer(new TransportConfig(
            bindAddress: '127.0.0.1',
            port: 0,
            maximumSessions: 1,
            maximumSessionEvents: 1,
        ));
        $this->handshake();

        try {
            $this->server()->removeSession('127.0.0.1', $this->clientPort());
            self::fail('Lifecycle queue overflow did not surface.');
        } catch (\OverflowException $exception) {
            self::assertStringContainsString('lifecycle event queue', $exception->getMessage());
        }
        self::assertSame(0, $this->server()->sessionCount());
        self::assertCount(1, $this->server()->drainSessionEvents());
    }

    public function testCloseReplacesUndrainedOpenEventsWithObservableServerClosedEvents(): void
    {
        $this->startServer();
        $this->handshake();
        $this->server()->close();

        $events = $this->server()->drainSessionEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SessionClosedEvent::class, $events[0]);
        self::assertSame(SessionCloseReason::ServerClosed, $events[0]->reason);
        self::assertSame([], $this->server()->drainSessionEvents());
        $this->server()->close();
        self::assertSame([], $this->server()->drainSessionEvents());
    }

    private function startServer(?TransportConfig $config = null): void
    {
        $this->clock = new MutableClock();
        $status = new DiscoveryStatus('opaque connected-test status');
        $this->server = DiscoveryServer::bind(
            $config ?? new TransportConfig(bindAddress: '127.0.0.1', port: 0),
            self::SERVER_GUID,
            $status,
            clock: $this->clock,
        );
    }

    private function handshake(int $clientGuid = 777, ?string $newIncoming = null): void
    {
        $requestTimestamp = $this->beginConnectedHandshake($clientGuid);

        if ($newIncoming === null) {
            $serverAddress = "\x04\x80\xff\xff\xfe" . pack('n', $this->server()->localPort());
            $internalAddresses = str_repeat("\x04\xff\xff\xff\xff\x00\x00", 20);
            $newIncoming = "\x13" . $serverAddress . $internalAddresses
                . pack('J', $requestTimestamp) . pack('J', 2_345);
            self::assertSame(164, \strlen($newIncoming));
        }
        $this->sendToServer($this->orderedDatagram(1, 1, 1, $newIncoming));
        self::assertSame(1, $this->awaitHandled(1));
        self::assertTrue($this->server()->isSessionReady('127.0.0.1', $this->clientPort()));
        self::assertSame(1, $this->server()->readySessionCount());
        $this->drainClientSocket();
    }

    private function beginConnectedHandshake(int $clientGuid = 777): int
    {
        $this->offlineHandshake($clientGuid);

        $requestTimestamp = 1_234;
        $connectionRequest = "\x09" . pack('J', $clientGuid) . pack('J', $requestTimestamp) . "\x00";
        $this->sendToServer($this->orderedDatagram(0, 0, 0, $connectionRequest));
        self::assertSame(1, $this->awaitHandled(1));

        $accepted = null;
        $acceptedSequence = null;
        $deadline = hrtime(true) + 500_000_000;
        while ($accepted === null && hrtime(true) < $deadline) {
            $wire = $this->receiveWhenReadable(20_000);
            if ($wire === false || !ConnectedDatagram::acceptsFlags(\ord($wire[0]))) {
                continue;
            }
            $decoded = ConnectedDatagram::decode($wire);
            foreach ($decoded->frames as $frame) {
                if (($frame->payload->bytes[0] ?? null) === "\x10") {
                    $accepted = $frame->payload->bytes;
                    $acceptedSequence = $decoded->sequenceNumber;
                }
            }
        }
        self::assertIsString($accepted);
        self::assertIsInt($acceptedSequence);
        self::assertSame(166, \strlen($accepted));
        $this->sendToServer("\xc0\x00\x01\x01"
            . \chr($acceptedSequence & 0xff)
            . \chr(($acceptedSequence >> 8) & 0xff)
            . \chr(($acceptedSequence >> 16) & 0xff));
        self::assertSame(1, $this->awaitHandled(1));

        return $requestTimestamp;
    }

    private function assertHandshakeRejection(
        ConnectedHandshakeStage $stage,
        ConnectedHandshakeRejectionReason $reason,
        Reliability $reliability,
        ?int $orderingChannel,
        int $controlPacketId = 0x13,
    ): void {
        $batch = $this->server()->drainHandshakeDiagnostics();
        self::assertSame(0, $batch->droppedEventCount);
        self::assertCount(1, $batch->events);
        $event = $batch->events[0];
        self::assertSame('127.0.0.1', $event->remoteAddress);
        self::assertSame($this->clientPort(), $event->remotePort);
        self::assertSame($stage, $event->stage);
        self::assertSame($reason, $event->reason);
        self::assertSame(0x80, $event->datagramId);
        self::assertSame($controlPacketId, $event->controlPacketId);
        self::assertGreaterThan(0, $event->payloadLength);
        self::assertSame($reliability, $event->reliability);
        self::assertSame($orderingChannel, $event->orderingChannel);
    }

    private function offlineHandshake(int $clientGuid = 777): void
    {
        $request1 = "\x05" . self::MAGIC . "\x0b" . str_repeat("\x00", 530);
        self::assertSame(28, \strlen($this->exchange($request1)));
        $address = "\x04\xfe\xfd\xfc\xfb\x00\x09";
        $request2 = "\x07" . self::MAGIC . $address . pack('n', 576) . pack('J', $clientGuid);
        self::assertSame(35, \strlen($this->exchange($request2)));
        self::assertSame(1, $this->server()->connectedSessionCount());
    }

    private function orderedDatagram(
        int $sequence,
        int $reliableIndex,
        int $orderingIndex,
        string $payload,
        int $orderingChannel = 0,
    ): string {
        return "\x80"
            . \chr($sequence & 0xff) . \chr(($sequence >> 8) & 0xff) . \chr(($sequence >> 16) & 0xff)
            . "\x60" . pack('n', \strlen($payload) * 8)
            . \chr($reliableIndex & 0xff) . \chr(($reliableIndex >> 8) & 0xff) . \chr(($reliableIndex >> 16) & 0xff)
            . \chr($orderingIndex & 0xff) . \chr(($orderingIndex >> 8) & 0xff) . \chr(($orderingIndex >> 16) & 0xff)
            . \chr($orderingChannel & 0xff)
            . $payload;
    }

    private function unreliableDatagram(int $sequence, string $payload): string
    {
        return "\x80"
            . \chr($sequence & 0xff) . \chr(($sequence >> 8) & 0xff) . \chr(($sequence >> 16) & 0xff)
            . "\x00" . pack('n', \strlen($payload) * 8)
            . $payload;
    }

    private function reliableDatagram(int $sequence, int $reliableIndex, string $payload): string
    {
        return "\x80"
            . \chr($sequence & 0xff) . \chr(($sequence >> 8) & 0xff) . \chr(($sequence >> 16) & 0xff)
            . "\x40" . pack('n', \strlen($payload) * 8)
            . \chr($reliableIndex & 0xff) . \chr(($reliableIndex >> 8) & 0xff) . \chr(($reliableIndex >> 16) & 0xff)
            . $payload;
    }

    private function findOutboundPayload(int $packetId): ?string
    {
        $deadline = hrtime(true) + 500_000_000;
        while (hrtime(true) < $deadline) {
            $this->server()->poll();
            $wire = $this->receiveWhenReadable(20_000);
            if ($wire === false || !ConnectedDatagram::acceptsFlags(\ord($wire[0]))) {
                continue;
            }
            foreach (ConnectedDatagram::decode($wire)->frames as $frame) {
                if (isset($frame->payload->bytes[0]) && \ord($frame->payload->bytes[0]) === $packetId) {
                    return $frame->payload->bytes;
                }
            }
        }

        return null;
    }

    private function exchange(string $payload): string
    {
        $this->sendToServer($payload);
        self::assertSame(1, $this->awaitHandled(1));
        $response = $this->receiveWhenReadable(500_000);
        self::assertIsString($response);

        return $response;
    }

    private function sendToServer(string $payload): void
    {
        self::assertSame(
            \strlen($payload),
            socket_sendto($this->client(), $payload, \strlen($payload), 0, '127.0.0.1', $this->server()->localPort()),
        );
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

    private function client(): Socket
    {
        if (!$this->client instanceof Socket) {
            $client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$client instanceof Socket) {
                self::fail('Unable to create connected loopback client.');
            }
            self::assertTrue(socket_set_nonblock($client));
            $this->client = $client;
        }

        return $this->client;
    }

    private function clientPort(): int
    {
        $address = '';
        $port = 0;
        self::assertTrue(socket_getsockname($this->client(), $address, $port));
        self::assertIsInt($port);

        return $port;
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

    /** @return list<string> */
    private function drainClientSocket(): array
    {
        $datagrams = [];
        while (($datagram = $this->receiveWhenReadable(20_000)) !== false) {
            $datagrams[] = $datagram;
        }

        return $datagrams;
    }

    private function server(): DiscoveryServer
    {
        if (!$this->server instanceof DiscoveryServer) {
            self::fail('Connected discovery server has not been started.');
        }

        return $this->server;
    }
}
