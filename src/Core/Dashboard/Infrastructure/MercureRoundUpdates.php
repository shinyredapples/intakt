<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Closure;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\SessionSummary;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

final readonly class MercureRoundUpdates implements RoundUpdates
{
    public function __construct(
        private LiveUpdates $live,
        private Environment $twig,
        private SessionSummaries $summaries,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public static function topicOf(string $round): string
    {
        return 'review/rounds/'.$round;
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->publish($round, RoundStream::ToolCalled, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'tool' => $tool]);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->publish($round, RoundStream::TextStreamed, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer, 'text' => $text]);
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
        $this->publish($round, RoundStream::ReviewerStopped, ['round' => $round->toRfc4122(), 'reviewer' => $reviewer]);
    }

    // Waited for, because a judged round's state must stay the last update of its topic: a worker publishing a tool call's summary finishes first, and the final summary goes out under the same lock, right before the state.
    private function publishJudged(Uuid $round, RoundProgress $progress): void
    {
        $lock = $this->summaryLockOf($round);

        if (!$this->doneOrLogged($round, static fn () => $lock->acquire(true))) {
            $this->publishState($round, $progress);

            return;
        }

        try {
            $this->doneOrLogged($round, fn () => $this->publishFinalSummary($round));
            $this->publishState($round, $progress);
        } finally {
            // Through doneOrLogged, because this runs inside the judge's event dispatch, outside the guard summaryChanged() puts around its own release.
            $this->doneOrLogged($round, static fn () => $lock->release());
        }
    }

    // Logged and swallowed, for the same reason as a render failure: the summary is a view and its loss must not touch the round.
    private function doneOrLogged(Uuid $round, Closure $step): bool
    {
        try {
            $step();

            return true;
        } catch (Throwable $failure) {
            $this->logger->warning('A live session summary was not published.', ['round' => $round->toRfc4122(), 'exception' => $failure]);

            return false;
        }
    }

    private function publishFinalSummary(Uuid $round): void
    {
        $summary = $this->summaries->ofRound($round->toRfc4122());

        if ($summary instanceof SessionSummary) {
            $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $this->summaryStreamOf($round, $summary)));
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

    // Compared as the rendered stream, because that is exactly what the page would change to.
    private function summaryStreamOf(Uuid $round, SessionSummary $summary): string
    {
        return $this->twig->render(RoundStream::SummaryChanged->template(), ['round' => $round->toRfc4122(), 'summary' => $summary]);
    }

    private function summaryLockOf(Uuid $round): LockInterface
    {
        return $this->locks->createLock('dashboard-session-summary-'.$round->toRfc4122());
    }

    private function publishState(Uuid $round, RoundProgress $progress): void
    {
        $this->publish($round, RoundStream::RoundChanged, ['round' => $round->toRfc4122(), 'progress' => $progress]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function publish(Uuid $round, RoundStream $stream, array $context): void
    {
        try {
            $turboStream = $this->twig->render($stream->template(), $context);
        } catch (Throwable $failure) {
            // Swallowed after a log, because a live update is best effort and must never change a verdict or a round.
            $this->logger->warning('A live round update was not rendered.', ['round' => $round->toRfc4122(), 'stream' => $stream->value, 'exception' => $failure]);

            return;
        }

        $this->live->publish(new LiveEvent(self::topicOf($round->toRfc4122()), $turboStream));
    }
}
