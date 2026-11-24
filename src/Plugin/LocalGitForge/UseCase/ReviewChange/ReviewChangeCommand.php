<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Review\Contract\ChangeReview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:review', description: 'Review a branch of a local repository with the project\'s panel and print the outcome, the act list, the tokens used and the cost')]
final readonly class ReviewChangeCommand implements SignalableCommandInterface
{
    // The shell's convention for a process ended by a signal, so a caller sees 130 for Ctrl-C and 143 for SIGTERM.
    public const int SIGNAL_EXIT_BASE = 128;

    // POSIX numbers rather than SIGINT and SIGTERM, because those constants exist only with pcntl and the command must load without it.
    public const int INTERRUPT = 2;
    public const int TERMINATE = 15;

    public function __construct(
        private LocalChangeReview $review,
        private ReviewReport $report,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ReviewChangeInput $input): int
    {
        if ($this->lacksRequired($input)) {
            $io->error('--project and --task are required, and --round must be 1 or more.');

            return Command::FAILURE;
        }

        try {
            $review = $this->review->of($input);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $input, $review);

        return ReviewExit::of($review->decision)->value;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return SignalRegistry::isSupported() ? [self::INTERRUPT, self::TERMINATE] : [];
    }

    // The previous exit code is widened to mixed because the interface requires the parameter and the command ignores it: a stop always ends with the signal's code.
    public function handleSignal(int $signal, mixed $previousExitCode = 0): int
    {
        $this->review->stop();

        return self::SIGNAL_EXIT_BASE + $signal;
    }

    private function lacksRequired(ReviewChangeInput $input): bool
    {
        return '' === $input->project || '' === $input->task || (null !== $input->round && $input->round < 1);
    }

    private function print(SymfonyStyle $io, ReviewChangeInput $input, ChangeReview $review): void
    {
        if (OutputFormat::Json === $input->format) {
            $io->writeln($this->report->asJson($review), SymfonyStyle::OUTPUT_RAW);

            return;
        }

        foreach ($this->report->asLines($review) as $line) {
            $io->writeln($line);
        }
    }
}
