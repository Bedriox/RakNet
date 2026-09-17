<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

final readonly class AckPacket
{
    /** @param list<SequenceRange> $ranges */
    public function __construct(public array $ranges)
    {
        AcknowledgementCodec::encode(AcknowledgementCodec::ACK_ID, $this->ranges);
    }

    public static function decode(string $packet): self
    {
        return new self(AcknowledgementCodec::decode($packet, AcknowledgementCodec::ACK_ID));
    }

    public function encode(): string
    {
        return AcknowledgementCodec::encode(AcknowledgementCodec::ACK_ID, $this->ranges);
    }
}
