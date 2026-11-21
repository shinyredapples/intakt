<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum EscalationOutcome: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
