<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Security;

use Bedriox\RakNet\Clock;
use InvalidArgumentException;

/** Stateful transport admission owned exclusively by the RakNet event loop. */
final class TransportAbuseGuard
{
    /** @var array<string, array{datagrams: TokenBucket, bytes: TokenBucket, handshakes: TokenBucket, last: int}> */
    private array $addresses = [];

    /** @var array<string, array{datagrams: TokenBucket, bytes: TokenBucket, last: int}> */
    private array $endpoints = [];

    /** @var array<string, array{until: int, offenses: int, last: int}> */
    private array $blocks = [];

    /** @var array<string, array{offenses: int, last: int}> */
    private array $blockHistory = [];

    /** @var array<string, array{count: int, last: int}> */
    private array $malformed = [];

    private TokenBucket $globalDatagrams;
    private TokenBucket $globalBytes;
    private int $receivedDatagrams = 0;
    private int $receivedBytes = 0;
    private int $droppedDatagrams = 0;
    private int $rateLimitedEndpoints = 0;
    private int $malformedDatagrams = 0;
    private int $temporaryBlocks = 0;

    public function __construct(private readonly TransportSecurityPolicy $policy, private readonly Clock $clock)
    {
        $now = $clock->nowNanoseconds();
        $this->globalDatagrams = new TokenBucket($policy->globalDatagramsPerSecond, $policy->globalDatagramBurst, $now);
        $this->globalBytes = new TokenBucket($policy->globalBytesPerSecond, $policy->globalByteBurst, $now);
    }

    public function admit(string $address, int $port, int $bytes, bool $connected, bool $handshake): AdmissionDecision
    {
        $address = self::normalizeAddress($address);
        $endpoint = $address . ':' . $port;
        $now = $this->clock->nowNanoseconds();
        ++$this->receivedDatagrams;
        $this->receivedBytes += $bytes;
        $this->expire($now);
        if (!$this->policy->enabled) {
            return AdmissionDecision::ALLOW;
        }
        if (isset($this->blocks[$address])) {
            ++$this->droppedDatagrams;

            return AdmissionDecision::BLOCK;
        }
        if (!$this->globalDatagrams->consume(1, $now) || !$this->globalBytes->consume($bytes, $now)) {
            ++$this->droppedDatagrams;

            return AdmissionDecision::DROP;
        }

        if ($connected) {
            $state = $this->endpoints[$endpoint] ?? $this->newEndpoint($now);
            $allowed = $state['datagrams']->consume(1, $now) && $state['bytes']->consume($bytes, $now);
            $state['last'] = $now;
            $this->endpoints[$endpoint] = $state;
            $this->trim($this->endpoints, $this->policy->maximumTrackedEndpoints);
            if (!$allowed) {
                ++$this->droppedDatagrams;
                ++$this->rateLimitedEndpoints;

                return AdmissionDecision::DROP;
            }

            return AdmissionDecision::ALLOW;
        }

        $state = $this->addresses[$address] ?? $this->newAddress($now);
        $allowed = $state['datagrams']->consume(1, $now) && $state['bytes']->consume($bytes, $now);
        if ($allowed && $handshake) {
            $allowed = $state['handshakes']->consume(1, $now);
        }
        $state['last'] = $now;
        $this->addresses[$address] = $state;
        $this->trim($this->addresses, $this->policy->maximumTrackedAddresses);
        if ($allowed) {
            return AdmissionDecision::ALLOW;
        }
        ++$this->droppedDatagrams;
        ++$this->rateLimitedEndpoints;
        if ($this->policy->automaticBlocking) {
            $this->block($address, $now);

            return AdmissionDecision::BLOCK;
        }

        return AdmissionDecision::DROP;
    }

    public function malformed(string $address, bool $severe = false): bool
    {
        $address = self::normalizeAddress($address);
        $now = $this->clock->nowNanoseconds();
        ++$this->malformedDatagrams;
        $state = $this->malformed[$address] ?? ['count' => 0, 'last' => $now];
        if ($now - $state['last'] > $this->policy->escalationWindowSeconds * 1_000_000_000) {
            $state['count'] = 0;
        }
        ++$state['count'];
        $state['last'] = $now;
        $this->malformed[$address] = $state;
        $this->trim($this->malformed, $this->policy->maximumTrackedAddresses);
        if (!$this->policy->enabled || !$this->policy->automaticBlocking
            || (!$severe && $state['count'] < $this->policy->malformedThreshold)) {
            return false;
        }
        $this->block($address, $now);

        return true;
    }

    public function blockAddress(string $address, ?int $seconds = null): void
    {
        $address = self::normalizeAddress($address);
        $now = $this->clock->nowNanoseconds();
        $seconds ??= $this->policy->baseBlockSeconds;
        if ($seconds < 1 || $seconds > $this->policy->maximumBlockSeconds) {
            throw new InvalidArgumentException('Block duration is outside the configured range.');
        }
        $existing = $this->blocks[$address] ?? ['offenses' => 0, 'last' => $now, 'until' => 0];
        $this->blocks[$address] = [
            'until' => $now + $seconds * 1_000_000_000,
            'offenses' => $existing['offenses'] + 1,
            'last' => $now,
        ];
        ++$this->temporaryBlocks;
    }

    public function unblockAddress(string $address): void
    {
        unset($this->blocks[self::normalizeAddress($address)]);
    }

    public function snapshot(): TransportSecuritySnapshot
    {
        $this->expire($this->clock->nowNanoseconds());

        return new TransportSecuritySnapshot(
            $this->receivedDatagrams,
            $this->receivedBytes,
            $this->droppedDatagrams,
            $this->rateLimitedEndpoints,
            $this->malformedDatagrams,
            $this->temporaryBlocks,
            \count($this->blocks),
            \count($this->addresses),
            \count($this->endpoints),
        );
    }

    /** @return array{datagrams: TokenBucket, bytes: TokenBucket, handshakes: TokenBucket, last: int} */
    private function newAddress(int $now): array
    {
        return [
            'datagrams' => new TokenBucket($this->policy->unauthenticatedDatagramsPerSecond, $this->policy->unauthenticatedDatagramBurst, $now),
            'bytes' => new TokenBucket($this->policy->unauthenticatedBytesPerSecond, $this->policy->unauthenticatedByteBurst, $now),
            'handshakes' => new TokenBucket($this->policy->handshakesPerSecond, $this->policy->handshakeBurst, $now),
            'last' => $now,
        ];
    }

    /** @return array{datagrams: TokenBucket, bytes: TokenBucket, last: int} */
    private function newEndpoint(int $now): array
    {
        return [
            'datagrams' => new TokenBucket($this->policy->connectedDatagramsPerSecond, $this->policy->connectedDatagramBurst, $now),
            'bytes' => new TokenBucket($this->policy->connectedBytesPerSecond, $this->policy->connectedByteBurst, $now),
            'last' => $now,
        ];
    }

    private function block(string $address, int $now): void
    {
        $existing = $this->blockHistory[$address] ?? null;
        $offenses = 1;
        if ($existing !== null && $now - $existing['last'] <= $this->policy->escalationWindowSeconds * 1_000_000_000) {
            $offenses = min(31, $existing['offenses'] + 1);
        }
        $multiplier = 1 << min(10, $offenses - 1);
        $seconds = min($this->policy->maximumBlockSeconds, $this->policy->baseBlockSeconds * $multiplier);
        $this->blocks[$address] = ['until' => $now + $seconds * 1_000_000_000, 'offenses' => $offenses, 'last' => $now];
        $this->blockHistory[$address] = ['offenses' => $offenses, 'last' => $now];
        $this->trim($this->blockHistory, $this->policy->maximumTrackedAddresses);
        ++$this->temporaryBlocks;
    }

    private function expire(int $now): void
    {
        foreach ($this->blocks as $address => $state) {
            if ($state['until'] <= $now) {
                unset($this->blocks[$address]);
            }
        }
        $stale = $now - $this->policy->escalationWindowSeconds * 1_000_000_000;
        foreach ($this->addresses as $key => $state) {
            if ($state['last'] < $stale) {
                unset($this->addresses[$key]);
            }
        }
        foreach ($this->endpoints as $key => $state) {
            if ($state['last'] < $stale) {
                unset($this->endpoints[$key]);
            }
        }
        foreach ($this->malformed as $key => $state) {
            if ($state['last'] < $stale) {
                unset($this->malformed[$key]);
            }
        }
        foreach ($this->blockHistory as $key => $state) {
            if ($state['last'] < $stale) {
                unset($this->blockHistory[$key]);
            }
        }
    }

    /**
     * @template T of array{last: int}
     * @param array<string, T> $states
     */
    private function trim(array &$states, int $maximum): void
    {
        while (\count($states) > $maximum) {
            $oldestKey = null;
            $oldest = PHP_INT_MAX;
            foreach ($states as $key => $state) {
                if ($state['last'] < $oldest) {
                    $oldest = $state['last'];
                    $oldestKey = $key;
                }
            }
            if ($oldestKey === null) {
                break;
            }
            unset($states[$oldestKey]);
        }
    }

    private static function normalizeAddress(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException('Address is not a valid IPv4 or IPv6 address.');
        }
        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            throw new InvalidArgumentException('Address could not be normalized.');
        }

        return strtolower($normalized);
    }
}
