<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ChangeEscalations
{
    // The outcome for the change's latest round only, because a confirmation sends the next build to the strong tier and a newer round has not been escalated yet; confirmed wins when one round escalated several findings.
    public function outcomeOfLatestRound(string $project, string $change): ?EscalationOutcome;
}
