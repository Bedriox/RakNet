<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Security;

use Bedriox\RakNet\Security\AdmissionDecision;
use Bedriox\RakNet\Security\TransportAbuseGuard;
use Bedriox\RakNet\Security\TransportSecurityPolicy;
use Bedriox\RakNet\Tests\MutableClock;
use PHPUnit\Framework\TestCase;

final class TransportAbuseGuardTest extends TestCase
{
    public function testLegitimateBurstRefillsWithoutBlocking(): void
    {
        $clock = new MutableClock();
        $guard = new TransportAbuseGuard(new TransportSecurityPolicy(
            unauthenticatedDatagramsPerSecond: 10,
            unauthenticatedDatagramBurst: 3,
            unauthenticatedBytesPerSecond: 1_000,
            unauthenticatedByteBurst: 300,
        ), $clock);

        for ($port = 10_000; $port < 10_003; ++$port) {
            self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', $port, 100, false, false));
        }
        $clock->advanceMilliseconds(1_000);
        self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', 10_003, 100, false, false));
        self::assertSame(0, $guard->snapshot()->activeBlocks);
    }

    public function testUnauthenticatedFloodBlocksThenExpires(): void
    {
        $clock = new MutableClock();
        $guard = new TransportAbuseGuard(new TransportSecurityPolicy(
            unauthenticatedDatagramsPerSecond: 1,
            unauthenticatedDatagramBurst: 1,
            baseBlockSeconds: 10,
        ), $clock);

        self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', 10_000, 1, false, false));
        self::assertSame(AdmissionDecision::BLOCK, $guard->admit('127.0.0.1', 10_001, 1, false, false));
        self::assertSame(AdmissionDecision::BLOCK, $guard->admit('127.0.0.1', 10_002, 1, false, false));
        $clock->advanceMilliseconds(10_001);
        self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', 10_003, 1, false, false));
    }

    public function testConnectedEndpointsSharingAddressAreIsolated(): void
    {
        $clock = new MutableClock();
        $guard = new TransportAbuseGuard(new TransportSecurityPolicy(
            connectedDatagramsPerSecond: 1,
            connectedDatagramBurst: 1,
        ), $clock);

        self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', 10_000, 1, true, false));
        self::assertSame(AdmissionDecision::DROP, $guard->admit('127.0.0.1', 10_000, 1, true, false));
        self::assertSame(AdmissionDecision::ALLOW, $guard->admit('127.0.0.1', 10_001, 1, true, false));
        self::assertSame(0, $guard->snapshot()->activeBlocks);
    }

    public function testMalformedThresholdCreatesTemporaryAddressBlock(): void
    {
        $clock = new MutableClock();
        $guard = new TransportAbuseGuard(new TransportSecurityPolicy(malformedThreshold: 3), $clock);

        self::assertFalse($guard->malformed('127.0.0.1'));
        self::assertFalse($guard->malformed('127.0.0.1'));
        self::assertTrue($guard->malformed('127.0.0.1'));
        self::assertSame(AdmissionDecision::BLOCK, $guard->admit('127.0.0.1', 10_000, 1, false, false));
    }

    public function testTrackedStateIsBounded(): void
    {
        $clock = new MutableClock();
        $guard = new TransportAbuseGuard(new TransportSecurityPolicy(
            maximumTrackedAddresses: 2,
            maximumTrackedEndpoints: 2,
        ), $clock);

        foreach (['127.0.0.1', '127.0.0.2', '127.0.0.3'] as $address) {
            self::assertSame(AdmissionDecision::ALLOW, $guard->admit($address, 10_000, 1, false, false));
            $clock->advanceMilliseconds(1);
        }
        self::assertSame(2, $guard->snapshot()->trackedAddresses);
    }
}
