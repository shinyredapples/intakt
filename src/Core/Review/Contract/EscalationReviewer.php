<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use NoDiscard;
use Symfony\Component\Uid\Uuid;

interface EscalationReviewer
{
    // A failed escalation keeps no verdict; a provider error from the call is thrown unchanged, so the queue tries the escalation again.
    /**
     * @throws ProjectNotConfigured
     */
    #[NoDiscard('an escalation that is not read decides nothing')]
    public function review(string $project, Uuid $round, EscalationRequest $request): EscalationReviewed|EscalationFailed;
}
