<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Connected\ConnectedSession;
use Bedriox\RakNet\Exception\TransportException;
use Bedriox\RakNet\Protocol\ConnectedPing;
use Bedriox\RakNet\Protocol\ConnectedPong;
use Bedriox\RakNet\Protocol\ConnectionRequest;
use Bedriox\RakNet\Protocol\ConnectionRequestAccepted;
use Bedriox\RakNet\Protocol\DisconnectNotification;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\NewIncomingConnection;
use Bedriox\RakNet\Protocol\OpenConnectionReply1;
use Bedriox\RakNet\Protocol\OpenConnectionReply2;
use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use Bedriox\RakNet\Protocol\OpenConnectionRequest2;
use Bedriox\RakNet\Protocol\Reliability;
use OverflowException;
use Socket;
use Throwable;

/** Non-blocking, single-peer RakNet client transport. */
final class RakNetClient
{
    public const int MAXIMUM_DATAGRAMS_PER_POLL = 4_096;

    private ClientState $state = ClientState::AwaitingReply1;
    private ?ConnectedSession $session = null;
    /** @var list<ReceivedPayload> */
    private array $received = [];
    private int $receivedBytes = 0;
    private int $startedAt;
    private int $lastHandshakeSendAt = 0;
    private int $localPort;

    private function __construct(
        private readonly Socket $socket,
        private readonly ClientConfig $config,
        private readonly int $clientGuid,
        private readonly Clock $clock,
    ) {
        $address = '';
        $port = 0;
        if (!socket_getsockname($socket, $address, $port) || !\is_string($address) || !\is_int($port)) {
            throw new TransportException('Unable to read RakNet client endpoint.');
        }
        $this->localPort = $port;
        $this->startedAt = $clock->nowNanoseconds();
        $this->sendRequest1();
    }

    public static function connect(ClientConfig $config, int $clientGuid, ?Clock $clock = null): self
    {
        if ($clientGuid < 0) {
            throw new TransportException('Client GUID must be a nonnegative 63-bit integer.');
        }
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new TransportException('Unable to create RakNet client socket.');
        }
        try {
            if (!socket_set_nonblock($socket) || !@socket_bind($socket, $config->bindAddress, $config->bindPort)) {
                throw new TransportException('Unable to bind the RakNet client socket.');
            }

            return new self($socket, $config, $clientGuid, $clock ?? new SystemClock());
        } catch (Throwable $throwable) {
            socket_close($socket);
            throw $throwable;
        }
    }

    public function poll(int $maximumDatagrams = self::MAXIMUM_DATAGRAMS_PER_POLL): void
    {
        if ($this->state === ClientState::Closed) {
            return;
        }
        if ($maximumDatagrams < 1 || $maximumDatagrams > self::MAXIMUM_DATAGRAMS_PER_POLL) {
            throw new TransportException('Poll batch must be between 1 and 4096 datagrams.');
        }
        $now = $this->clock->nowNanoseconds();
        if ($this->state !== ClientState::Ready
            && $now - $this->startedAt >= $this->config->handshakeTimeoutMilliseconds * 1_000_000) {
            $this->close();
            throw new TransportException('RakNet client handshake timed out.');
        }

        for ($count = 0; $count < $maximumDatagrams; ++$count) {
            $bytes = '';
            $address = '';
            $port = 0;
            $read = @socket_recvfrom($this->socket, $bytes, $this->config->maximumTransmissionUnit, 0, $address, $port);
            if ($read === false) {
                $error = socket_last_error($this->socket);
                socket_clear_error($this->socket);
                if (\in_array($error, [SOCKET_EWOULDBLOCK, SOCKET_EAGAIN], true)) {
                    break;
                }
                throw new TransportException(\sprintf('RakNet client receive failed: [%d] %s.', $error, socket_strerror($error)), $error);
            }
            if (!\is_string($bytes)) {
                throw new TransportException('RakNet client socket returned a non-string datagram.');
            }
            if ($address !== $this->config->remoteAddress || $port !== $this->config->remotePort || $bytes === '') {
                continue;
            }
            $this->receiveDatagram($bytes);
        }

        if ($this->session !== null) {
            $this->session->tick();
            $this->flushSessionEffects();
        } elseif ($now - $this->lastHandshakeSendAt >= $this->config->handshakeRetryMilliseconds * 1_000_000) {
            $this->retryHandshake();
        }
    }

    public function state(): ClientState
    {
        return $this->state;
    }

    public function isReady(): bool
    {
        return $this->state === ClientState::Ready;
    }

    public function localPort(): int
    {
        return $this->localPort;
    }

    public function sendPayload(string $payload, Reliability $reliability = Reliability::ReliableOrdered, int $orderingChannel = 0): void
    {
        if (!$this->isReady() || $this->session === null) {
            throw new TransportException('RakNet client is not ready.');
        }
        $this->session->queuePayload($payload, $reliability, $orderingChannel);
    }

    /** @return list<ReceivedPayload> */
    public function drainReceivedPayloads(): array
    {
        $payloads = $this->received;
        $this->received = [];
        $this->receivedBytes = 0;

        return $payloads;
    }

    public function close(): void
    {
        if ($this->state === ClientState::Closed) {
            return;
        }
        if ($this->session !== null && $this->state === ClientState::Ready) {
            try {
                $this->session->queuePayload(new DisconnectNotification()->encode(), Reliability::Reliable);
                $this->session->tick();
                $this->flushSessionEffects();
            } catch (Throwable) {
            }
        }
        socket_close($this->socket);
        $this->state = ClientState::Closed;
        $this->session = null;
    }

    private function receiveDatagram(string $bytes): void
    {
        if ($this->session !== null) {
            $packetId = \ord($bytes[0]);
            if ($packetId === OpenConnectionReply1::ID || $packetId === OpenConnectionReply2::ID) {
                return;
            }
            $this->session->receiveBytes($bytes);
            $this->flushSessionEffects();

            return;
        }
        if ($this->state === ClientState::AwaitingReply1 && \ord($bytes[0]) === OpenConnectionReply1::ID) {
            $reply = OpenConnectionReply1::decode($bytes);
            $mtu = min($reply->mtu, $this->config->maximumTransmissionUnit);
            $request = new OpenConnectionRequest2(
                new InternetAddress($this->config->remoteAddress, $this->config->remotePort),
                $mtu,
                $this->clientGuid,
            );
            $this->send($request->encode());
            $this->state = ClientState::AwaitingReply2;

            return;
        }
        if ($this->state === ClientState::AwaitingReply2 && \ord($bytes[0]) === OpenConnectionReply2::ID) {
            $reply = OpenConnectionReply2::decode($bytes);
            $this->session = new ConnectedSession($this->clock, $reply->mtu);
            $requestTimestamp = intdiv($this->clock->nowNanoseconds(), 1_000_000);
            $this->session->queuePayload(
                new ConnectionRequest($this->clientGuid, $requestTimestamp, false)->encode(),
                Reliability::ReliableOrdered,
            );
            $this->state = ClientState::AwaitingAcceptance;
            $this->session->tick();
            $this->flushSessionEffects();
        }
    }

    private function flushSessionEffects(): void
    {
        if ($this->session === null) {
            return;
        }
        $session = $this->session;
        $effects = $session->drainEffects();
        foreach ($effects->outboundDatagrams as $datagram) {
            $this->send($datagram);
        }
        if ($effects->expiredReliableIndices !== []) {
            $this->close();
            throw new TransportException('RakNet client reliable delivery expired.');
        }
        foreach ($effects->payloads as $event) {
            $id = \ord($event->payload[0]);
            if ($this->state === ClientState::AwaitingAcceptance && $id === ConnectionRequestAccepted::ID) {
                $accepted = ConnectionRequestAccepted::decode($event->payload);
                $internal = array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0));
                $session->queuePayload(new NewIncomingConnection(
                    new InternetAddress($this->config->remoteAddress, $this->config->remotePort),
                    $internal,
                    $accepted->requestTimestamp,
                    intdiv($this->clock->nowNanoseconds(), 1_000_000),
                )->encode(), Reliability::ReliableOrdered);
                $this->state = ClientState::Ready;
                continue;
            }
            if ($id === ConnectedPing::ID) {
                $ping = ConnectedPing::decode($event->payload);
                $session->queuePayload(new ConnectedPong(
                    $ping->timestamp,
                    intdiv($this->clock->nowNanoseconds(), 1_000_000),
                )->encode(), Reliability::Unreliable);
                continue;
            }
            if ($id === DisconnectNotification::ID) {
                $this->close();
                return;
            }
            if (\count($this->received) >= $this->config->maximumReceivedPayloads
                || \strlen($event->payload) > $this->config->maximumReceivedPayloadBytes - $this->receivedBytes) {
                $this->close();
                throw new OverflowException('RakNet client receive queue limit reached.');
            }
            $this->received[] = new ReceivedPayload(
                $this->config->remoteAddress,
                $this->config->remotePort,
                $event->payload,
                $event->reliability,
                $event->orderingChannel,
            );
            $this->receivedBytes += \strlen($event->payload);
        }
    }

    private function retryHandshake(): void
    {
        if ($this->state === ClientState::AwaitingReply1) {
            $this->sendRequest1();
        } elseif ($this->state === ClientState::AwaitingReply2) {
            $this->send(new OpenConnectionRequest2(
                new InternetAddress($this->config->remoteAddress, $this->config->remotePort),
                $this->config->maximumTransmissionUnit,
                $this->clientGuid,
            )->encode());
        }
    }

    private function sendRequest1(): void
    {
        $this->send(new OpenConnectionRequest1(
            $this->config->rakNetProtocolVersion,
            $this->config->maximumTransmissionUnit,
        )->encode());
    }

    private function send(string $bytes): void
    {
        $written = @socket_sendto($this->socket, $bytes, \strlen($bytes), 0, $this->config->remoteAddress, $this->config->remotePort);
        if ($written !== \strlen($bytes)) {
            $error = socket_last_error($this->socket);
            throw new TransportException(\sprintf('RakNet client send failed: [%d] %s.', $error, socket_strerror($error)), $error);
        }
        $this->lastHandshakeSendAt = $this->clock->nowNanoseconds();
    }
}
