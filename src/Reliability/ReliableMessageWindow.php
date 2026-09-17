<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Independent deduplication window for reliable message indexes. */
final class ReliableMessageWindow
{
    private readonly ReceiveSequenceWindow $window;

    public function __construct(int $windowSize)
    {
        $this->window = new ReceiveSequenceWindow($windowSize);
    }

    public function observe(int $reliableIndex): SequenceObservation
    {
        return $this->window->observe($reliableIndex)->observation;
    }

    public function retainedCount(): int
    {
        return $this->window->retainedCount();
    }
}
