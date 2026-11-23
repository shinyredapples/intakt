<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Closure;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

#[AsAlias(RoundUpdates::class)]
final readonly class MercureRoundUpdates implements RoundUpdates
{
    public function __construct(
        private LiveUpdates $live,
        private Environment $twig,
        private SessionSummaries $summaries,
        private LedgerReviewPages $pages,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public static function topicOf(string $round): string
    {
        return 'review/rounds/'.$round;
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $call): void
    {
        $this->publish($round, RoundStream::ToolCalled, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'outcome' => $this->startedReviewerOf($round, $reviewer), 'call' => $call]);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->publish($round, RoundStream::TextStreamed, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'outcome' => $this->startedReviewerOf($round, $reviewer), 'text' => $text]);
    }

    #[Override]
    public function answerRead(Uuid $round, string $reviewer, ReadAnswer $answer): void
    {
        $this->publish($round, RoundStream::AnswerRead, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'outcome' => $this->startedReviewerOf($round, $reviewer), 'answer' => $answer]);
    }

    #[Override]
    public function reviewerAskedAgain(Uuid $round, string $reviewer, string $try): void
    {
        $this->publish($round, RoundStream::ReviewerAskedAgain, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'outcome' => $this->startedReviewerOf($round, $reviewer), 'try' => $try]);
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        if ($progress->judged) {
            $this->publishJudged($round, $progress);

            return;
        }

        // The summary first, because the findings and the cost move when a reviewer finishes.
        $this->summaryChanged($round);
        $this->publishState($round, $progress);
    }

    #[Override]
    public function summaryChanged(Uuid $round): void
    {
        $this->doneOrLogged($round, fn () => $this->publishLatestSummary($round));
    }

    #[Override]
    public function reviewerStopped(Uuid $round, string $reviewer): void
    {
        $this->publish($round, RoundStream::ReviewerStopped, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'outcome' => $this->stoppedReviewerOf($round, $reviewer)]);
    }

    // Nothing on the round's topic, because the review page places a reviewer from its first tool call or text (stream/_reviewer_placed).
    #[Override]
    public function reviewerStarted(Uuid $round, string $reviewer): void
    {
    }

    // Read from the whole round, because a reviewer can be stopped after it answered and its row must keep the verdict and findings a reload shows; a stop is rare, unlike streamed text. Marked stopped here too, because this stream announces the stop whatever the read saw.
    private function stoppedReviewerOf(Uuid $round, string $reviewer): ?ReviewerOutcome
    {
        $recorded = array_find($this->outcomesOf($round), static fn (ReviewerOutcome $outcome): bool => $outcome->id === $reviewer);

        return ($recorded ?? $this->startedReviewerOf($round, $reviewer))?->stopped();
    }

    // Waited for, because a judged round's state must stay the last update of its topic: a worker publishing a tool call's summary finishes first, and the final summary goes out under the same lock, right before the state.
    private function publishJudged(Uuid $round, RoundProgress $progress): void
    {
        $lock = $this->summaryLockOf($round);

        if (!$this->doneOrLogged($round, static fn () => $lock->acquire(true))) {
            $this->publishJudgedState($round, $progress);

            return;
        }

        try {
            $this->doneOrLogged($round, fn () => $this->publishFinalSummary($round));
            $this->publishJudgedState($round, $progress);
        } finally {
            // Through doneOrLogged, because this runs inside the judge's event dispatch, outside the guard summaryChanged() puts around its own release.
            $this->doneOrLogged($round, static fn () => $lock->release(), 'The live session summary lock was not released.');
        }
    }

    // One worker at a time reads a round's summary and publishes it, because a worker whose older read was published last would leave the page showing fewer calls than were made.
    private function publishLatestSummary(Uuid $round): void
    {
        $lock = $this->summaryLockOf($round);
        $seen = null;

        // Tried without waiting, because the worker holding the lock reads the summary again after it lets go, and so publishes what this one would have.
        while ($lock->acquire(false)) {
            try {
                $seen = $this->runningSummaryPublishedOnce($round, $seen);
            } finally {
                $lock->release();
            }

            $latest = $this->runningSummaryStreamOf($round);

            if (null === $latest || $latest === $seen) {
                return;
            }
        }
    }

    private function doneOrLogged(Uuid $round, Closure $step, string $failureMessage = 'A live session summary was not published.'): bool
    {
        try {
            $step();

            return true;
            // Boundary: logs a failed summary step and swallows it, because the summary is a view and its loss must not touch the round.
        } catch (Throwable $failure) {
            $this->logger->warning($failureMessage, ['round' => $round->toRfc4122(), 'exception' => $failure]);

            return false;
        }
    }

    private function runningSummaryPublishedOnce(Uuid $round, ?string $seen): ?string
    {
        $latest = $this->runningSummaryStreamOf($round);

        if (null !== $latest && $latest !== $seen) {
            $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $latest));
        }

        return $latest;
    }

    // Null once the round is judged, because its final summary goes out with its judged state, under this lock, and nothing may follow that state.
    private function runningSummaryStreamOf(Uuid $round): ?string
    {
        $summary = $this->summaries->ofRound($round->toRfc4122());

        if (!$summary instanceof SessionSummary || $summary->judged) {
            return null;
        }

        return $this->summaryStreamOf($round, $summary);
    }

    private function summaryLockOf(Uuid $round): LockInterface
    {
        return $this->locks->createLock('dashboard-session-summary-'.$round->toRfc4122());
    }

    private function publishState(Uuid $round, RoundProgress $progress): void
    {
        $review = $this->reviewForLive($round);
        $reviewed = $this->roundIn($review, $round->toRfc4122());

        $this->publish($round, RoundStream::RoundChanged, ['round' => $round->toRfc4122(), 'progress' => $progress, 'outcomes' => $reviewed->outcomes ?? [], 'review' => $review]);
    }

    private function publishJudgedState(Uuid $round, RoundProgress $progress): void
    {
        $review = $this->reviewOfJudged($round);
        $reviewed = $this->roundIn($review, $round->toRfc4122());

        $this->publish($round, RoundStream::RoundJudged, ['round' => $round->toRfc4122(), 'progress' => $progress, 'reviewed' => $reviewed, 'review' => $review, 'outcomes' => $reviewed->outcomes ?? []]);
    }

    private function publishFinalSummary(Uuid $round): void
    {
        $summary = $this->summaries->ofRound($round->toRfc4122());

        if ($summary instanceof SessionSummary) {
            $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $this->summaryStreamOf($round, $summary)));
        }
    }

    // Compared as the rendered stream, because that is exactly what the page would change to.
    private function summaryStreamOf(Uuid $round, SessionSummary $summary): string
    {
        return $this->twig->render(RoundStream::SummaryChanged->template(), ['round' => $round->toRfc4122(), 'summary' => $summary]);
    }

    // Read as the page reads it, because the live findings, tokens and totals must be exactly what a reload shows; null leaves them to that reload.
    private function reviewOfJudged(Uuid $round): ?Review
    {
        try {
            $review = $this->pages->reviewOf($round->toRfc4122());
            // Boundary: logs a failed read and returns null, because the judged state must still go out when its findings cannot be read.
        } catch (Throwable $failure) {
            $this->logger->warning('The judged round was not read for its live update.', ['round' => $round->toRfc4122(), 'exception' => $failure]);

            return null;
        }

        return true === $this->roundIn($review, $round->toRfc4122())?->isJudged() ? $review : null;
    }

    // Read as the page reads it, because a reviewer placed by a finish must show the role, model, outcome and findings a reload shows, and a failed judging must hide the path.
    private function reviewForLive(Uuid $round): ?Review
    {
        try {
            return $this->pages->reviewOf($round->toRfc4122());
            // Boundary: logs a failed read and returns null, because the progress must still go out when its reviewers cannot be named.
        } catch (Throwable $failure) {
            $this->logger->warning('The round was not read for its live reviewer rows.', ['round' => $round->toRfc4122(), 'exception' => $failure]);

            return null;
        }
    }

    /**
     * @return list<ReviewerOutcome>
     */
    private function outcomesOf(Uuid $round): array
    {
        return $this->roundIn($this->reviewForLive($round), $round->toRfc4122())->outcomes ?? [];
    }

    private function roundIn(?Review $review, string $id): ?ReviewedRound
    {
        return array_find($review->rounds ?? [], static fn (ReviewedRound $reviewed): bool => $reviewed->round === $id);
    }

    // Null leaves the reviewer unnamed until a reload, because a live update must go out even when the ledger cannot be read.
    private function startedReviewerOf(Uuid $round, string $reviewer): ?ReviewerOutcome
    {
        try {
            return $this->pages->startedReviewerOf($round->toRfc4122(), $reviewer);
            // Boundary: logs a failed ledger read and returns null, so the reviewer's live update still goes out.
        } catch (Throwable $failure) {
            $this->logger->warning('The reviewer was not read for its live update.', ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'exception' => $failure]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function publish(Uuid $round, RoundStream $stream, array $context): void
    {
        try {
            $turboStream = $this->twig->render($stream->template(), $context);
            // Boundary: logs a failed render and skips the update, because a live update is best effort and must never change a verdict or a round.
        } catch (Throwable $failure) {
            $this->logger->warning('A live round update was not rendered.', ['round' => $round->toRfc4122(), 'stream' => $stream->value, 'exception' => $failure]);

            return;
        }

        $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $turboStream));
    }
}
