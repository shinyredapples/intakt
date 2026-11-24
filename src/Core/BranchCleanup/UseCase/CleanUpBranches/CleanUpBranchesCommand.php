<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:branches:clean-up', description: 'Run the weekly branch cleanup of a project now: warn a week ahead, delete what was warned, keep each deleted head as an archive ref')]
final readonly class CleanUpBranchesCommand
{
    public function __construct(
        private ProjectCleanup $cleanup,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name')]
        string $project,
    ): int {
        try {
            $report = $this->cleanup->run($project);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        foreach ($report->warned as $warning) {
            $io->writeln(sprintf('warned %s %s deletable from %s', $warning->branch(), $warning->head(), $warning->deletableFrom()->format('Y-m-d')));
        }

        foreach ($report->deleted as $archived) {
            $io->writeln(sprintf('deleted %s %s archived as %s', $archived->branch, $archived->commit, $archived->ref));
        }

        foreach ($report->stale as $head) {
            $io->writeln(sprintf('stale %s %s', $head->branch, $head->commit));
        }

        foreach ($report->failures as $failure) {
            $io->writeln('not deleted '.$failure);
        }

        $io->writeln(sprintf('%d warned, %d deleted, %d stale, %d not deleted', count($report->warned), count($report->deleted), count($report->stale), count($report->failures)));

        return [] === $report->failures ? Command::SUCCESS : Command::FAILURE;
    }
}
