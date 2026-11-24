<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ListProjectTiers;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolutionFailure;
use Intakt\Core\Ai\Contract\TierResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:ai:tiers', description: 'List the projects and the provider model each capability tier resolves to')]
final readonly class ListProjectTiersCommand
{
    public function __construct(
        private TierResolver $resolver,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $projects = [...$this->resolver->projects()];

        if ([] === $projects) {
            $io->warning('No projects are configured.');

            return Command::SUCCESS;
        }

        $io->table(['Project', 'Tier', 'Provider', 'Model or problem'], $this->rows($projects));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $projects
     *
     * @return list<list<string>>
     */
    private function rows(array $projects): array
    {
        $rows = [];

        foreach ($projects as $project) {
            foreach (Tier::cases() as $tier) {
                $rows[] = [$project, $tier->value, ...$this->resolution($project, $tier)];
            }
        }

        return $rows;
    }

    /**
     * @return array{string, string}
     */
    private function resolution(string $project, Tier $tier): array
    {
        try {
            $resolved = $this->resolver->resolve($project, $tier);
        } catch (TierResolutionFailure $failure) {
            return ['-', $failure->getMessage()];
        }

        return [$resolved->provider->value, $resolved->model];
    }
}
