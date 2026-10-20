<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use InvalidArgumentException;

/** Immutable limits for one outbound RakNet connection. */
final readonly class ClientConfig
{
    public function __construct(
        public string $remoteAddress,
        public int $remotePort,
        public string $bindAddress = '0.0.0.0',
        public int $bindPort = 0,
        public int $maximumTransmissionUnit = 1_400,
        public int $handshakeTimeoutMilliseconds = 5_000,
        public int $handshakeRetryMilliseconds = 250,
        public int $rakNetProtocolVersion = DiscoveryServer::DEFAULT_RAKNET_PROTOCOL_VERSION,
        public int $maximumReceivedPayloads = 4_096,
        public int $maximumReceivedPayloadBytes = 2_097_152,
    ) {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($this->bindAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('RakNet client endpoints must use IPv4 addresses.');
        }
        if ($this->remotePort < 1 || $this->remotePort > 65_535 || $this->bindPort < 0 || $this->bindPort > 65_535) {
            throw new InvalidArgumentException('RakNet client port is out of range.');
        }
        if ($this->maximumTransmissionUnit < OpenConnectionRequest1::MINIMUM_MTU
            || $this->maximumTransmissionUnit > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new InvalidArgumentException('RakNet client MTU is out of range.');
        }
        if ($this->handshakeTimeoutMilliseconds < 100 || $this->handshakeTimeoutMilliseconds > 60_000
            || $this->handshakeRetryMilliseconds < 25
            || $this->handshakeRetryMilliseconds >= $this->handshakeTimeoutMilliseconds) {
            throw new InvalidArgumentException('RakNet client handshake timing is out of range.');
        }
        if ($this->rakNetProtocolVersion < 0 || $this->rakNetProtocolVersion > 0xff) {
            throw new InvalidArgumentException('RakNet client protocol version must fit in one byte.');
        }
        if ($this->maximumReceivedPayloads < 1 || $this->maximumReceivedPayloads > 65_535
            || $this->maximumReceivedPayloadBytes < 1 || $this->maximumReceivedPayloadBytes > 67_108_864) {
            throw new InvalidArgumentException('RakNet client receive limits are out of range.');
        }
    }
}
