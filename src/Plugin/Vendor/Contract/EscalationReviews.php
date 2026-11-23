<?php

declare(strict_types=1);

namespace Intakt\Plugin\Vendor\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\EscalationVerdict;
use NoDiscard;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;

// Apart from Vendor, because only the escalation of a recurring P1 calls it and every other vendor double would carry a method no test of it calls.
interface EscalationReviews
{
    // The strong tier, because the escalation judges a P1 the panel raised twice; one home, so the check before the call asks for the tier the call uses.
    public const Tier TIER = Tier::Strong;

    // Null for an answer that is no escalation verdict; a failed call stays an error, unchanged, so the queue tries it again.
    #[NoDiscard('an escalation verdict that is not read decides nothing')]
    public function escalate(string $project, Uuid $round, MessageBag $messages): ?EscalationVerdict;
}
