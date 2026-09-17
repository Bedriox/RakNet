<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\UnconnectedPong;
use InvalidArgumentException;

/** A bounded application-owned status payload plus the generic open-connections response policy. */
final readonly class DiscoveryStatus
{
    public const int MAXIMUM_PAYLOAD_BYTES = UnconnectedPong::MAXIMUM_STATUS_BYTES;

    public function __construct(
        public string $payload,
        public bool $acceptingConnections = true,
    ) {
        $length = \strlen($this->payload);
        if ($length < 1 || $length > self::MAXIMUM_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(\sprintf(
                'Discovery status payload must contain between 1 and %d bytes.',
                self::MAXIMUM_PAYLOAD_BYTES,
            ));
        }

        if (preg_match('//u', $this->payload) !== 1) {
            throw new InvalidArgumentException('Discovery status payload must be valid UTF-8.');
        }
    }
}
