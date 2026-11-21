<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// How an escalation review ended for one finding: its P1 stands, or it was found not to hold.
enum EscalationOutcome: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
