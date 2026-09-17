<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Connected;

use Bedriox\RakNet\Protocol\AcknowledgementCodec;
use Bedriox\RakNet\Protocol\SplitMetadata;
use Bedriox\RakNet\Reliability\ReliabilityLimits;
use InvalidArgumentException;

/** Hard resource limits for one connected transport session. */
final readonly class ConnectedSessionLimits
{
    public ReliabilityLimits $reliability;

    public function __construct(
        ?ReliabilityLimits $reliability = null,
        public int $maximumApplicationPayloadBytes = 524_288,
        public int $maximumQueuedFrames = 4_096,
        public int $maximumQueuedPayloadBytes = 1_048_576,
        public int $maximumDeliveredPayloads = 4_096,
        public int $maximumDeliveredPayloadBytes = 2_097_152,
        public int $maximumOutboundDatagrams = 4_096,
        public int $maximumOutboundBytes = 4_194_304,
        public int $fragmentLifetimeNanoseconds = 5_000_000_000,
        public int $maximumActiveAssemblies = 16,
        public int $maximumFragmentsPerAssembly = 256,
        public int $maximumBytesPerAssembly = 524_288,
        public int $maximumAggregateFragmentBytes = 2_097_152,
        public int $maximumOrderedPayloadsPerChannel = 1_024,
        public int $maximumOrderedBytesPerChannel = 262_144,
        public int $maximumOutboundSplitLeases = 256,
        public int $maximumReliableDatagramsInFlight = 64,
        public int $maximumDatagramsPerTick = 256,
    ) {
        $this->reliability = $reliability ?? new ReliabilityLimits();
        foreach (
            [
                $maximumApplicationPayloadBytes,
                $maximumQueuedFrames,
                $maximumQueuedPayloadBytes,
                $maximumDeliveredPayloads,
                $maximumDeliveredPayloadBytes,
                $maximumOutboundDatagrams,
                $maximumOutboundBytes,
                $fragmentLifetimeNanoseconds,
                $maximumActiveAssemblies,
                $maximumFragmentsPerAssembly,
                $maximumBytesPerAssembly,
                $maximumAggregateFragmentBytes,
                $maximumOrderedPayloadsPerChannel,
                $maximumOrderedBytesPerChannel,
                $maximumOutboundSplitLeases,
                $maximumReliableDatagramsInFlight,
                $maximumDatagramsPerTick,
            ] as $limit
        ) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Connected-session limits must be positive.');
            }
        }
        if ($maximumFragmentsPerAssembly > SplitMetadata::MAXIMUM_PARTS) {
            throw new InvalidArgumentException('Fragment limit exceeds the supported wire limit.');
        }
        if ($maximumOutboundSplitLeases > 0x1_0000) {
            throw new InvalidArgumentException('Outbound split-lease limit exceeds the 16-bit ID space.');
        }
        if ($maximumReliableDatagramsInFlight > $this->reliability->maximumSentDatagrams) {
            throw new InvalidArgumentException('Reliable in-flight window exceeds sent-history capacity.');
        }
        if ($this->reliability->maximumAcknowledgementSequences > AcknowledgementCodec::MAXIMUM_REPRESENTED_SEQUENCES) {
            throw new InvalidArgumentException('Acknowledgement capacity exceeds the wire represented-sequence limit.');
        }
        if ($maximumOutboundBytes < 8) {
            throw new InvalidArgumentException('Outbound byte capacity cannot hold a minimum connected datagram.');
        }
    }
}
