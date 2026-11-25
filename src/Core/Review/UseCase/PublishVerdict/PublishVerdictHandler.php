<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Intakt\Core\Watcher\Contract\HandedOverChanges;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\RoundStatusMirror;
use Psr\Log\LoggerInterface;

// The mode and the hand-over are read here and not when the round is queued, so a project switched to or from reviewer-for-loop after its judge publishes by its mode at publication; a failed write is left to Messenger's retries.
#[AsCommandHandler]
final readonly class PublishVerdictHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private ProjectModes $modes,
        private HandedOverChanges $handed,
        private VerdictForTheLoop $verdicts,
        private OwnerAsks $ownerAsks,
        private ForgeWrites $forge,
        private ChangePublications $publications,
        private RoundStatusMirror $status,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishVerdict $command): void
    {
        $round = $this->rounds->stored($command->roundId);
        if (!$this->isPublishedFor($round)) {
            return;
        }

        $this->publications->whileHeld($round->project(), $round->change(), fn () => $this->publishLatestStateOf($command->roundId));
    }

    // In any mode for a pull request handed to Intakt, because the loop leaves it and Intakt's review is the one that counts there, while the loop's own heads of a live project stay with the weekly report.
    private function isPublishedFor(ReviewRound $round): bool
    {
        return ProjectMode::ReviewerForLoop === $this->modes->of($round->project()) || $this->handed->isHandedToIntakt($round->project(), $round->change());
    }

    // Read under the lock, because a judging or a stale mark committed while another publication of the change held it is only seen by a read after that one's write.
    private function publishLatestStateOf(string $roundId): void
    {
        $round = $this->rounds->stored($roundId);

        // Dropped, because the later round's status is the one its commit stands at, and an older publication arriving after it (a retry, a stale mark's withdrawal) would replace it.
        if ($this->rounds->judgedAfter($round)) {
            return;
        }

        $published = $round->hasStaleVerdicts();

        try {
            $this->publishReviewOf($round);
        } catch (ReviewTextHoldsSecret) {
            // Not recorded as published, because the error status carries no verdict for the people on the pull request to act on.
            $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $this->verdicts->unpublishable($round));
        } catch (ChangeNotFound) {
            // Logged and dropped, because a round of a local branch has no pull request to publish on, and retrying cannot give it one.
            $this->logger->warning('The round {round} of {project} names no pull request the forge knows, so its verdict is not published.', ['round' => $roundId, 'project' => $round->project()]);

            return;
        }

        // Read again after the write until nothing changed, because a failed boot marks the round without the lock, so its mark may commit while this round's approval is on the way.
        if ($this->rounds->stored($roundId)->hasStaleVerdicts() !== $published) {
            $this->publishLatestStateOf($roundId);
        }
    }

    // The ask recorded only after a forge write that happened, so no ledger line names an ask the pull request does not show.
    private function publishReviewOf(ReviewRound $round): void
    {
        $verdict = $this->verdicts->of($round);
        $ask = $this->ownerAsks->askFor($round, $verdict);
        $published = $this->publish($round, $ask->verdict ?? $verdict);

        if ($published && $ask instanceof OwnerAsk) {
            $this->ownerAsks->record($round, $ask);
        }
    }

    // Recorded only after a write the forge took, so a round whose publication was refused, skipped or is still queued stays in review; never for an error status (a replay, a head Intakt could not review), because it is still published but carries no verdict for the people on the pull request to act on.
    private function publish(ReviewRound $round, PublishedVerdict $verdict): bool
    {
        $published = $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $verdict);

        if ($published && HeadStatus::Error !== $verdict->status) {
            $this->rounds->recordPublication($round);
        }

        if ($published) {
            $this->status->reconcile($round->project(), new ChangeId($round->change()));
        }

        return $published;
    }
}
