<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:task', description: 'Read one issue of a project as a task, update the project\'s mirror and make its build clone, stopping at the first step that fails')]
final readonly class BuildTaskCommand
{
    public function __construct(private BuildTask $task)
    {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] BuildTaskInput $input): int
    {
        if ($input->issue < 1) {
            $io->error('The issue number must be at least 1.');

            return Command::FAILURE;
        }

        try {
            $outcome = $this->task->run($input->project, $input->issue);
        } catch (TaskStepNotRecorded $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        foreach ($outcome->completed as $step) {
            $io->writeln($step->value.' done');
        }

        if (!$outcome->passed()) {
            $io->error(sprintf('The %s step failed: %s', $outcome->failedAt?->value, $outcome->reason));

            return Command::FAILURE;
        }

        $io->writeln('clone '.OutputFormatter::escape((string) $outcome->directory));

        return Command::SUCCESS;
    }
}
