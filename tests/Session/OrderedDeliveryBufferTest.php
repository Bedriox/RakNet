<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Session;

use Bedriox\RakNet\Session\OrderedDeliveryBuffer;
use Bedriox\RakNet\Session\OrderedDeliveryStatus;
use Bedriox\RakNet\Session\OrderedPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderedDeliveryBufferTest extends TestCase
{
    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValues(): iterable
    {
        yield 'negative channel' => [static fn(): OrderedPayload => new OrderedPayload(-1, 0, 'a')];
        yield 'channel 32' => [static fn(): OrderedPayload => new OrderedPayload(32, 0, 'a')];
        yield 'negative index' => [static fn(): OrderedPayload => new OrderedPayload(0, -1, 'a')];
        yield 'oversized index' => [static fn(): OrderedPayload => new OrderedPayload(0, 0x100_0000, 'a')];
        yield 'empty payload' => [static fn(): OrderedPayload => new OrderedPayload(0, 0, '')];
        yield 'zero count limit' => [static fn(): OrderedDeliveryBuffer => new OrderedDeliveryBuffer(0, 1)];
        yield 'zero byte limit' => [static fn(): OrderedDeliveryBuffer => new OrderedDeliveryBuffer(1, 0)];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidInputsAndLimitsAreRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testOutOfOrderPayloadsAreDeliveredContiguously(): void
    {
        $buffer = new OrderedDeliveryBuffer(8, 128);
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 2, 'two')));
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 1, 'one')));
        self::assertSame(['zero', 'one', 'two'], $buffer->accept(new OrderedPayload(0, 0, 'zero')));
        self::assertSame(3, $buffer->expectedIndex(0));
        self::assertSame(0, $buffer->bufferedPayloadCount(0));
        self::assertSame(0, $buffer->bufferedByteCount(0));
    }

    public function testExactDuplicateDoesNotChangeAccounting(): void
    {
        $buffer = new OrderedDeliveryBuffer(2, 4);
        $payload = new OrderedPayload(0, 1, 'ab');
        self::assertSame([], $buffer->accept($payload));
        self::assertSame([], $buffer->accept($payload));
        self::assertSame(1, $buffer->bufferedPayloadCount(0));
        self::assertSame(2, $buffer->bufferedByteCount(0));
    }

    public function testConflictingDuplicateClearsOnlyAffectedChannel(): void
    {
        $buffer = new OrderedDeliveryBuffer(4, 32);
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 1, 'original')));
        self::assertSame([], $buffer->accept(new OrderedPayload(1, 1, 'safe')));

        self::assertSame([], $buffer->accept(new OrderedPayload(0, 1, 'conflict')));
        self::assertSame(0, $buffer->bufferedPayloadCount(0));
        self::assertSame(1, $buffer->bufferedPayloadCount(1));
        self::assertSame(['start', 'safe'], $buffer->accept(new OrderedPayload(1, 0, 'start')));
    }

    public function testCountAndByteLimitsAreIndependentPerChannel(): void
    {
        $buffer = new OrderedDeliveryBuffer(2, 3);
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 1, 'a')));
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 2, 'b')));
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 3, 'c')));
        self::assertSame(2, $buffer->bufferedPayloadCount(0));

        self::assertSame([], $buffer->accept(new OrderedPayload(1, 1, 'abc')));
        self::assertSame([], $buffer->accept(new OrderedPayload(1, 2, 'd')));
        self::assertSame(1, $buffer->bufferedPayloadCount(1));
        self::assertSame(3, $buffer->bufferedByteCount(1));
    }

    public function testDetailedAcceptanceReportsCapacityAndConflict(): void
    {
        $buffer = new OrderedDeliveryBuffer(1, 8);
        self::assertSame(
            OrderedDeliveryStatus::Buffered,
            $buffer->acceptDetailed(new OrderedPayload(0, 1, 'a'))->status,
        );
        self::assertSame(
            OrderedDeliveryStatus::RejectedCapacity,
            $buffer->acceptDetailed(new OrderedPayload(0, 2, 'b'))->status,
        );
        self::assertSame(
            OrderedDeliveryStatus::RejectedConflict,
            $buffer->acceptDetailed(new OrderedPayload(0, 1, 'different'))->status,
        );
    }

    public function testBlockedChannelDoesNotBlockAnotherChannel(): void
    {
        $buffer = new OrderedDeliveryBuffer(4, 32);
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 2, 'blocked')));
        self::assertSame(['free'], $buffer->accept(new OrderedPayload(31, 0, 'free')));
        self::assertSame(0, $buffer->expectedIndex(0));
        self::assertSame(1, $buffer->expectedIndex(31));
    }

    public function testAllThirtyTwoChannelsMaintainIndependentState(): void
    {
        $buffer = new OrderedDeliveryBuffer(2, 16);
        for ($channel = 0; $channel < OrderedPayload::CHANNEL_COUNT; ++$channel) {
            self::assertSame([], $buffer->accept(new OrderedPayload($channel, 1, "b$channel")));
        }
        for ($channel = 0; $channel < OrderedPayload::CHANNEL_COUNT; ++$channel) {
            self::assertSame(
                ["a$channel", "b$channel"],
                $buffer->accept(new OrderedPayload($channel, 0, "a$channel")),
            );
            self::assertSame(2, $buffer->expectedIndex($channel));
        }
    }

    public function testOrderIndexWrapsAcrossTwentyFourBitBoundary(): void
    {
        $buffer = new OrderedDeliveryBuffer(4, 32);
        $buffer->resetChannel(4, OrderedPayload::MAXIMUM_ORDER_INDEX);
        self::assertSame([], $buffer->accept(new OrderedPayload(4, 0, 'zero')));
        self::assertSame(
            ['maximum', 'zero'],
            $buffer->accept(new OrderedPayload(4, OrderedPayload::MAXIMUM_ORDER_INDEX, 'maximum')),
        );
        self::assertSame(1, $buffer->expectedIndex(4));
    }

    public function testStaleAndAmbiguousHalfRangeIndicesAreIgnored(): void
    {
        $buffer = new OrderedDeliveryBuffer(4, 32);
        self::assertSame(['zero'], $buffer->accept(new OrderedPayload(0, 0, 'zero')));
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 0, 'stale')));
        self::assertSame([], $buffer->accept(new OrderedPayload(0, 0x80_0001, 'ambiguous')));
        self::assertSame(0, $buffer->bufferedPayloadCount(0));
    }

    public function testResetAndClearReleaseDisconnectState(): void
    {
        $buffer = new OrderedDeliveryBuffer(4, 32);
        self::assertSame([], $buffer->accept(new OrderedPayload(1, 2, 'one')));
        self::assertSame([], $buffer->accept(new OrderedPayload(2, 2, 'two')));

        $buffer->resetChannel(1, 7);
        self::assertSame(0, $buffer->bufferedPayloadCount(1));
        self::assertSame(7, $buffer->expectedIndex(1));
        self::assertSame(1, $buffer->bufferedPayloadCount(2));

        $buffer->clear();
        for ($channel = 0; $channel < OrderedPayload::CHANNEL_COUNT; ++$channel) {
            self::assertSame(0, $buffer->expectedIndex($channel));
            self::assertSame(0, $buffer->bufferedPayloadCount($channel));
            self::assertSame(0, $buffer->bufferedByteCount($channel));
        }
    }

    public function testInvalidChannelAndResetIndexAreRejected(): void
    {
        $buffer = new OrderedDeliveryBuffer(1, 1);

        try {
            $buffer->bufferedPayloadCount(32);
            self::fail('Invalid channel was accepted.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $buffer->resetChannel(0, OrderedPayload::MAXIMUM_ORDER_INDEX + 1);
    }
}
