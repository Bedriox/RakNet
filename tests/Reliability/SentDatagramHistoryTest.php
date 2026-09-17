<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Reliability;

use Bedriox\RakNet\Reliability\SentDatagram;
use Bedriox\RakNet\Reliability\SentDatagramHistory;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class SentDatagramHistoryTest extends TestCase
{
    public function testTakeIsIdempotentAndReleasesReferences(): void
    {
        $history = new SentDatagramHistory(2, 3);
        $datagram = new SentDatagram(1, 0, [10, 11], false);
        $history->add($datagram);

        self::assertSame($datagram, $history->take(1));
        self::assertNull($history->take(1));
        self::assertSame(0, $history->count());
        self::assertSame(0, $history->referenceCount());
    }

    public function testCountLimitIsHardAndAtomic(): void
    {
        $history = new SentDatagramHistory(1, 2);
        $history->add(new SentDatagram(1, 0, [10], false));

        try {
            $history->add(new SentDatagram(2, 0, [11], false));
            self::fail('Expected history capacity failure.');
        } catch (OverflowException) {
            self::assertSame(1, $history->count());
            self::assertSame(1, $history->referenceCount());
        }
    }

    public function testReferenceLimitIsHardAndAtomic(): void
    {
        $history = new SentDatagramHistory(2, 2);
        $history->add(new SentDatagram(1, 0, [10, 11], false));

        $this->expectException(OverflowException::class);
        $history->add(new SentDatagram(2, 0, [12], false));
    }

    public function testRemovingFrameReferencesDropsEmptyDatagrams(): void
    {
        $history = new SentDatagramHistory(2, 4);
        $history->add(new SentDatagram(1, 0, [10, 11], false));
        $history->add(new SentDatagram(2, 0, [10], true));
        $history->removeReliableIndex(10);

        self::assertSame(1, $history->count());
        self::assertSame(1, $history->referenceCount());
    }
}
