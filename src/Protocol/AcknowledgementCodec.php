<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final class AcknowledgementCodec
{
    public const int ACK_ID = 0xc0;
    public const int NACK_ID = 0xa0;
    /** The largest worst-case record count that still fits in one 1,492-byte datagram. */
    public const int MAXIMUM_RECORDS = 212;
    public const int MAXIMUM_REPRESENTED_SEQUENCES = 8_192;
    public const int MAXIMUM_BYTES = 1_492;

    private function __construct() {}

    /** @param list<SequenceRange> $ranges */
    public static function encode(int $id, array $ranges): string
    {
        self::validateIdAndRanges($id, $ranges);
        $writer = new BinaryWriter(self::MAXIMUM_BYTES);
        $writer->writeByte($id);
        $writer->writeUnsignedShort(\count($ranges));
        foreach ($ranges as $range) {
            $writer->writeByte($range->isSingle() ? 1 : 0);
            $writer->writeUnsigned24LittleEndian($range->start);
            if (!$range->isSingle()) {
                $writer->writeUnsigned24LittleEndian($range->end);
            }
        }

        return $writer->bytes();
    }

    /** @return list<SequenceRange> */
    public static function decode(string $packet, int $expectedId): array
    {
        if ($expectedId !== self::ACK_ID && $expectedId !== self::NACK_ID) {
            throw new CodecException('Acknowledgement packet identifier is invalid.');
        }

        $reader = new BinaryReader($packet, self::MAXIMUM_BYTES);
        if ($reader->readByte() !== $expectedId) {
            throw new CodecException('Acknowledgement packet identifier does not match its codec.');
        }
        $recordCount = $reader->readUnsignedShort();
        if ($recordCount < 1 || $recordCount > self::MAXIMUM_RECORDS) {
            throw new CodecException('Acknowledgement record count is out of range.');
        }

        $ranges = [];
        $represented = 0;
        for ($record = 0; $record < $recordCount; ++$record) {
            $single = $reader->readBoolean();
            $start = $reader->readUnsigned24LittleEndian();
            $range = new SequenceRange($start, $single ? $start : $reader->readUnsigned24LittleEndian());
            $represented += $range->span();
            if ($represented > self::MAXIMUM_REPRESENTED_SEQUENCES) {
                throw new CodecException('Acknowledgement packet exceeds the represented-sequence limit.');
            }
            $ranges[] = $range;
        }
        $reader->requireEnd();

        return $ranges;
    }

    /** @param list<SequenceRange> $ranges */
    private static function validateIdAndRanges(int $id, array $ranges): void
    {
        if ($id !== self::ACK_ID && $id !== self::NACK_ID) {
            throw new CodecException('Acknowledgement packet identifier is invalid.');
        }
        if ($ranges === [] || \count($ranges) > self::MAXIMUM_RECORDS) {
            throw new CodecException('Acknowledgement record count is out of range.');
        }

        $represented = 0;
        foreach ($ranges as $range) {
            $represented += $range->span();
            if ($represented > self::MAXIMUM_REPRESENTED_SEQUENCES) {
                throw new CodecException('Acknowledgement packet exceeds the represented-sequence limit.');
            }
        }
    }
}
