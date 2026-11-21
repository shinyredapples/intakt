<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Closure;
use Intakt\Core\Review\Contract\EscalationRequest;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Override;

// Stands in for the strong tier because a test must never reach a provider; the requests tell a test what the escalation was asked, and the closure runs during the call, so a test can force another writer in before the verdict is kept.
final class ScriptedEscalations implements EscalationReviews
{
    /** @var list<array{string, EscalationRequest}> */
    public array $requests = [];

    public function __construct(
        private readonly ?EscalationVerdict $verdict,
        private readonly ?Closure $duringTheCall = null,
    ) {
    }

    #[Override]
    public function escalate(string $project, EscalationRequest $request): ?EscalationVerdict
    {
        $this->requests[] = [$project, $request];

        if ($this->duringTheCall instanceof Closure) {
            ($this->duringTheCall)($request);
        }

        return $this->verdict;
    }
}
