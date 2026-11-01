<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// Lives in the forge plugin because it reads the change from a local repository, which the core review module may not reach.
#[AsCommand(name: 'intakt:review', description: 'Review a branch of a local repository with the project\'s panel and print the outcome, the act list and the tokens used')]
final readonly class ReviewChangeCommand
{
    public function __construct(
        private Panels $panels,
        private ChangeReviews $reviews,
        private NameStatusParser $nameStatus,
        private ReviewReport $report = new ReviewReport(),
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] ReviewChangeInput $input): int
    {
        if ($this->lacksRequired($input)) {
            $io->error('--project and --task are required, and --round must be 1 or more.');

            return Command::FAILURE;
        }

        try {
            $review = $this->reviews->review($this->requestFor($input));
        } catch (ChangeNotFound|ForgeReadFailed|PanelNotConfigured|GlobCannotBeEvaluated|RoundNotNext|InvalidArgumentException $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $this->print($io, $input, $review);

        return ReviewExit::of($review->decision)->value;
    }

    private function lacksRequired(ReviewChangeInput $input): bool
    {
        return '' === $input->project || '' === $input->task || (null !== $input->round && $input->round < 1);
    }

    private function requestFor(ReviewChangeInput $input): ChangeReviewRequest
    {
        $forge = new LocalGitForge(new LocalRepository($input->repository, $input->base), $this->nameStatus);
        $change = $forge->change(new ChangeId($input->change));
        $panel = $this->panels->panelForChange($input->project, $this->describe($change));
        $identity = new VerdictIdentity($change->commits, ReviewTerms::of($input->task, $input->checkCommand, $panel));

        return new ChangeReviewRequest($input->project, $this->checkoutOf($input), $input->task, $input->change, $identity, $panel, $input->round);
    }

    private function describe(Change $change): ChangeDescription
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);

        return new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), $paths, []);
    }

    // Resolved because a worker started elsewhere reads the stored path from its own working directory.
    private function checkoutOf(ReviewChangeInput $input): string
    {
        $real = realpath($input->repository);

        return false === $real ? throw new InvalidArgumentException('The repository '.$input->repository.' does not exist.') : $real;
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
