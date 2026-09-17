<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

use Bedriox\RakNet\Clock;
use InvalidArgumentException;
use RuntimeException;

final class FragmentReassembler
{
    /**
     * @var array<int, array{
     *     fragmentCount: int,
     *     parts: array<int, string>,
     *     bytes: int,
     *     expiresAtNanoseconds: int
     * }>
     */
    private array $assemblies = [];

    private int $bufferedFragmentCount = 0;
    private int $bufferedByteCount = 0;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $lifetimeNanoseconds,
        private readonly int $maximumActiveAssemblies,
        private readonly int $maximumFragmentsPerAssembly,
        private readonly int $maximumBytesPerAssembly,
        private readonly int $maximumAggregateBytes,
    ) {
        if ($this->lifetimeNanoseconds < 1) {
            throw new InvalidArgumentException('Fragment assembly lifetime must be positive.');
        }
        if ($this->maximumActiveAssemblies < 1) {
            throw new InvalidArgumentException('Maximum active fragment assemblies must be positive.');
        }
        if ($this->maximumFragmentsPerAssembly < 1) {
            throw new InvalidArgumentException('Maximum fragments per assembly must be positive.');
        }
        if ($this->maximumBytesPerAssembly < 1) {
            throw new InvalidArgumentException('Maximum bytes per fragment assembly must be positive.');
        }
        if ($this->maximumAggregateBytes < 1) {
            throw new InvalidArgumentException('Maximum aggregate fragment bytes must be positive.');
        }
    }

    public function accept(Fragment $fragment): ?string
    {
        return $this->acceptDetailed($fragment)->payload;
    }

    public function acceptDetailed(Fragment $fragment): FragmentAcceptance
    {
        $now = $this->nowNanoseconds();
        $this->expireAt($now);

        $assembly = $this->assemblies[$fragment->splitId] ?? null;
        if ($assembly === null) {
            if (!$this->startAssembly($fragment, $now)) {
                return new FragmentAcceptance(FragmentAcceptanceStatus::RejectedCapacity);
            }

            return new FragmentAcceptance(FragmentAcceptanceStatus::Partial);
        }

        if ($assembly['fragmentCount'] !== $fragment->fragmentCount) {
            $this->remove($fragment->splitId);

            return new FragmentAcceptance(FragmentAcceptanceStatus::RejectedConflict);
        }

        $existingPayload = $assembly['parts'][$fragment->fragmentIndex] ?? null;
        if ($existingPayload !== null) {
            if ($existingPayload !== $fragment->payload) {
                $this->remove($fragment->splitId);

                return new FragmentAcceptance(FragmentAcceptanceStatus::RejectedConflict);
            }

            return new FragmentAcceptance(FragmentAcceptanceStatus::Duplicate);
        }

        $payloadBytes = \strlen($fragment->payload);
        if (
            $payloadBytes > $this->maximumBytesPerAssembly - $assembly['bytes']
            || $payloadBytes > $this->maximumAggregateBytes - $this->bufferedByteCount
        ) {
            $this->remove($fragment->splitId);

            return new FragmentAcceptance(FragmentAcceptanceStatus::RejectedCapacity);
        }

        $assembly['parts'][$fragment->fragmentIndex] = $fragment->payload;
        $assembly['bytes'] += $payloadBytes;
        $this->assemblies[$fragment->splitId] = $assembly;
        ++$this->bufferedFragmentCount;
        $this->bufferedByteCount += $payloadBytes;

        $payload = $this->completeIfReady($fragment->splitId);

        return $payload === null
            ? new FragmentAcceptance(FragmentAcceptanceStatus::Partial)
            : new FragmentAcceptance(FragmentAcceptanceStatus::Completed, $payload);
    }

    public function activeAssemblyCount(): int
    {
        $this->expire();

        return \count($this->assemblies);
    }

    public function bufferedFragmentCount(): int
    {
        $this->expire();

        return $this->bufferedFragmentCount;
    }

    public function bufferedByteCount(): int
    {
        $this->expire();

        return $this->bufferedByteCount;
    }

    public function remove(int $splitId): bool
    {
        if ($splitId < 0 || $splitId > Fragment::MAXIMUM_SPLIT_ID) {
            throw new InvalidArgumentException('Fragment split ID must fit in an unsigned 16-bit integer.');
        }

        $assembly = $this->assemblies[$splitId] ?? null;
        if ($assembly === null) {
            return false;
        }

        $this->bufferedFragmentCount -= \count($assembly['parts']);
        $this->bufferedByteCount -= $assembly['bytes'];
        unset($this->assemblies[$splitId]);

        return true;
    }

    public function expire(): int
    {
        return $this->expireAt($this->nowNanoseconds());
    }

    public function clear(): void
    {
        $this->assemblies = [];
        $this->bufferedFragmentCount = 0;
        $this->bufferedByteCount = 0;
    }

    private function startAssembly(Fragment $fragment, int $now): bool
    {
        $payloadBytes = \strlen($fragment->payload);
        if (
            $fragment->fragmentCount > $this->maximumFragmentsPerAssembly
            || $payloadBytes > $this->maximumBytesPerAssembly
            || $payloadBytes > $this->maximumAggregateBytes
            || \count($this->assemblies) >= $this->maximumActiveAssemblies
            || $payloadBytes > $this->maximumAggregateBytes - $this->bufferedByteCount
        ) {
            return false;
        }

        $expiresAt = $now > PHP_INT_MAX - $this->lifetimeNanoseconds
            ? PHP_INT_MAX
            : $now + $this->lifetimeNanoseconds;
        $this->assemblies[$fragment->splitId] = [
            'fragmentCount' => $fragment->fragmentCount,
            'parts' => [$fragment->fragmentIndex => $fragment->payload],
            'bytes' => $payloadBytes,
            'expiresAtNanoseconds' => $expiresAt,
        ];
        ++$this->bufferedFragmentCount;
        $this->bufferedByteCount += $payloadBytes;

        return true;
    }

    private function completeIfReady(int $splitId): ?string
    {
        $assembly = $this->assemblies[$splitId];
        if (\count($assembly['parts']) !== $assembly['fragmentCount']) {
            return null;
        }

        ksort($assembly['parts'], SORT_NUMERIC);
        $payload = implode('', $assembly['parts']);
        $this->remove($splitId);

        return $payload;
    }

    private function expireAt(int $now): int
    {
        $expired = 0;
        foreach ($this->assemblies as $splitId => $assembly) {
            if ($assembly['expiresAtNanoseconds'] <= $now) {
                $this->remove($splitId);
                ++$expired;
            }
        }

        return $expired;
    }

    private function nowNanoseconds(): int
    {
        $now = $this->clock->nowNanoseconds();
        if ($now < 0) {
            throw new RuntimeException('Monotonic clock cannot return a negative value.');
        }

        return $now;
    }
}
