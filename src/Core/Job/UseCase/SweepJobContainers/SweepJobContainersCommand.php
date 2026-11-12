<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\SweepJobContainers;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Infrastructure\OrphanedJobs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:job:sweep', description: 'Remove the containers and output directories of jobs whose orchestrator process is gone')]
final readonly class SweepJobContainersCommand
{
    public function __construct(private OrphanedJobs $orphans)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $removed = $this->orphans->removeAll();
        } catch (DockerEngineFailed $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        foreach ($removed as $container) {
            $io->writeln(sprintf('removed %s (job %s)', $container->id, $container->job->value));
        }

        $io->success(sprintf('%d orphaned job containers removed.', count($removed)));

        return Command::SUCCESS;
    }
}
