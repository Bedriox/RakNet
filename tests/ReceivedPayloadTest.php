<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceivedPayloadTest extends TestCase
{
    /** @return iterable<string, array{callable(): ReceivedPayload}> */
    public static function invalidValues(): iterable
    {
        yield 'invalid address' => [static fn(): ReceivedPayload => new ReceivedPayload('invalid', 1, 'x', Reliability::Reliable, null)];
        yield 'invalid port' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 0, 'x', Reliability::Reliable, null)];
        yield 'empty payload' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 1, '', Reliability::Reliable, null)];
        yield 'unsupported reliability' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 1, 'x', Reliability::UnreliableSequenced, null)];
        yield 'ordered missing channel' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 1, 'x', Reliability::ReliableOrdered, null)];
        yield 'unordered with channel' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 1, 'x', Reliability::Reliable, 0)];
        yield 'channel out of range' => [static fn(): ReceivedPayload => new ReceivedPayload('127.0.0.1', 1, 'x', Reliability::ReliableOrdered, 32)];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }
}
