<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Security;

/** @internal Owned by the single RakNet event loop. */
final class TokenBucket
{
    private float $tokens;

    public function __construct(
        private readonly int $refillPerSecond,
        private readonly int $capacity,
        private int $updatedAtNanoseconds,
    ) {
        $this->tokens = $capacity;
    }

    public function consume(int $amount, int $nowNanoseconds): bool
    {
        if ($nowNanoseconds > $this->updatedAtNanoseconds) {
            $elapsed = $nowNanoseconds - $this->updatedAtNanoseconds;
            $this->tokens = min(
                $this->capacity,
                $this->tokens + ($elapsed / 1_000_000_000) * $this->refillPerSecond,
            );
            $this->updatedAtNanoseconds = $nowNanoseconds;
        }
        if ($amount > $this->tokens) {
            return false;
        }
        $this->tokens -= $amount;

        return true;
    }
}
