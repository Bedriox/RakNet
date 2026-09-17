<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

use InvalidArgumentException;

final class OrderedDeliveryBuffer
{
    private const int SEQUENCE_MODULUS = 0x100_0000;
    private const int HALF_SEQUENCE_RANGE = 0x80_0000;

    /** @var array<int, int> */
    private array $expectedIndices;

    /** @var array<int, array<int, string>> */
    private array $buffers;

    /** @var array<int, int> */
    private array $bufferedBytes;

    public function __construct(
        private readonly int $maximumBufferedPayloadsPerChannel,
        private readonly int $maximumBufferedBytesPerChannel,
    ) {
        if ($this->maximumBufferedPayloadsPerChannel < 1) {
            throw new InvalidArgumentException('Maximum buffered payloads per channel must be positive.');
        }
        if ($this->maximumBufferedBytesPerChannel < 1) {
            throw new InvalidArgumentException('Maximum buffered bytes per channel must be positive.');
        }

        $this->expectedIndices = array_fill(0, OrderedPayload::CHANNEL_COUNT, 0);
        $this->buffers = array_fill(0, OrderedPayload::CHANNEL_COUNT, []);
        $this->bufferedBytes = array_fill(0, OrderedPayload::CHANNEL_COUNT, 0);
    }

    /** @return list<string> */
    public function accept(OrderedPayload $orderedPayload): array
    {
        return $this->acceptDetailed($orderedPayload)->payloads;
    }

    public function acceptDetailed(OrderedPayload $orderedPayload): OrderedDeliveryResult
    {
        $channel = $orderedPayload->channel;
        $expected = $this->expectedIndices[$channel];
        $distance = ($orderedPayload->orderIndex - $expected) & OrderedPayload::MAXIMUM_ORDER_INDEX;

        if ($distance === 0) {
            return new OrderedDeliveryResult(
                OrderedDeliveryStatus::Delivered,
                $this->deliverContiguous($orderedPayload),
            );
        }

        if ($distance >= self::HALF_SEQUENCE_RANGE) {
            return new OrderedDeliveryResult(OrderedDeliveryStatus::Stale);
        }

        $existingPayload = $this->buffers[$channel][$orderedPayload->orderIndex] ?? null;
        if ($existingPayload !== null) {
            if ($existingPayload !== $orderedPayload->payload) {
                $this->clearBufferedChannel($channel);

                return new OrderedDeliveryResult(OrderedDeliveryStatus::RejectedConflict);
            }

            return new OrderedDeliveryResult(OrderedDeliveryStatus::Duplicate);
        }

        $payloadBytes = \strlen($orderedPayload->payload);
        if (
            \count($this->buffers[$channel]) >= $this->maximumBufferedPayloadsPerChannel
            || $payloadBytes > $this->maximumBufferedBytesPerChannel - $this->bufferedBytes[$channel]
        ) {
            return new OrderedDeliveryResult(OrderedDeliveryStatus::RejectedCapacity);
        }

        $this->buffers[$channel][$orderedPayload->orderIndex] = $orderedPayload->payload;
        $this->bufferedBytes[$channel] += $payloadBytes;

        return new OrderedDeliveryResult(OrderedDeliveryStatus::Buffered);
    }

    public function expectedIndex(int $channel): int
    {
        $this->validateChannel($channel);

        return $this->expectedIndices[$channel];
    }

    public function bufferedPayloadCount(int $channel): int
    {
        $this->validateChannel($channel);

        return \count($this->buffers[$channel]);
    }

    public function bufferedByteCount(int $channel): int
    {
        $this->validateChannel($channel);

        return $this->bufferedBytes[$channel];
    }

    public function resetChannel(int $channel, int $nextExpectedIndex = 0): void
    {
        $this->validateChannel($channel);
        $this->validateOrderIndex($nextExpectedIndex);
        $this->clearBufferedChannel($channel);
        $this->expectedIndices[$channel] = $nextExpectedIndex;
    }

    public function clear(): void
    {
        $this->expectedIndices = array_fill(0, OrderedPayload::CHANNEL_COUNT, 0);
        $this->buffers = array_fill(0, OrderedPayload::CHANNEL_COUNT, []);
        $this->bufferedBytes = array_fill(0, OrderedPayload::CHANNEL_COUNT, 0);
    }

    /** @return list<string> */
    private function deliverContiguous(OrderedPayload $orderedPayload): array
    {
        $channel = $orderedPayload->channel;
        $delivered = [$orderedPayload->payload];
        $next = self::increment($orderedPayload->orderIndex);

        while (isset($this->buffers[$channel][$next])) {
            $payload = $this->buffers[$channel][$next];
            unset($this->buffers[$channel][$next]);
            $this->bufferedBytes[$channel] -= \strlen($payload);
            $delivered[] = $payload;
            $next = self::increment($next);
        }

        $this->expectedIndices[$channel] = $next;

        return $delivered;
    }

    private function clearBufferedChannel(int $channel): void
    {
        $this->buffers[$channel] = [];
        $this->bufferedBytes[$channel] = 0;
    }

    private function validateChannel(int $channel): void
    {
        if ($channel < 0 || $channel >= OrderedPayload::CHANNEL_COUNT) {
            throw new InvalidArgumentException('Ordering channel must be between 0 and 31.');
        }
    }

    private function validateOrderIndex(int $orderIndex): void
    {
        if ($orderIndex < 0 || $orderIndex > OrderedPayload::MAXIMUM_ORDER_INDEX) {
            throw new InvalidArgumentException('Order index must fit in an unsigned 24-bit integer.');
        }
    }

    private static function increment(int $orderIndex): int
    {
        return ($orderIndex + 1) % self::SEQUENCE_MODULUS;
    }
}
