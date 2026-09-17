<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Simulation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulationPrimitiveTest extends TestCase
{
    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValues(): iterable
    {
        yield 'negative loss' => [static fn(): ImpairmentProfile => new ImpairmentProfile(lossPercent: -1)];
        yield 'loss over 100' => [static fn(): ImpairmentProfile => new ImpairmentProfile(lossPercent: 101)];
        yield 'negative duplication' => [static fn(): ImpairmentProfile => new ImpairmentProfile(duplicationPercent: -1)];
        yield 'duplication over 100' => [static fn(): ImpairmentProfile => new ImpairmentProfile(duplicationPercent: 101)];
        yield 'negative delay' => [static fn(): ImpairmentProfile => new ImpairmentProfile(minimumDelayMilliseconds: -1)];
        yield 'reversed delay' => [static fn(): ImpairmentProfile => new ImpairmentProfile(0, 0, 2, 1)];
        yield 'negative seed' => [static fn(): SeededRandom => new SeededRandom(-1)];
        yield 'oversized seed' => [static fn(): SeededRandom => new SeededRandom(0x8000_0000)];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidConfigurationIsRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testSeedProducesRepeatableChoices(): void
    {
        $first = new SeededRandom(12_345);
        $second = new SeededRandom(12_345);

        for ($index = 0; $index < 100; ++$index) {
            self::assertSame($first->chance(37), $second->chance(37));
            self::assertSame($first->between(2, 19), $second->between(2, 19));
        }
    }

    public function testChanceAndFixedRangeBoundariesDoNotConsumeSurprisingValues(): void
    {
        $random = new SeededRandom(7);

        self::assertFalse($random->chance(0));
        self::assertTrue($random->chance(100));
        self::assertSame(4, $random->between(4, 4));
    }
}
