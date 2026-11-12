<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:job:plan-image', description: 'Show where the job image of a project checkout would come from, and the Dockerfile Intakt would write, without building')]
final readonly class PlanJobImageCommand
{
    public function __construct(private JobImagePlanner $planner)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The project checkout; the files of its HEAD commit decide the image')]
        string $directory,
    ): int {
        try {
            $plan = $this->planner->planFor($directory);
        } catch (JobImageNotBuilt $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($plan->summary());
        $io->writeln($plan->generated ?? sprintf('Builds %s in %s.', $plan->dockerfile, '' === $plan->tree ? 'the repository root' : $plan->tree));

        return Command::SUCCESS;
    }
}
