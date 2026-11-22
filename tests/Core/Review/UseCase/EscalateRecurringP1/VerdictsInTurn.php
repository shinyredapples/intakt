<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Plugin\Vendor\Contract\EscalationReviews;
use Override;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;

// Stands in for the strong tier inside the application's container, so a queue worker gets one scripted answer per message, in the order the queue hands them out.
final class VerdictsInTurn implements EscalationReviews
{
    /**
     * @param list<EscalationVerdict> $verdicts
     */
    public function __construct(private array $verdicts)
    {
    }

    #[Override]
    public function escalate(string $project, Uuid $round, MessageBag $messages): ?EscalationVerdict
    {
        return array_shift($this->verdicts);
    }
}
