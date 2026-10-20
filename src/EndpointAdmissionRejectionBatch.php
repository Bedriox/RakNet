<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use InvalidArgumentException;

final readonly class EndpointAdmissionRejectionBatch
{
    /** @var list<EndpointAdmissionRejectedEvent> */
    public array $events;

    /** @param array<array-key, mixed> $events */
    public function __construct(
        array $events,
        public int $droppedEventCount,
    ) {
        if (!array_is_list($events)) {
            throw new InvalidArgumentException('Endpoint admission rejection events must be a list.');
        }
        foreach ($events as $event) {
            if (!$event instanceof EndpointAdmissionRejectedEvent) {
                throw new InvalidArgumentException('Endpoint admission rejection batch contains an invalid event.');
            }
        }
        if ($this->droppedEventCount < 0) {
            throw new InvalidArgumentException('Dropped endpoint admission rejection count must be nonnegative.');
        }
        $this->events = $events;
    }
}
