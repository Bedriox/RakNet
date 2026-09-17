<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Session;

final readonly class FragmentAcceptance
{
    public function __construct(
        public FragmentAcceptanceStatus $status,
        public ?string $payload = null,
    ) {}
}
