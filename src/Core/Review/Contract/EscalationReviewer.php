<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use NoDiscard;
use Symfony\AI\Platform\PlatformInterface;

interface EscalationReviewer
{
    // Null for an answer that is no escalation verdict, because plain code must record that as a failed escalation and keep no verdict.
    /**
     * @param non-empty-string $model
     */
    #[NoDiscard('an escalation verdict that is not read decides nothing')]
    public function review(EscalationRequest $request, PlatformInterface $platform, string $model): ?EscalationVerdict;
}
