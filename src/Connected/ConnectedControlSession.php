<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Protocol\ConnectedPing;
use Bedriox\RakNet\Protocol\ConnectedPong;
use Bedriox\RakNet\Protocol\ConnectionRequest;
use Bedriox\RakNet\Protocol\ConnectionRequestAccepted;
use Bedriox\RakNet\Protocol\DisconnectNotification;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\NewIncomingConnection;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionInfo;
use LogicException;
use UnexpectedValueException;

/** Owns RakNet connected-control state before application payload delivery. */
final class ConnectedControlSession
{
    private ConnectedControlState $state = ConnectedControlState::AwaitingConnectionRequest;
    private ?int $requestTimestamp = null;
    private ?string $acceptedPayload = null;
    private bool $opened = false;

    public function __construct(
        private readonly SessionInfo $session,
        private readonly int $deadlineNanoseconds,
    ) {
        if ($this->deadlineNanoseconds < 0) {
            throw new LogicException('Connected-control deadline must be nonnegative.');
        }
    }

    public function isReady(): bool
    {
        return $this->state === ConnectedControlState::Ready;
    }

    public function isClosed(): bool
    {
        return $this->state === ConnectedControlState::Closed;
    }

    public function hasOpened(): bool
    {
        return $this->opened;
    }

    public function state(): ConnectedControlState
    {
        return $this->state;
    }

    public function tick(int $nowNanoseconds): ?SessionCloseReason
    {
        $this->validateNow($nowNanoseconds);
        if (!$this->isReady() && !$this->isClosed() && $nowNanoseconds >= $this->deadlineNanoseconds) {
            $this->state = ConnectedControlState::Closed;

            return SessionCloseReason::HandshakeTimeout;
        }

        return null;
    }

    public function receive(string $payload, int $nowNanoseconds): ConnectedControlEffects
    {
        if ($this->isClosed()) {
            throw new LogicException('Connected-control session is closed.');
        }
        if ($this->tick($nowNanoseconds) !== null) {
            return new ConnectedControlEffects(closeReason: SessionCloseReason::HandshakeTimeout);
        }
        if ($payload === '') {
            throw new UnexpectedValueException('Empty connected payload closed the control session.');
        }

        $packetId = \ord($payload[0]);
        if ($packetId === DisconnectNotification::ID) {
            DisconnectNotification::decode($payload);
            $this->state = ConnectedControlState::Closed;

            return new ConnectedControlEffects(closeReason: SessionCloseReason::RemoteDisconnect);
        }
        if ($packetId === ConnectedPing::ID) {
            $ping = ConnectedPing::decode($payload);
            $pong = new ConnectedPong($ping->timestamp, intdiv($nowNanoseconds, 1_000_000));

            return new ConnectedControlEffects([
                new ControlOutboundPayload($pong->encode(), Reliability::Unreliable),
            ]);
        }
        if ($packetId === ConnectedPong::ID) {
            ConnectedPong::decode($payload);

            return new ConnectedControlEffects();
        }
        if ($packetId === ConnectionRequest::ID) {
            return $this->receiveConnectionRequest($payload, $nowNanoseconds);
        }
        if ($packetId === NewIncomingConnection::ID) {
            return $this->receiveNewIncomingConnection();
        }
        if ($packetId === ConnectionRequestAccepted::ID) {
            ConnectionRequestAccepted::decode($payload);
            throw new UnexpectedValueException('A server-side session cannot receive a connection acceptance.');
        }

        if ($this->isReady()) {
            return new ConnectedControlEffects(deliverApplicationPayload: true);
        }

        throw new UnexpectedValueException('Application payload arrived before the connected handshake completed.');
    }

    private function receiveConnectionRequest(string $payload, int $nowNanoseconds): ConnectedControlEffects
    {
        $request = ConnectionRequest::decode($payload);
        if ($request->clientGuid !== $this->session->clientGuid) {
            throw new UnexpectedValueException('Connected request GUID does not match offline negotiation.');
        }
        if ($request->security) {
            throw new UnexpectedValueException('RakNet connected security negotiation is unsupported.');
        }
        if ($this->requestTimestamp !== null && $request->requestTimestamp !== $this->requestTimestamp) {
            throw new UnexpectedValueException('Retransmitted connection request changed its timestamp.');
        }
        if ($this->isReady()) {
            return new ConnectedControlEffects();
        }
        if ($this->state === ConnectedControlState::AwaitingConnectionRequest) {
            $this->requestTimestamp = $request->requestTimestamp;
            $internal = [new InternetAddress('127.0.0.1', 0)];
            while (\count($internal) < ConnectionRequestAccepted::INTERNAL_ADDRESS_COUNT) {
                $internal[] = new InternetAddress('0.0.0.0', 0);
            }
            $this->acceptedPayload = new ConnectionRequestAccepted(
                new InternetAddress($this->session->remoteAddress, $this->session->remotePort),
                0,
                $internal,
                $request->requestTimestamp,
                intdiv($nowNanoseconds, 1_000_000),
            )->encode();
            $this->state = ConnectedControlState::AwaitingNewIncomingConnection;
        }

        return new ConnectedControlEffects([
            new ControlOutboundPayload(
                $this->acceptedPayload ?? throw new LogicException('Connection acceptance was not initialized.'),
                Reliability::ReliableOrdered,
            ),
        ]);
    }

    private function receiveNewIncomingConnection(): ConnectedControlEffects
    {
        if ($this->state === ConnectedControlState::AwaitingConnectionRequest) {
            throw new UnexpectedValueException('New incoming connection arrived before connection request.');
        }
        if ($this->isReady()) {
            return new ConnectedControlEffects();
        }
        $this->state = ConnectedControlState::Ready;
        $this->opened = true;

        return new ConnectedControlEffects(becameReady: true);
    }

    private function validateNow(int $nowNanoseconds): void
    {
        if ($nowNanoseconds < 0) {
            throw new LogicException('Connected-control time must be nonnegative.');
        }
    }
}
