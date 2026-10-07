<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Security;

use InvalidArgumentException;

final readonly class TransportSecurityPolicy
{
    public function __construct(
        public bool $enabled = true,
        public bool $automaticBlocking = true,
        public int $globalDatagramsPerSecond = 100_000,
        public int $globalDatagramBurst = 100_000,
        public int $globalBytesPerSecond = 134_217_728,
        public int $globalByteBurst = 33_554_432,
        public int $unauthenticatedDatagramsPerSecond = 2_000,
        public int $unauthenticatedDatagramBurst = 400,
        public int $unauthenticatedBytesPerSecond = 4_194_304,
        public int $unauthenticatedByteBurst = 1_048_576,
        public int $connectedDatagramsPerSecond = 12_000,
        public int $connectedDatagramBurst = 240,
        public int $connectedBytesPerSecond = 16_777_216,
        public int $connectedByteBurst = 2_097_152,
        public int $handshakesPerSecond = 250,
        public int $handshakeBurst = 128,
        public int $malformedThreshold = 3,
        public int $baseBlockSeconds = 10,
        public int $maximumBlockSeconds = 1_800,
        public int $escalationWindowSeconds = 300,
        public int $maximumTrackedAddresses = 4_096,
        public int $maximumTrackedEndpoints = 8_192,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if (\is_int($value) && $value < 1) {
                throw new InvalidArgumentException("Transport security limit {$name} must be positive.");
            }
        }
        if ($this->baseBlockSeconds > $this->maximumBlockSeconds) {
            throw new InvalidArgumentException('Base block duration cannot exceed the maximum block duration.');
        }
        if ($this->maximumTrackedEndpoints < $this->maximumTrackedAddresses) {
            throw new InvalidArgumentException('Tracked endpoint capacity cannot be smaller than address capacity.');
        }
    }
}
