<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

// Thrown instead of an outcome, because a build tier chosen while the escalation review is still queued would miss the strong tier a confirmation gives.
final class EscalationStillPending extends RuntimeException implements ReportableFailure
{
    public static function of(string $change): self
    {
        return new self(sprintf('The escalation review of a recurring P1 of change "%s" has not answered yet: choose the build\'s tier once it has.', $change));
    }
}
