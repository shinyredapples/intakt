<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\ChangeSource;
use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QueuedRound;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

// Lives in the forge plugin because it reads the change from a local repository, which the core review module may not reach; the command and the MCP tool share it.
final readonly class LocalChangeReview
{
    public function __construct(
        private Panels $panels,
        private ChangeReviews $reviews,
        private CleanupOnStop $onStop = new CleanupOnStop(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // Reviewed in a snapshot of the head rather than in the checkout, kept as a start keeps one so a round that outlives the command can finish, because the checkout may be switched or edited while the round runs, and a verdict counts only for what it reviewed.
    public function of(ReviewChangeInput $input, ReviewProgress $progress = new IgnoredProgress()): ChangeReview
    {
        $forge = $this->forgeFor($input);
        $change = $forge->change(new ChangeId($input->change));
        $request = $this->requestFor($input, $change);

        $snapshot = $forge->keepSnapshotOf($change->commits->head);
        $roundGoesOn = false;

        try {
            return $this->onStop->during(
                static fn () => $forge->removeSnapshot($snapshot),
                fn (): ChangeReview => $this->reviews->review($request($snapshot), $progress),
            );
            // Boundary: kept when a demo stops waiting, because the queued round goes on in the worker and still reads the snapshot; RemoveKeptSnapshot removes it once the round is judged.
        } catch (DemoProviderBusy $busy) {
            $roundGoesOn = true;

            throw $busy;
        } finally {
            if (!$roundGoesOn) {
                $this->removeKeptSnapshotOf($forge, $snapshot);
            }
        }
    }

    public function startOf(ReviewChangeInput $input): string
    {
        $forge = $this->forgeFor($input);

        return $this->start($forge, $input, $forge->change(new ChangeId($input->change)))->roundId;
    }

    public function startAt(ReviewChangeInput $input, CommitId $head): QueuedRound
    {
        $forge = $this->forgeFor($input);

        return $this->start($forge, $input, $forge->changeAt(new ChangeId($input->change), $head));
    }

    public function identityAt(ReviewChangeInput $input, CommitId $head, CommitId $reviewed): HeadIdentity
    {
        $forge = $this->forgeFor($input);
        $change = $forge->changeAt(new ChangeId($input->change), $head);

        return new HeadIdentity($this->identityOf($input, $change, $this->panelFor($input, $change)), $forge->headMoveSince($reviewed, $change));
    }

    public function stop(): void
    {
        $this->onStop->stop();
    }

    private function forgeFor(ReviewChangeInput $input): LocalGitForge
    {
        return new LocalGitForge(LocalRepository::existingAt($input->repository, $input->base), new NameStatusParser());
    }

    // Queues the next round without waiting, so the snapshot outlives this call: RemoveKeptSnapshot removes it once the round is judged, DiscardQueuedRoundHandler when the caller's transaction rolls the round back, and this call when the round cannot be queued.
    private function start(LocalGitForge $forge, ReviewChangeInput $input, Change $change): QueuedRound
    {
        $request = $this->requestFor($input, $change);
        $snapshot = $forge->keepSnapshotOf($change->commits->head);

        try {
            return new QueuedRound($this->reviews->start($request($snapshot)), $snapshot);
            // Boundary: removes the snapshot and rethrows the start failure (unless removal itself throws), because no queued round owns it once the start failed.
        } catch (Throwable $failure) {
            $forge->removeSnapshot($snapshot);

            throw $failure;
        }
    }

    /**
     * @return Closure(string): ChangeReviewRequest
     */
    private function requestFor(ReviewChangeInput $input, Change $change): Closure
    {
        $panel = $this->panelFor($input, $change);
        $identity = $this->identityOf($input, $change, $panel);
        $round = new RequestedRound($input->change, $input->round, new ChangeSource($this->absolute($input->repository), $input->base));

        return static fn (string $snapshot): ChangeReviewRequest => new ChangeReviewRequest($input->project, $snapshot, $input->task, $change->diff->patch, $round, $identity, $panel);
    }

    // Logged, never thrown, because RemoveKeptSnapshot removes the same snapshot once the round is judged and the two git runs may overlap; a throw here would replace the review's own result or failure.
    private function removeKeptSnapshotOf(LocalGitForge $forge, string $snapshot): void
    {
        try {
            $forge->removeSnapshot($snapshot);
            // Boundary: logs the failed removal and goes on, because the review is settled already and RemoveKeptSnapshot removes what is left.
        } catch (ForgeReadFailed $failure) {
            $this->logger->warning('The kept snapshot of a review could not be removed; delete it and run "git worktree prune" in its repository.', ['snapshot' => $snapshot, 'failure' => $failure->getMessage()]);
        }
    }

    private function panelFor(ReviewChangeInput $input, Change $change): Panel
    {
        return $this->panels->panelForChange($input->project, $this->describe($change));
    }

    private function identityOf(ReviewChangeInput $input, Change $change, Panel $panel): VerdictIdentity
    {
        return new VerdictIdentity($change->commits, ReviewTerms::of($input->task, $input->checkCommand, $panel));
    }

    // Resolved, because the dashboard starts the next round from its own working directory, where a relative path names another repository.
    private function absolute(string $repository): string
    {
        $resolved = realpath($repository);

        return false === $resolved ? throw RepositoryNotFound::at($repository) : $resolved;
    }

    private function describe(Change $change): ChangeDescription
    {
        $paths = array_map(static fn (ChangedFile $file): string => $file->path, [...$change->diff->files]);

        return new ChangeDescription($change->diff->addedLines() + $change->diff->removedLines(), new Paths(...$paths), new Labels());
    }
}
