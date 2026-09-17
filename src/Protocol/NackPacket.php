<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

final readonly class NackPacket
{
    /** @param list<SequenceRange> $ranges */
    public function __construct(public array $ranges)
    {
        AcknowledgementCodec::encode(AcknowledgementCodec::NACK_ID, $this->ranges);
    }

    public static function decode(string $packet): self
    {
        return new self(AcknowledgementCodec::decode($packet, AcknowledgementCodec::NACK_ID));
    }

    public function encode(): string
    {
        return AcknowledgementCodec::encode(AcknowledgementCodec::NACK_ID, $this->ranges);
    }
}
