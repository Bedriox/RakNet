<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\OpenConnectionRequest1;
use InvalidArgumentException;

final readonly class OfflineDatagramResult
{
    public function __construct(
        public string $response,
        public ?SessionInfo $sessionAfterSend = null,
    ) {
        $length = \strlen($this->response);
        if ($length < 1 || $length > OpenConnectionRequest1::MAXIMUM_MTU) {
            throw new InvalidArgumentException('Offline response length is out of range.');
        }
    }
}
