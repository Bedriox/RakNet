<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

use InvalidArgumentException;

/** Hard per-session count, byte, attempt, and timing limits. */
final readonly class ReliabilityLimits
{
    public function __construct(
        public int $receiveWindowSize = 2_048,
        public int $maximumAcknowledgementSequences = 2_048,
        public int $maximumTrackedFrames = 4_096,
        public int $maximumTrackedPayloadBytes = 1_048_576,
        public int $maximumSentDatagrams = 2_048,
        public int $maximumHistoryReferences = 8_192,
        public int $maximumAttempts = 8,
        public int $maximumAgeNanoseconds = 10_000_000_000,
        public int $initialRtoNanoseconds = 500_000_000,
        public int $minimumRtoNanoseconds = 100_000_000,
        public int $maximumRtoNanoseconds = 2_000_000_000,
    ) {
        if ($receiveWindowSize < 2 || $receiveWindowSize >= Sequence24::HALF_RANGE) {
            throw new InvalidArgumentException('Receive window must be between 2 and 8388607.');
        }
        if ($maximumAcknowledgementSequences < 1 || $maximumAcknowledgementSequences >= Sequence24::HALF_RANGE) {
            throw new InvalidArgumentException('Acknowledgement limit must be positive and below half the sequence space.');
        }
        foreach (
            [
                'tracked frame count' => $maximumTrackedFrames,
                'tracked payload bytes' => $maximumTrackedPayloadBytes,
                'sent datagram count' => $maximumSentDatagrams,
                'history reference count' => $maximumHistoryReferences,
                'attempt count' => $maximumAttempts,
                'maximum age' => $maximumAgeNanoseconds,
                'initial RTO' => $initialRtoNanoseconds,
                'minimum RTO' => $minimumRtoNanoseconds,
                'maximum RTO' => $maximumRtoNanoseconds,
            ] as $name => $value
        ) {
            if ($value < 1) {
                throw new InvalidArgumentException($name . ' limit must be positive.');
            }
        }
        if ($minimumRtoNanoseconds > $initialRtoNanoseconds || $initialRtoNanoseconds > $maximumRtoNanoseconds) {
            throw new InvalidArgumentException('RTO limits must satisfy minimum <= initial <= maximum.');
        }
        if ($maximumHistoryReferences < $maximumSentDatagrams) {
            throw new InvalidArgumentException('History references must allow at least one reference per sent datagram.');
        }
    }
}
