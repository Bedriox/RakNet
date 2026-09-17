<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class ConnectedHandshakeDiagnosticEvent
{
    public function __construct(
        public string $remoteAddress,
        public int $remotePort,
        public ConnectedHandshakeStage $stage,
        public ConnectedHandshakeRejectionReason $reason,
        public ?int $datagramId = null,
        public ?int $controlPacketId = null,
        public ?int $payloadLength = null,
        public ?Reliability $reliability = null,
        public ?int $orderingChannel = null,
    ) {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Handshake diagnostic endpoint must use IPv4.');
        }
        if ($this->remotePort < 1 || $this->remotePort > 65_535) {
            throw new InvalidArgumentException('Handshake diagnostic endpoint port is out of range.');
        }
        foreach (['datagram' => $this->datagramId, 'control packet' => $this->controlPacketId] as $name => $identifier) {
            if ($identifier !== null && ($identifier < 0 || $identifier > 0xff)) {
                throw new InvalidArgumentException("Handshake diagnostic {$name} identifier is out of range.");
            }
        }
        if ($this->payloadLength !== null && $this->payloadLength < 0) {
            throw new InvalidArgumentException('Handshake diagnostic payload length must be nonnegative.');
        }
        if (($this->reliability?->hasOrdering() ?? false) !== ($this->orderingChannel !== null)) {
            throw new InvalidArgumentException('Handshake diagnostic ordering metadata is inconsistent.');
        }
        if ($this->orderingChannel !== null && ($this->orderingChannel < 0 || $this->orderingChannel > 31)) {
            throw new InvalidArgumentException('Handshake diagnostic ordering channel is out of range.');
        }
    }
}
