<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Tests\Connected;

use Bedriox\RakNet\Connected\ConnectedControlSession;
use Bedriox\RakNet\Protocol\ConnectedPing;
use Bedriox\RakNet\Protocol\ConnectedPong;
use Bedriox\RakNet\Protocol\ConnectionRequest;
use Bedriox\RakNet\Protocol\ConnectionRequestAccepted;
use Bedriox\RakNet\Protocol\InternetAddress;
use Bedriox\RakNet\Protocol\NewIncomingConnection;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionInfo;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ConnectedControlSessionTest extends TestCase
{
    public function testRequestRetransmissionIsIdempotentAndNewIncomingOpensSession(): void
    {
        $control = $this->control();
        $request = new ConnectionRequest(55, 1234, false);

        $first = $control->receive($request->encode(), 2_000_000);
        $second = $control->receive($request->encode(), 3_000_000);
        self::assertCount(1, $first->outboundPayloads);
        self::assertCount(1, $second->outboundPayloads);
        self::assertSame($first->outboundPayloads[0]->payload, $second->outboundPayloads[0]->payload);
        self::assertSame(Reliability::ReliableOrdered, $first->outboundPayloads[0]->reliability);
        self::assertSame(1234, ConnectionRequestAccepted::decode($first->outboundPayloads[0]->payload)->requestTimestamp);

        $incoming = new NewIncomingConnection(
            new InternetAddress('127.0.0.1', 19132),
            array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0)),
            1234,
            2,
        );
        self::assertTrue($control->receive($incoming->encode(), 4_000_000)->becameReady);
        self::assertTrue($control->isReady());
        self::assertFalse($control->receive($incoming->encode(), 5_000_000)->becameReady);
        self::assertTrue($control->receive("\xfeapplication", 6_000_000)->deliverApplicationPayload);
    }

    public function testConnectedPingIsAnsweredDuringHandshake(): void
    {
        $effects = $this->control()->receive(new ConnectedPing(99)->encode(), 8_000_000);
        self::assertCount(1, $effects->outboundPayloads);
        self::assertSame(99, ConnectedPong::decode($effects->outboundPayloads[0]->payload)->pingTimestamp);
        self::assertSame(8, ConnectedPong::decode($effects->outboundPayloads[0]->payload)->pongTimestamp);
    }

    public function testReadySessionPingsAndExpiresOnlyWithoutInboundActivity(): void
    {
        $control = $this->readyControl();
        self::assertNull($control->heartbeatPayload(4_999_999_999));
        $ping = $control->heartbeatPayload(5_000_000_002);
        self::assertNotNull($ping);
        self::assertSame(Reliability::Unreliable, $ping->reliability);
        self::assertSame(5_000, ConnectedPing::decode($ping->payload)->timestamp);
        self::assertNull($control->heartbeatPayload(5_000_000_003));

        self::assertNull($control->tick(29_999_999_999));
        $control->receive(new ConnectedPong(5_000, 5_001)->encode(), 29_999_999_999);
        self::assertNull($control->tick(59_999_999_998));
        self::assertSame(SessionCloseReason::IdleTimeout, $control->tick(59_999_999_999));
        self::assertTrue($control->isClosed());
    }

    public function testQuietReadySessionCanRemainAliveThroughConnectedPings(): void
    {
        $control = $this->readyControl();
        foreach ([20_000_000_000, 40_000_000_000, 60_000_000_000] as $time) {
            self::assertNull($control->tick($time));
            self::assertCount(1, $control->receive(new ConnectedPing(42)->encode(), $time)->outboundPayloads);
        }
        self::assertNull($control->tick(80_000_000_000));
    }

    public function testDeadlineAndDisconnectCloseIdempotently(): void
    {
        $control = $this->control();
        self::assertNull($control->tick(9_999_999));
        self::assertSame(SessionCloseReason::HandshakeTimeout, $control->tick(10_000_000));
        self::assertTrue($control->isClosed());

        $connected = $this->readyControl();
        self::assertSame(
            SessionCloseReason::RemoteDisconnect,
            $connected->receive("\x15", 5_000_000)->closeReason,
        );
        self::assertTrue($connected->isClosed());
    }

    public function testGuidSecurityAndPreReadyPayloadFailClosed(): void
    {
        foreach ([
            new ConnectionRequest(56, 1, false)->encode(),
            new ConnectionRequest(55, 1, true)->encode(),
            "\xfeapplication",
        ] as $payload) {
            $control = $this->control();
            try {
                $control->receive($payload, 1);
                self::fail('Invalid pre-ready payload was accepted.');
            } catch (UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }

    }

    public function testRetailStyleRefreshedNewIncomingTimestampsOpenSession(): void
    {
        $control = $this->control();
        $control->receive(new ConnectionRequest(55, 1_203_939_779, false)->encode(), 1);
        $incoming = new NewIncomingConnection(
            new InternetAddress('127.0.0.1', 19132),
            array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0)),
            1_203_939_787,
            1_203_939_790,
        );
        self::assertTrue($control->receive($incoming->encode(), 2)->becameReady);
        self::assertTrue($control->isReady());
    }

    public function testNewIncomingConnectionBodyIsOpaqueAfterConnectionRequest(): void
    {
        $payloads = [
            'identifier only' => "\x13",
            'ten IPv4 addresses' => "\x13" . str_repeat("\x04\xff\xff\xff\xff\x00\x00", 10),
            'twenty IPv4 addresses' => "\x13" . str_repeat("\x04\xff\xff\xff\xff\x00\x00", 20),
            'platform-specific IPv6 family' => "\x13\x06\x1e\x00\x4a\xbc\x00\x00",
            'truncated address-shaped body' => "\x13\x04\xff",
            'unrecognized bounded body' => "\x13opaque-final-acknowledgement",
        ];

        foreach ($payloads as $description => $payload) {
            $control = $this->control();
            $control->receive(new ConnectionRequest(55, 1, false)->encode(), 1);

            self::assertTrue(
                $control->receive($payload, 2)->becameReady,
                $description,
            );
            self::assertTrue($control->isReady(), $description);
        }
    }

    public function testNewIncomingConnectionOpensControlSessionExactlyOnce(): void
    {
        $control = $this->control();
        $control->receive(new ConnectionRequest(55, 1, false)->encode(), 1);

        self::assertTrue($control->receive("\x13", 2)->becameReady);
        self::assertFalse($control->receive("\x13duplicate-body-is-ignored", 3)->becameReady);
        self::assertTrue($control->isReady());
        self::assertTrue($control->hasOpened());
    }

    public function testNewIncomingConnectionBeforeConnectionRequestFailsClosed(): void
    {
        $control = $this->control();

        try {
            $control->receive("\x13", 1);
            self::fail('A final acknowledgement opened a control session in the wrong state.');
        } catch (UnexpectedValueException) {
            self::assertFalse($control->isReady());
            self::assertFalse($control->hasOpened());
        }
    }

    public function testContradictoryReadyReplayFailsClosed(): void
    {
        $control = $this->readyControl();
        $this->expectException(UnexpectedValueException::class);
        $control->receive(new ConnectionRequest(55, 2, false)->encode(), 3);
    }

    public function testEmptyPayloadFailsBeforePacketIdentifierAccess(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->control()->receive('', 1);
    }

    private function control(): ConnectedControlSession
    {
        return new ConnectedControlSession(new SessionInfo('127.0.0.1', 19133, 55, 576, 11), 10_000_000);
    }

    private function readyControl(): ConnectedControlSession
    {
        $control = $this->control();
        $control->receive(new ConnectionRequest(55, 1, false)->encode(), 1);
        $control->receive(new NewIncomingConnection(
            new InternetAddress('127.0.0.1', 19132),
            array_fill(0, NewIncomingConnection::INTERNAL_ADDRESS_COUNT, new InternetAddress('0.0.0.0', 0)),
            1,
            2,
        )->encode(), 2);

        return $control;
    }
}
