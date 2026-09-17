<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use InvalidArgumentException;

final readonly class PendingHandshake
{
    public function __construct(
        public int $mtu,
        public int $rakNetProtocolVersion,
        public int $expiresAtNanoseconds,
    ) {
        if ($this->mtu < OpenConnectionRequest1::MINIMUM_MTU || $this->mtu > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new InvalidArgumentException('Pending handshake MTU is out of range.');
        }
        if ($this->rakNetProtocolVersion < 0 || $this->rakNetProtocolVersion > 0xff) {
            throw new InvalidArgumentException('Pending handshake protocol version is out of range.');
        }
        if ($this->expiresAtNanoseconds < 0) {
            throw new InvalidArgumentException('Pending handshake expiry cannot be negative.');
        }
    }
}
