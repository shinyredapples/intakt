<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

// Run only when asked and never from bin/check or CI, because it calls the project's paid models.
#[AsCommand(name: 'intakt:bench:review', description: 'Review one bench change with the project\'s panel and print each reviewer\'s matched defects, false alarms, tokens and time (calls paid models)')]
final readonly class ReviewBenchChangeCommand
{
    public function __construct(
        private BenchChangeCheckout $checkout,
        private ReviewBenchChange $review,
        private ReadBenchProvenance $provenance,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The bench change, the name of a folder under fixtures/sample-repository/bench')] string $change,
        #[Option('The project whose review panel reviews the change')] string $project = '',
    ): int {
        if ('' === $project) {
            $io->error('--project is required.');

            return Command::FAILURE;
        }

        $directory = sys_get_temp_dir().'/intakt-bench-'.bin2hex(random_bytes(4));

        try {
            $review = $this->review->of($this->checkout->open($change, $project, $directory));
        } catch (BenchChangeNotFound|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        } finally {
            $this->filesystem->remove($directory);
        }

        $this->print($io, $review);

        return Command::SUCCESS;
    }

    private function print(SymfonyStyle $io, BenchReview $review): void
    {
        $provenance = $this->provenance->read();
        $io->writeln('change: '.$review->change);
        $io->writeln('intakt: '.$provenance->intaktVersion);
        $io->writeln('reviewer prompt: '.$provenance->prompts->reviewer);
        $io->writeln('judge prompt: '.$provenance->prompts->judge);
        $io->writeln('bench repository: '.$provenance->benchRepositoryHash);
        $io->writeln('decision: '.$review->decision->value);

        foreach ($review->reviewers as $reviewer) {
            $this->printReviewer($io, $reviewer);
        }

        foreach ($review->actList as $finding) {
            $io->writeln('act: '.$finding->id.' '.($finding->file ?? '-').':'.($finding->line ?? '-'));
        }
    }

    private function printReviewer(SymfonyStyle $io, BenchReviewerResult $reviewer): void
    {
        $verdict = $reviewer->verdict;
        $io->writeln(sprintf('reviewer %s (%s)', $reviewer->role, $reviewer->tier->value));
        $io->writeln('  caught: '.implode(', ', $verdict->caught));
        $io->writeln('  missed: '.implode(', ', array_map(static fn (string $id, FailureCause $cause): string => $id.' ('.$cause->value.')', array_keys($verdict->missed), $verdict->missed)));
        $io->writeln('  false alarms: '.count($verdict->falseAlarms));
        $io->writeln('  harness failure: '.($verdict->harnessFailure ?? '-'));
        $io->writeln(sprintf('  tokens: %d in, %d out, %d calls', $reviewer->tokens->prompt, $reviewer->tokens->completion, $reviewer->tokens->calls));
        $io->writeln('  time: '.(null === $reviewer->milliseconds ? '-' : $reviewer->milliseconds.' ms'));
    }
}
