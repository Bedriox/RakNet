<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\OfflineDatagramResult;
use Bedriox\RakNet\PendingHandshake;
use Bedriox\RakNet\SessionInfo;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NegotiationValueTest extends TestCase
{
    /** @return iterable<string, array{callable(): void}> */
    public static function invalidValues(): iterable
    {
        yield 'pending MTU' => [static function (): void {
            new PendingHandshake(575, 11, 1);
        }];
        yield 'pending protocol' => [static function (): void {
            new PendingHandshake(576, 256, 1);
        }];
        yield 'pending expiry' => [static function (): void {
            new PendingHandshake(576, 11, -1);
        }];
        yield 'session address' => [static function (): void {
            new SessionInfo('invalid', 1, 1, 576, 11);
        }];
        yield 'session port' => [static function (): void {
            new SessionInfo('127.0.0.1', 0, 1, 576, 11);
        }];
        yield 'session MTU' => [static function (): void {
            new SessionInfo('127.0.0.1', 1, 1, 1_493, 11);
        }];
        yield 'session protocol' => [static function (): void {
            new SessionInfo('127.0.0.1', 1, 1, 576, -1);
        }];
        yield 'empty response' => [static function (): void {
            new OfflineDatagramResult('');
        }];
        yield 'oversized response' => [static function (): void {
            new OfflineDatagramResult(str_repeat('x', 1_493));
        }];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function testSessionAcceptsHighBitClientGuidAsSignedPhpInteger(): void
    {
        self::assertSame(-1, new SessionInfo('127.0.0.1', 1, -1, 576, 11)->clientGuid);
    }
}
