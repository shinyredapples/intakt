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
        private ChangePublications $publications,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishVerdict $command): void
    {
        $round = $this->rounds->stored($command->roundId);
        if (ProjectMode::ReviewerForLoop !== $this->modes->of($round->project())) {
            return;
        }

        $this->publications->whileHeld($round->project(), $round->change(), fn () => $this->publishLatestStateOf($command->roundId));
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
            $this->publish($round, $this->verdicts->of($round));
        } catch (ReviewTextHoldsSecret) {
            $this->publish($round, $this->verdicts->unpublishable($round));
        } catch (ChangeNotFound) {
            // Logged and dropped, because a round of a local branch has no pull request to publish on, and retrying cannot give it one.
            $this->logger->warning('The round {round} of {project} names no pull request the forge knows, so its verdict is not published.', ['round' => $roundId, 'project' => $round->project()]);

            return;
        }

        $this->publishAgainIfMarkedDuringTheWrite($roundId, $published);
    }

    // Read again after the write until nothing changed, because a failed boot marks the round without the lock, so its mark may commit while this round's approval is on the way.
    private function publishAgainIfMarkedDuringTheWrite(string $roundId, bool $publishedStale): void
    {
        if ($this->rounds->stored($roundId)->hasStaleVerdicts() !== $publishedStale) {
            $this->publishLatestStateOf($roundId);
        }
    }

    private function publish(ReviewRound $round, PublishedVerdict $verdict): void
    {
        $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $verdict);
    }
}
