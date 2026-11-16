<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review;

use Closure;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\Component\Uid\Uuid;

final class RecordedRoundUpdates implements RoundUpdates
{
    /**
     * @var list<string>
     */
    public array $seen = [];

    /**
     * @var list<RoundProgress>
     */
    public array $progress = [];

    // Apart from $seen, because a reviewer starts in every delivery and the tests of what a round shows read $seen as a whole.
    /**
     * @var list<string>
     */
    public array $started = [];

    // Apart from $seen, because every reviewer that answers gives one and the tests of what a round streams read $seen as a whole; null for an unreadable answer.
    /**
     * @var list<?Verdict>
     */
    public array $answers = [];

    // Run once while the next round change is being published, so a test can let another worker finish in the middle of it.
    /**
     * @var (Closure(): void)|null
     */
    public ?Closure $whilePublishingNextRoundChange = null;

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->seen[] = sprintf('tool %s %s', $reviewer, $tool);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->seen[] = sprintf('text %s %s', $reviewer, $text);
    }

    #[Override]
    public function answerGiven(Uuid $round, string $reviewer, ?Verdict $verdict): void
    {
        $this->answers[] = $verdict;
    }

    #[Override]
    public function reviewerAskedAgain(Uuid $round, string $reviewer, string $try): void
    {
        $this->seen[] = sprintf('asked again %s', $reviewer);
    }

    #[Override]
    public function reviewerStarted(Uuid $round, string $reviewer): void
    {
        $this->started[] = $reviewer;
    }

    #[Override]
    public function reviewerStopped(Uuid $round, string $reviewer): void
    {
        $this->seen[] = sprintf('stopped %s', $reviewer);
    }

    #[Override]
    public function summaryChanged(Uuid $round): void
    {
        $this->seen[] = sprintf('summary %s', $round->toRfc4122());
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        $meanwhile = $this->whilePublishingNextRoundChange;
        $this->whilePublishingNextRoundChange = null;

        if ($meanwhile instanceof Closure) {
            $meanwhile();
        }

        $this->progress[] = $progress;
        $this->seen[] = sprintf('round %s %d/%d%s', $round->toRfc4122(), $progress->reviewersFinished, $progress->reviewers, $progress->judged ? ' judged' : '');
    }
}
