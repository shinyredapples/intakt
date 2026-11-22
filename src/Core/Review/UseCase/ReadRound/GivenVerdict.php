<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The verdict in the reviewer's answer format, so a client reads the resolved ids and follow-up notes as the strings the reviewer wrote, whatever types the contract Verdict gives them. Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class GivenVerdict
{
    public function __construct(
        public Tier $tier,
        public Verdict $verdict,
    ) {
    }

    public static function of(PanelVerdict $verdict): self
    {
        return new self($verdict->tier, Verdict::fromVerdict($verdict->verdict));
    }
}
