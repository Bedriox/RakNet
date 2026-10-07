<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Connected\ConnectedControlSession;
use Bedriox\RakNet\Connected\ConnectedControlState;
use Bedriox\RakNet\Connected\ConnectedSession;
use Bedriox\RakNet\Exception\CodecException;
use Bedriox\RakNet\Exception\TransportException;
use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\ConnectedDatagram;
use Bedriox\RakNet\Protocol\IncompatibleProtocolVersion;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\OpenConnectionReply1;
use Bedriox\RakNet\Protocol\OpenConnectionReply2;
use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use Bedriox\RakNet\Protocol\OpenConnectionRequest2;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\Protocol\UnconnectedPing;
use Bedriox\RakNet\Protocol\UnconnectedPong;
use Bedriox\RakNet\Security\AdmissionDecision;
use Bedriox\RakNet\Security\TransportAbuseGuard;
use Bedriox\RakNet\Security\TransportSecuritySnapshot;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Socket;
use UnexpectedValueException;

final class DiscoveryServer
{
    public const int DEFAULT_RAKNET_PROTOCOL_VERSION = 11;
    public const int MAXIMUM_IPV4_PROBE_MTU = OpenConnectionRequest1::MAXIMUM_MTU;
    public const int MAXIMUM_DATAGRAMS_PER_POLL = 4_096;

    private bool $closed = false;

    /** @var array<string, PendingHandshake> */
    private array $pendingHandshakes = [];

    /** @var array<string, SessionInfo> */
    private array $sessions = [];

    /** @var array<int, string> */
    private array $guidEndpoints = [];

    /** @var array<string, ConnectedSession> */
    private array $connectedSessions = [];

    /** @var array<string, ConnectedControlSession> */
    private array $connectedControlSessions = [];

    /** @var list<SessionOpenedEvent|SessionClosedEvent> */
    private array $sessionEvents = [];

    /** @var list<ConnectedHandshakeDiagnosticEvent> */
    private array $handshakeDiagnostics = [];

    private int $droppedHandshakeDiagnosticCount = 0;

    /** @var list<ReceivedPayload> */
    private array $receivedPayloads = [];

    private int $receivedPayloadBytes = 0;

    /** @var array<string, array{priority: list<string>, normal: list<string>}> */
    private array $pendingOutboundDatagrams = [];

    private int $pendingOutboundDatagramCount = 0;
    private int $pendingOutboundBytes = 0;

    /** @var array<string, true> */
    private array $connectedSessionsDue = [];

    private int $nextConnectedSessionMaintenanceNanoseconds = 0;

    private readonly TransportAbuseGuard $abuseGuard;

    private function __construct(
        private readonly Socket $socket,
        private readonly int $maximumNegotiatedMtu,
        private readonly int $serverGuid,
        private DiscoveryStatus $discoveryStatus,
        private readonly string $localAddress,
        private readonly int $localPort,
        private readonly int $maximumSessions,
        private readonly int $maximumPendingHandshakes,
        private readonly int $handshakeTimeoutNanoseconds,
        private readonly int $sessionIdleTimeoutNanoseconds,
        private readonly int $sessionPingIntervalNanoseconds,
        private readonly int $connectedSessionMaintenanceIntervalNanoseconds,
        private readonly int $rakNetProtocolVersion,
        private readonly Clock $clock,
        private readonly int $maximumReceivedPayloads,
        private readonly int $maximumReceivedPayloadBytes,
        private readonly int $maximumPendingOutboundDatagrams,
        private readonly int $maximumPendingOutboundBytes,
        private readonly int $maximumSessionEvents,
        private readonly int $maximumHandshakeDiagnosticEvents,
        TransportAbuseGuard $abuseGuard,
    ) {
        $this->abuseGuard = $abuseGuard;
    }

    public static function bind(
        TransportConfig $config,
        int $serverGuid,
        DiscoveryStatus $discoveryStatus,
        int $rakNetProtocolVersion = self::DEFAULT_RAKNET_PROTOCOL_VERSION,
        ?Clock $clock = null,
    ): self {
        if ($serverGuid < 0) {
            throw new TransportException('Server GUID must be a nonnegative 63-bit integer.');
        }
        if ($rakNetProtocolVersion < 0 || $rakNetProtocolVersion > 0xff) {
            throw new TransportException('RakNet protocol version must fit in one byte.');
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new TransportException('Unable to create UDP socket.');
        }

        try {
            if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
                throw new TransportException('Unable to configure UDP socket reuse.');
            }
            if (!socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, $config->socketReceiveBufferBytes)) {
                throw new TransportException('Unable to configure the UDP receive buffer.');
            }
            if (!socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, $config->socketSendBufferBytes)) {
                throw new TransportException('Unable to configure the UDP send buffer.');
            }

            if (!socket_set_nonblock($socket)) {
                throw new TransportException('Unable to make UDP socket non-blocking.');
            }

            if (!@socket_bind($socket, $config->bindAddress, $config->port)) {
                $error = socket_last_error($socket);
                $message = $error === 0 ? 'unknown socket error' : socket_strerror($error);
                throw new TransportException(\sprintf('Unable to bind UDP socket: [%d] %s.', $error, $message), $error);
            }

            $address = '';
            $port = 0;
            if (!socket_getsockname($socket, $address, $port)) {
                throw new TransportException('Unable to read bound UDP address.');
            }

            if (!\is_string($address) || !\is_int($port)) {
                throw new TransportException('UDP socket returned an invalid bound address.');
            }

            $resolvedClock = $clock ?? new SystemClock();

            return new self(
                $socket,
                $config->maximumTransmissionUnit,
                $serverGuid,
                $discoveryStatus,
                $address,
                $port,
                $config->maximumSessions,
                $config->maximumPendingHandshakes,
                $config->handshakeTimeoutMilliseconds * 1_000_000,
                $config->sessionIdleTimeoutMilliseconds * 1_000_000,
                $config->sessionPingIntervalMilliseconds * 1_000_000,
                $config->connectedSessionMaintenanceIntervalMilliseconds * 1_000_000,
                $rakNetProtocolVersion,
                $resolvedClock,
                $config->maximumReceivedPayloads,
                $config->maximumReceivedPayloadBytes,
                $config->maximumPendingOutboundDatagrams,
                $config->maximumPendingOutboundBytes,
                $config->maximumSessionEvents,
                $config->maximumHandshakeDiagnosticEvents,
                new TransportAbuseGuard($config->security, $resolvedClock),
            );
        } catch (\Throwable $exception) {
            socket_close($socket);
            if ($exception instanceof TransportException) {
                throw $exception;
            }

            throw new TransportException('Unable to initialize UDP discovery socket.', 0, $exception);
        }
    }

    public function localAddress(): string
    {
        return $this->localAddress;
    }

    public function localPort(): int
    {
        return $this->localPort;
    }

    public function updateDiscoveryStatus(DiscoveryStatus $discoveryStatus): void
    {
        $this->discoveryStatus = $discoveryStatus;
    }

    public function sessionCount(): int
    {
        return \count($this->sessions);
    }

    public function connectedSessionCount(): int
    {
        return \count($this->connectedSessions);
    }

    public function readySessionCount(): int
    {
        $ready = 0;
        foreach ($this->connectedControlSessions as $control) {
            if ($control->isReady()) {
                ++$ready;
            }
        }

        return $ready;
    }

    public function isSessionReady(string $remoteAddress, int $remotePort): bool
    {
        return ($this->connectedControlSessions[self::endpointKey($remoteAddress, $remotePort)] ?? null)?->isReady() ?? false;
    }

    public function pendingHandshakeCount(): int
    {
        $this->expirePendingHandshakes();

        return \count($this->pendingHandshakes);
    }

    public function securitySnapshot(): TransportSecuritySnapshot
    {
        return $this->abuseGuard->snapshot();
    }

    public function blockAddress(string $address, ?int $seconds = null): void
    {
        $this->abuseGuard->blockAddress($address, $seconds);
    }

    public function unblockAddress(string $address): void
    {
        $this->abuseGuard->unblockAddress($address);
    }

    public function sessionFor(string $remoteAddress, int $remotePort): ?SessionInfo
    {
        return $this->sessions[self::endpointKey($remoteAddress, $remotePort)] ?? null;
    }

    public function removeSession(string $remoteAddress, int $remotePort): bool
    {
        $key = self::endpointKey($remoteAddress, $remotePort);
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof SessionInfo) {
            return false;
        }

        $this->removeSessionByKey($key, SessionCloseReason::LocalRemoval);

        return true;
    }

    public function sendPayload(
        string $remoteAddress,
        int $remotePort,
        string $payload,
        Reliability $reliability,
        int $orderingChannel = 0,
    ): void {
        $key = self::endpointKey($remoteAddress, $remotePort);
        $session = $this->connectedSessions[$key] ?? null;
        if (!$session instanceof ConnectedSession) {
            throw new TransportException('Cannot send to an unknown connected endpoint.');
        }
        if (!(($this->connectedControlSessions[$key] ?? null)?->isReady() ?? false)) {
            throw new TransportException('Cannot send application payload before the connected handshake completes.');
        }

        $session->queuePayload($payload, $reliability, $orderingChannel);
        $this->connectedSessionsDue[$key] = true;
    }

    /** @return list<ReceivedPayload> */
    public function drainReceivedPayloads(): array
    {
        $payloads = $this->receivedPayloads;
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;

        return $payloads;
    }

    /** @return list<SessionOpenedEvent|SessionClosedEvent> */
    public function drainSessionEvents(): array
    {
        $events = $this->sessionEvents;
        $this->sessionEvents = [];

        return $events;
    }

    public function drainHandshakeDiagnostics(): ConnectedHandshakeDiagnosticBatch
    {
        $batch = new ConnectedHandshakeDiagnosticBatch(
            $this->handshakeDiagnostics,
            $this->droppedHandshakeDiagnosticCount,
        );
        $this->handshakeDiagnostics = [];
        $this->droppedHandshakeDiagnosticCount = 0;

        return $batch;
    }

    public function poll(int $maximumDatagrams = 64): int
    {
        if ($this->closed) {
            throw new TransportException('Cannot poll a closed discovery server.');
        }

        if ($maximumDatagrams < 1 || $maximumDatagrams > self::MAXIMUM_DATAGRAMS_PER_POLL) {
            throw new TransportException('Poll batch must be between 1 and 4096 datagrams.');
        }

        $this->expirePendingHandshakes();
        $handled = 0;
        while ($handled < $maximumDatagrams) {
            $payload = '';
            $sourceAddress = '';
            $sourcePort = 0;
            $received = @socket_recvfrom(
                $this->socket,
                $payload,
                self::MAXIMUM_IPV4_PROBE_MTU,
                0,
                $sourceAddress,
                $sourcePort,
            );

            if ($received === false) {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_EWOULDBLOCK || self::isRecoverableReceiveError($error)) {
                    socket_clear_error($this->socket);
                    break;
                }

                throw $this->socketFailure('receive a UDP datagram', $error);
            }

            ++$handled;
            if (
                $received < 1
                || !\is_string($payload)
                || !\is_string($sourceAddress)
                || !\is_int($sourcePort)
                || $sourcePort < 1
                || $sourcePort > 65_535
            ) {
                continue;
            }

            $key = self::endpointKey($sourceAddress, $sourcePort);
            $connectedInput = false;
            $packetId = null;
            try {
                $packetId = \ord($payload[0]);
                $connectedSession = $this->connectedSessions[$key] ?? null;
                $admission = $this->abuseGuard->admit(
                    $sourceAddress,
                    $sourcePort,
                    $received,
                    $connectedSession instanceof ConnectedSession,
                    $packetId === OpenConnectionRequest1::ID || $packetId === OpenConnectionRequest2::ID,
                );
                if ($admission !== AdmissionDecision::ALLOW) {
                    continue;
                }
                $isControlPacket = $packetId === AcknowledgementCodec::ACK_ID
                    || $packetId === AcknowledgementCodec::NACK_ID;
                if (
                    $connectedSession instanceof ConnectedSession
                    && ($isControlPacket || ConnectedDatagram::acceptsFlags($packetId))
                ) {
                    $connectedInput = true;
                    $connectedSession->receiveBytes($payload);
                    $this->consumeConnectedEffects($key, $connectedSession, false, $packetId);
                    if (isset($this->connectedSessions[$key])) {
                        $this->connectedSessionsDue[$key] = true;
                    }
                    continue;
                }

                $result = $this->handleOfflineDatagram($payload, $sourceAddress, $sourcePort);
            } catch (CodecException|InvalidArgumentException) {
                $this->abuseGuard->malformed($sourceAddress);
                if ($connectedInput) {
                    $this->appendHandshakeDiagnosticFor(
                        $key,
                        ConnectedHandshakeRejectionReason::MalformedDatagram,
                        datagramId: $packetId,
                        payloadLength: $received,
                    );
                    $this->removeSessionByKey(
                        $key,
                        SessionCloseReason::TransportFailure,
                        transportFailure: SessionTransportFailureReason::MalformedDatagram,
                    );
                }
                continue;
            } catch (UnexpectedValueException $exception) {
                $this->abuseGuard->malformed($sourceAddress, true);
                if ($connectedInput) {
                    $this->appendHandshakeDiagnosticFor(
                        $key,
                        ConnectedHandshakeRejectionReason::MalformedDatagram,
                        datagramId: $packetId,
                        payloadLength: $received,
                    );
                    $this->removeSessionByKey(
                        $key,
                        SessionCloseReason::TransportFailure,
                        transportFailure: SessionTransportFailureReason::MalformedDatagram,
                    );
                    continue;
                }

                throw new TransportException('Offline session failed closed while receiving a datagram.', 0, $exception);
            } catch (OverflowException|LogicException $exception) {
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: self::transportFailureReason($exception),
                    transportFailureDetail: $exception->getMessage(),
                );
                throw new TransportException('Connected session failed closed while receiving a datagram.', 0, $exception);
            }

            if (!$result instanceof OfflineDatagramResult) {
                continue;
            }

            $responseLength = \strlen($result->response);
            $sent = @socket_sendto($this->socket, $result->response, $responseLength, 0, $sourceAddress, $sourcePort);
            if ($sent !== $responseLength) {
                throw $this->socketFailure('send a UDP discovery response');
            }

            if ($result->sessionAfterSend instanceof SessionInfo) {
                $key = self::endpointKey($sourceAddress, $sourcePort);
                $this->sessions[$key] = $result->sessionAfterSend;
                $this->guidEndpoints[$result->sessionAfterSend->clientGuid] = $key;
                $this->connectedSessions[$key] = new ConnectedSession($this->clock, $result->sessionAfterSend->mtu);
                $now = $this->clock->nowNanoseconds();
                $deadline = $now > PHP_INT_MAX - $this->handshakeTimeoutNanoseconds
                    ? PHP_INT_MAX
                    : $now + $this->handshakeTimeoutNanoseconds;
                $this->connectedControlSessions[$key] = new ConnectedControlSession(
                    $result->sessionAfterSend,
                    $deadline,
                    $this->sessionIdleTimeoutNanoseconds,
                    $this->sessionPingIntervalNanoseconds,
                );
                unset($this->pendingHandshakes[$key]);
            }
        }

        $this->maintainConnectedSessions();

        return $handled;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $closing = [];
        foreach ($this->connectedControlSessions as $key => $control) {
            if ($control->isReady() && ($session = $this->sessions[$key] ?? null) instanceof SessionInfo) {
                $closing[] = new SessionClosedEvent($session, SessionCloseReason::ServerClosed);
            }
        }
        foreach ($this->connectedSessions as $connectedSession) {
            $connectedSession->clear();
        }
        $this->pendingHandshakes = [];
        $this->sessions = [];
        $this->guidEndpoints = [];
        $this->connectedSessions = [];
        $this->connectedControlSessions = [];
        $this->receivedPayloads = [];
        $this->receivedPayloadBytes = 0;
        $this->pendingOutboundDatagrams = [];
        $this->pendingOutboundDatagramCount = 0;
        $this->pendingOutboundBytes = 0;
        $this->connectedSessionsDue = [];
        $this->sessionEvents = $closing;
        socket_close($this->socket);
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function handleOfflineDatagram(string $payload, string $sourceAddress, int $sourcePort): ?OfflineDatagramResult
    {
        return match (\ord($payload[0])) {
            UnconnectedPing::ID => $this->handlePing($payload),
            UnconnectedPing::OPEN_CONNECTIONS_ID => $this->discoveryStatus->acceptingConnections
                ? $this->handlePing($payload)
                : null,
            OpenConnectionRequest1::ID => $this->handleRequest1($payload, $sourceAddress, $sourcePort),
            OpenConnectionRequest2::ID => $this->handleRequest2($payload, $sourceAddress, $sourcePort),
            default => null,
        };
    }

    private function handlePing(string $payload): OfflineDatagramResult
    {
        $ping = UnconnectedPing::decode($payload);

        return new OfflineDatagramResult(
            new UnconnectedPong($ping->timestamp, $this->serverGuid, $this->discoveryStatus->payload)->encode(),
        );
    }

    private function handleRequest1(string $payload, string $sourceAddress, int $sourcePort): ?OfflineDatagramResult
    {
        $request = OpenConnectionRequest1::decode($payload, self::MAXIMUM_IPV4_PROBE_MTU);
        if ($request->protocolVersion !== $this->rakNetProtocolVersion) {
            return new OfflineDatagramResult(
                new IncompatibleProtocolVersion($this->rakNetProtocolVersion, $this->serverGuid)->encode(),
            );
        }

        $key = self::endpointKey($sourceAddress, $sourcePort);
        $existing = $this->sessions[$key] ?? null;
        if ($existing instanceof SessionInfo) {
            unset($this->pendingHandshakes[$key]);

            return new OfflineDatagramResult(new OpenConnectionReply1($this->serverGuid, $existing->mtu)->encode());
        }

        if (!isset($this->pendingHandshakes[$key]) && \count($this->pendingHandshakes) >= $this->maximumPendingHandshakes) {
            return null;
        }

        $negotiatedMtu = min($request->mtu, $this->maximumNegotiatedMtu);
        $this->pendingHandshakes[$key] = new PendingHandshake(
            $negotiatedMtu,
            $request->protocolVersion,
            $this->clock->nowNanoseconds() + $this->handshakeTimeoutNanoseconds,
        );

        return new OfflineDatagramResult(new OpenConnectionReply1($this->serverGuid, $negotiatedMtu)->encode());
    }

    private function handleRequest2(string $payload, string $sourceAddress, int $sourcePort): ?OfflineDatagramResult
    {
        $request = OpenConnectionRequest2::decode($payload);
        $key = self::endpointKey($sourceAddress, $sourcePort);
        $clientAddress = new InternetAddress($sourceAddress, $sourcePort);
        $existing = $this->sessions[$key] ?? null;
        if ($existing instanceof SessionInfo) {
            if ($existing->clientGuid !== $request->clientGuid || $existing->mtu !== $request->mtu) {
                return null;
            }

            return new OfflineDatagramResult(
                new OpenConnectionReply2($this->serverGuid, $clientAddress, $existing->mtu)->encode(),
            );
        }

        $pending = $this->pendingHandshakes[$key] ?? null;
        if (!$pending instanceof PendingHandshake || $request->mtu > $pending->mtu) {
            return null;
        }

        if ($request->mtu < OpenConnectionRequest1::MINIMUM_MTU || $request->mtu > $this->maximumNegotiatedMtu) {
            return null;
        }

        if (\count($this->sessions) >= $this->maximumSessions) {
            return null;
        }

        $guidEndpoint = $this->guidEndpoints[$request->clientGuid] ?? null;
        if ($guidEndpoint !== null && $guidEndpoint !== $key) {
            return null;
        }

        $session = new SessionInfo(
            $sourceAddress,
            $sourcePort,
            $request->clientGuid,
            $request->mtu,
            $pending->rakNetProtocolVersion,
        );
        return new OfflineDatagramResult(
            new OpenConnectionReply2($this->serverGuid, $clientAddress, $request->mtu)->encode(),
            $session,
        );
    }

    private function expirePendingHandshakes(): void
    {
        $now = $this->clock->nowNanoseconds();
        foreach ($this->pendingHandshakes as $key => $pending) {
            if ($pending->expiresAtNanoseconds <= $now) {
                unset($this->pendingHandshakes[$key]);
            }
        }
    }

    private function maintainConnectedSessions(): void
    {
        $now = $this->clock->nowNanoseconds();
        if ($now >= $this->nextConnectedSessionMaintenanceNanoseconds) {
            $keys = array_keys($this->connectedSessions);
            $this->nextConnectedSessionMaintenanceNanoseconds = $now > PHP_INT_MAX - $this->connectedSessionMaintenanceIntervalNanoseconds
                ? PHP_INT_MAX
                : $now + $this->connectedSessionMaintenanceIntervalNanoseconds;
        } else {
            $keys = array_keys($this->connectedSessionsDue);
        }
        foreach ($keys as $key) {
            unset($this->connectedSessionsDue[$key]);
        }
        $this->tickConnectedSessions($keys, $now);
    }

    /** @param list<string> $keys */
    private function tickConnectedSessions(array $keys, int $now): void
    {
        foreach ($keys as $key) {
            $session = $this->connectedSessions[$key] ?? null;
            if (!$session instanceof ConnectedSession) {
                continue;
            }

            try {
                $control = $this->connectedControlSessions[$key] ?? null;
                if (!$control instanceof ConnectedControlSession) {
                    throw new LogicException('Connected session has no control-phase owner.');
                }
                $diagnosticStage = $this->connectedHandshakeStage($control);
                $timeoutReason = $control->tick($now);
                if ($timeoutReason !== null) {
                    if ($timeoutReason === SessionCloseReason::HandshakeTimeout) {
                        $this->appendHandshakeDiagnosticFor(
                            $key,
                            ConnectedHandshakeRejectionReason::Timeout,
                            stage: $diagnosticStage,
                        );
                    }
                    $this->removeSessionByKey($key, $timeoutReason);
                    continue;
                }
                $ping = $control->heartbeatPayload($now);
                if ($ping !== null) {
                    $session->queuePayload($ping->payload, $ping->reliability, $ping->orderingChannel);
                }
                $this->flushPendingOutbound($key);
                $session->tick();
                $this->consumeConnectedEffects($key, $session, true);
                if (isset($this->pendingOutboundDatagrams[$key])) {
                    $this->connectedSessionsDue[$key] = true;
                }
            } catch (OverflowException|LogicException $exception) {
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: self::transportFailureReason($exception),
                    transportFailureDetail: $exception->getMessage(),
                );
                continue;
            }
        }
    }

    private function consumeConnectedEffects(
        string $key,
        ConnectedSession $connectedSession,
        bool $sendOutbound,
        ?int $datagramId = null,
    ): void {
        $effects = $connectedSession->drainEffects();
        $sessionInfo = $this->sessions[$key] ?? null;
        if (!$sessionInfo instanceof SessionInfo) {
            $connectedSession->clear();
            unset($this->connectedSessions[$key]);

            return;
        }

        $applicationPayloads = [];
        foreach ($effects->payloads as $payload) {
            $control = $this->connectedControlSessions[$key] ?? null;
            if (!$control instanceof ConnectedControlSession) {
                throw new LogicException('Connected payload has no control-phase owner.');
            }
            try {
                $this->validateConnectedControlEnvelope($payload);
            } catch (UnexpectedValueException) {
                $this->appendHandshakeDiagnosticFor(
                    $key,
                    ConnectedHandshakeRejectionReason::InvalidEnvelope,
                    datagramId: $datagramId,
                    controlPacketId: \ord($payload->payload[0]),
                    payloadLength: \strlen($payload->payload),
                    reliability: $payload->reliability,
                    orderingChannel: $payload->orderingChannel,
                );
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: SessionTransportFailureReason::InvalidControlEnvelope,
                );

                return;
            }
            if (
                !$control->isReady()
                && $payload->payload !== ''
                && \ord($payload->payload[0]) === \Bedriox\RakNet\Protocol\NewIncomingConnection::ID
                && \count($this->sessionEvents) >= $this->maximumSessionEvents
            ) {
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    false,
                    SessionTransportFailureReason::SessionEventQueue,
                );
                throw new OverflowException('Session lifecycle event queue limit reached.');
            }
            try {
                $controlEffects = $control->receive($payload->payload, $this->clock->nowNanoseconds());
            } catch (CodecException|InvalidArgumentException|UnexpectedValueException) {
                $this->appendHandshakeDiagnosticFor(
                    $key,
                    ConnectedHandshakeRejectionReason::InvalidControlPayload,
                    datagramId: $datagramId,
                    controlPacketId: \ord($payload->payload[0]),
                    payloadLength: \strlen($payload->payload),
                    reliability: $payload->reliability,
                    orderingChannel: $payload->orderingChannel,
                );
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: SessionTransportFailureReason::InvalidControlPayload,
                );

                return;
            }
            foreach ($controlEffects->outboundPayloads as $outbound) {
                $connectedSession->queuePayload($outbound->payload, $outbound->reliability, $outbound->orderingChannel);
            }
            if ($controlEffects->becameReady) {
                $this->appendSessionEvent(new SessionOpenedEvent($sessionInfo));
            }
            if ($controlEffects->closeReason !== null) {
                $this->removeSessionByKey($key, $controlEffects->closeReason);

                return;
            }
            if ($controlEffects->deliverApplicationPayload) {
                $applicationPayloads[] = $payload;
            }
        }

        $newBytes = 0;
        foreach ($applicationPayloads as $payload) {
            $newBytes += \strlen($payload->payload);
        }
        if (
            \count($applicationPayloads) > $this->maximumReceivedPayloads - \count($this->receivedPayloads)
            || $newBytes > $this->maximumReceivedPayloadBytes - $this->receivedPayloadBytes
        ) {
            $this->removeSessionByKey(
                $key,
                SessionCloseReason::TransportFailure,
                transportFailure: SessionTransportFailureReason::GlobalReceivedPayloadQueue,
            );
            throw new TransportException('Global received-payload queue limit reached; endpoint was removed.');
        }

        foreach ($applicationPayloads as $payload) {
            $this->receivedPayloads[] = new ReceivedPayload(
                $sessionInfo->remoteAddress,
                $sessionInfo->remotePort,
                $payload->payload,
                $payload->reliability,
                $payload->orderingChannel,
            );
        }
        $this->receivedPayloadBytes += $newBytes;

        $newOutboundBytes = 0;
        foreach ($effects->outboundDatagrams as $datagram) {
            $newOutboundBytes += \strlen($datagram);
        }
        if (
            \count($effects->outboundDatagrams) > $this->maximumPendingOutboundDatagrams - $this->pendingOutboundDatagramCount
            || $newOutboundBytes > $this->maximumPendingOutboundBytes - $this->pendingOutboundBytes
        ) {
            $this->removeSessionByKey(
                $key,
                SessionCloseReason::TransportFailure,
                transportFailure: SessionTransportFailureReason::GlobalPendingOutboundQueue,
            );
            throw new TransportException('Global pending-outbound queue limit reached; endpoint was removed.');
        }
        $queues = $this->pendingOutboundDatagrams[$key] ?? ['priority' => [], 'normal' => []];
        foreach ($effects->outboundDatagrams as $offset => $datagram) {
            $queue = $offset < $effects->priorityOutboundDatagramCount ? 'priority' : 'normal';
            $queues[$queue][] = $datagram;
            ++$this->pendingOutboundDatagramCount;
            $this->pendingOutboundBytes += \strlen($datagram);
        }
        if ($effects->outboundDatagrams !== []) {
            $this->pendingOutboundDatagrams[$key] = $queues;
        }
        if (!$sendOutbound) {
            return;
        }
        $this->flushPendingOutbound($key);
    }

    private function validateConnectedControlEnvelope(\Bedriox\RakNet\Connected\ConnectedPayloadEvent $payload): void
    {
        $packetId = \ord($payload->payload[0]);
        $validConnectionRequest = $packetId === \Bedriox\RakNet\Protocol\ConnectionRequest::ID
            && (($payload->reliability === Reliability::Reliable && $payload->orderingChannel === null)
                || ($payload->reliability === Reliability::ReliableOrdered && $payload->orderingChannel === 0));
        $validNewIncomingConnection = $packetId === \Bedriox\RakNet\Protocol\NewIncomingConnection::ID
            && $payload->reliability === Reliability::ReliableOrdered
            && $payload->orderingChannel === 0;
        if (
            ($packetId === \Bedriox\RakNet\Protocol\ConnectionRequest::ID
                || $packetId === \Bedriox\RakNet\Protocol\NewIncomingConnection::ID)
            && !$validConnectionRequest
            && !$validNewIncomingConnection
        ) {
            throw new UnexpectedValueException(
                'Connected handshake message has an invalid reliability envelope.',
            );
        }
    }

    private function removeSessionByKey(
        string $key,
        SessionCloseReason $reason,
        bool $emitEvent = true,
        ?SessionTransportFailureReason $transportFailure = null,
        ?string $transportFailureDetail = null,
    ): void {
        $sessionInfo = $this->sessions[$key] ?? null;
        $wasReady = ($this->connectedControlSessions[$key] ?? null)?->hasOpened() ?? false;
        ($this->connectedSessions[$key] ?? null)?->clear();
        unset(
            $this->connectedSessions[$key],
            $this->connectedControlSessions[$key],
            $this->sessions[$key],
            $this->pendingHandshakes[$key],
            $this->connectedSessionsDue[$key],
        );
        $this->releasePendingOutbound($key);
        if ($sessionInfo instanceof SessionInfo) {
            unset($this->guidEndpoints[$sessionInfo->clientGuid]);
            if ($wasReady && $emitEvent) {
                $this->appendSessionEvent(new SessionClosedEvent(
                    $sessionInfo,
                    $reason,
                    $transportFailure,
                    $transportFailureDetail,
                ));
            }
        }
    }

    private function appendSessionEvent(SessionOpenedEvent|SessionClosedEvent $event): void
    {
        if (\count($this->sessionEvents) >= $this->maximumSessionEvents) {
            throw new OverflowException('Session lifecycle event queue limit reached.');
        }
        $this->sessionEvents[] = $event;
    }

    private function appendHandshakeDiagnosticFor(
        string $key,
        ConnectedHandshakeRejectionReason $reason,
        ?int $datagramId = null,
        ?int $controlPacketId = null,
        ?int $payloadLength = null,
        ?Reliability $reliability = null,
        ?int $orderingChannel = null,
        ?ConnectedHandshakeStage $stage = null,
    ): void {
        $session = $this->sessions[$key] ?? null;
        $control = $this->connectedControlSessions[$key] ?? null;
        if (!$session instanceof SessionInfo || !$control instanceof ConnectedControlSession) {
            return;
        }

        $stage ??= $this->connectedHandshakeStage($control);
        if ($stage === null) {
            return;
        }
        if (\count($this->handshakeDiagnostics) >= $this->maximumHandshakeDiagnosticEvents) {
            if ($this->droppedHandshakeDiagnosticCount < PHP_INT_MAX) {
                ++$this->droppedHandshakeDiagnosticCount;
            }

            return;
        }

        $this->handshakeDiagnostics[] = new ConnectedHandshakeDiagnosticEvent(
            $session->remoteAddress,
            $session->remotePort,
            $stage,
            $reason,
            $datagramId,
            $controlPacketId,
            $payloadLength,
            $reliability,
            $orderingChannel,
        );
    }

    private function connectedHandshakeStage(ConnectedControlSession $control): ?ConnectedHandshakeStage
    {
        return match ($control->state()) {
            ConnectedControlState::AwaitingConnectionRequest => ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedControlState::AwaitingNewIncomingConnection => ConnectedHandshakeStage::AwaitingNewIncomingConnection,
            ConnectedControlState::Ready, ConnectedControlState::Closed => null,
        };
    }

    private function flushPendingOutbound(string $key): void
    {
        $sessionInfo = $this->sessions[$key] ?? null;
        if (!$sessionInfo instanceof SessionInfo) {
            $this->releasePendingOutbound($key);

            return;
        }

        while (isset($this->pendingOutboundDatagrams[$key])) {
            $queues = $this->pendingOutboundDatagrams[$key];
            $queue = $queues['priority'] !== [] ? 'priority' : 'normal';
            if ($queues[$queue] === []) {
                break;
            }
            $datagram = $queues[$queue][0];
            $length = \strlen($datagram);
            $sent = @socket_sendto(
                $this->socket,
                $datagram,
                $length,
                0,
                $sessionInfo->remoteAddress,
                $sessionInfo->remotePort,
            );
            if ($sent === false) {
                $error = socket_last_error($this->socket);
                if ($error === SOCKET_EWOULDBLOCK) {
                    socket_clear_error($this->socket);

                    return;
                }
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: SessionTransportFailureReason::SocketSend,
                );
                throw $this->socketFailure('send a connected UDP datagram', $error);
            }
            if ($sent !== $length) {
                $error = socket_last_error($this->socket);
                $this->removeSessionByKey(
                    $key,
                    SessionCloseReason::TransportFailure,
                    transportFailure: SessionTransportFailureReason::SocketSend,
                );
                throw $this->socketFailure('send a complete connected UDP datagram', $error);
            }

            array_shift($queues[$queue]);
            $this->pendingOutboundDatagrams[$key] = $queues;
            --$this->pendingOutboundDatagramCount;
            $this->pendingOutboundBytes -= $length;
        }
        $queues = $this->pendingOutboundDatagrams[$key] ?? null;
        if ($queues !== null && $queues['priority'] === [] && $queues['normal'] === []) {
            unset($this->pendingOutboundDatagrams[$key]);
        }
    }

    private function releasePendingOutbound(string $key): void
    {
        $pending = $this->pendingOutboundDatagrams[$key] ?? ['priority' => [], 'normal' => []];
        foreach ([$pending['priority'], $pending['normal']] as $queue) {
            foreach ($queue as $datagram) {
                --$this->pendingOutboundDatagramCount;
                $this->pendingOutboundBytes -= \strlen($datagram);
            }
        }
        unset($this->pendingOutboundDatagrams[$key]);
    }

    private static function endpointKey(string $address, int $port): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65_535) {
            throw new TransportException('Session endpoint must contain a valid IPv4 address and port.');
        }

        return $address . ':' . $port;
    }

    private static function isRecoverableReceiveError(int $error): bool
    {
        return PHP_OS_FAMILY === 'Windows' && $error === SOCKET_ECONNRESET;
    }

    private function socketFailure(string $operation, ?int $error = null): TransportException
    {
        $error ??= socket_last_error($this->socket);
        $message = $error === 0 ? 'unknown socket error' : socket_strerror($error);

        return new TransportException(\sprintf('Unable to %s: [%d] %s.', $operation, $error, $message), $error);
    }

    private static function transportFailureReason(OverflowException|LogicException $exception): SessionTransportFailureReason
    {
        return match ($exception->getMessage()) {
            'Outbound frame queue limit reached.' => SessionTransportFailureReason::OutboundFrameQueue,
            'Reliable frame tracking limit reached.' => SessionTransportFailureReason::ReliableFrameTracking,
            'Undrained reliable-expiry effect limit reached; session was cleared.' => SessionTransportFailureReason::ReliableExpiryQueue,
            'Fragment reassembly capacity was exhausted.' => SessionTransportFailureReason::FragmentReassembly,
            'Ordered-delivery capacity was exhausted.' => SessionTransportFailureReason::OrderedDelivery,
            'Undrained delivered-payload effect limit reached; session was cleared.' => SessionTransportFailureReason::DeliveredPayloadQueue,
            'Outbound datagram queue limit reached.' => SessionTransportFailureReason::OutboundDatagramQueue,
            default => $exception instanceof LogicException
                ? SessionTransportFailureReason::InvariantViolation
                : SessionTransportFailureReason::ResourceLimit,
        };
    }
}
