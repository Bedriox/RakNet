<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Reliability;

/** Relative position within the 24-bit serial number space. */
enum SequenceRelation
{
    case Same;
    case Newer;
    case Older;
    case Ambiguous;
}
