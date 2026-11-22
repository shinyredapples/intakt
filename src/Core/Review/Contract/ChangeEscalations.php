<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ChangeEscalations
{
    // The outcome for the rounds at the head of the change's latest round only, because a confirmation sends the next build to the strong tier, a rejection's re-review runs on the same head, and a newer head has not been escalated yet; confirmed wins when several findings were escalated.
    /**
     * @throws EscalationStillPending
     */
    public function outcomeAtLatestHead(string $project, string $change): ?EscalationOutcome;
}
