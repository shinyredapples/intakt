<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Throwable;

// Lives in the forge plugin because it reads the change from a local repository, which the core review module may not reach; the command and the MCP tool share it.
final readonly class LocalChangeReview
{
    public function __construct(
        private Panels $panels,
        private ChangeReviews $reviews,
        private CleanupOnStop $onStop = new CleanupOnStop(),
    ) {
    }

    // Reviewed in a snapshot of the head rather than in the checkout, because the checkout may be switched or edited while the round runs, and a verdict counts only for what it reviewed.
    public function of(ReviewChangeInput $input, ReviewProgress $progress = new IgnoredProgress()): ChangeReview
    {
        $forge = new LocalGitForge(LocalRepository::existingAt($input->repository, $input->base), new NameStatusParser());
        $change = $forge->change(new ChangeId($input->change));
        $request = $this->requestFor($input, $change);

        return $forge->withSnapshotOf($change->commits->head, fn (string $snapshot): ChangeReview => $this->onStop->during(
            static fn () => $forge->removeSnapshot($snapshot),
            fn (): ChangeReview => $this->reviews->review($request($snapshot), $progress),
        ));
    }

    // Queues the next round without waiting, so the snapshot outlives this call and is removed only when the round cannot be queued; a removal after the judge is not built yet.
    public function startOf(ReviewChangeInput $input): string
    {
        $forge = new LocalGitForge(LocalRepository::existingAt($input->repository, $input->base), new NameStatusParser());
        $change = $forge->change(new ChangeId($input->change));
        $request = $this->requestFor($input, $change);
        $snapshot = $forge->keepSnapshotOf($change->commits->head);

        try {
            return $this->reviews->start($request($snapshot));
        } catch (Throwable $failure) {
            $forge->removeSnapshot($snapshot);

            throw $failure;
        }
    }

    public function stop(): void
    {
        $this->onStop->stop();
    }

    /**
     * @return Closure(string): ChangeReviewRequest
     */
    private function requestFor(ReviewChangeInput $input, Change $change): Closure
    {
        $panel = $this->panels->panelForChange($input->project, $this->describe($change));
        $identity = new VerdictIdentity($change->commits, ReviewTerms::of($input->task, $input->checkCommand, $panel));
        $round = new RequestedRound($input->change, $input->round, new ChangeSource($input->repository, $input->base));

        return static fn (string $snapshot): ChangeReviewRequest => new ChangeReviewRequest($input->project, $snapshot, $input->task, $change->diff->patch, $round, $identity, $panel);
    }

    private function describe(Change $change): ChangeDescription
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);

        return new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), $paths, []);
    }
}
