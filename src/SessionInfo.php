<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use InvalidArgumentException;

final readonly class SessionInfo
{
    public function __construct(
        public string $remoteAddress,
        public int $remotePort,
        public int $clientGuid,
        public int $mtu,
        public int $rakNetProtocolVersion,
    ) {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Session remote address must be IPv4.');
        }
        if ($this->remotePort < 1 || $this->remotePort > 65_535) {
            throw new InvalidArgumentException('Session port is out of range.');
        }
        if ($this->mtu < OpenConnectionRequest1::MINIMUM_MTU || $this->mtu > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new InvalidArgumentException('Session MTU is out of range.');
        }
        if ($this->rakNetProtocolVersion < 0 || $this->rakNetProtocolVersion > 0xff) {
            throw new InvalidArgumentException('Session protocol version is out of range.');
        }
    }
}
