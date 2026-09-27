<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

/** Bounded operational cause accompanying a transport-failure close event. */
enum SessionTransportFailureReason: string
{
    case MalformedDatagram = 'MALFORMED_DATAGRAM';
    case InvalidControlEnvelope = 'INVALID_CONTROL_ENVELOPE';
    case InvalidControlPayload = 'INVALID_CONTROL_PAYLOAD';
    case OutboundFrameQueue = 'OUTBOUND_FRAME_QUEUE';
    case ReliableFrameTracking = 'RELIABLE_FRAME_TRACKING';
    case ReliableExpiryQueue = 'RELIABLE_EXPIRY_QUEUE';
    case FragmentReassembly = 'FRAGMENT_REASSEMBLY';
    case OrderedDelivery = 'ORDERED_DELIVERY';
    case DeliveredPayloadQueue = 'DELIVERED_PAYLOAD_QUEUE';
    case OutboundDatagramQueue = 'OUTBOUND_DATAGRAM_QUEUE';
    case SessionEventQueue = 'SESSION_EVENT_QUEUE';
    case GlobalReceivedPayloadQueue = 'GLOBAL_RECEIVED_PAYLOAD_QUEUE';
    case GlobalPendingOutboundQueue = 'GLOBAL_PENDING_OUTBOUND_QUEUE';
    case SocketSend = 'SOCKET_SEND';
    case InvariantViolation = 'INVARIANT_VIOLATION';
    case ResourceLimit = 'RESOURCE_LIMIT';
}
