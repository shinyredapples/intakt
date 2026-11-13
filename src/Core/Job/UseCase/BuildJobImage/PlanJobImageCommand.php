<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
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
        #[Option('Plan the image of a review job: a project Dockerfile or devcontainer image gets the job tools added')]
        bool $review = false,
    ): int {
        try {
            $plan = $review ? $this->planner->planForReview($directory) : $this->planner->planFor($directory);
        } catch (JobImageNotBuilt $refused) {
            $io->error($refused->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($plan->summary());
        $this->showDockerfileOf($plan, $io);

        return Command::SUCCESS;
    }

    private function showDockerfileOf(JobImagePlan $plan, SymfonyStyle $io): void
    {
        if (null !== $plan->generated) {
            $io->writeln($plan->generated);
        }

        if (null !== $plan->tree) {
            $io->writeln(sprintf('Builds %s in %s.', $plan->dockerfile, '' === $plan->tree ? 'the repository root' : $plan->tree));
        }
    }
}
