<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// Run only when asked and never from bin/check or CI, because it calls the project's paid models for every bench change.
#[AsCommand(name: 'intakt:bench', description: 'Review every bench change with the project\'s panel and write one JSON result file under var/bench (calls paid models)')]
final readonly class RunBenchCommand
{
    public function __construct(
        private RunBench $bench,
        private BenchReport $report,
        private BenchResultFile $file,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('The project whose review panel reviews the changes')] string $project = '',
        #[Option('How many times each change is reviewed, each time from a fresh checkout')] int $repeat = 1,
    ): int {
        if ('' === $project) {
            $io->error('--project is required.');

            return Command::FAILURE;
        }

        if ($repeat < 1) {
            $io->error('--repeat must be at least 1.');

            return Command::FAILURE;
        }

        try {
            $changes = $this->bench->reviewEveryChange($project, $repeat);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($this->report->linesOf($changes));
        $io->writeln('result file: '.$this->file->write($project, $changes));

        return Command::SUCCESS;
    }
}
