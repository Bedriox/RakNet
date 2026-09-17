<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

final readonly class OrderedDeliveryResult
{
    /** @param list<string> $payloads */
    public function __construct(
        public OrderedDeliveryStatus $status,
        public array $payloads = [],
    ) {}
}
