<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** @internal Mutable scheduling state owned exclusively by ReliableFrameTracker. */
final class PendingReliableFrame
{
    public int $attempts = 0;
    public int $nextRetryAtNanoseconds = PHP_INT_MAX;
    public bool $retryQueued = false;

    public function __construct(public readonly CanonicalReliableFrame $frame) {}
}
