<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Psr\Log\LoggerInterface;
use Throwable;
use Twig\Environment;

// Only the install's first round, because it is the one that changes the setup wizard's last step; later rounds leave that section as it is, so a step 5 page left open keeps its link to round 1 while a fresh load links to the review's latest round.
#[AsCommandHandler]
final readonly class PublishFirstReview
{
    public const string TEMPLATE = 'dashboard/stream/first_review.stream.html.twig';

    public function __construct(
        private LedgerReviewPages $pages,
        private DemoRuns $demo,
        private Environment $twig,
        private LiveUpdates $updates,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RoundOpened $opened): void
    {
        try {
            $this->publishWhenFirst($opened);
            // Boundary: logs a failed update and swallows it, because the round is already committed and a failed live update must not send its start back to the queue.
        } catch (Throwable $failure) {
            $this->logger->warning('The first review was not published.', ['round' => $opened->round, 'exception' => $failure]);
        }
    }

    private function publishWhenFirst(RoundOpened $opened): void
    {
        // Checked from the starts before the review is read, because every round opens through here and only the first one needs its review built and priced.
        if ($this->pages->firstRound() !== $opened->round) {
            return;
        }

        $review = $this->pages->reviewOf($opened->round);

        if (!$review instanceof Review) {
            return;
        }

        // The button's weight follows the demo as on the page, because a recording that is ready is the one primary action there.
        $button = DemoReadiness::Ready === $this->demo->readiness() ? 'secondary' : 'primary';
        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['first_review' => new FirstReviewStep($review, false), 'review_button' => $button])));
    }
}
