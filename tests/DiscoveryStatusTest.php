<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\DiscoveryStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DiscoveryStatusTest extends TestCase
{
    public function testOpaquePayloadAndResponsePolicyAreRetainedExactly(): void
    {
        $payload = "application;owned\nopaque status";
        $status = new DiscoveryStatus($payload, false);

        self::assertSame($payload, $status->payload);
        self::assertFalse($status->acceptingConnections);
    }

    public function testConnectionsAreAcceptedByDefault(): void
    {
        self::assertTrue(new DiscoveryStatus('available')->acceptingConnections);
    }

    public function testExactMaximumBytePayloadIsAccepted(): void
    {
        $status = new DiscoveryStatus(str_repeat('é', DiscoveryStatus::MAXIMUM_PAYLOAD_BYTES / 2));

        self::assertSame(DiscoveryStatus::MAXIMUM_PAYLOAD_BYTES, \strlen($status->payload));
    }

    public function testEmptyPayloadIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and');
        new DiscoveryStatus('');
    }

    public function testOversizedPayloadIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage((string) DiscoveryStatus::MAXIMUM_PAYLOAD_BYTES);
        new DiscoveryStatus(str_repeat('x', DiscoveryStatus::MAXIMUM_PAYLOAD_BYTES + 1));
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UTF-8');
        new DiscoveryStatus("broken\xc3\x28");
    }
}
