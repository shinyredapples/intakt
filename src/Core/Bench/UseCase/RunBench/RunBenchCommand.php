<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\RunBench;

use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
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
        private BenchResultFile $file,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('The project whose review panel reviews the changes')] string $project = '',
    ): int {
        if ('' === $project) {
            $io->error('--project is required.');

            return Command::FAILURE;
        }

        try {
            $reviews = $this->bench->reviewEveryChange($project);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        foreach ($reviews as $review) {
            $this->print($io, $review);
        }

        $io->writeln('result file: '.$this->file->write($project, $reviews));

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, BenchReview $review): void
    {
        $io->writeln(sprintf('%s: decision %s, measured %d act list findings', $review->change, $review->decision->value, count($review->actList)));

        foreach ($review->reviewers as $reviewer) {
            $io->writeln($this->reviewerLine($reviewer));
        }
    }

    private function reviewerLine(BenchReviewerResult $reviewer): string
    {
        $verdict = $reviewer->verdict;
        $line = sprintf(
            '  %s (%s), measured: %d caught, %d missed, %d false alarms, %d prompt tokens, %d completion tokens, %d calls, %s',
            $reviewer->role,
            $reviewer->tier->value,
            count($verdict->caught),
            count($verdict->missed),
            count($verdict->falseAlarms),
            $reviewer->tokens->prompt,
            $reviewer->tokens->completion,
            $reviewer->tokens->calls,
            null === $reviewer->milliseconds ? 'no end' : $reviewer->milliseconds.' ms',
        );

        return null === $verdict->harnessFailure ? $line : $line.', harness failure: '.$verdict->harnessFailure;
    }
}
