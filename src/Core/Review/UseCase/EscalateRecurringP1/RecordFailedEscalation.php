<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Platform\Contract\CommandMessage;
use Symfony\Component\Messenger\Stamp\DispatchOnFailureStamp;

// Travels inside the DispatchOnFailureStamp of its EscalateRecurringP1, so Messenger dispatches it once that escalation is parked for good.
final readonly class RecordFailedEscalation implements CommandMessage
{
    public function __construct(
        public string $round,
        public string $finding,
        public string $key,
    ) {
    }

    public static function onFailureOf(EscalateRecurringP1 $escalation): DispatchOnFailureStamp
    {
        return new DispatchOnFailureStamp(new self($escalation->round, $escalation->finding, $escalation->key));
    }
}
