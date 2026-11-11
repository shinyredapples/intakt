<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\ShadowRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;
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
        private ShadowRounds $shadow,
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
            $this->publish($round, $this->verdictOf($round));
        } catch (ReviewTextHoldsSecret) {
            $this->publish($round, $this->verdicts->unpublishable($round));
        } catch (ChangeNotFound) {
            // Logged and dropped, because a round of a local branch has no pull request to publish on, and retrying cannot give it one.
            $this->logger->warning('The round {round} of {project} names no pull request the forge knows, so its verdict is not published.', ['round' => $command->roundId, 'project' => $round->project()]);
        }
    }

    // The recorded verdict modes count too, so a round reviewed in shadow mode before a switch to reviewer-for-loop never publishes a counting verdict.
    private function verdictOf(ReviewRound $round): PublishedVerdict
    {
        return $this->shadow->isShadow($round) ? $this->verdicts->shadowed($round) : $this->verdicts->of($round);
    }

    private function publish(ReviewRound $round, PublishedVerdict $verdict): void
    {
        $this->forge->publishVerdict($round->project(), new ChangeId($round->change()), $verdict);
    }
}
