<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// Measured (read from a provider's answer, as tokens), counted (from the ledger, as rounds) or estimated (from a price list, as cost), so an owner never reads a guess as a reading: the case's value is the one marker every surface shows after the figure.
enum FigureKind: string
{
    case Measured = 'measured';
    case Counted = 'counted';
    case Estimated = 'estimated';
}
