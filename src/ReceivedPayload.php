<?php

declare(strict_types=1);

namespace Bedriox\RakNet;

use Bedriox\RakNet\Protocol\Reliability;
use InvalidArgumentException;

final readonly class ReceivedPayload
{
    public function __construct(
        public string $remoteAddress,
        public int $remotePort,
        public string $payload,
        public Reliability $reliability,
        public ?int $orderingChannel,
    ) {
        if (filter_var($this->remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Received payload endpoint must use IPv4.');
        }
        if ($this->remotePort < 1 || $this->remotePort > 65_535) {
            throw new InvalidArgumentException('Received payload endpoint port is out of range.');
        }
        if ($this->payload === '') {
            throw new InvalidArgumentException('Received application payload cannot be empty.');
        }
        if (!\in_array($this->reliability, [Reliability::Unreliable, Reliability::Reliable, Reliability::ReliableOrdered], true)) {
            throw new InvalidArgumentException('Received payload reliability mode is unsupported.');
        }
        if (($this->reliability === Reliability::ReliableOrdered) !== ($this->orderingChannel !== null)) {
            throw new InvalidArgumentException('Ordering channel must be present only for reliable ordered payloads.');
        }
        if ($this->orderingChannel !== null && ($this->orderingChannel < 0 || $this->orderingChannel > 31)) {
            throw new InvalidArgumentException('Received payload ordering channel is out of range.');
        }
    }
}
