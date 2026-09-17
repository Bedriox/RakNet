<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class ConnectedDatagram
{
    public const int VALID_FLAG = 0x80;
    public const int ALLOWED_FLAGS = 0x9c;
    public const int MAXIMUM_BYTES = 1_492;
    public const int MAXIMUM_FRAMES = 256;

    /** @param list<EncapsulatedFrame> $frames */
    public function __construct(
        public int $flags,
        public int $sequenceNumber,
        public array $frames,
    ) {
        self::validateFlags($this->flags);
        SequenceMath::validate($this->sequenceNumber);
        if ($this->frames === [] || \count($this->frames) > self::MAXIMUM_FRAMES) {
            throw new CodecException('Connected datagram frame count is out of range.');
        }
    }

    public static function decode(string $datagram): self
    {
        $reader = new BinaryReader($datagram, self::MAXIMUM_BYTES);
        $flags = $reader->readByte();
        self::validateFlags($flags);
        $sequenceNumber = $reader->readUnsigned24LittleEndian();
        $frames = [];
        while ($reader->remaining() > 0) {
            if (\count($frames) >= self::MAXIMUM_FRAMES) {
                throw new CodecException('Connected datagram exceeds the frame-count limit.');
            }
            $frames[] = EncapsulatedFrame::decode($reader);
        }

        return new self($flags, $sequenceNumber, $frames);
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::MAXIMUM_BYTES);
        $writer->writeByte($this->flags);
        $writer->writeUnsigned24LittleEndian($this->sequenceNumber);
        foreach ($this->frames as $frame) {
            $frame->encode($writer);
        }

        return $writer->bytes();
    }

    /** Shared non-throwing predicate for socket-level packet dispatch. */
    public static function acceptsFlags(int $flags): bool
    {
        return $flags >= 0
            && $flags <= 0xff
            && ($flags & self::VALID_FLAG) !== 0
            && ($flags & ~self::ALLOWED_FLAGS) === 0;
    }

    private static function validateFlags(int $flags): void
    {
        if (!self::acceptsFlags($flags)) {
            throw new CodecException('Connected datagram flags are invalid or reserved.');
        }
    }
}
