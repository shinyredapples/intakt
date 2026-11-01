<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\GlobCannotBeEvaluated;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\RequestedRound;
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
            $review = $this->reviewed($input);
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

    // Reviewed in a snapshot of the head rather than in the checkout, because the checkout may be switched or edited while the round runs, and a verdict counts only for what it reviewed.
    private function reviewed(ReviewChangeInput $input): ChangeReview
    {
        $forge = new LocalGitForge(new LocalRepository($input->repository, $input->base), $this->nameStatus);
        $change = $forge->change(new ChangeId($input->change));
        $request = $this->requestFor($input, $change);

        return $forge->withSnapshotOf($change->commits->head, fn (string $snapshot): ChangeReview => $this->reviews->review($request($snapshot)));
    }

    /**
     * @return Closure(string): ChangeReviewRequest
     */
    private function requestFor(ReviewChangeInput $input, Change $change): Closure
    {
        $panel = $this->panels->panelForChange($input->project, $this->describe($change));
        $identity = new VerdictIdentity($change->commits, ReviewTerms::of($input->task, $input->checkCommand, $panel));
        $round = new RequestedRound($input->change, $input->round);

        return static fn (string $snapshot): ChangeReviewRequest => new ChangeReviewRequest($input->project, $snapshot, $input->task, $round, $identity, $panel);
    }

    private function describe(Change $change): ChangeDescription
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);

        return new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), $paths, []);
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
