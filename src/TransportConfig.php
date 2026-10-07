<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Security\TransportSecurityPolicy;
use InvalidArgumentException;

/**
 * Immutable limits for one RakNet transport server.
 */
final readonly class TransportConfig
{
    public TransportSecurityPolicy $security;

    public function __construct(
        public string $bindAddress = '0.0.0.0',
        public int $port = 19132,
        public int $maximumTransmissionUnit = 1_400,
        public int $maximumSessions = 1_024,
        public int $maximumPendingHandshakes = 1_024,
        public int $handshakeTimeoutMilliseconds = 5_000,
        public int $sessionIdleTimeoutMilliseconds = 10_000,
        public int $sessionPingIntervalMilliseconds = 5_000,
        public int $connectedSessionMaintenanceIntervalMilliseconds = 10,
        public int $maximumReceivedPayloads = 4_096,
        public int $maximumReceivedPayloadBytes = 2_097_152,
        public int $maximumPendingOutboundDatagrams = 4_096,
        public int $maximumPendingOutboundBytes = 4_194_304,
        public int $maximumSessionEvents = 65_535,
        public int $maximumHandshakeDiagnosticEvents = 1_024,
        public int $socketReceiveBufferBytes = 4_194_304,
        public int $socketSendBufferBytes = 4_194_304,
        ?TransportSecurityPolicy $security = null,
    ) {
        $this->security = $security ?? new TransportSecurityPolicy();
        if ($this->bindAddress === '') {
            throw new InvalidArgumentException('Bind address cannot be empty.');
        }

        if ($this->port < 0 || $this->port > 65_535) {
            throw new InvalidArgumentException('Port must be between 0 and 65535.');
        }

        if ($this->maximumTransmissionUnit < 576 || $this->maximumTransmissionUnit > 1_492) {
            throw new InvalidArgumentException('Maximum transmission unit must be between 576 and 1492 bytes.');
        }

        if ($this->maximumSessions < 1 || $this->maximumSessions > 65_535) {
            throw new InvalidArgumentException('Maximum sessions must be between 1 and 65535.');
        }

        if ($this->maximumPendingHandshakes < 1 || $this->maximumPendingHandshakes > 65_535) {
            throw new InvalidArgumentException('Maximum pending handshakes must be between 1 and 65535.');
        }

        if ($this->handshakeTimeoutMilliseconds < 100 || $this->handshakeTimeoutMilliseconds > 60_000) {
            throw new InvalidArgumentException('Handshake timeout must be between 100 and 60000 milliseconds.');
        }
        if ($this->sessionIdleTimeoutMilliseconds < 5_000 || $this->sessionIdleTimeoutMilliseconds > 300_000) {
            throw new InvalidArgumentException('Session idle timeout must be between 5000 and 300000 milliseconds.');
        }
        if ($this->sessionPingIntervalMilliseconds < 1_000 || $this->sessionPingIntervalMilliseconds >= $this->sessionIdleTimeoutMilliseconds) {
            throw new InvalidArgumentException('Session ping interval must be at least 1000 milliseconds and shorter than the idle timeout.');
        }
        if ($this->connectedSessionMaintenanceIntervalMilliseconds < 1
            || $this->connectedSessionMaintenanceIntervalMilliseconds > 50) {
            throw new InvalidArgumentException('Connected-session maintenance interval must be between 1 and 50 milliseconds.');
        }

        if ($this->maximumReceivedPayloads < 1 || $this->maximumReceivedPayloads > 65_535) {
            throw new InvalidArgumentException('Maximum received payloads must be between 1 and 65535.');
        }

        if ($this->maximumReceivedPayloadBytes < 1 || $this->maximumReceivedPayloadBytes > 67_108_864) {
            throw new InvalidArgumentException('Maximum received payload bytes must be between 1 and 67108864.');
        }

        if ($this->maximumPendingOutboundDatagrams < 1 || $this->maximumPendingOutboundDatagrams > 65_535) {
            throw new InvalidArgumentException('Maximum pending outbound datagrams must be between 1 and 65535.');
        }

        if ($this->maximumPendingOutboundBytes < 548 || $this->maximumPendingOutboundBytes > 67_108_864) {
            throw new InvalidArgumentException('Maximum pending outbound bytes must be between 548 and 67108864.');
        }
        if ($this->maximumPendingOutboundBytes < $this->maximumTransmissionUnit - 28) {
            throw new InvalidArgumentException('Maximum pending outbound bytes must hold one negotiated IPv4 UDP datagram.');
        }
        if ($this->maximumSessionEvents < $this->maximumSessions || $this->maximumSessionEvents > 65_535) {
            throw new InvalidArgumentException('Maximum session events must be between maximum sessions and 65535.');
        }
        if ($this->maximumHandshakeDiagnosticEvents < 1 || $this->maximumHandshakeDiagnosticEvents > 65_535) {
            throw new InvalidArgumentException('Maximum handshake diagnostic events must be between 1 and 65535.');
        }
        if ($this->socketReceiveBufferBytes < 65_536 || $this->socketReceiveBufferBytes > 67_108_864) {
            throw new InvalidArgumentException('Socket receive buffer must be between 65536 and 67108864 bytes.');
        }
        if ($this->socketSendBufferBytes < 65_536 || $this->socketSendBufferBytes > 67_108_864) {
            throw new InvalidArgumentException('Socket send buffer must be between 65536 and 67108864 bytes.');
        }
    }
}
