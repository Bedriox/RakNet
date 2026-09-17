<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use InvalidArgumentException;

final readonly class ConnectedHandshakeDiagnosticBatch
{
    /** @var list<ConnectedHandshakeDiagnosticEvent> */
    public array $events;

    /** @param array<array-key, mixed> $events */
    public function __construct(
        array $events,
        public int $droppedEventCount,
    ) {
        if (!array_is_list($events)) {
            throw new InvalidArgumentException('Handshake diagnostic events must be a list.');
        }
        foreach ($events as $event) {
            if (!$event instanceof ConnectedHandshakeDiagnosticEvent) {
                throw new InvalidArgumentException('Handshake diagnostic batch contains an invalid event.');
            }
        }
        if ($this->droppedEventCount < 0) {
            throw new InvalidArgumentException('Dropped handshake diagnostic count must be nonnegative.');
        }
        $this->events = $events;
    }
}
