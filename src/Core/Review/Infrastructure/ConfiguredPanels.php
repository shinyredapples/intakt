<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredPanels
{
    /**
     * @param array<string, array{quorum: string, reviewers: list<array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int}>}> $panels
     */
    public function __construct(
        #[Autowire('%intakt.review.panels%')]
        private array $panels,
    ) {
    }

    public function panelFor(string $project): Panel
    {
        $panel = $this->panels[$project] ?? throw new PanelNotConfigured($project);

        return new Panel(
            array_map(self::reviewer(...), $panel['reviewers']),
            QuorumPolicy::from($panel['quorum']),
        );
    }

    /**
     * @param array{role: non-empty-string, tier: string, provider: string, prompt: non-empty-string, budget: positive-int} $reviewer
     */
    private static function reviewer(array $reviewer): PanelReviewer
    {
        return new PanelReviewer(
            $reviewer['role'],
            Tier::from($reviewer['tier']),
            Provider::from($reviewer['provider']),
            $reviewer['prompt'],
            $reviewer['budget'],
        );
    }
}
