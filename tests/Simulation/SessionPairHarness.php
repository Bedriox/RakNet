<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Simulation;

use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Connected\ConnectedSession;
use Bedriox\RakNet\Tests\MutableClock;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class SessionPairHarness
{
    public const string LEFT_TO_RIGHT = 'left-to-right';
    public const string RIGHT_TO_LEFT = 'right-to-left';

    /**
     * @var list<array{
     *     direction: string,
     *     payload: string,
     *     deliverAtNanoseconds: int,
     *     insertionOrder: int
     * }>
     */
    private array $scheduled = [];

    /** @var list<ConnectedPayloadEvent> */
    private array $leftPayloads = [];

    /** @var list<ConnectedPayloadEvent> */
    private array $rightPayloads = [];

    /** @var list<int> */
    private array $leftExpiredReliableIndices = [];

    /** @var list<int> */
    private array $rightExpiredReliableIndices = [];

    /**
     * @var list<array{
     *     direction: string,
     *     ordinal: int,
     *     payload: string,
     *     dropped: bool,
     *     duplicate: bool
     * }>
     */
    private array $transmissionLog = [];

    /** @var Closure(string, int, string): bool|null */
    private readonly ?Closure $dropRule;

    private int $nextTransmissionOrdinal = 0;
    private int $nextInsertionOrder = 0;

    public function __construct(
        private readonly ConnectedSession $left,
        private readonly ConnectedSession $right,
        private readonly MutableClock $clock,
        int $seed,
        private readonly ImpairmentProfile $leftToRight = new ImpairmentProfile(),
        private readonly ImpairmentProfile $rightToLeft = new ImpairmentProfile(),
        ?Closure $dropRule = null,
        private readonly int $maximumScheduledDatagrams = 100_000,
        private readonly int $maximumTransmissionLogEntries = 200_000,
    ) {
        if ($this->maximumScheduledDatagrams < 1 || $this->maximumTransmissionLogEntries < 1) {
            throw new InvalidArgumentException('Simulation queue and log limits must be positive.');
        }

        $this->random = new SeededRandom($seed);
        $this->dropRule = $dropRule;
    }

    private readonly SeededRandom $random;

    public function left(): ConnectedSession
    {
        return $this->left;
    }

    public function right(): ConnectedSession
    {
        return $this->right;
    }

    public function runFor(int $milliseconds, int $stepMilliseconds = 10): void
    {
        if ($milliseconds < 0 || $stepMilliseconds < 1) {
            throw new InvalidArgumentException('Simulation duration must be nonnegative and step must be positive.');
        }

        $remaining = $milliseconds;
        while ($remaining > 0) {
            $step = min($remaining, $stepMilliseconds);
            $this->step($step);
            $remaining -= $step;
        }

        if ($milliseconds === 0) {
            $this->step(0);
        }
    }

    /** @param Closure(self): bool $condition */
    public function runUntil(Closure $condition, int $maximumMilliseconds, int $stepMilliseconds = 10): bool
    {
        if ($maximumMilliseconds < 0 || $stepMilliseconds < 1) {
            throw new InvalidArgumentException('Simulation deadline must be nonnegative and step must be positive.');
        }

        if ($condition($this)) {
            return true;
        }

        $elapsed = 0;
        while ($elapsed < $maximumMilliseconds) {
            $step = min($stepMilliseconds, $maximumMilliseconds - $elapsed);
            $this->step($step);
            $elapsed += $step;
            if ($condition($this)) {
                return true;
            }
        }

        return false;
    }

    public function step(int $advanceMilliseconds = 10): void
    {
        if ($advanceMilliseconds < 0) {
            throw new InvalidArgumentException('Simulation clock advance cannot be negative.');
        }

        $this->left->tick();
        $this->right->tick();
        $this->harvestEffects();
        $this->deliverDueDatagrams();
        $this->harvestEffects();
        $this->clock->advanceMilliseconds($advanceMilliseconds);
    }

    /** @return list<ConnectedPayloadEvent> */
    public function leftPayloads(): array
    {
        return $this->leftPayloads;
    }

    /** @return list<ConnectedPayloadEvent> */
    public function rightPayloads(): array
    {
        return $this->rightPayloads;
    }

    /** @return list<int> */
    public function leftExpiredReliableIndices(): array
    {
        return $this->leftExpiredReliableIndices;
    }

    /** @return list<int> */
    public function rightExpiredReliableIndices(): array
    {
        return $this->rightExpiredReliableIndices;
    }

    /**
     * @return list<array{
     *     direction: string,
     *     ordinal: int,
     *     payload: string,
     *     dropped: bool,
     *     duplicate: bool
     * }>
     */
    public function transmissionLog(): array
    {
        return $this->transmissionLog;
    }

    public function scheduledDatagramCount(): int
    {
        return \count($this->scheduled);
    }

    private function harvestEffects(): void
    {
        $leftEffects = $this->left->drainEffects();
        foreach ($leftEffects->payloads as $payload) {
            $this->leftPayloads[] = $payload;
        }
        foreach ($leftEffects->expiredReliableIndices as $reliableIndex) {
            $this->leftExpiredReliableIndices[] = $reliableIndex;
        }
        foreach ($leftEffects->outboundDatagrams as $datagram) {
            $this->transmit(self::LEFT_TO_RIGHT, $datagram, $this->leftToRight);
        }

        $rightEffects = $this->right->drainEffects();
        foreach ($rightEffects->payloads as $payload) {
            $this->rightPayloads[] = $payload;
        }
        foreach ($rightEffects->expiredReliableIndices as $reliableIndex) {
            $this->rightExpiredReliableIndices[] = $reliableIndex;
        }
        foreach ($rightEffects->outboundDatagrams as $datagram) {
            $this->transmit(self::RIGHT_TO_LEFT, $datagram, $this->rightToLeft);
        }
    }

    private function transmit(string $direction, string $payload, ImpairmentProfile $profile): void
    {
        $ordinal = $this->nextTransmissionOrdinal++;
        $dropped = ($this->dropRule !== null && ($this->dropRule)($direction, $ordinal, $payload))
            || $this->random->chance($profile->lossPercent);
        $this->recordTransmission($direction, $ordinal, $payload, $dropped, false);
        if ($dropped) {
            return;
        }

        $this->schedule($direction, $payload, $profile);
        if ($this->random->chance($profile->duplicationPercent)) {
            $this->recordTransmission($direction, $ordinal, $payload, false, true);
            $this->schedule($direction, $payload, $profile);
        }
    }

    private function schedule(string $direction, string $payload, ImpairmentProfile $profile): void
    {
        if (\count($this->scheduled) >= $this->maximumScheduledDatagrams) {
            throw new RuntimeException('Simulation datagram queue limit exceeded.');
        }

        $delay = $this->random->between(
            $profile->minimumDelayMilliseconds,
            $profile->maximumDelayMilliseconds,
        );
        $this->scheduled[] = [
            'direction' => $direction,
            'payload' => $payload,
            'deliverAtNanoseconds' => $this->clock->nowNanoseconds() + $delay * 1_000_000,
            'insertionOrder' => $this->nextInsertionOrder++,
        ];
    }

    private function deliverDueDatagrams(): void
    {
        usort(
            $this->scheduled,
            static fn(array $left, array $right): int => [
                $left['deliverAtNanoseconds'],
                $left['insertionOrder'],
            ] <=> [
                $right['deliverAtNanoseconds'],
                $right['insertionOrder'],
            ],
        );

        $now = $this->clock->nowNanoseconds();
        $remaining = [];
        foreach ($this->scheduled as $scheduled) {
            if ($scheduled['deliverAtNanoseconds'] > $now) {
                $remaining[] = $scheduled;
                continue;
            }

            $target = $scheduled['direction'] === self::LEFT_TO_RIGHT ? $this->right : $this->left;
            $target->receiveBytes($scheduled['payload']);
        }
        $this->scheduled = $remaining;
    }

    private function recordTransmission(
        string $direction,
        int $ordinal,
        string $payload,
        bool $dropped,
        bool $duplicate,
    ): void {
        if (\count($this->transmissionLog) >= $this->maximumTransmissionLogEntries) {
            throw new RuntimeException('Simulation transmission log limit exceeded.');
        }

        $this->transmissionLog[] = [
            'direction' => $direction,
            'ordinal' => $ordinal,
            'payload' => $payload,
            'dropped' => $dropped,
            'duplicate' => $duplicate,
        ];
    }
}
