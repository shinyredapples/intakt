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

        try {
            $this->publish($round, $this->verdicts->of($round));
            $this->withdrawIfMadeStaleMeanwhile($round);
        } catch (ReviewTextHoldsSecret) {
            $this->publish($round, $this->verdicts->unpublishable($round));
        } catch (ChangeNotFound) {
            // Logged and dropped, because a round of a local branch has no pull request to publish on, and retrying cannot give it one.
            $this->logger->warning('The round {round} of {project} names no pull request the forge knows, so its verdict is not published.', ['round' => $command->roundId, 'project' => $round->project()]);
        }
    }

    // Read again after the write, because a failed boot may have marked the round while its approval was on the way: either this read sees the mark and withdraws it, or the mark came after the write, and the publication the mark queued withdraws it later.
    private function withdrawIfMadeStaleMeanwhile(ReviewRound $published): void
    {
        if ($published->hasStaleVerdicts()) {
            return;
        }

        $current = $this->rounds->stored($published->id()->toRfc4122());

        if ($current->hasStaleVerdicts()) {
            $this->publish($current, $this->verdicts->of($current));
        }
    }

    private function publish(ReviewRound $round, PublishedVerdict $verdict): void
    {
        $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $verdict);
    }
}
