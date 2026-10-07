<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Security;

enum AdmissionDecision
{
    case ALLOW;
    case DROP;
    case BLOCK;
}
