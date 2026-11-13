<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Contract\ProjectModes;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeWrites;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewTextHoldsSecret;
use Psr\Log\LoggerInterface;

// The mode is read here and not when the round is queued, so a project switched to or from reviewer-for-loop after its judge publishes by its mode at publication; a failed write is left to Messenger's retries.
#[AsCommandHandler]
final readonly class PublishVerdictHandler
{
    public function __construct(
        private ReviewRounds $rounds,
        private ProjectModes $modes,
        private VerdictForTheLoop $verdicts,
        private ForgeWrites $forge,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishVerdict $command): void
    {
        $round = $this->rounds->stored($command->roundId);
        if (ProjectMode::ReviewerForLoop !== $this->modes->of($round->project())) {
            return;
        }

        // Dropped, because a later judged round of the change published its own verdict, and a delayed one of this round, such as the withdrawal a stale mark queued, would replace it.
        if (null !== $this->rounds->judgedAfter($round)) {
            return;
        }

        try {
            $this->publishVerdictOf($round);
            $this->settleWhatChangedDuringTheWrite($round);
        } catch (ChangeNotFound) {
            // Logged and dropped, because a round of a local branch has no pull request to publish on, and retrying cannot give it one.
            $this->logger->warning('The round {round} of {project} names no pull request the forge knows, so its verdict is not published.', ['round' => $command->roundId, 'project' => $round->project()]);
        }
    }

    // Read again after the write: a later round judged or a stale mark landed meanwhile is published here, and one that comes after this read queues its own publication, which runs after this write.
    private function settleWhatChangedDuringTheWrite(ReviewRound $published): void
    {
        $replacement = $this->rounds->judgedAfter($published);

        if (null !== $replacement) {
            $this->publishVerdictOf($replacement);

            return;
        }

        if ($published->hasStaleVerdicts()) {
            return;
        }

        $current = $this->rounds->stored($published->id()->toRfc4122());

        if ($current->hasStaleVerdicts()) {
            $this->publishVerdictOf($current);
        }
    }

    private function publishVerdictOf(ReviewRound $round): void
    {
        try {
            $this->publish($round, $this->verdicts->of($round));
        } catch (ReviewTextHoldsSecret) {
            $this->publish($round, $this->verdicts->unpublishable($round));
        }
    }

    private function publish(ReviewRound $round, PublishedVerdict $verdict): void
    {
        $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $verdict);
    }
}
