<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use InvalidArgumentException;

final readonly class EndpointAdmissionRejectedEvent
{
    public function __construct(
        public string $remoteAddress,
        public int $remotePort,
    ) {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Rejected endpoint address must be a literal IPv4 address.');
        }
        if ($this->remotePort < 1 || $this->remotePort > 65_535) {
            throw new InvalidArgumentException('Rejected endpoint port must be between 1 and 65535.');
        }
    }
}
