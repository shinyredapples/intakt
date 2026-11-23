<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\QueryMessage;

final readonly class ReplayMarkOfRound implements QueryMessage
{
    public function __construct(public string $roundId)
    {
    }
}
