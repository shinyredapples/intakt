<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Tier: string
{
    case Strong = 'strong';
    case Standard = 'standard';
    case Light = 'light';
}
