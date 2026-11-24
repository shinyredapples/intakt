<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A value of its own beside ProjectSummary, because the search and the project filter need only the name and the counts of rounds.
#[Exclude]
final readonly class ProjectCard
{
    /**
     * @param list<WorkingCard>    $working
     * @param list<ProjectOutcome> $recent
     * @param list<TierLine>       $tiers
     */
    public function __construct(
        public string $name,
        public int $running,
        public int $judged,
        public int $failing,
        public int $waitingOnYou,
        public ?string $problem,
        public TokenFigures $tokens,
        public Cost $cost,
        public array $working,
        public array $recent,
        public array $tiers,
    ) {
    }

    public function isFailing(): bool
    {
        return $this->failing > 0;
    }

    // One word for the dot and the line under the name, so the state never rests on color alone.
    public function status(): string
    {
        return match (true) {
            $this->isFailing() => 'Failing',
            $this->running > 0 => 'Running',
            default => 'Quiet',
        };
    }
}
