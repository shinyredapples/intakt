<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Closure;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Override;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;

// Stands in for the strong tier because a test must never reach a provider; the calls tell a test what the escalation was asked, and the closure runs during the call, so a test can force another writer in before the verdict is kept.
final class ScriptedEscalations implements EscalationReviews
{
    /** @var list<array{string, Uuid, MessageBag}> */
    public array $calls = [];

    public function __construct(
        private readonly ?EscalationVerdict $verdict,
        private readonly ?Closure $duringTheCall = null,
    ) {
    }

    #[Override]
    public function escalate(string $project, Uuid $round, MessageBag $messages): ?EscalationVerdict
    {
        $this->calls[] = [$project, $round, $messages];

        if ($this->duringTheCall instanceof Closure) {
            ($this->duringTheCall)();
        }

        return $this->verdict;
    }
}
