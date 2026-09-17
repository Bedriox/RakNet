<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests;

use Bedriox\RakNet\ConnectedHandshakeDiagnosticBatch;
use Bedriox\RakNet\ConnectedHandshakeDiagnosticEvent;
use Bedriox\RakNet\ConnectedHandshakeRejectionReason;
use Bedriox\RakNet\ConnectedHandshakeStage;
use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectedHandshakeDiagnosticTest extends TestCase
{
    public function testCarriesOnlyBoundedHandshakeMetadata(): void
    {
        $event = new ConnectedHandshakeDiagnosticEvent(
            '192.0.2.1',
            19_132,
            ConnectedHandshakeStage::AwaitingNewIncomingConnection,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            0x80,
            0x13,
            164,
            Reliability::ReliableOrdered,
            0,
        );
        $batch = new ConnectedHandshakeDiagnosticBatch([$event], 3);

        self::assertSame([$event], $batch->events);
        self::assertSame(3, $batch->droppedEventCount);
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidValues(): iterable
    {
        yield 'non IPv4 address' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '::1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::Timeout,
        )];
        yield 'invalid port' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            0,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::Timeout,
        )];
        yield 'invalid datagram identifier' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::MalformedDatagram,
            datagramId: 256,
        )];
        yield 'negative payload length' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::MalformedDatagram,
            payloadLength: -1,
        )];
        yield 'ordered reliability without channel' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            reliability: Reliability::ReliableOrdered,
        )];
        yield 'unordered reliability with channel' => [fn(): object => new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::InvalidEnvelope,
            reliability: Reliability::Reliable,
            orderingChannel: 0,
        )];
        yield 'non-list batch' => [fn(): object => new ConnectedHandshakeDiagnosticBatch([1 => self::event()], 0)];
        yield 'wrong batch member' => [fn(): object => new ConnectedHandshakeDiagnosticBatch([new \stdClass()], 0)];
        yield 'negative dropped count' => [fn(): object => new ConnectedHandshakeDiagnosticBatch([], -1)];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidMetadata(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    private static function event(): ConnectedHandshakeDiagnosticEvent
    {
        return new ConnectedHandshakeDiagnosticEvent(
            '127.0.0.1',
            19_132,
            ConnectedHandshakeStage::AwaitingConnectionRequest,
            ConnectedHandshakeRejectionReason::Timeout,
        );
    }
}
