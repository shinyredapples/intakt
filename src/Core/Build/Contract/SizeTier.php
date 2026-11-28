<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The tiers of the size contract: small tasks may be merged with siblings, large ones are built whole, and a task in the split tier must be split before it is built.
enum SizeTier: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Split = 'split';
}
