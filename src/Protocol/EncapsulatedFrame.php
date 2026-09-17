<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class EncapsulatedFrame
{
    public const int MAXIMUM_ORDERING_CHANNELS = 32;

    public function __construct(
        public Reliability $reliability,
        public BitPayload $payload,
        public ?int $reliableIndex = null,
        public ?int $sequenceIndex = null,
        public ?int $orderingIndex = null,
        public ?int $orderingChannel = null,
        public ?SplitMetadata $split = null,
    ) {
        $this->validateOptionalIndex($this->reliableIndex, $this->reliability->hasReliableIndex(), 'reliable');
        $this->validateOptionalIndex($this->sequenceIndex, $this->reliability->hasSequenceIndex(), 'sequence');
        $this->validateOptionalIndex($this->orderingIndex, $this->reliability->hasOrdering(), 'ordering');

        if ($this->reliability->hasOrdering()) {
            if ($this->orderingChannel === null || $this->orderingChannel < 0 || $this->orderingChannel >= self::MAXIMUM_ORDERING_CHANNELS) {
                throw new CodecException('Ordering channel is missing or out of range.');
            }
        } elseif ($this->orderingChannel !== null) {
            throw new CodecException('Ordering channel is forbidden for this reliability mode.');
        }
    }

    public static function decode(BinaryReader $reader): self
    {
        $flags = $reader->readByte();
        if (($flags & 0x0f) !== 0) {
            throw new CodecException('Encapsulated frame contains reserved flag bits.');
        }

        $reliability = Reliability::from(($flags >> 5) & 0x07);
        $split = ($flags & 0x10) !== 0;
        $bitLength = $reader->readUnsignedShort();
        if ($bitLength < 1 || $bitLength > BitPayload::MAXIMUM_BITS) {
            throw new CodecException('Encapsulated payload bit length is out of range.');
        }

        $reliableIndex = $reliability->hasReliableIndex() ? $reader->readUnsigned24LittleEndian() : null;
        $sequenceIndex = $reliability->hasSequenceIndex() ? $reader->readUnsigned24LittleEndian() : null;
        $orderingIndex = $reliability->hasOrdering() ? $reader->readUnsigned24LittleEndian() : null;
        $orderingChannel = $reliability->hasOrdering() ? $reader->readByte() : null;

        $splitMetadata = null;
        if ($split) {
            $splitMetadata = new SplitMetadata(
                $reader->readUnsignedInt(),
                $reader->readUnsignedShort(),
                $reader->readUnsignedInt(),
            );
        }

        $byteLength = intdiv($bitLength + 7, 8);
        $payload = new BitPayload($reader->readBytes($byteLength), $bitLength);

        return new self(
            $reliability,
            $payload,
            $reliableIndex,
            $sequenceIndex,
            $orderingIndex,
            $orderingChannel,
            $splitMetadata,
        );
    }

    public function encode(BinaryWriter $writer): void
    {
        $writer->writeByte(($this->reliability->value << 5) | ($this->split instanceof SplitMetadata ? 0x10 : 0));
        $writer->writeUnsignedShort($this->payload->bitLength);

        if ($this->reliableIndex !== null) {
            $writer->writeUnsigned24LittleEndian($this->reliableIndex);
        }
        if ($this->sequenceIndex !== null) {
            $writer->writeUnsigned24LittleEndian($this->sequenceIndex);
        }
        if ($this->orderingIndex !== null && $this->orderingChannel !== null) {
            $writer->writeUnsigned24LittleEndian($this->orderingIndex);
            $writer->writeByte($this->orderingChannel);
        }
        if ($this->split instanceof SplitMetadata) {
            $writer->writeUnsignedInt($this->split->count);
            $writer->writeUnsignedShort($this->split->id);
            $writer->writeUnsignedInt($this->split->index);
        }

        $writer->writeBytes($this->payload->bytes);
    }

    private function validateOptionalIndex(?int $value, bool $required, string $name): void
    {
        if ($required && $value === null) {
            throw new CodecException(ucfirst($name) . ' index is required for this reliability mode.');
        }
        if (!$required && $value !== null) {
            throw new CodecException(ucfirst($name) . ' index is forbidden for this reliability mode.');
        }
        if ($value !== null) {
            SequenceMath::validate($value);
        }
    }
}
