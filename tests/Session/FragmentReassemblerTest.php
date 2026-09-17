<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Session;

use Bedriox\RakNet\Session\Fragment;
use Bedriox\RakNet\Session\FragmentAcceptanceStatus;
use Bedriox\RakNet\Session\FragmentReassembler;
use Bedriox\RakNet\Tests\MutableClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FragmentReassemblerTest extends TestCase
{
    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValues(): iterable
    {
        yield 'negative split ID' => [static fn(): Fragment => new Fragment(-1, 2, 0, 'a')];
        yield 'oversized split ID' => [static fn(): Fragment => new Fragment(0x1_0000, 2, 0, 'a')];
        yield 'zero fragment count' => [static fn(): Fragment => new Fragment(1, 0, 0, 'a')];
        yield 'single fragment' => [static fn(): Fragment => new Fragment(1, 1, 0, 'a')];
        yield 'oversized fragment count' => [static fn(): Fragment => new Fragment(1, 0x1_0000_0000, 0, 'a')];
        yield 'negative fragment index' => [static fn(): Fragment => new Fragment(1, 2, -1, 'a')];
        yield 'index equal to count' => [static fn(): Fragment => new Fragment(1, 2, 2, 'a')];
        yield 'empty fragment payload' => [static fn(): Fragment => new Fragment(1, 2, 0, '')];
        yield 'zero lifetime' => [static fn(): FragmentReassembler => self::reassembler(lifetimeNanoseconds: 0)];
        yield 'zero active limit' => [static fn(): FragmentReassembler => self::reassembler(maximumActiveAssemblies: 0)];
        yield 'zero fragment limit' => [static fn(): FragmentReassembler => self::reassembler(maximumFragmentsPerAssembly: 0)];
        yield 'zero assembly bytes' => [static fn(): FragmentReassembler => self::reassembler(maximumBytesPerAssembly: 0)];
        yield 'zero aggregate bytes' => [static fn(): FragmentReassembler => self::reassembler(maximumAggregateBytes: 0)];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidInputsAndLimitsAreRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testOutOfOrderFragmentsCompleteInIndexOrder(): void
    {
        $reassembler = self::reassembler();

        self::assertNull($reassembler->accept(new Fragment(7, 3, 2, 'third')));
        self::assertNull($reassembler->accept(new Fragment(7, 3, 0, 'first')));
        self::assertSame(2, $reassembler->bufferedFragmentCount());
        self::assertSame('first-second-third', $reassembler->accept(new Fragment(7, 3, 1, '-second-')));
        self::assertSame(0, $reassembler->activeAssemblyCount());
        self::assertSame(0, $reassembler->bufferedFragmentCount());
        self::assertSame(0, $reassembler->bufferedByteCount());
    }

    public function testExactDuplicateDoesNotChangeAccountingOrRefreshDeadline(): void
    {
        $clock = new MutableClock();
        $reassembler = self::reassembler($clock, lifetimeNanoseconds: 10_000_000);
        $fragment = new Fragment(3, 2, 0, 'abc');
        self::assertNull($reassembler->accept($fragment));

        $clock->advanceMilliseconds(9);
        self::assertNull($reassembler->accept($fragment));
        self::assertSame(1, $reassembler->bufferedFragmentCount());
        self::assertSame(3, $reassembler->bufferedByteCount());

        $clock->advanceMilliseconds(1);
        self::assertSame(1, $reassembler->expire());
        self::assertSame(0, $reassembler->activeAssemblyCount());
    }

    public function testPayloadConflictClearsOnlyAffectedAssembly(): void
    {
        $reassembler = self::reassembler();
        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'one')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'safe')));

        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'conflict')));
        self::assertSame(1, $reassembler->activeAssemblyCount());
        self::assertSame(1, $reassembler->bufferedFragmentCount());
        self::assertSame('safe-end', $reassembler->accept(new Fragment(2, 2, 1, '-end')));
    }

    public function testMetadataConflictClearsOnlyAffectedAssembly(): void
    {
        $reassembler = self::reassembler();
        self::assertNull($reassembler->accept(new Fragment(1, 3, 0, 'bad')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'good')));

        self::assertNull($reassembler->accept(new Fragment(1, 2, 1, 'conflict')));
        self::assertSame(1, $reassembler->activeAssemblyCount());
        self::assertSame('good-result', $reassembler->accept(new Fragment(2, 2, 1, '-result')));
    }

    public function testSparseAndOversizedAssembliesAreRejected(): void
    {
        $reassembler = self::reassembler(
            maximumFragmentsPerAssembly: 4,
            maximumBytesPerAssembly: 5,
            maximumAggregateBytes: 10,
        );

        self::assertNull($reassembler->accept(new Fragment(1, 5, 4, 'x')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, '123456')));
        self::assertSame(0, $reassembler->activeAssemblyCount());

        self::assertNull($reassembler->accept(new Fragment(3, 2, 1, '1234')));
        self::assertNull($reassembler->accept(new Fragment(3, 2, 0, 'zz')));
        self::assertSame(0, $reassembler->activeAssemblyCount());
        self::assertSame(0, $reassembler->bufferedByteCount());
    }

    public function testAggregateByteLimitClearsOnlyAssemblyThatCrossesIt(): void
    {
        $reassembler = self::reassembler(maximumBytesPerAssembly: 10, maximumAggregateBytes: 6);
        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'aaa')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'bb')));

        self::assertNull($reassembler->accept(new Fragment(1, 2, 1, 'cc')));
        self::assertSame(1, $reassembler->activeAssemblyCount());
        self::assertSame(2, $reassembler->bufferedByteCount());
        self::assertSame('bb!', $reassembler->accept(new Fragment(2, 2, 1, '!')));
    }

    public function testActiveAssemblyLimitSurvivesChurnAndExplicitRemoval(): void
    {
        $reassembler = self::reassembler(maximumActiveAssemblies: 2);
        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'a')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'b')));
        self::assertNull($reassembler->accept(new Fragment(3, 2, 0, 'c')));
        self::assertSame(2, $reassembler->activeAssemblyCount());

        self::assertTrue($reassembler->remove(1));
        self::assertFalse($reassembler->remove(1));
        self::assertNull($reassembler->accept(new Fragment(3, 2, 0, 'c')));
        self::assertSame(2, $reassembler->activeAssemblyCount());
        self::assertSame(2, $reassembler->bufferedByteCount());
    }

    public function testDetailedAcceptanceDistinguishesCapacityFromPartialState(): void
    {
        $reassembler = self::reassembler(maximumActiveAssemblies: 1);
        self::assertSame(
            FragmentAcceptanceStatus::Partial,
            $reassembler->acceptDetailed(new Fragment(1, 2, 0, 'a'))->status,
        );
        self::assertSame(
            FragmentAcceptanceStatus::RejectedCapacity,
            $reassembler->acceptDetailed(new Fragment(2, 2, 0, 'b'))->status,
        );
    }

    public function testExpirationReleasesAllAccountingAtBoundary(): void
    {
        $clock = new MutableClock();
        $reassembler = self::reassembler($clock, lifetimeNanoseconds: 5_000_000);
        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'abc')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'de')));

        $clock->advanceMilliseconds(5);
        self::assertSame(2, $reassembler->expire());
        self::assertSame(0, $reassembler->activeAssemblyCount());
        self::assertSame(0, $reassembler->bufferedFragmentCount());
        self::assertSame(0, $reassembler->bufferedByteCount());
    }

    public function testClearReleasesDisconnectState(): void
    {
        $reassembler = self::reassembler();
        self::assertNull($reassembler->accept(new Fragment(1, 2, 0, 'abc')));
        self::assertNull($reassembler->accept(new Fragment(2, 2, 0, 'de')));

        $reassembler->clear();
        self::assertSame(0, $reassembler->activeAssemblyCount());
        self::assertSame(0, $reassembler->bufferedFragmentCount());
        self::assertSame(0, $reassembler->bufferedByteCount());
    }

    public function testRemoveRejectsInvalidSplitId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::reassembler()->remove(Fragment::MAXIMUM_SPLIT_ID + 1);
    }

    private static function reassembler(
        ?MutableClock $clock = null,
        int $lifetimeNanoseconds = 1_000_000_000,
        int $maximumActiveAssemblies = 8,
        int $maximumFragmentsPerAssembly = 8,
        int $maximumBytesPerAssembly = 128,
        int $maximumAggregateBytes = 512,
    ): FragmentReassembler {
        return new FragmentReassembler(
            $clock ?? new MutableClock(),
            $lifetimeNanoseconds,
            $maximumActiveAssemblies,
            $maximumFragmentsPerAssembly,
            $maximumBytesPerAssembly,
            $maximumAggregateBytes,
        );
    }
}
