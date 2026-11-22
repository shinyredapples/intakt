<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\EscalateRecurringP1;

use Symfony\Component\Messenger\Stamp\StampInterface;

// Apart from the RedeliveryStamp, because that one also counts the forced retries of a busy account or a rate limit, which must not use up the retries of a transient failure.
final readonly class TransientEscalationFailures implements StampInterface
{
    public function __construct(public int $count)
    {
    }
}
